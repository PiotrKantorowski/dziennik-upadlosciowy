# Instalacja

## Opcja rekomendowana: Docker

```bash
cp .env.example .env
# edytuj .env
docker compose up --build
```

Aplikacja: `http://127.0.0.1:8080`

## Bez Dockera

Wymagania:

- PHP 8.1 lub nowszy,
- rozszerzenia: PDO, pdo_mysql, json, openssl, mbstring,
- MySQL 8,
- Apache/Nginx z document root ustawionym na `public/`.

Kroki:

1. Utwórz bazę MySQL.
2. Zaimportuj `database/schema.sql`.
3. Skopiuj `.env.example` jako `.env` i ustaw DB/SMTP/KRZ.
4. Ustaw document root na katalog `public/`.
5. Załaduj wtyczkę Chrome z folderu `chrome_extension`.
