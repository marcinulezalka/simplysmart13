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

namespace Tests\Feature\Notifications;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Simplysmart\Polygon\Http\StatusMessage;
use Simplysmart\Polygon\Notifications\FlashNotifications;
use Simplysmart\Polygon\Notifications\NotificationKind;
use Tests\TestCase;

/**
 * Powiadomienia bieżącego żądania (Notifications\FlashNotifications): komunikat statusu (Http\StatusMessage, sesja
 * flash) i błędy walidacji jako jedna lista Notification, w stałej kolejności (status, potem błędy) — jedyne źródło
 * toastu i historii dzwonka strefy (resources/js/notifications.js, partials.messages strefy).
 */
class FlashNotificationsTest extends TestCase
{
    public function test_it_is_empty_without_a_status_or_validation_errors(): void
    {
        $notifications = (new FlashNotifications())->current(new ViewErrorBag());

        $this->assertSame([], $notifications);
        $this->assertSame('[]', (new FlashNotifications())->toJson($notifications));
    }

    public function test_it_combines_the_status_and_the_validation_errors_in_order(): void
    {
        Route::middleware('web')->get('/_flash-notifications', static function () {
            session()->flash(StatusMessage::KEY, 'Zapisano.');

            $errors = new ViewErrorBag();
            $errors->put('default', new MessageBag(['field' => ['Pole jest wymagane.', 'Pole jest za długie.']]));

            $notifications = (new FlashNotifications())->current($errors);

            return response()->json(array_map(static fn($notification): array => [$notification->kind->value, $notification->text], $notifications));
        });

        $this->get('/_flash-notifications')->assertOk()->assertExactJson([
            [NotificationKind::Status->value, 'Zapisano.'],
            [NotificationKind::Danger->value, "Pole jest wymagane.\nPole jest za długie."],
        ]);
    }

    public function test_to_json_keeps_polish_characters_readable_and_escapes_html(): void
    {
        $errors = new ViewErrorBag();
        $errors->put('default', new MessageBag(['field' => ['Zły znak: <script>alert(1)</script> & "cytat" i żółw bez uciekania unicode.']]));

        $notifications = (new FlashNotifications())->current($errors);
        $json = (new FlashNotifications())->toJson($notifications);

        $this->assertStringNotContainsString('<script>', $json);
        $this->assertStringContainsString('żółw', $json);
        $this->assertSame(
            [['text' => 'Zły znak: <script>alert(1)</script> & "cytat" i żółw bez uciekania unicode.', 'kind' => 'danger']],
            json_decode($json, true),
        );
    }
}
