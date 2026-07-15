# ditliti/php-sdk

Official **framework-agnostic** PHP SDK for [Ditliti](https://github.com/kangartha/inspectora) - error tracking, tracing, session replay, and profiling ingestion. Works in plain PHP, Symfony, WordPress, or anywhere else PHP runs - it has no dependency beyond the `curl` and `json` extensions that ship with virtually every PHP install, so it doesn't require Composer at all if you'd rather not use it (see "Without Composer" below).

Looking for Laravel-specific auto-registration via the framework's exception handler? See [`sdk/php-laravel`](../php-laravel) instead.

This SDK talks to a **self-hosted** Ditliti instance (`ingestion-api`). It does not connect to any managed/hosted service - point it at your own deployment's ingestion URL.

## Install

```bash
mkdir -p .ditliti/packages
curl -fsSL https://github.com/kangartha/inspectora/releases/download/sdk-v0.1.2/ditliti-php-0.1.2.zip -o .ditliti/packages/ditliti-php-sdk.zip
composer config repositories.ditliti artifact "$(pwd)/.ditliti/packages"
composer require ditliti/php-sdk:0.1.2
```

The artifact repository is the canonical fallback until Packagist confirms publication.

### Without Composer (e.g. a WordPress plugin)

Copy `src/DitlitiClient.php` into your project and `require` it directly - it declares the `Ditliti\DitlitiClient`, `Ditliti\TraceContext`, and `Ditliti\Span` classes with no other file dependencies:

```php
require_once __DIR__ . '/DitlitiClient.php';
use Ditliti\DitlitiClient;
```

## Quick start

```php
use Ditliti\DitlitiClient;

$client = new DitlitiClient(
    endpoint: 'http://localhost:8305',
    apiKey: 'mp_xxx',
    projectId: 'PROJECT_UUID',
    environment: 'production'
);

try {
    riskyOperation();
} catch (\Throwable $e) {
    $client->captureException($e, context: ['extra' => 'context']);
}
```

### WordPress

```php
// in your plugin's main file
require_once __DIR__ . '/vendor/ditliti/DitlitiClient.php';
$ditliti = new \Ditliti\DitlitiClient(DITLITI_ENDPOINT, DITLITI_API_KEY, DITLITI_PROJECT_ID);

set_exception_handler(function (\Throwable $e) use ($ditliti) {
    $ditliti->captureException($e, ['framework' => 'wordpress']);
});
```

### Symfony

```php
// src/EventSubscriber/DitlitiExceptionSubscriber.php
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class DitlitiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly \Ditliti\DitlitiClient $client) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onKernelException'];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $this->client->captureException($event->getThrowable(), ['framework' => 'symfony']);
    }
}
```

## API

- `new DitlitiClient(endpoint, apiKey, projectId, release: null, environment: null)`
- `$client->setUser(?array $user)` / `$client->setSession(?string $sessionId)` / `$client->setTimeout(float $seconds)`
- `$client->addBreadcrumb(message, category: null, level: 'info', data: [])`
- `$client->captureException(\Throwable $e, array $context = [], array $options = [])`
- `$client->captureMessage(string $message, string $level = 'info', array $context = [], array $tags = [])`
- `$client->captureReplaySegment(float $durationMs, array $events, array $options = [])`
- `$client->captureProfile(int $durationMs, array $samples, array $options = [])`
- `$client->sendEnvelope(array $envelope)` - low-level batch submit.

## Distributed tracing

Generates real W3C `traceparent` (https://www.w3.org/TR/trace-context/) trace context, so a trace can be propagated across service/microservice boundaries instead of staying siloed per project:

```php
// Service A: start a trace and call Service B, forwarding the header.
$client->startTrace();
$response = $httpClient->request('GET', $serviceBUrl, [
    'headers' => ['traceparent' => $client->getTraceparent()],
]);

// Service B: continue the same trace from the inbound header.
$client->startTrace($request->headers->get('traceparent'));

$span = $client->startSpan('db.query', ['db' => 'mysql']);
// ... do the work ...
$span->finish();

// captureException/captureMessage automatically tag trace_id when a trace is active,
// so errors show up correlated to the trace at GET /api/v1/traces/:trace_id (org-wide).
```

- `$client->startTrace(?string $incomingTraceparent = null): TraceContext` - starts a new trace, or continues one from an inbound `traceparent` header.
- `$client->getTraceparent(): ?string` - the header value to forward on outgoing requests.
- `$client->startSpan(string $operationName, array $tags = []): Span` → `$span->finish(array $extraTags = [])`.

## Database query instrumentation (Database Insights)

`Ditliti\DbTracing` wraps a query with a `db.query` span carrying the OpenTelemetry-shaped attributes the backend uses for Database Insights (slow query / N+1 / error-rate / regression detection). The server parameterizes and hashes `db.query.text` itself - send the real SQL text, never a redacted one:

```php
require_once __DIR__ . '/vendor/ditliti/php-sdk/src/Db.php'; // or rely on Composer autoload

use Ditliti\DbTracing;

// Generic wrapper - works for any driver:
$rows = DbTracing::traceQuery(
    $client,
    system: 'postgresql',
    text: 'SELECT * FROM orders WHERE id = ?',
    execute: fn () => $pdo->prepare('SELECT * FROM orders WHERE id = ?')->execute([$orderId]),
);

// Or wrap a PDO connection transparently:
$traced = DbTracing::instrumentPdo($client, $pdo, 'postgresql');
$rows = $traced->query('SELECT * FROM orders WHERE id = ?')->fetchAll();
$stmt = $traced->prepare('UPDATE orders SET status = ? WHERE id = ?');
$stmt->execute(['shipped', $orderId]);
```

Neither ever sets `db.query.summary` or `ditliti.query_hash` (server-computed). On failure they record `error.type` and re-throw the original exception unchanged; on success they record `db.response.returned_rows` from the driver's affected/row count where available.

## Transport behavior

- Uses PHP's `curl` extension directly - no HTTP client dependency to conflict with WordPress's bundled `Requests` library or a host Symfony app's own HTTP client version.
- All calls are best-effort: `curl_exec` failures are swallowed so reporting can never break the host application's response path. There is no retry/queue - if you need that, wrap calls yourself or batch via `sendEnvelope`.

## License

MIT - see [LICENSE](https://github.com/kangartha/inspectora/blob/main/LICENSE).
