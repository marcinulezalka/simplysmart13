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
use Illuminate\Support\Facades\Artisan;
use Simplysmart\Polygon\Database\PackageSeeders;
use Simplysmart\Polygon\Models\Dictionaries\Country;
use Simplysmart\Polygon\Models\Dictionaries\MeasureUnit;
use Simplysmart\Polygon\Models\Organization;
use Simplysmart\Polygon\Models\OrganizationUnitType;
use Simplysmart\Polygon\Models\PolygonAccount;
use Simplysmart\Polygon\Models\User;
use Tests\Fixtures\Database\PackageSeeder;
use Tests\TestCase;

/**
 * `php artisan db:seed` bez własnego seedera projektu: seeder główny z paczki simplysmart/polygon — seedery bazowe
 * (słowniki z jednostkami miar, typy jednostek i organizacja hero, plik instalacji: konto support strefy polygon z rolą
 * hero w organizacji hero; bez kont frontendu), potem seedery paczek z Database\PackageSeeders. Migracje danych nie
 * wstawiają.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_db_seed_loads_the_dictionaries_and_the_install_file_once(): void
    {
        Artisan::call('db:seed');
        Artisan::call('db:seed');

        $this->assertSame(0, User::query()->count());
        $this->assertSame(1, PolygonAccount::query()->count());
        $this->assertGreaterThan(200, Country::query()->count());
        $this->assertSame('PL', Country::query()->default()->value('alpha_code_2'));
        $this->assertSame([Organization::HERO_ID], Organization::query()->pluck('id')->all());
        $this->assertSame([Organization::HERO_ID], PolygonAccount::query()->sole()->organizations()->pluck('organizations.id')->all());
        $this->assertGreaterThan(0, OrganizationUnitType::query()->count());
        $this->assertGreaterThan(0, MeasureUnit::query()->count());
    }

    public function test_migrations_leave_the_reference_tables_empty(): void
    {
        $this->assertSame(0, Country::query()->count());
        $this->assertSame(0, MeasureUnit::query()->count());
        $this->assertSame(0, OrganizationUnitType::query()->count());
        $this->assertSame(0, Organization::query()->count());
    }

    public function test_package_seeders_run_after_the_base_seeders(): void
    {
        PackageSeeder::$runs = [];
        $this->app->make(PackageSeeders::class)->add(PackageSeeder::class);
        $this->app->make(PackageSeeders::class)->add(PackageSeeder::class);

        Artisan::call('db:seed');

        $this->assertSame([['organizations' => 1, 'accounts' => 1]], PackageSeeder::$runs);
    }
}
