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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Simplysmart\Polygon\Auth\AccountColumns;
use Simplysmart\Polygon\Database\ColumnNames;
use Simplysmart\Polygon\Database\History;
use Simplysmart\Polygon\Database\IdSlots;
use Simplysmart\Polygon\Database\ZoneTables;
use Simplysmart\Polygon\Zones\ZoneTable;
use Tests\TestCase;

/**
 * Tabele strefy `polygon` z migracji paczki simplysmart/polygon (ZoneTables): konta z kolumnami standardowymi,
 * historią i pulą id, kody logowania, sesje i zaufane urządzenia.
 */
class PolygonZoneTablesTest extends TestCase
{
    use RefreshDatabase;

    private const string ZONE = 'polygon';

    public function test_migration_creates_every_zone_table(): void
    {
        foreach (ZoneTable::cases() as $table) {
            $this->assertTrue(Schema::hasTable($table->for(self::ZONE)), $table->for(self::ZONE));
        }
    }

    public function test_accounts_frame_sign_in_columns_with_standard_columns(): void
    {
        $columns = Schema::getColumnListing(ZoneTable::Accounts->for(self::ZONE));

        $this->assertSame([ColumnNames::ID, ColumnNames::TOKEN], array_slice($columns, 0, 2));
        $this->assertSame(ColumnNames::DELETED_AT, end($columns));
        $this->assertContains(AccountColumns::EMAIL_HASH, $columns);
        $this->assertContains(AccountColumns::RECOVERY_EMAIL, $columns);
    }

    public function test_new_account_gets_a_user_id_and_history(): void
    {
        $accounts = ZoneTable::Accounts->for(self::ZONE);

        $id = DB::table($accounts)->insertGetId([
            ColumnNames::TOKEN => str_repeat('a', 64),
            ColumnNames::NAME => 'Jan Kowalski',
            AccountColumns::EMAIL => 'zaszyfrowany',
            AccountColumns::EMAIL_HASH => str_repeat('b', 64),
        ]);

        $this->assertSame(IdSlots::FIRST_USER_ID, $id);
        $this->assertSame(1, DB::table(History::tableFor($accounts))->where('row_id', $id)->where('action', 'insert')->count());
    }

    public function test_drop_removes_the_zone_tables_and_the_accounts_history(): void
    {
        $this->app->make(ZoneTables::class)->drop(self::ZONE);

        foreach (ZoneTable::cases() as $table) {
            $this->assertFalse(Schema::hasTable($table->for(self::ZONE)), $table->for(self::ZONE));
        }

        $this->assertFalse(Schema::hasTable(History::tableFor(ZoneTable::Accounts->for(self::ZONE))));
    }
}
