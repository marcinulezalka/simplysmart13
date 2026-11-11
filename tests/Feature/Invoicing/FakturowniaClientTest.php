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

namespace Tests\Feature\Invoicing;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Simplysmart\Polygon\Contractors\ContractorData;
use Simplysmart\Polygon\Contractors\SaveContractor;
use Simplysmart\Polygon\Database\ColumnNames;
use Simplysmart\Polygon\Database\History;
use Simplysmart\Polygon\Enums\InvoiceKind;
use Simplysmart\Polygon\Enums\InvoiceStatus;
use Simplysmart\Polygon\Enums\InvoiceTax;
use Simplysmart\Polygon\Enums\InvoicingProvider;
use Simplysmart\Polygon\Enums\PaymentMethod;
use Simplysmart\Polygon\Enums\TaxRateType;
use Simplysmart\Polygon\Integrations\Fakturownia\ClientMapper;
use Simplysmart\Polygon\Integrations\Fakturownia\FakturowniaClient;
use Simplysmart\Polygon\Integrations\Fakturownia\FakturowniaClients;
use Simplysmart\Polygon\Integrations\Fakturownia\FakturowniaFailed;
use Simplysmart\Polygon\Integrations\Fakturownia\InvoiceMapper;
use Simplysmart\Polygon\Integrations\Fakturownia\TaxRateCode;
use Simplysmart\Polygon\Models\Dictionaries\TaxRate;
use Simplysmart\Polygon\Models\Invoice;
use Simplysmart\Polygon\Models\InvoiceItem;
use Simplysmart\Polygon\Models\InvoicingAccount;
use Simplysmart\Polygon\Models\InvoicingContractorLink;
use Simplysmart\Polygon\Models\Organization;
use Tests\Fixtures\Fakturownia\FakturowniaStubs;
use Tests\TestCase;

/**
 * Schemat faktur i klient API Fakturowni z polygonu (docs/baza-danych.md §21) na stubach odpowiedzi
 * (Tests\Fixtures\Fakturownia\FakturowniaStubs): tabele z historią i szyfrowany token konta, adres i token żądań,
 * stronicowanie klientów, błędy 401 i 422 jako FakturowniaFailed, ponawianie odczytów przy 429 i brak ponawiania
 * zapisów po błędzie serwera, tłumaczenie kontrahenta, stawek VAT i faktury na format API.
 */
class FakturowniaClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private const string TOKEN = 'secret-api-token';

    private const string NIP = '5252344078';

    public function test_invoicing_tables_have_history_and_domain_statuses(): void
    {
        foreach ([InvoicingAccount::TABLE, InvoicingContractorLink::TABLE, Invoice::TABLE, InvoiceItem::TABLE] as $table) {
            $this->assertTrue(Schema::hasTable(History::tableFor($table)), $table);
        }

        $invoice = new Invoice();
        $this->assertSame([InvoiceStatus::Draft, InvoiceKind::Vat, PaymentMethod::Transfer, false], [$invoice->status, $invoice->kind, $invoice->payment_method, $invoice->usesRecordStatus()]);
        $this->assertSame(['robocza', 'faktura VAT', 'przelew', 'zwolniona (zw)', 'Fakturownia'], [InvoiceStatus::Draft->label(), InvoiceKind::Vat->label(), PaymentMethod::Transfer->label(), InvoiceTax::Exempt->label(), InvoicingProvider::Fakturownia->label()]);
        $this->assertSame([true, true, false, false], [InvoiceStatus::Queued->canSend(), InvoiceStatus::Failed->canSend(), InvoiceStatus::Sent->canSend(), InvoiceStatus::Draft->canSend()]);
    }

    public function test_the_account_token_is_encrypted_in_the_database_and_hidden_in_arrays(): void
    {
        $account = $this->account();

        $stored = DB::table(InvoicingAccount::TABLE)->where(ColumnNames::ID, $account->id)->value(InvoicingAccount::API_TOKEN);
        $this->assertIsString($stored);
        $this->assertStringNotContainsString(self::TOKEN, $stored);
        $this->assertSame(self::TOKEN, $account->refresh()->api_token);
        $this->assertArrayNotHasKey(InvoicingAccount::API_TOKEN, $account->toArray());
    }

    public function test_requests_go_to_the_account_subdomain_with_the_token(): void
    {
        Http::fake(['nzoz-test.fakturownia.pl/account.json*' => FakturowniaStubs::response('account.ok')]);

        $this->assertSame('nzoz-test', $this->client()->account()['prefix'] ?? null);
        Http::assertSent(static fn(Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://nzoz-test.fakturownia.pl/account.json')
            && $request['api_token'] === self::TOKEN);
    }

    public function test_the_subdomain_is_normalised_and_cannot_leave_the_fakturownia_domain(): void
    {
        $this->assertSame('nzoz-test', FakturowniaClients::subdomain(' https://NZOZ-Test.fakturownia.pl/invoices '));
        $this->assertSame('nzoz-test', FakturowniaClients::subdomain('nzoz-test'));

        foreach (['evil.example.com/x', 'a.b', 'nzoz_test', '', '-nzoz'] as $subdomain) {
            try {
                $this->app->make(FakturowniaClients::class)->with($subdomain, self::TOKEN);
                $this->fail('Subdomena „' . $subdomain . '” powinna być odrzucona.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_clients_are_read_page_by_page_and_found_by_tax_number_or_external_id(): void
    {
        Http::fake([
            '*clients.json?*&page=1&*' => FakturowniaStubs::response('clients.page-1'),
            '*clients.json?*&page=2&*' => FakturowniaStubs::response('clients.page-2'),
            '*clients.json?*&page=3&*' => FakturowniaStubs::response('clients.empty'),
            '*clients.json?*tax_no=' . self::NIP . '*' => Http::response([FakturowniaStubs::data('client.updated')]),
            '*clients.json?*external_id=*' => FakturowniaStubs::response('clients.empty'),
        ]);
        $client = $this->client();

        $this->assertSame([4, 1, 0], [count($client->clients(1)), count($client->clients(2)), count($client->clients(3))]);
        $this->assertSame(101, $client->clientByTaxNo(self::NIP)['id'] ?? null);
        $this->assertNull($client->clientByExternalId('missing'));
        Http::assertSent(static fn(Request $request): bool => str_contains($request->url(), 'per_page=' . FakturowniaClient::PER_PAGE));
    }

    public function test_rejections_carry_the_status_and_the_api_message_without_the_token(): void
    {
        Http::fake([
            '*account.json*' => FakturowniaStubs::response('error.401'),
            '*invoices.json' => FakturowniaStubs::response('error.422'),
        ]);

        $unauthorized = $this->failure(fn() => $this->client()->account());
        $this->assertSame([401, true, false], [$unauthorized->status, $unauthorized->unauthorized(), $unauthorized->retryable()]);

        $invalid = $this->failure(fn() => $this->client()->createInvoice(['kind' => 'vat']));
        $this->assertSame(422, $invalid->status);
        $this->assertSame('buyer_name: nie może być puste; positions: nie może być puste', $invalid->getMessage());
        $this->assertStringNotContainsString(self::TOKEN, $unauthorized->getMessage() . $invalid->getMessage());
    }

    public function test_the_token_echoed_back_by_the_api_is_removed_from_the_error_message(): void
    {
        Http::fake(['*account.json*' => Http::response(['code' => 'error', 'message' => 'Musisz być zalogowany: https://nzoz-test.fakturownia.pl/account.json?api_token=' . self::TOKEN], 401)]);

        $failed = $this->failure(fn() => $this->client()->account());

        $this->assertSame('Musisz być zalogowany: https://nzoz-test.fakturownia.pl/account.json?api_token=***', $failed->getMessage());
    }

    public function test_reads_are_retried_on_rate_limit_and_writes_are_not_retried_after_a_server_error(): void
    {
        Sleep::fake();
        Http::fake([
            '*account.json*' => Http::sequence()->pushResponse(FakturowniaStubs::response('error.429', headers: ['Retry-After' => '1']))->pushResponse(FakturowniaStubs::response('account.ok')),
            '*clients.json' => Http::sequence()->pushResponse(FakturowniaStubs::response('error.500'))->pushResponse(FakturowniaStubs::response('client.created')),
        ]);

        $this->assertSame('nzoz-test', $this->client()->account()['prefix'] ?? null);

        $failed = $this->failure(fn() => $this->client()->createClient(['name' => 'Firma']));
        $this->assertSame([500, true], [$failed->status, $failed->retryable()]);
        Http::assertSentCount(3);
    }

    public function test_a_contractor_maps_to_a_client_and_back(): void
    {
        $contractor = $this->app->make(SaveContractor::class)->save($this->organization('NZOZ Test'), new ContractorData('Firma Budowlana Sp. z o.o.', 'Budowlana', self::NIP, '123456785', '0000123456', 'ul. Prosta 1', '00-001', 'Warszawa', 'pl', 'biuro@budowlana.example', '+48 600 100 200', 'Uwagi'));

        $payload = ClientMapper::toPayload($contractor);
        $this->assertSame(['Firma Budowlana Sp. z o.o.', 'Budowlana', self::NIP, '123456785', 'PL', $contractor->token, true], [$payload['name'], $payload['shortcut'], $payload['tax_no'], $payload['register_number'], $payload['country'], $payload['external_id'], $payload['company']]);

        $clients = FakturowniaStubs::data('clients.page-1');
        $this->assertIsArray($clients[0]);
        $this->assertIsArray($clients[3]);
        $data = ClientMapper::toData($clients[0], $contractor);
        $this->assertSame(['Firma Budowlana Sp. z o.o.', 'Budowlana', self::NIP, '123456785', '0000123456', 'PL', 'biuro@budowlana.example'], [$data->name, $data->shortName, $data->nip, $data->regon, $data->krs, $data->countryCode, $data->email]);
        $this->assertSame('101', ClientMapper::id($clients[0]));
        $this->assertSame(CarbonImmutable::parse('2026-11-01 10:00:00', 'Europe/Warsaw')->getTimestamp(), ClientMapper::updatedAt($clients[0])?->getTimestamp());
        $this->assertNull(ClientMapper::nip($clients[3]), 'Zagraniczny numer podatkowy nie jest polskim NIP-em.');
        $this->assertSame('DE', ClientMapper::toData($clients[3])->countryCode);
    }

    public function test_a_client_with_fields_longer_than_the_columns_is_saved_with_names_cut_and_long_identifiers_dropped(): void
    {
        $longName = '" KARTIS" S.C. ANETA KUBAS WSPÓLNIK SPÓŁKI CYWILNEJ , Hurtownia " Kwartet" Aneta Kubas';
        $data = ClientMapper::toData(['id' => 7, 'name' => str_repeat('Ż', 300), 'shortcut' => $longName, 'tax_no' => self::NIP, 'register_number' => str_repeat('1', 15), 'post_code' => str_repeat('9', 17), 'phone' => str_repeat('5', 33), 'mobile_phone' => '600 100 200', 'email' => str_repeat('a', 251) . '@x.pl']);

        $this->assertSame(str_repeat('Ż', 255), $data->name);
        $this->assertSame(rtrim(mb_substr($longName, 0, 64)), $data->shortName);
        $this->assertSame([null, null, '600 100 200', null], [$data->regon, $data->postalCode, $data->phone, $data->email]);

        $contractor = $this->app->make(SaveContractor::class)->save($this->organization('NZOZ Test'), $data);
        $this->assertSame($data->shortName, $contractor->refresh()->short_name);
    }

    /**
     * @return array<string, array{0: InvoiceTax, 1: string|null, 2: int|float|string}>
     */
    public static function taxCodes(): array
    {
        return [
            'podstawowa 23%' => [InvoiceTax::Rate, '23.00', 23],
            'obniżona 8%' => [InvoiceTax::Rate, '8.00', 8],
            'obniżona 5%' => [InvoiceTax::Rate, '5.00', 5],
            'zerowa' => [InvoiceTax::Rate, '0.00', 0],
            'ułamkowa' => [InvoiceTax::Rate, '7.50', 7.5],
            'zwolniona' => [InvoiceTax::Exempt, null, 'zw'],
            'nie podlega' => [InvoiceTax::NotApplicable, null, 'np'],
        ];
    }

    #[DataProvider('taxCodes')]
    public function test_tax_rates_translate_to_the_fakturownia_format(InvoiceTax $tax, ?string $percent, int|float|string $expected): void
    {
        $this->assertSame($expected, TaxRateCode::of($tax, $percent));
    }

    public function test_dictionary_rates_translate_and_a_rate_without_a_percentage_is_refused(): void
    {
        foreach (TaxRateType::cases() as $type) {
            $rate = new TaxRate();
            $rate->forceFill(['type' => $type->value, 'rate' => $type->hasPercentage() ? '8.00' : null]);
            [$tax, $percent] = TaxRateCode::taxOf($rate);

            $this->assertSame($type === TaxRateType::Exempt ? 'zw' : 8, TaxRateCode::of($tax, $percent), $type->value);
        }

        foreach ([null, 'abc', '-1', '101'] as $percent) {
            try {
                TaxRateCode::of(InvoiceTax::Rate, $percent);
                $this->fail('Stawka z procentem „' . var_export($percent, true) . '” powinna być odrzucona.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_invoice_maps_to_the_api_payload_with_its_token_as_the_unique_oid(): void
    {
        $organization = $this->organization('NZOZ Test');
        $contractor = $this->app->make(SaveContractor::class)->save($organization, new ContractorData('Firma Budowlana', nip: self::NIP));
        $invoice = new Invoice();
        $invoice->forceFill([
            Organization::FOREIGN_KEY => $organization->id, 'contractor_id' => $contractor->id, ColumnNames::NAME => 'ROBOCZA/1',
            Invoice::ISSUE_DATE => '2026-11-10', Invoice::SELL_DATE => '2026-11-09', Invoice::PAYMENT_DUE => '2026-11-24', Invoice::CURRENCY_CODE => 'PLN',
            Invoice::SELLER_NAME => 'NZOZ Test', Invoice::SELLER_NIP => '5261040828', Invoice::BUYER_NAME => 'Firma Budowlana', Invoice::BUYER_NIP => self::NIP, Invoice::BUYER_CITY => 'Warszawa',
        ])->save();

        foreach ([['Badanie okresowe', '2.000', 'usł.', '100.00', InvoiceTax::Exempt, null, '200.00', '0.00', '200.00'], ['Druk', '1.000', 'szt.', '10.00', InvoiceTax::Rate, '23.00', '10.00', '2.30', '12.30']] as $index => [$name, $quantity, $unit, $price, $tax, $percent, $net, $vat, $gross]) {
            $item = new InvoiceItem();
            $item->forceFill([Invoice::FOREIGN_KEY => $invoice->id, ColumnNames::NAME => $name, ColumnNames::SORT_ORDER => $index, InvoiceItem::QUANTITY => $quantity, InvoiceItem::UNIT => $unit, InvoiceItem::UNIT_PRICE_NET => $price, InvoiceItem::TAX => $tax, InvoiceItem::TAX_PERCENT => $percent, InvoiceItem::NET => $net, InvoiceItem::VAT => $vat, InvoiceItem::GROSS => $gross])->save();
        }

        $payload = InvoiceMapper::toPayload($invoice->refresh(), '101', null);

        $this->assertSame(['vat', null, '2026-11-10', '2026-11-09', '2026-11-24', 'transfer', 'PLN', $invoice->token, 'yes', 101], [$payload['kind'], $payload['number'], $payload['issue_date'], $payload['sell_date'], $payload['payment_to'], $payload['payment_type'], $payload['currency'], $payload['oid'], $payload['oid_unique'], $payload['client_id']]);
        $this->assertArrayNotHasKey('department_id', $payload);
        $this->assertSame(['NZOZ Test', '5261040828', 'Firma Budowlana', self::NIP, 'Warszawa'], [$payload['seller_name'], $payload['seller_tax_no'], $payload['buyer_name'], $payload['buyer_tax_no'], $payload['buyer_city']]);
        $this->assertSame([
            ['name' => 'Badanie okresowe', 'quantity' => 2.0, 'quantity_unit' => 'usł.', 'price_net' => 100.0, 'total_price_net' => 200.0, 'total_price_gross' => 200.0, 'tax' => 'zw'],
            ['name' => 'Druk', 'quantity' => 1.0, 'quantity_unit' => 'szt.', 'price_net' => 10.0, 'total_price_net' => 10.0, 'total_price_gross' => 12.3, 'tax' => 23],
        ], $payload['positions']);

        $response = FakturowniaStubs::data('invoice.created');
        $this->assertSame(['9001', 'aBcD1234invoiceToken', '12/11/2026', 'https://nzoz-test.fakturownia.pl/invoice/aBcD1234invoiceToken'], [InvoiceMapper::externalId($response), InvoiceMapper::externalToken($response), InvoiceMapper::number($response), InvoiceMapper::url($response, 'nzoz-test')]);
    }

    public function test_an_invoice_is_found_by_its_oid_only_on_an_exact_match(): void
    {
        Http::fake([
            '*invoices.json?*oid=OID*' => FakturowniaStubs::response('invoices.by-oid.found'),
            '*invoices.json?*oid=OTHER*' => FakturowniaStubs::response('invoices.by-oid.found'),
            '*invoices/9001.pdf*' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $this->assertSame(9001, $this->client()->invoiceByOid('OID')['id'] ?? null);
        $this->assertNull($this->client()->invoiceByOid('OTHER'), 'Lista z innym oid to nie ta faktura.');
        $this->assertSame('%PDF-1.4', $this->client()->invoicePdf('9001'));
    }

    private function account(): InvoicingAccount
    {
        $account = new InvoicingAccount();
        $account->forceFill([Organization::FOREIGN_KEY => $this->organization('NZOZ Test')->id, ColumnNames::NAME => 'Fakturownia', InvoicingAccount::SUBDOMAIN => 'nzoz-test', InvoicingAccount::API_TOKEN => self::TOKEN])->save();

        return $account;
    }

    private function client(): FakturowniaClient
    {
        return $this->app->make(FakturowniaClients::class)->with('nzoz-test', self::TOKEN);
    }

    private function failure(callable $action): FakturowniaFailed
    {
        try {
            $action();
        } catch (FakturowniaFailed $failed) {
            return $failed;
        }

        $this->fail('Oczekiwano odmowy Fakturowni.');
    }
}
