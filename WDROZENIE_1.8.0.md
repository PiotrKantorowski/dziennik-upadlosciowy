# DUiR 1.8.0 — wdrożenie

## Kolejność

1. Na **każdym komputerze** rozpakuj paczkę `DUiR_WTYCZKA_CHROME_1.8.0.zip`.
2. Wejdź w Chrome na `chrome://extensions`, włącz „Tryb programisty” i przy
   dotychczasowej wtyczce kliknij „Załaduj ponownie”. Jeżeli Chrome nadal używa
   starego katalogu, wybierz „Załaduj rozpakowane” i wskaż folder
   `chrome_extension` z paczki.
3. W szczegółach wtyczki sprawdź, czy widnieje wersja **1.8.0**.
4. Dopiero potem rozpakuj `DUiR_HOSTING_1.8.0.zip` w katalogu aplikacji na hostingu,
   zachowując strukturę folderów i zastępując wskazane pliki.
5. W programie otwórz kartę spółki i kliknij „Sprawdź teraz”. Sprawdź osobno status
   KRZ i MSiG. Stary fałszywy wpis KRS z surowym JSON-em zniknie automatycznie przy
   wejściu na kartę, a ocena AI zostanie utworzona ponownie z poprawnych zdarzeń.

Nie jest potrzebne ręczne wykonywanie zapytania SQL. Aplikacja sama uzupełnia
techniczne kolumny kolejki, jeśli nie ma ich jeszcze w bazie.

## Ważne przy wielu komputerach

Po wgraniu części serwerowej wtyczki starsze niż 1.8.0 są celowo zatrzymywane
przed pobraniem zadania. Nie zepsują danych ani nie zablokują wspólnej kolejki,
ale dany komputer nie będzie pracował, dopóki jego wtyczka nie zostanie
zaktualizowana i ponownie załadowana.
