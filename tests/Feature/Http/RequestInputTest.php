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

namespace Tests\Feature\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;
use Simplysmart\Polygon\Http\RequestInput;
use Tests\TestCase;

/**
 * Odczyt pól żądania z paczki polygon (Http\RequestInput): tekst wymagany i opcjonalny przycięty, data wpisana lokalnie
 * albo w ISO, błędna data z komunikatem polygonu pod polem.
 */
class RequestInputTest extends TestCase
{
    public function test_texts_are_trimmed_and_an_empty_optional_text_is_null(): void
    {
        $request = Request::create('/', 'POST', ['name' => '  Kowalski ', 'notes' => '   ']);

        $this->assertSame('Kowalski', RequestInput::required($request, 'name'));
        $this->assertSame('Kowalski', RequestInput::text($request, 'name'));
        $this->assertNull(RequestInput::text($request, 'notes'));
    }

    public function test_dates_are_read_locally_or_in_iso_and_an_unreadable_one_is_rejected_under_its_field(): void
    {
        App::setLocale('pl');
        $request = Request::create('/', 'POST', ['born' => '09-11-1980', 'iso' => '1980-11-09', 'bad' => '31-02-1980', 'none' => '']);

        $this->assertSame('1980-11-09', RequestInput::date($request, 'born')?->toDateString());
        $this->assertSame('1980-11-09', RequestInput::date($request, 'iso')?->toDateString());
        $this->assertNull(RequestInput::date($request, 'none'));

        try {
            RequestInput::date($request, 'bad');
            $this->fail('Nieistniejąca data jest odrzucana.');
        } catch (ValidationException $rejected) {
            $this->assertSame(['bad' => ['Podaj datę w formacie DD-MM-RRRR.']], $rejected->errors());
        }
    }
}
