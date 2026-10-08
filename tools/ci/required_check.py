"""Stable required-check contract: only an actual success for every dependency passes."""
import json
import sys


def validate(results):
    expected = {"php", "mysql", "frontend", "e2e"}
    return set(results) == expected and all(results[job].get("result") == "success" for job in expected)


if __name__ == "__main__":
    results = json.loads(sys.argv[1])
    # Job IDs and conclusions only; no untrusted PR strings or outputs.
    print(json.dumps({key: value.get("result") for key, value in results.items()}, sort_keys=True))
    sys.exit(0 if validate(results) else 1)
