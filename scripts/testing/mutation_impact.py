"""Compare complete test coverage snapshots and Git inputs at file granularity."""

from collections import defaultdict
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import subprocess
from xml.etree import ElementTree


ROOT = Path(__file__).resolve().parents[2]
LOCAL_SOURCE = ('app/Http/Controllers/', 'app/Http/Requests/', 'app/Http/Resources/')
TEST_SUITES = ('tests/Feature/', 'tests/Integration/', 'tests/Unit/', 'tests/Contract/', 'tests/Architecture/')
PEST_COMMAND = ("php", "-d", "memory_limit=512M", "vendor/bin/pest", "--mutate",
                "--path=app,routes,bootstrap/app.php", "--no-cache")
RUNTIME_FILES = (
    'composer.json', 'composer.lock', 'phpunit.xml', '.github/actions/php/action.yml',
    'scripts/testing/ci-mysql.sh', '.env.testing.example',
)


def git(*args):
    return subprocess.check_output(['git', *args], cwd=ROOT)


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(',', ':')).encode()).hexdigest()


def tree_files(commit):
    files = {}
    for record in git('ls-tree', '-rz', commit).split(b'\0'):
        if record:
            entry, path = record.decode().split('\t', 1)
            mode, _, oid = entry.split()
            files[path] = f'{mode}:{oid}'
    return files


def tree_id(commit):
    return git('rev-parse', f'{commit}^{{tree}}').decode().strip()


def snapshot_environment(files, runtime, worker_recipe):
    return digest({
        'engine': {k: v for k, v in files.items() if k.startswith('scripts/testing/mutation/') and not k.endswith('/routes.php')},
        'inputs': {file: files.get(file) for file in RUNTIME_FILES},
        'coverage_protocol': 1, 'runtime': runtime, 'recipe': worker_recipe, 'pest': PEST_COMMAND,
    })


def runtime():
    php = json.loads(subprocess.check_output([
        'php', '-r', 'echo json_encode([PHP_VERSION, phpversion("xdebug"), phpversion("pdo_mysql"), php_uname("m")]);',
    ]))
    if not php[1] or not php[2]:
        raise ValueError('Mutation runtime requires Xdebug and PDO MySQL.')
    os_version = Path('/etc/os-release').read_text()
    workflow = (ROOT / '.github/workflows/phase-b.yml').read_text()
    worker = workflow.split('\n  mutation-shards:\n', 1)[1].split('\n  mutation:\n', 1)[0]
    image = re.search(r'^        image: (mysql@sha256:[a-f0-9]{64})$', worker, re.M)
    backend = workflow.split('\n  backend-tests:\n', 1)[1].split('\n  database-tests:\n', 1)[0]
    if image is None or image[1] not in backend:
        raise ValueError('The coverage job and mutation workers must use the same pinned MySQL image.')
    versions = {key: value.strip('"') for key, value in (line.split('=', 1) for line in os_version.splitlines() if '=' in line)}
    return {'php': php[0], 'xdebug': php[1], 'pdo_mysql': php[2], 'arch': php[3],
            'os': versions['ID'], 'os_version': versions['VERSION_ID'], 'mysql': image[1]}


def work_recipe():
    workflow = (ROOT / '.github/workflows/phase-b.yml').read_text()
    worker = workflow.split('\n  mutation-shards:\n', 1)[1].split('\n  mutation:\n', 1)[0]
    backend = workflow.split('\n  backend-tests:\n', 1)[1].split('\n  database-tests:\n', 1)[0]
    shared_env = workflow.split('\nenv:\n', 1)[1].split('\n\n', 1)[0] if '\nenv:\n' in workflow else ''
    # Job budgets and artifact paths do not affect Pest or coverage collection.
    relevant = r'\s*(runs-on:|image:|coverage:|memory-limit:|MYSQL_[A-Z_]+:|run: bash scripts/testing/ci-mysql.sh)'
    return {
        'worker': [line.strip() for line in worker.splitlines() if re.match(relevant, line)],
        'impact': [line.strip() for line in backend.splitlines() if re.match(relevant, line)
                   or 'composer test:coverage' in line or 'mutation.py prepare' in line],
        'env': [line.strip() for line in shared_env.splitlines() if not line.strip().startswith('MUTATION_')],
    }


def clean_path(path):
    return (isinstance(path, str) and bool(path) and not path.startswith('/')
            and '..' not in PurePosixPath(path).parts and str(PurePosixPath(path)) == path)


def known_test(path):
    return path.startswith(TEST_SUITES) and path.endswith('Test.php')


def coverage_snapshot(directory, junit, root=ROOT):
    classes = {}
    for case in ElementTree.parse(junit).iter('testcase'):
        name = case.attrib['class'].removeprefix('P\\')
        path = case.attrib['file'].split('::', 1)[0]
        if Path(path).is_absolute():
            path = Path(path).relative_to(root).as_posix()
        if not clean_path(path) or not path.startswith('tests/') or classes.setdefault(name, path) != path:
            raise ValueError(f'Could not attribute PHPUnit class {name}.')
    if not classes:
        raise ValueError('The impact coverage report has no executed tests.')
    coverage = {}
    index = ElementTree.parse(directory / 'index.xml')
    project = index.find('{*}project')
    if project is None:
        raise ValueError('Missing PHPUnit coverage index.')
    source_root = Path(project.attrib['source']).resolve().relative_to(root.resolve())
    for node in project.iterfind('.//{*}file'):
        href = node.attrib['href']
        if not clean_path(href) or not href.endswith('.php.xml'):
            raise ValueError('Invalid PHPUnit coverage entry.')
        file = ElementTree.parse(directory / href).find('{*}file')
        path = (source_root / file.attrib['path'].strip('/') / file.attrib['name']).as_posix()
        if not clean_path(path) or path in coverage:
            raise ValueError('Invalid or duplicate PHPUnit coverage source path.')
        by_test = defaultdict(list)
        for line in file.iterfind('{*}coverage/{*}line'):
            for hit in line.findall('{*}covered'):
                test = hit.attrib['by']
                name = test.split('::', 1)[0].removeprefix('P\\')
                if name not in classes:
                    raise ValueError(f'Unknown PHPUnit coverage owner {name}.')
                by_test[classes[name]].append([test, int(line.attrib['nr'])])
        coverage[path] = {test: digest(sorted(lines)) for test, lines in by_test.items()}
    if not coverage or not any(coverage.values()):
        raise ValueError('No usable per-test coverage was produced.')
    return {'tests': sorted(set(classes.values())), 'files': coverage}


def ignored(path):
    return (path.startswith('docs/') or path in {'AGENTS.md', 'README.md', 'CONTRIBUTING.md', 'LICENSE'}
            or path.startswith('.github/') or path in {
                'scripts/testing/mutation.py', 'scripts/testing/mutation_impact.py',
                'scripts/testing/mutation_history.py', 'scripts/testing/test_mutation.py',
                'scripts/testing/quality-gate.sh', 'scripts/testing/mutation/routes.php',
                'scripts/testing/benchmark_mutation.py',
            })


def changed_paths(before, after):
    return {path for path in before.keys() | after.keys() if before.get(path) != after.get(path)}


def additive_routes(changed, previous, current):
    """Accept literal new API endpoints in new prefixes after a require-only insertion."""
    entry = 'routes/api.php'
    sources = current.get('route_sources', {})
    old = previous.get('route_sources', {}).get(entry)
    new = sources.get(entry)
    if entry not in changed or not isinstance(old, str) or not isinstance(new, str):
        return set()
    include = re.compile(r"^ +require __DIR__\.'/([a-z][a-z0-9_-]*\.php)';$")
    old_lines = old.splitlines()
    new_lines = new.splitlines()
    remaining = iter(old_lines)
    expected = next(remaining, None)
    additions = set()
    for line in new_lines:
        if line == expected:
            expected = next(remaining, None)
        elif match := include.fullmatch(line):
            additions.add('routes/' + match[1])
        else:
            return set()
    if expected is not None or not additions or not additions <= changed - previous['files'].keys():
        return set()
    # These files contain only explicit Route facade declarations; no boot-time statements.
    allowed = re.compile(r"Route::(?:get|post|put|patch|delete)\('[a-z0-9/-]+'\s*,\s*\[[A-Za-z_][A-Za-z0-9_]*::class,\s*'[A-Za-z_][A-Za-z0-9_]*'\]\);")
    for path in additions:
        lines = [line.strip() for line in sources.get(path, '').splitlines() if line.strip()]
        if not lines or lines[0] != '<?php' or not any(allowed.fullmatch(line) for line in lines):
            return set()
        if not all(line == '<?php' or re.fullmatch(r'use [A-Za-z_][A-Za-z0-9_\\]*;', line) or allowed.fullmatch(line) for line in lines):
            return set()
    old_routes = previous.get('routes', [])
    new_routes = current.get('routes', [])
    if not old_routes or [route for route in new_routes if route in old_routes] != old_routes:
        return set()
    added = [route for route in new_routes if route not in old_routes]
    prefixes = set()
    for route in old_routes:
        parts = route[0].split('/')
        if parts[:2] == ['api', 'v1']:
            prefixes.add(parts[2] if len(parts) > 2 else '')
        elif any('{' in part for part in parts[:2]):
            return set()
    if not added or any('{' in p for p in prefixes):
        return set()
    for route in added:
        parts = route[0].split('/')
        if len(parts) < 3 or parts[:2] != ['api', 'v1'] or parts[2] in prefixes or '{' in route[0]:
            return set()
    if len({(tuple(route[1]), route[0]) for route in new_routes}) != len(new_routes):
        return set()
    return {entry, *additions}


def file_reasons(previous, current, sources):
    if previous is None:
        return dict.fromkeys(sources, 'missing-compatible-history')
    if previous['environment'] != current['environment']:
        return dict.fromkeys(sources, 'execution-environment-changed')
    changed = changed_paths(previous['files'], current['files'])
    if not changed - {p for p in changed if ignored(p)}:
        return dict.fromkeys(sources, 'verified-equivalent')
    if not previous.get('coverage') or not current.get('coverage'):
        return dict.fromkeys(sources, 'impact-coverage-unavailable')
    old_cov, new_cov = previous['coverage'], current['coverage']
    safe_routes = additive_routes(changed, previous, current)
    semantic = {p for p in changed if not ignored(p)} - safe_routes
    before, after = old_cov['files'], new_cov['files']
    tests = set(old_cov['tests']) | set(new_cov['tests'])
    test_changes = {p for p in semantic if known_test(p) and p in tests}
    app_changes = {p for p in semantic if p.startswith(LOCAL_SOURCE)}
    if semantic - test_changes - app_changes:
        return dict.fromkeys(sources, 'shared-or-unattributed-input-changed')

    impacted_tests = set(test_changes)
    for path in app_changes:
        impacted_tests.update(before.get(path, {}))
        impacted_tests.update(after.get(path, {}))
    # A changed controller may also be a dependency in a branch activated only by a mutant.
    callers = set(app_changes)
    for path, references in current.get('references', {}).items():
        if path in app_changes:
            callers.update(references)
    for path, references in previous.get('references', {}).items():
        if path in app_changes:
            callers.update(references)

    reasons = {}
    for path in sources:
        old_hit, new_hit = before.get(path, {}), after.get(path, {})
        if path in changed:
            reason = 'source-changed'
        elif path in callers:
            reason = 'application-dependency-changed'
        elif old_hit != new_hit:
            reason = 'test-coverage-changed'
        elif impacted_tests.intersection(old_hit.keys() | new_hit.keys()):
            reason = 'relevant-test-or-dependency-changed'
        else:
            reason = 'verified-equivalent'
        reasons[path] = reason
    return reasons


def lexical_callers(files):
    paths = [p for p in files if p.startswith('app/') and p.endswith('.php')]
    source = {path: (ROOT / path).read_text() for path in paths}
    callers = {}
    for path in paths:
        if path.startswith(LOCAL_SOURCE):
            name = re.compile(r'\b' + re.escape(PurePosixPath(path).stem) + r'\b')
            callers[path] = sorted(candidate for candidate in paths if candidate != path and name.search(source[candidate]))
    # Include transitive references, including callers through support classes.
    visited = dict(callers)
    for path, direct in callers.items():
        stack, seen = list(direct), {path}
        while stack:
            caller = stack.pop()
            if caller not in seen:
                seen.add(caller)
                lookup = re.compile(r'\b' + re.escape(PurePosixPath(caller).stem) + r'\b')
                stack.extend(candidate for candidate in paths if candidate not in seen and lookup.search(source[candidate]))
        visited[path] = sorted(seen - {path})
    return visited
