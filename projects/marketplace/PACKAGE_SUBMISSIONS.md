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

## Recommended launch policy

Start with free private package signing/testing and free submission for
open-source or private-review packages. Every executable package receives
manual security and compatibility review; no customer package is automatically
published, even when its signature verifies. Reviewers publish an independent
HostScope approval record and never replace the publisher signature.

Do not set a submission fee or expedited-review promise at launch. Revisit
pricing after tracking review effort and published package demand. For paid
packages, use marketplace settings—not package code—to configure the revenue
share, payout minimum, refund period, regional tax handling, and whether a
publisher is eligible to sell. Keep the private testing path free so customers
can validate packages without a commercial dependency.

The review interface should capture a source URL or attached source,
reproducible build/test instructions, requested capabilities, data and network
flows, license, support contact, and security disclosure contact. Encryption,
key-custody, storage, network, shell, or database-migration capabilities require
an additional security review and published compatibility evidence.
