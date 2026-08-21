# Instalacja lokalna

1. Rozpakuj paczkę.
2. Skopiuj `.env.example` do `.env` i uzupełnij połączenie z MySQL.
3. Uruchom `docker compose up -d` albo własny PHP 8.1+ oraz MySQL 8.
4. Zaimportuj `database/schema.sql`, jeżeli nie używasz Dockera.
5. Otwórz panel aplikacji.
6. Załaduj w Chrome/Edge folder `chrome_extension` jako rozszerzenie deweloperskie.
7. Ustaw URL aplikacji i token KRZ we wtyczce.
