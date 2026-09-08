<?php

namespace Icinga\Module\Kubernetes\Controllers;

use Icinga\Application\Logger;
use Icinga\Module\Kubernetes\Api\ApiException;
use Icinga\Module\Kubernetes\Api\Client;
use Icinga\Web\Controller;
use Throwable;

class DashboardController extends Controller
{
    public function indexAction(): void
    {
        $this->assertPermission('kubernetes/resources/show');
        $this->view->title = $this->translate('Kubernetes');
        try {
            $client = $this->createClient();
            [$status, $branches] = $client->getMany([
                ['path' => 'status'],
                ['path' => 'branches/status']
            ]);
            if (! isset($status['cluster'], $status['database'], $status['freshness'])
                || ! is_string($status['cluster'])
                || ! is_string($status['database'])
                || ! is_string($status['freshness'])
                || ! in_array($status['freshness'], ['live', 'stale', 'unavailable'], true)
                || (isset($status['pendingEvents']) && ! is_int($status['pendingEvents']))
                || (isset($status['lastAppliedAt']) && ! is_string($status['lastAppliedAt']))
                || ! isset($branches['items'])
                || ! is_array($branches['items'])
                || count($branches['items']) > 64
                || ($branches['transitive'] ?? null) !== false
            ) {
                throw new ApiException('Kubernetes API returned an invalid dashboard response');
            }
            $this->view->status = [
                'cluster' => $status['cluster'],
                'database' => $status['database'],
                'freshness' => $status['freshness'],
                'pendingEvents' => max(0, (int) ($status['pendingEvents'] ?? 0)),
                'lastAppliedAt' => $status['lastAppliedAt'] ?? null
            ];
            $sanitizedBranches = [];
            foreach ($branches['items'] as $branch) {
                if (! is_array($branch)
                    || ! isset($branch['name'], $branch['freshness'])
                    || ! is_string($branch['name'])
                    || $branch['name'] === ''
                    || strlen($branch['name']) > 253
                    || ! in_array($branch['freshness'], ['live', 'unavailable'], true)
                    || (isset($branch['latency']) && (! is_string($branch['latency']) || strlen($branch['latency']) > 64))
                    || isset($sanitizedBranches[$branch['name']])
                ) {
                    throw new ApiException('Kubernetes API returned an invalid federation branch');
                }
                // Trusted endpoint URLs and backend error details must never
                // enter the browser-facing view model.
                $sanitizedBranches[$branch['name']] = [
                    'name' => $branch['name'],
                    'freshness' => $branch['freshness'],
                    'latency' => $branch['latency'] ?? null
                ];
            }
            $this->view->branches = array_values($sanitizedBranches);
            $this->view->error = null;
        } catch (Throwable $error) {
            Logger::error(
                'Icinga Kubernetes Web dashboard API request failed (%s): %s',
                get_class($error),
                $error->getMessage()
            );
            $this->view->status = [];
            $this->view->branches = [];
            $this->view->error = $this->translate('Kubernetes API unavailable');
        }
    }

    protected function createClient(): Client
    {
        return new Client();
    }
}
