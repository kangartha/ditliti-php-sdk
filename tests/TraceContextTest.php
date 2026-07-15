<?php

declare(strict_types=1);

require __DIR__ . '/../src/DitlitiClient.php';

use Ditliti\DitlitiClient;
use Ditliti\TraceContext;

function check(string $name, bool $condition): void
{
    global $failures;
    echo ($condition ? "OK: " : "FAIL: ") . $name . "\n";
    if (!$condition) {
        $failures++;
    }
}

$failures = 0;

check('trace id is 32 lowercase hex', (bool) preg_match('/^[0-9a-f]{32}$/', TraceContext::generateTraceId()));
check('span id is 16 lowercase hex', (bool) preg_match('/^[0-9a-f]{16}$/', TraceContext::generateSpanId()));

$parsed = TraceContext::fromTraceparent('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
check(
    'parses valid traceparent',
    $parsed !== null
        && $parsed->traceId === '4bf92f3577b34da6a3ce929d0e0e4736'
        && $parsed->spanId === '00f067aa0ba902b7'
        && $parsed->sampled === true
);

check('rejects null', TraceContext::fromTraceparent(null) === null);
check('rejects empty string', TraceContext::fromTraceparent('') === null);
check('rejects garbage', TraceContext::fromTraceparent('garbage') === null);
check(
    'rejects all-zero',
    TraceContext::fromTraceparent('00-' . str_repeat('0', 32) . '-' . str_repeat('0', 16) . '-01') === null
);

$start = TraceContext::start();
$child = $start->child();
check('child keeps trace_id', $child->traceId === $start->traceId);
check('child gets a fresh span_id', $child->spanId !== $start->spanId);

check(
    'toTraceparent format round-trips',
    TraceContext::fromTraceparent($start->toTraceparent())?->traceId === $start->traceId
);

$client = new DitlitiClient('http://127.0.0.1:1', 'key', 'project');
check('getTraceparent is null before startTrace', $client->getTraceparent() === null);

$client->startTrace('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
check(
    'startTrace continues inbound header under same trace_id',
    str_starts_with($client->getTraceparent(), '00-4bf92f3577b34da6a3ce929d0e0e4736-')
);
check(
    'startTrace issues a fresh span_id, not the caller\'s',
    !str_contains($client->getTraceparent(), '00f067aa0ba902b7')
);

echo "\n";
if ($failures > 0) {
    echo "$failures FAILURE(S)\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
