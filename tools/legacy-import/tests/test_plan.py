from __future__ import annotations

import hashlib
import json
import re
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[3]
PLANNER = ROOT / "tools/legacy-import/plan.py"
SOURCES = {
    "primaerliste": ROOT / "var/legacy-import/primaerliste.xlsx",
    "beschaffungsliste": ROOT / "var/legacy-import/beschaffungsliste.xlsx",
}


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


class LegacyImportPlanTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        missing = [str(path) for path in SOURCES.values() if not path.is_file()]
        if missing:
            raise unittest.SkipTest("Required ignored legacy workbooks are absent: " + ", ".join(missing))
        cls.temporary = tempfile.TemporaryDirectory(prefix="spezitest-plan-test-")
        cls.output = Path(cls.temporary.name)
        cls.hashes_before = {key: sha256(path) for key, path in SOURCES.items()}
        subprocess.run(
            [sys.executable, str(PLANNER), "--output", str(cls.output)],
            cwd=ROOT,
            check=True,
            capture_output=True,
            text=True,
        )
        cls.plan = json.loads((cls.output / "import-plan.json").read_text(encoding="utf-8"))

    @classmethod
    def tearDownClass(cls) -> None:
        cls.temporary.cleanup()

    def drink_for_source(self, identifier: str) -> dict:
        matches = [
            drink
            for drink in self.plan["drinks"]
            if identifier in {source["source"] for source in drink["sources"]}
        ]
        self.assertEqual(1, len(matches))
        return matches[0]

    def test_sources_remain_unchanged_and_audited_counts_match(self) -> None:
        self.assertEqual(self.hashes_before, {key: sha256(path) for key, path in SOURCES.items()})
        self.assertTrue(self.plan["source_integrity_verified"])
        self.assertEqual({"primaerliste": 166, "beschaffungsliste": 34, "total": 200}, self.plan["counts"]["source_rows"])

    def test_lifecycle_and_red_row_mapping(self) -> None:
        self.assertEqual({"identified": 56, "acquired": 32, "tested": 108}, self.plan["counts"]["lifecycle"])
        self.assertEqual("identified", self.drink_for_source("primaerliste:110")["lifecycle_status"])
        self.assertEqual("acquired", self.drink_for_source("primaerliste:167")["lifecycle_status"])

    def test_only_four_audited_exact_pairs_are_merged(self) -> None:
        self.assertEqual(4, self.plan["counts"]["exact_duplicate_merges"])
        for prima_row, beschaffung_row in ((110, 7), (111, 17), (114, 3), (123, 27)):
            drink = self.drink_for_source(f"primaerliste:{prima_row}")
            self.assertIn(f"beschaffungsliste:{beschaffung_row}", {source["source"] for source in drink["sources"]})

    def test_fuzzy_candidates_are_unresolved_and_not_merged(self) -> None:
        self.assertFalse(self.plan["apply_ready"])
        self.assertEqual(5, self.plan["counts"]["fuzzy_unresolved"])
        for candidate in self.plan["fuzzy_candidates"]:
            self.assertEqual("UNRESOLVED", candidate["decision"])
            left = self.drink_for_source(candidate["left"]["source"])
            right = self.drink_for_source(candidate["right"]["source"])
            self.assertNotEqual(left["plan_key"], right["plan_key"])

    def test_row_151_identity_shift_is_corrected(self) -> None:
        drink = self.drink_for_source("primaerliste:151")
        self.assertEqual("Pyraser Waldquelle Cola-Mix", drink["name"])
        self.assertEqual("Pyraser Landbrauerei", drink["manufacturer"])
        self.assertEqual("91177 Thalmässing", drink["origin_location"])

    def test_all_tested_inputs_and_historical_results_are_planned(self) -> None:
        tests = [test for drink in self.plan["drinks"] for test in drink["tests"]]
        self.assertEqual(108, len(tests))
        self.assertEqual(324, sum(len(test["ratings"]) for test in tests))
        self.assertTrue(all(set(test["ratings"]) == {"manu", "fabi", "schorsch"} for test in tests))
        self.assertTrue(all(test["historical"]["gesamt"] != "" for test in tests))

    def test_price_precision_and_formula_artifacts_are_handled_deliberately(self) -> None:
        tests = {test["source"]: test for drink in self.plan["drinks"] for test in drink["tests"]}
        prices = [test["price_amount"] for test in tests.values()]
        self.assertIn("0.81583", prices)
        self.assertTrue(all(len(price.partition(".")[2]) <= 5 for price in prices))
        self.assertEqual(58, self.plan["counts"]["untested_prices_deferred"])
        for source in ("primaerliste:58", "primaerliste:59", "primaerliste:83"):
            self.assertIsNone(tests[source]["recorded_time"])
            self.assertIsNone(tests[source]["duration_value"])

    def test_images_are_content_addressed_deduplicated_and_row_mapped(self) -> None:
        self.assertEqual(199, self.plan["counts"]["images_extracted"])
        self.assertEqual(195, self.plan["counts"]["unique_image_files"])
        self.assertEqual(195, self.plan["counts"]["images_attached"])
        self.assertEqual(4, self.plan["counts"]["image_deduplications"])
        self.assertEqual([], self.drink_for_source("primaerliste:85")["images"])
        paths = [image["staged_path"] for drink in self.plan["drinks"] for image in drink["images"]]
        self.assertEqual(len(paths), len(set(paths)))
        self.assertTrue(all(re.fullmatch(r"images/[a-f0-9]{64}\.(png|jpg)", path) for path in paths))
        self.assertTrue(all((self.output / path).is_file() for path in paths))

    def test_explicit_decisions_survive_a_replanned_dry_run(self) -> None:
        with tempfile.TemporaryDirectory(prefix="spezitest-decision-test-") as temporary:
            output = Path(temporary)
            subprocess.run([sys.executable, str(PLANNER), "--output", str(output)], cwd=ROOT, check=True, capture_output=True)
            decision_path = output / "duplicate-decisions.json"
            decisions = json.loads(decision_path.read_text(encoding="utf-8"))
            decisions["candidates"][0]["decision"] = "DIFFERENT_PRODUCTS"
            decision_path.write_text(json.dumps(decisions, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
            subprocess.run([sys.executable, str(PLANNER), "--output", str(output)], cwd=ROOT, check=True, capture_output=True)
            replanned = json.loads((output / "import-plan.json").read_text(encoding="utf-8"))
            self.assertEqual("DIFFERENT_PRODUCTS", replanned["fuzzy_candidates"][0]["decision"])
            self.assertEqual(4, replanned["counts"]["fuzzy_unresolved"])

    def test_explicit_same_product_decision_merges_only_the_selected_pair(self) -> None:
        with tempfile.TemporaryDirectory(prefix="spezitest-same-product-test-") as temporary:
            output = Path(temporary)
            subprocess.run([sys.executable, str(PLANNER), "--output", str(output)], cwd=ROOT, check=True, capture_output=True)
            decision_path = output / "duplicate-decisions.json"
            decisions = json.loads(decision_path.read_text(encoding="utf-8"))
            decisions["candidates"][0]["decision"] = "SAME_PRODUCT"
            decisions["candidates"][0]["canonical_source"] = decisions["candidates"][0]["left"]["source"]
            decision_path.write_text(json.dumps(decisions, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
            subprocess.run([sys.executable, str(PLANNER), "--output", str(output)], cwd=ROOT, check=True, capture_output=True)
            replanned = json.loads((output / "import-plan.json").read_text(encoding="utf-8"))
            self.assertEqual(195, replanned["counts"]["drinks"])
            merged = next(
                drink for drink in replanned["drinks"]
                if "primaerliste:59" in {source["source"] for source in drink["sources"]}
            )
            self.assertIn("beschaffungsliste:17", {source["source"] for source in merged["sources"]})
            self.assertEqual("Cubanita Cola Mix", merged["name"])
            self.assertIn("primaerliste:59", merged["images"][0]["sources"])


if __name__ == "__main__":
    unittest.main()
