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

namespace Tests\Feature\Assets;

use Illuminate\Support\Facades\Route;
use Simplysmart\Polygon\Enums\ColorTheme;
use Simplysmart\Polygon\View\ColorThemePreference;
use Tests\TestCase;

/**
 * Tryb kolorystyczny z ciasteczka `polygon_theme` (View\ColorThemePreference): wartość zapisana przez skrypt strefy
 * (bez szyfrowania) przechodzi przez EncryptCookies, nieznana wartość albo brak ciasteczka to tryb automatyczny.
 */
class ColorThemePreferenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('web')->get('/_theme', static fn(ColorThemePreference $theme): string => $theme->current()->value);
    }

    public function test_a_plain_cookie_selects_the_theme(): void
    {
        foreach (ColorTheme::cases() as $theme) {
            $this->withUnencryptedCookie(ColorThemePreference::COOKIE, $theme->value)->get('/_theme')->assertOk()->assertSeeText($theme->value);
        }
    }

    public function test_an_unknown_or_missing_cookie_means_auto(): void
    {
        $this->withUnencryptedCookie(ColorThemePreference::COOKIE, 'neon')->get('/_theme')->assertSeeText(ColorTheme::Auto->value);
        $this->get('/_theme')->assertSeeText(ColorTheme::Auto->value);
    }

    public function test_every_theme_has_a_label(): void
    {
        foreach (ColorTheme::cases() as $theme) {
            $this->assertStringNotContainsString('enums.', $theme->label());
        }
    }
}
