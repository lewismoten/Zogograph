# SQLCipher encrypted SQLite package

This is the first executable-package scaffold for live SQLite encryption. It
is deliberately **not installable by the current data-only marketplace**. Its
manifest defines the compatibility, isolation, private-data, and settings
contract the marketplace runtime must enforce before it can execute any package
code.

The package must fail closed: if it cannot establish that the PHP PDO driver is
backed by the required SQLCipher build, it must leave existing plaintext files
untouched and report the unavailable capability. It must never silently use
normal SQLite after the administrator chose live database encryption.

Before activation it must create and verify a sealed recovery artifact, convert
one selected store at a time, validate schema and application queries, prove
WAL and crash recovery, and retain the original until the administrator
confirms recovery. Each package version requires compatibility tests against
the supported PHP/OS builds.
