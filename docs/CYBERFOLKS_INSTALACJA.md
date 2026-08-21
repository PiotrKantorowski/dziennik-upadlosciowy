# Instalacja DUiR na hostingu współdzielonym Cyber_Folks

## Co zawiera ta wersja

- aplikację webową PHP 8.1+ / MySQL;
- logowanie kontami użytkowników z bazy danych;
- panel administratora do tworzenia i blokowania kont;
- instalator dostępny z przeglądarki pod `/setup`;
- endpoint CRON `cron.php` oraz `/cron/run` do automatycznego cyklicznego sprawdzania;
- kolejki sprawdzeń KRZ i MSiG dla portali działających jako aplikacje przeglądarkowe;
- strukturę możliwą do wgrania bez Dockera i bez VPS.

## 1. Przygotowanie bazy danych

W panelu Cyber_Folks utwórz bazę MySQL i użytkownika bazy. Zanotuj:

- nazwę bazy,
- login użytkownika bazy,
- hasło,
- host bazy, najczęściej `localhost` albo host wskazany w panelu.

## 2. Konfiguracja `.env`

Skopiuj `.env.example` jako `.env` i ustaw co najmniej:

```env
APP_URL=https://twoja-domena.pl
DB_DSN=mysql:host=localhost;dbname=NAZWA_BAZY;charset=utf8mb4
DB_USER=UZYTKOWNIK_BAZY
DB_PASSWORD=HASLO_BAZY
CRON_TOKEN=wklej-losowy-token-minimum-32-znaki
KRZ_BRIDGE_TOKEN=wklej-drugi-losowy-token-minimum-32-znaki
```

Nie wgrywaj prawdziwych haseł do repozytoriów ani do publicznych paczek.

## 3. Wgranie plików

Najprostszy wariant dla zwykłego hostingu: wgraj całą zawartość paczki do katalogu domeny, np. `domains/twoja-domena.pl/public_html/`.

Wersja ma główny `index.php` w katalogu głównym oraz `.htaccess`, który blokuje bezpośredni dostęp do katalogów `app`, `database`, `storage`, `docs`, `bin`, `tests` i do pliku `.env`.

Jeżeli możesz ustawić katalog startowy domeny na `/public`, możesz użyć także wariantu z `public/index.php`, ale na hostingu współdzielonym zwykle wygodniejszy jest wariant pierwszy.

## 4. Pierwsze uruchomienie

Wejdź w przeglądarce na:

```text
https://twoja-domena.pl/setup
```

Instalator utworzy tabele i pierwsze konto administratora. Po utworzeniu administratora instalator wyłącza się automatycznie.

## 5. Konta użytkowników

Po zalogowaniu jako administrator wejdź w:

```text
Użytkownicy -> Dodaj użytkownika
```

Role:

- `administrator` — zarządza kontami i ustawieniami;
- `użytkownik` — korzysta z monitoringu, podmiotów i raportów, ale nie tworzy kont i nie zmienia ustawień systemowych.

## 6. CRON

Zadanie CRON może wywoływać:

```bash
wget -q -O - 'https://twoja-domena.pl/cron.php?token=TU_WKLEJ_CRON_TOKEN' >> /dev/null 2>&1
```

albo:

```bash
wget -q -O - 'https://twoja-domena.pl/cron/run?token=TU_WKLEJ_CRON_TOKEN' >> /dev/null 2>&1
```

Zalecany start: raz dziennie albo co kilka godzin, zależnie od liczby monitorowanych podmiotów. Przy większej liczbie podmiotów ustaw `CRON_BATCH_SIZE`, np. `25`, aby jeden przebieg nie był zbyt ciężki dla hostingu współdzielonego. CRON wybiera podmioty rotacyjnie — najpierw te nigdy niesprawdzane, potem najdawniej sprawdzane — więc większa baza nie powinna powodować stałego pomijania dalszych podmiotów.

## 7. Ważne ograniczenie KRZ/MSiG

Hosting współdzielony nie uruchomi stabilnie pełnej przeglądarki headless. Dlatego aplikacja wykonuje część serwerową przez PHP/CRON, natomiast portale KRZ i MSiG, które działają jako dynamiczne aplikacje przeglądarkowe, obsługiwane są przez kolejkę i mostek przeglądarkowy/wtyczkę.

To nie jest błąd architektury, tylko bezpieczniejsze rozwiązanie dla zwykłego hostingu: aplikacja nie wymaga VPS, Dockera ani stale działającego procesu w tle.

## 8. Aktualizacja z wcześniejszej wersji

Jeżeli baza już istnieje, uruchom z phpMyAdmin plik:

```text
database/upgrade_add_users.sql
```

Następnie wejdź na `/setup`, aby utworzyć pierwszego administratora.
