# Zogograph updates

This project provides manual release downloads, signed release metadata, and a
minimal version-check API. Client installations must continue to operate when
it is unavailable, and automatic updates remain opt-in.

Release signatures, checksums, compatibility ranges, and rollback information
are release artifacts. The service never needs monitoring data or encryption
keys to answer a version check.

## Local development

Run `npm ci`, `npm test`, and `npm run build`, then `npm start` to serve the
standalone artifact at `http://127.0.0.1:4178`. Production uses the configured
`https://updates.zogograph.com/` base URL.
