<?php

namespace Icinga\Module\Kubernetes\Controllers;

use Icinga\Authentication\Auth;
use Icinga\Application\Logger;
use Icinga\Module\Kubernetes\Api\ApiException;
use Icinga\Module\Kubernetes\Api\Client;
use Icinga\Module\Kubernetes\Authorization\ResourceAccess;
use Icinga\Module\Kubernetes\Web\Relationships;
use Icinga\Web\Controller;
use InvalidArgumentException;
use Throwable;

class ResourcesController extends Controller
{
    public function typesAction(): void
    {
        $this->assertPermission('kubernetes/resources/show');
        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->layout->disableLayout();
        $this->getResponse()->setHeader('Content-Type', 'application/json', true);
        $this->getResponse()->setHeader('Cache-Control', 'no-store', true);
        try {
            $selectors = $this->createResourceAccess()->selectors() ?: [[]];
            $pages = $this->createClient()->getMany(array_map(
                static fn (array $selector): array => ['path' => 'resource-types', 'query' => $selector + ['hideZeroReplicaSets' => 'true']], $selectors
            ));
            $types = [];
            foreach ($pages as $page) {
                if (! isset($page['items']) || ! is_array($page['items']) || count($page['items']) > 4096) {
                    throw new ApiException('Invalid resource type inventory');
                }
                foreach ($page['items'] as $type) {
                    foreach (['group', 'version', 'kind'] as $key) {
                        if (! isset($type[$key]) || ! is_string($type[$key]) || strlen($type[$key]) > 512) {
                            throw new ApiException('Invalid resource type');
                        }
                    }
                    if (! isset($type['count']) || ! is_int($type['count']) || $type['count'] < 1) continue;
                    $key = json_encode([$type['group'], $type['version'], $type['kind']]);
                    $types[$key] = array_intersect_key($type, array_flip(['group', 'version', 'kind', 'count']));
                    // Multiple granting roles can overlap; do not add duplicate counts.
                    if (count($selectors) > 1) $types[$key]['count'] = null;
                }
            }
            uasort($types, static fn (array $a, array $b): int => strcasecmp($a['kind'], $b['kind']));
            echo json_encode(['items' => array_values($types)], JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            $this->reportFailure($error);
            $this->getResponse()->setHttpResponseCode(502);
            echo json_encode(['error' => 'Resource inventory unavailable']);
        }
    }

    public function metricsAction(): void
    {
        $this->assertPermission('kubernetes/resources/show');
        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->layout->disableLayout();
        $response = $this->getResponse();
        $response->setHeader('Content-Type', 'application/json', true);
        $response->setHeader('Cache-Control', 'no-store', true);
        $response->setHeader('X-Content-Type-Options', 'nosniff', true);
        try {
            $id = (string) $this->params->getRequired('id');
            $client = $this->createClient();
            $access = $this->createResourceAccess();
            $selectors = $access->selectors();
            $items = $client->post('resources/batch-get', ['ids' => [$id]]);
            $resource = $this->findResource($items, $id);
            if ($resource === null || ! $access->permits($resource, $selectors)) {
                $response->setHttpResponseCode(404);
                echo json_encode(['error' => 'Resource not found'], JSON_THROW_ON_ERROR);
                return;
            }
            $range = (string) $this->params->get('range', '3600');
            if (! in_array($range, ['900', '3600', '10800', '21600'], true)) {
                throw new InvalidArgumentException('Invalid metrics time range');
            }
            $metrics = $client->get(
                'live/resources/' . rawurlencode($id) . '/metrics',
                ['cluster' => $resource['cluster'] ?? '', 'rangeSeconds' => (int) $range, 'stepSeconds' => (int) $range > 3600 ? 120 : 30]
            );
            $metrics = $this->sanitizeMetrics($metrics, $id, $resource['cluster'], $client->getFreshness());
            echo json_encode($metrics, JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            $this->reportFailure($error);
            $response->setHttpResponseCode(502);
            echo json_encode(['error' => 'Kubernetes API unavailable'], JSON_THROW_ON_ERROR);
        }
    }

    public function streamAction(): void
    {
        $this->assertPermission('kubernetes/resources/show');
        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->layout->disableLayout();
        $response = $this->getResponse();
        $response->setHeader('Content-Type', 'text/event-stream', true);
        $response->setHeader('Cache-Control', 'no-cache', true);
        $response->setHeader('X-Accel-Buffering', 'no', true);
        try {
            if ($this->createResourceAccess()->selectors() !== []) {
                $response->setHttpResponseCode(403);
                echo "event: error\ndata: {}\n\n";
                return;
            }
        } catch (Throwable $error) {
            $this->reportFailure($error);
            $response->setHttpResponseCode(403);
            echo "event: error\ndata: {}\n\n";
            return;
        }
        session_write_close();
        try {
            $this->createClient()->streamEvents(
                $this->getRequest()->getHeader('Last-Event-ID'),
                static function (string $chunk): void {
                    echo $chunk;
                    @ob_flush();
                    flush();
                }
            );
        } catch (Throwable $error) {
            $this->reportFailure($error);
            if (! headers_sent()) {
                $response->setHttpResponseCode(502);
            }
            echo "event: error\ndata: {}\n\n";
        }
    }

    public function indexAction(): void
    {
        $this->assertPermission('kubernetes/resources/show');
        $this->view->title = $this->translate('Kubernetes Resources');
        $params = [];
        try {
            foreach (['cluster', 'group', 'version', 'kind', 'namespace', 'name', 'labels', 'state', 'cursor', 'ownerUID'] as $name) {
                $params[$name] = $this->filterParameter($name, $this->params->get($name));
            }
            $limit = $this->params->get('limit', 100);
            if (! is_scalar($limit) || ! ctype_digit((string) $limit)) {
                throw new InvalidArgumentException('Invalid resource limit');
            }
            $params['limit'] = min(500, max(1, (int) $limit));
            $showInactive = $this->params->get('showInactive', 'no');
            if (! in_array($showInactive, ['yes', 'no'], true)) throw new InvalidArgumentException('Invalid showInactive');
            $params['showInactive'] = $showInactive;
            $client = $this->createClient();
            $access = $this->createResourceAccess();
            $selectors = $access->selectors();
            $selectorList = $selectors ?: [[]];
            $problems = ($params['state'] ?? '') === 'problem';
            if ($problems) {
                $expanded = [];
                foreach ($selectorList as $selector) {
                    foreach (['critical', 'warning', 'unknown'] as $state) {
                        $combined = $this->intersect(['state' => $state], $selector);
                        if ($combined !== null) $expanded[] = $combined;
                    }
                }
                $selectorList = $expanded;
            }
            $cursors = $this->decodeCursor((string) ($params['cursor'] ?? ''), count($selectorList));
            unset($params['cursor']);
            $requests = [];
            $requestIndexes = [];
            $pages = array_fill(0, count($selectorList), ['items' => []]);
            foreach ($selectorList as $index => $selector) {
                $queryParams = $params;
                if ($problems) unset($queryParams['state']);
                $restricted = $this->intersect($queryParams, $selector);
                if ($restricted !== null) {
                    $restricted['order'] = 'id';
                    unset($restricted['showInactive']);
                    $restricted['hideZeroReplicaSets'] = $showInactive === 'yes' ? 'false' : 'true';
                    $restricted['cursor'] = $cursors[$index] ?? null;
                    $requests[] = ['path' => 'resources', 'query' => $restricted];
                    $requestIndexes[] = $index;
                }
            }
            if ($requests !== []) {
                foreach ($client->getMany($requests) as $resultIndex => $page) {
                    $this->assertPage($page);
                    $pages[$requestIndexes[$resultIndex]] = $page;
                }
            }
            $items = [];
            foreach ($pages as $index => $page) {
                foreach ($page['items'] ?? [] as $item) {
                    $this->assertResource($item);
                    if ($access->permits($item, $selectors)) {
                        $items[$item['id']]['item'] = $item;
                        $items[$item['id']]['streams'][] = $index;
                    }
                }
            }
            ksort($items, SORT_STRING);
            $selected = array_slice($items, 0, $params['limit'], true);
            $this->view->items = array_values(array_map(static fn ($entry) => $entry['item'], $selected));
            $next = $cursors;
            foreach ($selected as $id => $entry) {
                foreach ($entry['streams'] as $index) {
                    $next[$index] = rtrim(strtr(base64_encode($id), '+/', '-_'), '=');
                }
            }
            $hasMore = count($items) > count($selected);
            foreach ($pages as $page) {
                $hasMore = $hasMore || ! empty($page['nextCursor']);
            }
            $this->view->nextCursor = $hasMore ? $this->encodeCursor($next) : null;
            $snapshots = [];
            $freshness = $client->getFreshness();
            foreach ($pages as $page) {
                if (isset($page['snapshot']) && is_string($page['snapshot']) && $page['snapshot'] !== '') {
                    $snapshots[] = $page['snapshot'];
                }
                if (isset($page['freshness']) && is_string($page['freshness'])) {
                    $freshness = $this->worstFreshness($freshness, $page['freshness']);
                }
            }
            sort($snapshots, SORT_STRING);
            $this->view->snapshot = $snapshots[0] ?? null;
            $this->view->freshness = $freshness;
            $this->view->filters = $params;
            $this->view->title = ! empty($params['kind']) ? $params['kind'] . ' resources' : $this->translate('All Kubernetes resources');
            if ($problems) $this->view->title = $this->translate('Kubernetes Problems');
            $this->view->error = null;
            // A proxied SSE connection occupies a PHP-FPM worker per browser tab.
            // Use short polling requests for every role so live lists cannot
            // exhaust the Web pool and delay navigation or readiness checks.
            $this->view->streamUrl = '';
        } catch (Throwable $error) {
            $this->reportFailure($error);
            if ($error instanceof InvalidArgumentException) {
                $this->getResponse()->setHttpResponseCode(400);
            }
            $this->view->items = [];
            $this->view->nextCursor = null;
            $this->view->filters = $params;
            $this->view->snapshot = null;
            $this->view->freshness = 'unavailable';
            $this->view->streamUrl = '';
            $this->view->error = $error instanceof InvalidArgumentException
                ? $this->translate('Invalid Kubernetes resource filter')
                : $this->translate('Kubernetes API unavailable');
        }
    }

    public function showAction(): void
    {
        $this->assertPermission('kubernetes/resources/show');
        $wantsLogs = $this->params->get('logs') === 'yes';
        if ($wantsLogs) {
            $this->assertPermission('kubernetes/resources/logs');
        }
        $id = (string) $this->params->getRequired('id');
        try {
            $showInactive = $this->params->get('showInactive', 'no');
            if (! in_array($showInactive, ['yes', 'no'], true)) throw new InvalidArgumentException('Invalid showInactive');
            $this->view->showInactive = $showInactive === 'yes';
            $client = $this->createClient();
            $access = $this->createResourceAccess();
            $selectors = $access->selectors();
            $items = $client->post('resources/batch-get', ['ids' => [$id]]);
            $resource = $this->findResource($items, $id);
            if ($resource === null || ! $access->permits($resource, $selectors)) {
                $this->httpNotFound($this->translate('Resource not found'));
                return;
            }
            $this->view->resource = $resource;
            $this->view->metricsUrl = $this->view->href('kubernetes/resources/metrics', ['id' => $id]);
            $this->view->canShowLogs = Auth::getInstance()->hasPermission('kubernetes/resources/logs');
            $this->view->logs = null;
            $this->view->manifest = null;
            $this->view->liveError = null;
            $this->view->children = [];
            $this->view->childrenMore = false;
            $this->view->relationsError = null;
            $this->view->connections = [];
            try {
                // Query each authorized selector independently, just like the list.
                $children = [];
                foreach ($selectors ?: [[]] as $selector) {
                    $query = $this->intersect(['cluster' => $resource['cluster'], 'ownerUID' => $resource['uid'], 'limit' => 100, 'order' => 'id'], $selector);
                    if ($query === null) continue;
                    $query['hideZeroReplicaSets'] = $showInactive === 'yes' ? 'false' : 'true';
                    $page = $client->get('resources', $query);
                    $this->assertPage($page);
                    foreach ($page['items'] as $child) {
                        $this->assertResource($child);
                        if ($access->permits($child, $selectors)) $children[$child['id']] = $child;
                    }
                    $this->view->childrenMore = $this->view->childrenMore || ! empty($page['nextCursor']);
                }
                $this->view->children = array_values($children);
            } catch (Throwable $error) {
                $this->reportFailure($error);
                $this->view->relationsError = $this->translate('Child resources could not be loaded');
            }
            try {
                $this->view->manifest = $client->get('live/resources/' . rawurlencode($id) . '/manifest', ['cluster' => $resource['cluster']]);
                if ($resource['kind'] === 'Pod' && $wantsLogs) {
                    $this->view->logs = $client->getText(
                        'live/pods/' . rawurlencode($resource['namespace'] ?? '') . '/' . rawurlencode($resource['name']) . '/logs',
                        ['cluster' => $resource['cluster'], 'container' => $this->params->get('container'), 'tailLines' => 500]
                    );
                }
            } catch (Throwable $error) {
                $this->reportFailure($error);
                $this->view->liveError = $this->translate('Live Kubernetes data is unavailable');
            }
            if ($this->view->manifest !== null) {
                try {
                    $this->view->connections = $this->environmentConnections($client, $access, $selectors, $resource, $this->view->manifest);
                } catch (Throwable $error) {
                    $this->reportFailure($error);
                    $this->view->relationsError = $this->translate('Some environment relationships could not be resolved');
                }
            }
            $this->view->error = null;
            $this->view->title = $resource['name'];
        } catch (Throwable $error) {
            $this->reportFailure($error);
            $this->view->resource = null;
            $this->view->metricsUrl = null;
            $this->view->canShowLogs = false;
            $this->view->manifest = null;
            $this->view->logs = null;
            $this->view->liveError = null;
            $this->view->error = $this->translate('Kubernetes API unavailable');
        }
    }

    private function environmentConnections(Client $client, ResourceAccess $access, array $selectors, array $resource, array $manifest): array
    {
        $connections = [];
        $resolve = function (array $refs) use ($client, $access, $selectors, $resource, &$connections): array {
            if (count($refs) > 64) throw new ApiException('Too many resource references');
            $requests = []; $mapping = [];
            foreach ($refs as $index => $ref) {
                foreach ($selectors ?: [[]] as $selector) {
                    $query = $this->intersect(['cluster' => $resource['cluster'], 'group' => $ref['group'], 'kind' => $ref['kind'], 'namespace' => $ref['namespace'], 'name' => $ref['name'], 'limit' => 2], $selector);
                    if ($query === null) continue;
                    $requests[] = ['path' => 'resources', 'query' => $query]; $mapping[] = $index;
                }
            }
            if (count($requests) > 256) throw new ApiException('Too many authorized relationship queries');
            foreach (array_chunk($requests, 16, true) as $batch) {
                $pages = $client->getMany(array_values($batch));
                foreach (array_keys($batch) as $offset => $requestIndex) {
                    $page = $pages[$offset]; $this->assertPage($page);
                    $index = $mapping[$requestIndex]; $ref = $refs[$index];
                    foreach ($page['items'] as $item) {
                        $this->assertResource($item);
                        if ($item['cluster'] !== $resource['cluster'] || $item['kind'] !== $ref['kind'] || ($item['group'] ?: 'core') !== $ref['group'] || ($item['namespace'] ?? '') !== $ref['namespace'] || $item['name'] !== $ref['name']) continue;
                        if (! empty($ref['uid']) && $item['uid'] !== $ref['uid']) continue;
                        if ($access->permits($item, $selectors)) $refs[$index]['resource'] = $item;
                    }
                }
            }
            foreach ($refs as $ref) $connections[json_encode([$ref['group'], $ref['kind'], $ref['namespace'], $ref['name']])] = $ref;
            $this->view->connections = array_values($connections);
            return $refs;
        };
        $refs = $resolve(Relationships::references($resource, $manifest));
        $inspect = [];
        // Resolve one additional owner/storage hop: Pod -> ReplicaSet -> Deployment,
        // or Pod -> PVC -> PV. These are only queried after authorization above.
        foreach ($refs as $ref) if (isset($ref['resource']) && in_array($ref['kind'], ['ReplicaSet', 'PersistentVolumeClaim'], true)) $inspect[$ref['resource']['id']] = $ref['resource'];
        $podManifest = $resource['kind'] === 'Pod' ? $manifest : ($manifest['spec']['template'] ?? $manifest['spec']['jobTemplate']['spec']['template'] ?? null);
        if ($podManifest !== null) {
            $podManifest['metadata']['namespace'] = $resource['namespace'] ?? '';
            foreach ($selectors ?: [[]] as $selector) {
                $query = $this->intersect(['cluster' => $resource['cluster'], 'namespace' => $resource['namespace'], 'group' => 'core', 'kind' => 'Service', 'limit' => 32], $selector);
                if ($query === null) continue;
                $page = $client->get('resources', $query); $this->assertPage($page);
                if (! empty($page['nextCursor'])) $this->view->relationsError = $this->translate('Service relationships are limited to the first 32 visible services');
                foreach ($page['items'] as $service) {
                    $this->assertResource($service);
                    if ($service['kind'] === 'Service' && ($service['group'] ?: 'core') === 'core' && $service['cluster'] === $resource['cluster'] && $service['namespace'] === $resource['namespace'] && $access->permits($service, $selectors)) $inspect[$service['id']] = $service;
                }
            }
        }
        if (count($inspect) > 64) throw new ApiException('Too many related manifests');
        foreach (array_chunk(array_values($inspect), 16) as $batch) {
            $manifests = $client->getMany(array_map(static fn (array $r): array => ['path' => 'live/resources/' . rawurlencode($r['id']) . '/manifest', 'query' => ['cluster' => $r['cluster']]], $batch));
            foreach ($batch as $index => $related) {
                $m = $manifests[$index];
                if ($related['kind'] === 'Service') {
                    if (Relationships::selectsPod($m, $podManifest)) $connections[$related['id']] = ['relation' => 'Selected by service', 'kind' => 'Service', 'name' => $related['name'], 'resource' => $related];
                } else {
                    $next = Relationships::references($related, $m);
                    $next = array_values(array_filter($next, static fn (array $ref): bool => in_array($ref['relation'], ['Owner', 'Bound volume'], true)));
                    foreach ($next as &$ref) $ref['relation'] = $related['kind'] === 'ReplicaSet' ? 'Workload' : 'Backing storage';
                    unset($ref);
                    $resolve($next);
                }
            }
        }
        if ($resource['kind'] === 'Service' && ! empty($manifest['spec']['selector'])) {
            $labels = [];
            foreach ($manifest['spec']['selector'] as $key => $value) $labels[] = $key . '=' . $value;
            foreach ($selectors ?: [[]] as $selector) {
                $query = $this->intersect(['cluster' => $resource['cluster'], 'namespace' => $resource['namespace'], 'group' => 'core', 'kind' => 'Pod', 'labels' => implode(',', $labels), 'limit' => 100], $selector);
                if ($query === null) continue;
                $page = $client->get('resources', $query); $this->assertPage($page);
                if (! empty($page['nextCursor'])) $this->view->relationsError = $this->translate('Only the first 100 selected pods are shown');
                foreach ($page['items'] as $pod) {
                    $this->assertResource($pod);
                    if ($pod['kind'] !== 'Pod' || ($pod['group'] ?: 'core') !== 'core' || $pod['cluster'] !== $resource['cluster'] || $pod['namespace'] !== $resource['namespace'] || ! $access->permits($pod, $selectors)) continue;
                    if (Relationships::selectsPod($manifest, ['metadata' => ['namespace' => $pod['namespace'], 'labels' => $pod['labels'] ?? []]])) $connections[$pod['id']] = ['relation' => 'Selected pod', 'kind' => 'Pod', 'name' => $pod['name'], 'resource' => $pod];
                }
            }
        }
        return array_values($connections);
    }

    /**
     * Intersect a user query with one exact role selector. A conflict yields no
     * request at all, which keeps authorization fail-closed.
     *
     * @return array<string, mixed>|null
     */
    private function intersect(array $query, array $selector): ?array
    {
        foreach ($selector as $key => $value) {
            if ($key === 'labels') {
                $query['labels'] = implode(',', array_filter([$query['labels'] ?? '', $value]));
            } elseif (isset($query[$key]) && $query[$key] !== '' && (string) $query[$key] !== $value) {
                return null;
            } else {
                $query[$key] = $value;
            }
        }

        return $query;
    }

    /** @return array<int, string> */
    private function decodeCursor(string $cursor, int $streams): array
    {
        if ($cursor === '') {
            return array_fill(0, $streams, '');
        }
        if (strlen($cursor) > 8192 || ! preg_match('/^[A-Za-z0-9_-]+$/', $cursor)) {
            throw new InvalidArgumentException('Invalid module cursor');
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        $decoded = $raw === false ? null : json_decode($raw, true);
        if (! is_array($decoded) || count($decoded) !== $streams) {
            throw new InvalidArgumentException('Invalid or outdated module cursor');
        }
        foreach ($decoded as $value) {
            if (! is_string($value)
                || ($value !== '' && (strlen($value) > 256 || ! preg_match('/^[A-Za-z0-9_-]+$/', $value)))
            ) {
                throw new InvalidArgumentException('Invalid module cursor');
            }
        }

        return array_values($decoded);
    }

    private function encodeCursor(array $cursors): string
    {
        return rtrim(strtr(base64_encode(json_encode(array_values($cursors), JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @param mixed $value */
    private function filterParameter(string $name, $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_scalar($value)) {
            throw new InvalidArgumentException('Invalid resource filter');
        }
        $value = (string) $value;
        $maximums = [
            'cluster' => 253, 'group' => 512, 'version' => 512, 'kind' => 128,
            'namespace' => 253, 'name' => 512, 'labels' => 4096, 'state' => 8, 'cursor' => 8192
        ];
        $maximum = $maximums[$name] ?? 253;
        if (strlen($value) > $maximum || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Invalid resource filter');
        }
        if ($name === 'state' && ! in_array($value, ['ok', 'warning', 'critical', 'unknown', 'problem'], true)) {
            throw new InvalidArgumentException('Invalid resource state');
        }

        return $value;
    }

    private function worstFreshness(string $left, string $right): string
    {
        $rank = ['live' => 0, 'stale' => 1, 'unavailable' => 2];
        if (! isset($rank[$left], $rank[$right])) {
            throw new ApiException('Kubernetes API returned an invalid freshness state');
        }

        return $rank[$left] >= $rank[$right] ? $left : $right;
    }

    /** @param array<string, mixed> $response @return array<string, mixed> */
    private function sanitizeMetrics(array $response, string $resourceId, string $cluster, string $headerFreshness): array
    {
        if (($response['resourceId'] ?? null) !== $resourceId
            || ($response['cluster'] ?? null) !== $cluster
            || ($response['source'] ?? null) !== 'cluster-monitoring-live'
            || ! is_string($response['start'] ?? null)
            || ! is_string($response['end'] ?? null)
            || ! is_int($response['stepSeconds'] ?? null)
            || $response['stepSeconds'] < 15
            || $response['stepSeconds'] > 300
            || ! is_array($response['metrics'] ?? null)
            || count($response['metrics']) > 64
            || ! is_string($response['freshness'] ?? null)
        ) {
            throw new ApiException('Kubernetes API returned invalid resource metrics');
        }
        $metrics = [];
        foreach ($response['metrics'] as $metric) {
            if (! is_array($metric)
                || ! is_string($metric['key'] ?? null)
                || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $metric['key'])
                || ! is_string($metric['label'] ?? null)
                || $metric['label'] === ''
                || strlen($metric['label']) > 128
                || ! is_string($metric['unit'] ?? null)
                || $metric['unit'] === ''
                || strlen($metric['unit']) > 64
                || (isset($metric['data']) && ! is_array($metric['data']))
                || (isset($metric['error']) && ! is_string($metric['error']))
            ) {
                throw new ApiException('Kubernetes API returned an invalid metric');
            }
            $item = ['key' => $metric['key'], 'label' => $metric['label'], 'unit' => $metric['unit']];
            if (isset($metric['data'])) {
                $item['data'] = $metric['data'];
            }
            if (isset($metric['error'])) {
                $item['error'] = $metric['error'];
            }
            $metrics[] = $item;
        }

        return [
            'resourceId' => $resourceId,
            'cluster' => $cluster,
            'source' => 'cluster-monitoring-live',
            'start' => $response['start'],
            'end' => $response['end'],
            'stepSeconds' => $response['stepSeconds'],
            'metrics' => $metrics,
            'freshness' => $this->worstFreshness($headerFreshness, $response['freshness'])
        ];
    }

    /** @return array<string, mixed>|null */
    private function findResource(array $items, string $id): ?array
    {
        foreach ($items as $item) {
            $this->assertResource($item);
            if ($item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    /** @param mixed $resource */
    private function assertResource($resource): void
    {
        if (! is_array($resource)) {
            throw new ApiException('Kubernetes API returned an invalid resource');
        }
        foreach (['id', 'cluster', 'uid', 'version', 'kind', 'name', 'resourceVersion', 'state', 'observedAt'] as $field) {
            if (! isset($resource[$field]) || ! is_string($resource[$field]) || $resource[$field] === '') {
                throw new ApiException('Kubernetes API returned an incomplete resource');
            }
        }
        if (! array_key_exists('group', $resource)
            || ! is_string($resource['group'])
            || ! in_array($resource['state'], ['ok', 'warning', 'critical', 'unknown'], true)
            || (isset($resource['namespace']) && ! is_string($resource['namespace']))
            || (isset($resource['labels']) && ! is_array($resource['labels']))
            || (isset($resource['conditions']) && ! is_array($resource['conditions']))
        ) {
            throw new ApiException('Kubernetes API returned an invalid resource');
        }
    }

    /** @param mixed $page */
    private function assertPage($page): void
    {
        if (! is_array($page)
            || ! isset($page['items'], $page['snapshot'], $page['freshness'])
            || ! is_array($page['items'])
            || ! is_string($page['snapshot'])
            || $page['snapshot'] === ''
            || ! is_string($page['freshness'])
            || ! in_array($page['freshness'], ['live', 'stale', 'unavailable'], true)
            || (isset($page['nextCursor']) && (
                ! is_string($page['nextCursor'])
                || strlen($page['nextCursor']) > 4096
                || ($page['nextCursor'] !== '' && ! preg_match('/^[A-Za-z0-9_-]+$/', $page['nextCursor']))
            ))
        ) {
            throw new ApiException('Kubernetes API returned an invalid resource page');
        }
    }

    private function reportFailure(Throwable $error): void
    {
        Logger::error(
            'Icinga Kubernetes Web request failed (%s): %s',
            get_class($error),
            $error->getMessage()
        );
    }

    protected function createClient(): Client
    {
        return new Client();
    }

    protected function createResourceAccess(): ResourceAccess
    {
        return new ResourceAccess();
    }
}
