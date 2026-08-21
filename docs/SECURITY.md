# Bezpieczeństwo

- Nie zapisuj `.env` w repozytorium.
- Nie umieszczaj w ZIP-ie realnych haseł SMTP, tokenów KRZ, kluczy API ani bazy danych.
- Sekrety w panelu ustawień są maskowane jako `******`.
- Wtyczka KRZ nie powinna mieć uprawnienia `https://*/*` ani `<all_urls>`.
