<?php
namespace Icinga\Module\Kubernetes\Web;

/** Relationships evidenced by object references or exact Kubernetes selectors. */
final class Relationships
{
    public static function references(array $r, array $m): array
    {
        $refs = [];
        $namespace = $r['namespace'] ?? '';
        $add = static function (string $relation, string $kind, $name, string $group = 'core', ?string $ns = null, ?string $uid = null) use (&$refs, $namespace): void {
            if (! is_string($name) || $name === '') return;
            $ref = ['relation' => $relation, 'group' => $group, 'kind' => $kind, 'name' => $name, 'namespace' => $ns ?? $namespace, 'uid' => $uid];
            $key = json_encode([$group, $kind, $ref['namespace'], $name]);
            if (! isset($refs[$key])) $refs[$key] = $ref;
        };
        foreach ($m['metadata']['ownerReferences'] ?? [] as $owner) {
            $api = explode('/', $owner['apiVersion'] ?? 'v1');
            $add('Owner', $owner['kind'], $owner['name'], count($api) > 1 ? $api[0] : 'core', null, $owner['uid'] ?? null);
        }
        if ($namespace !== '') $add('Namespace', 'Namespace', $namespace, 'core', '');
        if (($r['kind'] ?? '') === 'Event') {
            foreach (['regarding', 'involvedObject', 'related'] as $key) {
                $target = $m[$key] ?? [];
                if (empty($target['kind'])) continue;
                $api = explode('/', $target['apiVersion'] ?? 'v1');
                $relation = 'Regarding';
                if (! empty($target['fieldPath'])) $relation .= ' · ' . $target['fieldPath'];
                $add($relation, $target['kind'], $target['name'] ?? null, count($api) > 1 ? $api[0] : 'core', $target['namespace'] ?? $namespace, $target['uid'] ?? null);
            }
        }
        $spec = $m['spec'] ?? [];
        $pod = ($r['kind'] ?? '') === 'Pod' ? $spec : ($spec['template']['spec'] ?? $spec['jobTemplate']['spec']['template']['spec'] ?? []);
        $add('Runs on', 'Node', $pod['nodeName'] ?? null, 'core', '');
        $add('Identity', 'ServiceAccount', $pod['serviceAccountName'] ?? null);
        foreach ($pod['imagePullSecrets'] ?? [] as $secret) $add('Image pull credentials', 'Secret', $secret['name'] ?? null);
        foreach ($pod['volumes'] ?? [] as $volume) {
            $add('Storage', 'PersistentVolumeClaim', $volume['persistentVolumeClaim']['claimName'] ?? null);
            $add('Configuration volume', 'ConfigMap', $volume['configMap']['name'] ?? null);
            $add('Secret volume', 'Secret', $volume['secret']['secretName'] ?? null);
            foreach ($volume['projected']['sources'] ?? [] as $source) {
                $add('Projected configuration', 'ConfigMap', $source['configMap']['name'] ?? null);
                $add('Projected secret', 'Secret', $source['secret']['name'] ?? null);
            }
        }
        foreach (['containers', 'initContainers', 'ephemeralContainers'] as $key) foreach ($pod[$key] ?? [] as $container) {
            foreach ($container['envFrom'] ?? [] as $env) {
                $add('Container configuration', 'ConfigMap', $env['configMapRef']['name'] ?? null);
                $add('Container secret', 'Secret', $env['secretRef']['name'] ?? null);
            }
            foreach ($container['env'] ?? [] as $env) {
                $add('Container configuration', 'ConfigMap', $env['valueFrom']['configMapKeyRef']['name'] ?? null);
                $add('Container secret', 'Secret', $env['valueFrom']['secretKeyRef']['name'] ?? null);
            }
        }
        if (($r['kind'] ?? '') === 'PersistentVolumeClaim') $add('Bound volume', 'PersistentVolume', $spec['volumeName'] ?? null, 'core', '');
        if (in_array($r['kind'] ?? '', ['PersistentVolume', 'PersistentVolumeClaim'], true)) $add('Storage class', 'StorageClass', $spec['storageClassName'] ?? null, 'storage.k8s.io', '');
        if (($r['kind'] ?? '') === 'StatefulSet') $add('Governing service', 'Service', $spec['serviceName'] ?? null);
        if (($r['kind'] ?? '') === 'HorizontalPodAutoscaler' && isset($spec['scaleTargetRef'])) {
            $target = $spec['scaleTargetRef']; $api = explode('/', $target['apiVersion'] ?? 'v1');
            $add('Scales', $target['kind'], $target['name'], count($api) > 1 ? $api[0] : 'core');
        }
        if (($r['kind'] ?? '') === 'PersistentVolume') $add('Claim', 'PersistentVolumeClaim', $spec['claimRef']['name'] ?? null, 'core', $spec['claimRef']['namespace'] ?? '');
        if (($r['kind'] ?? '') === 'Route') {
            $add('Routes to', 'Service', $spec['to']['name'] ?? null);
            foreach ($spec['alternateBackends'] ?? [] as $backend) $add('Routes to', 'Service', $backend['name'] ?? null);
        }
        if (($r['kind'] ?? '') === 'Ingress') {
            $add('Default backend', 'Service', $spec['defaultBackend']['service']['name'] ?? null);
            foreach ($spec['rules'] ?? [] as $rule) foreach ($rule['http']['paths'] ?? [] as $path) $add('Backend', 'Service', $path['backend']['service']['name'] ?? null);
        }
        return array_values($refs);
    }

    public static function selectsPod(array $service, array $pod): bool
    {
        $selector = $service['spec']['selector'] ?? [];
        if (! is_array($selector) || $selector === []) return false;
        if (($service['metadata']['namespace'] ?? '') !== ($pod['metadata']['namespace'] ?? '')) return false;
        foreach ($selector as $key => $value) if (($pod['metadata']['labels'][$key] ?? null) !== $value) return false;
        return true;
    }
}
