"""HTTP integration tests using an isolated database and real PHP process."""
import http.cookiejar
import fcntl
import json
import os
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')


class HttpTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        cls.env = dict(os.environ, BADGER_CONFIG=cls.temp.name + '/config.json')
        cls.password = 'test-password-ONLY-0123456789'
        subprocess.run([PHP, str(ROOT / 'bin/setup.php'), 'http://127.0.0.1:8080', '--dev'],
                       input=cls.password + '\n', text=True, env=cls.env, check=True, capture_output=True)
        cls.log = open(cls.temp.name + '/server.log', 'w')
        cls.process = subprocess.Popen([PHP, '-S', '127.0.0.1:8080', '-t', str(ROOT / 'public')],
                                       env=cls.env, stdout=cls.log, stderr=cls.log)
        cls.base = 'http://127.0.0.1:8080/api.php?r='
        for _ in range(100):
            if cls.process.poll() is not None: raise RuntimeError('PHP server failed to start')
            try:
                with socket.create_connection(('127.0.0.1', 8080), timeout=.1): break
            except OSError: time.sleep(.05)
        cls.cookies = http.cookiejar.CookieJar()
        cls.browser = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cls.cookies))
        _, session, _ = cls.request('session')
        cls.csrf = session['csrf']
        status, result, _ = cls.request('login', {'password': cls.password})
        if status != 200:
            cls.process.terminate(); cls.process.wait(timeout=5); cls.log.close()
            raise RuntimeError(str(result) + '\n' + Path(cls.temp.name + '/server.log').read_text())
        cls.csrf = result['csrf']

    @classmethod
    def tearDownClass(cls):
        cls.process.terminate(); cls.process.wait(timeout=5)
        cls.log.close(); cls.temp.cleanup()

    @classmethod
    def request(cls, route, body=None, token=None, headers=None, method=None, anonymous=False):
        hdr = {'Content-Type': 'application/json', 'X-CSRF-Token': getattr(cls, 'csrf', '')}
        if token: hdr['Authorization'] = 'Bearer ' + token
        if headers: hdr.update(headers)
        request = urllib.request.Request(cls.base + route, data=json.dumps(body).encode() if body is not None else None,
                                         headers=hdr, method=method)
        opener = urllib.request.build_opener() if anonymous else cls.browser
        try:
            response = opener.open(request, timeout=8)
        except urllib.error.HTTPError as error: response = error
        raw = response.read()
        return response.status, json.loads(raw), response.headers

    def app(self, app_id):
        body = {'id': app_id, 'title': 'Energy', 'enabled': True, 'ttl': 900,
                'screens': [{'title': 'PV', 'rows': [{'label': 'Power', 'value': '5 kW'}]}], 'version': 0}
        status, result, _ = self.request('app', body)
        self.assertEqual(status, 200, result)
        return result['token'], body

    def test_01_auth_csrf_and_headers(self):
        self.assertEqual(self.request('state', anonymous=True)[0], 401)
        self.assertEqual(self.request('app', {}, headers={'X-CSRF-Token': 'wrong'})[0], 403)
        self.assertEqual(self.request('state', headers={'Origin': 'https://evil.example'})[0], 403)
        self.assertEqual(self.request('state', method='POST', body={})[0], 405)
        self.assertEqual(self.request('manifest')[0], 401)
        status, _, headers = self.request('state')
        self.assertEqual(status, 200)
        self.assertIn('no-store', headers['Cache-Control'])
        self.assertEqual(headers['X-Content-Type-Options'], 'nosniff')

    def test_02_publish_scopes_rotation_and_stale_data(self):
        token, body = self.app('http-app')
        other_token, _ = self.app('other-app')
        status, result, _ = self.request('device', {'id': 'test-badge', 'name': 'Test', 'enabled': True,
            'refresh': 60, 'version': 0, 'apps': ['http-app']})
        self.assertEqual(status, 200, result)
        device_token = result['token']
        self.assertEqual(self.request('manifest', token=token)[0], 401)
        self.assertEqual(self.request('publish', {'screens': body['screens'], 'version': 1}, token=device_token)[0], 401)
        status, manifest, _ = self.request('manifest', token=device_token)
        self.assertEqual(status, 200)
        self.assertEqual([app['id'] for app in manifest['apps']], ['http-app'])
        self.assertEqual(self.request('app-status', token=token)[1]['version'], 1)
        self.assertEqual(self.request('publish', {'screens': body['screens'], 'version': 1}, token=token)[0], 200)
        status, _, headers = self.request('publish', {'screens': body['screens'], 'version': 1}, token=token)
        self.assertEqual(status, 409)
        self.assertEqual(headers['X-App-Version'], '2')
        self.assertEqual(self.request('app-status', token=other_token)[1]['version'], 1)
        self.assertEqual(self.request('publish', {'screens': body['screens'], 'version': 2, 'id': 'other-app'}, token=token)[0], 422)
        status, rotated, _ = self.request('rotate', {'kind': 'app', 'id': 'http-app', 'version': 2})
        self.assertEqual(status, 200)
        self.assertEqual(self.request('app-status', token=token)[0], 401)
        self.assertEqual(self.request('app-status', token=rotated['token'])[0], 200)
        body.update(enabled=False, version=3)
        self.assertEqual(self.request('app', body)[0], 200)
        self.assertEqual(self.request('manifest', token=device_token)[1]['apps'], [])

    def test_03_body_validation(self):
        self.assertEqual(self.request('app', {'large': 'x' * 17000})[0], 413)
        self.assertEqual(self.request('app', {}, headers={'Content-Type': 'text/plain'})[0], 415)
        self.assertEqual(self.request('app', {'unknown': 'test'})[0], 422)

    def test_04_database_lock_has_deadline(self):
        db = sqlite3.connect(self.temp.name + '/hub.sqlite')
        db.execute('BEGIN IMMEDIATE')
        started = time.monotonic()
        try:
            status, _, headers = self.request('app', {'id': 'locked', 'title': 'Test', 'enabled': True,
                'ttl': 60, 'version': 0, 'screens': [{'title': 'Test', 'rows': [{'label': 'A', 'value': 'B'}]}]})
            self.assertEqual(status, 503)
            self.assertIn('Retry-After', headers)
            self.assertLess(time.monotonic() - started, 3.5)
        finally: db.rollback(); db.close()
        self.assertEqual(self.request('state')[0], 200)

    def test_05_session_lock_has_deadline(self):
        session_id = next(cookie.value for cookie in self.cookies if cookie.name == 'badger_session')
        with open(self.temp.name + '/sessions/sess_' + session_id, 'r+') as handle:
            fcntl.flock(handle, fcntl.LOCK_EX)
            started = time.monotonic()
            self.assertEqual(self.request('state')[0], 503)
            self.assertLess(time.monotonic() - started, 2)
            fcntl.flock(handle, fcntl.LOCK_UN)
        self.assertEqual(self.request('state')[0], 200)

    def test_055_values_api_keeps_layout(self):
        token, body = self.app('values-app')
        body.update(version=1, publish_mode='values', screens=[{'title': 'Generic', 'rows': [
            {'label': 'Amount', 'field': 'amount', 'unit': 'kg', 'decimals': 2}]}])
        self.assertEqual(self.request('app', body)[0], 200)
        status = self.request('app-status', token=token)[1]
        self.assertEqual(status['publish_mode'], 'values')
        self.assertEqual(status['updated_at'], 0)
        revision = status['data_version']
        self.assertEqual(self.request('publish', {'version': 2, 'screens': []}, token=token)[0], 409)
        self.assertEqual(self.request('publish-values', {'version': revision, 'values': {'amount': 12.5}}, token=token)[0], 200)
        self.assertEqual(self.request('publish-values', {'version': revision, 'values': {'amount': 20}}, token=token)[0], 409)
        self.assertEqual(self.request('publish-values', {'version': revision + 1, 'values': {'amount': {}}}, token=token)[0], 422)
        self.assertEqual(self.request('publish-values', {'version': revision + 1, 'values': []}, token=token)[0], 422)
        # Same admin version remains valid after publishing data.
        body.update(version=2)
        body['screens'][0]['title'] = 'Renamed'
        self.assertEqual(self.request('app', body)[0], 200)
        apps = self.request('state')[1]['apps']
        app = next(app for app in apps if app['id'] == 'values-app')
        self.assertEqual(app['rendered_screens'][0]['rows'][0]['value'], '12.50 kg')
        self.assertEqual(app['screens'][0]['rows'][0]['field'], 'amount')
        device = self.request('device', {'id': 'data-badge', 'name': 'Data', 'enabled': True,
            'refresh': 60, 'version': 0, 'apps': ['values-app']})[1]['token']
        manifest = self.request('manifest', token=device)[1]['apps'][0]
        self.assertEqual(manifest['screens'][0]['title'], 'Renamed')
        self.assertEqual(set(manifest['screens'][0]['rows'][0]), {'label', 'value'})
        self.assertEqual(self.request('publish-values', {'version': 0, 'values': {'amount': 1}}, token=device)[0], 401)
        self.assertEqual(self.request('publish-values', {'version': revision + 1, 'values': {}, 'screens': []}, token=token)[0], 422)

    def test_06_login_rate_limit(self):
        for _ in range(10):
            status, _, _ = self.request('login', {'password': 'wrong-password'})
        self.assertEqual(status, 429)


    def test_07_backup_and_password_recovery(self):
        backup = self.temp.name + '/backup.sqlite'
        subprocess.run([PHP, str(ROOT / 'bin/maintenance.php'), 'backup', backup],
                       env=self.env, check=True, capture_output=True)
        db = sqlite3.connect(backup)
        self.assertEqual(db.execute('PRAGMA integrity_check').fetchone()[0], 'ok')
        db.close()
        self.assertEqual(os.stat(backup).st_mode & 0o777, 0o600)
        subprocess.run([PHP, str(ROOT / 'bin/maintenance.php'), 'password'],
                       input='replacement-test-password-1234\n', text=True, env=self.env, check=True, capture_output=True)
        self.assertEqual(self.request('state')[0], 401)
        subprocess.run([PHP, str(ROOT / 'bin/maintenance.php'), 'cleanup'],
                       env=self.env, check=True, capture_output=True)


if __name__ == '__main__': unittest.main()
