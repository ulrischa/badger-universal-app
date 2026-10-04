import copy
import json
import os
import random
import sys
import tempfile
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'device'))
from hub_core import Navigation, validate, decode, load_cache, save_cache, retry_seconds


def fixture():
    return {'schema': 1, 'generated_at': 1700000000, 'refresh_seconds': 900, 'apps': [
        {'id': 'energy', 'title': 'Energy', 'updated_at': 1700000000, 'ttl': 900,
         'screens': [{'title': 'PV', 'rows': [{'label': 'Power', 'value': '5 kW'}]},
                     {'title': 'Battery', 'rows': [{'label': 'SOC', 'value': '84 %'}]}]}]}


class DeviceTests(unittest.TestCase):
    def test_navigation_always_escapes(self):
        nav = Navigation(fixture())
        rng = random.Random(7)
        for _ in range(10000):
            nav.press(rng.choice(['A', 'B', 'C', 'UP', 'DOWN']))
            self.assertLess(nav.page, len(nav.current_app()['screens']))
            nav.press('C')
            self.assertFalse(nav.in_app)

    def test_removed_app_returns_home(self):
        nav = Navigation(fixture()); nav.press('A')
        replacement = fixture(); replacement['apps'] = []
        nav.replace(replacement)
        self.assertFalse(nav.in_app)
        for key in ['A', 'B', 'C', 'UP', 'DOWN']:
            nav.press(key)
        self.assertIsNone(nav.current_app())

    def test_cache_keeps_valid_slot(self):
        with tempfile.TemporaryDirectory() as directory:
            prefix = directory + '/cache'
            save_cache(fixture(), prefix)
            later = fixture(); later['generated_at'] += 1
            save_cache(later, prefix)
            self.assertEqual(load_cache(prefix)['generated_at'], later['generated_at'])
            with open(prefix + '.b.json', 'w') as handle:
                handle.write('{truncated')
            self.assertEqual(load_cache(prefix)['generated_at'], fixture()['generated_at'])
            with open(prefix + '.a.json', 'w') as handle:
                handle.write('invalid')
            self.assertEqual(load_cache(prefix)['apps'], [])

    def test_corrupt_manifest_rejected(self):
        mutations = [lambda d: d.update(schema=2), lambda d: d.update(refresh_seconds=True),
                     lambda d: d['apps'][0].update(screens=[]),
                     lambda d: d['apps'][0].update(title='Bad\nTitle'),
                     lambda d: d['apps'][0].update(id='../x'),
                     lambda d: d['apps'].append(copy.deepcopy(d['apps'][0])),
                     lambda d: d.update(buttons={'C': 'locked'})]
        for mutate in mutations:
            data = fixture(); mutate(data)
            with self.assertRaises(ValueError): validate(data)
        with self.assertRaises(ValueError): decode(b'x' * 32769)
        with self.assertRaises(ValueError): decode(b'[' * 100 + b']' * 100)
        with self.assertRaises(ValueError): decode(b'[' + b'[],' * 1000 + b'[]]')
        with self.assertRaises(ValueError): decode(b'[[[')
        with self.assertRaises(ValueError): decode(b'[' + b'0,' * 1500 + b'0]')

    def test_refresh_preserves_app_identity_after_reorder(self):
        data = fixture(); second = copy.deepcopy(data['apps'][0]); second['id'] = 'weather'
        data['apps'].append(second)
        nav = Navigation(data); nav.press('DOWN'); nav.press('A')
        data = copy.deepcopy(data); data['apps'].reverse(); nav.replace(data)
        self.assertEqual(nav.current_app()['id'], 'weather')
        self.assertTrue(nav.in_app)

    def test_backoff_bounded(self):
        self.assertEqual([retry_seconds(i) for i in range(1, 7)], [30, 60, 120, 240, 480, 900])
        self.assertEqual(retry_seconds(1000000), 900)


if __name__ == '__main__': unittest.main()
