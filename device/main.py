"""Standalone Badger Hub client for Pimoroni Badger 2040 W firmware."""
import time
import machine
import badger2040
from hub_core import Navigation, load_cache, save_cache, retry_seconds


def main():
    display = badger2040.Badger2040()
    # Holding C during reset leaves USB REPL available without an active WDT.
    if display.pressed(badger2040.BUTTON_C) or badger2040.pressed_to_wake(badger2040.BUTTON_C):
        print("Maintenance mode. Edit config.py, then reset without holding C.")
        return
    import config
    from hub_network import fetch_manifest, Cancelled
    recovery = machine.reset_cause() == machine.WDT_RESET
    watchdog = machine.WDT(timeout=8000)
    try:
        badger2040.pcf_to_pico_rtc()
    except (OSError, AttributeError):
        pass
    nav = Navigation(load_cache())
    display.set_font("bitmap8")
    display.set_update_speed(badger2040.UPDATE_FAST)
    status = "RECOVERY - B retry" if recovery else "OFFLINE - saved data"
    online = False
    failures = 0
    next_refresh = time.ticks_add(time.ticks_ms(), 2000)
    last_cache = time.ticks_add(time.ticks_ms(), -600000)
    keys = [("C", badger2040.BUTTON_C), ("B", badger2040.BUTTON_B),
            ("A", badger2040.BUTTON_A), ("UP", badger2040.BUTTON_UP),
            ("DOWN", badger2040.BUTTON_DOWN)]
    held = set()
    dirty = True
    last_stale = None

    def unix_now():
        # MicroPython ports may use 2000 or 1970 as their epoch.
        return time.time() + (946684800 if time.gmtime(0)[0] == 2000 else 0)

    def stale():
        app = nav.current_app()
        return bool(app and unix_now() - app["updated_at"] > app["ttl"])

    def draw():
        watchdog.feed()
        display.set_pen(15)
        display.clear()
        display.set_pen(0)
        app = nav.current_app()
        title = app["screens"][nav.page]["title"] if nav.in_app and app else "BADGER HUB"
        display.text(title, 5, 2, 286, 1)
        display.line(0, 16, 296, 16)
        if nav.in_app and app:
            for index, row in enumerate(app["screens"][nav.page]["rows"]):
                y = 21 + index * 22
                display.text(row["label"], 5, y, 110, 1)
                display.text(row["value"], 116, y, 176, 1)
        elif nav.manifest["apps"]:
            start = (nav.selection // 3) * 3
            for index, item in enumerate(nav.manifest["apps"][start:start + 3]):
                mark = "> " if start + index == nav.selection else "  "
                display.text(mark + item["title"], 5, 22 + index * 22, 286, 1)
        else:
            display.text("No apps assigned.", 5, 25, 286, 1)
            display.text("Register in web hub. B: sync", 5, 48, 286, 1)
        label = "OLD DATA" if online and stale() else status
        display.text(label, 5, 91, 286, 1)
        display.line(0, 106, 296, 106)
        display.text("A:open/next  B:sync  C:menu", 5, 112, 286, 1)
        display.update()
        watchdog.feed()

    while True:
        watchdog.feed()
        pressed = {name for name, pin in keys if display.pressed(pin)}
        event = next((name for name, _ in keys if name in pressed and name not in held), None)
        held = pressed
        refresh = False
        if event:
            action = nav.press(event)
            dirty = True
            refresh = action == "refresh"
        if not recovery and time.ticks_diff(time.ticks_ms(), next_refresh) >= 0:
            refresh = True
        if refresh:
            status = "SYNC - C cancels"
            draw()
            try:
                manifest = fetch_manifest(config, watchdog, lambda: display.pressed(badger2040.BUTTON_C))
                nav.replace(manifest)
                online = True
                recovery = False
                failures = 0
                status = "ONLINE"
                next_refresh = time.ticks_add(time.ticks_ms(), manifest["refresh_seconds"] * 1000)
                if time.ticks_diff(time.ticks_ms(), last_cache) >= 600000:
                    try:
                        save_cache(manifest)
                        last_cache = time.ticks_ms()
                    except (OSError, ValueError):
                        status = "ONLINE - cache not saved"
            except Cancelled:
                nav.press("C")
                status = "CANCELLED - B retry"
                recovery = True
                online = False
            except Exception as error:
                # Never print credentials, response bodies or exception messages.
                print("Sync failed:", type(error).__name__)
                online = False
                failures += 1
                status = "OFFLINE - B retry"
                next_refresh = time.ticks_add(time.ticks_ms(), retry_seconds(failures) * 1000)
                if failures >= 5:
                    recovery = True
                    status = "PAUSED - B retry"
            held = {name for name, pin in keys if display.pressed(pin)}
            dirty = True
        current_stale = stale()
        if current_stale != last_stale:
            dirty = True
            last_stale = current_stale
        if dirty:
            draw()
            dirty = False
        time.sleep_ms(40)


main()
