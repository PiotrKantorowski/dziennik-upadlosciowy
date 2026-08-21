# KRZ — diagnostyka wtyczki

1. W Chrome/Edge otwórz `chrome://extensions`.
2. Włącz tryb deweloperski.
3. Kliknij „Załaduj rozpakowane” i wskaż folder `chrome_extension` z tej paczki.
4. W opcjach wtyczki ustaw URL backendu oraz token KRZ.
5. W panelu DUiR kliknij „Sprawdź teraz”.
6. Wtyczka powinna pobrać zadanie przez `/api/krz/worklist` i odesłać wynik przez `/api/krz/ingest`.

Status `error` albo `uncertain` nie oznacza braku wpisów. Oznacza, że źródło nie zostało pewnie odczytane i wynik trzeba sprawdzić ręcznie.
