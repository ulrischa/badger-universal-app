"""Bounded data model, cache and navigation; also testable on CPython."""
import json
import gc
import os

MAX_BODY = 32768
EMPTY = {"schema": 1, "generated_at": 0, "refresh_seconds": 900, "apps": []}


def integer(value, minimum, maximum):
    if type(value) is not int or not minimum <= value <= maximum:
        raise ValueError("Invalid integer")


def text(value, maximum):
    if not isinstance(value, str) or not 1 <= len(value) <= maximum:
        raise ValueError("Invalid text")
    if any(ord(char) < 32 or ord(char) > 126 for char in value):
        raise ValueError("Display text must be ASCII")


def validate(data):
    if not isinstance(data, dict) or set(data) != {"schema", "generated_at", "refresh_seconds", "apps"}:
        raise ValueError("Invalid manifest")
    integer(data["schema"], 1, 1)
    integer(data["generated_at"], 0, 4102444800)
    integer(data["refresh_seconds"], 60, 86400)
    apps = data["apps"]
    if not isinstance(apps, list) or len(apps) > 10:
        raise ValueError("Invalid app count")
    ids = set()
    for app in apps:
        if not isinstance(app, dict) or set(app) != {"id", "title", "updated_at", "ttl", "screens"}:
            raise ValueError("Invalid app")
        text(app["id"], 32)
        app_id = app["id"]
        if not "a" <= app_id[0] <= "z" or any(c not in "abcdefghijklmnopqrstuvwxyz0123456789-" for c in app_id):
            raise ValueError("Invalid app id")
        if app_id in ids:
            raise ValueError("Duplicate app")
        ids.add(app_id)
        text(app["title"], 24)
        integer(app["updated_at"], 0, 4102444800)
        integer(app["ttl"], 60, 604800)
        screens = app["screens"]
        if not isinstance(screens, list) or not 1 <= len(screens) <= 6:
            raise ValueError("Invalid screens")
        for screen in screens:
            if not isinstance(screen, dict) or set(screen) != {"title", "rows"}:
                raise ValueError("Invalid screen")
            text(screen["title"], 28)
            rows = screen["rows"]
            if not isinstance(rows, list) or not 1 <= len(rows) <= 3:
                raise ValueError("Invalid rows")
            for row in rows:
                if not isinstance(row, dict) or set(row) != {"label", "value"}:
                    raise ValueError("Invalid row")
                text(row["label"], 14)
                text(row["value"], 22)
    return data


def decode(raw):
    if len(raw) > MAX_BODY:
        raise ValueError("Manifest too large")
    # Bound parser nesting and allocation before constructing Python objects.
    # Otherwise a small JSON body of thousands of empty lists can exhaust RAM.
    depth = 0
    containers = 0
    separators = 0
    quoted = False
    escaped = False
    for char in raw:
        code = ord(char) if isinstance(char, str) else char
        if quoted:
            if escaped:
                escaped = False
            elif code == 92:
                escaped = True
            elif code == 34:
                quoted = False
        elif code == 34:
            quoted = True
        elif code in (123, 91):
            depth += 1
            containers += 1
            if depth > 8 or containers > 400:
                raise ValueError("JSON complexity limit")
        elif code in (44, 58):
            separators += 1
            if separators > 1200:
                raise ValueError("JSON token limit")
        elif code in (125, 93):
            depth -= 1
            if depth < 0:
                raise ValueError("Invalid JSON depth")
    if quoted or depth != 0:
        raise ValueError("Incomplete JSON")
    return validate(json.loads(raw))


def load_cache(prefix="cache"):
    best = None
    for suffix in ("a", "b"):
        try:
            with open(prefix + "." + suffix + ".json", "rb") as handle:
                data = decode(handle.read(MAX_BODY + 1))
            if best is None or data["generated_at"] > best["generated_at"]:
                best = data
        except (OSError, ValueError, TypeError, KeyError, MemoryError, RuntimeError):
            gc.collect()
    return best if best is not None else dict(EMPTY)


def save_cache(data, prefix="cache"):
    validate(data)
    raw = json.dumps(data)
    if len(raw) > MAX_BODY:
        raise ValueError("Cache too large")
    candidates = []
    for suffix in ("a", "b"):
        path = prefix + "." + suffix + ".json"
        try:
            with open(path, "rb") as handle:
                age = decode(handle.read(MAX_BODY + 1))["generated_at"]
        except (OSError, ValueError, TypeError, KeyError, MemoryError, RuntimeError):
            gc.collect()
            age = -1
        candidates.append((age, path))
    target = min(candidates)[1]
    temporary = prefix + ".tmp"
    with open(temporary, "w") as handle:
        handle.write(raw)
        handle.flush()
    if hasattr(os, "sync"):
        os.sync()
    # The other slot remains valid if power is lost during replacement.
    try:
        os.remove(target)
    except OSError:
        pass
    os.rename(temporary, target)


class Navigation:
    def __init__(self, manifest):
        self.manifest = validate(manifest)
        self.selection = 0
        self.page = 0
        self.in_app = False

    def replace(self, manifest):
        previous = self.current_app()
        previous_id = previous["id"] if previous else None
        self.manifest = validate(manifest)
        self.selection = 0
        found = False
        for index, app in enumerate(manifest["apps"]):
            if app["id"] == previous_id:
                self.selection = index
                found = True
                break
        if not found:
            self.in_app = False
        self.page = 0

    def current_app(self):
        apps = self.manifest["apps"]
        return apps[self.selection] if apps else None

    def press(self, key):
        # Reserved locally; a server response cannot redefine this escape route.
        if key == "C":
            self.in_app = False
            self.page = 0
            return "draw"
        if key == "B":
            return "refresh"
        app = self.current_app()
        if not app:
            return "draw"
        if self.in_app:
            if key in ("A", "DOWN", "UP"):
                self.page = (self.page + (-1 if key == "UP" else 1)) % len(app["screens"])
        elif key == "A":
            self.in_app = True
            self.page = 0
        elif key in ("UP", "DOWN"):
            self.selection = (self.selection + (-1 if key == "UP" else 1)) % len(self.manifest["apps"])
        return "draw"


def retry_seconds(failures):
    return min(900, 30 * (2 ** min(max(failures - 1, 0), 5)))
