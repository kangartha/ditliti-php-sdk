<?php

namespace Ditliti;

/**
 * Database query auto-instrumentation.
 *
 * Wraps a query execution with a `db.query` span carrying OpenTelemetry-
 * shaped database attributes. The backend (ingestion-api) parameterizes
 * and sanitizes `db.query.text` server-side and derives `db.query.summary`
 * / `db.operation.name` / `db.collection.name` / `ditliti.query_hash` from
 * it - callers here only need to supply the real SQL text, exactly like
 * every other span already sent by this SDK travels over the same trust
 * boundary to the user's own ingestion-api.
 *
 * This never changes the wrapped call's exception flow: span-recording
 * failures are swallowed, and the original exception (if any) is always
 * re-thrown unchanged.
 */
final class DbTracing
{
    public static function traceQuery(
        DitlitiClient $client,
        string $system,
        string $text,
        callable $execute,
        ?string $collection = null,
        ?string $operation = null,
        ?callable $rowCountResolver = null
    ): mixed {
        $tags = ['db.system.name' => $system, 'db.query.text' => $text];
        if ($operation !== null) {
            $tags['db.operation.name'] = $operation;
        }
        if ($collection !== null) {
            $tags['db.collection.name'] = $collection;
        }

        $span = $client->startSpan('db.query', $tags);
        try {
            $result = $execute();
            $rowCount = $rowCountResolver !== null ? $rowCountResolver($result) : self::extractRowCount($result);
            self::safeFinish($span, $rowCount !== null ? ['db.response.returned_rows' => (string) $rowCount] : []);
            return $result;
        } catch (\Throwable $exception) {
            self::safeFinish($span, ['error.type' => $exception::class]);
            throw $exception;
        }
    }

    private static function safeFinish(Span $span, array $extraTags): void
    {
        try {
            $span->finish($extraTags);
        } catch (\Throwable) {
            // Instrumentation must never break the caller's query path.
        }
    }

    private static function extractRowCount(mixed $result): ?int
    {
        if (is_int($result)) {
            return $result >= 0 ? $result : null; // PDO::exec returns false, not negative, on failure
        }
        if (is_array($result)) {
            return count($result);
        }
        return null;
    }

    public static function instrumentPdo(DitlitiClient $client, \PDO $pdo, string $system): InstrumentedPdo
    {
        return new InstrumentedPdo($client, $pdo, $system);
    }
}

/**
 * Wraps a `PDO` connection so `query()`/`exec()`/prepared statements are
 * traced as `db.query` spans. All other PDO methods pass through
 * unchanged via `__call`.
 */
final class InstrumentedPdo
{
    public function __construct(
        private readonly DitlitiClient $client,
        private readonly \PDO $pdo,
        private readonly string $system
    ) {
    }

    public function query(string $query, mixed ...$fetchArgs): \PDOStatement|false
    {
        return DbTracing::traceQuery(
            $this->client,
            $this->system,
            $query,
            fn () => $this->pdo->query($query, ...$fetchArgs)
        );
    }

    public function exec(string $statement): int|false
    {
        return DbTracing::traceQuery(
            $this->client,
            $this->system,
            $statement,
            fn () => $this->pdo->exec($statement)
        );
    }

    public function prepare(string $query, array $options = []): InstrumentedPdoStatement
    {
        return new InstrumentedPdoStatement($this->client, $this->pdo->prepare($query, $options), $this->system, $query);
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->pdo->$name(...$arguments);
    }
}

/**
 * Wraps a `PDOStatement` so `execute()` is traced as a `db.query` span,
 * using `rowCount()` (read after execution) for `db.response.returned_rows`.
 */
final class InstrumentedPdoStatement
{
    public function __construct(
        private readonly DitlitiClient $client,
        private readonly \PDOStatement $statement,
        private readonly string $system,
        private readonly string $queryText
    ) {
    }

    public function execute(?array $params = null): bool
    {
        return DbTracing::traceQuery(
            $this->client,
            $this->system,
            $this->queryText,
            fn () => $params !== null ? $this->statement->execute($params) : $this->statement->execute(),
            rowCountResolver: fn () => $this->statement->rowCount()
        );
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->statement->$name(...$arguments);
    }
}
