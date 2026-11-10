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

namespace Tests\Feature\Icons;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Simplysmart\Polygon\Icons\IconCatalog;
use Simplysmart\Polygon\Icons\IconifyCollection;
use Simplysmart\Polygon\Icons\IconifyIcon;
use Simplysmart\Polygon\Models\Organization;
use Simplysmart\Polygon\Rules\IconName;
use Tests\TestCase;

/**
 * Ikony Iconify polygonu (docs/ikony.md): nazwa „zestaw:ikona” i znacznik web componentu, lokalna kolekcja Iconify
 * JSON składana w SVG na serwerze, katalog z dozwolonymi zestawami i sprawdzaniem ikony w API (cache), reguła
 * walidacji, komendy deweloperskie i ikona organizacji.
 */
class IconifyIconsTest extends TestCase
{
    public function test_an_icon_name_is_set_and_icon_and_renders_as_a_hidden_web_component(): void
    {
        $icon = IconifyIcon::from('lucide:heart-pulse');

        $this->assertSame(['lucide', 'heart-pulse', 'lucide:heart-pulse'], [$icon->prefix, $icon->name, (string) $icon]);
        $this->assertSame('<iconify-icon icon="lucide:heart-pulse" class="ul-icon x" aria-hidden="true"></iconify-icon>', (string) $icon->html(' ul-icon x '));
        $this->assertSame('<iconify-icon icon="lucide:heart-pulse" aria-hidden="true"></iconify-icon>', (string) $icon->html());

        foreach (['heart', 'Lucide:heart', 'lucide:', ':heart', 'lucide:heart--pulse', 'lucide:heart pulse', 'lucide:heart"><script>', 'a:b:c'] as $invalid) {
            $this->assertNull(IconifyIcon::tryFrom($invalid), $invalid);
        }

        $this->expectException(InvalidArgumentException::class);
        IconifyIcon::from('serce');
    }

    public function test_a_local_collection_builds_svg_for_icons_and_aliases(): void
    {
        $collection = IconifyCollection::fromArray([
            'prefix' => 'demo',
            'width' => 24,
            'height' => 24,
            'icons' => ['dot' => ['body' => '<circle cx="12" cy="12" r="4"/>'], 'wide' => ['body' => '<path d="M0 0"/>', 'width' => 32]],
            'aliases' => ['point' => ['parent' => 'dot']],
        ]);

        $this->assertSame('demo', $collection->prefix);
        $this->assertSame(['dot', 'wide'], $collection->names());
        $this->assertTrue($collection->has('point'));
        $this->assertFalse($collection->has('missing'));
        $this->assertSame('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="a&quot;b"><circle cx="12" cy="12" r="4"/></svg>', $collection->svg('point', ['class' => 'a"b']));
        $this->assertStringContainsString('viewBox="0 0 32 24"', (string) $collection->svg('wide'));
        $this->assertNull($collection->svg('missing'));

        $this->expectException(InvalidArgumentException::class);
        IconifyCollection::fromArray(['icons' => []]);
    }

    public function test_the_catalog_checks_icons_in_the_api_and_caches_the_answer(): void
    {
        Http::fake([
            'api.iconify.design/lucide.json?icons=heart' => Http::response(['prefix' => 'lucide', 'icons' => ['heart' => ['body' => '<path/>']]]),
            'api.iconify.design/lucide.json?icons=home' => Http::response(['prefix' => 'lucide', 'icons' => [], 'aliases' => ['home' => ['parent' => 'house']]]),
            'api.iconify.design/lucide.json?icons=nope' => Http::response(['prefix' => 'lucide', 'icons' => [], 'not_found' => ['nope']]),
        ]);
        $catalog = $this->app->make(IconCatalog::class);

        $this->assertSame(['lucide', 'fluent', 'tabler', 'healthicons'], $catalog->sets());
        $this->assertTrue($catalog->allows(IconifyIcon::from('healthicons:cardiology')));
        $this->assertFalse($catalog->allows(IconifyIcon::from('mdi:heart')));
        $this->assertTrue($catalog->exists(IconifyIcon::from('lucide:heart')));
        $this->assertTrue($catalog->exists(IconifyIcon::from('lucide:home')), 'Alias też jest ikoną.');
        $this->assertFalse($catalog->exists(IconifyIcon::from('lucide:nope')));
        $this->assertTrue($catalog->exists(IconifyIcon::from('lucide:heart')));
        Http::assertSentCount(3);
    }

    public function test_an_unreachable_api_is_not_cached_and_the_rule_lets_a_valid_name_through(): void
    {
        $attempts = 0;
        Http::fake(static function () use (&$attempts): never {
            $attempts++;

            throw new ConnectionException('offline');
        });
        $catalog = $this->app->make(IconCatalog::class);

        $this->assertNull($catalog->exists(IconifyIcon::from('lucide:heart')));
        $this->assertTrue($this->passes('lucide:heart'), 'Zapis nie czeka na zewnętrzną usługę.');
        $this->assertSame(2, $attempts, 'Brak odpowiedzi nie trafia do cache — reguła pyta ponownie.');
    }

    public function test_the_rule_needs_a_name_from_an_allowed_set_that_exists(): void
    {
        Http::fake([
            'api.iconify.design/lucide.json?icons=heart' => Http::response(['prefix' => 'lucide', 'icons' => ['heart' => ['body' => '<path/>']]]),
            'api.iconify.design/lucide.json?icons=nope' => Http::response(['prefix' => 'lucide', 'icons' => [], 'not_found' => ['nope']]),
        ]);

        $this->assertTrue($this->passes('lucide:heart'));
        $this->assertSame([text('icons.invalid')], $this->messages('serce'));
        $this->assertSame([text('icons.invalid')], $this->messages(['lucide:heart']));
        $this->assertSame([text('icons.set_not_allowed', ['sets' => 'lucide, fluent, tabler, healthicons'])], $this->messages('mdi:heart'));
        $this->assertSame([text('icons.missing', ['icon' => 'lucide:nope'])], $this->messages('lucide:nope'));
    }

    public function test_the_subset_command_writes_a_collection_per_set(): void
    {
        Http::fake([
            'api.iconify.design/lucide.json*' => Http::response(['prefix' => 'lucide', 'lastModified' => 1, 'width' => 24, 'height' => 24, 'icons' => ['clock' => ['body' => '<path/>'], 'heart' => ['body' => '<path/>']]]),
            'api.iconify.design/fluent.json*' => Http::response(['prefix' => 'fluent', 'icons' => [], 'not_found' => ['nope']]),
        ]);
        $directory = sys_get_temp_dir() . '/polygon-icons-' . uniqid();

        $this->artisan('polygon:icons:subset', ['directory' => $directory, 'icons' => ['lucide:heart', 'lucide:clock']])->assertSuccessful();
        Http::assertSent(static fn(Request $request): bool => $request->url() === 'https://api.iconify.design/lucide.json?icons=clock%2Cheart');
        $collection = IconifyCollection::load($directory . '/lucide.json');
        $this->assertSame(['clock', 'heart'], $collection->names());
        $this->assertStringNotContainsString('lastModified', (string) file_get_contents($directory . '/lucide.json'));

        $this->artisan('polygon:icons:subset', ['directory' => $directory, 'icons' => ['fluent:nope']])->assertFailed()->expectsOutputToContain('nope');
        $this->artisan('polygon:icons:subset', ['directory' => $directory, 'icons' => ['serce']])->assertFailed();
        $this->assertFileDoesNotExist($directory . '/fluent.json');

        unlink($directory . '/lucide.json');
        rmdir($directory);
    }

    public function test_the_png_command_rejects_a_bad_name_or_colour(): void
    {
        Http::fake();

        $this->artisan('polygon:icons:png', ['icon' => 'serce', 'output' => sys_get_temp_dir() . '/icon.png'])->assertFailed();
        $this->artisan('polygon:icons:png', ['icon' => 'lucide:heart', 'output' => sys_get_temp_dir() . '/icon.png', '--color' => 'red'])->assertFailed();
        Http::assertNothingSent();
    }

    public function test_an_organization_icon_may_be_any_iconify_icon(): void
    {
        $organization = new Organization();

        $organization->forceFill(['json_attributes' => [Organization::ICON_ATTRIBUTE => 'healthicons:cardiology']]);
        $this->assertSame('healthicons:cardiology', $organization->icon());
        $organization->forceFill(['json_attributes' => [Organization::ICON_ATTRIBUTE => 'heart']]);
        $this->assertSame('heart', $organization->icon(), 'Nazwa z ICONS zostaje.');
        $organization->forceFill(['json_attributes' => [Organization::ICON_ATTRIBUTE => 'nie ikona']]);
        $this->assertSame(Organization::ICONS[0], $organization->icon());
    }

    private function passes(mixed $value): bool
    {
        return Validator::make(['icon' => $value], ['icon' => [$this->app->make(IconName::class)]])->passes();
    }

    /**
     * @return list<string>
     */
    private function messages(mixed $value): array
    {
        return array_values(Validator::make(['icon' => $value], ['icon' => [$this->app->make(IconName::class)]])->errors()->get('icon'));
    }
}
