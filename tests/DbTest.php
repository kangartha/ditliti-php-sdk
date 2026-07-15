<?php

declare(strict_types=1);

require __DIR__ . '/../src/DitlitiClient.php';
require __DIR__ . '/../src/Db.php';

use Ditliti\DbTracing;
use Ditliti\DitlitiClient;

function check(string $name, bool $condition): void
{
    global $failures;
    echo ($condition ? "OK: " : "FAIL: ") . $name . "\n";
    if (!$condition) {
        $failures++;
    }
}

$failures = 0;

function capturingClient(array &$sent): DitlitiClient
{
    return new DitlitiClient(
        'http://127.0.0.1:1',
        'key',
        'project',
        'checkout@2.4.0',
        'production',
        function (string $path, array $payload, ?string $eventId) use (&$sent): void {
            $sent[] = ['path' => $path, 'payload' => $payload, 'eventId' => $eventId];
        }
    );
}

// --- traceQuery: sends real SQL text and row count -------------------------
$sent = [];
$client = capturingClient($sent);
$client->startTrace();
$result = DbTracing::traceQuery(
    $client,
    'postgresql',
    'SELECT * FROM orders WHERE id = $1',
    fn () => [['id' => 1], ['id' => 2]]
);
check('traceQuery returns the execute() result', count($result) === 2);
check('exactly one span sent', count($sent) === 1 && count($sent[0]['payload']['spans']) === 1);
$tags = $sent[0]['payload']['spans'][0]['tags'];
check('db.system.name is set', $tags['db.system.name'] === 'postgresql');
check('db.query.text carries the real SQL', $tags['db.query.text'] === 'SELECT * FROM orders WHERE id = $1');
check('db.response.returned_rows is set', $tags['db.response.returned_rows'] === '2');
check('environment is stamped', $tags['environment'] === 'production');
check('release is stamped', $tags['release'] === 'checkout@2.4.0');
check('runtime tag preserved', $tags['runtime'] === 'php');
check('no error.type on success', !array_key_exists('error.type', $tags));
check('db.query.summary never sent (server-computed)', !array_key_exists('db.query.summary', $tags));
check('ditliti.query_hash never sent (server-computed)', !array_key_exists('ditliti.query_hash', $tags));

// --- traceQuery: records error.type and re-throws original exception -------
$sent = [];
$client = capturingClient($sent);
$client->startTrace();
$thrown = null;
try {
    DbTracing::traceQuery(
        $client,
        'postgresql',
        'INSERT INTO orders (id) VALUES (1)',
        function (): void {
            throw new \RuntimeException('duplicate key value violates unique constraint');
        }
    );
} catch (\RuntimeException $exception) {
    $thrown = $exception;
}
check('original exception is re-thrown unchanged', $thrown !== null && $thrown->getMessage() === 'duplicate key value violates unique constraint');
$tags = $sent[0]['payload']['spans'][0]['tags'];
check('error.type is set to the exception class', $tags['error.type'] === \RuntimeException::class);
check('no returned_rows tag on failure', !array_key_exists('db.response.returned_rows', $tags));

// --- instrumentPdo: end-to-end against a real PDO SQLite connection --------
$sent = [];
$client = capturingClient($sent);
$client->startTrace();
$pdo = new \PDO('sqlite::memory:');
$traced = DbTracing::instrumentPdo($client, $pdo, 'sqlite');

$traced->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, total INTEGER)');
$insert = $traced->prepare('INSERT INTO orders (id, total) VALUES (:id, :total)');
$insert->execute(['id' => 1, 'total' => 4200]);
$insert2 = $traced->prepare('INSERT INTO orders (id, total) VALUES (:id, :total)');
$insert2->execute(['id' => 2, 'total' => 900]);
$update = $traced->prepare('UPDATE orders SET total = total + 1 WHERE total > :threshold');
$update->execute(['threshold' => 500]);
$rows = $traced->query('SELECT * FROM orders')->fetchAll(\PDO::FETCH_ASSOC);

check('real PDO/SQLite query returned 2 rows', count($rows) === 2);
check('5 spans sent (create+2 insert+update+select)', count($sent) === 5);

$updateTags = $sent[3]['payload']['spans'][0]['tags'];
check('update span has db.system.name=sqlite', $updateTags['db.system.name'] === 'sqlite');
check('update span reports 2 affected rows via rowCount()', $updateTags['db.response.returned_rows'] === '2');

$selectTags = $sent[4]['payload']['spans'][0]['tags'];
check('select span carries the real SQL text', str_starts_with($selectTags['db.query.text'], 'SELECT'));

echo "\n";
if ($failures > 0) {
    echo "$failures FAILURE(S)\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
