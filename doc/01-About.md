# Icinga Kubernetes Web 2

Icinga Kubernetes Web is the trusted Icinga Web client for the central Icinga
Kubernetes API. The module does not connect to PostgreSQL, Kubernetes,
OpenShift, Prometheus or a federated cluster itself. Its server-side API client
is the only data path.

The module provides:

* a combined cluster and federation overview;
* server-filtered and cursor-paginated resources;
* generic views for Kubernetes, OpenShift and operator-provided resources;
* bounded live manifests, pod logs and resource-aware metrics through the API;
* role-based resource restrictions and a separate permission for pod logs; and
* stable resource links for Business Process integration.

The API owns discovery, persistence, federation and access to live source data.
This keeps every Icinga Web replica stateless: replicas share neither a module
database nor a writable module volume, and a restart does not rebuild an
in-memory resource model.

## Multi-cluster behavior

Each cluster runs its own highly available API. A module normally talks to its
local API. Configured one-hop federation endpoints let that API include remote
branches without making the web module aware of remote credentials or network
topology. The UI labels data as `live`, `stale` or `unavailable` according to
the API response.

Inventory state is served from PostgreSQL by the API. Logs, manifests and
cluster metrics remain in the responsible cluster and are retrieved live only
when a user requests them. No such live data is copied into Icinga Web.

## Installation

See [Installation](02-Installation.md) and
[Configuration](03-Configuration.md).

## License

Icinga Kubernetes Web and its documentation are licensed under the GNU Affero
General Public License Version 3.
