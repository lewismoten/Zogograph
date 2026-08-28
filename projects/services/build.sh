#!/usr/bin/env sh
set -eu

rm -rf dist
mkdir -p dist
tar --exclude='./.git' --exclude='./dist' --exclude='./.forgejo' --exclude='./**/data' --exclude='./**/config.php' -cf - key-custody replica-relay | tar -xf - -C dist
test -s dist/key-custody/api.php
test -s dist/replica-relay/api.php
