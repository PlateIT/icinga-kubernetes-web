<?php
require dirname(__DIR__) . '/library/Kubernetes/Web/Relationships.php';
use Icinga\Module\Kubernetes\Web\Relationships;
function checkRelation(bool $ok, string $message): void { if (! $ok) throw new RuntimeException($message); }
$r = ['kind' => 'Pod', 'namespace' => 'demo'];
$m = ['metadata' => ['namespace' => 'demo', 'labels' => ['app' => 'api'], 'ownerReferences' => [['kind' => 'ReplicaSet', 'name' => 'api-rs', 'apiVersion' => 'apps/v1', 'uid' => 'owner-id']]],
    'spec' => ['nodeName' => 'node-1', 'serviceAccountName' => 'api-sa', 'volumes' => [
        ['persistentVolumeClaim' => ['claimName' => 'data']], ['projected' => ['sources' => [['secret' => ['name' => 'credentials']]]]]],
        'containers' => [['name' => 'api', 'env' => [['name' => 'PASSWORD', 'value' => 'never-export'], ['valueFrom' => ['secretKeyRef' => ['name' => 'credentials', 'key' => 'password']]]], 'envFrom' => [['configMapRef' => ['name' => 'settings']]]]]]];
$refs = Relationships::references($r, $m);
checkRelation(count(array_filter($refs, fn($ref) => $ref['name'] === 'credentials')) === 1, 'Repeated secret references deduplicate');
checkRelation(! str_contains(json_encode($refs), 'never-export') && ! str_contains(json_encode($refs), 'password'), 'Relations never include environment or secret values');
$nodes = array_values(array_filter($refs, fn($ref) => $ref['kind'] === 'Node'));
checkRelation($nodes[0]['namespace'] === '', 'Nodes are cluster-scoped');
checkRelation($refs[0]['uid'] === 'owner-id', 'Owner identity is retained');
$service = ['metadata' => ['namespace' => 'demo'], 'spec' => ['selector' => ['app' => 'api']]];
checkRelation(Relationships::selectsPod($service, $m), 'Matching service selector');
$service['spec']['selector']['tier'] = 'backend';
checkRelation(! Relationships::selectsPod($service, $m), 'All selector terms must match');
$service['spec']['selector'] = [];
checkRelation(! Relationships::selectsPod($service, $m), 'Selector-less service must not select every pod');
$service['spec']['selector'] = ['app' => 'api']; $service['metadata']['namespace'] = 'other';
checkRelation(! Relationships::selectsPod($service, $m), 'Services cannot select pods in another namespace');
echo "relationship inference tests: ok\n";
