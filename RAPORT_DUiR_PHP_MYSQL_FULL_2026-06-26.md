# Raport: pełniejszy port DUiR do PHP/MySQL + Chrome

## Zakres wykonany

Przygotowano pełniejszą wersję aplikacji w stosie:

- PHP 8.1+,
- MySQL 8,
- Docker Compose,
- wtyczka Chrome do KRZ,
- panel webowy,
- kolejka zadań KRZ,
- sprawdzenia KRS/MSiG/KRZ,
- scoring ryzyka,
- raporty PDF,
- wysyłka e-mail SMTP z PDF,
- raport dzienny,
- ustawienia z maskowaniem sekretów,
- testy regresyjne.

## Najważniejsze funkcje

1. **Podmioty**
   - dodawanie, edycja, usuwanie,
   - KRS/NIP/REGON/PESEL,
   - typ podmiotu,
   - karta podmiotu z danymi u góry, wynikami MSiG/KRZ/KRS niżej i przyciskami na dole.

2. **KRZ**
   - backend tworzy zadania w `krz_tasks`,
   - wtyczka Chrome pobiera zadania przez `/api/krz/worklist`,
   - wtyczka odsyła wynik przez `/api/krz/ingest`,
   - backend rozpoznaje postępowania aktywne i zakończone,
   - zakończenie niepowodzeniem podbija ryzyko do wysokiego.

3. **KRS**
   - klient KRS API,
   - próba pobrania odpisu aktualnego i pełnego,
   - analiza statusu i działu historycznego,
   - kontrola sprawozdania finansowego.

4. **MSiG**
   - przygotowana integracja pod API iMSiG,
   - ochrona przed fałszywym dopasowaniem po generycznej nazwie,
   - preferencja identyfikatorów KRS/NIP/REGON.

5. **Raporty i e-mail**
   - raport podmiotu PDF,
   - raport dzienny PDF,
   - mail z krótkim podsumowaniem, poziomem ryzyka i załącznikiem PDF.

## Podwójna weryfikacja

Wykonano dwie iteracje weryfikacyjne:

- `php bin/lint.php` — OK,
- `php tests/run.php` — 12 testów OK,
- `node --check` dla plików wtyczki Chrome — OK.

Dodatkowo po spakowaniu ZIP-a wykonano świeże rozpakowanie i ponowne sprawdzenie:

- integralność ZIP — OK,
- PHP lint — OK,
- testy PHP — OK,
- składnia JS wtyczki — OK.

Szczegółowy log jest w `docs/WERYFIKACJA_ITERACJE.md`.

## Ograniczenia testu

W aktualnym środowisku nie było dostępnego serwera MySQL ani rozszerzenia `pdo_mysql` po stronie CLI, dlatego nie wykonałem pełnego live-testu bazy MySQL. Schemat MySQL, kod PDO i zapytania zostały przygotowane, a testy regresyjne sprawdzają logikę usług, parsery, scoring, PDF i manifest wtyczki.

Nie wykonano też live-testu na realnym KRZ w Twojej sesji Chrome, bo wymaga to uruchomionej aplikacji, załadowanej wtyczki i aktywnego portalu KRZ po stronie użytkownika.
