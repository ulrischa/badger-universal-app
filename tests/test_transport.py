import importlib
import json
import sys
import tempfile
import types
import unittest
from unittest.mock import patch
from pathlib import Path
from test_device import fixture


class Stream:
    def __init__(self, response): self.response = response; self.closed = False
    def read(self, count):
        value = self.response[:count]; self.response = self.response[count:]; return value
    def write(self, data): return min(17, len(data))
    def close(self): self.closed = True


class RawSocket:
    def settimeout(self, seconds): pass
    def connect(self, address): pass
    def close(self): pass


class Wifi:
    def __init__(self): self.active_state = False
    def active(self, value): self.active_state = value
    def isconnected(self): return True
    def disconnect(self): pass


class TransportTests(unittest.TestCase):
    def execute(self, response, cancel=False):
        wifi = Wifi(); stream = Stream(response); captured = {}
        def wrap(sock, **kwargs): captured.update(kwargs); return stream
        socket = types.SimpleNamespace(SOCK_STREAM=1, getaddrinfo=lambda *a: [(None, None, None, None, ('127.0.0.1', 443))], socket=RawSocket)
        ssl = types.SimpleNamespace(CERT_REQUIRED=2, wrap_socket=wrap)
        clock = types.SimpleNamespace(localtime=lambda: (2026,), ticks_ms=lambda: 1,
                                     ticks_diff=lambda a, b: a-b, sleep_ms=lambda n: None)
        network = types.SimpleNamespace(STA_IF=0, WLAN=lambda _: wifi)
        with tempfile.TemporaryDirectory() as directory:
            Path(directory + '/ca.der').write_bytes(b'test-ca')
            config = types.SimpleNamespace(SERVER_HOST='example.com', SERVER_PORT=443, API_PATH='/api.php?r=manifest',
                                          DEVICE_TOKEN='a'*64, CA_FILE=directory+'/ca.der', WIFI_SSID='test', WIFI_PASSWORD='test')
            with patch.dict(sys.modules, {'network': network, 'ssl': ssl, 'socket': socket, 'time': clock}):
                sys.modules.pop('hub_network', None)
                module = importlib.import_module('hub_network')
                try:
                    result = module.fetch_manifest(config, types.SimpleNamespace(feed=lambda: None), lambda: cancel)
                    self.assertEqual(captured['cert_reqs'], 2)
                    self.assertEqual(captured['server_hostname'], 'example.com')
                    return result
                finally:
                    self.assertFalse(wifi.active_state)
                    sys.modules.pop('hub_network', None)

    def test_valid_fragmented_response(self):
        body = json.dumps(fixture()).encode()
        response = b'HTTP/1.0 200 OK\r\nContent-Type: application/json\r\nContent-Length: ' + str(len(body)).encode() + b'\r\n\r\n' + body
        self.assertEqual(self.execute(response)['schema'], 1)

    def test_bad_http_fails_closed(self):
        responses = [b'HTTP/1.0 302 Found\r\nContent-Length: 0\r\n\r\n',
                     b'HTTP/1.0 200 OK\r\nContent-Type: application/json\r\nContent-Length: 40000\r\n\r\n',
                     b'HTTP/1.0 200 OK\r\nContent-Type: application/json\r\nContent-Length: 5\r\n\r\nx',
                     b'HTTP/1.0 200 OK\r\nContent-Type: application/json\r\nTransfer-Encoding: chunked\r\n\r\n',
                     b'x'*4400]
        for response in responses:
            with self.assertRaises((ValueError, OSError)): self.execute(response)

    def test_cancel_terminates(self):
        with self.assertRaises(Exception) as context: self.execute(b'', cancel=True)
        self.assertEqual(type(context.exception).__name__, 'Cancelled')


if __name__ == '__main__': unittest.main()
