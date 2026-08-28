#!/usr/bin/env sh
set -eu

test -s src/index.html
grep -Fq '<title>Zogograph' src/index.html
grep -Fq 'https://zogograph.com/' src/index.html
grep -Fq 'Observe, record, and explain' src/index.html
