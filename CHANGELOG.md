# Changelog

## KRZ 2026-10-27 — to samo postępowanie wpadało do bazy jako nowe zdarzenie

- OBJAW (feedback: „wyszukiwanie, w szczególności w KRZ, wychodzi gorzej"):
  39 zdarzeń KRZ w bazie, z czego 18 to kopie tych samych 19 postępowań —
  po 2, 3, a nawet 4 wiersze na jedną sygnaturę, część powstała w jednym
  przebiegu. Raport dzienny pokazywał te same sprawy jako nowe, a poziom
  ryzyka potrafił się przy tym zmienić (ARS ZG1E/GRz-nu/5/2022: `found_active`
  → `found_closed_positive_or_neutral`; AD MARKET GD1G/GRz-nu/85/2025 →
  `found_bankruptcy_closed`).
- PRZYCZYNA: zdarzenia KRZ nie niosły własnego klucza, więc `Repository::addEvent`
  liczyło `eventHash()` — sha256 z m.in. tytułu i PIERWSZYCH 180 ZNAKÓW OPISU.
  Moduły KRZ renderują się w ramkach i wtyczka za każdym razem przechwytuje inny
  wycinek strony: raz nagłówek z danymi podmiotu, raz samą tabelę postępowań
  (zdarzenia 1955/1956 zaczynają się odpowiednio od „h prowadzących działalność…"
  i „nia Postępowania restrukturyzacyjne…"). Inny początek opisu = inny skrót =
  nowy wiersz. Gałąź KRS nie miała tego problemu, bo ma `proceedingKey()`, który
  celowo pomija treść.
- FIX: `RiskAnalyzer::krzDedupeHash(subject_id, sygnatura, data obwieszczenia)` —
  klucz bez treści, dokładana do zdarzenia w `eventFromKrzChunk()` (tylko gdy jest
  sygnatura i id podmiotu; inaczej zostaje stara ścieżka). Rodzaju postępowania
  świadomie NIE ma w kluczu: wynika z treści, więc bogatszy odczyt potrafi go
  zmienić i duplikat wróciłby tylnymi drzwiami. `ON DUPLICATE KEY UPDATE` w
  `addEvent` odświeża teraz treść i ryzyko istniejącego wiersza zamiast dokładać
  nowy.
- PRZY OKAZJI: luźny wzorzec „Sygnatura …" brał z pustego wiersza-nagłówka tabeli
  sygnaturę „Data rejestracji Data zakończenia Status" i robił zdarzenie z niczego
  (zdarzenie #239 z lipca). Sygnatura bez ani jednej cyfry jest odrzucana.
- MIGRACJA (nic nie usunięta): w każdej grupie duplikatów nowy klucz dostał
  NAJSTARSZY wiersz — zachowuje datę pierwszego wykrycia, od której zależy sekcja
  „nowe wpisy" w raporcie — a jego treść/ryzyko wzięte z najnowszego, najbogatszego
  przechwycenia. 19 wierszy przeklonowanych, 18 starych kopii ZOSTAŁO w bazie;
  przestaną się tylko mnożyć. Kopia wszystkich zdarzeń KRZ sprzed zmiany:
  `storage/backups/events_krz_pre_dedupe_20261027.json` (z sieci 403).
- WERYFIKACJA na danych produkcyjnych: wszystkie 61 zapisanych przechwyceń KRZ
  (`raw_json.chunk`) przepuszczone ponownie przez `krzEventsFromText` — 60 trafia
  w istniejący wiersz, 0 utworzyłoby nowy (przed poprawką nagłówka: 1). Testy 140/140.
- DO DECYZJI WŁAŚCICIELA: 18 starych kopii i dwa stare puste zdarzenia (#239, #1282)
  nadal widać w kartach podmiotów — scalenie/usunięcie to osobna decyzja.

## Infrastruktura 2026-10-27 — cron DUiR martwy od 23.10 (HTTP zamknięty, crontab nie zmieniony)

- OBJAW (raport dzienny 26.10): wszystkie 33 podmioty i wszystkie źródła
  „brak świeżego sprawdzenia", ostatnie wpisy KRS/CEIDG z 23.10 06:45,
  KRZ/MSiG z 23.10 ~07:2x–07:4x. `cron_last_run_at` = `2026-10-23T06:45:35`.
  Wtyczka cały czas pingowała (raport: „Wtyczki są aktywne (1 komp.)"), tylko
  kolejka była pusta — nie miała czego wykonać.
- PRZYCZYNA: 23.10 o 13:36 `cron.php` i `CronController::run()` zostały
  zamknięte na wywołania HTTP (`PHP_SAPI !== 'cli'` → 403) po tym, jak token
  w adresie URL wyciekł. Wpis w crontabie konta g4326 został BEZ ZMIAN:
  `45 6 * * * wget -q -O - 'https://duir.../cron.php?token=...' >> /dev/null 2>&1`.
  Od 24.10 cron codziennie dostawał 403 i kończył się cicho — `>> /dev/null 2>&1`
  i `MAILTO=""` sprawiały, że nie było po tym żadnego śladu.
- FIX: wpis w crontabie zamieniony na wywołanie z wiersza poleceń
  `45 6 * * * cd /home/g4326/domains/kantorowskiglab.pl/public_html/duir && /usr/local/bin/php85 cron.php >> /home/g4326/kopie-crm/duir.log 2>&1`
  (php85, bo `.htaccess` ustawia dla tego katalogu `x-httpd-php85`; token nie jest
  już potrzebny — bramką jest sam SAPI CLI, a przy okazji sekret znika z crontaba).
  Metoda zmiany: `crontab -l` do pliku `~/kopie-crontab/crontab-20261027-przed-duir-cli.txt`,
  edycja pliku, `crontab plik` — nigdy potokiem (65 linii przed i po, różnica w 1 linii).
  Wynik przekierowany do `~/kopie-crm/duir.log`, żeby następna cicha awaria
  zostawiła ślad.
- NADROBIENIE: `php85 cron.php` uruchomiony ręcznie 27.10 19:28 — 34 podmioty,
  19,5 s, KRS 21 × success, CEIDG 13 × success, 34 zadania KRZ i 34 MSiG w kolejce;
  `krz_sweep_requested_at`/`msig_sweep_requested_at` ustawione na „teraz", więc
  wtyczka podjęła przebieg o 19:31.
- SYNCHRONIZACJA REPO: produkcja miała od 23.10 trzy pliki, których NIE było w
  repozytorium (`cron.php`, `app/Controllers/CronController.php`,
  `app/Support/Client.php` — usunięte `curl_close()`, od PHP 8.5 Deprecated) oraz
  `.htaccess` (handler `x-httpd-php85` zamiast `x-lsphp82`). Pełne wgranie folderu
  z repozytorium COFNĘŁOBY zamknięcie crona na HTTP. Pliki wciągnięte do repo;
  w `.htaccess` przywrócony katalog `knowledge` na liście blokowanych (serwerowa
  wersja go zgubiła) i ta wersja wgrana na serwer (kopia:
  `.htaccess.przed-knowledge-20261027`). Zweryfikowane: `/` 303, `/assets/app.css` 200,
  `/knowledge/` 403, `/.env` 403. Testy: 137/137.
- DO OBSERWACJI: wtyczka zapisuje `lastRunDate` po KAŻDYM przebiegu, także gdy
  kolejka była pusta (`dailyRunCompleted` nie patrzy na liczbę zadań). Jeśli cron
  serwera spóźni się za 7:00, wtyczka „zużywa" dzień i zadania czekają do jutra —
  dzisiaj trzeba było ręcznie ustawić sweep.

## Infrastruktura 2026-09-07 — cron DUiR zniknął z crontaba (nie wina kodu)

- OBJAW (raport 07.09): każdy podmiot i każde źródło (KRS/KRZ/MSiG) pokazywał
  „brak świeżego sprawdzenia" — `cron_last_run_at` w bazie utknęło na
  `2026-09-04 09:50:07`, zero aktywności w `source_checks` od tego momentu.
- DIAGNOZA: ręczne uruchomienie `cron.php` zadziałało poprawnie (10,4 s,
  9/9 podmiotów, HTTP 200) — kod jest sprawny. `crontab -l` na koncie g4326
  pokazywał WYŁĄCZNIE wpis monitoringu KSeF (`crm/scripts/monitoruj-ksef.php`,
  7:10); wpis DUiR (`cron.php?token=...`, 09:50) zniknął całkowicie. Plik
  crontaba i skrypt KSeF miały bardzo świeże znaczniki czasu modyfikacji —
  wygląda na to, że przy dodawaniu zadania CRON dla KSeF crontab został
  NADPISANY w całości (nie dopisany), co skasowało wpis DUiR.
- FIX: przywrócono wpis `50 9 * * * wget -q -O - 'https://duir.kantorowskiglab.pl/
  cron.php?token=...' >> /dev/null 2>&1` metodą DOPISANIA do istniejącej
  zawartości (nie nadpisania) — wpis KSeF zachowany. Backup poprzedniej
  zawartości crontaba: `/tmp/crontab_backup_20260907.txt` na serwerze (uwaga:
  `/tmp` może być czyszczony przez system, to tylko dodatkowa siatka
  bezpieczeństwa, treść jest też w tym wpisie CHANGELOG).
- LEKCJA: crontab na tym koncie jest współdzielony między projektami DUiR i
  CRM/KSeF — każda przyszła zmiana crontaba na koncie g4326 (dowolny projekt)
  MUSI dopisywać do istniejącej zawartości (`crontab -l` → dopisz → `crontab -`),
  nigdy nie nadpisywać pliku bez wcześniejszego odczytania go.

## Dane 2026-09-02 — scalenie zduplikowanego podmiotu SŁAWOMIR KAŹMIERCZAK

- W bazie istniały dwa rekordy tego samego podmiotu (NIP 6581003887): id=18
  (utworzony 2026-07-15, historia: 152 source_checks/36 krz_tasks/36
  msig_tasks/0 events) i id=19 (przypadkowy duplikat z 2026-08-21: 41
  source_checks/1 event/10 krz_tasks/10 msig_tasks/2 reports). Oba
  sprawdzane były CODZIENNIE równolegle (identyczny `last_checked_at`) i oba
  pojawiały się w każdym raporcie dziennym.
- FIX: cała historia z id=19 PRZENIESIONA (nie skasowana) pod kanoniczny
  id=18 (starszy, głębsza historia) — `UPDATE ... SET subject_id=18 WHERE
  subject_id=19` na `source_checks/events/krz_tasks/msig_tasks/reports`,
  dopiero potem usunięty pusty rekord `subjects` id=19. Backup pełnej treści
  obu rekordów przed scaleniem: `storage/backups/subjects_18_19_pre_merge_20260902.json`
  (katalog `storage/` blokowany z zewnątrz przez `.htaccess` — zweryfikowane HTTP 403).
  Zweryfikowane po scaleniu: 193 source_checks / 1 event / 46 krz_tasks / 46
  msig_tasks / 2 reports pod id=18, id=19 nie istnieje, bramka wtyczki
  zwraca teraz 9 podmiotów (było 10) z jednym Kaźmierczakiem.
- LOCKUS K2 (id=16): `service_mode='one_time'`, `monitored=0`,
  `last_checked_at=2026-07-13` — to NIE był błąd, tylko świadome
  zachowanie trybu jednorazowego (sprawdzenie zrobione raz, celowo poza
  bieżącym monitoringiem). Na wyraźne życzenie użytkownika przełączony na
  `office_monitoring`/`monitored=1` (tryb większości podmiotów-spółek w tej
  bazie) przez `Repository::updateSubject()` — od jutrzejszego crona wchodzi
  do codziennego sprawdzania i będzie pojawiać się w raportach.

## Serwer 2026-09-02 — KRS/CEIDG: prawdziwe ponowienia zamiast ślepego "Nie udało się pobrać URL"

- BŁĄD (raporty 31.08 i 01.09: AD MARKET, ADCOOKIE, FAN M.M. ANTONIUK, HORTINO,
  SMART & GREEN — wszystkie „Nie udało się pobrać odpisu KRS"; PETSTARS — „Nie
  udało się pobrać URL" z CEIDG): sześć realnych numerów KRS z bazy sprawdzonych
  bezpośrednio na API Ministerstwa dało 6× HTTP 200 w ~0,2 s — źródło i numery
  były sprawne, awaria była po stronie DUiR.
- ROOT CAUSE #1 (`app/Support/Client.php`): `getJson()`/`postJson()` używały
  `file_get_contents()` bez ŻADNEGO ponowienia — jedno przejściowe mignięcie
  sieci (DNS/TCP/TLS) na hostingu kończyło zapytanie od razu, bez szans na
  powtórzenie za chwilę.
- ROOT CAUSE #2 (`app/Services/KrsClient.php::safeGet()`): łapał KAŻDY
  `\Throwable` i zwracał `null` — odmowa połączenia, błąd HTTP i uszkodzony
  JSON dawały identyczny, ślepy komunikat. Dla porównania KRZ/MSiG zapisują
  meta+próbkę treści przy błędzie (stąd konkretne komunikaty), więc przy KRS
  nie dało się nic rozpoznać z karty podmiotu.
- FIX: `Client.php` przepisany na cURL z do 3 próbami i odstępem (400 ms,
  900 ms) dla awarii TRANSPORTOWYCH (brak połączenia, 5xx, 429) — błąd 4xx
  (poza 429) i nieprawidłowy URL/schemat kończą się od razu, bo ponowienie
  dałoby identyczny wynik. Fallback do starego `file_get_contents`, gdyby
  cURL nie był dostępny na jakimś SAPI. `KrsClient::safeGet()` zapisuje teraz
  przyczynę każdego nieudanego zapytania (`raw_json.fetch_errors`) — widoczna
  pod „🔍 Diagnoza" zamiast ginąć.
- Dodatkowo: `CheckService::checkAllMonitored()` wstawia 300 ms odstępu
  MIĘDZY podmiotami w pętli crona — jedno przeciążenie źródła nie zabiera już
  całej serii sprawdzeń naraz.
- WDROŻENIE: zsynchronizowano też rozjazd wersji — produkcja miała
  `MIN_PLUGIN_VERSION=1.8.5` z 17.07 (żaden sierpniowy fix do
  Krz/MsigApiController nie był wdrożony), lokalnie już `1.8.6`. Wgrano cały
  folder `app/` (kopia poprzedniej wersji: `app_old_20260902` na serwerze).
  Zweryfikowano na żywo: `cron.php` przez HTTP 200, 9 podmiotów, bez błędu.
- Testy: `tests/test_http_client.php` (nowy) — realne ponowienie z odstępem
  (min. 1200 ms) na odrzuconym porcie lokalnym, brak ponowień przy złym
  schemacie URL, propagacja `fetch_errors` z `KrsClient`. Suite 133/133.
- OBSERWACJA UBOCZNA (niezmieniona w tym fixie — wymaga decyzji użytkownika):
  LOCKUS K2 (id 16, monitored prawdopodobnie=0 albo tryb jednorazowy) nie
  pojawia się w raportach od kilku dni — do sprawdzenia na karcie podmiotu.
  SŁAWOMIR KAŹMIERCZAK istnieje w bazie DWUKROTNIE (subject_id 18 i 19,
  identyczne NIP/REGON) — prawdopodobny duplikat do scalenia/usunięcia.

## Wtyczka 1.8.8 (2026-08-27) — KRZ: metadane wiersza dostają jawny identyfikator podmiotu

- BŁĄD (user, raport z 26.08: „Wygląda na to że KRZ w ogóle nie trafia" — trzy
  podmioty z realnymi wpisami, AD MARKET, FAN M.M. ANTONIUK, RAFAŁ MUCHARSKI,
  kończyły się błędem „wynik nie zawiera identyfikatora ani zgodnej nazwy
  podmiotu"): to sam fix 1.8.7 (wysyłka `flush()` metadanych wiersza PRZED
  ryzykownym kliknięciem w obwieszczenie) wprowadził tę regresję.
- ROOT CAUSE: metadane samego wiersza postępowania KRZ (rodzaj postępowania,
  sygnatura, daty, status) opisują SPRAWĘ, nie DŁUŻNIKA — z natury nie zawierają
  jego NIP/PESEL/nazwy. Niezależna bramka `captureMatchesSubject` w
  `background.js` (funkcja `matchSubject`, wspólna z odpowiednikiem serwerowym
  `RiskAnalyzer::textMatchesSubject`) wymaga twardego identyfikatora albo nazwy
  W SAMEJ TREŚCI przechwyconej pozycji — i odrzucała taki fragment jeszcze PRZED
  wysyłką do serwera, mimo że strona wyników JUŻ wcześniej przeszła
  `resultRowsMatchCurrentQuery(job)`, czyli była potwierdzona jako dotycząca
  właściwego podmiotu.
- FIX: `flush()` w `content_krz.js` dopisuje teraz do każdego wysyłanego
  fragmentu jawne kryterium wyszukiwania (`job.query` — identyfikator/nazwę,
  którymi KRZ już przeszukano) w formacie `[Kryterium wyszukiwania: ...]` — ten
  sam, już zaufany wzorzec, jaki `visiblePageText()` stosuje od dawna przy
  potwierdzonym braku wyników. Nie osłabia to żadnej bramki (klienckiej ani
  serwerowej) — czyni jedynie jawną informację, którą strona już potwierdziła.
- Testy: `tests/test_extension.php` — 130/130 przechodzi lokalnie (kontrakt na
  treść pliku; brak dostępu do żywego portalu KRZ w tej sesji, więc fix
  NIEZWERYFIKOWANY na żywym portalu — do potwierdzenia w kolejnym raporcie
  dziennym).
- To plik wtyczki Chrome (`chrome_extension/`) — NIE jest wdrażany na serwer
  WWW; działa lokalnie z folderu wczytanego jako „unpacked" w Chrome.

## Wtyczka 1.8.7 (2026-08-21) — KRZ: dane wysyłane NA BIEŻĄCO, nie na końcu drążenia

- BŁĄD (user: „działa gdy KRZ puste, zawodzi gdy ma wpisy" — trafna obserwacja):
  jedyny monitorowany podmiot z realnym wpisem w KRZ (wymagający klikania w
  obwieszczenia) systematycznie kończył się błędem „ramka formularza nie
  zgłosiła wyniku", mimo wcześniejszych poprawek timingu/watchdoga. Podmioty
  bez wyników nigdy nie docierały do tego kodu — stąd zawsze działały.
- ROOT CAUSE: pozycje (metadane postępowania, treść obwieszczenia) zbierane były
  WYŁĄCZNIE w pamięci ramki i wysyłane RAZEM na samym końcu drążenia. Klikanie
  w obwieszczenie to interakcja z nieznanym elementem PORTALU — jeśli poskutkuje
  pełną nawigacją modułu (iframe wymienia całą treść) zamiast wewnętrznej trasy
  SPA, kontekst JS tej ramki ginie W TRAKCIE działania, bez wyjątku do złapania.
  Wszystko zebrane do tego momentu przepadało bez śladu; serwer po watchdogu
  widział czyste milczenie i zgłaszał błąd — mimo że dane były już w zasięgu ręki.
- FIX: każda pozycja jest teraz wysyłana OD RAZU (funkcja `flush`) — metadane
  wiersza (sygnatura, rodzaj, daty, status) PRZED jakimkolwiek ryzykownym
  kliknięciem, a bogatsza treść obwieszczenia (jeśli drążenie się powiedzie)
  NADPISUJE ją tym samym mechanizmem dedupe_hash po stronie serwera (bez zmian
  w PHP). Nawet gdy ramka zginie w połowie, to co już zdobyto jest bezpiecznie
  zapisane — `job.captures` w background.js (który przetrwa śmierć ramki/karty,
  bo żyje w service workerze) sprawia, że KrzApiController::runFinished poprawnie
  ignoruje późniejszy błąd/watchdog (`if(!empty($item['captured'])) continue;`
  już istniało i było gotowe na to zanim jeszcze o tym wiedziałem).
- Testy: 129/129 (nowy: test_krz_flushes_each_item_immediately_before_risky_notice_click).
- WAŻNE: to plik wtyczki Chrome — działa LOKALNIE w przeglądarce użytkownika,
  NIE na serwerze WWW. Nie wymaga (i nie ma czego) wdrażać przez SSH — jedyny
  krok: przeładować wtyczkę w chrome://extensions.
- NIEZWERYFIKOWANE na żywym portalu (brak dostępu do przeglądarki w tej sesji) —
  hipoteza „ryzykowne kliknięcie zabija ramkę" nie została potwierdzona wizualnie,
  ale mechanizm naprawczy (wysyłka na bieżąco) jest odporny na TĘ i KAŻDĄ inną
  przyczynę nagłej śmierci ramki w trakcie drążenia — więc naprawia obserwowany
  skutek niezależnie od precyzyjnego zdiagnozowania samego wyzwalacza.

## Serwer 2026-08-11 (3) — KRS: adres wreszcie się składa (był ZAWSZE pusty)

- BŁĄD (osobny od poprawki nazwy, ten sam dzień): adres KRS był ZAWSZE pusty —
  nie tylko dla spółek wykreślonych. Przyczyna: findValue() szuka JEDNEJ wartości
  pod jedną nazwą klucza, a adres to kilka pól złożonych w tekst (ulica, nr domu,
  kod pocztowy, miejscowość) — nigdy nie było tam gołego skalara do znalezienia,
  nawet dla odpisu aktualnego (płaski obiekt bez historii).
- FIX: nowa KrsClient::extractAddress() składa adres z pól cząstkowych. Priorytet:
  „adres" (pełny adres pocztowy) przed „siedziba" (tylko miejscowość/powiat/
  województwo, fallback gdy adresu brak). Obsługuje OBA kształty odpisu: płaski
  obiekt (aktualny) i listę historycznych wpisów (pełny — bierze NAJNOWSZY,
  tym samym mechanizmem co poprawka nazwy).
- Zweryfikowane na 6 rzeczywistych odpisach (curl) + na żywo na produkcji:
  Alfa Energia → „UL. PRZYKŁADOWA 3, 00-001 WARSZAWA" (wcześniej: puste).
  Używane w podglądzie „Pobierz dane z rejestrów" na karcie podmiotu.
- Testy: 128/128 (2 nowe, na dosłownych strukturach z żywych odpowiedzi API).

## Serwer 2026-08-11 (2) — KRS: poprawna nazwa historyczna + informowanie o zmianie nazwy

- BŁĄD: KrsClient::findValue dla pól, które zmieniły się w historii spółki (nazwa,
  adres, siedziba — w odpisie PEŁNYM zapisanych jako LISTA wpisów), brał PIERWSZY
  wpis (najstarsza wartość), nie ostatni. Przykład: spółka zarejestrowana jako
  „ALFA ENERGIA", przemianowana na „BETA GRUPA" na lata przed wykreśleniem —
  DUiR pokazywał pierwotną nazwę, portal KRS pokazuje ostatnią.
- FIX: findValue rozpoznaje listę wpisów (po obecności `nrWpisuWprow` w elementach)
  i wybiera wpis o NAJWYŻSZYM numerze — czyli najpóźniej wprowadzoną wartość.
  Naprawia legal_name; adres/siedziba pozostają nierozwiązane z INNEGO, wcześniej
  istniejącego powodu (pole złożone z wielu podpól — findValue nigdy go nie składał,
  nawet dla odpisu aktualnego; poza zakresem tej poprawki).
- NOWOŚĆ (na życzenie): gdy KRS/CEIDG ujawni nazwę różną od zapisanej w DUiR, oprócz
  trwałego zapisu w aliasach powstaje teraz zdarzenie „KRS/CEIDG: zaktualizowano
  nazwę…" (ryzyko: niskie, informacyjne) — widoczne na karcie podmiotu i w raporcie
  dziennym, nie tylko w ukrytej kolumnie aliasów. Idempotentne — kolejne sprawdzenia
  z tą samą nazwą nie duplikują zdarzenia.
- Zweryfikowane na produkcji: Alfa Energia (KRS 0000111222) — po ponownym sprawdzeniu
  alias „BETA GRUPA…” zapisany, zdarzenie utworzone, drugie sprawdzenie nie
  zduplikowało go. Testy: 126/126 (nowy test na prawdziwej strukturze historii nazwy;
  test zdarzenia zweryfikowany ręcznie na produkcji — Repository wymaga MySQL,
  SQLite odrzuca `SHOW COLUMNS`/`ON DUPLICATE KEY UPDATE` używane gdzie indziej w klasie).

## Serwer 2026-08-11 — KRS: działy 4 i 5 odpisu (zaległości, kurator) przestały być ignorowane

- BŁĄD: KrsClient::extractProceedings czytał WYŁĄCZNIE dział 6 odpisu KRS
  (postępowania upadłościowe/restrukturyzacyjne). Dział 4 (zaległości podatkowe
  i celne, umorzenie egzekucji z braku majątku) i dział 5 (kurator ustanowiony
  z powodu braku organów, art. 42 k.c.) były CAŁKOWICIE ignorowane — nawet gdy
  miały treść. Znalezione na przykładzie ALFA ENERGIA: kurator ustanowiony od
  2017 r., zero wzmianki w danych DUiR przez całą historię monitoringu.
- FIX: KrsClient::extractProceedings czyta teraz strukturalnie działy 4, 5 i 6
  (nowe mapy D4_SECTIONS/D5_SECTIONS, nieznane sekcje wciąż raportowane pod
  nazwą klucza — nic nie ginie po cichu). RiskAnalyzer::riskFromText: nowa
  gałąź „kurator" → ryzyko wysokie (podmiot istnieje, ale zwykły obrót z nim
  jest utrudniony/wątpliwy).
- Zweryfikowane na PRAWDZIWYCH danych: curl do api-krs.ms.gov.pl (ten sam
  publiczny endpoint, którego używa KrsClient) + wizualne potwierdzenie na
  wyszukiwarka-krs.ms.gov.pl (wpis widoczny tylko w odpisie PEŁNYM/historycznym,
  zgodnie z architekturą proceedings/historical_proceedings). Po wdrożeniu
  ponowne sprawdzenie Alfa Energia utworzyło zdarzenie „KRS: Kurator ustanowiony
  wobec podmiotu" (wysoki) — potwierdzone w bazie produkcyjnej.
- Przy okazji: dodane etykiety pól nazwiskoICzlon/imie/pesel (wcześniej
  wyświetlane jako surowe nazwy kluczy API w opisach syndyka/kuratora).
- UWAGA (nie naprawione, do decyzji): portal KRS pokazuje tę spółkę jako
  „BETA GRUPA SP. Z O.O.", a w DUiR figuruje jako „ALFA ENERGIA..." —
  możliwa nieaktualna nazwa w bazie (do weryfikacji, poza zakresem tej poprawki).
- Testy: 125/125. Zmiana WYŁĄCZNIE server-side (app/Services/KrsClient.php,
  RiskAnalyzer.php) — chrome_extension NIE dotyczy (KRS nie przechodzi przez
  wtyczkę, to publiczne REST API wołane z serwera).

## 1.8.6 (2026-08-10) — wspólny zegar zadania KRZ i odporność na losowy błąd Chrome

- Audyt zgłoszenia: raport dzienny z 2026-08-10 pokazywał powtarzające się błędy KRZ
  akurat u podmiotów z NIEPUSTYM wynikiem (Mucharski — realne postępowanie) oraz przy
  otwieraniu karty automatu (ADCOOKIE, Sławomir Kaźmierczak). Dwie niezależne przyczyny:
- **Rozjazd zegara zadania między ramkami (Mucharski)**: `JOB_STARTED_AT` w
  `content_krz.js` był ustawiany osobnym `Date.now()` w KAŻDEJ ramce — top i formularz
  to dwa OSOBNE konteksty JS bez współdzielonych zmiennych. Gdy SPA portalu
  renderowało iframe formularza z opóźnieniem (typowe przy realnym wpisie, bo trzeba
  wejść w treść obwieszczenia), ta ramka dostawała świeże 80s budżetu drążenia
  (`DRILL_DEADLINE_MS`, 1.8.3) liczone od WŁASNEGO, spóźnionego startu, podczas gdy
  watchdog ramki głównej (105s) liczył od startu KARTY. Budżet drążenia mógł więc
  kończyć się PO watchdogu zamiast przed nim — dokładnie odwrotnie niż zakładał
  komentarz w kodzie („wspólny zegar odniesienia"), bo nic go faktycznie nie
  współdzielił. Naprawa: `background.js` znakuje moment utworzenia karty
  (`jobsByTab[tab.id].startedAt`) i przekazuje go w odpowiedzi na `krzReady`; obie
  ramki liczą budżet od TEGO SAMEGO `job.startedAt` (`runJob` → `JOB_STARTED_AT`,
  `runJobTopFrame` → `t0 = JOB_STARTED_AT`), więc drążenie zawsze kończy się z
  zapasem przed watchdogiem niezależnie od tego, jak długo SPA renderowało formularz.
- **Chwilowy błąd Chrome przy otwieraniu karty (ADCOOKIE, Kaźmierczak)**:
  `chrome.tabs.create`/`chrome.tabs.update` w `processItemInTab` potrafiły dostać
  „Tabs cannot be edited right now (user may be dragging a tab)" — to wewnętrzna
  niespójność Chrome tuż po zamknięciu poprzedniej karty automatu (przebudowa paska
  kart), nie realne przeciąganie karty. Bez ponowienia pojedyncze takie zdarzenie na
  stałe wykreślało losowy podmiot z danego dnia. Naprawa: `withTabEditRetry` (do 5
  prób, 400 ms odstępu) opakowuje oba wywołania; inne błędy nadal przerywają od razu.
- Serwer wymaga wtyczki 1.8.6 przy pobieraniu i zapisie zadań (jak dotychczas —
  starsza wtyczka na jednym komputerze nie blokuje pozostałych).

## 1.8.5 (2026-07-17) — pewna praca wielu komputerów i samonaprawa po restarcie

- Rezerwacja zadania KRZ/MSiG jest teraz krótką transakcją `SELECT … FOR UPDATE`
  z ponownym warunkiem w `UPDATE`. Dwa komputery nie mogą już nadpisać sobie
  `claimed_by` podczas równoczesnego pobierania worklisty.
- Pusta worklista nie zamyka od razu zleconego sweepu. Przez 18 minut wtyczka
  ponawia próbę, dzięki czemu po restarcie workera i wygaśnięciu 15-minutowej
  dzierżawy zadanie wraca do obiegu jeszcze tego samego dnia.
- `cron.php`, czyli ścieżka zalecana na hostingu Cyber_Folks, otrzymuje teraz
  `ReportService`; awaryjny raport po godzinie granicznej działa także bez pingu
  aktywnej wtyczki.
- Dodano trwałe testy runtime prawdziwych plików rozszerzenia w izolowanej atrapie
  Chrome: job+resolver przed nawigacją oraz dokładnie jeden start po retry `krzReady`.
- Serwer wymaga wtyczki 1.8.5 przy pobieraniu i zapisie zadań.

## 1.8.4 (2026-07-17) — deterministyczny start ramek i blokada starych komputerów

- Karta automatu otwiera najpierw neutralną stronę wtyczki, zapisuje zadanie oraz
  resolver wyniku i dopiero potem przechodzi do KRZ/MSiG. Ramka formularza nie może
  już wyprzedzić `jobsByTab` i dostać jednorazowo `job:null`.
- Ramki KRZ przez 8 sekund ponawiają `krzReady`, a lokalna blokada gwarantuje
  jednokrotny start. To dodatkowe zabezpieczenie na krótkie restarty workera Chrome.
- Błąd watchdoga zawiera od teraz numer wtyczki, więc następny ślad pokaże wprost,
  który komputer wykonał zadanie.
- Serwer wymaga wersji 1.8.4 przy pobraniu zadania, przechwyceniu wyniku i końcowym
  raporcie przebiegu. Starszy z dwóch komputerów nie może już losowo przejąć zadania
  i zapisać znanego błędu Mucharskiego.

## 1.8.3 (2026-07-16) — budżet czasu drążenia KRZ (koniec fałszywego timeoutu)

- KRZ (wtyczka 1.8.3): DRĄŻENIE treści postępowań ma teraz twardy budżet czasu
  (`DRILL_DEADLINE_MS = 80 s`, liczony od startu zadania `JOB_STARTED_AT`).
  Objaw: jedyny podmiot z realnym wpisem w KRZ (Mucharski — restrukturyzacja
  KR1S/GRz-nu/175/2025) wywalał błąd „ramka formularza nie zgłosiła wyniku",
  bo wchodzenie w treść obwieszczeń/postanowień (klik sygnatury → osobny widok →
  `history.back`) przekraczało watchdog ramki głównej (105 s). Podmioty bez wpisu
  kończyły od razu „brak wyników", więc problem dotyczył WYŁĄCZNIE tego, który
  wymagał wejścia w treść.
- Naprawa: `collectItems` przerywa otwieranie kolejnych treści po przekroczeniu
  budżetu i oddaje to, co już zebrano. Metadane postępowań (sygnatura, rodzaj,
  daty) są tanie i przechwytywane niezależnie, więc wynik pozostaje kompletny co
  do identyfikacji sprawy; obcięcie drążenia jest sygnalizowane uczciwą adnotacją
  „limit wtyczki". Ramka formularza zawsze zgłasza `krzJobDone` (wynik) ~96–98 s —
  przed watchdogiem 105 s — i wygrywa wyścig z top-frame'em (właściciel dzierżawy),
  więc zamiast błędu zapisywany jest realny wynik.

## 1.8.2 (2026-07-14) — zamknięta luka watchdoga KRZ 75s/105s

- Diagnoza incydentu Mucharski (KRZ „ramka formularza nie zgłosiła wyniku" mimo
  udanej nawigacji w 2s): ramka główna przestawała nadawać sygnał aktywności do
  iframe'a formularza po 75s, ale watchdog czekał na wynik do 105s — iframe
  wyrenderowany później (portal wolny/zdegradowany; KRZ tego dnia sam zgłaszał
  „opóźnienia") nigdy nie dostawał sygnału i musiał zawieść niezależnie od tego,
  ile jeszcze budżetu zostało.
- Fix: okno nadawania sygnału sięga teraz niemal do samego watchdoga (wspólna
  stała WATCHDOG_MS zamiast dwóch rozjechanych liczb).
- Diagnostyka błędu rozróżnia teraz „iframe.active-view-container nigdy nie
  znaleziony" (możliwa zmiana struktury portalu) od „iframe był, ale się nie
  wyrobił" (zwykła powolność) — do szybszej diagnozy kolejnych incydentów.
- NIEZWERYFIKOWANE na żywym portalu (brak dostępu do przeglądarki w tej sesji).

## 1.8.1 (2026-07-13) — brak obcych szczegółów i osieroconych kart KRZ

- Przed kliknięciem sygnatury wtyczka sprawdza teraz sam wiersz wyniku względem
  bieżącego NIP/KRS/nazwy. Stary wynik innej osoby nie otworzy szczegółów.
- Długi przebieg aktywnie podtrzymuje service worker Chrome. Karty automatyczne są
  zapamiętywane poza jego pamięcią i sprzątane po nieoczekiwanym restarcie.
- Zamykanie ma ponowienie oraz ograniczony do zapamiętanej karty fallback CDP,
  gdy portal KRZ zatrzyma zwykłe zamknięcie mechanizmem `beforeunload`.

## 1.8.0 (2026-07-13) — ścisłe przypisanie wyniku i niezależne KRZ/MSiG

- Każdy automatyczny wynik musi pasować do identyfikatora lub nazwy podmiotu oraz
  do aktywnego zadania i jego jednorazowego tokenu. Sam numer zadania nie jest już
  dowodem; obce dane (np. wynik osoby w karcie spółki) są odrzucane przed zapisem.
- KRZ: wyszukiwanie jest trwale związane z typem podmiotu. Spółka nie przechodzi
  już awaryjnie do zakładki JDG; pracuje wyłącznie aktywna, widoczna ramka portalu,
  a stara ramka traci dzierżawę. Kryterium jest ponownie sprawdzane po kliknięciu.
- KRZ i MSiG mają niezależne kolejki i są przeplatane przez jedną bezpieczną kartę,
  więc błąd lub długa kolejka KRZ nie wstrzymuje całego MSiG.
- Wiele komputerów: jeden komputer rezerwuje jedno zadanie; wynik musi zwrócić ten
  sam token. Serwer blokuje wtyczki starsze niż 1.8.0 jeszcze przed rezerwacją.
  Nieaktualnej wtyczki nie wlicza też do liczby aktywnych komputerów.
- KRS: pusty dział 6 nie jest postępowaniem. Surowy/ucięty JSON nie może tworzyć
  alertu, a stare fałszywe wpisy i oparta na nich ocena LLM są automatycznie usuwane.
- „Brak wyników” jest uznawany tylko wtedy, gdy strona potwierdza także kryterium
  wyszukiwania monitorowanego podmiotu.
- Automatyczny e-mail dzienny wychodzi tylko w polskie dni robocze (także z
  uwzględnieniem świąt ruchomych i wolnej Wigilii); błąd SMTP zwalnia blokadę,
  dzięki czemu następny ping może ponowić wysyłkę tego samego dnia.

## 1.7.1 (2026-07-13) — niezawodność KRZ, typ podmiotu, pełna firma CEIDG

- KRZ: COFNIĘTA równoległość kart (MAX_CONCURRENT_TABS 2→1). Po 1.7.0 przebiegi
  KRZ przestały się kończyć (zawisały) — sekwencyjnie znów kończą się niezawodnie.
  Przyspieszenie: WIELE komputerów (kolejka dzieli się przez claimed_by).
- CEIDG: pełna firma (i REGON, jeśli podany) trafia do podmiotu (aliasy/puste pola)
  PRZED zleceniem KRZ/MSiG — wyszukiwanie idzie po pełnej nazwie, nie samym imieniu.
- Heartbeat wtyczek: każda wtyczka ma stabilny identyfikator komputera i „melduje się"
  przy pingu; panel („Skrót monitoringu") pokazuje, ILE komputerów jest teraz aktywnych.
  Codzienne sprawdzenie rozkłada się między nie automatycznie (atomowa kolejka
  claimed_by + alarm dzienny na każdym komputerze); 0 aktywnych = ostrzeżenie, że
  KRZ/MSiG się nie wykonają, aż któraś wtyczka wystartuje.

### „krytyczny" zależny od typu podmiotu

- Rozróżnienie: „przestał istnieć = krytyczne" dotyczy TYLKO osób PRAWNYCH.
  Osoba fizyczna/JDG po zakończonej upadłości albo wykreśleniu z CEIDG NADAL
  istnieje (to nie śmierć) → poziom „wysoki", nie „krytyczny".
- Zakończona upadłość: osoba prawna → krytyczny; osoba fizyczna → wysoki
  (RiskAnalyzer::riskFromText z flagą isLegalPerson; przekazywaną z typu podmiotu
  w KRZ/MSiG, a dla KRS z obecności numeru KRS).
- CEIDG „działalność wykreślona": cofnięte z „krytyczny" na „wysoki".
- Wykreślenie z KRS (osoba prawna) pozostaje „krytyczny".
- UWAGA: istniejące zdarzenia zachowują zapisany poziom — po wgraniu trzeba
  „Sprawdź teraz", żeby przeliczyć (maxRisk czyta zapisany risk zdarzeń).

## 1.7.0 (2026-07-12) — wydajność przebiegów KRZ/MSiG

- Wtyczka (1.7.0): ograniczona RÓWNOLEGŁOŚĆ kart (MAX_CONCURRENT_TABS=2) zamiast
  ściśle jednej naraz — realne przyspieszenie mieszczące się w budżecie 120 s/karta.
  Świadomie małe: Chrome dławi timery kart w tle, więc więcej nie skaluje liniowo.
  Poziomo skaluje się przez WIELE komputerów (kolejka dzieli się po claimed_by).
- MSiG: pomijanie już ZNANYCH ogłoszeń po stabilnym „id" z linku POBIERZ
  (…/Monitor/Download?id=NNNN) — ZWERYFIKOWANE na żywym portalu: sygnatura BMSiG
  jest dopiero w szczegółach, a tekst wiersza (numer monitora + data) NIE jest
  unikalny, więc „id" to jedyny pewny klucz widoczny bez otwierania. Wtyczka nie
  otwiera szczegółów znanego ogłoszenia, wysyła lekki znacznik, serwer domyka
  zadanie bez nadpisywania istniejącej treści. Duży zysk przy cyklicznych
  przebiegach. (KRZ świadomie POMINIĘTY — bez weryfikacji na żywym portalu skip
  groziłby przeoczeniem nowego obwieszczenia pod istniejącym postępowaniem.)

## 1.6.2 (2026-07-12)

- Nowy, najwyższy poziom ryzyka „krytyczny" (ponad „wysoki") = podmiot przestał
  istnieć / utracił zdolność działania. Nadawany, gdy: zakończone postępowanie
  UPADŁOŚCIOWE (w tym likwidacyjne; wyjątek: zakończone zawarciem/wykonaniem
  układu), wykreślenie z KRS, wykreślenie działalności z CEIDG.
- Naprawa błędnej klasyfikacji: zakończona upadłość była opisywana jako „aktywne
  postępowanie" (goły rdzeń „upadlosc" łapał się przed wykryciem zakończenia,
  bo odmiana „zakończenia" nie pasowała do wzorca). Teraz wykrywany rdzeń „zakoncz".
- „krytyczny" spójnie w rankingu, kolorach (biały tekst na ciemnej czerwieni),
  licznikach i podsumowaniach (karta, raport dzienny, e-mail).
- UI: na karcie podmiotu przycisk „← Powrót do listy podmiotów" (nad „Sprawdź
  teraz") — powrót do panelu głównego, niezależnie od linku „Podmioty" w nawigacji.
- UWAGA (nie bug aplikacji): dane osób fizycznych w odpisie KRS (nazwisko/imię/
  PESEL syndyka) są maskowane przez SAMO publiczne API KRS Ministerstwa
  (`P******`, `5**********`). Aplikacja niczego nie maskuje i nie ma jak odmaskować
  — pełnych danych to API nie zwraca. Sygnatury, sąd i daty są pełne.

## 1.6.1 (2026-07-12)

- KRZ (wtyczka 1.6.1): NAPRAWA regresji z 1.6.0 — osoby fizyczne (JDG/osoba)
  znów się wykrywają. Root cause: `norm()` nie składał „ł→l" (ł nie ma
  dekompozycji NFD), więc etykieta zakładki „…prowadząca działalność" nie pasowała
  do wzorca → `searchTab` zwracał null, a nowa (zbyt ścisła) gotowość odrzucała
  zakładkę. Fix: `norm` składa ł→l; gotowość zakładek osób potwierdza pole
  charakterystyczne LUB realna zmiana układu pól LUB aria (odporne, gdy portal nie
  ustawia aria-selected). Zakładka spółek bez zmian (działa).
- UI: surowy „Podgląd strony źródła" schowany pod dyskretny przycisk „🔍 Diagnoza"
  i pokazywany TYLKO przy statusie „błąd"; „brak wyników" nie zaśmieca już zrzutem
  strony.

## 1.6.0 (2026-07-12)

- KRZ (wtyczka 1.6.0): gotowość zakładki liczona z WIDOCZNYCH pól, nie z
  widoczności panelu PrimeNG — naprawia zakładkę podmiotów rejestrowych (spółek),
  która pozostawała „niegotowa" mimo aria-selected=true (panel raportowany jako
  ukryty w cross-origin iframe). aria-selected pozostaje sygnałem pomocniczym.
- Ocena LLM: do modelu (Gemini) trafiają teraz istotne dane postępowania
  wyłuskane lokalnie (terminy, czynności, sąd, kwoty) + rodzaj ogłoszenia,
  ryzyko, SYGNATURA i data — ocena mówi „co z ogłoszeń wynika", a nie ile ich
  jest. To MINIMALIZACJA przez dobór danych (art. 5 ust. 1 lit. c RODO), a NIE
  maskowanie: pełna treść ogłoszenia (adresy, dane osób) nie jest wysyłana.
  Podstawa monitoringu: uzasadniony interes administratora (art. 6 ust. 1 lit. f).
- Status źródła nazywa rodzaje wpisów: „MSiG — 7 ogłoszeń: plan podziału; …"
  (poprawna polska odmiana liczebnika) zamiast samej liczby.
- Nowy przycisk „🧠 Odśwież ocenę" na karcie podmiotu (POST /subjects/{id}/reassess)
  — przelicza ocenę z bieżących zdarzeń bez usuwania podmiotu.
- KRZ (wtyczka): karta zamyka się automatycznie po zakończeniu wyszukiwania —
  neutralizacja onbeforeunload portalu (dialog „Czy na pewno wyjść?" blokował
  programowe zamknięcie); tylko w karcie z zadaniem, ręczne przeglądanie bez zmian.

## Wtyczka Chrome 1.5.0 (2026-07-12)

- KRZ: potwierdzanie przełączenia zakładki bez zgadywania etykiet pól
  (aria-selected lub realna zmiana układu pól i zniknięcie "Nazwa podmiotu"),
- KRZ: natywne kliknięcie CDP weryfikuje skutek (aria-selected) i przy braku
  potwierdzenia próbuje fokus + Enter (niezależne od współrzędnych OOPIF),
- KRZ: diagnostyka stanu zakładki w komunikacie błędu (aria-selected, panel,
  widoczne pola, metoda CDP).

## 1.0.0

- finalizacja portu PHP/MySQL,
- stabilizacja danych podmiotów i twardych identyfikatorów,
- poprawione dopasowanie MSiG,
- KRZ przez kolejkę i wtyczkę Chrome,
- scoring historycznych i zakończonych niepowodzeniem postępowań,
- kontrola sprawozdań finansowych,
- PDF wielostronicowy,
- e-mail z PDF,
- dokumentacja instalacji lokalnej i VPS,
- testy regresyjne.
