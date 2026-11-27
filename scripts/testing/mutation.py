"""Plan and reconcile isolated Pest mutation runs for the same CI candidate."""

import argparse
from collections import Counter
import hashlib
import json
import math
import os
from pathlib import Path, PurePosixPath
import re
import subprocess
import sys
import time


ROOT = Path(__file__).resolve().parents[2]
SUPPORT = ROOT / "scripts/testing/mutation"
MINIMUM_SCORE = 80
SHARD_COUNTS = (4, 8)
OUTCOMES = ("tested", "untested", "uncovered", "timeout")
IDENTITY_FIELDS = ("id", "file", "mutator", "start", "end")


def require(condition, message):
    if not condition:
        raise ValueError(message)


def unique_fields(pairs):
    result = dict(pairs)
    require(len(result) == len(pairs), "Duplicate JSON field.")
    return result


def invalid_constant(value):
    raise ValueError(f"Invalid JSON constant {value}.")


def decode(data):
    return json.loads(data, object_pairs_hook=unique_fields, parse_constant=invalid_constant)


def read_json(path):
    return decode(path.read_bytes())


def write_json(path, data):
    path.write_text(json.dumps(data, indent=2, sort_keys=True, allow_nan=False) + "\n", encoding="utf-8")


def configuration():
    files = [
        ROOT / "composer.lock", ROOT / "composer.json", ROOT / "phpunit.xml",
        ROOT / "tests/Pest.php", ROOT / ".github/actions/php/action.yml", Path(__file__).resolve(),
        *sorted(SUPPORT.glob("*.php")), *sorted((ROOT / ".github/workflows").glob("*.yml")),
    ]
    digest = hashlib.sha256()
    for path in files:
        digest.update(str(path.relative_to(ROOT)).encode() + b"\0")
        digest.update(hashlib.sha256(path.read_bytes()).digest())
    return digest.hexdigest()


def context():
    commit = subprocess.check_output(["git", "rev-parse", "HEAD"], text=True, cwd=ROOT).strip()
    require(os.environ.get("GITHUB_SHA", commit) == commit, "Checkout does not match the candidate SHA.")
    return {
        "commit": commit,
        "configuration": configuration(),
        "run": os.environ.get("GITHUB_RUN_ID", "local"),
        "attempt": os.environ.get("GITHUB_RUN_ATTEMPT", "local"),
    }


def identity_index(entries):
    require(isinstance(entries, list) and entries, "Mutation inventory is empty or invalid.")
    index = {}
    for entry in entries:
        require(isinstance(entry, dict) and all(key in entry for key in IDENTITY_FIELDS), "Invalid mutation identity.")
        identifier, source, mutator, start, end = (entry[key] for key in IDENTITY_FIELDS)
        require(isinstance(identifier, str) and re.fullmatch("[a-f0-9]{64}", identifier), "Invalid mutation ID.")
        require(identifier not in index, f"Duplicate mutation ID {identifier}.")
        require(isinstance(source, str) and "," not in source, "Invalid mutation source path.")
        path = PurePosixPath(source)
        in_scope = source == "bootstrap/app.php" or (
            len(path.parts) > 1 and path.parts[0] in ("app", "routes") and path.suffix == ".php"
        )
        require(in_scope and ".." not in path.parts and str(path) == source, "Mutation source is outside the recorded scope.")
        require(isinstance(mutator, str) and mutator.startswith("Pest\\Mutate\\Mutators\\"), "Invalid mutator.")
        require(type(start) is int and type(end) is int and 0 < start <= end, "Invalid mutation line range.")
        index[identifier] = {key: entry[key] for key in IDENTITY_FIELDS}
    return index


def expected_cost(source):
    if source.startswith(("bootstrap/", "routes/", "app/Providers/", "app/Http/Middleware/", "app/Support/")):
        return 4
    if source.startswith(("app/Http/Controllers/", "app/Console/")):
        return 2
    return 1


def partition(inventory, count):
    """Spread every source file, then use estimated load to resolve ties."""
    shards = {str(shard): [] for shard in range(1, count + 1)}
    loads = dict.fromkeys(shards, 0)
    per_file = {}
    entries = sorted(inventory.values(), key=lambda entry: (entry["file"], entry["start"], entry["id"]))
    for entry in entries:
        source = entry["file"]
        assignments = per_file.setdefault(source, Counter())
        shard = min(shards, key=lambda number: (assignments[number], loads[number], int(number)))
        shards[shard].append(entry["id"])
        assignments[shard] += 1
        loads[shard] += expected_cost(source)
    require(all(shards.values()), "One or more shards have no mutations.")
    return shards, loads


def validate_plan(plan, expected_context):
    require(isinstance(plan, dict) and type(plan.get("schema")) is int and plan["schema"] == 1, "Invalid mutation plan schema.")
    require(plan.get("context") == expected_context, "Mutation plan does not match this candidate, configuration and run attempt.")
    require(type(plan.get("count")) is int and plan["count"] in SHARD_COUNTS, "Invalid mutation shard count.")
    inventory = identity_index(plan.get("inventory"))
    shards, loads = partition(inventory, plan["count"])
    require(plan.get("shards") == shards and plan.get("estimated_cost") == loads, "Mutation shard assignment is incomplete or inconsistent.")
    return inventory


def plan(path, count):
    entries = decode(subprocess.check_output(
        ["php", "-d", "memory_limit=512M", str(SUPPORT / "inventory.php")], cwd=ROOT,
    ))
    inventory = identity_index(entries)
    shards, loads = partition(inventory, count)
    write_json(path, {
        "schema": 1, "context": context(), "count": count,
        "inventory": sorted(entries, key=lambda entry: entry["id"]),
        "shards": shards, "estimated_cost": loads,
    })
    files = len({entry["file"] for entry in entries})
    print(f"Planned {len(entries)} mutations across {files} files.")
    print(f"Estimated load by shard: {loads}")
    matrix = json.dumps({"shard": [int(shard) for shard in shards]}, separators=(",", ":"))
    if output := os.environ.get("GITHUB_OUTPUT"):
        with open(output, "a", encoding="utf-8") as stream:
            print(f"matrix={matrix}", file=stream)


def evidence_header(plan, plan_digest, shard):
    return {"schema": 1, "context": plan["context"], "plan_sha256": plan_digest, "shard": shard}


def run_shard(plan_path, shard, directory):
    source = plan_path.read_bytes()
    manifest = decode(source)
    inventory = validate_plan(manifest, context())
    assigned = manifest["shards"].get(str(shard))
    require(assigned is not None, "Shard is absent from the mutation plan.")
    directory = directory.resolve()
    directory.mkdir(parents=True)
    write_json(directory / "selection.json", {"inventory": sorted(inventory), "assigned": assigned})
    env = dict(os.environ, XDEBUG_MODE="coverage", EXPEDFLOW_MUTATION_EVIDENCE_DIR=str(directory))
    subprocess.run(
        ["php", "tests/Support/check-database.php"], cwd=ROOT, env=dict(env, APP_ENV="testing"), check=True,
    )
    command = [
        "php", "-d", "memory_limit=512M", "vendor/bin/pest", "--mutate",
        "--path=app,routes,bootstrap/app.php", "--no-cache",
        f"--bootstrap={SUPPORT / 'bootstrap.php'}", f"--log-junit={directory / 'pest-junit.xml'}",
    ]
    started = time.monotonic()
    exit_code = subprocess.run(command, cwd=ROOT, env=env).returncode
    elapsed = round(time.monotonic() - started, 3)
    generated_file = directory / "generated.json"
    outcomes_file = directory / "outcomes.json"
    report = evidence_header(manifest, hashlib.sha256(source).hexdigest(), shard) | {
        "exit_code": exit_code, "wall_seconds": elapsed,
        "generated": read_json(generated_file) if generated_file.is_file() else [],
        "outcomes": read_json(outcomes_file) if outcomes_file.is_file() else [],
    }
    write_json(directory / "report.json", report)
    reconcile_shard(report, manifest, inventory, hashlib.sha256(source).hexdigest(), shard)
    print(f"Shard {shard} completed {len(assigned)} mutations in {elapsed:.3f} seconds.")


def duration(value):
    return type(value) in (int, float) and math.isfinite(value) and value >= 0


def reconcile_shard(report, manifest, inventory, plan_digest, shard):
    require(isinstance(report, dict), f"Invalid shard {shard} report.")
    require(type(report.get("schema")) is int and type(report.get("shard")) is int, f"Invalid shard {shard} report identifiers.")
    require(all(report.get(key) == value for key, value in evidence_header(manifest, plan_digest, shard).items()),
            f"Shard {shard} evidence does not match this run or inventory.")
    require(type(report.get("exit_code")) is int and report["exit_code"] == 0, f"Shard {shard} Pest execution failed.")
    require(duration(report.get("wall_seconds")) and report["wall_seconds"] > 0, f"Invalid shard {shard} wall time.")
    assigned = {identifier: inventory[identifier] for identifier in manifest["shards"][str(shard)]}
    require(identity_index(report.get("generated")) == assigned, f"Shard {shard} did not generate its complete assignment.")
    require(identity_index(report.get("outcomes")) == assigned, f"Shard {shard} has missing or unexpected outcomes.")
    for record in report["outcomes"]:
        identifier = record["id"]
        require(record.get("result") in OUTCOMES, f"Invalid Pest outcome for {identifier}.")
        require(duration(record.get("seconds")), f"Invalid duration for {identifier}.")
        require("infrastructure_failure" in record and record["infrastructure_failure"] is None,
                f"Infrastructure failure for {record['file']}:{record['start']} ({identifier}): {record.get('infrastructure_failure')}")
        require(record["result"] != "timeout", f"Unverified mutation timeout for {identifier}.")
        native = record.get("native_id")
        require(isinstance(native, str) and re.fullmatch("[a-f0-9]{16}", native), f"Invalid Pest native ID for {identifier}.")
    return report["outcomes"]


def aggregate(manifest, reports, plan_digest, expected_context):
    inventory = validate_plan(manifest, expected_context)
    shards = set(range(1, manifest["count"] + 1))
    require(set(reports) == shards, "One or more shard reports are missing or unexpected.")
    results = []
    for shard in sorted(shards):
        results.extend(reconcile_shard(reports[shard], manifest, inventory, plan_digest, shard))
    require(identity_index(results) == inventory, "Combined results differ from the complete inventory.")
    require(len({result["native_id"] for result in results}) == len(results), "Duplicate native Pest mutation ID.")
    counts = Counter(result["result"] for result in results)
    total = len(inventory)
    detected = counts["tested"]
    wall_times = [reports[shard]["wall_seconds"] for shard in sorted(shards)]
    return {
        "schema": 1, "context": expected_context, "plan_sha256": plan_digest,
        "counts": {outcome: counts[outcome] for outcome in OUTCOMES},
        "total": total, "detected": detected, "score": detected * 100 / total,
        "passed": detected * 100 >= MINIMUM_SCORE * total,
        "shard_wall_seconds": wall_times,
        "runner_seconds": sum(wall_times), "longest_shard_seconds": max(wall_times),
        "worker_utilization": sum(wall_times) / (len(wall_times) * max(wall_times)),
        "results": sorted(results, key=lambda result: (result["file"], result["start"], result["id"])),
    }


def combine(plan_path, reports_directory, output):
    source = plan_path.read_bytes()
    reports = {}
    for path in reports_directory.glob("**/report.json"):
        matched = re.fullmatch(r"mutation-shard-([1-8])", path.relative_to(reports_directory).parts[0])
        require(matched is not None, f"Unexpected report artifact path {path}.")
        shard = int(matched[1])
        require(shard not in reports, f"Duplicate report for shard {shard}.")
        reports[shard] = read_json(path)

    result = aggregate(decode(source), reports, hashlib.sha256(source).hexdigest(), context())
    write_json(output, result)
    summary = (
        f"{result['detected']} detected of {result['total']} generated "
        f"({result['score']:.2f}%, minimum {MINIMUM_SCORE}%). "
        f"{result['counts']['untested']} survived, {result['counts']['uncovered']} uncovered. "
        f"Longest shard {result['longest_shard_seconds']:.1f}s, "
        f"mutation command runner time {result['runner_seconds']:.1f}s, "
        f"worker utilization {result['worker_utilization']:.1%}."
    )
    print(summary)
    if step_summary := os.environ.get("GITHUB_STEP_SUMMARY"):
        with open(step_summary, "a", encoding="utf-8") as stream:
            print("## Global mutation results\n\n" + summary, file=stream)
    require(result["passed"], "The global mutation score is below the required threshold.")


def main():
    cli = argparse.ArgumentParser(description=__doc__)
    commands = cli.add_subparsers(dest="mode", required=True)
    planner = commands.add_parser("plan")
    planner.add_argument("output", type=Path)
    planner.add_argument("shards", type=int, choices=SHARD_COUNTS)
    worker = commands.add_parser("run")
    worker.add_argument("plan", type=Path)
    worker.add_argument("shard", type=int)
    worker.add_argument("directory", type=Path)
    aggregator = commands.add_parser("aggregate")
    aggregator.add_argument("plan", type=Path)
    aggregator.add_argument("reports", type=Path)
    aggregator.add_argument("output", type=Path)
    args = cli.parse_args()
    if args.mode == "plan":
        plan(args.output, args.shards)
    elif args.mode == "run":
        run_shard(args.plan, args.shard, args.directory)
    else:
        combine(args.plan, args.reports, args.output)


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, subprocess.CalledProcessError) as error:
        sys.exit(str(error))
