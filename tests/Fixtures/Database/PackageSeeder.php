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

namespace Tests\Fixtures\Database;

use Illuminate\Database\Seeder;
use Simplysmart\Polygon\Models\Organization;
use Simplysmart\Polygon\Models\PolygonAccount;

/**
 * Seeder paczki testowej (Database\PackageSeeders): zapisuje, co zastał w bazie przy każdym uruchomieniu, więc test
 * widzi, że seeder paczki działa po seederach bazowych polygon.
 */
final class PackageSeeder extends Seeder
{
    /**
     * @var list<array{organizations: int, accounts: int}>
     */
    public static array $runs = [];

    public function run(): void
    {
        self::$runs[] = ['organizations' => Organization::query()->count(), 'accounts' => PolygonAccount::query()->count()];
    }
}
