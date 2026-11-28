"""Measure G11 policy with bounded, real Pest mutations in a disposable fixture."""

from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time

from mutation import aggregate, context, decisions, mutation_index, partition, write_json
from mutation_impact import ROOT, coverage_snapshot


def write(root, name, contents):
    target = root / name
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(contents)


def controller(root, name, expression):
    write(root, f'app/Http/Controllers/{name}.php',
          '<?php\nfunction fixture' + name + '(int $n): bool\n{\n    return ' + expression + ';\n}\n')


def test(root, name, calls, dependencies=()):
    imports = ''.join(f"require_once __DIR__.'/../../app/Http/Controllers/{dep}.php';\n"
                      for dep in (name, *dependencies))
    assertions = ''.join(f"    expect(fixture{name}({value}))->{'toBeTrue' if result else 'toBeFalse'}();\n"
                         for value, result in calls)
    write(root, f'tests/Unit/{name}Test.php',
          f"<?php\n{imports}\ntest('{name} behavior', function (): void {{\n{assertions}}});\n")


def fixture(root):
    root.mkdir()
    (root / 'vendor').symlink_to(ROOT / 'vendor', target_is_directory=True)
    (root / 'routes').mkdir()
    write(root, 'bootstrap/app.php', '<?php\n')
    write(root, 'phpunit.xml', '''
<phpunit bootstrap="vendor/autoload.php">
    <testsuites><testsuite name="Fixture"><directory>tests/Unit</directory></testsuite></testsuites>
    <source><include><directory>app</directory></include></source>
</phpunit>
'''.lstrip())
    support = root / 'scripts/testing/mutation'
    support.mkdir(parents=True)
    for name in ('bootstrap.php', 'inventory.php', 'identity.php'):
        shutil.copyfile(ROOT / 'scripts/testing/mutation' / name, support / name)
    write(root, 'app/Http/Controllers/Threshold.php', '<?php\nfunction fixtureThreshold(): int\n{\n    return 3;\n}\n')
    controller(root, 'Caller', '$n > fixtureThreshold()')
    test(root, 'Caller', [(3, False), (6, True)], ['Threshold'])
    for name in ('Alpha', 'Beta'):
        controller(root, name, '$n > 0')
        test(root, name, [(-1, False), (0, False), (1, True), (2, True)])


def pest(root, *args, evidence=None):
    env = dict(os.environ, XDEBUG_MODE='coverage')
    if evidence is not None:
        env['EXPEDFLOW_MUTATION_EVIDENCE_DIR'] = str(evidence)
    process = subprocess.run(
        ['php', '-d', 'memory_limit=512M', 'vendor/bin/pest', *args], env=env, cwd=root,
        stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, timeout=30,
    )
    if process.returncode:
        raise RuntimeError(process.stdout)


def snapshot(root):
    started = time.monotonic()
    coverage, junit = root / 'coverage', root / 'junit.xml'
    pest(root, f'--coverage-xml={coverage}', f'--log-junit={junit}')
    entries = json.loads(subprocess.check_output(
        ['php', 'scripts/testing/mutation/inventory.php'], cwd=root,
    ))
    data = {
        'files': {p.relative_to(root).as_posix(): hashlib.sha256(p.read_bytes()).hexdigest()
                  for directory in ['app', 'tests', 'docs'] for p in (root / directory).rglob('*') if p.is_file()},
        'environment': 'e' * 64, 'coverage': coverage_snapshot(coverage, junit, root),
        'references': {'app/Http/Controllers/Threshold.php': ['app/Http/Controllers/Caller.php']},
    }
    return mutation_index(entries), data, time.monotonic() - started


def execute(root, mode, inventory, metadata, current, history=None):
    pending, reused, estimates = decisions(inventory, metadata, history, current, mode)
    shards, loads = partition({i: inventory[i] for i in pending}, 8, estimates)
    plan = {'schema': 2, 'mode': mode, 'count': 8, 'context': current, 'history': history,
            'lineage': {'head': current['commit'], 'base': ''}, 'snapshot': metadata,
            'shards': shards, 'estimated_cost': loads, 'planning_seconds': 0,
            'pending': pending, 'reused': reused, 'estimates': estimates, 'inventory': list(inventory.values())}
    reports = {}
    for worker, selected in shards.items():
        location = root / f'{mode}-{worker}'
        location.mkdir()
        write_json(location / 'selection.json', {'inventory': list(inventory), 'assigned': selected})
        started_wall, started = time.time(), time.monotonic()
        pest(root, '--mutate', '--path=app,routes,bootstrap/app.php', '--no-cache',
             f'--bootstrap={root}/scripts/testing/mutation/bootstrap.php',
             f'--log-junit={location}/pest-junit.xml', evidence=location)
        generated = location / 'generated.json'
        elapsed = time.monotonic() - started
        reports[int(worker)] = {
            'schema': 2, 'context': current, 'plan_sha256': 'fixture', 'shard': int(worker),
            'exit_code': 0, 'wall_seconds': elapsed, 'executed_at': datetime.now(timezone.utc).isoformat(),
            'preparation_seconds': min(elapsed, max(0, generated.stat().st_mtime - started_wall)),
            'generated': json.loads(generated.read_text()),
            'outcomes': json.loads((location / 'outcomes.json').read_text()),
        }
    return aggregate(plan, reports, 'fixture', current)


def outcomes(result):
    return {row['id']: row['result'] for row in result['results']}


def main():
    cases = {
        'no relevant changes': lambda root: None,
        'documentation only': lambda root: write(root, 'docs/CI.md', 'Updated documentation.\n'),
        'small application change': lambda root: controller(root, 'Alpha', '$n >= 1'),
        'changed test': lambda root: test(root, 'Alpha', [(-1, False), (0, False), (1, True), (2, True), (3, True)]),
        'new isolated module': lambda root: (
            controller(root, 'Gamma', '$n > 0'),
            test(root, 'Gamma', [(-1, False), (0, False), (1, True), (2, True)]),
        ),
        'shared dependency change': lambda root: write(
            root, 'app/Http/Controllers/Threshold.php', '<?php\nfunction fixtureThreshold(): int\n{\n    return 4;\n}\n',
        ),
    }
    candidate = context() | {'run': '1', 'attempt': '1'}
    with tempfile.TemporaryDirectory(prefix='expedflow-mutation-fixture-') as temporary:
        directory = Path(temporary) / 'fixture'
        fixture(directory)
        inventory, metadata, _ = snapshot(directory)
        history = execute(directory, 'full', inventory, metadata, candidate)
        assert history['passed']
        for index, (label, change) in enumerate(cases.items(), 2):
            shutil.rmtree(directory)
            fixture(directory)
            change(directory)
            start = time.monotonic()
            inventory, metadata, prep = snapshot(directory)
            candidate = candidate | {'run': str(index)}
            result = execute(directory, 'incremental', inventory, metadata, candidate, history)
            elapsed = time.monotonic() - start
            full_start = time.monotonic()
            fresh = execute(directory, 'full', inventory, metadata, candidate)
            full_wall = time.monotonic() - full_start + prep
            if outcomes(result) != outcomes(fresh):
                raise AssertionError(f'Incremental outcomes differ from independent full execution: {label}')
            if label == 'shared dependency change':
                changed = [row for row in result['results'] if row['file'].endswith('/Caller.php')
                           and row['mutator'].endswith('GreaterToGreaterOrEqual')]
                assert changed[0]['result'] == 'untested' and changed[0]['origin']['run'] == str(index)
                assert outcomes(history)[changed[0]['id']] == 'tested'
            if label in ('no relevant changes', 'documentation only'):
                assert result['evaluated'] == 0
            else:
                if not 0 < result['evaluated'] < result['total']:
                    from mutation_impact import file_reasons
                    reasons = file_reasons(history['snapshot'], metadata, {r['file'] for r in inventory.values()})
                    raise AssertionError(f"Unexpected fixture reuse for {label}: {reasons}")
            workers = result['workers'].values()
            print(json.dumps({
                'scenario': label, 'total': result['total'], 'fresh': result['evaluated'], 'reused': result['reused'],
                'hit_percent': round(100 * result['reused'] / result['total'], 1),
                'workers': len(result['workers']), 'prepare_seconds': round(prep + sum(w['preparation_seconds'] for w in workers), 3),
                'execution_seconds': round(sum(w['execution_seconds'] for w in workers), 3),
                'wall_seconds': round(elapsed, 3), 'runner_seconds': round(prep + result['runner_seconds'], 3),
                'independent_full_wall_seconds': round(full_wall, 3), 'fresh_score': result['fresh_score'],
            }))


if __name__ == '__main__':
    main()
