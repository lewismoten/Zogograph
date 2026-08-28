#!/usr/bin/env sh
set -eu
rm -rf dist
mkdir -p dist
cp src/index.html src/releases.json dist/
test -s dist/index.html
test -s dist/releases.json
