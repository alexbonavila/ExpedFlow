"""Discover immutable evidence from successful runs of the repository workflow."""

from concurrent.futures import ThreadPoolExecutor
from datetime import datetime
import io
import json
import os
import re
import subprocess
from zipfile import BadZipFile, ZipFile

from mutation_impact import PEST_COMMAND, ROOT, RUNTIME_FILES, changed_paths, file_reasons, git, ignored, tree_files, tree_id


HISTORY_ARTIFACT = 'mutation-history-v2'
WORKFLOW = '.github/workflows/phase-b.yml'
SHA = re.compile(r'[a-f0-9]{40}')
DIGEST = re.compile(r'[a-f0-9]{64}')
NUMBER = re.compile(r'[1-9][0-9]*')
LEGACY_ENGINE = {
    'scripts/testing/mutation.py': 'a059161f7f6745c3a62dabdb9e169b6ca8a4ce1d',
    'scripts/testing/mutation/bootstrap.php': '66aeef65e8c75e1f0421a037e5c6a59a2548e5c7',
    'scripts/testing/mutation/identity.php': '521551e6b4abc3728e93f5e50b0fac3f1682d19f',
    'scripts/testing/mutation/inventory.php': 'c798a9b7d7d53891a91901e9b0ff3584e5f12b3c',
}
LEGACY_WORKFLOWS = {'77e7b88ccd5e876741a5f9a249b7604f2c7b7629', 'a243817a0eea74008898b01142e45888109db4f2'}
VERIFIERS = (
    'scripts/testing/mutation.py', 'scripts/testing/mutation_history.py', 'scripts/testing/mutation_impact.py',
    'scripts/testing/quality-gate.sh',
)
METADATA_ONLY = set(LEGACY_ENGINE) - {'scripts/testing/mutation/bootstrap.php', 'scripts/testing/mutation/inventory.php'}


def valid(value, expression):
    return isinstance(value, str) and expression.fullmatch(value) is not None


def timestamp(value):
    try:
        return isinstance(value, str) and datetime.fromisoformat(value.replace('Z', '+00:00')).utcoffset() is not None
    except ValueError:
        return False


def ancestor(commit, candidate):
    return valid(commit, SHA) and subprocess.run(
        ['git', 'merge-base', '--is-ancestor', commit, candidate], cwd=ROOT, capture_output=True,
    ).returncode == 0


def lineage(commit):
    head = os.environ.get('MUTATION_REF_HEAD') or commit
    base = os.environ.get('MUTATION_REF_BASE') or ''
    return {'head': head, 'base': base, 'tree': tree_id(commit)}


def trusted_branches():
    branch = os.environ.get('MUTATION_TRUSTED_BRANCH')
    return {'main', branch} - {'', None}


def eligible_run(run, workflow_id, repository, current, branches):
    event, branch = run.get('event'), run.get('head_branch')
    return (
        run.get('workflow_id') == workflow_id and run.get('path', '').split('@', 1)[0] == WORKFLOW
        and run.get('status') == 'completed' and run.get('conclusion') == 'success'
        and run.get('head_repository', {}).get('full_name') == repository
        and run.get('repository', {}).get('full_name') == repository
        and str(run.get('id')) != current['run'] and valid(str(run.get('id')), NUMBER)
        and valid(str(run.get('run_attempt')), NUMBER)
        and branch in branches and event in {'schedule', 'release', 'workflow_dispatch', 'pull_request'}
        and (event not in {'schedule', 'release'} or branch == 'main')
        and (event != 'pull_request' or branch != 'main')
        and ancestor(run.get('head_sha'), current['commit'])
    )


def verified_tree(history, run, candidate):
    context, source = history['context'], history['lineage']
    if context['run'] != str(run['id']) or context['attempt'] != str(run['run_attempt']) or source['head'] != run['head_sha']:
        return False
    if not all(valid(source[key], SHA) for key in ('head', 'tree')) or not valid(context['commit'], SHA):
        return False
    if run['event'] != 'pull_request':
        return not source['base'] and context['commit'] == source['head'] and tree_id(source['head']) == source['tree']
    if not ancestor(source['base'], candidate):
        return False
    # GitHub PR runs have a synthetic merge commit. Recreate its tree from the recorded parents.
    reconstructed = git('merge-tree', '--write-tree', source['base'], source['head']).decode().splitlines()[0]
    return reconstructed == source['tree']


class Actions:
    def __init__(self, repository):
        self.repository = repository

    def get(self, suffix, *parameters, binary=False):
        command = ['gh', 'api', '--method', 'GET', f'repos/{self.repository}/actions/{suffix}']
        for parameter in parameters:
            command += ['-f', parameter]
        content = subprocess.check_output(command, stderr=subprocess.DEVNULL, timeout=40)
        return content if binary else json.loads(content)

    def artifact(self, run, name):
        artifacts = self.get(f'runs/{run}/artifacts', f'name={name}')['artifacts']
        candidates = [a for a in artifacts if a['name'] == name and not a['expired']]
        if len(candidates) != 1:
            return None
        artifact = candidates[0]
        if artifact['workflow_run']['id'] != run or artifact['size_in_bytes'] > 16 * 1024 * 1024:
            return None
        binary = self.get(f"artifacts/{artifact['id']}/zip", binary=True)
        with ZipFile(io.BytesIO(binary)) as archive:
            files = archive.infolist()
            if (len(files) != 1 or files[0].file_size > 64 * 1024 * 1024
                    or files[0].filename != ('mutation-history.json' if name == HISTORY_ARTIFACT else 'mutation-summary.json')):
                return None
            # No path extraction or code loading from an artifact.
            from mutation import decode
            return decode(archive.read(files[0])), artifact['id']

    def verified_jobs(self, run):
        jobs = self.get(f"runs/{run['id']}/attempts/{run['run_attempt']}/jobs", 'per_page=100')['jobs']
        named = {job['name']: job for job in jobs}
        if any(named.get(name, {}).get('conclusion') != 'success' for name in ('mutation-plan', 'mutation', 'quality-gate')):
            return []
        workers = [j for j in jobs if re.fullmatch(r'mutation-shards \(\d+\)', j['name'])]
        if any(j['conclusion'] != 'success' for j in workers):
            return []
        # A valid zero-work plan still has a successful aggregate and gate.
        return jobs

    def legacy_runtime_matches(self, jobs, current):
        workers = [j for j in jobs if re.fullmatch(r'mutation-shards \(\d+\)', j['name'])]
        if len(workers) not in {4, 8} or current['os'] != 'ubuntu' or current['os_version'] != '24.04' or current['arch'] != 'x86_64':
            return False

        def matches(job):
            raw = self.get(f"jobs/{job['id']}/logs", binary=True).decode(errors='replace')
            log = re.sub(r'\x1b\[[0-9;]*m', '', raw)
            php = set(re.findall(r'Installed PHP (\d+\.\d+\.\d+)', log))
            xdebug = set(re.findall(r'Xdebug (\d+\.\d+\.\d+) enabled as coverage driver', log))
            return php == {current['php']} and xdebug == {current['xdebug']} and 'Image: ubuntu-24.04' in log

        with ThreadPoolExecutor(4) as pool:
            return all(pool.map(matches, workers))


def legacy_compatible(run, current, snapshot):
    if run['event'] == 'pull_request':
        return False
    old = tree_files(run['head_sha'])
    if old.get(WORKFLOW, '').split(':')[-1] not in LEGACY_WORKFLOWS:
        return False
    new = snapshot['files']
    if PEST_COMMAND != ('php', '-d', 'memory_limit=512M', 'vendor/bin/pest', '--mutate',
                        '--path=app,routes,bootstrap/app.php', '--no-cache') or snapshot['recipe']['env']:
        return False
    if any(old.get(path) != f'100644:{oid}' for path, oid in LEGACY_ENGINE.items()):
        return False
    if any(old.get(path) != new.get(path) for path in RUNTIME_FILES):
        return False
    workflow = git('show', f"{run['head_sha']}:{WORKFLOW}").decode()
    block = workflow.split('\n  mutation-shards:\n', 1)[1].split('\n  mutation:\n', 1)[0]
    return (
        f"image: {snapshot['runtime']['mysql']}" in block and 'coverage: xdebug' in block
        and all(ignored(path) or path in METADATA_ONLY for path in changed_paths(old, new))
        and all(line in block for line in snapshot['recipe']['worker'])
        and ancestor(run['head_sha'], current['commit'])
    )


def same_branch_verifier(files, historical_tree, current):
    if any(files.get(path) != current.get(path) for path in VERIFIERS):
        return False
    def semantic_workflow(ref):
        source = git('show', f'{ref}:{WORKFLOW}').decode()
        return re.sub(r'^\s*(?:timeout-minutes|retention-days): [0-9]+\s*$', '', source, flags=re.M)
    return semantic_workflow(historical_tree) == semantic_workflow('HEAD')



def discover(current, snapshot, inventory):
    """Return the newest compatible protected-main history or trusted same-repository branch evidence."""
    from mutation import full_reference, history_index, import_legacy

    repository = os.environ.get('GITHUB_REPOSITORY')
    if not os.environ.get('GH_TOKEN') or not repository:
        return None, None
    actions = Actions(repository)
    try:
        workflow_id = actions.get('workflows/phase-b.yml')['id']
        runs = []
        for branch in sorted(trusted_branches(), key=lambda branch: (branch != 'main', branch)):
            runs += actions.get('workflows/phase-b.yml/runs', 'status=success', f'branch={branch}', 'per_page=16')['workflow_runs']
    except (subprocess.SubprocessError, ValueError, KeyError, OSError):
        print('Historical mutation discovery is unavailable. Fresh evidence is required.')
        return None, None
    compatible = []
    full_scores = []
    for run in runs:
        try:
            if not eligible_run(run, workflow_id, repository, current, trusted_branches()):
                continue
            standard = actions.artifact(run['id'], HISTORY_ARTIFACT)
            legacy = standard is None
            if legacy and run['event'] == 'pull_request':
                continue
            if legacy and full_scores and not legacy_compatible(run, current, snapshot):
                continue
            payload = standard or actions.artifact(run['id'], 'mutation-results')
            if payload is None:
                continue
            evidence, artifact = payload
            jobs = actions.verified_jobs(run)
            if not jobs:
                continue
            if legacy:
                old = tree_files(run['head_sha'])
                if old.get(WORKFLOW, '').split(':')[-1] not in LEGACY_WORKFLOWS:
                    continue
                reference = full_reference(evidence, run)
                full_scores.append((run['updated_at'], reference))
                if not legacy_compatible(run, current, snapshot):
                    continue
                if not actions.legacy_runtime_matches(jobs, snapshot['runtime']):
                    continue
                evidence = import_legacy(evidence, run, snapshot, inventory)
            if not verified_tree(evidence, run, current['commit']):
                continue
            past_files = tree_files(evidence['lineage']['tree'])
            if evidence['snapshot']['files'] != past_files:
                continue
            if not legacy and run['head_branch'] != 'main' and not same_branch_verifier(past_files, evidence['lineage']['tree'], snapshot['files']):
                continue
            history_index(evidence, current['commit'])
            if not legacy and evidence['mode'] == 'full':
                full_scores.append((run['updated_at'], full_reference(evidence, run)))
            # Same-tree evidence can bootstrap without measuring the same mutations again.
            exact = all(ignored(path) or path in METADATA_ONLY
                        for path in changed_paths(evidence['snapshot']['files'], snapshot['files']))
            if legacy:
                evidence['snapshot'] = snapshot
            reasons = file_reasons(evidence['snapshot'], snapshot, {row['file'] for row in inventory.values()})
            if 'verified-equivalent' in reasons.values():
                compatible.append((exact, run['head_branch'] == 'main', evidence, artifact))
            if exact and compatible:
                break
        except (subprocess.SubprocessError, ValueError, KeyError, IndexError, TypeError, AttributeError, OSError, BadZipFile):
            print(f"Discarded incompatible mutation artifact from run {run.get('id')}.")
    latest_full = max(full_scores, key=lambda item: item[0])[1] if full_scores else None
    if not compatible:
        return None, latest_full
    _, _, evidence, artifact = max(compatible, key=lambda record: (record[0], record[1]))
    evidence['artifact_id'] = artifact
    return evidence, latest_full
