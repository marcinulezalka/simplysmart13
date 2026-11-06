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
use Simplysmart\Polygon\Enums\ExchangeRateSource;
use Simplysmart\Polygon\Enums\HolidaySource;
use Simplysmart\Polygon\Enums\MeasureUnitKind;
use Simplysmart\Polygon\Models\Dictionaries\Country;
use Simplysmart\Polygon\Models\Dictionaries\Currency;
use Simplysmart\Polygon\Models\Dictionaries\ExchangeRate;
use Simplysmart\Polygon\Models\Dictionaries\Holiday;
use Simplysmart\Polygon\Models\Dictionaries\Language;
use Simplysmart\Polygon\Models\Dictionaries\MeasureUnit;
use Simplysmart\Polygon\Queries\Dictionaries\DefaultEntries;
use Simplysmart\Polygon\Queries\Dictionaries\DictionarySearch;
use Simplysmart\Polygon\Queries\Dictionaries\ExchangeRatesOn;
use Simplysmart\Polygon\Queries\Dictionaries\HolidaysIn;
use Simplysmart\Polygon\Queries\Dictionaries\MeasureUnits;
use Tests\TestCase;

/**
 * Odczyty słowników (Queries\Dictionaries): wpisy domyślne z flagi albo configu, dni wolne i robocze, kursy walut
 * z ostatniego dnia publikacji i przeliczenia przez PLN, wyszukiwanie po nazwie i kodzie, jednostki miar kraju.
 */
class DictionaryQueriesTest extends TestCase
{
    use RefreshDatabase;

    private Country $poland;

    private Currency $pln;

    private Currency $eur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pln = $this->currency('PLN', 'złoty polski');
        $this->eur = $this->currency('EUR', 'euro');
        $this->poland = (new Country())->forceFill(['name' => 'Polska', 'alpha_code_2' => 'PL', 'is_active' => true, 'currency_id' => $this->pln->id]);
        $this->poland->save();
    }

    public function test_default_entries_come_from_the_flag_or_the_configured_code(): void
    {
        $defaults = $this->app->make(DefaultEntries::class);

        $this->assertTrue($defaults->country()?->is($this->poland), 'Bez is_default: kraj z APP_COUNTRY.');
        $this->assertTrue($defaults->currency()?->is($this->pln));

        $germany = (new Country())->forceFill(['name' => 'Niemcy', 'alpha_code_2' => 'DE', 'is_default' => true]);
        $germany->save();
        (new Language())->forceFill(['name' => 'polski', 'code' => 'pl'])->save();

        $this->assertTrue($defaults->country()->is($germany));
        $this->assertSame('pl', $defaults->language()?->code);
    }

    public function test_days_off_and_business_days(): void
    {
        (new Holiday())->forceFill(['name' => 'Święto Niepodległości', 'country_id' => $this->poland->id, 'holiday_date' => '2026-11-11', 'source' => HolidaySource::Manual])->save();
        $holidays = $this->app->make(HolidaysIn::class);

        $this->assertSame(['2026-11-11'], array_keys($holidays->applicationDaysOff(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'))));
        $this->assertFalse($holidays->isBusinessDay($this->poland, CarbonImmutable::parse('2026-11-11')));
        $this->assertFalse($holidays->isBusinessDay($this->poland, CarbonImmutable::parse('2026-11-14')));
        $this->assertTrue($holidays->isBusinessDay($this->poland, CarbonImmutable::parse('2026-11-12')));
        $this->assertSame([], $holidays->daysOff(null, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30')));
    }

    public function test_rate_before_a_date_skips_days_without_publication_and_converts_amounts(): void
    {
        (new ExchangeRate())->forceFill(['name' => 'EUR 2026-10-23', 'currency_id' => $this->eur->id, 'rate_date' => '2026-10-23', 'rate' => '4.2601', 'source' => ExchangeRateSource::Nbp])->save();
        $rates = $this->app->make(ExchangeRatesOn::class);

        $this->assertSame('4.260100', $rates->lastBefore($this->eur, CarbonImmutable::parse('2026-10-26'))?->rate);
        $this->assertNull($rates->lastBefore($this->eur, CarbonImmutable::parse('2026-10-23')));
        $this->assertSame('426.01', $rates->convert('100', $this->eur, $this->pln, CarbonImmutable::parse('2026-10-26')));
        $this->assertSame('23.47', $rates->convert('100', $this->pln, $this->eur, CarbonImmutable::parse('2026-10-26')));
        $this->assertNull($rates->convert('100', $this->eur, $this->pln, CarbonImmutable::parse('2026-10-01')));
    }

    public function test_search_matches_names_and_codes_without_like_wildcards(): void
    {
        $search = $this->app->make(DictionarySearch::class);

        $this->assertSame(['PL'], $search->countries('pol', 10)->pluck('alpha_code_2')->all());
        $this->assertSame(['EUR'], $search->currencies('eur', 10)->pluck('alpha_code_3')->all());
        $this->assertSame([], $search->countries('%', 10)->pluck('alpha_code_2')->all());
    }

    public function test_measure_units_of_a_country(): void
    {
        (new MeasureUnit())->forceFill(['name' => 'kilogram', 'country_id' => $this->poland->id, 'code' => 'kg', 'kind' => MeasureUnitKind::Mass, 'is_active' => true])->save();

        $this->assertSame(['kg'], $this->app->make(MeasureUnits::class)->forCountry($this->poland)->pluck('code')->all());
    }

    private function currency(string $code, string $name): Currency
    {
        $currency = (new Currency())->forceFill(['name' => $name, 'alpha_code_3' => $code, 'is_active' => true]);
        $currency->save();

        return $currency;
    }
}
