# DUiR 1.8.5 — wdrożenie poprawki trwałej

> Wersja 1.8.5 zastępuje przygotowaną wcześniej 1.8.4. Nie wdrażaj już paczek 1.8.4.

## 1. Wtyczka Chrome — na każdym komputerze

1. Rozpakuj `DUiR_WTYCZKA_CHROME_1.8.5.zip`.
2. Skopiuj **całą zawartość** paczki do dotychczasowego folderu wtyczki DUiR
   i zastąp wszystkie stare pliki. Zachowanie tej samej lokalizacji zachowuje token
   i pozostałe ustawienia.
3. Otwórz `chrome://extensions`, włącz „Tryb dewelopera” i kliknij „Odśwież”
   przy wtyczce DUiR.
4. W oknie wtyczki potwierdź numer **1.8.5**.

Powtórz na każdym komputerze uczestniczącym w monitoringu. Wystarczy, że pierwszy
komputer ma już 1.8.5, aby bezpiecznie przejść do kroku 2; pozostałe starsze kopie
zostaną potem zablokowane przez serwer.

## 2. Hosting

Rozpakuj `DUiR_HOSTING_1.8.5.zip` w katalogu głównym istniejącej instalacji DUiR
(tam, gdzie leży `index.php`) i zastąp wyłącznie następujące pliki:

- `app/Controllers/KrzApiController.php`
- `app/Controllers/MsigApiController.php`
- `app/Repository.php`
- `cron.php`

Nie usuwaj ani nie zastępuj całego katalogu `app`. Aktualizacja nie zmienia `.env`,
bazy danych ani danych klientów.

## 3. Kontrola po wdrożeniu

1. Na karcie „RAFAŁ MUCHARSKI Electro Hard” kliknij „Sprawdź teraz”.
2. Poczekaj na zakończenie KRZ i MSiG. KRZ powinien zapisać właściwe postępowanie,
   a MSiG wynik albo potwierdzony brak wyników — bez timeoutu ramki.
3. Jeżeli jeden komputer nadal ma starszą wersję, wtyczka pokaże błąd aktualizacji
   i nie przejmie zadania. Jest to zamierzone zabezpieczenie.

Wydanie 1.8.5 dodatkowo zapewnia atomową rezerwację zadań między komputerami,
ponowienie po restarcie workera Chrome oraz awaryjną wysyłkę raportu przez `cron.php`.
