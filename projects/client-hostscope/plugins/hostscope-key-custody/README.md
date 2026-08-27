# HostScope remote key-custody package

This optional package connects a client deployment to the commercial
key-custody API. It is disabled by default and is not a requirement for local
keys, sealed copies, or self-managed fleet custody.

The package configures an HTTPS service base, tenant identity, and a
`secret_ref:` enrolled-client credential. The default unwrap cache is zero
seconds; an administrator may choose up to 15 minutes only after understanding
that a cached authorization can remain usable until it expires.

It sends artifact and envelope identifiers, authenticated request metadata, and
wrapped-key material only. It must not upload metrics, SQLite databases,
configuration files, plaintext keys, or billing credentials. Billing is managed
through the commercial account API/browser flow, separately from enrolled
client credentials.

The included client adapter uses HTTPS with normal certificate validation, no
redirect following, a five-minute service timestamp window, and a one-time
request nonce. It retains no key cache: any unwrapped key is returned only to
the immediate caller for in-memory use.
