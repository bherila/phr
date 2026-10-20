#!/usr/bin/env python3
"""Run the PHP resource harness and sample its private temporary-file footprint.

Usage: python3 scripts/observe-phr-resources.py --dir=/tmp/phr-resource-example --operation=backup
Fixture preparation remains a separate invocation of measure-phr-resources.php.
Linux /proc is used for RSS and anonymous tmpfile sizes; no patient values are read.
"""

import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import time

script = Path(__file__).with_name("measure-phr-resources.php")
process = subprocess.Popen(
    ["php", "-d", "memory_limit=1G", str(script), *sys.argv[1:]],
    stdout=subprocess.PIPE,
    stderr=subprocess.PIPE,
    text=True,
)
peak_disk = 0
peak_rss = 0
while process.poll() is None:
    inodes = {}
    try:
        for fd in Path(f"/proc/{process.pid}/fd").iterdir():
            try:
                name = os.readlink(fd)
                info = fd.stat()
                # Only the native operation's spool files, not fixture DB/source.
                if stat.S_ISREG(info.st_mode) and (
                    name.startswith("/tmp/phr-native-")
                    or name.startswith("/tmp/phr-restore-")
                    or name.startswith("/tmp/php")
                    or name.endswith("/measured-archive.zip")
                ):
                    inodes[(info.st_dev, info.st_ino)] = info.st_size
            except (FileNotFoundError, PermissionError):
                pass
        peak_disk = max(peak_disk, sum(inodes.values()))
        for line in Path(f"/proc/{process.pid}/status").read_text().splitlines():
            if line.startswith("VmRSS:"):
                peak_rss = max(peak_rss, int(line.split()[1]))
    except (FileNotFoundError, PermissionError):
        pass
    time.sleep(0.005)
stdout, stderr = process.communicate()
sys.stderr.write(stderr)
if process.returncode:
    sys.stdout.write(stdout)
    sys.exit(process.returncode)
result = json.loads(stdout)
result["sampled_temp_file_high_water_bytes"] = peak_disk
result["sampled_rss_kib"] = peak_rss
print(json.dumps(result))
