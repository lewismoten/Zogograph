# Bundled marketplace manifests

`hostscope-path-hygiene.json` is a data-only package manifest for the local
marketplace. It authorizes a small, core-owned check; it does not load or run
package code.

To try it after deploying this release, sign in, open **System → Marketplace**,
choose **Install Path-name hygiene**, then enable **Path-name hygiene**. Run the
normal account disk scan. Its non-notifying result appears in **What is wrong**
only when it finds a name needing review. The manual installer remains for
third-party manifests; do not paste this README into it.

For a harmless demonstration in a throwaway directory inside the configured
scan root, create a file with a trailing space:

```sh
touch 'HostScope path hygiene demo '
```

Run `php cron.php --scan`, refresh **What is wrong**, then delete the test file
and scan again. The plugin treats control characters, leading or trailing
whitespace, and a leading dash as review-worthy. Ordinary spaces, Unicode, and
quotes are not findings.
