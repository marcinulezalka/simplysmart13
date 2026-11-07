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
 * Strefa testowa gamma z logowaniem i hashem sesji w adresie ekranów po zalogowaniu (`url_key`).
 */
class GammaZoneServiceProvider extends FixtureZoneServiceProvider
{
    protected function zoneName(): string
    {
        return 'gamma';
    }

    protected function zoneConfig(): array
    {
        return $this->signInZone(GammaAccount::class, ['url_key' => true]);
    }
}
