import copy
import hashlib
import json
import os
import shutil
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from mutation import (
    ROOT, SUPPORT,
    ROOT, aggregate, combine, context, decisions, decode, history_index, identity_index, mutation_index,
    partition, write_json,
)
from mutation_history import eligible_run, verified_tree
from mutation_impact import file_reasons, snapshot_environment


class MutationAggregationTest(unittest.TestCase):
    def setUp(self):
        self.context = {"commit": "candidate", "configuration": "config", "run": "12", "attempt": "1"}
        self.inventory = [{
            "id": f"{index:064x}", "file": "routes/api.php",
            "mutator": "Pest\\Mutate\\Mutators\\Logical\\LogicalAndAllSubExprNegation",
            "start": index, "end": index, "mutated_sha256": f"{index:064x}",
        } for index in range(1, 22)]
        indexed = mutation_index(self.inventory)
        snapshot = {"files": {"routes/api.php": "blob"}, "environment": "a" * 64}
        fresh, reused, estimates = decisions(indexed, snapshot, None, self.context, "full")
        shards, loads = partition(indexed, 4, estimates)
        self.plan = {
            "schema": 2, "context": self.context, "count": 4, "inventory": self.inventory,
            "mode": "full", "snapshot": snapshot, "history": None, "lineage": {"head": "candidate", "base": ""},
            "pending": fresh, "reused": reused, "estimates": estimates, "planning_seconds": 1,
            "shards": shards, "estimated_cost": loads,
        }
        self.reports = {}
        for shard, ids in shards.items():
            generated = [indexed[identifier] for identifier in ids]
            self.reports[int(shard)] = {
                "schema": 2, "context": self.context, "plan_sha256": "digest", "shard": int(shard),
                "exit_code": 0, "wall_seconds": float(shard) * 10, "generated": generated,
                "executed_at": "2026-11-28T01:00:00+00:00", "preparation_seconds": 1,
                "outcomes": [record | {
                    "native_id": record["id"][-16:], "result": "tested", "seconds": 1,
                    "infrastructure_failure": None,
                } for record in generated],
            }

    def reconcile(self):
        return aggregate(self.plan, self.reports, "digest", self.context)

    def test_deterministic_work_distribution_at_four_and_eight_runners(self):
        inventory = identity_index(self.inventory)
        for count in (4, 8):
            shards, loads = partition(inventory, count)
            self.assertEqual((shards, loads), partition(dict(reversed(list(inventory.items()))), count))
            self.assertCountEqual([item for shard in shards.values() for item in shard], inventory)
            self.assertLessEqual(max(loads.values()) - min(loads.values()), 4)

    def test_global_score_counts_every_outcome_including_uncovered(self):
        for record, outcome in zip(self.reports[1]["outcomes"], ["untested", "untested", "uncovered", "uncovered"]):
            record["result"] = outcome

        result = self.reconcile()

        self.assertEqual(result["counts"], {"tested": 17, "untested": 2, "uncovered": 2, "timeout": 0})
        self.assertAlmostEqual(result["score"], 17 / 21 * 100)
        self.assertTrue(result["passed"])
        self.assertEqual(result["runner_seconds"], 100)
        self.assertEqual(result["worker_utilization"], 100 / (4 * 40))

    def test_below_threshold_never_passes(self):
        for record in self.reports[1]["outcomes"][:5]:
            record["result"] = "untested"

        self.assertFalse(self.reconcile()["passed"])

    def test_missing_report_fails(self):
        del self.reports[3]
        with self.assertRaisesRegex(ValueError, "reports are missing"):
            self.reconcile()

    def test_duplicate_inventory_id_fails(self):
        self.inventory.append(self.inventory[0])
        with self.assertRaisesRegex(ValueError, "Duplicate mutation ID"):
            self.reconcile()

    def test_duplicate_outcome_id_fails(self):
        self.reports[1]["outcomes"].append(self.reports[1]["outcomes"][0])
        with self.assertRaisesRegex(ValueError, "Duplicate mutation ID"):
            self.reconcile()

    def test_duplicate_native_id_fails_across_shards(self):
        self.reports[2]["outcomes"][0]["native_id"] = self.reports[1]["outcomes"][0]["native_id"]
        with self.assertRaisesRegex(ValueError, "Duplicate native Pest"):
            self.reconcile()

    def test_incomplete_inventory_or_shard_assignment_fails(self):
        for field in ("generated", "outcomes"):
            with self.subTest(field=field):
                saved = self.reports[2][field].pop()
                with self.assertRaisesRegex(ValueError, "complete assignment|unexpected outcomes"):
                    self.reconcile()
                self.reports[2][field].append(saved)

        self.plan["shards"]["2"].pop()
        with self.assertRaisesRegex(ValueError, "assignment is incomplete"):
            self.reconcile()

    def test_invalid_or_infrastructure_outcomes_fail(self):
        original = self.reports[2]["outcomes"][0]
        for errors, message in [
            ({"result": "none"}, "Invalid Pest outcome"),
            ({"seconds": float("inf")}, "Invalid duration"),
            ({"infrastructure_failure": "Database connection refused"}, "Infrastructure failure"),
            ({"result": "timeout"}, "Unverified mutation timeout"),
        ]:
            with self.subTest(errors=errors):
                self.reports[2]["outcomes"][0] = original | errors
                with self.assertRaisesRegex(ValueError, message):
                    self.reconcile()

    def test_failed_pest_process_fails(self):
        self.reports[4]["exit_code"] = 2
        with self.assertRaisesRegex(ValueError, "Pest execution failed"):
            self.reconcile()

    def test_candidate_configuration_attempt_and_manifest_must_match(self):
        original = copy.deepcopy(self.reports[4])
        for field in ("commit", "configuration", "run", "attempt"):
            with self.subTest(field=field):
                self.reports[4]["context"] = self.context | {field: "stale"}
                with self.assertRaisesRegex(ValueError, "does not match"):
                    self.reconcile()
                self.reports[4] = copy.deepcopy(original)

        self.reports[4]["plan_sha256"] = "different"
        with self.assertRaisesRegex(ValueError, "does not match"):
            self.reconcile()

    def test_artifact_layout_and_below_threshold_step_failure(self):
        current_context = context()
        self.plan["context"] = current_context
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            manifest = directory / "plan.json"
            write_json(manifest, self.plan)
            digest = hashlib.sha256(manifest.read_bytes()).hexdigest()
            reports = directory / "reports"
            for shard, report in self.reports.items():
                report["context"] = current_context
                report["plan_sha256"] = digest
                path = reports / f"mutation-shard-{shard}" / "mutation-shard"
                path.mkdir(parents=True)
                if shard == 1:
                    for outcome in report["outcomes"]:
                        outcome["result"] = "untested"
                write_json(path / "report.json", report)

            result = directory / "summary.json"
            with self.assertRaisesRegex(ValueError, "below the required threshold"):
                combine(manifest, reports, result)
            self.assertFalse(decode(result.read_bytes())["passed"])

    def test_invalid_json_does_not_accept_duplicate_or_nonfinite_fields(self):
        for content in ('{"result":"none","result":"tested"}', '{"seconds":NaN}'):
            with self.subTest(content=content), self.assertRaises(ValueError):
                decode(content)


    def test_unexpected_generated_or_outcome_id_fails(self):
        for field in ("generated", "outcomes"):
            with self.subTest(field=field):
                old_record = self.reports[2][field][0]
                self.reports[2][field][0] = old_record | {"id": "f" * 64}
                with self.assertRaisesRegex(ValueError, "complete assignment|unexpected outcomes"):
                    self.reconcile()
                self.reports[2][field][0] = old_record

class IncrementalMutationTest(unittest.TestCase):
    def setUp(self):
        self.a = "app/Http/Controllers/Alpha.php"
        self.b = "app/Http/Controllers/Beta.php"
        self.c = "app/Http/Controllers/Caller.php"
        self.ta = "tests/Feature/AlphaTest.php"
        self.tb = "tests/Feature/BetaTest.php"
        self.snapshot = {
            "files": dict.fromkeys((self.a, self.b, self.c, self.ta, self.tb), "original"),
            "environment": "a" * 64, "references": {self.a: [self.c]},
            "coverage": {"tests": [self.ta, self.tb], "files": {
                self.a: {self.ta: "hits-a"}, self.b: {self.tb: "hits-b"}, self.c: {},
            }},
        }
        self.current = copy.deepcopy(self.snapshot)
        self.execution = context() | {"run": "67", "attempt": "1"}
        self.inventory = mutation_index([
            {"file": source, "id": f"{i:064x}", "start": 5, "end": 5,
             "mutator": "Pest\\Mutate\\Mutators\\Removal",
             "mutated_sha256": f"{i + 50:064x}"}
            for i, source in enumerate((self.a, self.b, self.c), 1)
        ])
        base, reports = self.manifest(self.snapshot, None, "full")
        self.history = aggregate(base, reports, "digest", self.execution)
        self.execution = self.execution | {"run": "68"}

    def manifest(self, snapshot, history, mode):
        pending, reused, estimates = decisions(self.inventory, snapshot, history, self.execution, mode)
        shards, loads = partition({i: self.inventory[i] for i in pending}, 8, estimates)
        plan = {"schema": 2, "mode": mode, "snapshot": snapshot, "history": history,
                "context": self.execution, "count": 8, "inventory": list(self.inventory.values()),
                "pending": pending, "reused": reused, "estimates": estimates, "shards": shards,
                "estimated_cost": loads, "planning_seconds": 0,
                "lineage": {"head": self.execution["commit"], "base": ""}}
        reports = {}
        for shard, ids in shards.items():
            entries = [self.inventory[i] for i in ids]
            reports[int(shard)] = {
                "schema": 2, "context": self.execution, "plan_sha256": "digest", "shard": int(shard),
                "exit_code": 0, "wall_seconds": 2, "preparation_seconds": 1,
                "executed_at": "2026-11-28T01:00:00+00:00", "generated": entries,
                "outcomes": [row | {"result": "tested", "infrastructure_failure": None,
                                    "seconds": 1, "native_id": row["id"][-16:]} for row in entries],
            }
        return plan, reports

    def test_identical_docs_and_orchestration_candidates_have_proven_zero_work(self):
        for changes in ({}, {"docs/CI.md": "docs"}, {".github/workflows/phase-b.yml": "timeout"}):
            with self.subTest(changes=changes):
                current = self.snapshot | {"files": self.snapshot["files"] | changes}
                plan, reports = self.manifest(current, self.history, "incremental")
                result = aggregate(plan, reports, "digest", self.execution)
                self.assertEqual(plan["shards"], {})
                self.assertEqual((result["evaluated"], result["reused"], result["fresh_score"]), (0, 3, None))
                self.assertTrue(result["passed"])
                self.assertTrue(all(row["origin"]["run"] == "67" for row in result["results"]))

    def test_changed_dependency_invalidates_uncovered_caller_without_retesting_unrelated_source(self):
        self.current["files"][self.a] = "changed"
        reasons = file_reasons(self.snapshot, self.current, {self.a, self.b, self.c})
        self.assertEqual(reasons, {self.a: "source-changed", self.b: "verified-equivalent",
                                   self.c: "application-dependency-changed"})
        plan, reports = self.manifest(self.current, self.history, "incremental")
        result = aggregate(plan, reports, "digest", self.execution)
        self.assertEqual((result["evaluated"], result["reused"], result["total"]), (2, 1, 3))
        self.assertEqual(len(plan["shards"]), 1)
        self.assertEqual({r["file"]: r["origin"]["run"] for r in result["results"]},
                         {self.a: "68", self.b: "67", self.c: "68"})
        reports[1]["outcomes"][0]["result"] = "untested"
        result = aggregate(plan, reports, "digest", self.execution)
        self.assertFalse(result["passed"])
        self.assertEqual(result["fresh_score"], 50)

    def test_new_deleted_and_renamed_sources_do_not_transfer_outcomes(self):
        destination = "app/Http/Controllers/Renamed.php"
        removed = next(key for key, row in self.inventory.items() if row["file"] == self.a)
        old_row = self.inventory.pop(removed)
        del self.current["files"][self.a]
        self.current["files"][destination] = "original"
        self.inventory["e" * 64] = old_row | {"id": "e" * 64, "file": destination}
        plan, reports = self.manifest(self.current, self.history, "incremental")
        result = aggregate(plan, reports, "digest", self.execution)
        self.assertNotIn(removed, plan["pending"] | plan["reused"])
        self.assertIn("e" * 64, plan["pending"])
        self.assertEqual(result["total"], 3)

    def test_relevant_test_edits_deletions_additions_and_coverage_changes(self):
        current = self.current
        current["files"][self.ta] = "weaker"
        sources = {self.a, self.b, self.c}
        self.assertEqual(file_reasons(self.snapshot, current, sources)[self.a], "relevant-test-or-dependency-changed")
        self.assertEqual(file_reasons(self.snapshot, current, sources)[self.b], "verified-equivalent")
        del current["files"][self.ta]
        current["coverage"]["tests"].remove(self.ta)
        current["coverage"]["files"][self.a] = {}
        self.assertEqual(file_reasons(self.snapshot, current, sources)[self.a], "test-coverage-changed")
        new_test = "tests/Feature/NewTest.php"
        current["files"][new_test] = "new"
        current["coverage"]["tests"].append(new_test)
        current["coverage"]["files"][self.c] = {new_test: "newly-covered"}
        self.assertEqual(file_reasons(self.snapshot, current, sources)[self.c], "test-coverage-changed")

    def test_shared_fixture_dataset_bootstrap_and_configuration_invalidate_the_inventory(self):
        for path in ("tests/Fixtures/scenario.json", "tests/Pest.php", "config/app.php", "database/migrations/new.php",
                     "app/Policies/Shared.php", "lang/en/messages.php"):
            with self.subTest(path=path):
                current = self.snapshot | {"files": self.snapshot["files"] | {path: "changed"}}
                self.assertEqual(set(file_reasons(self.snapshot, current, {self.a, self.b}).values()),
                                 {"shared-or-unattributed-input-changed"})

    def test_additive_literal_route_keeps_existing_prefixes_reusable(self):
        old_route = ["api/v1/alpha", ["GET", "HEAD"], "Alpha", ["api"], {}, None]
        api = "routes/api.php"
        module = "routes/new-module.php"
        self.snapshot["files"][api] = "old"
        self.snapshot["route_sources"] = {api: "<?php\n    require __DIR__.'/alpha.php';\n"}
        self.snapshot["routes"] = [old_route]
        current = copy.deepcopy(self.snapshot)
        current["files"] |= {api: "new", module: "new"}
        current["route_sources"][api] += "    require __DIR__.'/new-module.php';\n"
        current["route_sources"][module] = "<?php\nuse Foo\\Widget;\nRoute::get('widgets', [Widget::class, 'index']);\n"
        current["routes"].append(["api/v1/widgets", ["GET", "HEAD"], "Widget@index", ["api"], {}, None])
        self.assertEqual(file_reasons(self.snapshot, current, {self.a, api, module}),
                         {self.a: "verified-equivalent", api: "source-changed", module: "source-changed"})
        current["routes"][1][0] = "api/v1/alpha/widgets"
        self.assertEqual(file_reasons(self.snapshot, current, {self.a})[self.a], "shared-or-unattributed-input-changed")

    def test_missing_runtime_and_dependency_evidence_requires_fresh_execution(self):
        for history, environment in ((None, "a" * 64), (self.history, "b" * 64)):
            with self.subTest(environment=environment):
                self.current["environment"] = environment
                plan, _ = self.manifest(self.current, history, "incremental")
                self.assertEqual(set(plan["pending"]), set(self.inventory))
        self.assertNotEqual(snapshot_environment({"composer.lock": "a"}, {}, []),
                            snapshot_environment({"composer.lock": "b"}, {}, []))

    def test_corrupt_incompatible_or_invalidated_history_cannot_supply_outcomes(self):
        for metadata in ({"schema": 1}, {"passed": False}):
            with self.assertRaisesRegex(ValueError, "Unsupported or unsuccessful"):
                self.manifest(self.current, self.history | metadata, "incremental")
        for corrupt in ({"origin": {}}, {"source": "forged"}, {"impact": "unknown"}, {"result": "timeout"}):
            history = copy.deepcopy(self.history)
            history["results"][0].update(corrupt)
            with self.subTest(corrupt=corrupt), self.assertRaises(ValueError):
                self.manifest(self.current, history, "incremental")
        duplicate = copy.deepcopy(self.history)
        duplicate["results"].append(duplicate["results"][0])
        with self.assertRaisesRegex(ValueError, "Duplicate mutation ID"):
            self.manifest(self.current, duplicate, "incremental")
        with patch("mutation.ancestor", return_value=False), self.assertRaisesRegex(ValueError, "outside candidate ancestry"):
            history_index(self.history, self.execution["commit"])

    def test_fresh_required_ids_and_historical_reuse_are_checked_at_aggregation(self):
        self.current["files"][self.a] = "changed"
        plan, reports = self.manifest(self.current, self.history, "incremental")
        with self.assertRaisesRegex(ValueError, "reports are missing"):
            aggregate(plan, {}, "digest", self.execution)
        plan["reused"][next(iter(plan["pending"]))] = "verified-equivalent"
        with self.assertRaisesRegex(ValueError, "reuse or execution"):
            aggregate(plan, reports, "digest", self.execution)

    def test_prior_durations_deterministically_distribute_expensive_mutations(self):
        costs = {str(i): cost for i, cost in enumerate((60, 60, 55, 53, 3, 2, 1))}
        jobs = dict.fromkeys(costs)
        shards, loads = partition(jobs, 4, costs)
        self.assertEqual((shards, loads), partition(dict(reversed(list(jobs.items()))), 4, costs))
        self.assertEqual(len(shards), 4)
        self.assertEqual(len({next(worker for worker, ids in shards.items() if expensive in ids)
                              for expensive in ("0", "1", "2", "3")}), 4)
        self.assertEqual(partition({"tiny": {}}, 8, {"tiny": 1})[0], {"1": ["tiny"]})

    def test_full_mode_never_reuses_a_valid_history(self):
        plan, reports = self.manifest(self.current, self.history, "full")
        result = aggregate(plan, reports, "digest", self.execution)
        self.assertEqual((result["evaluated"], result["reused"], result["latest_full"]["run"]), (3, 0, "68"))

    def test_run_discovery_rejects_fork_other_branch_failure_and_other_workflow(self):
        repository = "owner/repo"
        run = {"id": 1, "run_attempt": 1, "workflow_id": 10, "path": ".github/workflows/phase-b.yml",
               "status": "completed", "conclusion": "success", "event": "workflow_dispatch", "head_branch": "main",
               "head_sha": self.execution["commit"], "repository": {"full_name": repository},
               "head_repository": {"full_name": repository}}
        self.assertTrue(eligible_run(run, 10, repository, self.execution, {"main"}))
        for forged in ({"head_repository": {"full_name": "fork/repo"}}, {"head_branch": "other"},
                       {"conclusion": "failure"}, {"workflow_id": 11}, {"event": "push"}):
            with self.subTest(forged=forged):
                self.assertFalse(eligible_run(run | forged, 10, repository, self.execution, {"main"}))
        evidence = self.history | {"lineage": {"head": run["head_sha"], "base": "", "tree": "0" * 40}}
        self.assertFalse(verified_tree(evidence, run, self.execution["commit"]))


class MutationSourceTest(unittest.TestCase):
    def test_task_read_controllers_produce_compilable_array_item_removals(self):
        controllers = [
            "app/Http/Controllers/TaskAssigneeController.php",
            "app/Http/Controllers/TaskChecklistController.php",
            "app/Http/Controllers/TaskCommentController.php",
            "app/Http/Controllers/TaskController.php",
        ]
        generated = subprocess.check_output(
            ["php", "-r", r"""
                require 'vendor/autoload.php';
                Pest\Support\Container::getInstance()->add(
                    Psr\SimpleCache\CacheInterface::class, new Pest\Mutate\Cache\NullStore
                );
                $generator = new Pest\Mutate\Support\MutationGenerator;
                foreach (Pest\Mutate\Support\FileFinder::files(array_slice($argv, 1), []) as $file) {
                    foreach ($generator->generate($file, [Pest\Mutate\Mutators\Removal\RemoveArrayItem::class]) as $mutation) {
                        echo json_encode([
                            'file' => $file->getFilename(),
                            'line' => $mutation->startLine,
                            'source' => $mutation->modifiedSource(),
                        ], JSON_THROW_ON_ERROR), PHP_EOL;
                    }
                }
            """, *controllers],
            cwd=ROOT, text=True, timeout=30,
        )
        mutations = [json.loads(line) for line in generated.splitlines()]
        self.assertEqual({item["file"] for item in mutations}, {Path(path).name for path in controllers})
        for mutation in mutations:
            with self.subTest(file=mutation["file"], line=mutation["line"]):
                result = subprocess.run(
                    ["php", "-l"], input=mutation["source"], text=True, capture_output=True, timeout=10,
                )
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


class NativeMutationIdentityTest(unittest.TestCase):
    source = """<?php
function sameLine(): array { return [null, null]; }
function differentLines(): array {
    return [
        false,
        false,
    ];
}
"""

    worker_probe = r"""
    require 'scripts/testing/mutation/bootstrap.php';

    $container = Pest\Support\Container::getInstance();
    $container->add(Psr\SimpleCache\CacheInterface::class, new Pest\Mutate\Cache\NullStore);
    $generator = $container->get(Pest\Mutate\Support\MutationGenerator::class);
    $file = new Symfony\Component\Finder\SplFileInfo('app/Collision.php', 'app', 'app/Collision.php');
    $operators = Pest\Mutate\Mutators\Sets\DefaultSet::mutators();
    $native = (new Pest\Mutate\Support\MutationGenerator)->generate($file, $operators);
    $resolved = $generator->generate($file, $operators);
    $suite = new Pest\Mutate\MutationSuite;
    foreach ($resolved as $mutation) {
        $suite->repository->add($mutation);
    }
    Pest\Mutate\Event\Facade::instance()->emitter()->finishMutationGeneration($suite);
    echo json_encode([
        'native' => array_map(fn ($mutation) => mutationIdentity($mutation) + ['native' => $mutation->id], $native),
        'resolved' => array_map(fn ($mutation) => mutationIdentity($mutation) + [
            'native' => $mutation->id, 'offset' => $mutation instanceof LocatedMutation ? $mutation->sourceOffset : null,
        ], $resolved),
    ], JSON_THROW_ON_ERROR);
    """

    def run_php(self, checkout, *arguments, **environment):
        process = subprocess.run(
            ["php", "-d", "display_errors=stderr", *arguments], cwd=checkout,
            env=dict(os.environ, **environment), capture_output=True,
        )
        self.assertEqual(process.returncode, 0, process.stderr.decode())
        return decode(process.stdout)

    def test_preserves_same_line_sites_and_the_worker_matches_portable_planning(self):
        with tempfile.TemporaryDirectory() as temporary:
            inventories = []
            for checkout_name in ("planner", "independent-worker-path"):
                checkout = Path(temporary) / checkout_name
                for directory in ("app", "routes", "bootstrap", "evidence"):
                    (checkout / directory).mkdir(parents=True)
                shutil.copytree(SUPPORT, checkout / "scripts/testing/mutation")
                (checkout / "vendor").symlink_to(ROOT / "vendor", target_is_directory=True)
                (checkout / "app/Collision.php").write_text(self.source, encoding="utf-8")
                (checkout / "bootstrap/app.php").write_text("<?php\n", encoding="utf-8")

                inventory = self.run_php(checkout, "scripts/testing/mutation/inventory.php")
                self.assertEqual(inventory, self.run_php(checkout, "scripts/testing/mutation/inventory.php"))
                inventories.append(identity_index(inventory))
                evidence = checkout / "evidence"
                selected = [row["id"] for row in inventory if row["mutator"].endswith("\\RemoveArrayItem")]
                write_json(evidence / "selection.json", {"inventory": sorted(inventories[-1]), "assigned": selected})
                result = self.run_php(checkout, "-r", self.worker_probe, EXPEDFLOW_MUTATION_EVIDENCE_DIR=str(evidence))
                native, resolved = result["native"], result["resolved"]
                self.assertEqual(len(native), len(inventory))
                self.assertEqual(len({row["native"] for row in resolved}), len(native))
                self.assertEqual(identity_index(resolved), inventories[-1])
                self.assertEqual(set(identity_index(decode((evidence / "generated.json").read_bytes()))), set(selected))

                removed = [row for row in native if row["mutator"].endswith("\\RemoveArrayItem")]
                self.assertEqual([row["start"] for row in removed], [2, 2, 5, 6])
                self.assertEqual(removed[0]["id"], removed[1]["id"])
                self.assertEqual(removed[0]["native"], removed[1]["native"])
                self.assertEqual(removed[2]["id"], removed[3]["id"])
                self.assertEqual(removed[2]["native"], removed[3]["native"])
                offsets = [row["offset"] for row in resolved if row["id"] in selected]
                first = self.source.index("null")
                self.assertEqual(offsets, [
                    first, self.source.index("null", first + 1),
                    self.source.index("false"), self.source.rindex("false"),
                ])

            self.assertEqual(*inventories)


class WorkflowGateTest(unittest.TestCase):
    def test_event_requirements_and_explicit_zero_work(self):
        core = (
            "backend-quality", "backend-tests", "database-tests", "frontend-quality",
            "frontend-tests", "api-contract", "security", "codeql", "build", "e2e",
            "accessibility", "storage-integration",
        )
        mutation = ("mutation-plan", "mutation-shards", "mutation")
        extended = ("performance", "dynamic-security")
        gate = Path(__file__).with_name("quality-gate.sh")

        for event in ("workflow_dispatch", "schedule", "release", "pull_request", "push"):
            outcomes = {name: {"result": "success"} for name in core + mutation + extended}
            mode = "incremental" if event == "pull_request" else "full"
            outcomes["mutation-plan"]["outputs"] = {"work": "yes", "mode": mode}
            if event in ("pull_request", "push"):
                outcomes.update({name: {"result": "skipped"} for name in extended})
            if event == "push":
                outcomes.update({name: {"result": "skipped"} for name in mutation})
            with self.subTest(event=event):
                self.assertEqual(self.run_gate(gate, event, outcomes), 0)
                for name in mutation + extended:
                    self.assertNotEqual(self.run_gate(gate, event, outcomes | {name: {"result": "failure"}}), 0)
                    self.assertNotEqual(self.run_gate(gate, event, {k: v for k, v in outcomes.items() if k != name}), 0)
                if event != "push":
                    skipped = outcomes | {"mutation-shards": {"result": "skipped"}}
                    self.assertNotEqual(self.run_gate(gate, event, skipped), 0)
                    skipped["mutation-plan"] = {"result": "success", "outputs": {"work": "no", "mode": mode}}
                    self.assertEqual(self.run_gate(gate, event, skipped) == 0, event == "pull_request")

    @staticmethod
    def run_gate(gate, event, outcomes):
        return subprocess.run(
            ["bash", str(gate), event], env=dict(os.environ, NEEDS_RESULTS=json.dumps(outcomes)),
            capture_output=True, check=False,
        ).returncode
