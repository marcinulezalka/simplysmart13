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

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Simplysmart\Polygon\Auth\AccountColumns;
use Simplysmart\Polygon\Contracts\BlindIndex;
use Simplysmart\Polygon\Models\Organization;
use Simplysmart\Polygon\Models\OrganizationMember;
use Simplysmart\Polygon\Models\PolygonAccount;
use Simplysmart\Polygon\Models\User;
use Simplysmart\Polygon\Organizations\DefaultOrganization;
use Simplysmart\Polygon\Permissions\OrganizationRoles;
use Simplysmart\Polygon\Zones\PolygonZone;
use Tests\TestCase;

/**
 * Komenda `polygon:account:create` (akcja Auth\CreateAccount): konto strefy `polygon` w `polygon_accounts`
 * (nie w `users` frontendu), e-mail szyfrowany z `email_hash`, role guarda strefy w transakcji, duplikaty adresu.
 */
class CreateAccountCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_an_active_zone_account_without_pin_and_with_roles(): void
    {
        $this->role('owner', PolygonZone::GUARD);

        $this->command('polygon:account:create', ['email' => ' Jan@Example.com ', 'name' => 'Jan Kowalski', '--role' => ['owner']])
            ->expectsOutput('Konto założone. PIN właściciel konta ustawi przy pierwszym logowaniu.')
            ->assertSuccessful();

        $account = PolygonAccount::query()->sole();
        $this->assertSame('jan@example.com', $account->emailAddress());
        $this->assertSame($this->app->make(BlindIndex::class)->hash('jan@example.com'), $account->email_hash);
        $this->assertNotSame('jan@example.com', DB::table(PolygonAccount::TABLE)->value(AccountColumns::EMAIL), 'E-mail w bazie jest zaszyfrowany.');
        $this->assertTrue($account->is_active);
        $this->assertNull($account->pin_hash);
        $this->assertTrue($account->hasRole('owner'));
        $this->assertSame(0, User::query()->count(), 'Konta strefy nie trafiają do users frontendu.');
    }

    public function test_zone_guard_uses_the_accounts_table(): void
    {
        $this->assertSame(PolygonZone::PROVIDER, config('auth.guards.' . PolygonZone::GUARD . '.provider'));
        $this->assertSame(PolygonAccount::class, config('auth.providers.' . PolygonZone::PROVIDER . '.model'));
    }

    public function test_invalid_input_creates_nothing(): void
    {
        $this->command('polygon:account:create', ['email' => 'nie-adres', 'name' => 'Jan'])->assertFailed();

        $this->assertSame(0, PolygonAccount::query()->count());
    }

    public function test_missing_role_rolls_the_account_back(): void
    {
        $this->command('polygon:account:create', ['email' => 'jan@example.com', 'name' => 'Jan', '--role' => ['brak']])->assertFailed();

        $this->assertSame(0, PolygonAccount::withTrashed()->count());
    }

    public function test_an_account_joins_organizations_with_roles_in_each_and_gets_the_default_context(): void
    {
        $this->role('owner', PolygonZone::GUARD);
        $this->role('support', PolygonZone::GUARD);
        $clinic = $this->organization('Poradnia', ['json_attributes' => ['install_key' => 'poradnia']]);
        $desk = $this->organization('Recepcja', ['json_attributes' => ['install_key' => 'recepcja']]);

        $this->command('polygon:account:create', ['email' => 'anna@example.com', 'name' => 'Anna Nowak', '--organization' => ['poradnia=owner,support']])->assertSuccessful();
        $anna = PolygonAccount::query()->sole();
        $roles = $this->app->make(OrganizationRoles::class);
        $this->assertSame(['owner', 'support'], $this->sorted($roles->names($anna, $clinic->id)));
        $this->assertSame([], $roles->names($anna, null), 'Bez ról globalnych.');
        $this->assertTrue(OrganizationMember::query()->where(Organization::FOREIGN_KEY, $clinic->id)->exists(), 'Konto jest personelem organizacji.');
        $this->assertSame($clinic->id, $this->app->make(DefaultOrganization::class)->id($anna), 'Jedyna organizacja to domyślny kontekst.');

        $this->command('polygon:account:create', ['email' => 'ewa@example.com', 'name' => 'Ewa Lis', '--organization' => ['poradnia=owner', 'recepcja=support'], '--default-organization' => 'recepcja'])->assertSuccessful();
        $ewa = PolygonAccount::query()->get()->sole(static fn(PolygonAccount $account): bool => $account->emailAddress() === 'ewa@example.com');
        $this->assertSame(['support'], $roles->names($ewa, $desk->id));
        $this->assertSame($desk->id, $this->app->make(DefaultOrganization::class)->id($ewa));
    }

    public function test_an_unknown_organization_or_role_in_it_creates_nothing(): void
    {
        $this->organization('Poradnia', ['json_attributes' => ['install_key' => 'poradnia']]);

        $this->command('polygon:account:create', ['email' => 'jan@example.com', 'name' => 'Jan', '--organization' => ['brak=owner']])
            ->expectsOutput('Nie ma organizacji o kluczu brak — konto nie zostało założone.')
            ->assertFailed();
        $this->command('polygon:account:create', ['email' => 'jan@example.com', 'name' => 'Jan', '--organization' => ['poradnia=brak']])->assertFailed();
        $this->command('polygon:account:create', ['email' => 'jan@example.com', 'name' => 'Jan', '--organization' => ['poradnia'], '--default-organization' => 'inna'])->assertFailed();

        $this->assertSame(0, PolygonAccount::withTrashed()->count());
        $this->assertSame(0, OrganizationMember::query()->count());
    }

    public function test_taken_address_fails_or_is_skipped_on_request(): void
    {
        $arguments = ['email' => 'jan@example.com', 'name' => 'Jan'];
        $this->command('polygon:account:create', $arguments)->assertSuccessful();
        PolygonAccount::query()->sole()->delete();

        $this->command('polygon:account:create', ['email' => 'JAN@example.com', 'name' => 'Jan'])->expectsOutput('Konto z tym adresem e-mail już istnieje.')->assertFailed();
        $this->command('polygon:account:create', [...$arguments, '--skip-existing' => true])->assertSuccessful();
        $this->assertSame(1, PolygonAccount::withTrashed()->count());
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        sort($names);

        return $names;
    }
}
