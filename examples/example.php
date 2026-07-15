<?php

require __DIR__ . '/../src/DitlitiClient.php';

use Ditliti\DitlitiClient;

[$endpoint, $apiKey, $projectId] = array_slice($argv, 1);

$client = new DitlitiClient($endpoint, $apiKey, $projectId, null, 'production');

$client->captureMessage('hello from the plain PHP SDK', 'info');
echo "captureMessage sent\n";

try {
    throw new RuntimeException('vanilla PHP crash test');
} catch (Throwable $e) {
    $client->captureException($e);
    echo "captureException sent\n";
}

$client->startTrace();
echo 'traceparent=' . $client->getTraceparent() . "\n";

$span = $client->startSpan('db.query', ['db' => 'mysql']);
usleep(5000);
$span->finish();
echo "span sent\n";
