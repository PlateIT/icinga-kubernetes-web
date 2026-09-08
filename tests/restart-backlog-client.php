<?php

declare(strict_types=1);

namespace Icinga\Application {
    final class RestartTestConfig
    {
        public function get(string $section, string $key, mixed $default = null): mixed
        {
            return $default;
        }
    }

    final class Config
    {
        public static function module(string $name): RestartTestConfig
        {
            return new RestartTestConfig();
        }
    }
}

namespace {
    use Icinga\Module\Kubernetes\Api\Client;

    require dirname(__DIR__) . '/library/Kubernetes/Api/ApiException.php';
    require dirname(__DIR__) . '/library/Kubernetes/Api/Client.php';

    $port = $argv[1] ?? '';
    if (! ctype_digit($port)) {
        throw new RuntimeException('Test server port is required');
    }
    $client = new Client('http://127.0.0.1:' . $port, 'reader-token');
    $responses = $client->getMany(array_map(
        static fn(int $index): array => [
            'path' => 'resources',
            'query' => ['namespace' => 'restart-' . $index, 'delayMs' => 25]
        ],
        range(1, 4)
    ));
    if (count($responses) !== 4 || $client->getFreshness() !== 'live') {
        throw new RuntimeException('Cold-start API query returned an invalid result');
    }
    foreach ($responses as $index => $response) {
        if (($response['namespace'] ?? null) !== 'restart-' . ($index + 1)) {
            throw new RuntimeException('Cold-start API responses were reordered');
        }
    }
    echo "ok\n";
}
