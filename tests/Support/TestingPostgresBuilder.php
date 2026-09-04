<?php

namespace Tests\Support;

use Illuminate\Database\Schema\PostgresBuilder;

/**
 * Avoids Laravel's single-statement DROP for every table (~130+ locks), which
 * exceeds Postgres default max_locks_per_transaction during parallel test workers.
 *
 * Batches drops so each statement stays under typical limits (64) without
 * requiring a local Postgres restart.
 *
 * The chunk is 12, not 32: the drop is `DROP TABLE … CASCADE`, and cascading
 * through foreign keys takes locks on the *referenced* tables too, so a chunk
 * of 32 could blow past max_locks_per_transaction (64 by default) depending on
 * which tables happened to land in it. When that happened the wipe aborted
 * half-done and the run that followed migrated onto a partial schema —
 * surfacing as a couple of hundred "relation … does not exist" failures that
 * cleared on the next run and came back on the one after.
 */
class TestingPostgresBuilder extends PostgresBuilder
{
    private const DROP_CHUNK_SIZE = 12;

    public function dropAllTables(): void
    {
        $excludedTables = $this->connection->getConfig('dont_drop') ?? ['spatial_ref_sys'];

        $tables = [];

        foreach ($this->getTables($this->getCurrentSchemaListing()) as $table) {
            if (empty(array_intersect([$table['name'], $table['schema_qualified_name']], $excludedTables))) {
                $tables[] = $table['schema_qualified_name'];
            }
        }

        if ($tables === []) {
            return;
        }

        foreach (array_chunk($tables, self::DROP_CHUNK_SIZE) as $chunk) {
            // IF EXISTS: a concurrent/interrupted migrate:fresh can leave the
            // catalog half-dropped; Laravel's default DROP TABLE list then fails
            // on the first missing name and aborts the rest of the wipe.
            $sql = $this->grammar->compileDropAllTables($chunk);
            $sql = preg_replace('/^drop table /i', 'drop table if exists ', $sql) ?? $sql;
            $this->connection->statement($sql);
        }
    }

    public function dropAllViews(): void
    {
        $views = array_column($this->getViews($this->getCurrentSchemaListing()), 'schema_qualified_name');

        if ($views === []) {
            return;
        }

        foreach (array_chunk($views, self::DROP_CHUNK_SIZE) as $chunk) {
            $sql = $this->grammar->compileDropAllViews($chunk);
            $sql = preg_replace('/^drop view /i', 'drop view if exists ', $sql) ?? $sql;
            $this->connection->statement($sql);
        }
    }

    public function dropAllTypes(): void
    {
        $types = [];
        $domains = [];

        foreach ($this->getTypes($this->getCurrentSchemaListing()) as $type) {
            if (! $type['implicit']) {
                if ($type['type'] === 'domain') {
                    $domains[] = $type['schema_qualified_name'];
                } else {
                    $types[] = $type['schema_qualified_name'];
                }
            }
        }

        foreach (array_chunk($types, self::DROP_CHUNK_SIZE) as $chunk) {
            if ($chunk !== []) {
                $this->connection->statement($this->grammar->compileDropAllTypes($chunk));
            }
        }

        foreach (array_chunk($domains, self::DROP_CHUNK_SIZE) as $chunk) {
            if ($chunk !== []) {
                $this->connection->statement($this->grammar->compileDropAllDomains($chunk));
            }
        }
    }
}
