#!/usr/bin/env python3
"""Run a declared profile under the unchanged 300s overall operation ceiling."""
import importlib.util
import json
import os
from pathlib import Path
import sys

spec = importlib.util.spec_from_file_location("observe", Path(__file__).with_name("observe-phr-resources.py"))
observer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(observer)
profiles = json.loads(Path(__file__).with_name("ehi-qualification-profiles.json").read_text())
name, directory = sys.argv[1:3]
profile = profiles[name]
envelopes = json.loads(Path(__file__).with_name("ehi-qualification-envelopes.json").read_text())
limits = envelopes[profile["envelope"]] | {"seconds": 240}
for field, variable in {"rows": "PHR_EHI_PREVIEW_MAX_ROWS", "bytes": "PHR_EHI_PREVIEW_MAX_BYTES",
                        "native_records": "PHR_NATIVE_MAX_RECORDS", "native_stream_bytes": "PHR_NATIVE_MAX_RECORD_STREAM_BYTES",
                        "seconds": "PHR_EHI_PREVIEW_MAX_SECONDS"}.items():
    os.environ[variable] = str(limits[field])
result = observer.observe([f"--dir={directory}", "--prepare", f"--qualification={name}", *sys.argv[3:]], timeout_seconds=300)
result.setdefault("profile", name)
result.setdefault("declaration", profile)
if "limits" in result:
    comparable = dict(result["limits"])
    if profile.get("native_boundary") in {"exact", "over"}:
        boundary = result.get("lifecycle", {}).get("boundary_native_records")
        if not isinstance(boundary, int) or not 0 < boundary <= limits["native_records"] or comparable["native_records"] != boundary:
            raise ValueError("Measured native boundary did not preserve its lowered fixture cap")
        comparable["native_records"] = limits["native_records"]
    if comparable != limits:
        raise ValueError("Measured runtime limits differ from the declared synthetic envelope")
result.setdefault("limits", limits)
result["limits_source"] = "declared_process_environment" if result["status"] == "timeout" else "runtime_configuration"
result["operation_ceiling_seconds"] = 300
print(json.dumps(result))
