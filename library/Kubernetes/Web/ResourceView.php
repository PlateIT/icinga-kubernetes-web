<?php

namespace Icinga\Module\Kubernetes\Web;

/** Presentation of API resources; never emits HTML or reads credentials. */
final class ResourceView
{
    public static function environmentGroups(array $resource, array $connections, array $children): array
    {
        $visible = []; $unresolved = [];
        foreach ($connections as $connection) {
            if (isset($connection['resource'])) $visible[$connection['resource']['id']] = $connection;
            else $unresolved[] = $connection;
        }
        foreach ($children as $child) {
            $visible[$child['id']] ??= ['kind' => $child['kind'], 'name' => $child['name'], 'relation' => 'Child', 'resource' => $child];
        }
        $visible[$resource['id']] = ['kind' => $resource['kind'], 'name' => $resource['name'], 'relation' => 'Current object', 'resource' => $resource, 'current' => true];
        return ['visible' => self::sortByType(array_values($visible)), 'unresolved' => self::sortByType($unresolved)];
    }

    public static function sortByType(array $items): array
    {
        usort($items, static function (array $a, array $b): int {
            foreach (['kind', 'name', 'namespace', 'group', 'id'] as $field) {
                $comparison = strnatcasecmp((string) ($a[$field] ?? ''), (string) ($b[$field] ?? ''));
                if ($comparison !== 0) return $comparison;
            }
            return 0;
        });
        return $items;
    }

    public static function containerAnchor(array $resource, array $container): string
    {
        return 'kube-container-' . substr(hash('sha256', ($resource['id'] ?? '') . '/' . $container['name']), 0, 16);
    }
    public static function replicaActivity(array $resource): string
    {
        if (($resource['group'] ?? '') !== 'apps' || ($resource['kind'] ?? '') !== 'ReplicaSet') return '';
        $summary = $resource['summary'] ?? [];
        $desired = $summary['spec.replicas'] ?? null;
        if (! is_numeric($desired)) return 'Replica count unavailable';
        $ready = $summary['status.readyReplicas'] ?? 0;
        $available = $summary['status.availableReplicas'] ?? 0;
        if ((int) $desired === 0) return max((int) $ready, (int) $available) > 0
            ? sprintf('Scaling down · %d replicas still ready/available', max((int) $ready, (int) $available))
            : 'Scaled down · 0 desired';
        return sprintf('Active · %d/%d ready', (int) $ready, (int) $desired);
    }

    public static function containerState(array $container): string
    {
        if (($container['ready'] ?? false) === true) return 'ok';
        if (($container['phase'] ?? '') === 'terminated' && ($container['exitCode'] ?? null) === 0) return 'ok';
        if (in_array($container['reason'] ?? '', ['CrashLoopBackOff', 'ImagePullBackOff', 'ErrImagePull', 'OOMKilled', 'Error'], true)) return 'critical';
        return ($container['phase'] ?? 'unknown') === 'unknown' ? 'unknown' : 'warning';
    }

    public static function state(array $resource): string
    {
        return in_array($resource['state'] ?? '', ['ok', 'warning', 'critical', 'unknown'], true)
            ? $resource['state'] : 'unknown';
    }

    public static function facts(array $resource, array $manifest = []): array
    {
        $summary = $resource['summary'] ?? [];
        $get = static function (string $path) use ($manifest, $summary) {
            if (array_key_exists($path, $summary)) return $summary[$path];
            $value = $manifest;
            foreach (explode('.', $path) as $part) {
                if (! is_array($value) || ! array_key_exists($part, $value)) return null;
                $value = $value[$part];
            }
            return $value;
        };
        $fields = [
            'Created' => 'metadata.creationTimestamp', 'Phase' => 'status.phase',
            'Node' => 'spec.nodeName', 'Pod IP' => 'status.podIP',
            'Restart policy' => 'spec.restartPolicy', 'Quality of service' => 'status.qosClass',
            'Desired replicas' => 'spec.replicas', 'Ready replicas' => 'status.readyReplicas',
            'Available replicas' => 'status.availableReplicas', 'Updated replicas' => 'status.updatedReplicas',
            'Desired nodes' => 'status.desiredNumberScheduled', 'Ready nodes' => 'status.numberReady',
            'Schedule' => 'spec.schedule', 'Suspended' => 'spec.suspend',
            'Active jobs' => 'status.active', 'Succeeded' => 'status.succeeded', 'Failed' => 'status.failed',
            'Service type' => 'spec.type', 'Cluster IP' => 'spec.clusterIP',
            'Storage class' => 'spec.storageClassName', 'Volume' => 'spec.volumeName',
            'Capacity' => 'status.capacity.storage', 'Host' => 'spec.host'
        ];
        $facts = [];
        foreach ($fields as $label => $path) {
            $value = $get($path);
            if (is_scalar($value) && $value !== '') $facts[$label] = is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value;
        }
        if (isset($summary['containers.restarts'])) $facts['Container restarts'] = (string) $summary['containers.restarts'];
        return $facts;
    }

    public static function containers(array $resource, array $manifest = []): array
    {
        $groups = ['Init containers' => 'initContainers', 'Containers' => 'containers', 'Ephemeral containers' => 'ephemeralContainers'];
        $result = [];
        foreach ($groups as $label => $key) {
            $statuses = $manifest['status'][substr($key, 0, -1) . 'Statuses'] ?? [];
            // Kubernetes calls the regular status array containerStatuses.
            $byName = [];
            foreach ($statuses as $status) $byName[$status['name']] = $status;
            foreach ($manifest['spec'][$key] ?? [] as $container) {
                $status = $byName[$container['name']] ?? [];
                $state = $status['state'] ?? [];
                $phase = array_key_first($state) ?? 'unknown';
                $detail = $state[$phase] ?? [];
                $group = $key === 'initContainers' && ($container['restartPolicy'] ?? '') === 'Always' ? 'Sidecar containers' : $label;
                $result[$group][] = [
                    'name' => $container['name'], 'image' => $container['image'] ?? '',
                    'ready' => ($status['ready'] ?? false) === true,
                    'restarts' => (int) ($status['restartCount'] ?? 0), 'phase' => $phase,
                    'reason' => $detail['reason'] ?? '', 'message' => $detail['message'] ?? '',
                    'exitCode' => $detail['exitCode'] ?? null
                ];
            }
        }
        if ($result === [] && isset($resource['summary']['containers'])) {
            $result['Containers'] = $resource['summary']['containers'];
        }
        return $result;
    }

    public static function groupedLabels(array $labels): array
    {
        $groups = [];
        ksort($labels);
        foreach ($labels as $key => $value) {
            $parts = explode('/', $key, 2);
            $groups[count($parts) === 2 ? $parts[0] : 'Labels'][end($parts)] = $value;
        }
        return $groups;
    }
}
