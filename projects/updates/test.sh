#!/usr/bin/env sh
set -eu
test -s src/index.html && test -s src/releases.json
grep -Fq '<title>Zogograph Updates' src/index.html
grep -Fq 'https://updates.zogograph.com/' src/index.html
grep -Fq '"releases":[]' src/releases.json
