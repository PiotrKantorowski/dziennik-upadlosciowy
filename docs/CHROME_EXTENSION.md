# Wtyczka Chrome/Edge KRZ

Folder wtyczki: `chrome_extension`.

## Instalacja

1. Otwórz `chrome://extensions` albo `edge://extensions`.
2. Włącz tryb deweloperski.
3. Wybierz „Załaduj rozpakowane”.
4. Wskaż folder `chrome_extension`.
5. Otwórz opcje wtyczki i ustaw:
   - adres aplikacji,
   - token KRZ zgodny z ustawieniem `KRZ_BRIDGE_TOKEN`.

## Zasada działania

Wtyczka pobiera zadania z `/api/krz/worklist`, otwiera KRZ w realnej karcie przeglądarki i odsyła wynik do `/api/krz/ingest`.

Błąd lub niepewny wynik nie jest traktowany jako brak wpisów.
