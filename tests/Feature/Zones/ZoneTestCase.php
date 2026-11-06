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

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\ViewErrorBag;
use Simplysmart\Polygon\Auth\SignIn\CompleteSignIn;
use Simplysmart\Polygon\Contracts\ZoneRegistry;
use Simplysmart\Polygon\Database\ZoneTables;
use Simplysmart\Polygon\Models\ZoneAccount;
use Simplysmart\Polygon\Zones\ZoneSessionConfigurator;
use Simplysmart\Polygon\Zones\ZoneSessionManager;
use Tests\Fixtures\Zones\AlphaZoneServiceProvider;
use Tests\Fixtures\Zones\BetaZoneServiceProvider;
use Tests\Fixtures\Zones\OpenZoneServiceProvider;
use Tests\TestCase;

/**
 * Baza testów stref: rejestruje strefy testowe alpha i beta (z logowaniem i widokami fikstur) oraz open (publiczną)
 * i tworzy tabele stref z logowaniem schematem polygon (Database\ZoneTables, SQLite w pamięci).
 *
 * Strefy alpha, beta i open istnieją wyłącznie w testach.
 */
abstract class ZoneTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['alpha', 'beta'] as $zone) {
            $this->app->make(ZoneTables::class)->create($zone);
        }

        foreach ($this->zoneProviders() as $provider) {
            $this->registerZone($provider);
        }
    }

    /**
     * Providery stref testowych rejestrowane w setUp().
     *
     * @return list<class-string<ServiceProvider>>
     */
    protected function zoneProviders(): array
    {
        return [AlphaZoneServiceProvider::class, BetaZoneServiceProvider::class, OpenZoneServiceProvider::class];
    }

    /**
     * Konto strefy zapisane w bazie; PIN podany jawnie trafia do bazy jako hash.
     *
     * @template TAccount of ZoneAccount
     *
     * @param class-string<TAccount> $model
     * @param array<string, mixed> $attributes
     *
     * @return TAccount
     */
    protected function account(string $model, array $attributes = [], ?string $pin = null): ZoneAccount
    {
        $account = new $model();
        $account->forceFill([
            'name' => 'Anna Nowak',
            'email' => 'anna@example.com',
            'pin_hash' => $pin === null ? null : Hash::make($pin),
            'is_active' => true,
            ...$attributes,
        ])->save();

        return $account;
    }

    /**
     * Zalogowane konto strefy (guard o nazwie strefy) z epoką w sesji strefy, jak po CompleteSignIn.
     */
    protected function signedIn(ZoneAccount $account, string $zone): static
    {
        $sessions = $this->app->make(ZoneSessionConfigurator::class);
        $sessions->apply($this->app->make(ZoneRegistry::class)->get($zone));
        $manager = $this->app->make('session');
        $this->assertInstanceOf(ZoneSessionManager::class, $manager);
        $manager->driver()->put(CompleteSignIn::EPOCH_SESSION_KEY, $account->authEpoch());
        $sessions->restore();

        return $this->actingAs($account, $zone);
    }

    /**
     * Pierwszy błąd pola w sesji strefy po żądaniu zalogowanego konta. TestResponse::assertSessionHasErrors()
     * startuje magazyn sesji strefy od nowa i nie widzi wtedy błędów, więc testy kont zalogowanych czytają je tu — z magazynu
     * `session.store`, który ZoneSessionConfigurator ustawia na sesję strefy ostatniego żądania.
     */
    protected function sessionError(string $field): string
    {
        $errors = $this->app->make('session.store')->get('errors');

        if ($errors instanceof ViewErrorBag) {
            return (string) $errors->first($field);
        }

        $messages = is_array($errors) && is_array($errors['default'] ?? null) ? ($errors['default']['messages'] ?? null) : null;
        $message = is_array($messages) && is_array($messages[$field] ?? null) ? ($messages[$field][0] ?? '') : '';

        return is_string($message) ? $message : '';
    }

    protected function assertSessionError(string $field, string $message): void
    {
        $this->assertSame($message, $this->sessionError($field));
    }

    /**
     * Rejestruje provider strefy w uruchomionej już aplikacji i odświeża indeks nazw tras.
     *
     * @param class-string<ServiceProvider>|ServiceProvider $provider
     */
    protected function registerZone(string|ServiceProvider $provider): void
    {
        $this->app->register($provider);
        $routes = $this->app->make(Router::class)->getRoutes();
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();
    }
}
