# simplysmart13

**Gotowy fundament aplikacji biznesowych simplysmart.** simplysmart13 to instalacja bazowa, na której powstają systemy dla firm i placówek — z bezpiecznym logowaniem, strukturą organizacyjną, uprawnieniami, słownikami i pełnym audytem zmian dostępnymi od pierwszego uruchomienia. Nowy system nie zaczyna się od zera: zespół buduje tylko to, co wyróżnia danego klienta.

## Dla kogo

- **Firmy i sieci placówek**, które potrzebują jednego systemu dla wielu oddziałów, filii czy poradni — z jasnym podziałem, kto do czego ma dostęp.
- **Zespoły wdrożeniowe simplysmart**, które na wspólnym rdzeniu uruchamiają kolejne systemy branżowe szybciej, taniej i w jednym, sprawdzonym standardzie.

## Co zyskujesz na starcie

- **Wydzielone strefy systemu** — osobne „wejścia” dla różnych grup użytkowników (np. administracja, personel, klienci), każde z własnym logowaniem, sesją i zestawem uprawnień.
- **Bezpieczne logowanie bez haseł** — jednorazowy kod z e-maila i osobisty PIN, z blokadami po nieudanych próbach, zaufanymi urządzeniami, blokadą ekranu i zapasowym adresem e-mail.
- **Struktura organizacyjna** — drzewo organizacji (siedziba, placówki, filie, działy) z danymi firm i personelem przypisanym do jednostek.
- **Uprawnienia w kontekście organizacji** — ta sama osoba może mieć różne role w różnych jednostkach (np. właściciel w jednej, lekarz w drugiej); system zawsze liczy uprawnienia w organizacji, w której użytkownik właśnie pracuje i pozwala ją zmienić w trakcie pracy.
- **Gotowa konfiguracja startowa** — role, uprawnienia, organizacje i konta startowe opisane w plikach konfiguracyjnych i zakładane automatycznie przy instalacji oraz każdym wdrożeniu, bez ręcznego klikania.
- **Słowniki globalne** — kraje, języki, waluty, stawki VAT, kursy walut NBP, dni wolne i jednostki miar gotowe do użycia.
- **Pełny audyt zmian** — każda zmiana danych zapisuje się w historii: kto, co i kiedy zmienił, także zmiany wykonane przez zadania w tle.
- **Lokalizacja** — teksty, kwoty, liczby i daty formatowane zgodnie z językiem i krajem użytkownika; system działa po polsku od pierwszego uruchomienia.

## Jakość i bezpieczeństwo wdrożeń

- **Kontrolowane wydania** — każda wersja ma numer i opis zmian ([CHANGELOG.md](CHANGELOG.md)), widoczny też na stronie startowej.
- **Bezpieczne wdrożenia** — każda zmiana trafia najpierw na środowisko przed-produkcyjne, a na produkcję dopiero po potwierdzeniu.
- **Automatyczne standardy** — styl kodu, analiza statyczna na najwyższym poziomie i testy sprawdzane przy każdej zmianie.
- **Ochrona danych** — hasła, klucze i dane dostępowe środowisk nigdy nie trafiają do repozytorium.

## Jak to działa

simplysmart13 łączy framework Laravel 13 z rdzeniem simplysmart (`simplysmart/polygon`), który dostarcza bazę danych, strefy, logowanie, organizacje, uprawnienia i narzędzia wdrożeniowe. Systemy branżowe dokładają do niego własne strefy i funkcje jako osobne pakiety — dzięki temu poprawki rdzenia trafiają do wszystkich systemów naraz, a aktualizacja Laravela sprowadza się do podbicia wersji.

## Dla programistów

Instalacja, baza danych, testy, standardy kodu i wdrożenia: [CONTRIBUTING.md](CONTRIBUTING.md).

## Licencja

Simplysmart Restricted Source License 1.0 — © 2026 Marcin Ulezalka.

- [Polski (wiążąca)](LICENSE.md)
- [English (translation)](LICENSE.en.md)

Użytek komercyjny wymaga wpisu w pliku atrybucji projektu — wzór w [THIRD_PARTY.md](THIRD_PARTY.md).
