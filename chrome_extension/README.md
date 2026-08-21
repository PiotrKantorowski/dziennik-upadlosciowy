# Asystent KRZ/MSiG — wtyczka przeglądarki (Dziennik Upadłościowy)

Trwałe, legalne, automatyczne pobieranie obwieszczeń z **portali KRZ i MSiG**. KRZ nie ma
publicznego API i blokuje dostęp automatyczny (Imperva/Incapsula), a MSiG nie udostępnia
darmowego API — dlatego oba portale odpytujemy przez darmową, oficjalną wyszukiwarkę
MSiG (`wyszukiwarka-msig.ms.gov.pl`) i portal KRZ. Wtyczka działa
**w Twojej prawdziwej przeglądarce** — portal widzi normalnego człowieka i przepuszcza
ją tak jak Ciebie. **Nie obchodzimy zabezpieczeń**: bez trybu headless, bez podszywania
fingerprintem, bez łamania CAPTCHA. To po prostu Twoja sesja, zautomatyzowana od środka.

## Jak to działa

1. Program (Dziennik Upadłościowy) udostępnia lokalnie listę monitorowanych podmiotów
   i token (zakładka **Ustawienia → KRZ (wtyczka)**).
2. Wtyczka pobiera tę listę i osobno dla każdego źródła otwiera KRZ oraz darmową
   wyszukiwarkę MSiG w Twojej przeglądarce, wyszukuje podmiot po KRS/NIP, odczytuje
   obwieszczenie i odsyła jego treść do programu (`content_krz.js` dla KRZ,
   `content_msig.js` dla MSiG).
3. Program rozpoznaje typ i sygnaturę, deduplikuje i pokazuje wpis obok KRS i MSiG —
   dokładnie jak przy ręcznym wklejaniu, tylko automatycznie.

Działa codziennie o ustawionej godzinie (domyślnie 10:00) **gdy przeglądarka jest
uruchomiona**, oraz na żądanie (ikona wtyczki → „Sprawdź KRZ teraz"). Jest też przycisk
**„📤 Wyślij obwieszczenie do DUiR"** na stronie KRZ — gdy oglądasz obwieszczenie ręcznie,
jednym kliknięciem trafia ono do programu (z automatycznym dopasowaniem podmiotu).

## Instalacja (Chrome / Edge)

### A. Szybko — „Załaduj rozpakowane" (do testów / pojedynczego stanowiska)
1. Otwórz `chrome://extensions` (lub `edge://extensions`).
2. Włącz **Tryb dewelopera** (prawy górny róg).
3. Kliknij **Załaduj rozpakowane** i wskaż ten folder (`chrome_extension`).
4. Kliknij ikonę wtyczki → **Ustawienia**:
   - **Adres programu**: `http://127.0.0.1:8080` (wersja desktop) lub adres z paska przeglądarki,
   - **Token**: skopiuj z programu (Ustawienia → KRZ (wtyczka) → Kopiuj),
   - **Test połączenia** → powinno pokazać „Połączono ✓".

### B. Automatyczne zgłoszenie z akceptacją (docelowo, dla kancelarii)
Cel: instalator programu **sam zgłasza** wtyczkę, a przeglądarka **prosi użytkownika
o jej włączenie** (akceptacja) — bez trybu dewelopera, bez cichego wymuszania.

Mechanizm to „external extension": instalator zapisuje w rejestrze (per-użytkownik)
`HKCU\Software\Google\Chrome\Extensions\<ID>` oraz `…\Microsoft\Edge\Extensions\<ID>`
z wartością `update_url = https://clients2.google.com/service/update2/crx`. Przy
następnym starcie Chrome/Edge pokazuje monit „Włącz nowe rozszerzenie?" — użytkownik
akceptuje. Wpisy są już przygotowane w `installer/dziennik.iss` (sekcja `[Registry]`)
i aktywują się, gdy w `#define ChromeExtId` wpiszesz realny identyfikator.

> **Warunek:** Chrome (Windows) blokuje rozszerzenia spoza Web Store, więc ten monit
> akceptacji działa po **opublikowaniu wtyczki w Chrome Web Store** (może być „niepubliczna").
> To jednorazowy krok (konto dewelopera Google). Po publikacji wpisujesz ID do
> `#define ChromeExtId` i instalator robi resztę. Do tego czasu używaj wariantu A
> („Załaduj rozpakowane"), a program i tak ostrzega na każdej stronie, gdy wtyczka jest
> nieaktywna („KRZ nie jest analizowany automatycznie").

> Alternatywa bez akceptacji (ciche wymuszenie, niezalecane przy tym wymaganiu):
> `ExtensionInstallForcelist` — instaluje po cichu, użytkownik nie może wyłączyć. Nie
> spełnia wymogu „wymaga akceptacji", dlatego instalator używa metody z monitem.

## Tryb lokalny vs VPS

Instalator pyta na starcie, czy program działa **lokalnie**, czy na **serwerze VPS**:

- **Lokalnie** — program działa na tym komputerze, wtyczka łączy się z `http://127.0.0.1:8080`. Token jest generowany przy instalacji, zapisany w `config.env` i wpisany do wtyczki — nic nie konfigurujesz ręcznie.
- **VPS** — program i raporty działają na serwerze; podajesz w instalatorze **adres serwera** i **token** (z panelu na VPS → Ustawienia → KRZ). Wtyczka w Twojej przeglądarce przechodzi Imperva jak człowiek i **wysyła obwieszczenia KRZ na adres VPS**. Serwer sam z siebie KRZ nie odpyta (jest bezgłowy) — to przeglądarka wykonuje sprawdzenie, a wynik trafia centralnie na serwer.

Na serwerze endpointy `/api/krz/*` są zwolnione z logowania (chroni je token mostka), więc wtyczka może je wywołać mimo włączonego panelu z hasłem. Zmianę tę trzeba wdrożyć na VPS (aktualizacja do wersji ≥ 1.0).

Adres i token można też w każdej chwili zmienić ręcznie w **Ustawieniach wtyczki**.

## Dostrojenie do żywego portalu

Wyszukiwanie w KRZ (wypełnienie formularza i otwarcie wyniku) jest „best-effort" — układ
portalu (aplikacja Angular) bywa zmieniany. Niezależnie od tego **przechwytywanie treści
obwieszczenia działa zawsze** (czyta widoczny tekst strony). Jeśli automatyczne
wyszukiwanie nie trafia w pola, użyj przycisku „📤 Wyślij obwieszczenie do DUiR" po ręcznym
otwarciu obwieszczenia — efekt jest ten sam (auto-dopasowanie + dedup), a selektory
wyszukiwania dostroję na podstawie zrzutu z konsoli (`[DUiR/KRZ]`).

## Bezpieczeństwo

- Token jest lokalny; mostek nasłuchuje tylko na `127.0.0.1`/`localhost`.
- Wtyczka komunikuje się wyłącznie z portalami KRZ i MSiG (`*.ms.gov.pl`, w tym
  `wyszukiwarka-msig.ms.gov.pl`) oraz Twoim programem na localhost (zob.
  `host_permissions` w `manifest.json`).
- Brak danych wysyłanych do internetu poza zapytaniami do KRZ i MSiG, które i tak robisz ręcznie.
