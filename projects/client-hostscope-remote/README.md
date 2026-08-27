# Remote HostScope client

This future minimal deployment is for a machine that supplies collection,
ciphertext replication, or key-custody duties without exposing the full
dashboard and administration surface.

It should contain only the collectors, enrolled-peer protocol, encrypted
artifact handling, health endpoint, and narrowly scoped configuration required
for its selected role. It must not inherit browser administration, destructive
actions, marketplace management, or AI features by default.

The remote client is a separate package boundary, not a configuration switch in
the full client. Shared wire formats and enrollment rules belong in a versioned
protocol package once the deployable split begins.
