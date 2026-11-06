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
use Simplysmart\Polygon\Models\PolygonAccount;
use Simplysmart\Polygon\Models\User;
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

    public function test_taken_address_fails_or_is_skipped_on_request(): void
    {
        $arguments = ['email' => 'jan@example.com', 'name' => 'Jan'];
        $this->command('polygon:account:create', $arguments)->assertSuccessful();
        PolygonAccount::query()->sole()->delete();

        $this->command('polygon:account:create', ['email' => 'JAN@example.com', 'name' => 'Jan'])->expectsOutput('Konto z tym adresem e-mail już istnieje.')->assertFailed();
        $this->command('polygon:account:create', [...$arguments, '--skip-existing' => true])->assertSuccessful();
        $this->assertSame(1, PolygonAccount::withTrashed()->count());
    }


}
