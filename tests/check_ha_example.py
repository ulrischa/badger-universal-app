"""Optional template checks, not a Home Assistant runtime test (PyYAML + Jinja2)."""
import json
import math
from pathlib import Path
import yaml
from jinja2 import StrictUndefined
from jinja2.nativetypes import NativeEnvironment

class Loader(yaml.SafeLoader):
    pass
Loader.add_constructor('!secret', lambda loader, node: loader.construct_scalar(node))
package = yaml.load((Path(__file__).resolve().parents[1] / 'examples/home-assistant-package.yaml').read_text(), Loader=Loader)
env = NativeEnvironment(undefined=StrictUndefined)
env.filters['to_json'] = json.dumps

def is_number(value):
    try:
        return math.isfinite(float(value))
    except (TypeError, ValueError):
        return False

def render(value, context):
    if isinstance(value, str):
        return env.from_string(value).render(context)
    if isinstance(value, list):
        return [render(item, context) for item in value]
    if isinstance(value, dict):
        return {key: render(item, context) for key, item in value.items()}
    return value

def run(app, source=None, sensor='21.4', status=200, publish_status=200):
    context = {'is_number': is_number, 'states': lambda entity: sensor}
    calls = []
    for step in package['script']['badger_publish_' + app]['sequence']:
        if 'variables' in step:
            context.update(render(step['variables'], context))
        elif 'if' in step:
            if render(step['if'], context):
                assert step['then'][0]['error'] is True
                return calls, None
        else:
            action = step['action']
            if action.endswith('read_bitaxe'):
                response = {'status': 200, 'content': source}
            elif action.endswith('_status'):
                response = {'status': status, 'content': {'version': 7}}
            else:
                body = render(step['data'], context)
                payload = render(package['rest_command'][action.split('.')[1]]['payload'], body)
                if isinstance(payload, str):
                    payload = json.loads(payload)
                assert payload['version'] == 7
                for screen in payload['screens']:
                    assert 1 <= len(screen['rows']) <= 3
                    for row in screen['rows']:
                        for key, limit in [('label', 14), ('value', 22)]:
                            assert isinstance(row[key], str) and 1 <= len(row[key]) <= limit
                            assert all(32 <= ord(char) <= 126 for char in row[key])
                calls.append(payload)
                response = {'status': publish_status, 'content': {}}
            context[step['response_variable']] = response
    return calls, True

valid = {'hashRate': 1100.5, 'temp': 56.1, 'power': 18.5}
for app in ['bitaxe', 'home']:
    calls, success = run(app, valid)
    assert success and len(calls) == 1
    assert not run(app, valid, status=401)[0]
    calls, success = run(app, valid, publish_status=409)
    assert len(calls) == 1 and success is None  # No replay after conflict.
for invalid in [None, {}, 'bad response', {**valid, 'power': 'unknown'}, {**valid, 'temp': 999}]:
    assert not run('bitaxe', invalid)[0]
for sensor in ['unknown', 'unavailable', 'nan', 'inf', '999']:
    assert not run('home', sensor=sensor)[0]
assert run('bitaxe', valid)[0][0]['screens'][0]['rows'][0]['value'] == '1100.5 GH/s'
assert run('home')[0][0]['screens'][0]['rows'][1]['value'] == '21 %'
print('HA example: YAML, templates, display bounds, missing sources and rejected publishes checked')
