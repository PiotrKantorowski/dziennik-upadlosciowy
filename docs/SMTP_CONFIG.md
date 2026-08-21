# Konfiguracja SMTP

W panelu ustawień uzupełnij:

- `SMTP_HOST`,
- `SMTP_PORT`,
- `SMTP_USER`,
- `SMTP_PASSWORD`,
- `SMTP_FROM`,
- `SMTP_TLS`,
- `REPORT_TO`.

Port 465 używa SMTP SSL. Dla portu 587 używany jest STARTTLS, jeżeli `SMTP_TLS=1`.

Hasło i klucze w panelu są maskowane jako `******`. Wpisanie `******` nie powinno nadpisywać sekretu.
