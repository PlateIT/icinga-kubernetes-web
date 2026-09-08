# Installing Icinga Kubernetes Web from Source

Install the module as described in the Icinga Web documentation and use
`kubernetes` as the module name.

## Requirements

* Icinga Web 2 2.9 or newer
* Icinga PHP Library 0.19 or newer
* Icinga PHP Thirdparty 0.12 or newer
* PHP cURL and JSON extensions
* network access to an Icinga Kubernetes API v2 endpoint
* a reader token mounted as a read-only file

The module has no local database schema and no migration step. Continue with
[configuration](../03-Configuration.md) after enabling the module.
