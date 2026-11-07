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

use Illuminate\Support\Facades\Blade;
use InvalidArgumentException;
use Simplysmart\Polygon\Assets\PackageAssets;
use Tests\TestCase;

/**
 * Assety paczek z ich własnego builda Vite (Assets\PackageAssets): znaczniki wejść z manifestu paczki pod
 * `public/vendor/<paczka>/build/`, dyrektywa `@packageAssets` i komenda `polygon:assets` udostępniająca katalog
 * `public/` paczki. Paczka testowa `shop` (tests/Fixtures/Assets/shop).
 */
class PackageAssetsTest extends TestCase
{
    private const string PACKAGE = 'shop';

    private const string FIXTURE = __DIR__ . '/../../Fixtures/Assets/shop/public';

    protected function setUp(): void
    {
        parent::setUp();
        $assets = new PackageAssets();
        $assets->add(self::PACKAGE, self::FIXTURE);
        $this->app->instance(PackageAssets::class, $assets);
    }

    protected function tearDown(): void
    {
        $target = public_path(PackageAssets::PUBLIC_DIRECTORY . '/' . self::PACKAGE);

        if (is_link($target)) {
            unlink($target);
        } elseif (is_dir($target)) {
            $this->app->make('files')->deleteDirectory($target);
        }

        parent::tearDown();
    }

    public function test_entries_render_tags_from_the_package_manifest_in_order(): void
    {
        $tags = (string) $this->app->make(PackageAssets::class)->tags(self::PACKAGE, ['resources/scss/app.scss', 'resources/js/app.js']);

        $this->assertSame(implode("\n", [
            '<link rel="stylesheet" href="' . asset('vendor/shop/build/assets/app-abc123.css') . '">',
            '<link rel="stylesheet" href="' . asset('vendor/shop/build/assets/app-def456.css') . '">',
            '<script type="module" src="' . asset('vendor/shop/build/assets/app-def456.js') . '"></script>',
        ]), $tags);
    }

    public function test_the_blade_directive_renders_the_same_tags(): void
    {
        $html = Blade::render("@packageAssets('shop', ['resources/scss/app.scss'])");

        $this->assertSame('<link rel="stylesheet" href="' . asset('vendor/shop/build/assets/app-abc123.css') . '">', $html);
    }

    public function test_an_unknown_entry_or_package_fails_loudly(): void
    {
        $assets = $this->app->make(PackageAssets::class);

        $this->assertThrows(static fn() => $assets->tags(self::PACKAGE, ['resources/missing.css']), InvalidArgumentException::class);
        $this->assertThrows(static fn() => $assets->tags('missing', ['resources/scss/app.scss']), InvalidArgumentException::class);
    }

    public function test_the_command_links_or_copies_the_package_public_directory(): void
    {
        $target = public_path('vendor/shop');

        $this->command('polygon:assets')->assertSuccessful();
        $this->assertTrue(is_link($target));
        $this->assertFileExists($target . '/build/manifest.json');

        $this->command('polygon:assets', ['--copy' => true])->assertSuccessful();
        $this->assertFalse(is_link($target));
        $this->assertFileExists($target . '/build/manifest.json');
    }
}
