# Changelog

Format oparty na [Keep a Changelog](https://keepachangelog.com/pl/1.1.0/).

## [Unreleased]

### Added

- Reset PIN-u na stronie bezpieczeństwa konta strefy (`simplysmart/polygon`): po wpisaniu obecnego PIN-u PIN zostaje usunięty, konto wylogowane ze wszystkich urządzeń, osoba dostaje potwierdzenie e-mailem, a przy następnym logowaniu kodem z e-maila osoba ustawia nowy PIN.
- Domyślna organizacja po zalogowaniu per osoba (`simplysmart/polygon`): zapisana w `json_default_settings` konta strefy — przez samą osobę w przełączniku organizacji albo w pliku instalacji (`accounts[].defaults.organization`); bez niej, jak dotąd, najstarsze członkostwo.
- Build assetów paczek (`simplysmart/polygon`): `npm run build` / `npm run dev` budują Vite każdą paczkę z jej `vite.config.js` (SCSS przez `sass`) i projekt; `php artisan polygon:assets` udostępnia build pod `public/vendor/<paczka>`, także w deployu. Tryby kolorystyczne jasny, ciemny, WCAG i WCAG duży kontrast z osobnymi `theme.scss` strefy.
- Organizacje i personel w plikach instalacji (`simplysmart/polygon`): sekcja `organizations` (drzewo, typ jednostki, dane firmy) i `organizations` w kontach stref; nowy typ jednostki „Siedziba”; `"parent": "simplysmart"` wpina organizację pod niewidoczną we frontendzie organizację hero (root operatora).
- Wdrożenie przesyła opcjonalny plik instalacji projektu `infrastructure/deploy/vendors/<projekt>.json` do przed-produkcji i na serwer, a po migracjach uruchamia typy jednostek (`polygon:organizations:seed`) i pliki instalacji (`polygon:install`).
- Role kont stref per organizacja (`simplysmart/polygon`): konto może mieć w każdej organizacji inne role (np. właściciel w jednej, lekarz w drugiej), a uprawnienia liczą się w bieżącej organizacji z przełącznika; w plikach instalacji `accounts[].organizations` jako `{"<organizacja>": ["<rola>"]}`. Hero i konta bez organizacji mają role globalne.
- Strona startowa po zalogowaniu wybierana przez strefę (`simplysmart/polygon`, klucz `home` configu strefy): np. pulpit roli konta w bieżącej organizacji; bez niej — jak dotąd pulpit strefy.

### Changed

- Wdrożenie czyści cache konfiguracji, tras i widoków zaraz po instalacji paczek, restartuje workery kolejki i może przeładować PHP-FPM poleceniem `DEPLOY_RELOAD` / `PREPRODUCTION_RELOAD` z konfiguracji projektu (`simplysmart/polygon`), żeby opcache nie serwował starego kodu.
- README opisuje system biznesowo (dla kogo, co zyskuje firma na starcie, jakość wdrożeń); opis techniczny — stos, baza danych, testy, standardy kodu i wdrożenie — przeniesiony do `CONTRIBUTING.md` i zaktualizowany (role spatie z zespołami-organizacjami, oba układy konfiguracji wdrożeń, seed typów jednostek i organizacji hero przy każdym wdrożeniu).
- Zestaw testów `Packages` uruchamia testy integracyjne tylko paczek zainstalowanych w projekcie (`vendor/simplysmart/*/tests/Integration`) zamiast wszystkich paczek z `../packages/simplysmart/`.

## [13.0.0] - 2026-11-06

Pierwsze wydanie simplysmart-13 (numer wersji głównej zgodny z Laravel 13).

### Added

- Licencja Simplysmart Restricted Source License 1.0 po polsku (`LICENSE.md`, wersja wiążąca) z tłumaczeniem angielskim (`LICENSE.en.md`) i linkami między nimi; wzór zgłoszenia użytku komercyjnego w `THIRD_PARTY.md`.
- Czysta instalacja Laravel 13.
- Pliki środowiskowe `.env.local`, `.env.staging`, `.env.production` (poza repozytorium; w repozytorium tylko szablon `.env.example`).
- Logi zapisywane dziennie (`LOG_STACK=daily`, plik `storage/logs/laravel-RRRR-MM-DD.log`, retencja 14 dni) we wszystkich środowiskach.
- Laravel Boost i skille agentów jak w skeleton13 (`changelog-before-commit`, `docs-after-tests`, `solid-dry` jako własne skille w lokalnym `.claude/skills/` poza zarządzaniem Boost i poza repozytorium; `laravel-best-practices`, `testing-best-practices`, `infer-conventions`, `tailwindcss-development` z Boost).
- Wdrożenie projektu `composer deploy -- <projekt>`: przed-produkcja na komputerze deweloperskim, potem po potwierdzeniu produkcja przez SSH; paczki lokalne trafiają na serwery z ich repozytoriów git przez świeży manifest wdrożeniowy (`composer.deploy.json` / `.lock`, git-ignorowany); konfiguracje w lokalnym `infrastructure/deploy/vendors/` (wzór `vendor/simplysmart/polygon/resources/deploy/deploy-vendor.env.example`).
- Katalog `infrastructure/` (vhost, pula PHP-FPM, certyfikat mkcert, konfiguracje wdrożeń) jest lokalny i poza repozytorium.
- Podgląd wydania `composer release -- --dry-run`.
- Paczka `simplysmart/polygon` (lokalnie z `../packages/simplysmart/polygon`): narzędzia wydań i wdrożeń oraz rdzeń bazy danych — tabela `users` z kolumnami standardowymi i historią, tabele strefy `polygon_*` i słowniki lokalizacji (`php artisan polygon:localization:seed`).
- Role i uprawnienia `spatie/laravel-permission` (bez zespołów; tabele i konfiguracja z `simplysmart/polygon`).
- Zakładanie kont strefy `polygon` (`polygon_accounts`): `php artisan polygon:account:create {email} {name} [--role=*] [--skip-existing]`; `users` zostaje dla kont frontendu.
- Specyfikacja bazy danych (z `simplysmart/polygon`): odczyty i zarządzanie słownikami, przejścia stanu wiersza, etykiety kolumn każdego modelu w pl i en.
- Domyślna konfiguracja systemu z plików instalacji JSON (`php artisan polygon:install`, także w `db:seed`): role strefy polygon (`guest`, `support`, `owner`, `hero` — root) i konto `support@simplysmart.pl` z rolą `hero` z losowym id poniżej 1000; własne pliki projektu przez `POLYGON_INSTALL_FILES` (wpis w `.env.example`).
- Dni wolne Polski 2026–2027 i kursy NBP walut CHF, EUR, GBP, USD (29.10–05.11.2026) w słownikach (`HolidaysSeeder`, `ExchangeRatesSeeder` z `simplysmart/polygon`, dane jak w skeleton13).
- Polskie komunikaty Laravela (walidacja, logowanie, hasła, paginacja), helpery `text_*` / `local_*` oraz szablon i import tłumaczeń JSON z `simplysmart/polygon`.
- Strona startowa `/` z kartą instalacji simplysmart (funkcje systemu, „Zbudowane na Laravel”), stylami inline i wersją z najnowszego wydania w CHANGELOG.md.
- Pełna historia zmian każdej tabeli danych z aktorem zmian (użytkownik żądania `web`, zadanie kolejki) z `simplysmart/polygon`.
- Strefy i logowanie kodem z e-maila + PIN z `simplysmart/polygon` (blokady, limity, zaufane urządzenia, blokada ekranu, zmiana PIN-u, zapasowy e-mail) z testami mechanizmu na strefach testowych `alpha`, `beta` i `open` (`tests/Fixtures/Zones`); katalog ról każdej strefy w plikach instalacji (dodatkowo `registration`, `doctor`, `accountant`, `analyst`, `manager`).
- Organizacje z `simplysmart/polygon`: typy jednostek, członkostwo kont stref i kontekst organizacji konta zalogowanego w strefie (przełączenie `POST /<strefa>/account/organization`); `db:seed` zakłada organizację hero „Simplysmart” dla kont z rolą `hero`, ukrytą we frontendzie stref. Migracje nie wstawiają danych — dane referencyjne dają wyłącznie seedery (bazowe polygon i seedery paczek).
- Zestaw testów `Packages` w `phpunit.xml`: testy integracyjne lokalnych paczek (`../packages/simplysmart/*/tests/Integration`).
- Zasady ochrony sekretów w `CLAUDE.md` (bez wypisywania wartości z `.env.production`, `.env.staging` i konfiguracji wdrożeń).
- Kontrola jakości: `composer lint`, `composer format` (Pint PER, `strict_types`), `composer analyse` (PHPStan/Larastan, poziom `max`).

### Changed

- Język aplikacji polski: `APP_LOCALE=pl`, `APP_FALLBACK_LOCALE=pl`, `APP_FAKER_LOCALE=pl_PL` oraz nowe `APP_LOCALES=pl`, `APP_COUNTRY=PL`, `APP_CURRENCY=PLN` w `.env.example` i plikach środowisk `.env`, `.env.local`, `.env.staging`, `.env.production` (słowniki po polsku, Polska, złoty, polskie stawki VAT).
- Licencja projektu w `composer.json`: `proprietary` zamiast `MIT`.
- Projekt w `composer.json` jako `simplysmart/simplysmart-13` (opis, słowa kluczowe, autor) zamiast stockowego `laravel/laravel`; `APP_NAME="simplysmart-13"` w `.env.example`.
- Kolejka domyślnie synchroniczna w `.env.example` (`QUEUE_CONNECTION=sync` zamiast `database`).
- Wymagana wersja PHP podniesiona do `^8.4`.
- Narzędzia wydań i wdrożeń (wcześniej `scripts/version-release.php`) dostarcza paczka `simplysmart/polygon` (`vendor/bin/version-release`, `vendor/bin/deploy`); w projekcie zostają tylko lokalne konfiguracje w `infrastructure/deploy/vendors/`.
- Migracje, seedery, fabryki i model użytkownika dostarcza `simplysmart/polygon` (projekt nie ma własnych `database/migrations/`, `database/seeders/`, `database/factories/` ani `app/Models/`; `php artisan db:seed` uruchamia seeder paczki, a `config/auth.php` wskazuje model `Simplysmart\Polygon\Models\User`).
