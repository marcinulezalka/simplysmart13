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

use Simplysmart\Polygon\Enums\RecordStatus;
use Simplysmart\Polygon\Localization\PackageText;
use Tests\TestCase;

/**
 * Teksty paczki simplysmart/polygon w przestrzeni `polygon::` (etykiety enumów), w języku żądania.
 */
class PolygonTextsTest extends TestCase
{
    public function test_enum_labels_follow_the_current_locale(): void
    {
        $this->app->setLocale('pl');
        $this->assertSame('Aktywny', RecordStatus::Active->label());

        $this->app->setLocale('en');
        $this->assertSame('Active', RecordStatus::Active->label());
    }

    public function test_missing_text_returns_its_key(): void
    {
        $this->assertSame('enums.missing', PackageText::get('enums.missing'));
    }
}
