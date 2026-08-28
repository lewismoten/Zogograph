# Zogograph Market

Zogograph Market publishes reviewed packages and service plans. It is
distinct from the customer's local marketplace catalog: local packages remain
administrator-installed and do not require a commercial account.

This project owns publisher review, catalog discovery, purchase records, and
entitlement delivery. It does not execute extension code in a browser or expose
customer secrets to publishers.

## Local development

Run `npm ci`, `npm test`, and `npm run build`, then `npm start` to serve the
standalone artifact at `http://127.0.0.1:4175`. Production uses the configured
`https://market.zogograph.com/` base URL.
