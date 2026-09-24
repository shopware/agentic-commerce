#!/usr/bin/env bash
# Assert that every relative link in the repository's markdown resolves.
#
# Documentation moves -- sections get split into their own files, files get renamed -- and a link
# written from the repository root keeps pointing at `docs/x.md` from inside `docs/`, where it
# means `docs/docs/x.md`. Nothing renders an error for that; the link is simply dead. Three of
# them were already in the tree when this check was written.
#
# External links are not fetched: this is a structural check, not a network one.

set -euo pipefail

python3 - "$@" <<'PYTHON'
import re
import sys
from pathlib import Path

SKIP = {'node_modules', '.tools', 'vendor', 'var', 'dist'}

broken = []
checked = 0

for markdown in sorted(Path('.').rglob('*.md')):
    if any(part in SKIP for part in markdown.parts):
        continue

    for match in re.finditer(r'\[[^\]]*\]\(([^)]+)\)', markdown.read_text()):
        target = match.group(1).split('#')[0].strip()

        if not target or target.startswith(('http://', 'https://', 'mailto:')):
            continue

        checked += 1

        if not (markdown.parent / target).resolve().exists():
            broken.append(f'{markdown}: {target}')

for entry in broken:
    print(f'FAIL: dead relative link -> {entry}', file=sys.stderr)

if broken:
    sys.exit(1)

print(f'ok: {checked} relative markdown links resolve.')
PYTHON
