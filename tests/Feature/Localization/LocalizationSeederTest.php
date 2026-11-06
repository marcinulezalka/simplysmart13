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

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Simplysmart\Polygon\Database\History;
use Simplysmart\Polygon\Database\IdSlots;
use Simplysmart\Polygon\Database\Seeders\LocalizationData;
use Carbon\CarbonImmutable;
use Simplysmart\Polygon\Enums\Continent;
use Simplysmart\Polygon\Enums\ExchangeRateSource;
use Simplysmart\Polygon\Enums\HolidaySource;
use Simplysmart\Polygon\Enums\TaxRateType;
use Simplysmart\Polygon\Models\Dictionaries\Country;
use Simplysmart\Polygon\Models\Dictionaries\Currency;
use Simplysmart\Polygon\Models\Dictionaries\ExchangeRate;
use Simplysmart\Polygon\Models\Dictionaries\Holiday;
use Simplysmart\Polygon\Models\Dictionaries\Language;
use Simplysmart\Polygon\Models\Dictionaries\TaxRate;
use Simplysmart\Polygon\Queries\Dictionaries\ExchangeRatesOn;
use Tests\TestCase;

/**
 * Komenda polygon:localization:seed: słowniki z ICU, wartości domyślne z configu, powiązania krajów,
 * stawki VAT kraju aplikacji, dni wolne Polski, kursy NBP, ponowne uruchomienie bez zmian i z zachowaniem wyborów administratora.
 */
class LocalizationSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.locale' => 'pl', 'polygon.locale.enabled' => ['pl', 'en']]);
        $this->app->setLocale('pl');
        $this->seedLocalization();
    }

    public function test_defaults_are_poland_polish_and_zloty_linked_together(): void
    {
        $country = Country::query()->default()->sole();

        $this->assertSame('PL', $country->alpha_code_2);
        $this->assertSame('pl', Language::query()->default()->sole()->code);
        $this->assertSame('PLN', Currency::query()->default()->sole()->alpha_code_3);
        $this->assertSame(1, Country::query()->where('is_default', true)->count());
        $this->assertSame('PLN', $country->currency?->alpha_code_3);
        $this->assertSame('pl', $country->languages->first()?->code);
        $this->assertTrue($country->is_eu);
        $this->assertSame('+48', $country->dial_code);
        $this->assertSame('Poland', $country->translatedName('en'));
    }

    public function test_switched_on_entries_and_interface_languages(): void
    {
        $this->assertSame(['CHF', 'EUR', 'GBP', 'PLN', 'USD'], Currency::query()->active()->orderBy('alpha_code_3')->pluck('alpha_code_3')->all());
        $this->assertSame(['en', 'pl'], Language::query()->where('is_interface', true)->orderBy('code')->pluck('code')->all());
        $this->assertSame('angielski', Language::query()->where('code', 'en')->value('name'));
        $this->assertSame('English', Language::query()->where('code', 'en')->value('native_name'));
        $this->assertGreaterThan(200, Country::query()->count());
    }

    public function test_countries_and_languages_get_flag_icons(): void
    {
        $this->assertSame('pl', Country::query()->where('alpha_code_2', 'PL')->value('flag_icon'));
        $this->assertSame(['cs' => 'cz', 'el' => 'gr', 'en' => 'gb', 'pl' => 'pl', 'sv' => 'se'], Language::query()->whereIn('code', ['cs', 'el', 'en', 'pl', 'sv'])->orderBy('code')->pluck('flag_icon', 'code')->all());
        $this->assertSame(0, Language::query()->whereNull('flag_icon')->count());
    }

    public function test_new_install_enables_only_the_interface_languages(): void
    {
        $this->assertSame(['en', 'pl'], Language::query()->active()->orderBy('code')->pluck('code')->all());
    }

    public function test_languages_keep_the_flags_of_every_country_they_are_official_in(): void
    {
        $english = Language::query()->where('code', 'en')->sole();
        $german = Language::query()->where('code', 'de')->sole();

        $this->assertSame('gb', $english->flagIcons()[0]);
        $this->assertContains('us', $english->flagIcons());
        $this->assertSame(['de', 'at', 'ch'], array_slice(array_values(array_intersect($german->flagIcons(), ['de', 'at', 'ch'])), 0, 3));
        $this->assertSame($german->flagIcons(), $german->json_attributes[Language::FLAG_ICONS] ?? null);
    }

    public function test_countries_have_iso_codes_and_territory(): void
    {
        $poland = Country::query()->where('alpha_code_2', 'PL')->sole();
        $zloty = Currency::query()->where('alpha_code_3', 'PLN')->sole();

        $this->assertSame(['POL', '616', Continent::Europe, '151'], [$poland->alpha_code_3, $poland->numeric_code, $poland->continent, $poland->region_code]);
        $this->assertGreaterThan(30_000_000, $poland->population);
        $this->assertSame('004', Country::query()->where('alpha_code_2', 'AF')->value('numeric_code'));
        $this->assertSame(Continent::SouthAmerica, Country::query()->where('alpha_code_2', 'BR')->sole()->continent);
        $this->assertSame('985', $zloty->numeric_code);
        $this->assertSame(0, Country::query()->whereNull('continent')->count());
    }

    public function test_country_lists_are_grouped_by_continent_with_the_application_country_first(): void
    {
        $continents = Country::query()->orderBy('sort_order')->orderBy('name')->get()->map(static fn(Country $country): ?Continent => $country->continent)->unique()->values()->all();

        $this->assertSame(array_values(array_filter(LocalizationData::CONTINENT_ORDER, static fn(Continent $continent): bool => in_array($continent, $continents, true))), $continents);
        $this->assertSame([Continent::Europe, Continent::Asia], array_slice($continents, 0, 2));
        $this->assertSame('PL', Country::query()->orderBy('sort_order')->value('alpha_code_2'));
    }

    public function test_seeded_entries_use_seed_id_slots(): void
    {
        foreach ([Country::class, Currency::class, Language::class, TaxRate::class] as $model) {
            $this->assertLessThanOrEqual(IdSlots::SEED_MAX, $model::query()->max('id'), $model);
        }

        $country = (new Country())->forceFill(['name' => 'Testland', 'alpha_code_2' => 'QZ']);
        $country->save();

        $this->assertSame(IdSlots::FIRST_USER_ID, $country->id);
    }

    public function test_polish_vat_rates_are_complete(): void
    {
        $poland = Country::query()->where('alpha_code_2', 'PL')->sole();
        $rates = TaxRate::query()->where('country_id', $poland->id)->orderBy('id')->get();

        $this->assertSame(['23%', '8%', '5%', '0%', 'zw'], $rates->pluck('name')->all());
        $this->assertSame([TaxRateType::Standard, TaxRateType::Reduced, TaxRateType::Reduced, TaxRateType::Zero, TaxRateType::Exempt], $rates->pluck('type')->all());
        $this->assertNull($rates->last()?->rate);
        $this->assertSame('exempt', $rates->last()?->translatedName('en'));
        $this->assertSame(['PL'], Country::query()->whereIn('id', TaxRate::query()->select('country_id'))->pluck('alpha_code_2')->all(), 'Stawki VAT ma tylko kraj aplikacji.');
    }

    public function test_polish_days_off_are_seeded_and_manual_entries_are_kept(): void
    {
        $poland = Country::query()->where('alpha_code_2', 'PL')->sole();
        $holidays = Holiday::query()->where('country_id', $poland->id);

        $this->assertSame(28, (clone $holidays)->count());
        $this->assertSame(['2026', '2027'], (clone $holidays)->get()->map(static fn(Holiday $holiday): string => $holiday->holiday_date->format('Y'))->unique()->values()->all());
        $independence = (clone $holidays)->whereDate('holiday_date', '2026-11-11')->sole();
        $this->assertSame('Narodowe Święto Niepodległości', $independence->name);
        $this->assertSame('Independence Day', $independence->translatedName('en'));
        $this->assertSame(HolidaySource::Nager, $independence->source);
        $this->assertLessThan(IdSlots::FIRST_USER_ID, $independence->id);

        $independence->forceFill(['name' => 'Święto (ręcznie)', 'source' => HolidaySource::Manual])->save();
        $this->seedLocalization();

        $this->assertSame(28, (clone $holidays)->count());
        $this->assertSame('Święto (ręcznie)', $independence->refresh()->name);
    }

    public function test_nbp_exchange_rates_are_seeded_and_manual_rates_are_kept(): void
    {
        $this->assertSame(24, ExchangeRate::query()->count());
        $this->assertSame(['CHF', 'EUR', 'GBP', 'USD'], ExchangeRate::query()->with('currency')->get()->map(static fn(ExchangeRate $rate): string => $rate->currency->alpha_code_3)->unique()->sort()->values()->all());

        $eur = Currency::query()->where('alpha_code_3', 'EUR')->sole();
        $rates = $this->app->make(ExchangeRatesOn::class);
        $rate = $rates->on($eur, CarbonImmutable::parse('2026-11-05'));
        $this->assertSame('4.391600', $rate?->rate);
        $this->assertSame('216/A/NBP/2026', $rate->table_number);
        $this->assertSame(ExchangeRateSource::Nbp, $rate->source);
        $this->assertLessThan(IdSlots::FIRST_USER_ID, $rate->id);
        $this->assertSame('4.418500', $rates->lastBefore($eur, CarbonImmutable::parse('2026-11-03'))?->rate, 'Po weekendzie: ostatni dzień publikacji przed datą.');

        $rate->forceFill(['rate' => '4.5', 'source' => ExchangeRateSource::Manual])->save();
        $this->seedLocalization();

        $this->assertSame(24, ExchangeRate::query()->count());
        $this->assertSame('4.500000', $rate->refresh()->rate);
    }

    public function test_running_again_changes_nothing_and_keeps_the_administrators_choices(): void
    {
        Currency::query()->where('alpha_code_3', 'EUR')->sole()->forceFill(['is_active' => false])->save();
        Language::query()->where('code', 'en')->sole()->forceFill(['flag_icon' => 'us'])->save();
        $polishReduced = TaxRate::query()->where('country_id', Country::query()->where('alpha_code_2', 'PL')->value('id'))->where('name', '8%');
        $polishReduced->sole()->delete();
        $counts = $this->counts();
        $history = DB::table(History::tableFor('locale_countries'))->count();

        $this->seedLocalization();

        $this->assertSame($counts, $this->counts());
        $this->assertSame($history, DB::table(History::tableFor('locale_countries'))->count());
        $this->assertFalse(Currency::query()->where('alpha_code_3', 'EUR')->sole()->is_active);
        $this->assertSame('us', Language::query()->where('code', 'en')->value('flag_icon'));
        $this->assertSame(0, $polishReduced->count());
    }

    private function seedLocalization(): void
    {
        $this->artisan('polygon:localization:seed');
    }

    /**
     * @return list<int>
     */
    private function counts(): array
    {
        return [Currency::query()->count(), Language::query()->count(), Country::query()->count(), TaxRate::withTrashed()->count(), Holiday::withTrashed()->count(), ExchangeRate::withTrashed()->count()];
    }
}
