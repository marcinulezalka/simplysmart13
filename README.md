# simplysmart13

Aplikacja Laravel 13 na paczce `simplysmart/polygon`.

## Stos

- Laravel 13 (PHP ^8.4), baza MySQL 8 (lokalnie wg `.env`; testy na SQLite w pamięci z `phpunit.xml`)
- `spatie/laravel-permission` — role i uprawnienia (tabele i konfiguracja z `simplysmart/polygon`, bez zespołów)
- `simplysmart/polygon` (`require`) — rdzeń bazy danych: wszystkie migracje i seedery (projekt nie ma własnych `database/migrations/` ani `database/seeders/`), uprawnienia spatie (kolumny standardowe, historia zmian, pule id, tabele strefy `polygon_*`, słowniki lokalizacji z seederami; `vendor/simplysmart/polygon/docs/baza-danych.md`) oraz narzędzia wydań (`vendor/bin/version-release`) i wdrożeń (`vendor/bin/deploy`), lokalnie z `../packages/simplysmart/polygon` (repozytorium path `../packages/*/*`, symlink); na serwer trafia z repozytorium git
- Laravel Boost — wytyczne i skille agentów w lokalnym (poza repozytorium, `.gitignore`) `.claude/skills/` (konfiguracja: `boost.json`, aktualizacja skilli Boost: `php artisan boost:update`); własne skille projektu (`changelog-before-commit`, `docs-after-tests`, `solid-dry`) leżą tam bezpośrednio, poza `boost.json`, więc Boost ich nie nadpisuje ani nie usuwa

## Baza danych

```bash
php artisan migrate                      # migracje projektu i paczki polygon
php artisan db:seed                      # DatabaseSeeder z polygon: słowniki + pliki instalacji (role, konto support@simplysmart.pl)
php artisan polygon:install              # same pliki instalacji (JSON paczki + POLYGON_INSTALL_FILES projektu)
php artisan polygon:account:create jan@example.com "Jan Kowalski" --role=owner   # konto strefy polygon (polygon_accounts; PIN przy pierwszym logowaniu)
```

Język, kraj i waluta przez `.env`: `APP_LOCALE=pl`, `APP_LOCALES=pl`, `APP_COUNTRY=PL`, `APP_CURRENCY=PLN` (słowniki po polsku, stawki VAT Polski; `config/polygon.php` w paczce). Każda zmiana danych trafia do `<tabela>_history` z aktorem (użytkownik żądania `web` albo zadanie kolejki).

## Tłumaczenia

Polskie komunikaty Laravela (walidacja, logowanie, hasła, paginacja) i helpery `text_*` / `local_*` dostarcza `simplysmart/polygon` (`vendor/simplysmart/polygon/docs/tlumaczenia.md`). Szablon tłumaczeń: `php artisan polygon:translations:export en --output=en.json`, import: `php artisan polygon:translations:import en.json` (do `lang/vendor/`).

## Jakość kodu

- `composer lint` / `composer format` — Laravel Pint, preset PER + `declare(strict_types=1)` (`pint.json`, nagłówek licencji w testach: `pint.header.json`)
- `composer analyse` — PHPStan + Larastan, poziom `max` (`phpstan.neon`)
- `composer test` — PHPUnit
- `composer release -- --dry-run` (`vendor/bin/version-release` z `simplysmart/polygon`) — podgląd wydania SemVer na podstawie `CHANGELOG.md` (bez `--dry-run` zapisuje `VERSION` i `CHANGELOG.md`; MAJOR wymaga `--major-confirm`)

## Struktura

- `app/`, `config/`, `routes/`, `resources/` — aplikacja Laravel, bez modeli w `app/` (konta strefy: `Simplysmart\Polygon\Models\PolygonAccount` w `polygon_accounts`; konta frontendu: `Simplysmart\Polygon\Models\User` w `users`); `database/` jest pusty (migracje, seedery i fabryki są w `simplysmart/polygon`)
- `public/` — document root Laravela (punkt wejścia: `public/index.php`)
- `infrastructure/` — lokalny, poza repozytorium (`.gitignore`), tworzony na każdym komputerze osobno:
  - `infrastructure/ssl/` — certyfikat SSL (mkcert),
  - `infrastructure/config/` — vhost Apache i pula PHP-FPM,
  - `infrastructure/deploy/vendors/<projekt>.env` — konfiguracje wdrażanych projektów (wzór w paczce `simplysmart/polygon`: `resources/deploy/deploy-vendor.env.example`),
  - `infrastructure/deploy/vendors/<projekt>.json` — opcjonalny plik instalacji projektu (konta, role i uprawnienia stref; format: `vendor/simplysmart/polygon/docs/instalacja.md`)

## Wdrożenie

Uruchamiane na komputerze deweloperskim przez `vendor/bin/deploy` z paczki `simplysmart/polygon` (`remote.sh` trafia na serwer przez `ssh … bash -s`). Konfiguracja projektu: lokalny `infrastructure/deploy/vendors/<projekt>.env` (poza repozytorium).

Każde wdrożenie buduje świeży manifest wdrożeniowy (`composer.deploy.json` i `composer.deploy.lock`, git-ignorowane): paczki z lokalnych repozytoriów path (`../packages/*/*`) dostają repozytoria git (`origin`) zamiast katalogów. Manifest jest kopiowany do przed-produkcji i wysyłany na serwer (`scp`), a `composer install --no-dev` instaluje z niego paczki. Dlatego przed wdrożeniem wypchnij zmienione paczki, a lokalny Composer musi mieć dostęp do ich repozytoriów (token GitHub: `composer config -g github-oauth.github.com <token>`). Na serwerze musi być klon repozytorium z `.env.production` (kopiowanym przy każdym wdrożeniu jako `.env`).

Po migracjach każde wdrożenie uruchamia `php artisan polygon:install`: uprawnienia i role z plików paczek, `POLYGON_INSTALL_FILES` z `.env` oraz `infrastructure/deploy/vendors/<projekt>.json`, jeśli jest (deploy przesyła go do obu środowisk jako `storage/app/<projekt>.install.json`). Instalacja jest idempotentna. Słowników i organizacji deploy nie seeduje — przy pierwszej instalacji uruchom w każdym środowisku `php artisan db:seed --force`.

```bash
cp vendor/simplysmart/polygon/resources/deploy/deploy-vendor.env.example infrastructure/deploy/vendors/simplysmart13.env   # raz, uzupełnij
composer deploy -- simplysmart13 --dry-run          # podgląd planu (z listą paczek manifestu)
vendor/bin/composer-private --deploy --dry-run      # sam manifest: paczki z repozytoriów git
composer deploy -- simplysmart13 --with-push        # git push, przed-produkcja, potem pytanie o produkcję
composer deploy -- simplysmart13 --no-production    # tylko przed-produkcja
composer deploy -- simplysmart13 --production       # produkcja bez pytania
```

Kroki i opcje: `vendor/simplysmart/polygon/docs/deploy.md`.


## Licencja

Simplysmart Restricted Source License 1.0 — © 2026 Marcin Ulezalka.

- [Polski (wiążąca)](LICENSE.md)
- [English (translation)](LICENSE.en.md)

Użytek komercyjny wymaga wpisu w pliku atrybucji projektu — wzór w [THIRD_PARTY.md](THIRD_PARTY.md).
