#!/usr/bin/env sh
set -eu

for test_file in key-custody/tests/*.php replica-relay/tests/*.php; do
  php "$test_file"
done
