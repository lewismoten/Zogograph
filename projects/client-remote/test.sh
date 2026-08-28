#!/usr/bin/env sh
set -eu
test -s src/index.html
grep -Fq '<title>Zogograph Remote' src/index.html
grep -Fq 'demo-remote.zogograph.com' src/index.html
grep -Fq 'does not expose' src/index.html
