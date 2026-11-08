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

use Simplysmart\Polygon\Database\Seeders\HeroOrganizationSeeder;
use LogicException;
use Simplysmart\Polygon\Models\Organization;
use Simplysmart\Polygon\Models\Permissions\Permission;
use Simplysmart\Polygon\Organizations\OrganizationMembership;
use Simplysmart\Polygon\Permissions\OrganizationRoles;
use Tests\Fixtures\Zones\AlphaAccount;
use Tests\Fixtures\Zones\BetaAccount;

/**
 * Trasa z uprawnieniem strefy (`ZonePermission::middleware()`): konto strefy z uprawnieniem w jej guardzie (przez rolę)
 * wchodzi (przy kilku uprawnieniach trasy wystarczy dowolne), bez niego dostaje 403, a uprawnienie tej samej nazwy w guardzie innej strefy nie liczy się. Rola nadana
 * w organizacji działa tylko w jej kontekście (zespół spatie = bieżąca organizacja), a hero pracuje na rolach globalnych
 * w każdej organizacji.
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

    public function test_a_route_with_several_permissions_opens_with_any_of_them(): void
    {
        $this->permission('desk.manage', 'alpha');
        $account = $this->account(AlphaAccount::class);
        $this->role('doctor', 'alpha')->givePermissionTo($this->permission('desk.admit', 'alpha'));

        $this->signedIn($account, 'alpha')->get('/alpha/desk')->assertForbidden();

        $account->assignRole('doctor');
        $this->get('/alpha/desk')->assertOk()->assertSee('desk');
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

    public function test_a_role_counts_only_in_the_organization_it_was_given_in(): void
    {
        $account = $this->account(AlphaAccount::class);
        $north = $this->organization('Północ');
        $south = $this->organization('Południe');
        $this->role('owner', 'alpha')->givePermissionTo($this->permission('reports.view', 'alpha'));
        $this->role('doctor', 'alpha');
        $membership = $this->app->make(OrganizationMembership::class);
        $roles = $this->app->make(OrganizationRoles::class);
        $membership->add($account, $north);
        $membership->add($account, $south);
        $roles->assign($account, ['owner'], $north->id);
        $roles->assign($account, ['doctor'], $south->id);

        $this->signedIn($account, 'alpha')->get('/alpha/reports')->assertOk();

        $this->post('/alpha/account/organization', ['organization' => $south->token])->assertRedirect(route('alpha.dashboard'));
        $this->get('/alpha/reports')->assertForbidden();
        $this->assertSame(['owner'], $roles->names($account, $north->id));
        $this->assertSame(['doctor'], $roles->names($account, $south->id));
        $this->assertSame([], $roles->names($account, null), 'Role organizacji nie są globalne.');
    }

    public function test_role_holders_are_the_active_accounts_with_the_role_in_that_organization(): void
    {
        $north = $this->organization('Północ');
        $south = $this->organization('Południe');
        $this->role('doctor', 'alpha');
        $roles = $this->app->make(OrganizationRoles::class);
        $anna = $this->account(AlphaAccount::class, ['name' => 'Anna', 'email' => 'anna@example.com']);
        $roles->assign($anna, ['doctor'], $north->id);
        $roles->assign($this->account(AlphaAccount::class, ['name' => 'Bartek', 'email' => 'bartek@example.com']), ['doctor'], $south->id);
        $roles->assign($this->account(AlphaAccount::class, ['name' => 'Globalny', 'email' => 'global@example.com']), ['doctor'], null);
        $roles->assign($this->account(AlphaAccount::class, ['name' => 'Nieaktywny', 'email' => 'off@example.com', 'is_active' => false]), ['doctor'], $north->id);

        $this->assertSame([$anna->id], $roles->holders(AlphaAccount::class, 'doctor', $north->id)->pluck('id')->all());
        $this->assertSame([], $roles->holders(AlphaAccount::class, 'owner', $north->id)->all());
    }

    public function test_hero_keeps_its_global_roles_in_every_organization(): void
    {
        $this->seed(HeroOrganizationSeeder::class);
        $account = $this->account(AlphaAccount::class);
        $this->app->make(OrganizationMembership::class)->add($account, Organization::query()->findOrFail(Organization::HERO_ID));
        $clinic = $this->organization('Przychodnia');
        $this->role('hero', 'alpha')->givePermissionTo($this->permission('reports.view', 'alpha'));
        $account->assignRole('hero');

        $this->signedIn($account, 'alpha')->get('/alpha/reports')->assertOk();

        $this->post('/alpha/account/organization', ['organization' => $clinic->token])->assertRedirect(route('alpha.dashboard'));
        $this->get('/alpha/reports')->assertOk();
    }

    public function test_roles_in_an_unsaved_organization_are_refused_instead_of_becoming_global(): void
    {
        $account = $this->account(AlphaAccount::class);
        $this->role('owner', 'alpha');
        $roles = $this->app->make(OrganizationRoles::class);
        $unsaved = new Organization();
        $unsaved->name = 'Bez zapisu';

        try {
            $roles->assignIn($account, ['owner'], $unsaved);
            $this->fail('Role w niezapisanej organizacji muszą być odrzucone.');
        } catch (LogicException) {
            $this->assertSame([], $roles->names($account, null), 'Role nie trafiają do ról globalnych.');
        }
    }

    private function permission(string $name, string $guard): Permission
    {
        $permission = new Permission();
        $permission->forceFill(['name' => $name, 'guard_name' => $guard])->save();

        return $permission;
    }
}
