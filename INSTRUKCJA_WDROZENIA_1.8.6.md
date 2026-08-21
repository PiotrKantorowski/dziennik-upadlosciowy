# DUiR 1.8.6 — wdrożenie poprawki: wspólny zegar zadania KRZ + odporność na losowy błąd Chrome

> Wersja 1.8.6 zastępuje 1.8.5. Poprawka usuwa dwie przyczyny błędów KRZ z raportu
> dziennego 2026-08-10 (Mucharski, ADCOOKIE, Sławomir Kaźmierczak) — patrz CHANGELOG.md.

## 1. Wtyczka Chrome — na każdym komputerze

1. Rozpakuj `DUiR_WTYCZKA_CHROME_1.8.6.zip` (albo, jeśli wtyczka jest wczytana
   bezpośrednio z folderu `chrome_extension` tego repozytorium — jak na komputerze,
   z którego prowadzony był ten audyt — pomiń ten krok, pliki są już podmienione).
2. Skopiuj **całą zawartość** paczki do dotychczasowego folderu wtyczki DUiR i
   zastąp wszystkie stare pliki. Zachowanie tej samej lokalizacji zachowuje token
   i pozostałe ustawienia.
3. Otwórz `chrome://extensions`, włącz „Tryb dewelopera" i kliknij „Odśwież"
   przy wtyczce DUiR.
4. W oknie wtyczki potwierdź numer **1.8.6**.

Powtórz na każdym komputerze uczestniczącym w monitoringu. Wystarczy, że pierwszy
komputer ma już 1.8.6, aby bezpiecznie przejść do kroku 2; pozostałe starsze kopie
zostaną potem zablokowane przez serwer (426 „Wtyczka jest za stara").

## 2. Hosting

Rozpakuj `DUiR_HOSTING_1.8.6.zip` w katalogu głównym istniejącej instalacji DUiR
(tam, gdzie leży `index.php`) i zastąp wyłącznie następujące pliki:

- `app/Controllers/KrzApiController.php`
- `app/Controllers/MsigApiController.php`

Nie usuwaj ani nie zastępuj całego katalogu `app`. Aktualizacja nie zmienia `.env`,
bazy danych ani danych klientów. `cron.php` i `Repository.php` bez zmian względem 1.8.5.

## 3. Kontrola po wdrożeniu

1. Na karcie „RAFAŁ MUCHARSKI Electro Hard" kliknij „Sprawdź teraz".
2. Poczekaj na zakończenie KRZ. Postępowanie restrukturyzacyjne (KR1S/GRz-nu/175/2025)
   powinno zostać zapisane bez błędu „ramka formularza nie zgłosiła wyniku" —
   nawet jeśli portal tego dnia renderuje formularz z opóźnieniem.
3. Sprawdź też karty „ADCOOKIE" i „SŁAWOMIR KAŹMIERCZAK" — dotychczasowy błąd „Nie
   udało się otworzyć karty KRZ: Tabs cannot be edited right now" nie powinien się
   już pojawiać (a jeśli sporadycznie wystąpi, wtyczka ponowi próbę sama, po cichu).
4. Jeżeli jeden komputer nadal ma starszą wersję, wtyczka pokaże błąd aktualizacji
   i nie przejmie zadania. Jest to zamierzone zabezpieczenie.

## Uwaga porządkowa (niezwiązana z tą poprawką)

Na komputerze audytu w `chrome://extensions` widoczna jest dodatkowo **stara wtyczka
z poprzedniej, wycofanej wersji programu** (folder
`AppData\Local\Programs\DziennikUpadlosciowy\browser_extension`, wersja 1.0.0,
sprzed portu na PHP/MySQL). Jej serwer docelowy (`127.0.0.1:8765`) już nie działa,
więc w praktyce nic nie robi, ale zaleca się jej usunięcie w `chrome://extensions`
(przycisk „Usuń"), żeby wykluczyć ją jako źródło ewentualnych przyszłych niespójności.
