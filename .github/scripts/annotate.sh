#!/usr/bin/env bash
# Posts the tail of a failed check's log as GitHub annotations, so failures
# are readable through the checks API (annotations), not only in raw logs.
# Usage: annotate.sh <title> <logfile>
set -uo pipefail
title="$1"
log="$2"
[ -s "$log" ] || exit 0

# Last 400 lines, in chunks of ~6,000 characters (annotation size limit),
# at most 6 chunks per check (GitHub keeps 50 annotations per job).
tail -n 400 "$log" | sed 's/\x1b\[[0-9;]*[A-Za-z]//g' | python3 -c '
import sys
title = sys.argv[1]
text = sys.stdin.read()
chunks, size = [], 6000
while text and len(chunks) < 6:
    chunks.append(text[:size]); text = text[size:]
for i, chunk in enumerate(chunks, 1):
    body = chunk.replace("%", "%25").replace("\r", "").replace("\n", "%0A")
    print(f"::error title={title} ({i}/{len(chunks)})::{body}")
' "$title"
