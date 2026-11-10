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

namespace Tests\Feature\Zones;

use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Simplysmart\Polygon\Mail\LoginCodeMail;

/**
 * Baza testów logowania kodem i PIN-em w strefie alpha (poczta przechwytywana).
 */
abstract class SignInTestCase extends ZoneTestCase
{
    protected const string PIN = '159357';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    /**
     * @param array<string, string> $server
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function requestCode(string $email = self::EMAIL, array $server = []): TestResponse
    {
        return $this->withServerVariables($server)->post(route('alpha.auth.login.store'), ['email' => $email]);
    }

    /**
     * Ostatni kod wysłany na adres.
     */
    protected function sentCode(string $email = self::EMAIL): string
    {
        $code = null;

        Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use ($email, &$code): bool {
            if ($mail->hasTo($email)) {
                $code = $mail->code;
            }

            return $mail->hasTo($email);
        });

        return (string) $code;
    }

    /**
     * Kod, który na pewno nie jest wysłanym.
     */
    protected function wrongCode(string $code): string
    {
        return str_pad((string) (((int) $code + 1) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function submitCode(string $code): TestResponse
    {
        return $this->post(route('alpha.auth.code.store'), ['code' => $code]);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function submitPin(string $pin): TestResponse
    {
        return $this->post(route('alpha.auth.pin.store'), ['pin' => $pin]);
    }
}
