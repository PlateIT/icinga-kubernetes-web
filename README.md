# Icinga Kubernetes Web 2

This Icinga Web module is the trusted UI client for the central Icinga
Kubernetes API. It has no database resource, Kubernetes credentials,
Prometheus credentials or direct connection to another cluster.

The module provides:

- cluster and federation health;
- server-filtered, cursor-paginated resources with explicit cluster and GVK identity;
- generic views for native Kubernetes, OpenShift and operator CRDs;
- resource state, labels, conditions and bounded adapter summaries;
- resource-aware live charts for default and adapter-provided metrics;
- links used by the Business Process integration.

The old per-kind ORM models and direct PostgreSQL queries were deliberately
removed. A restart therefore reads the already materialized API state instead
of rebuilding large PHP query graphs or warming a local cache.

## Configuration

The preferred deployment uses environment variables injected from Secrets:

```text
ICINGA_KUBERNETES_API_URL=http://icinga-kubernetes-api:8080
ICINGA_KUBERNETES_API_TOKEN_FILE=/run/secrets/icinga-kubernetes/reader-token
```

An Icinga Web module configuration remains possible:

```ini
[api]
url = "http://icinga-kubernetes-api:8080"
token_file = "/run/secrets/icinga-kubernetes/reader-token"
timeout = 15
```

End users never receive the API token. Icinga Web permissions and restrictions
are evaluated by the module; only its server-side client calls the API.
Plaintext token values in environment variables or module configuration are
deliberately unsupported.
Unknown freshness headers fail closed, and live-metrics responses are validated
and reduced to the chart contract before any JSON reaches the browser.
The server-side client bypasses environment HTTP proxies for every API request;
the API is an internal cluster service and must not leak its bearer token or
traffic to an external proxy.

Dashboard status calls and role-restricted resource streams are issued in
bounded parallel batches. A single JSON response is limited to 8 MiB and a
batch to 32 MiB, so multiple role selectors cannot multiply memory use without
a fixed ceiling. User filters and the module's composite cursor are likewise
type- and length-bounded before the trusted API is contacted.

## Metrics

Resource lists refresh every 30 seconds using short polling requests. The Web
interface does not open persistent SSE connections, since each proxied stream
would occupy a PHP-FPM worker and could delay navigation and readiness checks.

Navigation and the object-type browser use the PostgreSQL resource inventory,
refreshed every 60 seconds. Only kinds with visible objects appear, including
custom resources. Role selectors are applied by the resource-types API before
aggregation. Counts are omitted when granting roles overlap. Deploy the API
with filtered resource-types support before deploying this Web module.

Lists, navigation counts and deployment children hide apps ReplicaSets whose
desired replica count is explicitly zero. Use "Show scaled-down ReplicaSets"
to include historical revisions in lists or deployment details. Active revisions
show ready/desired replicas; concurrent revisions remain visible during rollouts.
Other kinds and resources with unknown replica counts remain visible. The API
must support `hideZeroReplicaSets` before this default takes effect.

Environment resolves explicit owners, namespace, node, service account,
configuration/secret references and PVC/PV storage references. Pod owners are
followed one additional hop to their workload. Services are connected by exact
selectors, and Service details show selected Pods. Workload templates, Routes,
Ingresses and autoscalers expose their declared references as well. Only
authorized inventory matches become links; unresolved references retain their
name and are explicitly marked. Secret values are never part of relationship
data. Pod containers form a child tree with links to container detail rows.
Lookups are bounded (64 references/manifests, batches of 16, 32 candidate
services per role, 100 selected Pods per role); truncation is reported.

The navigation offers dedicated views for standard Kubernetes kinds and
OpenShift Routes. Resource details show operational properties, owners, direct
children, container readiness/restarts, conditions and grouped labels. Annotation
and manifest JSON remain available in collapsed technical sections. Child lists
apply the same role restrictions as the resource list.

Pod charts include container CPU/memory, requests and limits, throttling,
filesystem I/O and network errors. Workload charts associate pods through
`kube_pod_owner` (and ReplicaSet ownership for Deployments), not name prefixes.
PVC charts include capacity, requested/available space and inode usage. Time
ranges cover 15 minutes, 1 hour, 3 hours and 6 hours. Missing series are reported
explicitly and are never substituted with zeroes. Container requests/limits
describe containers, not effective pod-level scheduling requirements.

Metric contracts follow [kube-state-metrics](https://github.com/kubernetes/kube-state-metrics/blob/main/docs/metrics/workload/pod-metrics.md)
and [cAdvisor](https://github.com/google/cadvisor/blob/master/docs/storage/prometheus.md).

The resource detail page requests its metric catalog and samples through the
server-side module controller. It neither embeds monitoring credentials nor
executes browser-provided PromQL. Charts refresh every 30 seconds and omit an
individual series when the responsible cluster monitoring backend does not
provide it. The API supplies defaults for common Kubernetes/OpenShift kinds and
can extend them for operator CRDs through declarative adapters. PodMonitor and
ServiceMonitor objects show their currently selected targets and live `up`
state.

These cluster metrics remain in the responsible Kubernetes/OpenShift cluster.
They are independent of Icinga check performance data shown through the Icinga
Web Grafana module.

## Development

All runtime PHP files can be checked without a running Icinga installation:

```sh
find application library -name '*.php' -exec php -l {} \;
```

On Windows, the complete standalone suite additionally checks security,
controllers, concurrent HTTP/freshness handling and starts a temporary local
mock API. Three waves of ten fresh module processes each issue concurrent API
queries into an intentionally serialized backlog; all cold starts together
must complete within 30 seconds. This proves that a Web restart has no local
inventory warm-up dependency. The suite loads the installed cURL extension
only for the test processes and does not change `php.ini`:

```powershell
.\tests\run.ps1
```

The browser integration gate uses a temporary isolated Microsoft Edge profile
and verifies SSE-triggered partial refreshes plus live metric rendering:

```powershell
.\tests\run-browser.ps1
```

Licensed under the GNU Affero General Public License Version 3; see [LICENSE](LICENSE).
