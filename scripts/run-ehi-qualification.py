#!/usr/bin/env python3
"""Run a declared profile under the unchanged 300s overall operation ceiling."""
import importlib.util
import json
from pathlib import Path
import sys

spec = importlib.util.spec_from_file_location("observe", Path(__file__).with_name("observe-phr-resources.py"))
observer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(observer)
profiles = json.loads(Path(__file__).with_name("ehi-qualification-profiles.json").read_text())
name, directory = sys.argv[1:3]
profile = profiles[name]
result = observer.observe([f"--dir={directory}", "--prepare", f"--qualification={name}", *sys.argv[3:]], timeout_seconds=300)
result.setdefault("profile", name)
result.setdefault("declaration", profile)
result["operation_ceiling_seconds"] = 300
print(json.dumps(result))
