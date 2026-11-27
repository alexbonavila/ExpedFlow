import copy
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

from mutation import aggregate, combine, context, decode, identity_index, partition, write_json


class MutationAggregationTest(unittest.TestCase):
    def setUp(self):
        self.context = {"commit": "candidate", "configuration": "config", "run": "12", "attempt": "1"}
        self.inventory = [{
            "id": f"{index:064x}", "file": "routes/api.php",
            "mutator": "Pest\\Mutate\\Mutators\\Logical\\LogicalAndAllSubExprNegation",
            "start": index, "end": index,
        } for index in range(1, 22)]
        indexed = identity_index(self.inventory)
        shards, loads = partition(indexed, 4)
        self.plan = {
            "schema": 1, "context": self.context, "count": 4, "inventory": self.inventory,
            "shards": shards, "estimated_cost": loads,
        }
        self.reports = {}
        for shard, ids in shards.items():
            generated = [indexed[identifier] for identifier in ids]
            self.reports[int(shard)] = {
                "schema": 1, "context": self.context, "plan_sha256": "digest", "shard": int(shard),
                "exit_code": 0, "wall_seconds": float(shard) * 10, "generated": generated,
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


class WorkflowGateTest(unittest.TestCase):
    def test_extended_gate_requires_all_mutation_jobs_and_core_requires_their_skip(self):
        core = (
            "backend-quality", "backend-tests", "database-tests", "frontend-quality", "frontend-tests",
            "api-contract", "security", "codeql", "build", "e2e", "accessibility", "storage-integration",
        )
        extended = ("mutation-plan", "mutation-shards", "mutation", "performance", "dynamic-security")
        gate = Path(__file__).with_name("quality-gate.sh")

        for event, status in (("workflow_dispatch", "success"), ("pull_request", "skipped")):
            outcomes = {name: {"result": status if name in extended else "success"} for name in core + extended}
            with self.subTest(event=event):
                self.assertEqual(self.run_gate(gate, event, outcomes), 0)
            for name in extended[:3]:
                with self.subTest(event=event, failed_job=name):
                    failed = outcomes | {name: {"result": "failure"}}
                    self.assertNotEqual(self.run_gate(gate, event, failed), 0)
                    self.assertNotEqual(self.run_gate(gate, event, {key: value for key, value in outcomes.items() if key != name}), 0)

    @staticmethod
    def run_gate(gate, event, outcomes):
        return subprocess.run(
            ["bash", str(gate), event], env=dict(os.environ, NEEDS_RESULTS=json.dumps(outcomes)),
            capture_output=True, check=False,
        ).returncode
