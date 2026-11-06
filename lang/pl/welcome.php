<?php

declare(strict_types=1);

/*
| Strona startowa aplikacji (resources/views/welcome.blade.php, trasa `/`).
*/

return [

    'title_before' => 'System',
    'title_after' => 'został pomyślnie zainstalowany',
    'lead' => 'Fundament Twojej nowej aplikacji biznesowej, zbudowany na Laravelu, jest w pełni gotowy do pracy. Wszystko, czego potrzebujesz na start, działa od pierwszego uruchomienia.',
    'inside' => 'Co zyskujesz na starcie?',
    'built_on' => 'Zbudowane na',
    'version' => 'Wersja :version',

    'features' => [
        'zones' => [
            'title' => 'Wydzielone strefy systemowe',
            'description' => 'Odrębne logowanie, dedykowane sesje i zaawansowane zarządzanie uprawnieniami.',
        ],
        'sign_in' => [
            'title' => 'Bezpieczne uwierzytelnianie',
            'description' => 'Nowoczesne logowanie przy użyciu kodu z e-maila oraz kodu PIN.',
        ],
        'dictionaries' => [
            'title' => 'Gotowe słowniki globalne',
            'description' => 'Wbudowane bazy krajów, języków, walut oraz aktualnych stawek VAT.',
        ],
        'history' => [
            'title' => 'Pełny audyt zmian (Audit Trail)',
            'description' => 'Automatyczna historia zmian we wszystkich źródłach danych: kto, co i kiedy zmienił.',
        ],
        'locale' => [
            'title' => 'Pełna lokalizacja (i18n)',
            'description' => 'Precyzyjne formatowanie tekstów, liczb i dat dostosowane do języka użytkownika.',
        ],
        'standards' => [
            'title' => 'Standardy i dokumentacja',
            'description' => 'Spójne standardy kodu sprawdzane automatycznie, aktualna dokumentacja i kontrolowane wydania każdego pakietu.',
        ],
    ],

];
