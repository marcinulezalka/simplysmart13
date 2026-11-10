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

namespace Tests\Feature\Localization;

use Carbon\CarbonImmutable;
use Simplysmart\Polygon\Localization\ApplicationTimezone;
use Simplysmart\Polygon\PolygonConfig;
use Tests\TestCase;

/**
 * Strefa czasowa aplikacji z `APP_TIMEZONE` (polygon: Localization\ApplicationTimezone) — stockowy config/app.php ma
 * na sztywno UTC, a godziny grafików są czasem lokalnym.
 */
class ApplicationTimezoneTest extends TestCase
{
    private string $previousTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimezone);

        parent::tearDown();
    }

    public function test_configured_timezone_becomes_the_application_and_php_timezone(): void
    {
        config([PolygonConfig::TIMEZONE => 'Europe/Warsaw', 'app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');

        $this->assertSame('Europe/Warsaw', $this->app->make(ApplicationTimezone::class)->apply());
        $this->assertSame('Europe/Warsaw', config('app.timezone'));
        $this->assertSame('Europe/Warsaw', date_default_timezone_get());
        $this->assertSame('Europe/Warsaw', CarbonImmutable::now()->getTimezone()->getName());
    }

    public function test_empty_or_unknown_timezone_keeps_the_framework_timezone(): void
    {
        foreach (['', 'Mars/Olympus'] as $timezone) {
            config([PolygonConfig::TIMEZONE => $timezone, 'app.timezone' => 'UTC']);
            date_default_timezone_set('UTC');

            $this->assertNull($this->app->make(ApplicationTimezone::class)->apply());
            $this->assertSame('UTC', config('app.timezone'));
            $this->assertSame('UTC', date_default_timezone_get());
        }
    }
}
