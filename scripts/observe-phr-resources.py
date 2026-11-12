#!/usr/bin/env python3
"""Sample one isolated PHP resource process; qualification adds a strict total deadline."""
import json
import os
from pathlib import Path
import signal
import stat
import subprocess
import sys
import tempfile
import time


def observe(arguments, timeout_seconds=None):
    script = Path(__file__).with_name("measure-phr-resources.php")
    qualification = any(arg.startswith("--qualification=") for arg in arguments)
    directory = next((Path(arg.split("=", 1)[1]) for arg in arguments if arg.startswith("--dir=")), None)
    captured_stdout = tempfile.TemporaryFile(mode="w+t")
    captured_stderr = tempfile.TemporaryFile(mode="w+t")
    process = subprocess.Popen(["php", "-d", "memory_limit=1G", str(script), *arguments],
                               stdout=captured_stdout, stderr=captured_stderr, text=True, start_new_session=qualification)
    start = time.monotonic()
    peak_disk = peak_rss = fixture_peak = 0
    stages = {}
    last_fixture_sample = 0
    timed_out = False
    current_stage = None
    while process.poll() is None:
        if timeout_seconds is not None and time.monotonic() - start >= timeout_seconds:
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            timed_out = True
            break
        inodes = {}
        try:
            if qualification and directory is not None:
                try:
                    current_stage = json.loads((directory / "qualification-stage.json").read_text())["stage"]
                except (OSError, ValueError, KeyError):
                    pass
            for fd in Path(f"/proc/{process.pid}/fd").iterdir():
                try:
                    name = os.readlink(fd)
                    info = fd.stat()
                    if stat.S_ISREG(info.st_mode) and (name.startswith(("/tmp/phr-native-", "/tmp/phr-restore-", "/tmp/php", "/tmp/phr-capacity-"))
                            or name.endswith(("/measured-archive.zip", "/independent-recovery.zip"))):
                        inodes[(info.st_dev, info.st_ino)] = info.st_size
                except (FileNotFoundError, PermissionError):
                    pass
            disk = sum(inodes.values())
            rss = 0
            for line in Path(f"/proc/{process.pid}/status").read_text().splitlines():
                if line.startswith("VmRSS:"):
                    rss = int(line.split()[1])
            peak_disk, peak_rss = max(peak_disk, disk), max(peak_rss, rss)
            if qualification and isinstance(current_stage, str):
                prior = stages.setdefault(current_stage, {"rss_kib": 0, "temporary_bytes": 0})
                prior["rss_kib"], prior["temporary_bytes"] = max(prior["rss_kib"], rss), max(prior["temporary_bytes"], disk)
            if qualification and directory is not None and time.monotonic() - last_fixture_sample >= 0.5:
                # Includes input fixtures/DB/managed objects: explicitly separate from native spool files.
                fixture_peak = max(fixture_peak, sum(path.stat().st_size for path in directory.rglob("*") if path.is_file() and not path.is_symlink()))
                last_fixture_sample = time.monotonic()
        except (FileNotFoundError, PermissionError):
            pass
        time.sleep(0.005)
    process.wait()
    captured_stdout.seek(0)
    captured_stderr.seek(0)
    stdout, stderr = captured_stdout.read(), captured_stderr.read()
    captured_stdout.close()
    captured_stderr.close()
    if timed_out:
        try:
            partial = json.loads((directory / "qualification-progress.json").read_text())
        except (OSError, ValueError):
            partial = {"stages": {}}
        result = partial | {"status": "timeout", "refused_stage": current_stage, "metrics_incomplete": True}
        # A killed stage cannot run PHP's finally block. Retain its periodically
        # published aggregate lower bounds alongside the completed stages.
        try:
            path = directory / "qualification-active.json"
            active = json.loads(path.read_text())
            if isinstance(active.get("stage"), str) and active["stage"] not in result["stages"]:
                active["snapshot_age_seconds"] = round(max(0, time.time() - path.stat().st_mtime), 6)
                active["metrics_incomplete"] = True
                result["active_stage_metrics"] = active
                result["refused_stage"] = active["stage"]
        except (OSError, ValueError, KeyError):
            pass
        result["queries"] = sum(stage["queries"] for stage in result["stages"].values()) + result.get("active_stage_metrics", {}).get("queries", 0)
        result["peak_php_bytes"] = max([0, *[stage.get("peak_php_bytes", 0) for stage in result["stages"].values()],
                                        result.get("active_stage_metrics", {}).get("peak_php_bytes", 0)])
    else:
        if process.returncode:
            if qualification:
                raise RuntimeError("Synthetic qualification process failed; raw output remains private")
            sys.stderr.write(stderr)
            sys.stdout.write(stdout)
            raise subprocess.CalledProcessError(process.returncode, process.args)
        if qualification:
            lines = [line for line in stdout.splitlines() if line]
            # A migration emits this fixed empty synthetic scan directly to STDOUT.
            if any(line != "Scanned 0 fill(s); updated 0." for line in lines[:-1]):
                raise ValueError("Unexpected qualification output; raw output remains private")
            result = json.loads(lines[-1])
        else:
            sys.stderr.write(stderr)
            result = json.loads(stdout)
    result.update({"sampled_temp_file_high_water_bytes": peak_disk, "sampled_rss_kib": peak_rss,
                   "profile_wall_seconds": round(time.monotonic() - start, 6)})
    if qualification:
        result.update({"sampled_stages": stages, "private_fixture_high_water_bytes": fixture_peak})
    return result


if __name__ == "__main__":
    print(json.dumps(observe(sys.argv[1:])))
