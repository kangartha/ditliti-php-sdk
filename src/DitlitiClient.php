<?php

namespace Ditliti;

/**
 * A W3C trace-context (https://www.w3.org/TR/trace-context/) carried across
 * service boundaries via the `traceparent` header, so a trace started in
 * one process is continued - not restarted - in the next.
 */
final class TraceContext
{
    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly bool $sampled = true
    ) {
    }

    public static function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public static function start(): self
    {
        return new self(self::generateTraceId(), self::generateSpanId());
    }

    public static function fromTraceparent(?string $header): ?self
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $parts = explode('-', trim($header));
        if (count($parts) !== 4) {
            return null;
        }

        [, $traceId, $spanId, $flags] = $parts;
        if (!preg_match('/^[0-9a-f]{32}$/', $traceId) || $traceId === str_repeat('0', 32)) {
            return null;
        }
        if (!preg_match('/^[0-9a-f]{16}$/', $spanId) || $spanId === str_repeat('0', 16)) {
            return null;
        }
        if (!preg_match('/^[0-9a-f]{2}$/', $flags)) {
            return null;
        }

        return new self($traceId, $spanId, (hexdec($flags) & 1) === 1);
    }

    public function child(): self
    {
        return new self($this->traceId, self::generateSpanId(), $this->sampled);
    }

    public function toTraceparent(): string
    {
        return sprintf('00-%s-%s-%s', $this->traceId, $this->spanId, $this->sampled ? '01' : '00');
    }
}

/**
 * Returned by DitlitiClient::startSpan(); call finish() when the operation
 * completes to record and send the span.
 */
final class Span
{
    private readonly float $startedAtMicrotime;

    public function __construct(
        private readonly DitlitiClient $client,
        private readonly string $operationName,
        private readonly ?string $parentSpanId,
        private readonly TraceContext $ctx,
        private readonly array $tags
    ) {
        $this->startedAtMicrotime = microtime(true);
    }

    public function getTraceId(): string
    {
        return $this->ctx->traceId;
    }

    public function getSpanId(): string
    {
        return $this->ctx->spanId;
    }

    public function getTraceparent(): string
    {
        return $this->ctx->toTraceparent();
    }

    public function finish(array $extraTags = []): void
    {
        $durationMs = (microtime(true) - $this->startedAtMicrotime) * 1000;
        $this->client->recordSpan(
            $this->ctx->traceId,
            $this->ctx->spanId,
            $this->parentSpanId,
            $this->operationName,
            $durationMs,
            array_merge($this->tags, $extraTags)
        );
    }
}

/**
 * Dependency-free client for the Ditliti ingestion API - works in plain
 * PHP, Symfony, WordPress, or any other PHP codebase without requiring
 * Composer/Guzzle. Uses PHP's built-in `curl` extension directly, which
 * ships with virtually every PHP install (including WordPress hosting).
 *
 * For a Laravel-specific integration (auto-registered exception reporting
 * via the framework's exception handler), see `sdk/php-laravel` instead -
 * this package is the one to reach for everywhere else.
 */
class DitlitiClient
{
    private array $breadcrumbs = [];
    private ?array $user = null;
    private ?string $sessionId = null;
    private ?TraceContext $trace = null;
    private float $timeoutSeconds = 1.5;

    /** @var (callable(string, array, ?string): void)|null */
    private $transport;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $apiKey,
        private readonly string $projectId,
        private readonly ?string $release = null,
        private readonly ?string $environment = null,
        ?callable $transport = null
    ) {
        $this->transport = $transport;
    }

    public function setTimeout(float $seconds): void
    {
        $this->timeoutSeconds = $seconds;
    }

    public function setUser(?array $user): void
    {
        $this->user = $user;
    }

    public function setSession(?string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function addBreadcrumb(string $message, ?string $category = null, string $level = 'info', array $data = []): void
    {
        $this->breadcrumbs[] = [
            'timestamp' => gmdate('c'),
            'category' => $category,
            'message' => $message,
            'level' => $level,
            'data' => $data,
        ];
        if (count($this->breadcrumbs) > 100) {
            array_shift($this->breadcrumbs);
        }
    }

    /**
     * Start (or continue, if an inbound `traceparent` header is given) the
     * active trace. Downstream calls should read getTraceparent() and
     * forward it as the `traceparent` header of any outgoing HTTP request
     * to propagate the trace into the next service.
     */
    public function startTrace(?string $incomingTraceparent = null): TraceContext
    {
        $parent = TraceContext::fromTraceparent($incomingTraceparent);
        $this->trace = $parent !== null ? $parent->child() : TraceContext::start();
        return $this->trace;
    }

    public function getTraceparent(): ?string
    {
        return $this->trace?->toTraceparent();
    }

    /** Start a child span under the active trace (starting one implicitly if none is active yet). */
    public function startSpan(string $operationName, array $tags = []): Span
    {
        $trace = $this->trace ?? $this->startTrace();
        $parentSpanId = $trace->spanId;
        $ctx = $trace->child();

        return new Span($this, $operationName, $parentSpanId, $ctx, array_merge(['runtime' => 'php'], $tags));
    }

    public function recordSpan(string $traceId, string $spanId, ?string $parentSpanId, string $operationName, float $durationMs, array $tags = []): void
    {
        $this->recordSpans([[
            'traceId' => $traceId,
            'spanId' => $spanId,
            'parentSpanId' => $parentSpanId,
            'operationName' => $operationName,
            'durationMs' => $durationMs,
            'tags' => $tags,
        ]]);
    }

    /**
     * Batch variant of recordSpan(): records any number of already-finished
     * spans in a single envelope/HTTP request instead of one request per
     * span. Useful for callers that accumulate several spans over the
     * course of a request (e.g. database query tracing) and want to flush
     * them all at once rather than serially.
     *
     * @param array<int, array{traceId:string,spanId:string,parentSpanId:?string,operationName:string,durationMs:float,tags?:array}> $spans
     */
    public function recordSpans(array $spans): void
    {
        if ($spans === []) {
            return;
        }

        $baseTags = ['runtime' => 'php'];
        if ($this->environment !== null) {
            $baseTags['environment'] = $this->environment;
        }
        if ($this->release !== null) {
            $baseTags['release'] = $this->release;
        }

        $this->sendEnvelope([
            'spans' => array_map(
                fn (array $span): array => [
                    'trace_id' => $span['traceId'],
                    'span_id' => $span['spanId'],
                    'parent_span_id' => $span['parentSpanId'] ?? null,
                    'project_id' => $this->projectId,
                    'operation_name' => $span['operationName'],
                    'start_time' => gmdate('c'),
                    'duration_ms' => $span['durationMs'],
                    'tags' => array_merge($baseTags, $span['tags'] ?? []),
                ],
                $spans
            ),
        ]);
    }

    private function currentTraceTags(): array
    {
        return $this->trace !== null ? ['trace_id' => $this->trace->traceId] : [];
    }

    public function captureException(\Throwable $exception, array $context = [], array $options = []): void
    {
        $payload = [
            'project_id' => $this->projectId,
            'level' => 'error',
            'message' => $exception->getMessage(),
            'exception_type' => $exception::class,
            'stack_trace' => $this->stackFrames($exception),
            'tags' => array_merge(['runtime' => 'php'], $this->currentTraceTags(), $options['tags'] ?? []),
            'context' => $context,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'release' => $options['release'] ?? $this->release,
            'environment' => $options['environment'] ?? $this->environment,
            'transaction' => $options['transaction'] ?? null,
            'session_id' => $options['session_id'] ?? $this->sessionId,
            'breadcrumbs' => array_merge($this->breadcrumbs, $options['breadcrumbs'] ?? []),
            'user' => $options['user'] ?? $this->user,
        ];

        $this->post('/api/v1/ingest', $payload, $this->eventId($exception));
    }

    public function captureMessage(string $message, string $level = 'info', array $context = [], array $tags = []): void
    {
        $payload = [
            'project_id' => $this->projectId,
            'level' => $level,
            'message' => $message,
            'exception_type' => null,
            'stack_trace' => [],
            'tags' => array_merge(['runtime' => 'php'], $this->currentTraceTags(), $tags),
            'context' => $context,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'release' => $this->release,
            'environment' => $this->environment,
            'transaction' => null,
            'session_id' => $this->sessionId,
            'breadcrumbs' => $this->breadcrumbs,
            'user' => $this->user,
        ];

        $this->post('/api/v1/ingest', $payload, hash('sha256', $this->projectId . '|' . $message));
    }

    public function sendEnvelope(array $envelope): void
    {
        $payload = [
            'project_id' => $this->projectId,
            'events' => $envelope['events'] ?? [],
            'spans' => $envelope['spans'] ?? [],
            'logs' => $envelope['logs'] ?? [],
            'replays' => $envelope['replays'] ?? [],
            'profiles' => $envelope['profiles'] ?? [],
        ];

        $this->post('/api/v1/envelope', $payload, null);
    }

    public function captureReplaySegment(float $durationMs, array $events, array $options = []): void
    {
        $sessionId = $options['session_id'] ?? $this->sessionId ?? null;
        if ($sessionId === null || $sessionId === '') {
            return;
        }

        $this->sendEnvelope([
            'replays' => [[
                'project_id' => $this->projectId,
                'session_id' => $sessionId,
                'started_at' => $options['started_at'] ?? gmdate('c'),
                'duration_ms' => $durationMs,
                'segment_index' => $options['segment_index'] ?? 0,
                'events' => $events,
                'tags' => array_merge(['runtime' => 'php'], $options['tags'] ?? []),
                'user' => $options['user'] ?? $this->user,
                'release' => $options['release'] ?? $this->release,
                'environment' => $options['environment'] ?? $this->environment,
            ]],
        ]);
    }

    public function captureProfile(int $durationMs, array $samples, array $options = []): void
    {
        $this->sendEnvelope([
            'profiles' => [[
                'project_id' => $this->projectId,
                'trace_id' => $options['trace_id'] ?? $this->trace?->traceId,
                'transaction' => $options['transaction'] ?? null,
                'started_at' => $options['started_at'] ?? gmdate('c'),
                'duration_ms' => $durationMs,
                'platform' => 'php',
                'samples' => $samples,
                'tags' => array_merge(['runtime' => 'php'], $options['tags'] ?? []),
                'release' => $options['release'] ?? $this->release,
                'environment' => $options['environment'] ?? $this->environment,
            ]],
        ]);
    }

    private function eventId(\Throwable $exception): string
    {
        return hash('sha256', implode('|', [
            $this->projectId,
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            (string) $exception->getLine(),
        ]));
    }

    private function stackFrames(\Throwable $exception): array
    {
        return array_map(
            static fn (array $frame): array => [
                'function' => $frame['function'] ?? null,
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'column' => null,
            ],
            $exception->getTrace()
        );
    }

    private function post(string $path, array $payload, ?string $eventId): void
    {
        if ($this->transport !== null) {
            ($this->transport)($path, $payload, $eventId);
            return;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return;
        }

        $headers = ['content-type: application/json', 'x-api-key: ' . $this->apiKey];
        if ($eventId !== null) {
            $headers[] = 'x-event-id: ' . $eventId;
        }

        $ch = curl_init(rtrim($this->endpoint, '/') . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) ($this->timeoutSeconds * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->timeoutSeconds * 1000),
        ]);
        try {
            curl_exec($ch);
        } catch (\Throwable) {
            // Reporting must never break the host application's response path.
        } finally {
            curl_close($ch);
        }
    }
}
