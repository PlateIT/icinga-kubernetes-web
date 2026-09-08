# Business Process integration

The Business Process module is a separate trusted API client. It no longer
imports Kubernetes ORM models or reads the inventory database.

Two node forms are supported:

1. A fixed node references the stable resource UUID returned by the API.
2. A selector node follows replaceable objects by cluster, GVK, namespace,
   name, labels, owner and state.

Selector example in the stored process source:

```text
kubernetes-selector:payments =
k8s_selector kubernetes-selector:payments;cluster=campus;group=apps;version=v1;kind=Deployment;namespace=payments;labels=app%3Dpayments;aggregation=and
```

The API resolves the selection and its `and`, `or` or `worst` aggregation in
one snapshot. No match is `UNKNOWN`. Stale or unavailable federation data is
also `UNKNOWN`, so a check never reports cached data as current.

Relationship expansion uses one batched graph request. Owner changes caused by
rollouts are applied at runtime and are not written into the process definition.

Process definitions themselves are stored in PostgreSQL through
`/api/v1/business-processes`. All Icinga Web replicas therefore see the same
generation without a shared process directory. There is no file import or v1
migration path.
