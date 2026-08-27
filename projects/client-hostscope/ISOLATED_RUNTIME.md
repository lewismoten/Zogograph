# Isolated package runtime design

Executable packages are an optional upgrade. HostScope never loads a package
entry point in the dashboard, cron, setup, or API PHP process. A signed package
is only a reviewed registry record until an operator deploys a separate runtime
that satisfies this document.

## Recommended first deployment

Keep the default installation free of an executable package runtime. Use the
built-in sealed-copy protection first. Install SQLCipher, remote custody, or a
replica plugin only when its specific benefit is needed.

For a small deployment, run one short-lived package worker per invocation in a
dedicated container or operating-system service account. Mount only:

- the immutable, verified package release, read-only;
- that package's private data directory, read/write;
- a single request/response channel owned by HostScope; and
- explicitly approved, short-lived input values.

HostScope includes `scripts/package-runtime-worker.php` as the initial runner
component. It refuses to start unless its launcher sets
`HOSTSCOPE_PACKAGE_SANDBOX=1`, accepts only a 64 KiB `capability` probe, and
does not receive configuration, secrets, core database handles, or package
private-data paths. The marker is an operator attestation, not sandbox proof:
run the worker only from the container/OS service described above. Core does
not invoke it yet, so its presence does not enable executable packages.

[`deploy/package-worker.compose.example.yml`](deploy/package-worker.compose.example.yml)
is an optional Docker-compatible one-shot sandbox starting point. It disables
networking, drops Linux capabilities, uses a read-only root and read-only
package release, runs as an unprivileged numeric account, and sets conservative
process/memory/CPU limits. Copy it outside the web root and review every host
path/image tag. It intentionally does not mount core data, configuration, or
key files; add a package-private data mount only for a later signed operation
that has passed its release gate.

Do not mount the HostScope web root, core databases, setup database, bootstrap
configuration, key files, SSH agent, Docker socket, home directory, or a broad
host filesystem. Disable outbound networking by default; grant it only for a
package with the signed `network_client` capability and an operator-approved
egress policy. Run as a non-login account with no shared group access.

PHP `open_basedir`, `disable_functions`, or an environment scrub alone are
helpful defense in depth, but are not an isolation boundary. They must not make
the dashboard say that isolated execution is installed. Container/OS sandbox
evidence is required before that capability can be enabled.

## Contract version 1.0

Every executable manifest declares `runtime.kind` as
`hostscope-isolated-php-package`, its signed relative `entrypoint`, and a
`runtime.minimum_api` such as `1.0`. Core validates this declaration during
installation and treats a package requiring a later API as unavailable. This
is a compatibility contract, not a permission to execute code.

The future runner should use a length-limited JSON request/response protocol
over a private Unix socket or standard input/output. Each request must carry a
fresh core-generated invocation id, package id, operation name, and only the
capability-scoped data needed for that operation. Responses must be bounded,
schema-validated, attributable to the invocation id, and recorded in the core
audit trail. Reject unknown fields and duplicate invocation ids.

The runner must re-check all of the following immediately before invoking an
entry point:

1. installed manifest fingerprint and every signed file hash;
2. trusted publisher signature and package revocation status;
3. declared PHP/extensions/runtime API compatibility;
4. package enabled state and the exact administrator-granted capabilities; and
5. a deployment-provided isolation attestation, including the sandbox profile
   and executable image/version.

The runner does not receive core PDO handles or filesystem paths. It receives
a package-private database handle or a narrow storage RPC operation instead.
Secrets remain core-owned: settings store `secret_ref:` identifiers; core may
resolve an explicitly declared reference only into the one invocation that
needs it, never expose a vault listing, and must redact it from logs and errors.

## Choices to review before enabling it

- **Sandbox provider:** container, systemd sandbox, jail, or a managed worker.
  Record the exact profile in deployment configuration. The default remains
  disabled because the right choice depends on the host's operating system.
- **Network policy:** use an allowlist of service origins for custody/relay
  packages. A generic network capability is not a license for arbitrary egress.
- **Secret delivery:** start with one-time process input and short-lived worker
  processes. Do not write decrypted secrets into package files or private
  SQLite databases.
- **Resource limits:** set CPU, memory, process, output, and wall-clock limits
  per package. Denial-of-service resistance needs host-specific limits, so core
  must display them rather than invent unsafe universal defaults.
- **Local development:** test packages in a disposable sandbox with a separate
  signing key. Local signing proves authorship to the local trust store; it is
  not marketplace review or a security certification.

Until these controls are implemented and independently tested, HostScope keeps
the `isolated_package_runtime` capability unavailable. Packages that require it
cannot be enabled, opened for private storage, or used to make encryption or
remote-custody claims.

[`PROVIDER_RPC_PLAN.md`](PROVIDER_RPC_PLAN.md) records the signed operation
set, data boundary, deployment decision, and safe rollout order for the next
implementation step. The container template keeps standard input open for a
single bounded JSON request; feed only the core-generated request through that
channel and do not pass values through command arguments or environment.
