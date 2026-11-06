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

use LogicException;
use Simplysmart\Polygon\Mail\LoginCodeMail;
use Simplysmart\Polygon\Zones\ZoneServiceProvider;

/**
 * E-maile stref: polygon nie ma widoków maili, więc mail renderuje szablon z `mail.views` strefy (z `$mailZone`
 * i danymi maila) także poza żądaniem strefy, a strefa bez `mail.views` nie wyśle maila.
 */
class ZoneMailTest extends ZoneTestCase
{
    public function test_mail_renders_the_zone_template_outside_a_request_zone(): void
    {
        $html = (new LoginCodeMail('alpha', 'Anna Nowak', '123456', 10))->render();

        $this->assertStringContainsString('data-template="login-code" data-zone="alpha"', $html);
        $this->assertStringContainsString('<p data-code>123456</p>', $html);
        $this->assertStringContainsString('Anna Nowak', $html);
    }

    public function test_mail_subject_comes_from_the_zone_text_cascade(): void
    {
        $mail = new LoginCodeMail('alpha', 'Anna Nowak', '123456', 10);

        $mail->assertHasSubject(text_email('login_code.subject'));
    }

    public function test_zone_without_mail_views_cannot_send_mails(): void
    {
        $this->registerZone(new class ($this->app) extends ZoneServiceProvider {
            protected function routeNamePrefix(): string
            {
                return 'silent';
            }

            protected function zoneConfig(): array
            {
                return ['name' => 'silent', 'model' => \Tests\Fixtures\Zones\AlphaAccount::class];
            }

            protected function routesPath(): string
            {
                return __DIR__ . '/missing';
            }
        });

        $this->expectException(LogicException::class);

        (new LoginCodeMail('silent', 'Anna Nowak', '123456', 10))->render();
    }
}
