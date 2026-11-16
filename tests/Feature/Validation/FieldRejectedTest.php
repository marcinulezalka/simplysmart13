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

namespace Tests\Feature\Validation;

use Illuminate\Support\Facades\Route;
use Simplysmart\Polygon\Validation\FieldRejected;
use Tests\TestCase;

/**
 * Odrzucenie reguły domeny z paczki polygon (Validation\FieldRejected) nieprzechwycone w kontrolerze: formularz wraca
 * z błędem pod polem, żądanie JSON dostaje 422 z błędem pola.
 */
class FieldRejectedTest extends TestCase
{
    private const string URL = '/_test/field-rejected';

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('web')->post(self::URL, static function (): never {
            throw new class ('name', 'Nazwa jest już zajęta.') extends FieldRejected {};
        });
    }

    public function test_a_form_returns_with_the_error_under_the_field(): void
    {
        $this->from('/form')->post(self::URL, ['name' => 'X'])
            ->assertRedirect('/form')
            ->assertSessionHasErrors(['name' => 'Nazwa jest już zajęta.']);
    }

    public function test_a_json_request_gets_422_with_the_field_error(): void
    {
        $this->postJson(self::URL, ['name' => 'X'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'Nazwa jest już zajęta.']);
    }
}
