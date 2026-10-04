"""HTTPS transport with certificate verification and finite resource budgets."""
import gc
import socket
import ssl
import time
import network
from hub_core import MAX_BODY, decode


class Cancelled(Exception):
    pass


def fetch_manifest(config, watchdog, cancelled):
    started = time.ticks_ms()
    wlan = network.WLAN(network.STA_IF)
    raw_socket = None
    stream = None

    def checkpoint():
        if cancelled():
            raise Cancelled()
        if time.ticks_diff(time.ticks_ms(), started) > 25000:
            raise OSError("Total request deadline")
        watchdog.feed()

    try:
        # A trusted RTC and a CA certificate are prerequisites, never bypassed.
        if time.localtime()[0] < 2025:
            raise ValueError("Set RTC over USB before enabling HTTPS")
        host = config.SERVER_HOST
        path = config.API_PATH
        token = config.DEVICE_TOKEN
        if not host or any(c not in "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.-" for c in host):
            raise ValueError("Invalid server host")
        if not path.startswith("/") or any(ord(c) < 33 or ord(c) > 126 for c in path):
            raise ValueError("Invalid API path")
        if len(token) != 64 or any(c not in "0123456789abcdef" for c in token):
            raise ValueError("Invalid device token")
        with open(config.CA_FILE, "rb") as handle:
            ca = handle.read(8193)
        if not ca or len(ca) > 8192:
            raise ValueError("Invalid CA certificate size")
        wlan.active(True)
        if not wlan.isconnected():
            wlan.connect(config.WIFI_SSID, config.WIFI_PASSWORD)
        wifi_started = time.ticks_ms()
        while not wlan.isconnected():
            checkpoint()
            if wlan.status() < 0 or time.ticks_diff(time.ticks_ms(), wifi_started) > 12000:
                raise OSError("WiFi unavailable")
            time.sleep_ms(100)
        checkpoint()
        # DNS and TLS implementations may block in native code. The hardware
        # watchdog bounds those calls; main.py enters recovery after such a reset.
        address = socket.getaddrinfo(host, config.SERVER_PORT, 0, socket.SOCK_STREAM)[0][-1]
        checkpoint()
        raw_socket = socket.socket()
        raw_socket.settimeout(4)
        raw_socket.connect(address)
        checkpoint()
        gc.collect()
        if hasattr(ssl, "SSLContext"):
            context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
            context.verify_mode = ssl.CERT_REQUIRED
            context.load_verify_locations(cadata=ca)
            stream = context.wrap_socket(raw_socket, server_hostname=host)
        else:
            # Older Pimoroni builds expose only wrap_socket. Unsupported keyword
            # arguments fail closed rather than silently disabling verification.
            stream = ssl.wrap_socket(raw_socket, cert_reqs=ssl.CERT_REQUIRED,
                                     cadata=ca, server_hostname=host)
        checkpoint()
        request = ("GET " + path + " HTTP/1.0\r\nHost: " + host + ":" + str(config.SERVER_PORT) +
                   "\r\nAuthorization: Bearer " + token +
                   "\r\nAccept: application/json\r\nAccept-Encoding: identity\r\nConnection: close\r\n\r\n").encode()
        offset = 0
        while offset < len(request):
            checkpoint()
            count = stream.write(request[offset:])
            if not count:
                raise OSError("Incomplete write")
            offset += count
        # Read headers in chunks without consuming unbounded memory.
        received = bytearray()
        while b"\r\n\r\n" not in received:
            checkpoint()
            chunk = stream.read(256)
            if not chunk:
                raise OSError("Incomplete headers")
            received.extend(chunk)
            if len(received) > 4352:
                raise ValueError("Headers too large")
        header, body = bytes(received).split(b"\r\n\r\n", 1)
        del received
        if len(header) > 4096:
            raise ValueError("Headers too large")
        lines = header.split(b"\r\n")
        parts = lines[0].split(b" ")
        if len(parts) < 2 or parts[1] != b"200":
            raise OSError("HTTP request rejected")
        headers = {}
        for line in lines[1:]:
            if b":" not in line:
                raise ValueError("Invalid header")
            key, value = line.split(b":", 1)
            key = key.strip().lower()
            if key in headers:
                raise ValueError("Duplicate header")
            headers[key] = value.strip().lower()
        if b"transfer-encoding" in headers or headers.get(b"content-encoding", b"identity") != b"identity":
            raise ValueError("Encoded responses unsupported")
        if not headers.get(b"content-type", b"").startswith(b"application/json"):
            raise ValueError("Invalid response type")
        length = int(headers.get(b"content-length", b"-1"))
        if not 1 <= length <= MAX_BODY or len(body) > length:
            raise ValueError("Invalid response length")
        body = bytearray(body)
        while len(body) < length:
            checkpoint()
            chunk = stream.read(min(1024, length - len(body)))
            if not chunk:
                raise OSError("Truncated response")
            body.extend(chunk)
        checkpoint()
        stream.close()
        stream = None
        raw_socket.close()
        raw_socket = None
        del headers, header, lines, ca, request
        gc.collect()
        return decode(bytes(body))
    finally:
        for resource in (stream, raw_socket):
            if resource is not None:
                try:
                    resource.close()
                except OSError:
                    pass
        try:
            wlan.disconnect()
            wlan.active(False)
        except OSError:
            pass
        gc.collect()
