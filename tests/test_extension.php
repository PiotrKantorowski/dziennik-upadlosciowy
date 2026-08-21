<?php
function test_extension_manifest_has_no_global_https_access(): void {
    $manifest = json_decode(file_get_contents(dirname(__DIR__).'/chrome_extension/manifest.json'), true);
    assert_true(!in_array('https://*/*', $manifest['host_permissions'] ?? [], true), 'extension must not request global https host permission');
    assert_true(in_array('https://krz.ms.gov.pl/*', $manifest['host_permissions'] ?? [], true));
}
function test_extension_uses_php_krz_endpoints(): void {
    $bg = file_get_contents(dirname(__DIR__).'/chrome_extension/background.js');
    assert_true(str_contains($bg, '/api/krz/worklist'));
    assert_true(str_contains($bg, '/api/krz/ingest'));
}

function test_extension_default_app_url_matches_php_docker_port(): void {
    $bg = file_get_contents(dirname(__DIR__).'/chrome_extension/background.js');
    $opts = file_get_contents(dirname(__DIR__).'/chrome_extension/options.js');
    assert_true(str_contains($bg, 'http://127.0.0.1:8080'));
    assert_true(str_contains($opts, 'http://127.0.0.1:8080'));
}
function test_extension_supports_optional_vps_permissions(): void {
    $manifest = json_decode(file_get_contents(dirname(__DIR__).'/chrome_extension/manifest.json'), true);
    assert_true(in_array('https://*/*', $manifest['optional_host_permissions'] ?? [], true), 'VPS URL must be grantable as optional permission');
    assert_true(!in_array('https://*/*', $manifest['host_permissions'] ?? [], true), 'global https must not be mandatory');
    $opts = file_get_contents(dirname(__DIR__).'/chrome_extension/options.js');
    assert_true(str_contains($opts, 'chrome.permissions.request'));
}
function test_extension_has_subject_matching_endpoint_without_marking_worklist(): void {
    $bg = file_get_contents(dirname(__DIR__).'/chrome_extension/background.js');
    $router = file_get_contents(dirname(__DIR__).'/public/index.php');
    $controller = file_get_contents(dirname(__DIR__).'/app/Controllers/KrzApiController.php');
    assert_true(str_contains($bg, '/api/krz/subjects'));
    assert_true(str_contains($router, '/api/krz/subjects'));
    assert_true(str_contains($controller, 'monitoredKrzSubjects'));
}

function test_extension_sends_krz_task_id_to_backend(): void {
    $bg = file_get_contents(dirname(__DIR__).'/chrome_extension/background.js');
    assert_true(str_contains($bg, 'task_id: meta.taskId || null'), 'ingest must include task_id');
    assert_true(str_contains($bg, 'taskId: item.task_id'), 'job state must retain task_id');
}

function test_krz_api_does_not_fail_open_without_token(): void {
    $controller = file_get_contents(dirname(__DIR__).'/app/Controllers/KrzApiController.php');
    assert_true(str_contains($controller, 'missing KRZ bridge token'), 'KRZ API must reject missing bridge token configuration');
}

function test_extension_tab_open_failure_keeps_subject_and_task_id(): void {
    $bg = file_get_contents(dirname(__DIR__).'/chrome_extension/background.js');
    assert_true(str_contains($bg, 'return { task_id: item.task_id') && str_contains($bg, 'subject_id: item.subject_id, subject: item.name, error:'), 'tab-open failure must retain subject_id/task_id so the backend can record the KRZ error instead of silently dropping it');
}

function test_extension_supports_msig_portal(): void {
    $manifest = json_decode(file_get_contents(dirname(__DIR__).'/chrome_extension/manifest.json'), true);
    assert_true(in_array('https://wyszukiwarka-msig.ms.gov.pl/*', $manifest['host_permissions'] ?? [], true), 'MSiG portal must be a built-in host permission (free official source, not a paid API)');
    $scripts = $manifest['content_scripts'] ?? [];
    $hasMsigScript = false;
    foreach ($scripts as $cs) { if (in_array('content_msig.js', $cs['js'] ?? [], true)) $hasMsigScript = true; }
    assert_true($hasMsigScript, 'content_msig.js must be registered as a content script');
    $bg = file_get_contents(dirname(__DIR__).'/chrome_extension/background.js');
    assert_true(str_contains($bg, '/api/msig/worklist'));
    assert_true(str_contains($bg, '/api/msig/ingest'));
    assert_true(str_contains($bg, 'msigReady'));
    assert_true(str_contains($bg, 'msigCapture'));
    assert_true(str_contains($bg, 'msigJobDone'));
}

// Optymalizacja przebiegów: jedna karta globalnie + pomijanie znanych ogłoszeń.
function test_run_is_bounded_parallel_and_skips_known_msig(): void {
    $bg = file_get_contents(dirname(__DIR__).'/chrome_extension/background.js');
    // Pula równoległa (kilka kart naraz), ale mała i świadoma dławienia kart w tle.
    assert_true(str_contains($bg, 'MAX_CONCURRENT_TABS'), 'przebieg ma używać puli równoległej');
    assert_true(str_contains($bg, 'Promise.all(slice.map'), 'paczka kart ma być przetwarzana równolegle');
    assert_true(str_contains($bg, 'seen: Array.isArray(item.seen)'), 'zadanie ma nieść listę znanych sygnatur');
    // Serwer dołącza znane identyfikatory ogłoszeń (id z linku POBIERZ) do worklistu MSiG.
    $ctrl = file_get_contents(dirname(__DIR__).'/app/Controllers/MsigApiController.php');
    assert_true(str_contains($ctrl, "seenMsigDownloadIds((int)\$t['subject_id'])"), 'worklist MSiG ma zawierać znane identyfikatory ogłoszeń');
    // Content MSiG pomija otwieranie znanych ogłoszeń (klucz = id z linku POBIERZ,
    // bo sygnatura BMSiG jest dopiero w szczegółach) i wysyła lekki znacznik.
    $msig = file_get_contents(dirname(__DIR__).'/chrome_extension/content_msig.js');
    assert_true(str_contains($msig, 'Monitor/Download'), 'MSiG ma czytać stabilne id z linku POBIERZ');
    assert_true(str_contains($msig, 'known.has(rowId)'), 'MSiG ma pomijać otwieranie znanego id ogłoszenia');
    assert_true(str_contains($msig, 'known: true'), 'znane ogłoszenie ma iść jako lekki znacznik, bez otwierania');
    // Serwer NIE degraduje istniejącej treści dla znanego znacznika, ale domyka zadanie.
    $check = file_get_contents(dirname(__DIR__).'/app/Services/CheckService.php');
    assert_true(str_contains($check, "!empty(\$it['known'])"), 'ingest MSiG ma rozpoznawać znacznik known');
    assert_true(str_contains($check, 'brak nowych ogłoszeń'), 'przypadek „same znane" ma domykać zadanie jako sukces');
}

// Heartbeat wtyczek: stabilny identyfikator komputera + licznik aktywnych w panelu.
function test_plugin_heartbeat_counts_active_computers(): void {
    $bg = file_get_contents(dirname(__DIR__).'/chrome_extension/background.js');
    assert_true(str_contains($bg, 'function getInstanceId'), 'wtyczka ma mieć stabilny identyfikator instalacji');
    assert_true(str_contains($bg, 'duirInstanceId'), 'identyfikator zapisywany trwale w chrome.storage.local');
    assert_true(str_contains($bg, 'X-DUiR-Instance'), 'identyfikator wysyłany w nagłówku do serwera');
    $ping = file_get_contents(dirname(__DIR__).'/app/Controllers/KrzApiController.php');
    assert_true(str_contains($ping, 'touchPluginInstance'), 'ping rejestruje heartbeat wtyczki');
    assert_true(str_contains($ping, "'active_plugins'"), 'ping zwraca liczbę aktywnych wtyczek');
    $repo = file_get_contents(dirname(__DIR__).'/app/Repository.php');
    assert_true(str_contains($repo, 'function activePluginInstances'), 'repo liczy aktywne wtyczki w oknie czasowym');
    assert_true(str_contains($repo, 'plugin_instances'), 'heartbeat trzymany w tabeli plugin_instances');
    $idx = file_get_contents(dirname(__DIR__).'/app/Controllers/SubjectController.php');
    assert_true(str_contains($idx, 'Aktywne wtyczki'), 'panel pokazuje liczbę aktywnych komputerów');
}

function test_msig_api_does_not_fail_open_without_token(): void {
    $controller = file_get_contents(dirname(__DIR__).'/app/Controllers/MsigApiController.php');
    assert_true(str_contains($controller, "missing bridge token"), 'MSiG bridge API must reject missing bridge token configuration');
    assert_true(str_contains($controller, 'hash_equals'), 'MSiG bridge API must use constant-time token comparison');
}

// Content scripty wysyłają wyniki jako tablicę items[] (szczegóły obwieszczeń).
// Service worker MUSI ją przekazać do /api/*/ingest — bez tego backend widzi pusty
// text i każdy automatyczny przebieg kończy się błędem mimo zebranych danych.
function test_extension_background_forwards_items_contract(): void {
    $bg = file_get_contents(dirname(__DIR__).'/chrome_extension/background.js');
    assert_true(str_contains($bg, 'msg.items'), 'service worker must read items[] from capture messages');
    assert_true(str_contains($bg, 'items: Array.isArray'), 'service worker must forward items[] in the ingest payload');
    foreach (['content_krz.js','content_msig.js'] as $cs) {
        $src = file_get_contents(dirname(__DIR__).'/chrome_extension/'.$cs);
        assert_true(str_contains($src, 'items'), $cs.' must collect the items[] contract');
    }
}

function test_krz_selected_subject_type_is_binding_and_frame_has_single_owner(): void {
    $src = file_get_contents(dirname(__DIR__).'/chrome_extension/content_krz.js');
    $bg = file_get_contents(dirname(__DIR__).'/chrome_extension/background.js');
    assert_true(str_contains($src, 'const kinds = [baseKind];'), 'jawny typ podmiotu ma prowadzić do dokładnie jednej zakładki KRZ');
    assert_true(!str_contains($src, '? ["company", "business_person"]'), 'spółka bez KRS nie może być automatycznie szukana w zakładce osób fizycznych');
    assert_true(str_contains($src, 'tab.getAttribute("aria-selected") === "true"'), 'tab switching must positively verify the portal-selected ARIA tab');
    assert_true(str_contains($src, 'Nie udało się wybrać właściwej zakładki wyszukiwania KRZ.'), 'failed tab selection must stop that search attempt explicitly');
    assert_true(str_contains($src, 'function searchTabReady(kind)'), 'KRZ must verify the selected tab, active panel and expected form together');
    assert_true(str_contains($src, 'tab.click();'), 'KRZ must use one native click per freshly located PrimeNG tab');
    assert_true(str_contains($src, 'isActivePortalFrame() && findAnySearchInput()'), 'only a freshly active cached portal iframe may run a KRZ job');
    assert_true(str_contains($src, 'iframe.active-view-container'), 'top frame must identify the active ACP iframe');
    assert_true(str_contains($src, 'ACTIVE_FRAME_MESSAGE'), 'active-frame selection must be relayed across frame boundaries');
    assert_true(str_contains($src, 'krzClaimSearchFrame'), 'ramka formularza musi uzyskać pojedynczą dzierżawę zadania');
    assert_true(str_contains($bg, 'senderOwnsKrzWorker'), 'capture i jobDone muszą być związane z frameId/documentId właściciela');
    assert_true(str_contains($bg, 'wrong_or_stale_krz_frame'), 'stara ramka musi być jawnie odrzucona');
}

// Regresja 2026-07-14: watchdog ramki głównej czekał na wynik do 105s, ale sygnał
// aktywności do iframe'a formularza nadawał tylko do 75s — iframe wyrenderowany
// później (portal wolny/zdegradowany) nigdy nie dostawał sygnału i musiał zawieść
// mimo pozostałego budżetu. Okno nadawania musi sięgać niemal do watchdoga, a
// diagnostyka błędu ma odróżniać "iframe nigdy nie znaleziony" od "był, ale wolny".
function test_krz_watchdog_broadcast_window_covers_full_budget(): void {
    $src = file_get_contents(dirname(__DIR__).'/chrome_extension/content_krz.js');
    assert_true(!str_contains($src, 't0 + 75000'), 'sygnał aktywności nie może przestawać być nadawany 30s przed watchdogiem');
    assert_true(str_contains($src, 'WATCHDOG_MS'), 'budżet watchdoga i okno nadawania mają być jedną nazwaną stałą, nie rozjechanymi liczbami');
    assert_true(str_contains($src, 'NIGDY nie znaleziony w budżecie zadania'), 'diagnostyka ma rozróżniać brak iframe od samej powolności portalu');
}

function test_extension_validates_capture_and_schedules_msig_independently(): void {
    $bg = file_get_contents(dirname(__DIR__).'/chrome_extension/background.js');
    assert_true(str_contains($bg, 'captureMatchesSubject'), 'service worker must validate captured content against the expected subject');
    assert_true(str_contains($bg, 'trustedMatch: false'), 'task/tab context must not be sent as proof of subject identity');
    assert_true(str_contains($bg, 'withTabSlot'), 'KRZ and MSiG must share one global tab slot');
    assert_true(str_contains($bg, 'Promise.all(sourceRuns)'), 'source queues must run concurrently instead of draining all KRZ first');
    assert_true(str_contains($bg, '/api/msig/ping'), 'poll must observe the independent MSiG sweep marker');
    assert_true(str_contains($bg, 'msigRequested'), 'a standalone MSiG request must wake the worker');
}

function test_extension_identifies_safe_protocol_version(): void {
    $manifest = json_decode(file_get_contents(dirname(__DIR__).'/chrome_extension/manifest.json'), true);
    // Wersja manifestu jest pinowana świadomie: podbicie wtyczki ma wymuszać przejście przez ten test.
    // 1.8.7 = wysyłka pozycji KRZ na bieżąco (przed ryzykownym kliknięciem w obwieszczenie).
    assert_eq('1.8.7', $manifest['version'] ?? null, 'wydanie musi wysyłać pozycje KRZ na bieżąco, a nie dopiero po drążeniu');
    // Budżet drążenia treści KRZ: bez niego drążenie postępowania (jedyny podmiot
    // z realnym wpisem) przekraczało watchdog 105 s → błąd „ramka nie zgłosiła wyniku".
    $krz = file_get_contents(dirname(__DIR__).'/chrome_extension/content_krz.js');
    assert_true(str_contains($krz, 'DRILL_DEADLINE_MS'), 'content_krz musi mieć budżet czasu drążenia');
    assert_true(str_contains($krz, 'drillDeadline'), 'collectItems musi respektować budżet drążenia');
    $bg = file_get_contents(dirname(__DIR__).'/chrome_extension/background.js');
    assert_true(str_contains($bg, 'X-DUiR-Plugin-Version'), 'wtyczka musi podawać wersję protokołu przy pobieraniu zadania');
    assert_true(str_contains($bg, 'chrome.runtime.getManifest().version'), 'wersja nagłówka ma pochodzić z manifestu');
    foreach (['KrzApiController.php','MsigApiController.php'] as $file) {
        $controller = file_get_contents(dirname(__DIR__).'/app/Controllers/'.$file);
        assert_true(str_contains($controller, "MIN_PLUGIN_VERSION = '1.8.6'"), $file.' musi blokować wszystkie wtyczki sprzed naprawy zegara zadania i ponowień tabs.*');
        assert_true(strpos($controller, '$this->guardPluginVersion();') < strpos($controller, 'pending'), $file.' musi odrzucić starą wtyczkę przed rezerwacją zadania');
        assert_true(substr_count($controller, '$this->guardPluginVersion();') >= 3, $file.' musi blokować starą wersję także przy zapisie wyniku i zakończeniu przebiegu');
    }
    $krzController = file_get_contents(dirname(__DIR__).'/app/Controllers/KrzApiController.php');
    assert_true(str_contains($krzController, 'pluginVersionIsCompatible'), 'heartbeat musi rozpoznawać zgodną wersję');
    assert_true(str_contains($krzController, "'plugin_compatible'=>\$compatible"), 'ping jawnie raportuje zgodność wtyczki');
    assert_true(str_contains($bg, 'await resp.json()).error'), 'błąd aktualizacji ma być widoczny użytkownikowi, nie tylko kod HTTP 426');
}

// Regresja 2026-08-10 (incydent Mucharski, raport 10:01): top i formularz to OSOBNE
// konteksty JS (all_frames), więc dwa niezależne `Date.now()` dawały dwa niezależne
// zegary zadania — ramka formularza wyrenderowana z opóźnieniem przez SPA dostawała
// świeże 80s drążenia liczone od WŁASNEGO, spóźnionego startu, mimo że watchdog ramki
// głównej liczył 105s od startu KARTY. Budżet drążenia mógł kończyć się PO
// watchdogu. Jedyny podmiot z realnym wpisem w KRZ (wymagający wejścia w treść,
// więc wolniejszy) systematycznie padał błędem „ramka nie zgłosiła wyniku".
function test_krz_job_clock_is_shared_from_background_not_local_to_each_frame(): void {
    $bg = file_get_contents(dirname(__DIR__).'/chrome_extension/background.js');
    $krz = file_get_contents(dirname(__DIR__).'/chrome_extension/content_krz.js');
    assert_true(str_contains($bg, 'startedAt: Date.now(),'), 'moment utworzenia karty musi być zapisany w jobsByTab');
    assert_true(
        strpos($bg, 'jobsByTab[tab.id] =') < strpos($bg, 'startedAt: Date.now(),'),
        'startedAt musi być polem rekordu jobsByTab utworzonego dla karty automatu'
    );
    assert_true(str_contains($bg, 'seen: Array.isArray(job.seen) ? job.seen : [], startedAt: job.startedAt }'), 'krzReady/msigReady musi zwracać startedAt razem z resztą zadania');
    assert_true(str_contains($krz, 'JOB_STARTED_AT = Number(job.startedAt) || Date.now();'), 'ramka NIE MOŻE liczyć własnego, lokalnego startu zadania — musi użyć job.startedAt z background.js');
    assert_true(!str_contains($krz, 'JOB_STARTED_AT = Date.now();'), 'nie może pozostać stara, w pełni lokalna inicjalizacja zegara zadania');
    assert_true(str_contains($krz, 'const t0 = JOB_STARTED_AT;'), 'watchdog ramki głównej musi liczyć od tego samego job.startedAt, a nie od własnego Date.now()');
}

// Regresja 2026-08-10 (raport 10:01): ADCOOKIE i Sławomir Kaźmierczak — dwa różne
// podmioty, ten sam błąd Chrome przy otwieraniu karty automatu ("Tabs cannot be
// edited right now (user may be dragging a tab)"), bez związku z realnym
// przeciąganiem karty przez człowieka. Bez ponowienia pojedyncze takie zdarzenie
// na stałe wykreślało podmiot z danego przebiegu.
function test_tab_create_and_update_retry_on_transient_chrome_drag_error(): void {
    $bg = file_get_contents(dirname(__DIR__).'/chrome_extension/background.js');
    assert_true(str_contains($bg, 'async function withTabEditRetry'), 'musi istnieć wspólny helper ponawiający operacje na kartach');
    assert_true(str_contains($bg, 'cannot be edited right now'), 'ponowienie ma być ograniczone do tego konkretnego, znanego błędu Chrome');
    assert_true(str_contains($bg, 'withTabEditRetry(() => chrome.tabs.create({ url: AUTOMATION_STAGING_URL'), 'otwarcie karty stagingowej musi przechodzić przez ponowienie');
    assert_true(str_contains($bg, 'withTabEditRetry(() => chrome.tabs.update(tab.id, { url: portalUrl'), 'nawigacja do portalu musi przechodzić przez ponowienie');
    assert_true(str_contains($bg, 'throw e;'), 'inne błędy niż "cannot be edited right now" muszą przerywać natychmiast, bez ponawiania');
}

function test_sweep_is_retried_after_worker_restart_and_active_lease(): void {
    $bg = file_get_contents(dirname(__DIR__).'/chrome_extension/background.js');
    assert_true(str_contains($bg, 'SWEEP_EMPTY_RETRY_MS = 18 * 60 * 1000'), 'pusta worklista musi poczekać dłużej niż 15-minutowa dzierżawa');
    assert_true(str_contains($bg, 'sweepRetryState'), 'pierwsza pusta próba musi być zapamiętana między restartami workera');
    assert_true(str_contains($bg, 'lastSweepHandled: null'), 'sprzątnięcie osieroconej karty musi odblokować ponowienie sweepu');
    assert_true(strpos($bg, 'if (processed === 0)') < strpos($bg, 'lastSweepHandled: requested'), 'pusty run nie może od razu oznaczyć sweepu jako wykonanego');
}

function test_krz_job_is_registered_before_portal_navigation(): void {
    $bg = file_get_contents(dirname(__DIR__).'/chrome_extension/background.js');
    $start = strpos($bg, 'async function processItemInTab');
    $end = strpos($bg, 'async function processItem(', $start);
    $fn = substr($bg, $start, $end - $start);
    $create = strpos($fn, 'chrome.tabs.create({ url: AUTOMATION_STAGING_URL');
    $job = strpos($fn, 'jobsByTab[tab.id] =');
    $wait = strpos($fn, 'const resultPromise = waitForTabJob');
    $navigate = strpos($fn, 'chrome.tabs.update(tab.id, { url: portalUrl');
    assert_true($create !== false && $job !== false && $wait !== false && $navigate !== false, 'start karty musi mieć staging, job, resolver i osobną nawigację');
    assert_true($create < $job && $job < $wait && $wait < $navigate, 'job i resolver muszą istnieć przed wejściem na portal');
    assert_true(!str_contains($fn, 'chrome.tabs.create({ url: portalUrl'), 'tabs.create nie może otwierać portalu przed rejestracją jobu');
    assert_true(str_contains($bg, 'url === AUTOMATION_STAGING_URL'), 'sprzątanie po restarcie musi rozpoznawać kartę stagingową');
    assert_true(str_contains($fn, 'delete jobsByTab[tab.id]') && str_contains($fn, 'closeAutomationTab(tab.id)'), 'błąd nawigacji musi usunąć job i zamknąć kartę');
}

function test_krz_content_retries_ready_until_job_exists(): void {
    $src = file_get_contents(dirname(__DIR__).'/chrome_extension/content_krz.js');
    assert_true(str_contains($src, 'READY_RETRY_WINDOW_MS = 8000'), 'ramka musi mieć ograniczone okno ponawiania krzReady');
    assert_true(str_contains($src, 'READY_RETRY_INTERVAL_MS = 300'), 'ponawianie musi mieć nazwany interwał');
    assert_true(str_contains($src, 'do {') && str_contains($src, 'await send("krzReady", {})'), 'init musi ponawiać pobranie jobu');
    assert_true(str_contains($src, 'JOB_RUN_STARTED = true') && str_contains($src, 'await runJob(r.job);'), 'otrzymany job może wystartować dokładnie raz');
    assert_true(str_contains($src, 'pageText: "[wtyczka v" + chrome.runtime.getManifest().version'), 'także błąd watchdoga musi podawać wersję wtyczki');
}

// Regresja 2026-08-21 (user: „działa gdy KRZ puste, zawodzi gdy ma wpisy" — jedyny
// podmiot wymagający klikania w obwieszczenia to jedyny, który zawodził). Root cause:
// pozycje zbierane TYLKO w pamięci ramki i wysyłane RAZEM na końcu drążenia — ryzykowne
// kliknięcie w obwieszczenie mogło zabić kontekst JS ramki W TRAKCIE, gubiąc WSZYSTKO
// co jeszcze nie zostało wysłane. Fix: każda pozycja (metadane wiersza — PRZED
// kliknięciem — i bogatsza treść obwieszczenia) wysyłana OD RAZU przez `flush`.
function test_krz_flushes_each_item_immediately_before_risky_notice_click(): void {
    $content = file_get_contents(dirname(__DIR__).'/chrome_extension/content_krz.js');
    assert_true(str_contains($content, 'async function collectItems(job)'), 'collectItems musi znać job (subjectId) do wysyłki na bieżąco');
    assert_true(str_contains($content, 'const flush = async (item) => {'), 'musi istnieć funkcja wysyłająca każdą pozycję od razu');
    assert_true(str_contains($content, 'send("krzCapture", { items: [item]'), 'flush ma wysyłać krzCapture per-pozycja, nie na końcu');
    // Metadane wiersza (await flush({...proceedingMeta...)) muszą wystąpić PRZED
    // pętlą klikającą w obwieszczenia (for (let j = 0; j < openers.length...),
    // inaczej ryzykowne kliknięcie mogłoby zabić ramkę przed wysłaniem czegokolwiek.
    $metaFlushPos = strpos($content, 'await flush({') ;
    $openerLoopPos = strpos($content, 'for (let j = 0; j < openers.length; j++)');
    assert_true($metaFlushPos !== false && $openerLoopPos !== false && $metaFlushPos < $openerLoopPos, 'metadane muszą być wysłane PRZED kliknięciem w obwieszczenie, nie po');
    // searchInKind nie ma już wysyłać zbiorczo po collectItems — to by tylko
    // niepotrzebnie duplikowało już wysłane przez flush() pozycje.
    assert_true(!str_contains($content, 'const res = await send("krzCapture", { items, url: location.href, subjectId: job.subjectId || null });'), 'searchInKind nie może już wysyłać całej tablicy zbiorczo — flush() już to zrobił per-pozycja');
}

function test_krz_rejects_stale_row_before_opening_details_and_always_cleans_managed_tab(): void {
    $bg = file_get_contents(dirname(__DIR__).'/chrome_extension/background.js');
    $content = file_get_contents(dirname(__DIR__).'/chrome_extension/content_krz.js');
    assert_true(str_contains($content, 'resultRowsMatchCurrentQuery'), 'wiersz wyniku musi pasować przed kliknięciem sygnatury');
    assert_true(strpos($content, 'const boundResult = await waitFor(() => resultRowsMatchCurrentQuery(job)') < strpos($content, 'try { items = await collectItems(job);'), 'walidacja ma nastąpić przed otwieraniem szczegółów');
    assert_true(str_contains($bg, 'duirManagedAutomationTabsV1'), 'karta automatu musi być zapamiętana poza pamięcią workera');
    assert_true(str_contains($bg, 'cleanupOrphanedManagedTabs'), 'restart workera musi sprzątać osieroconą kartę');
    assert_true(str_contains($bg, 'chrome.runtime.getPlatformInfo'), 'długi przebieg KRZ musi podtrzymywać service worker');
    assert_true(str_contains($bg, 'Target.closeTarget'), 'blokada beforeunload ma mieć ograniczony fallback zamknięcia');
}

function test_krz_has_native_cdp_click_fallback_for_cross_origin_tab(): void {
    $manifest = json_decode(file_get_contents(dirname(__DIR__).'/chrome_extension/manifest.json'), true);
    $bg = file_get_contents(dirname(__DIR__).'/chrome_extension/background.js');
    $content = file_get_contents(dirname(__DIR__).'/chrome_extension/content_krz.js');
    assert_true(in_array('debugger', $manifest['permissions'] ?? [], true), 'native KRZ click requires the narrowly used debugger permission');
    assert_true(str_contains($bg, 'async function trustedKrzTabClick'), 'background must provide the native click fallback');
    assert_true(str_contains($bg, 'Target.setAutoAttach'), 'fallback must attach to cross-origin iframe targets');
    assert_true(str_contains($bg, 'DOM.performSearch'), 'fallback must locate the exact ARIA tab');
    assert_true(str_contains($bg, 'Input.dispatchMouseEvent'), 'fallback must dispatch one browser-level mouse click');
    assert_true(str_contains($bg, 'chrome.debugger.detach(root)'), 'debugger must always be detached after the click');
    assert_true(str_contains($content, 'krzTrustedTabClick'), 'content script must invoke the fallback only after ordinary selection fails');
}
