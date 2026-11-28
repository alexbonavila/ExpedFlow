"""Plan and reconcile isolated Pest mutation runs for the same CI candidate."""

import argparse
from collections import Counter
from datetime import datetime, timezone
import hashlib
import json
import math
import os
from pathlib import Path, PurePosixPath
import re
import subprocess
import sys
import time


from mutation_history import DIGEST, NUMBER, SHA, ancestor, discover, lineage, timestamp, valid
from mutation_impact import (
    PEST_COMMAND, ROOT, coverage_snapshot, digest, file_reasons, git, lexical_callers, runtime, snapshot_environment,
    tree_files, work_recipe,
)

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
        *sorted(SUPPORT.glob("*.php")), *sorted(ROOT.glob("scripts/testing/mutation_*.py")), *sorted((ROOT / ".github/workflows").glob("*.yml")),
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


def partition(inventory, count, estimates=None):
    """Allocate pending work by longest estimated processing time with stable ties."""
    estimates = estimates if estimates is not None else {
        identifier: expected_cost(entry["file"]) for identifier, entry in inventory.items()
    }
    # Unknown jobs are measured in estimated seconds. Avoid bringing up another DB for < 30s of work.
    active = min(count, len(inventory), math.ceil(sum(estimates.values()) / 30))
    if inventory:
        active = max(1, active)
    shards = {str(shard): [] for shard in range(1, active + 1)}
    loads = dict.fromkeys(shards, 0)
    for identifier in sorted(inventory, key=lambda key: (-estimates[key], key)):
        shard = min(shards, key=lambda number: (loads[number], len(shards[number]), int(number)))
        shards[shard].append(identifier)
        loads[shard] += estimates[identifier]
    return shards, loads


def mutation_index(entries):
    index = identity_index(entries)
    for entry in entries:
        value = entry.get("mutated_sha256")
        require(valid(value, DIGEST), "Missing or invalid rendered mutant fingerprint.")
        index[entry["id"]]["mutated_sha256"] = value
    return index


def outcome(row):
    require(row.get("result") in OUTCOMES, f"Invalid Pest outcome for {row['id']}.")
    require(duration(row.get("seconds")), f"Invalid duration for {row['id']}.")
    require("infrastructure_failure" in row and row["infrastructure_failure"] is None,
            f"Infrastructure failure for {row['file']}:{row['start']} ({row['id']}): {row.get('infrastructure_failure')}")
    require(row["result"] != "timeout", f"Unverified mutation timeout for {row['id']}.")
    require(isinstance(row.get("native_id"), str) and re.fullmatch("[a-f0-9]{16}", row["native_id"]),
            f"Invalid Pest native ID for {row['id']}.")


def evidence_origin(execution, source, completed_at, kind="fresh"):
    return {"commit": execution["commit"], "head": source["head"], "base": source["base"],
            "run": execution["run"], "attempt": execution["attempt"], "executed_at": completed_at, "kind": kind}


def history_index(history, candidate):
    require(isinstance(history, dict) and history.get("schema") == 2 and history.get("passed") is True,
            "Unsupported or unsuccessful mutation history.")
    require(history.get("mode") in {"full", "incremental"}, "Missing historical execution mode.")
    result = mutation_index(history["results"])
    require(history["total"] == len(result), "Historical inventory does not reconcile.")
    require(isinstance(history["snapshot"]["files"], dict) and valid(history["snapshot"]["environment"], DIGEST),
            "Invalid historical fingerprints.")
    coverage = history["snapshot"].get("coverage")
    if coverage is not None:
        require({row["file"] for row in result.values()} <= coverage["files"].keys(),
                "Historical coverage omits mutation source files.")
    for row in history["results"]:
        outcome(row)
        origin = row["origin"]
        require(origin.get("kind") in {"fresh", "legacy"} and timestamp(origin.get("executed_at"))
                and valid(origin.get("run"), NUMBER) and valid(origin.get("attempt"), NUMBER)
                and valid(origin.get("commit"), SHA) and valid(origin.get("head"), SHA)
                and (origin.get("base") == "" or valid(origin.get("base"), SHA)),
                "Invalid historical execution provenance.")
        require(row["source"] == history["snapshot"]["files"].get(row["file"]) and row["source"],
                "Historical source fingerprint differs.")
        require(row["impact"] == impact_fingerprint(history["snapshot"], row["file"]),
                "Invalid historical test or dependency fingerprints.")
    require(len({r["native_id"] for r in history["results"]}) == len(result), "Duplicate historical native ID.")
    detected = sum(row["result"] == "tested" for row in history["results"])
    require(history["score"] == detected * 100 / len(result)
            and detected * 100 >= MINIMUM_SCORE * len(result), "Historical mutation score does not reconcile.")
    evaluated, reused = history["evaluated"], history["reused"]
    require(type(evaluated) is int and type(reused) is int and evaluated >= 0 and reused >= 0
            and evaluated + reused == len(result) and (history["mode"] != "full" or reused == 0),
            "Historical fresh and reused totals do not reconcile.")
    for head, base, execution in {(r["origin"]["head"], r["origin"]["base"], r["origin"]["commit"]) for r in history["results"]}:
        require(ancestor(head, candidate) and (ancestor(base, candidate) if base else execution == head),
                "Historical execution is outside candidate ancestry.")
    return {row["id"]: row for row in history["results"]}


def impact_fingerprint(snapshot, source):
    coverage = snapshot.get("coverage") or {}
    tests = coverage.get("files", {}).get(source, {})
    # Dependency footprints are checked over both coverage snapshots by file_reasons().
    references = sorted(path for path, callers in snapshot.get("references", {}).items() if source in callers)
    files = snapshot["files"]
    return digest({"tests": {path: [files.get(path), hit] for path, hit in tests.items()},
                   "dependencies": {path: files.get(path) for path in references},
                   "environment": snapshot["environment"]})


def full_reference(evidence, run):
    execution = evidence["context"]
    require(evidence.get("passed") is True and execution["commit"] == run["head_sha"]
            and execution["run"] == str(run["id"]) and execution["attempt"] == str(run["run_attempt"]),
            "Full score is from another execution.")
    rows = evidence["results"]
    index = identity_index(rows)
    for row in rows:
        outcome(row)
    detected = sum(row["result"] == "tested" for row in rows)
    require(evidence["total"] == len(index) and evidence["score"] == detected * 100 / len(index)
            and detected * 100 >= MINIMUM_SCORE * len(index), "Full score does not reconcile.")
    return {"commit": execution["commit"], "run": execution["run"], "attempt": execution["attempt"],
            "score": evidence["score"], "total": len(index)}


def import_legacy(legacy, run, snapshot, inventory):
    require(legacy.get("schema") == 1 and legacy.get("passed") is True, "Legacy mutation validation was unsuccessful.")
    execution = legacy["context"]
    require(execution["commit"] == run["head_sha"] and execution["run"] == str(run["id"])
            and execution["attempt"] == str(run["run_attempt"]), "Legacy evidence is from another run or attempt.")
    require(identity_index(legacy["results"]) == identity_index(list(inventory.values())),
            "Legacy mutation inventory differs.")
    source = {"head": execution["commit"], "base": "", "tree": lineage(execution["commit"])["tree"]}
    origin = evidence_origin(execution, source, run["updated_at"], kind="legacy")
    old = snapshot | {"files": tree_files(execution["commit"])}
    records = []
    for row in legacy["results"]:
        file = row["file"]
        records.append(row | {"mutated_sha256": inventory[row["id"]]["mutated_sha256"], "origin": origin,
                              "source": old["files"][file], "impact": impact_fingerprint(old, file)})
    return {"schema": 2, "mode": "full", "passed": True, "context": execution, "lineage": source,
            "snapshot": old, "total": len(records), "results": records,
            "evaluated": len(records), "reused": 0, "score": legacy["score"],
            "latest_full": {"commit": execution["commit"], "run": execution["run"],
                            "attempt": execution["attempt"], "score": legacy["score"], "total": len(records)}}


def decisions(inventory, snapshot, history, current, mode):
    prior = history_index(history, current["commit"]) if history else {}
    reasons = file_reasons(history["snapshot"] if history else None, snapshot, {r["file"] for r in inventory.values()})
    previous_cost = [max(1, math.ceil(row["seconds"])) for row in prior.values() if row["seconds"] > 0]
    scale = max(1, sorted(previous_cost)[len(previous_cost) // 2] // 2) if previous_cost else 2
    pending, reused, estimates = {}, {}, {}
    for identifier, entry in inventory.items():
        old = prior.get(identifier)
        reason = reasons[entry["file"]]
        if mode == "full":
            reason = "full-validation"
        elif reason == "verified-equivalent" and old is None:
            reason = "new-or-missing-mutation"
        elif reason == "verified-equivalent" and (
            mutation_index([old])[identifier] != entry
            or old["source"] != snapshot["files"].get(entry["file"])
            or old["impact"] != impact_fingerprint(snapshot, entry["file"])
        ):
            reason = "incompatible-mutation-fingerprint"
        if reason == "verified-equivalent":
            reused[identifier] = reason
        else:
            pending[identifier] = reason
            estimates[identifier] = max(1, math.ceil(old["seconds"])) if old is not None else expected_cost(entry["file"]) * scale
    return pending, reused, estimates


def validate_plan(plan, expected_context):
    require(isinstance(plan, dict) and type(plan.get("schema")) is int and plan["schema"] == 2, "Invalid mutation plan schema.")
    require(plan.get("context") == expected_context, "Mutation plan does not match this candidate, configuration and run attempt.")
    require(type(plan.get("count")) is int and plan["count"] in SHARD_COUNTS, "Invalid mutation shard count.")
    require(plan.get("mode") in {"full", "incremental"}, "Invalid mutation mode.")
    inventory = mutation_index(plan.get("inventory"))
    pending, reused, estimates = decisions(inventory, plan["snapshot"], plan.get("history"), expected_context, plan["mode"])
    require(plan.get("pending") == pending and plan.get("reused") == reused and plan.get("estimates") == estimates,
            "Mutation reuse or execution reasons are incomplete or incompatible.")
    shards, loads = partition({key: inventory[key] for key in pending}, plan["count"], estimates)
    require(plan.get("shards") == shards and plan.get("estimated_cost") == loads, "Mutation shard assignment is incomplete or inconsistent.")
    return inventory


def prepare(directory, junit, output):
    current = context()
    route_context = dict(os.environ, APP_ENV="testing")
    routes = decode(subprocess.check_output(["php", str(SUPPORT / "routes.php")], cwd=ROOT, env=route_context))
    write_json(output, {
        "context": current, "coverage": coverage_snapshot(directory, junit), "routes": routes, "runtime": runtime(),
    })


def plan(path, count, mode, impact_path=None):
    started = time.monotonic()
    execution = context()
    php_env = {key: value for key, value in os.environ.items() if key not in {"GH_TOKEN", "GITHUB_TOKEN"}}
    entries = decode(subprocess.check_output(
        ["php", "-d", "memory_limit=512M", str(SUPPORT / "inventory.php")], cwd=ROOT, env=php_env,
    ))
    inventory = mutation_index(entries)
    files = tree_files(execution["commit"])
    runtime_info, recipe = runtime(), work_recipe()
    impact = read_json(impact_path) if impact_path else {}
    if impact:
        require(impact["context"] == execution and impact["runtime"] == runtime_info,
                "Candidate impact coverage is from a different run, attempt or runtime.")
        require({entry["file"] for entry in entries} <= impact["coverage"]["files"].keys(),
                "Candidate coverage omits mutation source files.")
    snapshot = {"files": files, "runtime": runtime_info, "recipe": recipe,
                "environment": snapshot_environment(files, runtime_info, recipe),
                "coverage": impact.get("coverage"), "routes": impact.get("routes", []),
                "route_sources": {name: (ROOT / name).read_text() for name in files if name.startswith("routes/") and name.endswith(".php")},
                "references": lexical_callers(files)}
    history, latest_full = discover(execution, snapshot, inventory)
    pending, reused, estimates = decisions(inventory, snapshot, history, execution, mode)
    shards, loads = partition({key: inventory[key] for key in pending}, count, estimates)
    manifest = {
        "schema": 2, "mode": mode, "context": execution, "lineage": lineage(execution["commit"]), "count": count,
        "snapshot": snapshot, "history": history, "latest_full": latest_full, "pending": pending, "reused": reused, "estimates": estimates,
        "inventory": sorted(entries, key=lambda entry: entry["id"]), "shards": shards, "estimated_cost": loads,
        "planning_seconds": round(time.monotonic() - started, 3),
    }
    validate_plan(manifest, execution)
    write_json(path, manifest)
    print(f"{mode}: {len(entries)} mutations across {len({entry['file'] for entry in entries})} files; "
          f"{len(pending)} fresh, {len(reused)} reused from run {history['context']['run'] if history else 'none'}.")
    print(f"Estimated load by worker: {loads}")
    if output := os.environ.get("GITHUB_OUTPUT"):
        with open(output, "a", encoding="utf-8") as stream:
            print("matrix=" + json.dumps({"shard": [int(shard) for shard in shards]}, separators=(",", ":")), file=stream)
            print("work=" + ("yes" if shards else "no"), file=stream)
            print("mode=" + mode, file=stream)


def evidence_header(plan, plan_digest, shard):
    return {"schema": 2, "context": plan["context"], "plan_sha256": plan_digest, "shard": shard}


def verify_checkout(manifest, expected):
    snapshot = manifest["snapshot"]
    files = tree_files(expected["commit"])
    require(snapshot["files"] == files and manifest["lineage"] == lineage(expected["commit"]),
            "Mutation plan does not describe this checkout.")
    require(snapshot["environment"] == snapshot_environment(files, snapshot["runtime"], work_recipe()),
            "Mutation environment fingerprint does not match the checkout.")


def run_shard(plan_path, shard, directory):
    source = plan_path.read_bytes()
    manifest = decode(source)
    current = context()
    inventory = validate_plan(manifest, current)
    verify_checkout(manifest, current)
    require(runtime() == manifest["snapshot"]["runtime"], "Worker mutation runtime differs from the plan.")
    assigned = manifest["shards"].get(str(shard))
    require(assigned is not None, "Shard is absent from the mutation plan.")
    directory = directory.resolve()
    directory.mkdir(parents=True)
    for name in ("generated.json", "outcomes.json", "pest-junit.xml"):
        (directory / name).unlink(missing_ok=True)
    write_json(directory / "selection.json", {"inventory": sorted(inventory), "assigned": assigned})
    env = dict(os.environ, XDEBUG_MODE="coverage", EXPEDFLOW_MUTATION_EVIDENCE_DIR=str(directory))
    subprocess.run(
        ["php", "tests/Support/check-database.php"], cwd=ROOT, env=dict(env, APP_ENV="testing"), check=True,
    )
    command = [
        *PEST_COMMAND,
        f"--bootstrap={SUPPORT / 'bootstrap.php'}", f"--log-junit={directory / 'pest-junit.xml'}",
    ]
    started = time.monotonic()
    wall_start = time.time()
    exit_code = subprocess.run(command, cwd=ROOT, env=env).returncode
    elapsed = round(time.monotonic() - started, 3)
    generated_file = directory / "generated.json"
    outcomes_file = directory / "outcomes.json"
    report = evidence_header(manifest, hashlib.sha256(source).hexdigest(), shard) | {
        "exit_code": exit_code, "wall_seconds": elapsed,
        "executed_at": datetime.now(timezone.utc).isoformat(),
        "preparation_seconds": min(elapsed, max(0, round(generated_file.stat().st_mtime - wall_start, 3)))
        if generated_file.is_file() else elapsed,
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
    require(mutation_index(report.get("generated")) == assigned, f"Shard {shard} did not generate its complete assignment.")
    require(mutation_index(report.get("outcomes")) == assigned, f"Shard {shard} has missing or unexpected outcomes.")
    require(timestamp(report.get("executed_at")) and duration(report.get("preparation_seconds"))
            and report["preparation_seconds"] <= report["wall_seconds"], "Invalid worker execution metadata.")
    for record in report["outcomes"]:
        outcome(record)
    return report["outcomes"]


def aggregate(manifest, reports, plan_digest, expected_context):
    inventory = validate_plan(manifest, expected_context)
    shards = {int(shard) for shard in manifest["shards"]}
    require(set(reports) == shards, "One or more shard reports are missing or unexpected.")
    snapshot = manifest["snapshot"]
    fresh = []
    for shard in sorted(shards):
        report = reports[shard]
        origin = evidence_origin(expected_context, manifest["lineage"], report["executed_at"])
        for row in reconcile_shard(report, manifest, inventory, plan_digest, shard):
            source = row["file"]
            require(snapshot["files"].get(source) is not None, "Fresh source is absent from the candidate tree.")
            fresh.append(row | {"origin": origin, "source": snapshot["files"][source],
                                "impact": impact_fingerprint(snapshot, source)})
    history = manifest.get("history")
    prior = history_index(history, expected_context["commit"]) if history else {}
    reused = [prior[key] for key in manifest["reused"]]
    results = fresh + reused
    require(mutation_index(results) == inventory, "Combined results differ from the complete inventory.")
    require(len({result["native_id"] for result in results}) == len(results), "Duplicate native Pest mutation ID.")
    counts = Counter(result["result"] for result in results)
    fresh_counts = Counter(result["result"] for result in fresh)
    total, detected, evaluated = len(inventory), counts["tested"], len(fresh)
    score = detected * 100 / total
    fresh_score = fresh_counts["tested"] * 100 / evaluated if evaluated else None
    passed = detected * 100 >= MINIMUM_SCORE * total and (
        not evaluated or fresh_counts["tested"] * 100 >= MINIMUM_SCORE * evaluated
    )
    full = manifest.get("latest_full") or (history.get("latest_full") if history else None)
    if manifest["mode"] == "full" and passed:
        full = {"commit": expected_context["commit"], "run": expected_context["run"],
                "attempt": expected_context["attempt"], "score": score, "total": total}
    wall_times = [reports[shard]["wall_seconds"] for shard in sorted(shards)]
    worker_loads = {str(shard): {
        "estimated_seconds": manifest["estimated_cost"][str(shard)],
        "preparation_seconds": reports[shard]["preparation_seconds"],
        "execution_seconds": round(reports[shard]["wall_seconds"] - reports[shard]["preparation_seconds"], 3),
        "wall_seconds": reports[shard]["wall_seconds"],
    } for shard in sorted(shards)}
    longest = max(wall_times, default=0)
    return {
        "schema": 2, "mode": manifest["mode"], "context": expected_context, "lineage": manifest["lineage"],
        "snapshot": snapshot, "plan_sha256": plan_digest,
        "counts": {name: counts[name] for name in OUTCOMES}, "fresh_counts": {name: fresh_counts[name] for name in OUTCOMES},
        "total": total, "detected": detected, "score": score, "fresh_score": fresh_score,
        "evaluated": evaluated, "reused": len(reused), "passed": passed, "latest_full": full,
        "runner_seconds": sum(wall_times), "longest_shard_seconds": longest, "workers": worker_loads,
        "worker_utilization": sum(wall_times) / (len(wall_times) * longest) if longest else None,
        "planning_seconds": manifest["planning_seconds"],
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
    scope = "full global" if result["mode"] == "full" else "reconciled incremental"
    fresh = "no fresh measurements" if result["fresh_score"] is None else f"{result['fresh_score']:.2f}% fresh"
    full = result["latest_full"]
    reference = (f"Latest verified full score: {full['score']:.2f}% in run {full['run']} attempt {full['attempt']}."
                 if full else "No compatible verified full score is available.")
    summary = (
        f"{result['detected']} of {result['total']} detected ({result['score']:.2f}% {scope}; {fresh}; minimum {MINIMUM_SCORE}%).\n"
        f"{result['evaluated']} executed, {result['reused']} reused ({result['reused'] / result['total']:.1%} hit rate). "
        f"Fresh: {result['fresh_counts']['tested']} detected, {result['fresh_counts']['untested']} survived, "
        f"{result['fresh_counts']['uncovered']} uncovered.\n"
        f"{len(result['workers'])} workers, {result['planning_seconds']:.1f}s planning, "
        f"{result['longest_shard_seconds']:.1f}s longest worker, "
        f"{result['runner_seconds']:.1f} worker-seconds.\n{reference}"
    )
    print(summary)
    for worker, stats in result["workers"].items():
        print(f"Worker {worker}: {stats}")
    if step_summary := os.environ.get("GITHUB_STEP_SUMMARY"):
        with open(step_summary, "a", encoding="utf-8") as stream:
            print("## Mutation results\n\n" + summary, file=stream)
    require(result["passed"], "The evaluated or reconciled mutation score is below the required threshold.")


def main():
    cli = argparse.ArgumentParser(description=__doc__)
    commands = cli.add_subparsers(dest="mode", required=True)
    planner = commands.add_parser("plan")
    planner.add_argument("output", type=Path)
    planner.add_argument("shards", type=int, choices=SHARD_COUNTS)
    planner.add_argument("--mode", dest="execution_mode", choices=("incremental", "full"), default="full")
    planner.add_argument("--impact", type=Path)
    coverage = commands.add_parser("prepare")
    coverage.add_argument("directory", type=Path)
    coverage.add_argument("junit", type=Path)
    coverage.add_argument("output", type=Path)
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
        plan(args.output, args.shards, args.execution_mode, args.impact)
    elif args.mode == "prepare":
        prepare(args.directory, args.junit, args.output)
    elif args.mode == "run":
        run_shard(args.plan, args.shard, args.directory)
    else:
        verify_checkout(read_json(args.plan), context())
        combine(args.plan, args.reports, args.output)


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, subprocess.CalledProcessError) as error:
        sys.exit(str(error))
