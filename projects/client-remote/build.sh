#!/usr/bin/env sh
set -eu
rm -rf dist
mkdir -p dist
cp src/index.html dist/index.html
test -s dist/index.html
