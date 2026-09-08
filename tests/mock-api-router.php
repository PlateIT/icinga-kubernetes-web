<?php

$namespace = $_GET['namespace'] ?? '';
$delayMs = filter_var($_GET['delayMs'] ?? 0, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 0, 'max_range' => 250]
]);
if ($delayMs === false) {
    http_response_code(400);
    echo '{"error":"invalid delay"}';
    return;
}
if ($delayMs > 0) {
    usleep($delayMs * 1000);
}
header('Content-Type: application/json');
if ($namespace === 'oversized') {
    echo '{"payload":"' . str_repeat('x', 8 * 1024 * 1024) . '"}';
    return;
}
if ($namespace === 'stale') {
    header('X-Icinga-Freshness: stale');
} elseif ($namespace === 'unavailable') {
    header('X-Icinga-Freshness: unavailable');
} elseif ($namespace === 'invalid-freshness') {
    header('X-Icinga-Freshness: fresh-enough');
} else {
    header('X-Icinga-Freshness: live');
}
echo json_encode(['items' => [], 'snapshot' => '2026-01-01T00:00:00Z', 'namespace' => $namespace], JSON_THROW_ON_ERROR);
