"""Publish structured, credential-free test diagnostics. Never copy exception payloads."""
import collections
import json
import pathlib
import re
import sys
import xml.etree.ElementTree as ET

source = pathlib.Path(sys.argv[1])
destination = pathlib.Path(sys.argv[2])
destination.mkdir(parents=True, exist_ok=True)
root = ET.parse(source).getroot()
cases = list(root.iter("testcase"))
failed = []
totals = collections.Counter()
for case in cases:
    for attribute in ("class", "classname", "name"):
        value = case.get(attribute)
        if value:
            # Omit data-provider arguments which can include credential fixtures.
            case.set(attribute, re.match(r"[A-Za-z0-9_\\:]+", value).group(0) if re.match(r"[A-Za-z0-9_\\:]+", value) else "redacted")
    kind = next((tag for tag in ("failure", "error", "skipped") if case.find(tag) is not None), "passed")
    totals[kind] += 1
    if kind != "passed":
        failed.append({"class": case.get("class"), "test": case.get("name"), "kind": kind,
                       "type": case.find(kind).get("type")})
    # Raw failures can contain credentials, response bodies or private records. Remove all output.
    for tag in ("failure", "error", "skipped", "system-out", "system-err"):
        for child in case.findall(tag):
            child.clear()
            if tag in ("failure", "error"):
                child.text = "See the credential-free failure inventory."
            elif tag.startswith("system-"):
                case.remove(child)
for parent in root.iter():
    for tag in ("system-out", "system-err", "properties"):
        for child in parent.findall(tag):
            parent.remove(child)
ET.ElementTree(root).write(destination / "junit.xml", encoding="utf-8", xml_declaration=True)
(destination / "summary.json").write_text(json.dumps({"total": len(cases), "counts": totals, "failures": failed}, indent=2))
print(json.dumps({"total": len(cases), "counts": totals}))
