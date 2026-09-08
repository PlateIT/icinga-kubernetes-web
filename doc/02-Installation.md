<!-- {% if index %} -->
# Installing Icinga Kubernetes Web

The supported deployment is the project Helm chart. It deploys the module as
part of Icinga Web and injects the API URL plus a reader token file. The module
does not need a database resource, Kubernetes ServiceAccount, kubeconfig or
Prometheus credentials.

For a package or source installation, install the `icinga-kubernetes-web`
module on every Icinga Web replica. Every replica must be able to reach the
configured Icinga Kubernetes API over HTTP(S), and the PHP cURL extension must
be available.

The API token is mounted as a read-only file. Do not place it in an environment
variable, module INI value or web form. NetworkPolicy should permit only the
required Icinga Web-to-API connection.

<!-- {% else %} -->
<!-- {% if not icingaDocs %} -->
Install the `icinga-kubernetes-web` package from the configured package
repository or install it [from source](02-Installation.md.d/From-Source.md).
<!-- {% endif %} -->

Continue with [configuration](03-Configuration.md).
<!-- {% endif %} -->
