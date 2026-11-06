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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Simplysmart\Polygon\Contracts\InterfaceLocales;
use Simplysmart\Polygon\Enums\TaxRateType;
use Simplysmart\Polygon\Localization\DictionaryChangeRejected;
use Simplysmart\Polygon\Localization\DictionaryManagement;
use Simplysmart\Polygon\Models\Dictionaries\Country;
use Simplysmart\Polygon\Models\Dictionaries\Language;
use Simplysmart\Polygon\Models\Dictionaries\TaxRate;
use Tests\TestCase;

/**
 * Zmiany słowników (Localization\DictionaryManagement): bez wyłączania wpisów domyślnych i języków interfejsu, nowa
 * stawka podstawowa kończy poprzednią, języki interfejsu ze słownika w granicach configu (InterfaceLocales).
 */
class DictionaryManagementTest extends TestCase
{
    use RefreshDatabase;

    private DictionaryManagement $management;

    private Country $poland;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.locale' => 'pl', 'polygon.locale.enabled' => ['pl', 'en']]);
        $this->management = $this->app->make(DictionaryManagement::class);
        $this->poland = (new Country())->forceFill(['name' => 'Polska', 'alpha_code_2' => 'PL', 'is_active' => true, 'is_default' => true]);
        $this->poland->save();
    }

    public function test_default_entries_and_interface_languages_cannot_be_disabled(): void
    {
        $this->assertRejected(fn() => $this->management->setActive($this->poland, false));

        $polish = (new Language())->forceFill(['name' => 'polski', 'code' => 'pl', 'is_active' => true, 'is_interface' => true]);
        $polish->save();
        $this->assertRejected(fn() => $this->management->setActive($polish, false));

        $german = (new Language())->forceFill(['name' => 'niemiecki', 'code' => 'de', 'is_active' => true]);
        $german->save();
        $this->management->setActive($german, false);
        $this->assertFalse($german->refresh()->is_active);
    }

    public function test_new_standard_rate_ends_the_previous_one(): void
    {
        $old = $this->management->addTaxRate($this->poland, TaxRateType::Standard, '22', CarbonImmutable::parse('2010-01-01'));
        $this->management->addTaxRate($this->poland, TaxRateType::Standard, '23', CarbonImmutable::parse('2011-01-01'));

        $this->assertSame('2010-12-31', $old->refresh()->valid_to?->toDateString());
        $this->assertSame(2, TaxRate::query()->count());
        $this->assertRejected(fn() => $this->management->addTaxRate($this->poland, TaxRateType::Standard, '120', CarbonImmutable::parse('2012-01-01')));
    }

    public function test_interface_languages_come_from_the_dictionary_within_the_config(): void
    {
        $locales = $this->app->make(InterfaceLocales::class);
        $this->assertSame(['pl', 'en'], $locales->enabled(), 'Bez słownika decyduje config.');

        (new Language())->forceFill(['name' => 'polski', 'code' => 'pl', 'is_interface' => true, 'is_default' => true])->save();
        (new Language())->forceFill(['name' => 'niemiecki', 'code' => 'de', 'is_interface' => true])->save();
        $locales->forget();

        $this->assertSame(['pl'], $locales->enabled(), 'Słownik nie włącza języka spoza configu (de).');
        $this->assertSame('pl', $locales->default());
    }

    private function assertRejected(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected the change to be rejected.');
        } catch (DictionaryChangeRejected) {
            $this->addToAssertionCount(1);
        }
    }
}
