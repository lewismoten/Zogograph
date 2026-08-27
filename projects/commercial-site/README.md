# Commercial site

The public HostScope website describes the product, links to documentation and
the marketplace, and gives customers a place to manage subscriptions, upgrades,
and service access.

It must not receive or store customer monitoring data, encryption keys, or
database copies. It links to optional services through authenticated account
flows rather than becoming part of a client installation's runtime path.

## Deployment and naming

The current provisional deployment is `https://hostscope.clewismoten.com/`.
Commercial products may be deployed beneath separate paths on that host while
their names and domains are still being decided. The site must use configured
canonical URLs and product labels rather than baking this hostname or the
current HostScope name into client protocols, key identifiers, download
signatures, or marketplace package identities. A later domain or product-name
change must be a deployment/configuration change, not a customer-data or
encryption migration.
