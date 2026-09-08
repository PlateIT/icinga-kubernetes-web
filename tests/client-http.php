<?php

namespace Icinga\Application {
    final class TestConfig { public function get(string $section, string $key, $default = null) { return $default; } }
    final class Config { public static function module(string $name): TestConfig { return new TestConfig(); } }
}

namespace {
    use Icinga\Module\Kubernetes\Api\ApiException;
    use Icinga\Module\Kubernetes\Api\Client;

    require dirname(__DIR__) . '/library/Kubernetes/Api/ApiException.php';
    require dirname(__DIR__) . '/library/Kubernetes/Api/Client.php';

    if (! function_exists('curl_multi_init')) {
        echo "concurrent API client test: skipped (PHP cURL extension unavailable)\n";
        exit(0);
    }

    $port = $argv[1] ?? '';
    if (! ctype_digit($port)) {
        throw new RuntimeException('Test server port is required');
    }
    $client = new Client('http://127.0.0.1:' . $port, 'reader-token');
    $pages = $client->getMany([
        ['path' => 'resources', 'query' => ['namespace' => 'stale']],
        ['path' => 'resources', 'query' => ['namespace' => 'unavailable']]
    ]);
    if (($pages[0]['namespace'] ?? null) !== 'stale' || ($pages[1]['namespace'] ?? null) !== 'unavailable') {
        throw new RuntimeException('Concurrent API responses were not mapped to their requests');
    }
    if ($client->getFreshness() !== 'unavailable') {
        throw new RuntimeException('Concurrent API freshness must preserve the worst response');
    }
    try {
        (new Client('http://127.0.0.1:' . $port, 'reader-token'))->getMany([
            ['path' => 'resources', 'query' => ['namespace' => 'invalid-freshness']]
        ]);
        throw new RuntimeException('Invalid API freshness was accepted');
    } catch (ApiException $error) {
        if (! str_contains($error->getMessage(), 'invalid freshness header')) {
            throw $error;
        }
    }
    try {
        $client->getMany([['path' => 'resources', 'query' => ['namespace' => 'oversized']]]);
        throw new RuntimeException('Oversized concurrent response was accepted');
    } catch (ApiException $error) {
        if (! str_contains($error->getMessage(), 'exceeds 8 MiB')) {
            throw $error;
        }
    }
    echo "concurrent API client test: ok\n";
}
