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

use Simplysmart\Polygon\Http\FileResponse;
use Tests\TestCase;

/**
 * Odpowiedź z plikiem z paczki polygon (Http\FileResponse): nagłówek Content-Disposition według RFC 6266 z nazwą
 * UTF-8 i zastępczą ASCII, nazwa bez znaków sterujących, ukośników i cudzysłowów, bez cache i zgadywania typu.
 */
class FileResponseTest extends TestCase
{
    public function test_an_inline_file_keeps_the_full_name_in_utf8_and_an_ascii_fallback(): void
    {
        $response = FileResponse::inline('%PDF', 'application/pdf', 'Zaświadczenie Łódź.pdf');

        $this->assertSame('%PDF', $response->getContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame("inline; filename=\"Zaswiadczenie Lodz.pdf\"; filename*=utf-8''Za%C5%9Bwiadczenie%20%C5%81%C3%B3d%C5%BA.pdf", $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_control_characters_slashes_and_quotes_never_reach_the_header(): void
    {
        $response = FileResponse::attachment('x', 'text/plain', "a\r\nSet-Cookie: x=1/..\\\"b\".txt");
        $header = (string) $response->headers->get('Content-Disposition');

        $this->assertSame("attachment; filename=\"a-Set-Cookie- x-1-..-b-.txt\"; filename*=utf-8''a-Set-Cookie%3A%20x%3D1-..-b-.txt", $header);
        $this->assertDoesNotMatchRegularExpression('/[\r\n]/', $header);
    }

    public function test_an_empty_name_falls_back_to_a_default(): void
    {
        $this->assertSame('file', FileResponse::safeName(" \n "));
        $this->assertSame('inline; filename=file', FileResponse::inline('', 'text/plain', '')->headers->get('Content-Disposition'));
    }
}
