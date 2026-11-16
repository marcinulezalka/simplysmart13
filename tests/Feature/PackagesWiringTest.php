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

namespace Tests\Feature;

use Simplysmart\Polygon\Contracts\ZoneRegistry;
use Simplysmart\Polygon\Providers\PolygonServiceProvider;
use Simplysmart\Polygon\Zones\PolygonZone;
use Tests\TestCase;

/**
 * Podpięcie paczek w projekcie: provider polygonu ładuje się przez auto-discovery, a strefa `polygon` jest
 * zarejestrowana. Logikę paczek testują ich własne testy integracyjne (zestaw `Packages`).
 */
class PackagesWiringTest extends TestCase
{
    public function test_the_polygon_provider_is_discovered_and_its_zone_registered(): void
    {
        $this->assertNotNull($this->app->getProvider(PolygonServiceProvider::class));
        $this->assertTrue($this->app->make(ZoneRegistry::class)->has(PolygonZone::NAME));
    }
}
