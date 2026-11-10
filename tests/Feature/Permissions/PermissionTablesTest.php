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

namespace Tests\Feature\Permissions;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Simplysmart\Polygon\Database\History;
use Simplysmart\Polygon\Database\IdSlots;
use Simplysmart\Polygon\Models\Permissions\Permission;
use Simplysmart\Polygon\Models\Permissions\Role;
use Simplysmart\Polygon\Models\User;
use Simplysmart\Polygon\Permissions\PermissionTables;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tabele spatie/laravel-permission z migracji paczki simplysmart/polygon z zespołami (zespół = organizacja): modele paczki
 * z kolumnami standardowymi, historia każdej tabeli i nadawanie ról użytkownikowi.
 */
class PermissionTablesTest extends TestCase
{
    use RefreshDatabase;

    private const string GUARD = 'web';

    public function test_spatie_uses_the_polygon_models_with_organizations_as_teams(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertSame(Role::class, $registrar->getRoleClass());
        $this->assertSame(Permission::class, $registrar->getPermissionClass());
        $this->assertTrue($registrar->teams);
        $this->assertSame(PermissionTables::TEAM_FOREIGN_KEY, $registrar->teamsKey);

        foreach (PermissionTables::TEAM_TABLES as $table) {
            $this->assertTrue(Schema::hasColumn($table, PermissionTables::TEAM_FOREIGN_KEY), $table);
        }
    }

    public function test_every_permission_table_has_history(): void
    {
        foreach (PermissionTables::ALL as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertTrue(Schema::hasTable(History::tableFor($table)), History::tableFor($table));
        }
    }

    public function test_roles_and_permissions_get_standard_columns_and_user_ids(): void
    {
        $role = $this->role('editor', self::GUARD);
        $permission = $this->permission('articles.edit', self::GUARD);

        $this->assertSame(IdSlots::FIRST_USER_ID, $role->id);
        $this->assertSame(IdSlots::FIRST_USER_ID, $permission->id);
        $this->assertNotSame('', $role->token);
        $this->assertNotNull($role->checksum);
        $this->assertSame(self::GUARD, $role->guard_name);
    }

    public function test_user_gets_permissions_through_a_role(): void
    {
        $role = $this->role('editor', self::GUARD);
        $role->givePermissionTo($this->permission('articles.edit', self::GUARD));
        $user = User::factory()->create();

        $user->assignRole($role);

        $this->assertTrue($user->refresh()->hasPermissionTo('articles.edit'));
        $this->assertSame(1, DB::table(History::tableFor(PermissionTables::MODEL_HAS_ROLES))->where('action', 'insert')->count());
    }

}
