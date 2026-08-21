# Migracja ze starej wersji Python/SQLite

Ten port PHP/MySQL ma inny schemat bazy, dlatego migrację najlepiej wykonać eksportem pośrednim:

1. W starej aplikacji wyeksportuj podmioty i zdarzenia do CSV/JSON.
2. Utwórz bazę MySQL według `database/schema.sql`.
3. Zaimportuj podmioty do tabeli `subjects`.
4. Zaimportuj zdarzenia do tabeli `events`, zachowując `source`, `title`, `description`, `signature`, `publication_date`, `risk`, `risk_reason`.
5. Nie przenoś sekretów SMTP/API bezpośrednio z plików konfiguracyjnych. Ustaw je ponownie w panelu albo w `.env`.

W kolejnej iteracji można dodać automatyczny migrator pod konkretny plik SQLite, jeśli zostanie przekazany rzeczywisty schemat bazy produkcyjnej.
