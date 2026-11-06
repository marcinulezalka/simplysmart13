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

use Simplysmart\Polygon\Models\Permissions\Role;
use Simplysmart\Polygon\Permissions\BaseRole;

/**
 * Instalacja z zarejestrowanymi strefami: każda strefa z kontami dostaje katalog ról z pliku paczki w swoim guardzie,
 * także bez własnej sekcji w plikach; strefa publiczna nie dostaje ról.
 */
class ZoneInstallTest extends ZoneTestCase
{
    public function test_every_zone_with_accounts_gets_the_role_catalog_in_its_guard(): void
    {
        $this->command('polygon:install')->assertSuccessful();

        foreach (['alpha', 'beta', 'polygon'] as $guard) {
            $this->assertEqualsCanonicalizing(
                array_column(BaseRole::cases(), 'value'),
                Role::query()->where('guard_name', $guard)->pluck('name')->all(),
                $guard,
            );
        }

        $this->assertSame(0, Role::query()->where('guard_name', 'open')->count());
    }
}
