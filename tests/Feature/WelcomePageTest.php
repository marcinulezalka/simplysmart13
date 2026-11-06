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

use Illuminate\Contracts\Foundation\Application;
use Simplysmart\Polygon\View\ApplicationVersion;
use Tests\TestCase;

/**
 * Strona startowa (`/`, resources/views/welcome.blade.php): teksty z lang/pl/welcome.php, wersja z najnowszego
 * wydania w CHANGELOG.md,
 * style inline bez zewnętrznych arkuszy.
 */
class WelcomePageTest extends TestCase
{
    /**
     * Funkcje pokazane na stronie (klucze `welcome.features.*`).
     *
     * @var list<string>
     */
    private const array FEATURES = ['zones', 'sign_in', 'dictionaries', 'history', 'locale', 'standards'];

    public function test_welcome_page_shows_the_installation_card(): void
    {
        $this->app->setLocale('pl');
        $version = $this->app->make(ApplicationVersion::class)->current();
        $this->assertNotNull($version);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('został pomyślnie zainstalowany');
        $response->assertSee('Co zyskujesz na starcie?');
        $response->assertSee('Pełny audyt zmian (Audit Trail)');
        $response->assertSee('<span class="ss-badge__version" aria-hidden="true">' . $version . '</span>', false);
        $response->assertSee('aria-label="Wersja ' . $version . '"', false);
        $response->assertDontSee('wersja ', false);
        $response->assertSee('ss-badge__zap', false);
        $response->assertSee('<style>', false);
        $response->assertDontSee('rel="stylesheet"', false);
    }

    public function test_version_follows_the_latest_release_in_the_changelog(): void
    {
        $root = sys_get_temp_dir() . '/welcome-version-' . uniqid();
        mkdir($root);
        file_put_contents($root . '/' . ApplicationVersion::CHANGELOG, "# Changelog\n\n## [Unreleased]\n\n## [2.3.4] - 2026-12-01\n\n## [2.3.3] - 2026-11-30\n");
        $app = $this->createMock(Application::class);
        $app->method('basePath')->willReturnCallback(static fn(string $path = ''): string => $root . '/' . $path);

        $this->assertSame('2.3.4', (new ApplicationVersion($app))->current());

        unlink($root . '/' . ApplicationVersion::CHANGELOG);
        rmdir($root);
    }

    public function test_every_welcome_text_is_translated(): void
    {
        $this->app->setLocale('pl');
        $keys = ['welcome.title_before', 'welcome.title_after', 'welcome.lead', 'welcome.inside', 'welcome.built_on', 'welcome.version'];

        foreach (self::FEATURES as $feature) {
            $keys[] = "welcome.features.{$feature}.title";
            $keys[] = "welcome.features.{$feature}.description";
        }

        foreach ($keys as $key) {
            $this->assertNotSame($key, text($key), "Brak tekstu {$key}.");
        }
    }
}
