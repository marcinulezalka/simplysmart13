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

namespace Tests\Fixtures\Fakturownia;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Odpowiedzi API Fakturowni do testów (`responses/<nazwa>.json`), odtworzone z dokumentacji API — kształt pól jak
 * w odpowiedziach konta, klientów, faktur i błędów (401, 422, 429, 500). Jedno miejsce, z którego testy biorą odpowiedzi
 * dla `Http::fake()`: data() — treść jako tablica (z podmianą pól), response() — odpowiedź HTTP z kodem i nagłówkami.
 */
final class FakturowniaStubs
{
    /** Kody HTTP odpowiedzi błędów według nazwy stuba. */
    private const array STATUSES = [
        'error.401' => 401,
        'error.422' => 422,
        'error.429' => 429,
        'error.500' => 500,
        'client.tax-no-taken' => 422,
        'invoice.oid-taken' => 422,
    ];

    /**
     * @param array<string, mixed> $overrides Pola podmienione w obiekcie odpowiedzi (nie w liście).
     *
     * @return array<array-key, mixed>
     */
    public static function data(string $name, array $overrides = []): array
    {
        $path = __DIR__ . '/responses/' . $name . '.json';
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($data)) {
            throw new RuntimeException('Brak stuba odpowiedzi Fakturowni: ' . $name);
        }

        return [...$data, ...$overrides];
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<string, string> $headers
     */
    public static function response(string $name, array $overrides = [], array $headers = []): PromiseInterface
    {
        return Http::response(self::data($name, $overrides), self::STATUSES[$name] ?? 200, $headers);
    }
}
