# Full HostScope client

This is the customer-facing HostScope deployment: dashboard, setup, local
SQLite stores, collection, alerts, administration, and optional fleet control.

The current application remains at this repository's root while it is used as a
direct PHP deployment. A future move into this folder must be a separate,
tested packaging migration that preserves existing installation paths and cron
commands.

The full client can create local sealed copies and later coordinate fleet
replicas. It must function without commercial services or external providers.
