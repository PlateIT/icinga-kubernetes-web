# Security

Icinga Kubernetes Web 2 is a trusted server-side client of the central Icinga
Kubernetes API. The browser and end user never receive an API token, Kubernetes
credential, Prometheus credential or unrestricted API response. The module has
no direct database, cluster or monitoring-system connection.

## Permission

`kubernetes/resources/show` permits access to the dashboard, resource list,
resource details and the module's live-data endpoints. Without this permission
the controller rejects the request before it opens an API connection.

`kubernetes/resources/logs` separately permits loading current Pod logs. This
right is not implied by normal resource visibility because application logs can
contain sensitive business data.

## Resource restriction

`kubernetes/filter/resources` is an URL-encoded conjunction of exact API
selectors. Supported fields are:

- `cluster`
- `group`
- `version`
- `kind`
- `namespace`
- `name`
- `state`
- `labels`, formatted as `key=value[,key=value]`

Example:

```text
cluster=campus&namespace=payments&labels=app%3Dcheckout,environment%3Dproduction
```

Each granting role is an alternative (OR). Fields within one restriction are
combined (AND), matching Icinga Web's role-widening semantics. A granting role
without this restriction grants unrestricted resource visibility. Unknown
fields, empty values, wildcards, negation and expressions are rejected
fail-closed.

Restrictions are intersected with the user's filters before each API list
request. Results are checked again before rendering. Details, manifests, logs
and metrics first load the inventory object and apply the same authorization;
an unauthorized and an unknown ID both return `404`.

## Live updates

The upstream event payload never reaches the browser. The module reduces SSE
bursts to payload-free invalidations containing only the latest numeric stream
sequence, at most once per second. The browser performs a throttled partial
refresh at most once per ten seconds. Restricted users deliberately receive no
global SSE side channel and use 30-second polling. Unrestricted users also fall
back to polling whenever SSE is unavailable.

Freshness headers are accepted only as `live`, `stale` or `unavailable`; an
unknown value fails the backend request instead of being presented as live.
The live-metrics controller validates resource identity, cluster, time range,
metric count and every metric descriptor, then emits only the documented chart
fields. Additional trusted-API fields are never forwarded to the browser.

## Secrets and errors

The API bearer token must be mounted as a Secret-backed file and referenced by
`ICINGA_KUBERNETES_API_TOKEN_FILE` or `[api] token_file`. Plaintext environment
and module-config tokens are unsupported. API responses are size-bounded (8 MiB
individually and 32 MiB across one parallel request batch); user filters and
composite cursors are bounded before any backend request. Backend bodies,
transport details and credentials are never returned to the browser. All
server-side API calls explicitly bypass environment HTTP proxies,
because their internal bearer token must only reach the configured cluster
service. Detailed failures are written only to the protected Icinga Web log.
