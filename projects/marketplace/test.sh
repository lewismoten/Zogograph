#!/usr/bin/env sh
set -eu
test -s src/index.html
grep -Fq '<title>Zogograph Market' src/index.html
grep -Fq 'https://market.zogograph.com/' src/index.html
grep -Fq 'Package IDs stay stable' src/index.html
