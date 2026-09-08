# Configuration

Icinga Kubernetes Web requires exactly one server-side API endpoint and one
reader token file. Environment variables are preferred:

```text
ICINGA_KUBERNETES_API_URL=http://icinga-kubernetes-api:8080
ICINGA_KUBERNETES_API_TOKEN_FILE=/run/secrets/icinga-kubernetes/reader-token
```

The equivalent module configuration is:

```ini
[api]
url = "http://icinga-kubernetes-api:8080"
token_file = "/run/secrets/icinga-kubernetes/reader-token"
timeout = 15
```

`timeout` must be between 1 and 60 seconds. Plaintext token values are not
supported. The token file must be readable by the PHP process and should be
mounted read-only from a Secret. The API endpoint must not contain URL user
information, a query or a fragment.

## Permissions and restrictions

Grant `kubernetes/resources/show` to users who may view Kubernetes resources.
Grant the independent `kubernetes/resources/logs` permission only to users who
may retrieve current pod logs.

The optional `kubernetes/filter/resources` role restriction accepts exact API
selectors encoded as a query string, for example:

```text
cluster=campus&namespace=payments&labels=app%3Dpayments
```

Supported fields are `cluster`, `group`, `version`, `kind`, `namespace`,
`name`, `state` and `labels`. Predicates within a role are combined with AND;
multiple granting roles are alternatives. Invalid or empty restrictions fail
closed. See [Security](04-Security.md) for the complete model.

No database resource, kubeconfig, Kubernetes credential or Prometheus setting
is configured in this module. Federation and live-source credentials belong to
the central API deployment.
