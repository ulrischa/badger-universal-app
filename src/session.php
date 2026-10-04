<?php
declare(strict_types=1);

/** File sessions with a bounded lock wait instead of PHP's blocking flock. */
final class BoundedSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private mixed $handle = null;

    public function __construct(private readonly string $directory) {}

    private function path(string $id): string
    {
        if (!preg_match('/^[a-zA-Z0-9,-]{16,128}$/D', $id)) {
            throw new RuntimeException('Invalid session id');
        }
        return $this->directory . '/sess_' . $id;
    }

    public function open(string $path, string $name): bool
    {
        return is_dir($this->directory) && is_writable($this->directory);
    }

    public function close(): bool
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
        return true;
    }

    public function read(string $id): string|false
    {
        $this->handle = fopen($this->path($id), 'c+b');
        if (!$this->handle) { throw new HttpError(503, 'Sitzungsspeicher nicht verfügbar.'); }
        $deadline = hrtime(true) + 1000000000;
        while (!flock($this->handle, LOCK_EX | LOCK_NB)) {
            if (hrtime(true) >= $deadline) {
                $this->close();
                throw new HttpError(503, 'Sitzung beschäftigt. Bitte erneut versuchen.');
            }
            usleep(10000);
        }
        $data = stream_get_contents($this->handle, 4097);
        return $data !== false && strlen($data) <= 4096 ? $data : '';
    }

    public function write(string $id, string $data): bool
    {
        if (strlen($data) > 4096) { return false; }
        if (!is_resource($this->handle)) { $this->read($id); }
        rewind($this->handle);
        return ftruncate($this->handle, 0) && fwrite($this->handle, $data) === strlen($data) && fflush($this->handle);
    }

    public function destroy(string $id): bool
    {
        $path = $this->path($id);
        $result = !is_file($path) || unlink($path);
        $this->close();
        return $result;
    }

    public function gc(int $max_lifetime): int|false
    {
        $removed = 0;
        $visited = 0;
        foreach (new DirectoryIterator($this->directory) as $file) {
            if (++$visited > 500) { break; }
            if (!$file->isFile() || !str_starts_with($file->getFilename(), 'sess_') || $file->getMTime() >= time() - $max_lifetime) { continue; }
            $handle = fopen($file->getPathname(), 'r+');
            if ($handle && flock($handle, LOCK_EX | LOCK_NB)) {
                clearstatcache(true, $file->getPathname());
                if (filemtime($file->getPathname()) < time() - $max_lifetime && unlink($file->getPathname())) { $removed++; }
            }
            if ($handle) { fclose($handle); }
        }
        return $removed;
    }

    public function validateId(string $id): bool
    {
        return preg_match('/^[a-zA-Z0-9,-]{16,128}$/D', $id) === 1 && is_file($this->path($id));
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return touch($this->path($id));
    }
}
