import os
from pathlib import Path
from xml.etree import ElementTree

report = Path("test-results/phpunit.xml")
suites = ["Integration", "Contract"]

with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as output:
    for name in suites:
        cases = []
        if report.is_file():
            root = ElementTree.parse(report).getroot()
            for suite in root.iter("testsuite"):
                if suite.get("name") == name:
                    cases.extend(suite.iter("testcase"))
        failed = any(
            child.tag in {"failure", "error", "skipped"}
            for case in cases
            for child in case
        )
        outcome = "passed" if cases and not failed else "failed"
        print(f"{name}: {outcome} ({len(cases)} executed or reported)")
        print(f"{name.lower()}={outcome}", file=output)
