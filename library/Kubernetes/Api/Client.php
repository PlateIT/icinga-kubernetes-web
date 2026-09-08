<?php

namespace Icinga\Module\Kubernetes\Api;

use Icinga\Application\Config;
use JsonException;

class Client
{
    private const MAX_JSON_BYTES = 8 * 1024 * 1024;
    private const MAX_CONCURRENT_JSON_BYTES = 32 * 1024 * 1024;
    private const MAX_TEXT_BYTES = 4 * 1024 * 1024;
    private const MAX_SSE_FRAME_BYTES = 1024 * 1024;

    private string $baseUrl;
    private string $token;
    private int $timeout;
    private string $freshness = 'live';
    private bool $invalidFreshness = false;

    public function __construct(?string $baseUrl = null, ?string $token = null)
    {
        $config = Config::module('kubernetes');
        $this->baseUrl = rtrim($baseUrl ?? getenv('ICINGA_KUBERNETES_API_URL') ?: $config->get('api', 'url', 'http://icinga-kubernetes-api:8080'), '/');
        $this->timeout = (int) $config->get('api', 'timeout', 15);
        $parts = parse_url($this->baseUrl);
        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new ApiException('Invalid Icinga Kubernetes API URL');
        }
        if ($this->timeout < 1 || $this->timeout > 60) {
            throw new ApiException('Icinga Kubernetes API timeout must be between 1 and 60 seconds');
        }
        $tokenFile = getenv('ICINGA_KUBERNETES_API_TOKEN_FILE') ?: $config->get('api', 'token_file');
        if ($token === null && $tokenFile) {
            $token = @file_get_contents($tokenFile);
            if ($token === false) {
                throw new ApiException(sprintf('Cannot read Kubernetes API token file %s', $tokenFile));
            }
        }
        $this->token = trim($token ?? '');
        if ($this->token === '') {
            throw new ApiException('No Kubernetes API module token is configured');
        }
    }

    public function getFreshness(): string
    {
        return $this->freshness;
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $this->url($path, $query));
    }

    /**
     * @param array<int, array{path: string, query?: array<string, mixed>}> $requests
     * @return array<int, array<string, mixed>>
     */
    public function getMany(array $requests): array
    {
        $this->requireCurl(true);
        if ($requests === [] || count($requests) > 16) {
            throw new ApiException('Concurrent Kubernetes API request count must be between 1 and 16');
        }
        $multi = curl_multi_init();
        if ($multi === false) {
            throw new ApiException('Cannot initialize concurrent Kubernetes API requests');
        }
        $handles = [];
        $responses = [];
        $tooLarge = [];
        $totalBytes = 0;
        $totalTooLarge = false;
        try {
            foreach (array_values($requests) as $index => $request) {
                if (! isset($request['path']) || ! is_string($request['path'])) {
                    throw new ApiException('Invalid concurrent Kubernetes API request');
                }
                $handle = curl_init($this->url($request['path'], $request['query'] ?? []));
                if ($handle === false) {
                    throw new ApiException('Cannot initialize Kubernetes API request');
                }
                $responses[$index] = '';
                $tooLarge[$index] = false;
                curl_setopt_array($handle, [
                    CURLOPT_NOPROXY => '*',
                    CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
                    CURLOPT_TIMEOUT => $this->timeout,
                    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $this->token],
                    CURLOPT_HEADERFUNCTION => function ($curl, string $header): int {
                        $this->readFreshnessHeader($header);
                        return strlen($header);
                    },
                    CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (
                        &$responses,
                        &$tooLarge,
                        &$totalBytes,
                        &$totalTooLarge,
                        $index
                    ): int {
                        if (strlen($responses[$index]) + strlen($chunk) > self::MAX_JSON_BYTES) {
                            $tooLarge[$index] = true;
                            return 0;
                        }
                        $totalBytes += strlen($chunk);
                        if ($totalBytes > self::MAX_CONCURRENT_JSON_BYTES) {
                            $totalTooLarge = true;
                            return 0;
                        }
                        $responses[$index] .= $chunk;
                        return strlen($chunk);
                    }
                ]);
                $handles[$index] = $handle;
                curl_multi_add_handle($multi, $handle);
            }
            do {
                $status = curl_multi_exec($multi, $running);
                if ($status !== CURLM_OK) {
                    throw new ApiException('Concurrent Kubernetes API request failed');
                }
                if ($running > 0 && curl_multi_select($multi, 1.0) === -1) {
                    usleep(1000);
                }
            } while ($running > 0);

            $decoded = [];
            if ($totalTooLarge) {
                throw new ApiException('Concurrent Kubernetes API responses exceed 32 MiB');
            }
            $this->assertFreshnessHeaders();
            foreach ($handles as $index => $handle) {
                $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                if ($tooLarge[$index]) {
                    throw new ApiException('Kubernetes API JSON response exceeds 8 MiB');
                }
                if (curl_errno($handle) !== 0 || $status < 200 || $status >= 300) {
                    throw new ApiException(sprintf('Kubernetes API request failed with HTTP %d', $status));
                }
                $decoded[$index] = $this->decodeJSON($responses[$index]);
            }
            return $decoded;
        } finally {
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);
        }
    }

    public function post(string $path, array $body): array
    {
        return $this->request('POST', $this->baseUrl . '/api/v1/' . ltrim($path, '/'), $body);
    }

    public function getText(string $path, array $query = []): string
    {
        $this->requireCurl();
        $url = $this->baseUrl . '/api/v1/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query(array_filter($query, static fn ($v) => $v !== null && $v !== ''));
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ApiException('Cannot initialize Kubernetes API request');
        }
        $response = '';
        $tooLarge = false;
        curl_setopt_array($ch, [
            CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Accept: text/plain', 'Authorization: Bearer ' . $this->token],
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$response, &$tooLarge): int {
                if (strlen($response) + strlen($chunk) > self::MAX_TEXT_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            }
        ]);
        $successful = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($tooLarge) {
            throw new ApiException('Kubernetes API text response exceeds 4 MiB');
        }
        if ($successful === false || $status < 200 || $status >= 300) {
            throw new ApiException(sprintf('Kubernetes API request failed with HTTP %d', $status));
        }
        return $response;
    }

    public function streamEvents(?string $lastEventId, callable $write, int $seconds = 25): void
    {
        $this->requireCurl();
        $headers = ['Accept: text/event-stream', 'Authorization: Bearer ' . $this->token];
        if ($lastEventId !== null && $lastEventId !== '') {
            if (! ctype_digit($lastEventId) || strlen($lastEventId) > 20) {
                throw new ApiException('Invalid event stream cursor');
            }
            $headers[] = 'Last-Event-ID: ' . $lastEventId;
        }
        $seconds = min(60, max(5, $seconds));
        $ch = curl_init($this->baseUrl . '/api/v1/events/stream');
        if ($ch === false) {
            throw new ApiException('Cannot initialize Kubernetes event stream');
        }
        $buffer = '';
        $tooLarge = false;
        $pendingId = null;
        $lastForwardedAt = 0.0;
        curl_setopt_array($ch, [
            CURLOPT_NOPROXY => '*',
            CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => $seconds, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (
                $write,
                &$buffer,
                &$tooLarge,
                &$pendingId,
                &$lastForwardedAt
            ): int {
                $buffer .= $chunk;
                $lastId = null;
                while (preg_match('/(?:\r\n|\r|\n){2}/', $buffer, $delimiter, PREG_OFFSET_CAPTURE)) {
                    $end = $delimiter[0][1];
                    $frame = str_replace(["\r\n", "\r"], "\n", substr($buffer, 0, $end));
                    $buffer = substr($buffer, $end + strlen($delimiter[0][0]));
                    if (preg_match('/(?:^|\n)id:\s*([0-9]{1,20})(?:\n|$)/', $frame, $match)) {
                        $lastId = $match[1];
                    }
                }
                if (strlen($buffer) > self::MAX_SSE_FRAME_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                if ($lastId !== null) {
                    $pendingId = $lastId;
                }
                if ($pendingId !== null && microtime(true) - $lastForwardedAt >= 1.0) {
                    // Collapse a burst to its latest sequence and never expose
                    // the trusted API's resource payload to the browser.
                    $write("id: $pendingId\nevent: resource\ndata: {}\n\n");
                    $pendingId = null;
                    $lastForwardedAt = microtime(true);
                }
                return strlen($chunk);
            }
        ]);
        $successful = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($pendingId !== null) {
            $write("id: $pendingId\nevent: resource\ndata: {}\n\n");
        }
        if ($tooLarge) {
            throw new ApiException('Kubernetes event stream frame exceeds 1 MiB');
        }
        if (($successful === false || $status !== 200) && $errno !== CURLE_OPERATION_TIMEDOUT) {
            throw new ApiException(sprintf('Kubernetes event stream failed with HTTP %d', $status));
        }
    }

    private function request(string $method, string $url, ?array $body = null): array
    {
        $this->requireCurl();
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ApiException('Cannot initialize Kubernetes API request');
        }
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $this->token];
        $response = '';
        $tooLarge = false;
        curl_setopt_array($ch, [
            CURLOPT_NOPROXY => '*',
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($curl, string $header): int {
                $this->readFreshnessHeader($header);
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$response, &$tooLarge): int {
                if (strlen($response) + strlen($chunk) > self::MAX_JSON_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            }
        ]);
        if ($body !== null) {
            $encoded = json_encode($body, JSON_THROW_ON_ERROR);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/json']));
        }
        $successful = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($tooLarge) {
            throw new ApiException('Kubernetes API JSON response exceeds 8 MiB');
        }
        $this->assertFreshnessHeaders();
        if ($successful === false || $status < 200 || $status >= 300) {
            throw new ApiException(sprintf('Kubernetes API request failed with HTTP %d', $status));
        }
        return $this->decodeJSON($response);
    }

    private function url(string $path, array $query = []): string
    {
        $url = $this->baseUrl . '/api/v1/' . ltrim($path, '/');
        $filtered = array_filter($query, static fn ($value) => $value !== null && $value !== '');
        if ($filtered !== []) {
            $url .= '?' . http_build_query($filtered);
        }
        return $url;
    }

    private function readFreshnessHeader(string $header): void
    {
        if (stripos($header, 'X-Icinga-Freshness:') !== 0) {
            return;
        }
        $freshness = trim(substr($header, strlen('X-Icinga-Freshness:')));
        $rank = ['live' => 0, 'stale' => 1, 'unavailable' => 2];
        if (! isset($rank[$freshness])) {
            $this->invalidFreshness = true;
            return;
        }
        if (isset($rank[$freshness]) && $rank[$freshness] > $rank[$this->freshness]) {
            $this->freshness = $freshness;
        }
    }

    private function assertFreshnessHeaders(): void
    {
        if ($this->invalidFreshness) {
            throw new ApiException('Kubernetes API returned an invalid freshness header');
        }
    }

    private function decodeJSON(string $response): array
    {
        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $_) {
            throw new ApiException('Kubernetes API returned invalid JSON');
        }
        if (! is_array($decoded)) {
            throw new ApiException('Kubernetes API returned an invalid response');
        }
        return $decoded;
    }

    private function requireCurl(bool $multi = false): void
    {
        if (! function_exists('curl_init') || ($multi && ! function_exists('curl_multi_init'))) {
            throw new ApiException('The PHP cURL extension is required by Icinga Kubernetes Web');
        }
    }
}
