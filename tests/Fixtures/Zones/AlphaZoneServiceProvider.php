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

/**
 * Strefa testowa alpha z logowaniem, zaufanymi urządzeniami i stroną startową konta (AlphaZoneHome).
 */
class AlphaZoneServiceProvider extends FixtureZoneServiceProvider
{
    protected function zoneName(): string
    {
        return 'alpha';
    }

    protected function zoneConfig(): array
    {
        return $this->signInZone(AlphaAccount::class, ['home' => AlphaZoneHome::class]);
    }
}
