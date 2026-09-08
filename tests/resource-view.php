<?php
require dirname(__DIR__) . '/library/Kubernetes/Web/ResourceView.php';
use Icinga\Module\Kubernetes\Web\ResourceView;
function expect($value, $message) { if (! $value) throw new RuntimeException($message); }
$manifest = ['spec' => ['nodeName' => 'worker-1', 'containers' => [['name' => 'db', 'image' => 'postgres:test']], 'initContainers' => [['name' => 'proxy', 'restartPolicy' => 'Always']]], 'status' => ['containerStatuses' => [['name' => 'db', 'ready' => true, 'restartCount' => 3, 'state' => ['running' => []]]], 'initContainerStatuses' => [['name' => 'proxy', 'ready' => true, 'state' => ['running' => []]]]]];
$groups = ResourceView::containers([], $manifest);
expect($groups['Containers'][0]['ready'] && $groups['Containers'][0]['restarts'] === 3, 'Container readiness and restarts');
expect($groups['Sidecar containers'][0]['name'] === 'proxy', 'Restartable init container is a sidecar');
expect(ResourceView::facts([], $manifest)['Node'] === 'worker-1', 'Pod node detail');
expect(ResourceView::facts(['summary' => ['spec.replicas' => 0]])['Desired replicas'] === '0', 'Zero replicas must remain visible');
expect(ResourceView::groupedLabels(['app.kubernetes.io/name' => 'demo', 'app' => 'x'])['app.kubernetes.io']['name'] === 'demo', 'Grouped labels');
expect(ResourceView::state(['state' => '" onclick="alert(1)']) === 'unknown', 'Only known states become CSS classes');
$replicaSet = ['group' => 'apps', 'kind' => 'ReplicaSet', 'summary' => ['spec.replicas' => 3, 'status.readyReplicas' => 2]];
expect(ResourceView::replicaActivity($replicaSet) === 'Active · 2/3 ready', 'Active rollout must show readiness');
$replicaSet['summary']['spec.replicas'] = 0;
expect(str_starts_with(ResourceView::replicaActivity($replicaSet), 'Scaling down'), 'Serving old replicas remain identifiable during rollout');
$replicaSet['summary']['status.readyReplicas'] = 0;
expect(ResourceView::replicaActivity($replicaSet) === 'Scaled down · 0 desired', 'Historical revision must be identified');
unset($replicaSet['summary']['spec.replicas']);
expect(ResourceView::replicaActivity($replicaSet) === 'Replica count unavailable', 'Unknown count is not scaled down');
$sorted = ResourceView::sortByType([
    ['kind' => 'Secret', 'name' => 'z'], ['kind' => 'Pod', 'name' => 'pod-10'],
    ['kind' => 'Secret', 'name' => 'a'], ['kind' => 'Pod', 'name' => 'pod-2']
]);
expect(array_column($sorted, 'name') === ['pod-2', 'pod-10', 'a', 'z'], 'Environment sorts by kind then name');
$child = ['id' => 'pod', 'kind' => 'Pod', 'name' => 'pod'];
$current = ['id' => 'rs', 'kind' => 'ReplicaSet', 'name' => 'replica'];
$groups = ResourceView::environmentGroups($current, [
    ['kind' => 'Secret', 'name' => 'z'], ['kind' => 'Namespace', 'name' => 'demo'],
    ['kind' => 'Pod', 'name' => 'pod', 'resource' => $child], ['kind' => 'Secret', 'name' => 'a']
], [$child]);
expect(array_column($groups['visible'], 'kind') === ['Pod', 'ReplicaSet'], 'Visible objects include sorted children and current object without duplicates');
expect(array_column($groups['unresolved'], 'name') === ['demo', 'a', 'z'], 'Unresolved references sort independently');
expect($groups['visible'][1]['current'] === true, 'Current object retains its highlight');
echo "resource presentation tests: ok\n";
