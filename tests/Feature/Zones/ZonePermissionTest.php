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

namespace Tests\Feature\Zones;

use Simplysmart\Polygon\Models\Permissions\Permission;
use Tests\Fixtures\Zones\AlphaAccount;
use Tests\Fixtures\Zones\BetaAccount;

/**
 * Trasa z uprawnieniem strefy (`ZonePermission::middleware()`): konto strefy z uprawnieniem w jej guardzie (przez rolę)
 * wchodzi, bez niego dostaje 403, a uprawnienie tej samej nazwy w guardzie innej strefy nie liczy się.
 */
class ZonePermissionTest extends ZoneTestCase
{
    public function test_account_with_the_zone_permission_through_a_role_opens_the_route(): void
    {
        $account = $this->account(AlphaAccount::class);
        $this->role('manager', 'alpha')->givePermissionTo($this->permission('reports.view', 'alpha'));
        $account->assignRole('manager');

        $this->signedIn($account, 'alpha')->get('/alpha/reports')->assertOk()->assertSee('reports');
    }

    public function test_account_without_the_permission_gets_403(): void
    {
        $this->permission('reports.view', 'alpha');

        $this->signedIn($this->account(AlphaAccount::class), 'alpha')->get('/alpha/reports')->assertForbidden();
    }

    public function test_unknown_permission_is_403_and_not_an_error(): void
    {
        $this->signedIn($this->account(AlphaAccount::class), 'alpha')->get('/alpha/reports')->assertForbidden();
    }

    public function test_permission_of_another_zone_guard_does_not_count(): void
    {
        $this->permission('reports.view', 'alpha');
        $beta = $this->account(BetaAccount::class);
        $this->role('manager', 'beta')->givePermissionTo($this->permission('reports.view', 'beta'));
        $beta->assignRole('manager');

        $this->signedIn($this->account(AlphaAccount::class, ['email' => 'ola@example.com']), 'alpha')->get('/alpha/reports')->assertForbidden();
    }

    private function permission(string $name, string $guard): Permission
    {
        $permission = new Permission();
        $permission->forceFill(['name' => $name, 'guard_name' => $guard])->save();

        return $permission;
    }
}
