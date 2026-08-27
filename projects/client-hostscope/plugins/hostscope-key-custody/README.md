# HostScope remote key-custody package

This optional package connects a client deployment to the commercial
key-custody API. It is disabled by default and is not a requirement for local
keys, sealed copies, or self-managed fleet custody.

The package configures an HTTPS service base, tenant identity, and separate
`secret_ref:` values for the enrolled-client bearer credential and its Base64
Ed25519 private signing key. The service retains only the public half of the
signing key. The default unwrap cache is zero seconds; an administrator may
choose up to 15 minutes only after understanding that a cached authorization
can remain usable until it expires.

The signed settings schema accepts only an `https://` service base and the
opaque enrolled `ten_…` tenant identifier. This catches common setup mistakes
before settings are stored; the adapter performs stricter URL checks again when
it connects.

It sends artifact and envelope identifiers, authenticated request metadata, and
wrapped-key material only. It must not upload metrics, SQLite databases,
configuration files, plaintext keys, or billing credentials. Billing is managed
through the commercial account API/browser flow, separately from enrolled
client credentials.

The included client adapter accepts only an unambiguous HTTPS service URL (no
embedded user credentials, query, or fragment), explicitly verifies the server
certificate and hostname, follows no redirects, and uses a five-minute service
timestamp window plus a one-time request nonce. It signs the HTTP method, request path, timestamp, nonce, and
the exact request-body hash with Ed25519, so a stolen bearer alone cannot alter
or create an enrolled-client request. It retains no key cache: any unwrapped
key is returned only to the immediate caller for in-memory use.
