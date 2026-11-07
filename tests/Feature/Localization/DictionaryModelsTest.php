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

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Simplysmart\Polygon\Enums\TaxRateType;
use Simplysmart\Polygon\Localization\PackageText;
use Simplysmart\Polygon\Models\Dictionaries\ExchangeRate;
use Simplysmart\Polygon\Models\Dictionaries\Holiday;
use Simplysmart\Polygon\Models\Dictionaries\MeasureUnit;
use Simplysmart\Polygon\Models\Organization;
use Simplysmart\Polygon\Models\Contractor;
use Simplysmart\Polygon\Models\ContractorContact;
use Simplysmart\Polygon\Models\ContractorContract;
use Simplysmart\Polygon\Models\OrganizationMember;
use Simplysmart\Polygon\Models\OrganizationResource;
use Simplysmart\Polygon\Models\OrganizationUnitType;
use Simplysmart\Polygon\Models\Permissions\Permission;
use Simplysmart\Polygon\Models\Permissions\Role;
use Simplysmart\Polygon\Models\PolygonAccount;
use Simplysmart\Polygon\Models\User;
use Simplysmart\Polygon\Models\Dictionaries\Country;
use Simplysmart\Polygon\Models\Dictionaries\CountryLanguage;
use Simplysmart\Polygon\Models\Dictionaries\Currency;
use Simplysmart\Polygon\Models\Dictionaries\Language;
use Simplysmart\Polygon\Models\Dictionaries\TaxRate;
use Tests\TestCase;

/**
 * Modele paczki polygon (słowniki, uprawnienia, konta, organizacje): każda kolumna ma cast i etykietę PackageText::data()
 * w pl i en; modele słowników: checksum nowego
 * rekordu jest taka sama jak po odczycie z bazy, relacje działają w obie strony.
 */
class DictionaryModelsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{class-string<Model>}>
     */
    public static function models(): array
    {
        return [
            'currency' => [Currency::class],
            'language' => [Language::class],
            'country' => [Country::class],
            'country language' => [CountryLanguage::class],
            'tax rate' => [TaxRate::class],
            'exchange rate' => [ExchangeRate::class],
            'holiday' => [Holiday::class],
            'measure unit' => [MeasureUnit::class],
            'role' => [Role::class],
            'permission' => [Permission::class],
            'user' => [User::class],
            'polygon account' => [PolygonAccount::class],
            'organization' => [Organization::class],
            'organization unit type' => [OrganizationUnitType::class],
            'organization member' => [OrganizationMember::class],
            'organization resource' => [OrganizationResource::class],
            'contractor' => [Contractor::class],
            'contractor contact' => [ContractorContact::class],
            'contractor contract' => [ContractorContract::class],
        ];
    }

    /**
     * @param class-string<Model> $model
     */
    #[DataProvider('models')]
    public function test_every_column_has_a_cast(string $model): void
    {
        $instance = new $model();

        $this->assertSame([], array_values(array_diff(Schema::getColumnListing($instance->getTable()), array_keys($instance->getCasts()))));
    }

    /**
     * @param class-string<Model> $model
     */
    #[DataProvider('models')]
    public function test_every_column_has_a_label_in_each_language(string $model): void
    {
        foreach (['pl', 'en'] as $locale) {
            $this->app->setLocale($locale);

            foreach (Schema::getColumnListing((new $model())->getTable()) as $column) {
                $this->assertStringNotContainsString('data.', PackageText::data($model, $column), "{$locale}: {$model}.{$column}");
            }
        }
    }

    public function test_checksum_of_a_new_record_matches_the_stored_one(): void
    {
        $records = [
            $this->currency(),
            (new Language())->forceFill(['name' => 'polski', 'code' => 'pl']),
            (new Country())->forceFill(['name' => 'Polska', 'alpha_code_2' => 'PL']),
            (new TaxRate())->forceFill(['name' => '23%', 'type' => TaxRateType::Standard, 'rate' => 23, 'valid_from' => '2025-01-01']),
        ];

        foreach ($records as $record) {
            $record->save();

            $this->assertSame($record->checksum, $record->refresh()->computeChecksum(), $record::class);
        }
    }

    public function test_relations_link_countries_with_currencies_languages_and_tax_rates(): void
    {
        $currency = $this->currency();
        $currency->save();
        $language = (new Language())->forceFill(['name' => 'polski', 'code' => 'pl']);
        $language->save();
        $country = (new Country())->forceFill(['name' => 'Polska', 'alpha_code_2' => 'PL', 'currency_id' => $currency->id]);
        $country->save();
        $country->languages()->sync([$language->id => [CountryLanguage::IS_PRIMARY => true]]);
        $rate = (new TaxRate())->forceFill(['name' => '23%', 'country_id' => $country->id, 'type' => TaxRateType::Standard, 'rate' => 23, 'valid_from' => '2025-01-01']);
        $rate->save();

        $this->assertTrue($country->currency?->is($currency));
        $this->assertSame(['PL'], $currency->countries->pluck('alpha_code_2')->all());
        $this->assertSame(['pl'], $country->languages()->wherePivot(CountryLanguage::IS_PRIMARY, true)->pluck('code')->all());
        $this->assertSame(['PL'], $language->countries->pluck('alpha_code_2')->all());
        $this->assertSame(['23.00'], $country->taxRates->pluck('rate')->all());
        $this->assertTrue($rate->country?->is($country));
    }

    public function test_tax_rate_type_labels_come_from_translations(): void
    {
        $this->app->setLocale('pl');
        $this->assertSame('Zwolniona (zw)', TaxRateType::Exempt->label());
        $this->assertSame(TaxRateType::SuperReduced, TaxRateType::forReducedPercentage(4.8));
        $this->assertSame(TaxRateType::Zero, TaxRateType::forReducedPercentage(0));
        $this->assertFalse(TaxRateType::Exempt->hasPercentage());
    }

    private function currency(): Currency
    {
        return (new Currency())->forceFill(['name' => 'złoty polski', 'alpha_code_3' => 'PLN']);
    }
}
