# Package submissions

Commercial marketplace review accepts a customer-created `*.zip` review bundle
from `scripts/package-submit.php`. The bundle contains `review.json`, the
signed `manifest.json`, and only the immutable `package/` files declared by
that manifest. It must never contain a publisher private key, runtime data,
customer monitoring data, or a database copy.

## Review checks

1. Validate the ZIP entry names and size limits before extraction.
2. Reconstruct the declared file list; reject added, missing, changed, or
   symlinked package files.
3. Verify the manifest's Ed25519 signature against the publisher key supplied
   during publisher enrollment.
4. Review package identity, capabilities, dependency and storage declarations,
   security model, license, source, and compatibility evidence.
5. Publish marketplace approval as separate HostScope provenance. Never
   re-sign a customer package as though HostScope authored it.

## Future API

The provisional commercial VPS may initially expose this through a configured
path such as `/marketplace/api/v1/submissions`; a later dedicated domain must
preserve the API version and account identity. A `POST` requires an
authenticated publisher session or scoped publisher token and one review ZIP.
It returns a submission identifier and state, never an executable result.

Suggested lifecycle: `received`, `signature_verified`, `reviewing`,
`changes_requested`, `approved`, `published`, `rejected`, `withdrawn`.
Marketplace publication is opt-in and does not grant an installed client any
additional filesystem, database, network, or key capability.
