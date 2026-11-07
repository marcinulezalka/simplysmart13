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

namespace Tests\Fixtures\Zones;

use Simplysmart\Polygon\Contracts\ZoneHome;
use Simplysmart\Polygon\Models\ZoneAccount;

/**
 * Strona startowa strefy alpha: konto z uprawnieniem PERMISSION (w bieżącej organizacji) zaczyna od raportów,
 * konto z adresem MISSING_ROUTE_EMAIL dostaje nieistniejącą trasę (polygon wraca wtedy na pulpit), reszta — pulpit.
 */
final readonly class AlphaZoneHome implements ZoneHome
{
    public const string PERMISSION = 'reports.view';

    public const string ROUTE = 'reports';

    public const string MISSING_ROUTE_EMAIL = 'missing@example.com';

    public function routeFor(ZoneAccount $account): ?string
    {
        if ($account->emailAddress() === self::MISSING_ROUTE_EMAIL) {
            return 'missing';
        }

        return $account->can(self::PERMISSION) ? self::ROUTE : null;
    }
}
