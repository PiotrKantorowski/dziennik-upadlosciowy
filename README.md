# Dziennik Upadłościowy i Restrukturyzacyjny (DUiR)

Monitoring kontrahentów i klientów kancelarii w rejestrach: **KRS**, **Krajowy Rejestr Zadłużonych** i **Monitor Sądowy i Gospodarczy**. Aplikacja sprawdza podmioty cyklicznie, klasyfikuje znalezione wpisy jako sygnały ryzyka i wysyła raport, zamiast kazać komukolwiek codziennie klikać po portalach.

Serwer to zwykły PHP + MySQL na hostingu współdzielonym — bez Dockera, bez VPS-a, bez kolejek. Portale, które wymagają realnej sesji przeglądarki, obsługuje wtyczka Chrome pracująca na komputerze użytkownika.

**Narzędzia wdrożeniowe dla kancelarii — [lexpilot.app](https://lexpilot.app).**

---

## Po co to jest

Upadłość albo restrukturyzacja kontrahenta zmienia sytuację prawną z dnia na dzień: zmienia się tryb dochodzenia należności, biegną terminy na zgłoszenie wierzytelności, zapada decyzja o wstrzymaniu dostaw. Informacja jest publiczna i darmowa, tylko rozsypana po trzech rejestrach o różnych interfejsach, z których dwa nie mają otwartego API. DUiR pilnuje tego za człowieka.

Typowe zastosowania: portfel klientów kancelarii, kontrahenci przed podpisaniem umowy, dłużnicy w sprawach windykacyjnych, monitoring na czas trwania procesu.

## Co potrafi

- **Lista monitorowanych podmiotów** — spółki po KRS/NIP/REGON i osoby fizyczne, z aliasami nazw i trybem obsługi per podmiot.
- **KRS przez oficjalne API** — dane rejestrowe, zarząd, adres, wykreślenia, sprawozdania finansowe i sygnały z ich braku.
- **KRZ i MSiG przez wtyczkę Chrome** — wyszukanie podmiotu, wejście w obwieszczenia i postanowienia, odczyt treści i odesłanie jej do backendu. Bez płatnych API i bez headless browsera na hostingu.
- **Analiza ryzyka** — klasyfikacja treści wpisu (upadłość, restrukturyzacja, zmiany w zarządzie, wykreślenie, zaległości sprawozdawcze) z matrycą decyzji „co robić po wykryciu" w katalogu [knowledge/](knowledge).
- **Dopasowanie treści do podmiotu** — obwieszczenie liczy się dopiero wtedy, gdy naprawdę dotyczy monitorowanego podmiotu; kontekst zakładki ani zadania nie jest dowodem tożsamości.
- **Raporty** — PDF i e-mail, dzienne albo na żądanie, z historią zdarzeń.
- **Konta i uprawnienia** — pierwszy administrator przez `/setup`, dalej zakładanie i blokowanie kont z panelu.
- **Podsumowania LLM** — opcjonalne, wyłączone bez klucza; nie decydują o klasyfikacji ryzyka.

## Architektura

```
przeglądarka użytkownika                hosting współdzielony (PHP 8.1+ / MySQL)
┌───────────────────────────┐           ┌────────────────────────────────────────┐
│ wtyczka Chrome            │  zadania  │ panel + API + CRON                     │
│  background.js  (kolejka) │◄──────────│ /api/krz/worklist  /api/msig/worklist  │
│  content_krz.js (KRZ)     │           │                                        │
│  content_msig.js (MSiG)   │  treść    │ /api/krz/ingest    /api/msig/ingest    │
│                           │──────────►│ klasyfikacja → zdarzenia → raport      │
└───────────────────────────┘           └───────────────┬────────────────────────┘
                                                        │  KRS API (api-krs.ms.gov.pl)
                                                        ▼  MF: wykaz podatników VAT
```

Warstwy: `app/Controllers` (routing i API), `app/Services` (KRS, CEIDG, analiza ryzyka, sprawozdania, raporty, mailer, PDF), `app/Repository.php` (dostęp do danych), `chrome_extension/` (mostek przeglądarkowy), `database/schema.sql` (schemat), `knowledge/` (reguły decyzyjne), `docs/` (instalacja, architektura, bezpieczeństwo, troubleshooting KRZ).

Szczegóły: [docs/ARCHITEKTURA.md](docs/ARCHITEKTURA.md).

## Dlaczego wtyczka, a nie scraper na serwerze

KRZ i MSiG nie wystawiają otwartego API, a ich portale wymagają realnej sesji przeglądarki. Hosting współdzielony nie jest miejscem na headless browser, a obchodzenie zabezpieczeń portali publicznych nie wchodzi w grę. Wtyczka działa więc tam, gdzie i tak siedzi użytkownik: w jego przeglądarce, na jego uprawnieniach, na publicznie dostępnych stronach. Serwer dostaje z niej wyłącznie tekst obwieszczenia i metadane wiersza.

Wtyczka ma wąskie uprawnienia (bez `<all_urls>`), a każda pozycja jest odsyłana na bieżąco — jeszcze przed kliknięciem w obwieszczenie, które potrafi wymienić całą zawartość ramki i zabić kontekst skryptu. Dzięki temu przerwane drążenie nie kasuje tego, co już zebrano. Historia takich potknięć i ich rozwiązań jest w [CHANGELOG.md](CHANGELOG.md) — wraz z opisem błędów, które trzeba było najpierw zrozumieć.

## Instalacja

Wymagania: PHP 8.1+ (PDO, JSON, mbstring, OpenSSL), MySQL/MariaDB, Apache z `.htaccess` i mod_rewrite, zadania CRON w panelu hostingu, Chrome do wtyczki.

1. Załóż bazę MySQL w panelu hostingu i wczytaj `database/schema.sql`.
2. Skopiuj `.env.example` do `.env`, uzupełnij dane bazy i wygeneruj losowe tokeny (`APP_KEY`, `CRON_TOKEN`, `KRZ_BRIDGE_TOKEN`).
3. Wgraj pliki do katalogu domeny. `.htaccess` zamyka dostęp do kodu, schematu bazy, logów i `.env`.
4. Wejdź na `https://twoja-domena.pl/setup` i utwórz pierwszego administratora.
5. Dodaj zadanie CRON:

```bash
wget -q -O - 'https://twoja-domena.pl/cron.php?token=TWOJ_CRON_TOKEN' >> /dev/null 2>&1
```

6. Zainstaluj wtyczkę z katalogu `chrome_extension/` (chrome://extensions → tryb dewelopera → „Załaduj rozpakowany"), wpisz w jej opcjach adres instancji i `KRZ_BRIDGE_TOKEN`.

Pełne instrukcje: [docs/CYBERFOLKS_INSTALACJA.md](docs/CYBERFOLKS_INSTALACJA.md) (hosting współdzielony), [docs/INSTALL_LOCAL.md](docs/INSTALL_LOCAL.md), [docs/INSTALL_VPS.md](docs/INSTALL_VPS.md), [docs/CHROME_EXTENSION.md](docs/CHROME_EXTENSION.md).

## Testy

```bash
php tests/run.php
```

129 testów PHP plus testy runtime wtyczki w Node. Suite pilnuje m.in. kontraktu API wtyczki, minimalnej wersji protokołu, dopasowania treści do podmiotu, parsowania sprawozdań KRS, prywatności raportów i reguł ryzyka. Wersja manifestu wtyczki jest w teście przypięta świadomie — podbicie wersji ma przechodzić przez ten test.

## Bezpieczeństwo i dane

- Sekrety wyłącznie w `.env` (poza repozytorium) albo w panelu ustawień, gdzie są maskowane.
- Wtyczka nie dostaje uprawnienia `<all_urls>`; działa na portalach KRZ i MSiG.
- Repozytorium nie zawiera danych monitorowanych podmiotów: `database/schema.sql` to sam schemat, a dane w testach są syntetyczne.
- Aplikacja przetwarza dane z rejestrów publicznych, w tym dane osób fizycznych — instalacja u siebie oznacza rolę administratora tych danych ze wszystkim, co z tego wynika (podstawa przetwarzania, retencja, dostęp do panelu, kopie zapasowe).
- Uwagi wdrożeniowe: [docs/SECURITY.md](docs/SECURITY.md).

## Ograniczenia

- KRZ i MSiG wymagają uruchomionej przeglądarki z wtyczką — bez niej działa tylko tor KRS.
- Portale publiczne bywają zmieniane bez zapowiedzi; selektory są pisane pod ich aktualny układ i mogą wymagać poprawki po zmianie strony.
- Klasyfikacja ryzyka jest wsparciem, nie opinią prawną. Decyzję i odpowiedzialność za nią bierze prawnik.
- Aplikacja nie zgłasza niczego za użytkownika do żadnego rejestru ani sądu.

## Kontekst i wdrożenia

DUiR powstał w kancelarii Kantorowski x Głąb do własnej pracy z portfelem klientów i wyrósł z tego, czego brakowało w codziennym monitoringu. Z tej samej pracowni pochodzą narzędzia dostępne jako pakiety wdrożeniowe na **[lexpilot.app](https://lexpilot.app)**:

| Narzędzie | Do czego |
|---|---|
| **easyEPU** | pozew w elektronicznym postępowaniu upominawczym z faktur: odczyt dokumentów, weryfikacja stron w rejestrach, wyliczenia i wysyłka do e-Sądu |
| **Anonimio** | odwracalna pseudonimizacja dokumentów Word przed pracą z modelem językowym |
| **OLA** | AI z dostępem do polskich i unijnych źródeł prawa, orzecznictwa i rejestrów, z weryfikacją cytowań |
| **OLA Plus** | OLA plus lokalny OCR, pseudonimizacja całych zestawów plików i monitoring legislacji |

Każdy pakiet to program z licencją organizacyjną, ankieta wdrożeniowa, raport zasad korzystania, checklista i wsparcie — narzędzia wspierają pracę prawnika i nie zastępują jego kontroli ani decyzji. Wdrożenie DUiR albo pytania o niego: przez formularz na **[lexpilot.app](https://lexpilot.app)**.

## Licencja i autorstwo

**Apache License 2.0** — [LICENSE](LICENSE). Wolno używać, modyfikować i rozpowszechniać, także komercyjnie i we własnych produktach. Warunek jest jeden i dotyczy autorstwa: zachowaj notę o prawach autorskich, kopię licencji i plik [NOTICE](NOTICE), a w plikach, które zmienisz, zaznacz, że zostały zmienione (sekcja 4 licencji). Licencja nie daje praw do nazw i znaków towarowych — w tym „LexPilot", „easyEPU", „Anonimio", „OLA" (sekcja 6).

```
Copyright 2026 Kancelaria Prawna Kantorowski, Głąb i Wspólnicy Sp.j.
ul. Baczyńskiego 6B, 35-345 Rzeszów
KRS 0000897641 — Sąd Rejonowy w Rzeszowie, XII Wydział Gospodarczy KRS
NIP 5170383178 | REGON 367784460
https://lexpilot.app
```

Zbudowałeś coś na tym albo wdrażasz to u siebie? Napisz przez [lexpilot.app](https://lexpilot.app) — chętnie zobaczymy.
