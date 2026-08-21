# Review techniczne aplikacji DUiR PHP/MySQL — 2026-07-06

## Zakres sprawdzenia

Sprawdzono strukturę paczki, routing, konfigurację, instalator, logowanie, role użytkowników, panel administratora, CRON, endpointy mostka KRZ/MSiG, generowanie raportów, wysyłkę e-mail, zabezpieczenia plików oraz wtyczkę Chrome.

## Wynik

Aplikacja przeszła testy składni PHP i testy regresyjne. Po review wprowadzono poprawki zabezpieczające i operacyjne.

## Poprawki po review

1. **CRON i większa liczba podmiotów**
   - wcześniej limit `CRON_BATCH_SIZE` mógł stale obejmować pierwszy alfabetyczny pakiet podmiotów, przez co dalsze rekordy mogły być pomijane;
   - dodano rotację po `last_checked_at`: najpierw podmioty nigdy niesprawdzane, potem najdawniej sprawdzane.

2. **Odświeżanie roli użytkownika**
   - wcześniej rola administratora była oparta na wartości zapisanej w sesji;
   - teraz dane aktywnego użytkownika są odświeżane z bazy przy każdym żądaniu, więc odebranie roli albo blokada konta działa od razu.

3. **Ochrona ostatniego administratora**
   - dodano blokadę odebrania uprawnień lub zablokowania ostatniego aktywnego administratora.

4. **Słabe tokeny produkcyjne**
   - placeholdery z `.env.example`, np. `change-me-...-32chars`, są teraz uznawane za słabe;
   - tokeny CRON i mostka KRZ/MSiG muszą mieć losową wartość minimum 32 znaki.

5. **Duplikaty e-maili użytkowników**
   - dodano czytelny komunikat zamiast surowego błędu bazy danych.

6. **Ochrona katalogów prywatnych**
   - wzmocniono `.htaccess`, aby blokada katalogów `app`, `database`, `storage`, `docs`, `bin`, `tests`, `chrome_extension` działała przed regułą przepuszczającą istniejące pliki.

## Wyniki kontroli

- `php bin/lint.php`: OK
- `php tests/run.php`: 72/72 testy zaliczone
- `node --check chrome_extension/*.js`: OK
- brak pliku `.env` w paczce; pozostaje tylko `.env.example`

## Uwagi wdrożeniowe

- Przed produkcyjnym uruchomieniem należy ustawić losowe `CRON_TOKEN` i `KRZ_BRIDGE_TOKEN`.
- Na hostingu współdzielonym KRZ/MSiG pozostają obsługiwane przez kolejkę i wtyczkę Chrome, ponieważ stabilne uruchamianie headless browsera zwykle wymaga VPS.
- Warto po wdrożeniu wykonać próbę: `/setup`, logowanie admina, dodanie użytkownika, dodanie podmiotu, ręczne sprawdzenie, wywołanie CRON i test połączenia wtyczki Chrome.
