# Changelog

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
