<?php

declare(strict_types=1);

/**
 * This file is part of simplysmart/simplysmart13.
 *
 * Copyright (c) 2026 Marcin Ulezalka <marcin.ulezalka@simplysmart.pl>
 *
 * Licensed under the Simplysmart Restricted Source License 1.0.
 * See the LICENSE file distributed with this source code.
 */

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Simplysmart\Polygon\Contracts\ZoneRegistry;
use Simplysmart\Polygon\Database\History;
use Simplysmart\Polygon\Zones\ZoneTable;
use Tests\TestCase;

/**
 * Pełna historia (docs/baza-danych.md §4): każda tabela po migracjach ma `<tabela>_history` z triggerami zgodnymi
 * z jej kolumnami; bez historii są tylko tabele techniczne.
 */
class FullHistoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tabele techniczne bez historii: migracje, cache, kolejka i sesje; do tego jednorazowe dane logowania każdej strefy
     * (technicalTables()).
     *
     * @var list<string>
     */
    private const array TECHNICAL_TABLES = [
        'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions', 'password_reset_tokens',
    ];

    public function test_every_data_table_has_history_matching_its_columns(): void
    {
        $history = $this->app->make(History::class);
        $technical = $this->technicalTables();
        $tables = array_values(array_filter(
            Schema::getTableListing(schemaQualified: false),
            static fn(string $table): bool => ! str_ends_with($table, History::TABLE_SUFFIX) && ! in_array($table, $technical, true) && $table !== 'sqlite_sequence',
        ));

        $this->assertContains('users', $tables);

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable(History::tableFor($table)), "{$table} nie ma historii.");
            $this->assertSame(Schema::getColumnListing($table), $history->trackedColumns($table), $table);
        }
    }

    /**
     * Tabele techniczne aplikacji i tabele logowania (kody, sesje, zaufane urządzenia) każdej zarejestrowanej strefy
     * z kontami; konta strefy mają historię.
     *
     * @return list<string>
     */
    private function technicalTables(): array
    {
        $tables = self::TECHNICAL_TABLES;

        foreach ($this->app->make(ZoneRegistry::class)->all() as $zone) {
            if ($zone->model !== null) {
                foreach ([ZoneTable::LoginChallenges, ZoneTable::Sessions, ZoneTable::TrustedDevices] as $table) {
                    $tables[] = $table->for($zone->name);
                }
            }
        }

        return $tables;
    }
}
