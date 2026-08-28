#!/usr/bin/env sh
# Classify remaining Hostscope references without treating a raw text match as
# authority to rewrite persisted protocols or installation state.
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$root"

rg -n -i --glob '!data/**' --glob '!dist/**' --glob '!scripts/rebrand-audit.sh' 'hostscope' . |
while IFS= read -r match; do
  file=${match%%:*}
  rest=${match#*:}
  line=${rest%%:*}
  text=${rest#*:}
  case "$file:$text" in
    ./REBRAND_MIGRATION.md:*|./REBRAND_AUDIT.md:*) category='Historical record or documented migration' ;;
    ./projects/client/CHANGELOG.md:*) category='Historical record or documented migration' ;;
    */DEPLOYMENT.md:*CLIENT_HOSTSCOPE_*|*/ISOLATED_RUNTIME.md:*HOSTSCOPE_PACKAGE_SANDBOX*|*/deploy/package-worker.compose.example.yml:*HOSTSCOPE_PACKAGE_SANDBOX*) category='Backward-compatible protocol, storage, or deployment identifier' ;;
    */scripts/package-runtime-worker.php:*HOSTSCOPE_PACKAGE_SANDBOX*|*/plugins/sqlcipher-encrypted-sqlite/manifest.json:*|*/plugins/sqlcipher-encrypted-sqlite/src/SqlcipherStorageProvider.php:*HostScope*) category='Backward-compatible plugin or package identifier' ;;
    */lib/peertransport.php:*X-Hostscope-*|*/lib/urlwatch.php:*HostScope/1.0*|*/lib/setupstore.php:*'HostScope setup configuration v1'*|*/lib/package-runtime.php:*'HostScope package secret vault v1'*) category='Backward-compatible protocol, storage, or deployment identifier' ;;
    */tests/*:*|*/SBOM.cdx.json:*|*/THIRD_PARTY_NOTICES.md:*) category='Test, generated inventory, or third-party record' ;;
    */plugins/hostscope-*:*|*'"id": "hostscope-'*|*'hostscope-package-'*) category='Backward-compatible plugin or package identifier' ;;
    *HOSTSCOPE-SEAL-*|*X-HostScope-*|*HTTP_X_HOSTSCOPE_*|*'hostscope-'*|*'hostscope.'*) category='Backward-compatible protocol, storage, or deployment identifier' ;;
    *) category='Documented follow-up: inspect for current public wording' ;;
  esac
  printf '%s:%s	%s\n' "$file" "$line" "$category"
done
