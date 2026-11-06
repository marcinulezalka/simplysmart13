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
use Simplysmart\Polygon\Database\ColumnNames;
use Simplysmart\Polygon\Database\History;
use Simplysmart\Polygon\Database\IdSlots;
use Simplysmart\Polygon\Models\User;
use Tests\TestCase;

/**
 * Tabela `users` wyrównana migracją paczki simplysmart/polygon do kolumn standardowych, z historią i pulą id;
 * model użytkowników to Simplysmart\Polygon\Models\User z tokenem, checksumą i soft delete.
 */
class UsersTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_has_the_standard_columns(): void
    {
        $columns = Schema::getColumnListing('users');
        $standard = [
            ColumnNames::TOKEN, ColumnNames::PARENT_ID, ColumnNames::STATUS, ColumnNames::IS_ACTIVE, ColumnNames::IS_DEFAULT,
            ColumnNames::SORT_ORDER, ColumnNames::JSON_ATTRIBUTES, ColumnNames::JSON_DEFAULT_SETTINGS, ColumnNames::JSON_TAGS,
            ColumnNames::JSON_MIGRATIONS, ColumnNames::CHECKSUM, ColumnNames::JSON_ARCHIVE, ColumnNames::DELETED_AT,
        ];

        $this->assertSame([], array_values(array_diff($standard, $columns)));
        $this->assertSame($columns, $this->app->make(History::class)->trackedColumns('users'));
    }

    public function test_user_factory_gets_a_user_id_token_checksum_and_history(): void
    {
        $user = User::factory()->create();

        $this->assertSame(IdSlots::FIRST_USER_ID, $user->id);
        $this->assertSame($user->token, $user->getRouteKey());
        $this->assertSame($user->computeChecksum(), $user->checksum);
        $this->assertSame(1, DB::table(History::tableFor('users'))->where('row_id', $user->id)->count());
    }

    public function test_auth_uses_the_polygon_user_model(): void
    {
        $this->assertSame(User::class, config('auth.providers.users.model'));
    }

    public function test_deleted_user_is_kept(): void
    {
        $user = User::factory()->create();
        $user->delete();

        $this->assertSoftDeleted($user);
    }
}
