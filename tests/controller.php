<?php

namespace Icinga\Exception { class ConfigurationError extends \RuntimeException {} }

namespace Icinga\Application {
    final class Config
    {
        public static function module(string $name) { throw new \RuntimeException('Unexpected production client construction'); }
    }
    final class Logger
    {
        public static array $messages = [];
        public static function error(string $format, ...$arguments): void { self::$messages[] = vsprintf($format, $arguments); }
    }
}

namespace Icinga\Authentication {
    class Auth
    {
        public static array $permissions = [];
        public static function getInstance(): self { return new self(); }
        public function hasPermission($permission): bool { return in_array($permission, self::$permissions, true); }
        public function getUser() { throw new \RuntimeException('Unexpected production authorization construction'); }
    }
}

namespace Icinga\Web {
    final class TestParams
    {
        public function __construct(public array $values = []) {}
        public function get(string $name, $default = null) { return $this->values[$name] ?? $default; }
        public function getRequired(string $name) { if (! isset($this->values[$name])) throw new \RuntimeException("Missing $name"); return $this->values[$name]; }
    }
    final class TestResponse
    {
        public array $headers = [];
        public int $status = 200;
        public function setHeader(string $name, string $value, bool $replace): void { $this->headers[strtolower($name)] = $value; }
        public function setHttpResponseCode(int $status): void { $this->status = $status; }
    }
    final class TestRequest
    {
        public array $headers = [];
        public function getHeader(string $name): ?string { return $this->headers[strtolower($name)] ?? null; }
    }
    final class TestView
    {
        private array $values = [];
        public function __set(string $name, $value): void { $this->values[$name] = $value; }
        public function __get(string $name) { return $this->values[$name] ?? null; }
        public function href(string $path, array $query = []): string { return '/' . $path . ($query ? '?' . http_build_query($query) : ''); }
    }
    final class TestViewRenderer { public bool $noRender = false; public function setNoRender(): void { $this->noRender = true; } }
    final class TestLayout { public bool $disabled = false; public function disableLayout(): void { $this->disabled = true; } }
    final class TestHelper { public TestViewRenderer $viewRenderer; public TestLayout $layout; public function __construct() { $this->viewRenderer = new TestViewRenderer(); $this->layout = new TestLayout(); } }

    class Controller
    {
        public TestParams $params;
        public TestView $view;
        public TestHelper $_helper;
        public TestResponse $response;
        public TestRequest $request;
        public array $permissions = [];

        public function __construct()
        {
            $this->params = new TestParams();
            $this->view = new TestView();
            $this->_helper = new TestHelper();
            $this->response = new TestResponse();
            $this->request = new TestRequest();
        }
        public function assertPermission($permission): void { if (! in_array($permission, $this->permissions, true)) throw new \RuntimeException("Denied $permission"); }
        public function getResponse(): TestResponse { return $this->response; }
        public function getRequest(): TestRequest { return $this->request; }
        public function translate($message): string { return $message; }
        public function httpNotFound($message): void { $this->response->status = 404; }
    }
}

namespace {
    use Icinga\Authentication\Auth;
    use Icinga\Module\Kubernetes\Api\Client;
    use Icinga\Module\Kubernetes\Authorization\ResourceAccess;
    use Icinga\Module\Kubernetes\Controllers\DashboardController;
    use Icinga\Module\Kubernetes\Controllers\ResourcesController;
    use Icinga\Web\TestParams;

    require dirname(__DIR__) . '/library/Kubernetes/Api/ApiException.php';
    require dirname(__DIR__) . '/library/Kubernetes/Api/Client.php';
    require dirname(__DIR__) . '/library/Kubernetes/Authorization/ResourceAccess.php';
    require dirname(__DIR__) . '/library/Kubernetes/Web/Relationships.php';
    require dirname(__DIR__) . '/application/controllers/ResourcesController.php';
    require dirname(__DIR__) . '/application/controllers/DashboardController.php';

    final class FakeClient extends Client
    {
        public array $calls = [];
        public array $gets = [];
        public array $posts = [];
        public $many = null;
        public string $text = 'live log';
        public function __construct() {}
        public function getFreshness(): string { return 'live'; }
        public function get(string $path, array $query = []): array { $this->calls[] = ['get', $path, $query]; return $this->gets[$path] ?? []; }
        public function getMany(array $requests): array
        {
            $this->calls[] = ['many', $requests];
            if ($this->many !== null) return ($this->many)($requests);
            return array_map(fn (array $request): array => $this->gets[$request['path']] ?? [], $requests);
        }
        public function post(string $path, array $body): array { $this->calls[] = ['post', $path, $body]; return $this->posts[$path] ?? []; }
        public function getText(string $path, array $query = []): string { $this->calls[] = ['text', $path, $query]; return $this->text; }
        public function streamEvents(?string $lastEventId, callable $write, int $seconds = 25): void { $this->calls[] = ['stream', $lastEventId]; $write("id: 1\nevent: resource\ndata: {}\n\n"); }
    }

    final class FakeAccess extends ResourceAccess
    {
        public function __construct(private array $rules = []) {}
        public function selectors(): array { return $this->rules; }
        public function permits(array $resource, array $selectors): bool
        {
            if ($selectors === []) return true;
            foreach ($selectors as $selector) {
                $matches = true;
                foreach ($selector as $field => $value) if (($resource[$field] ?? null) !== $value) $matches = false;
                if ($matches) return true;
            }
            return false;
        }
    }

    final class TestResourcesController extends ResourcesController
    {
        public function __construct(public FakeClient $client, public FakeAccess $access) { parent::__construct(); }
        protected function createClient(): Client { return $this->client; }
        protected function createResourceAccess(): ResourceAccess { return $this->access; }
    }

    final class TestDashboardController extends DashboardController
    {
        public function __construct(public FakeClient $client) { parent::__construct(); }
        protected function createClient(): Client { return $this->client; }
    }

    function check(bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); }

    function resource(string $id, string $cluster = 'campus', string $namespace = 'payments'): array
    {
        return ['id' => $id, 'cluster' => $cluster, 'uid' => 'uid-' . $id, 'group' => '', 'version' => 'v1', 'kind' => 'Pod',
            'namespace' => $namespace, 'name' => 'pod-' . $id, 'resourceVersion' => '1', 'state' => 'ok', 'observedAt' => '2026-01-01T00:00:00Z'];
    }

    $first = '11111111-1111-4111-8111-111111111111';
    $wanted = '22222222-2222-4222-8222-222222222222';
    $client = new FakeClient();
    $client->posts['resources/batch-get'] = [resource($first, 'wrong'), resource($wanted)];
    $client->gets['live/resources/' . $wanted . '/metrics'] = [
        'resourceId' => $wanted, 'cluster' => 'campus', 'source' => 'cluster-monitoring-live',
        'start' => '2026-01-01T00:00:00Z', 'end' => '2026-01-01T01:00:00Z', 'stepSeconds' => 30,
        'metrics' => [['key' => 'cpu', 'label' => 'CPU', 'unit' => 'cores', 'data' => ['status' => 'success'], 'internal' => 'drop']],
        'freshness' => 'stale', 'internal' => 'drop'
    ];
    $controller = new TestResourcesController($client, new FakeAccess());
    $controller->permissions = ['kubernetes/resources/show'];
    $controller->params = new TestParams(['id' => $wanted]);
    ob_start(); $controller->metricsAction(); $payload = json_decode(ob_get_clean(), true);
    check($controller->response->status === 200 && ($payload['freshness'] ?? null) === 'stale', 'Metrics response failed');
    check(! isset($payload['internal'], $payload['metrics'][0]['internal']), 'Metrics response must be reduced to the browser contract');
    check($controller->response->headers['x-content-type-options'] === 'nosniff', 'JSON response must be nosniff');
    check($controller->_helper->layout->disabled, 'Metrics must disable the HTML layout');
    check($client->calls[1][2]['cluster'] === 'campus', 'Controller must select the exact requested ID, not the first batch result');

    $client = new FakeClient();
    $client->many = static function (array $requests) use ($first, $wanted): array {
        return array_map(static function (array $request) use ($first, $wanted): array {
            check(($request['query']['order'] ?? null) === 'id', 'Merged resource streams must request stable ID ordering');
            $cursor = $request['query']['cursor'] ?? null;
            if ($cursor === null || $cursor === '') {
                return ['items' => [resource($first)], 'nextCursor' => rtrim(strtr(base64_encode($first), '+/', '-_'), '='), 'snapshot' => 'now', 'freshness' => 'stale'];
            }
            check(base64_decode(strtr($cursor, '-_', '+/'), true) === $first, 'Second page must resume each API stream at its consumed ID');
            return ['items' => [resource($wanted)], 'snapshot' => 'later', 'freshness' => 'live'];
        }, $requests);
    };
    $access = new FakeAccess([['namespace' => 'payments'], ['kind' => 'Pod']]);
    $controller = new TestResourcesController($client, $access);
    $controller->permissions = ['kubernetes/resources/show'];
    $controller->params = new TestParams(['limit' => 1]);
    $controller->indexAction();
    check(($controller->view->items[0]['id'] ?? null) === $first && $controller->view->nextCursor !== null, 'Restricted first cursor page failed');
    check($controller->view->streamUrl === '', 'Restricted users must not receive the global SSE channel');
    check($controller->view->freshness === 'stale', 'Page freshness must not be hidden by a default-live client state');
    $secondController = new TestResourcesController($client, $access);
    $secondController->permissions = ['kubernetes/resources/show'];
    $secondController->params = new TestParams(['limit' => 1, 'cursor' => $controller->view->nextCursor]);
    $secondController->indexAction();
    check(($secondController->view->items[0]['id'] ?? null) === $wanted && $secondController->view->nextCursor === null, 'Restricted second cursor page failed');

    $client = new FakeClient();
    $client->gets['resources'] = ['items' => [resource($wanted)], 'snapshot' => 'now', 'freshness' => 'live'];
    $controller = new TestResourcesController($client, new FakeAccess());
    $controller->permissions = ['kubernetes/resources/show'];
    $controller->indexAction();
    check($controller->view->error === null && count($controller->view->items) === 1, 'Unrestricted resource list failed');
    check($controller->view->streamUrl === '', 'Unrestricted lists must also poll without occupying a persistent PHP worker');
    check($client->calls[0][1][0]['query']['hideZeroReplicaSets'] === 'true', 'Lists hide scaled-down ReplicaSets before pagination');
    $controller->params = new TestParams(['showInactive' => 'yes']);
    $controller->indexAction();
    check($client->calls[1][1][0]['query']['hideZeroReplicaSets'] === 'false', 'Users can include scaled-down ReplicaSets');

    $client = new FakeClient();
    $client->gets['resources'] = ['items' => [], 'snapshot' => 'now', 'freshness' => 'invented'];
    $controller = new TestResourcesController($client, new FakeAccess());
    $controller->permissions = ['kubernetes/resources/show'];
    $controller->indexAction();
    check($controller->view->freshness === 'unavailable', 'Invalid API page freshness must fail closed');
    check($controller->view->error === 'Kubernetes API unavailable', 'Invalid API page must not be rendered');

    $client = new FakeClient();
    $controller = new TestResourcesController($client, new FakeAccess());
    $controller->permissions = ['kubernetes/resources/show'];
    $controller->params = new TestParams(['cursor' => str_repeat('a', 8193)]);
    $controller->indexAction();
    check($controller->response->status === 400, 'Oversized module cursor must be rejected as a client error');
    check($client->calls === [], 'Invalid module cursor must be rejected before the trusted API is called');

    $client = new FakeClient();
    $controller = new TestResourcesController($client, new FakeAccess());
    $controller->permissions = ['kubernetes/resources/show'];
    $controller->params = new TestParams(['labels' => ['unexpected-array']]);
    $controller->indexAction();
    check($controller->response->status === 400, 'Non-scalar resource filters must be rejected');
    check($client->calls === [], 'Invalid resource filters must be rejected before the trusted API is called');

    $controller = new TestResourcesController(new FakeClient(), new FakeAccess([['namespace' => 'payments']]));
    $controller->permissions = ['kubernetes/resources/show'];
    ob_start(); $controller->streamAction(); $stream = ob_get_clean();
    check($controller->response->status === 403 && str_contains($stream, 'event: error'), 'Restricted SSE must fail closed');

    $client = new FakeClient();
    $client->posts['resources/batch-get'] = [resource($wanted)];
    $client->gets['live/resources/' . $wanted . '/manifest'] = ['kind' => 'Pod'];
    $controller = new TestResourcesController($client, new FakeAccess());
    $controller->permissions = ['kubernetes/resources/show', 'kubernetes/resources/logs'];
    Auth::$permissions = ['kubernetes/resources/logs'];
    $controller->params = new TestParams(['id' => $wanted, 'logs' => 'yes']);
    $controller->showAction();
    check($controller->view->logs === 'live log' && $controller->view->manifest['kind'] === 'Pod', 'Authorized live data was not loaded');

    $controller = new TestResourcesController(new FakeClient(), new FakeAccess());
    $controller->permissions = ['kubernetes/resources/show'];
    $controller->params = new TestParams(['id' => $wanted, 'logs' => 'yes']);
    try { $controller->showAction(); throw new RuntimeException('Log permission was not enforced'); }
    catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'Denied'), 'Unexpected log denial'); }

    $client = new FakeClient();
    $client->gets['status'] = ['cluster' => 'primus', 'database' => 'ready', 'freshness' => 'live', 'pendingEvents' => 2, 'internal' => 'drop'];
    $client->gets['branches/status'] = ['items' => [[
        'name' => 'campus', 'freshness' => 'unavailable', 'latency' => '25ms', 'url' => 'https://internal.example', 'error' => 'sensitive backend detail'
    ]], 'transitive' => false];
    $dashboard = new TestDashboardController($client);
    $dashboard->permissions = ['kubernetes/resources/show'];
    $dashboard->indexAction();
    check($client->calls[0][0] === 'many' && count($client->calls[0][1]) === 2, 'Dashboard status calls must run as one bounded parallel batch');
    check($dashboard->view->branches === [['name' => 'campus', 'freshness' => 'unavailable', 'latency' => '25ms']], 'Dashboard must strip federation endpoint internals');
    check(! isset($dashboard->view->status['internal']), 'Dashboard must use an explicit status projection');

    $client = new FakeClient();
    $parent = resource($wanted);
    $client->posts['resources/batch-get'] = [$parent];
    $client->gets['resources'] = ['items' => [resource($first), resource('33333333-3333-4333-8333-333333333333', 'campus', 'private')], 'snapshot' => '2026-01-01T00:00:00Z', 'freshness' => 'live'];
    $controller = new TestResourcesController($client, new FakeAccess([['namespace' => 'payments']]));
    $controller->permissions = ['kubernetes/resources/show'];
    $controller->params = new TestParams(['id' => $wanted]);
    $controller->showAction();
    check(count($controller->view->children) === 1 && $controller->view->children[0]['id'] === $first, 'Child objects must obey resource restrictions');
    check($client->calls[1][2]['ownerUID'] === $parent['uid'] && $client->calls[1][2]['cluster'] === 'campus' && $client->calls[1][2]['namespace'] === 'payments', 'Child lookup must be scoped by owner, cluster and role');

    $client = new FakeClient();
    $client->many = static function (array $requests): array {
        check(count($requests) === 2 && $requests[0]['query']['namespace'] === 'payments' && $requests[1]['query']['kind'] === 'Pod', 'Inventory must query only granting role selectors');
        return array_fill(0, 2, ['items' => [['group' => 'core', 'version' => 'v1', 'kind' => 'Pod', 'count' => 3]]]);
    };
    $controller = new TestResourcesController($client, new FakeAccess([['namespace' => 'payments'], ['kind' => 'Pod']]));
    $controller->permissions = ['kubernetes/resources/show'];
    ob_start(); $controller->typesAction(); $inventory = json_decode(ob_get_clean(), true);
    check(count($inventory['items']) === 1 && $inventory['items'][0]['count'] === null, 'Inventory must deduplicate overlapping roles without inventing counts');
    check($controller->_helper->layout->disabled, 'Inventory JSON must disable the HTML layout');
    $client = new FakeClient();
    $wrongOwner = resource($first); $wrongOwner['group'] = 'apps'; $wrongOwner['kind'] = 'ReplicaSet'; $wrongOwner['name'] = 'owner';
    $client->gets['resources'] = ['items' => [$wrongOwner], 'snapshot' => 'now', 'freshness' => 'live'];
    $access = new FakeAccess([['namespace' => 'payments']]);
    $controller = new TestResourcesController($client, $access);
    $relations = new ReflectionMethod(ResourcesController::class, 'environmentConnections');
    $connections = $relations->invoke($controller, $client, $access, $access->selectors(), resource($wanted), ['metadata' => ['ownerReferences' => [['apiVersion' => 'apps/v1', 'kind' => 'ReplicaSet', 'name' => 'owner', 'uid' => 'different-uid']]]]);
    check(! isset($connections[0]['resource']), 'A reused owner name with a different UID must not be linked');
    foreach ($client->calls as $call) {
        if ($call[0] === 'many') foreach ($call[1] as $request) check($request['query']['namespace'] === 'payments', 'Related lookups must preserve role namespace restrictions');
    }
    $client = new FakeClient();
    $client->many = static function (array $requests): array {
        check(count($requests) === 3, 'Problem overview queries all three non-OK states');
        foreach ($requests as $request) {
            check(in_array($request['query']['state'], ['critical', 'warning', 'unknown'], true), 'Problem query excludes OK');
            check($request['query']['namespace'] === 'payments', 'Problem query preserves authorization');
        }
        return array_fill(0, 3, ['items' => [], 'snapshot' => 'now', 'freshness' => 'live']);
    };
    $controller = new TestResourcesController($client, new FakeAccess([['namespace' => 'payments']]));
    $controller->permissions = ['kubernetes/resources/show'];
    $controller->params = new TestParams(['state' => 'problem']);
    $controller->indexAction();
    check($controller->view->error === null && $controller->view->title === 'Kubernetes Problems', 'Problem overview failed');
    echo "standalone controller integration tests: ok\n";
}
