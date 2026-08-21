# Raport weryfikacji wtyczki Chrome KRZ

Data: 2026-06-28
Zakres: DUiR PHP/MySQL 1.0.0 + wtyczka `chrome_extension`

## Wynik

Wtyczka została zweryfikowana statycznie i kontraktowo. W trakcie kontroli wykryto i poprawiono trzy problemy, które mogły realnie blokować działanie wtyczki po stronie użytkownika.

## Wykryte problemy i poprawki

### 1. Nieprawidłowy domyślny adres backendu

Wtyczka nadal miała domyślny adres starej wersji desktopowej:

```text
http://127.0.0.1:8765
```

Wersja PHP/MySQL uruchamiana przez Docker Compose wystawia panel na porcie:

```text
http://127.0.0.1:8080
```

Poprawiono:

- `chrome_extension/background.js`,
- `chrome_extension/options.js`,
- `chrome_extension/options.html`,
- dokumentację wtyczki.

### 2. Tryb VPS nie miał poprawnej zgody hosta

Manifest miał uprawnienia do lokalnego backendu i domen KRZ, ale nie pozwalał automatycznie połączyć się z dowolnym adresem VPS podanym w ustawieniach. To oznaczało, że lokalnie mogło działać, ale VPS mógł kończyć się błędem połączenia.

Poprawiono:

- dodano `optional_host_permissions` dla `http://*/*` i `https://*/*`,
- w `options.js` dodano `chrome.permissions.request(...)`,
- przy zapisie/testowaniu adresu VPS wtyczka prosi użytkownika o zgodę na dostęp do konkretnego hosta.

Uprawnienia globalne nie są obowiązkowe przy instalacji. Są proszone dopiero wtedy, gdy użytkownik wpisze adres VPS.

### 3. Ręczne wysłanie wyniku KRZ mogło nie dopasować podmiotu

Przycisk „Wyślij wynik KRZ do DUiR” próbował dopasować podmiot na podstawie kolejki zadań KRZ. Jeżeli użytkownik ręcznie otworzył wynik KRZ bez aktywnego zadania w kolejce, dopasowanie mogło się nie udać.

Poprawiono:

- dodano endpoint `/api/krz/subjects`,
- dodano `Repository::monitoredKrzSubjects()`,
- wtyczka używa teraz pełnej listy monitorowanych podmiotów do ręcznego dopasowania,
- endpoint `/api/krz/worklist` nadal służy tylko do realnych zadań i może oznaczać je jako `running`.

### 4. Domyślny adres portalu KRZ

Fallback portalu KRZ ustawiono na:

```text
https://portal-pub-prod.apps.ocp.prod.ms.gov.pl/
```

Wtyczka nadal ma uprawnienia także do `krz.ms.gov.pl` i `prs.ms.gov.pl`, więc działa zarówno przez wejście z portalu PRS, jak i bezpośrednio przez publiczny portal KRZ.

## Weryfikacja techniczna

Wykonano dwie pełne iteracje kontroli:

### Iteracja 1

- `node --check chrome_extension/background.js` — OK
- `node --check chrome_extension/content_krz.js` — OK
- `node --check chrome_extension/options.js` — OK
- `node --check chrome_extension/popup.js` — OK
- PHP lint dla `app`, `public`, `tests` — OK
- `php tests/run.php` — 29 testów passed

### Iteracja 2

- `node --check chrome_extension/background.js` — OK
- `node --check chrome_extension/content_krz.js` — OK
- `node --check chrome_extension/options.js` — OK
- `node --check chrome_extension/popup.js` — OK
- PHP lint dla `app`, `public`, `tests` — OK
- `php tests/run.php` — 29 testów passed

## Nowe testy regresyjne

Dodano testy sprawdzające, że:

- wtyczka domyślnie używa portu `8080`, zgodnego z PHP/Docker Compose,
- tryb VPS ma obsługę opcjonalnej zgody hosta,
- globalny dostęp `https://*/*` nie jest obowiązkowym uprawnieniem instalacyjnym,
- istnieje endpoint `/api/krz/subjects` do ręcznego dopasowania wyników KRZ bez przestawiania kolejki zadań.

## Ograniczenie

Nie wykonano testu live w prawdziwym Chrome na portalu KRZ, ponieważ wymaga to sesji przeglądarkowej użytkownika i dostępu do działającego środowiska. Zweryfikowano natomiast kod wtyczki, manifest, kontrakt API, router PHP oraz testy regresyjne.

## Wniosek

Po poprawkach wtyczka ma dużo większe prawdopodobieństwo poprawnego działania niż wersja z paczki 1.0.0. Najważniejsze ryzyka: zmiana DOM portalu KRZ oraz brak zgody użytkownika na host VPS. Oba przypadki są teraz obsługiwane lepiej: pierwszy przez status błędu/niepewności, drugi przez request permission w ustawieniach.
