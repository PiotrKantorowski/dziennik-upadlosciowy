// Asystent KRZ/MSiG — service worker (MV3).
//
// Rola: pobrać z lokalnego programu listę podmiotów do sprawdzenia (KRZ i MSiG
// osobno — to dwa różne portale rządowe), otworzyć je w realnej przeglądarce
// użytkownika (gdzie zabezpieczenia portali przepuszczają człowieka), odebrać
// od content-scriptu przechwycone wyniki i odesłać je do programu. Bez
// headless, bez podszywania — to realna sesja.

const DEFAULTS = { appUrl: "http://127.0.0.1:8080", token: "", scheduleHour: 7, scheduleMinute: 0, autoRun: true, instanceLabel: "", backgroundWindow: true };
// Ile kart naraz w JEDNEJ przeglądarce. COFNIĘTE DO 1 (2026-07-13): po włączeniu
// równoległości (2) przebiegi KRZ przestały się KOŃCZYĆ — wieloramkowy, wrażliwy na
// czas przepływ KRZ + dławienie kart w tle przez Chrome + limity service workera MV3
// zostawiały przebieg zawieszony. Sekwencyjnie (1) KRZ znów się kończy (wolniej, ale
// niezawodnie). Przyspieszenie robimy poziomo (WIELE komputerów — kolejka dzieli się
// przez claimed_by), nie równoległością w jednej przeglądarce. NIE podnosić bez
// rozwiązania keepalive dla service workera MV3.
const MAX_CONCURRENT_TABS = 1;
// Bezpiecznik pętli runSource — musi być większy niż liczba monitorowanych podmiotów.
const MAX_RUN_ROUNDS = 500;
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// Chrome potrafi chwilowo odrzucić tabs.create/tabs.update komunikatem "Tabs cannot
// be edited right now (user may be dragging a tab)" — to wewnętrzna niespójność
// Chrome tuż po zamknięciu poprzedniej karty automatu (przebudowa paska kart), NIE
// realne przeciąganie karty przez człowieka. Bez ponowienia pojedyncze takie
// zdarzenie na stałe wykreślało podmiot z dzisiejszego przebiegu (obserwowane m.in.
// na ADCOOKIE i SŁAWOMIRZE KAŹMIERCZAKU — różne podmioty, ten sam losowy błąd Chrome).
async function withTabEditRetry(action, attempts = 5, stepMs = 400) {
  let lastError;
  for (let i = 0; i < attempts; i++) {
    try { return await action(); }
    catch (e) {
      lastError = e;
      if (!/cannot be edited right now/i.test(String((e && e.message) || e))) throw e;
      await delay(stepMs);
    }
  }
  throw lastError;
}

// Każde źródło ma własny portal, własny content script i własne endpointy —
// KRZ i MSiG to dwie niezależne wyszukiwarki rządowe.
const SOURCES = {
  KRZ: {
    key: "KRZ",
    worklistPath: "/api/krz/worklist",
    ingestPath: "/api/krz/ingest",
    runFinishedPath: "/api/krz/run-finished",
    urlField: "krz_url",
    defaultUrl: "https://portal-pub-prod.apps.ocp.prod.ms.gov.pl/",
  },
  MSIG: {
    key: "MSIG",
    worklistPath: "/api/msig/worklist",
    ingestPath: "/api/msig/ingest",
    runFinishedPath: "/api/msig/run-finished",
    urlField: "msig_url",
    defaultUrl: "https://wyszukiwarka-msig.ms.gov.pl/",
  },
};

// Karty otwarte przez automat muszą przeżyć utratę pamięci service workera MV3.
// Chrome może uśpić worker w trakcie długiej nawigacji KRZ; zwykły obiekt
// jobsByTab wtedy znikał i nikt nie dochodził już do chrome.tabs.remove().
const MANAGED_TABS_KEY = "duirManagedAutomationTabsV1";
// Gdy worker umrze w trakcie zadania, dzierżawa serwera wygasa po 15 min. Przez
// 18 min nie uznajemy pustej worklisty za zakończony sweep, aby poll po wygaśnięciu
// dzierżawy ponownie przejął zadanie jeszcze tego samego dnia.
const SWEEP_EMPTY_RETRY_MS = 18 * 60 * 1000;
// Karta pośrednia daje deterministyczną barierę startu: content-script portalu
// nie może poprosić o zadanie, zanim jobsByTab zostanie ustawione. Bez tej bariery
// szybka ramka formularza potrafiła dostać job:null i już nigdy nie pytała ponownie.
const AUTOMATION_STAGING_URL = chrome.runtime.getURL("staging.html");

async function managedTabs() {
  const stored = await chrome.storage.local.get(MANAGED_TABS_KEY);
  return Array.isArray(stored[MANAGED_TABS_KEY]) ? stored[MANAGED_TABS_KEY] : [];
}

async function saveManagedTabs(items) {
  await chrome.storage.local.set({ [MANAGED_TABS_KEY]: items.slice(-20) });
}

async function rememberManagedTab(tab, source, item) {
  const list = (await managedTabs()).filter((x) => Number(x.tabId) !== Number(tab.id));
  list.push({
    tabId: tab.id,
    windowId: tab.windowId,
    source: source.key,
    taskId: item.task_id || null,
    createdAt: Date.now(),
  });
  await saveManagedTabs(list);
}

async function forgetManagedTab(tabId) {
  await saveManagedTabs((await managedTabs()).filter((x) => Number(x.tabId) !== Number(tabId)));
}

function tabMatchesManagedSource(tab, sourceKey) {
  const url = String((tab && (tab.url || tab.pendingUrl)) || "");
  if (url === AUTOMATION_STAGING_URL) return sourceKey === "KRZ" || sourceKey === "MSIG";
  if (sourceKey === "KRZ") return /^https:\/\/(?:[^/]+\.)?apps\.ocp\.prod\.ms\.gov\.pl\//i.test(url)
    || /^https:\/\/(?:krz|prs)\.ms\.gov\.pl\//i.test(url);
  if (sourceKey === "MSIG") return /^https:\/\/wyszukiwarka-msig\.ms\.gov\.pl\//i.test(url);
  return false;
}

async function tabExists(tabId) {
  try { return await chrome.tabs.get(tabId); } catch (_) { return null; }
}

async function closeAutomationTab(tabId) {
  // Zwykłe API najpierw; dwie próby pokrywają chwilę przebudowy SPA.
  for (let attempt = 0; attempt < 2; attempt++) {
    try { await chrome.tabs.remove(tabId); } catch (_) {}
    await delay(250);
    if (!(await tabExists(tabId))) return true;
  }
  // KRZ rejestruje beforeunload. Gdy portal mimo wszystko zatrzyma zwykłe
  // zamknięcie, domykamy WYŁĄCZNIE zapamiętaną kartę automatu przez jej target CDP.
  const root = { tabId };
  let attached = false;
  try {
    await chrome.debugger.attach(root, "1.3");
    attached = true;
    const info = await chrome.debugger.sendCommand(root, "Target.getTargetInfo");
    const targetId = info && info.targetInfo && info.targetInfo.targetId;
    if (targetId) await chrome.debugger.sendCommand(root, "Target.closeTarget", { targetId });
  } catch (_) {
  } finally {
    if (attached) { try { await chrome.debugger.detach(root); } catch (_) {} }
  }
  await delay(300);
  return !(await tabExists(tabId));
}

async function cleanupOrphanedManagedTabs() {
  const list = await managedTabs();
  const keep = [];
  let orphanDetected = false;
  for (const record of list) {
    const tabId = Number(record.tabId);
    if (!Number.isInteger(tabId)) continue;
    // W tej samej instancji workera karta nadal ma żywe zadanie — nie dotykamy.
    if (jobsByTab[tabId]) { keep.push(record); continue; }
    const tab = await tabExists(tabId);
    // Sam trwały rekord bez żywego jobu dowodzi utraty pamięci poprzedniego
    // workera, także gdy Chrome zdążył już sam zamknąć kartę.
    if (!tab) { orphanDetected = true; continue; }
    // Ochrona przed ponownym użyciem tabId po restarcie całej przeglądarki.
    if (Number(record.windowId) !== Number(tab.windowId) || !tabMatchesManagedSource(tab, record.source)) continue;
    const closed = await closeAutomationTab(tabId);
    if (!closed) keep.push(record);
    else orphanDetected = true;
  }
  await saveManagedTabs(keep);
  // Nie zostawiaj znacznika „obsłużone” po utracie pamięci jobsByTab. Następny
  // krzPoll ma wejść w kontrolowane okno ponawiania i odzyskać wygasłą dzierżawę.
  if (orphanDetected) {
    await chrome.storage.local.set({ lastSweepHandled: null, sweepRetryState: null });
  }
}

async function getConfig() {
  const c = await chrome.storage.local.get(DEFAULTS);
  return { ...DEFAULTS, ...c };
}

function appHeaders(token) {
  return { "Content-Type": "application/json", "X-KRZ-Token": token };
}

// Stabilny identyfikator TEGO komputera/instalacji wtyczki (losowany raz, trwały w
// chrome.storage.local). Serwer po nim liczy, ile RÓŻNYCH komputerów jest aktywnych —
// dzięki temu widać, że codzienne sprawdzenie rozkłada się na więcej niż jedną maszynę.
async function getInstanceId() {
  const { duirInstanceId } = await chrome.storage.local.get("duirInstanceId");
  if (duirInstanceId) return duirInstanceId;
  const id = "inst-" + Array.from(crypto.getRandomValues(new Uint8Array(9)))
    .map((b) => b.toString(16).padStart(2, "0")).join("");
  await chrome.storage.local.set({ duirInstanceId: id });
  return id;
}

async function appFetch(path, opts = {}) {
  const { appUrl, token, instanceLabel } = await getConfig();
  const instanceId = await getInstanceId();
  const url = appUrl.replace(/\/+$/, "") + path;
  // Serwer wpuszcza do wspólnej kolejki tylko wtyczki obsługujące token
  // dzierżawy zadania. Dzięki temu stary komputer nie może przejąć zadania,
  // którego nie umie bezpiecznie domknąć, i blokować go przez cały timeout.
  const instHeaders = {
    "X-DUiR-Instance": instanceId,
    "X-DUiR-Plugin-Version": chrome.runtime.getManifest().version,
  };
  if (instanceLabel) instHeaders["X-DUiR-Instance-Label"] = String(instanceLabel).slice(0, 120);
  const resp = await fetch(url, { ...opts, headers: { ...appHeaders(token), ...instHeaders, ...(opts.headers || {}) } });
  if (!resp.ok) {
    let detail = "";
    try { detail = String((await resp.json()).error || ""); } catch (_) {}
    throw new Error(`HTTP ${resp.status} (${path})${detail ? ": " + detail : ""}`);
  }
  return resp.json();
}

async function ping() {
  try { return { ok: true, info: await appFetch("/api/krz/ping") }; }
  catch (e) { return { ok: false, error: String(e) }; }
}

async function getWorklist(source) {
  const data = await appFetch(source.worklistPath);
  return { items: data.items || [], portalUrl: data[source.urlField] || source.defaultUrl };
}

async function getSubjectsForMatching() {
  const data = await appFetch("/api/krz/subjects");
  return data.items || [];
}

async function ingest(source, subjectId, payload, meta = {}) {
  // payload: { text, url, items } — items to tablica pozycji (ogłoszenia/obwieszczenia
  // ze szczegółami). Zachowujemy pole text dla wstecznej kompatybilności i dopasowania.
  return appFetch(source.ingestPath, {
    method: "POST",
    body: JSON.stringify({
      subject_id: subjectId,
      text: payload.text || "",
      items: Array.isArray(payload.items) && payload.items.length ? payload.items : null,
      source_url: payload.url || null,
      trusted_match: !!meta.trustedMatch,
      query: meta.query || null,
      task_id: meta.taskId || null,
      claim_token: meta.claimToken || null,
    }),
  });
}

async function finishRun(source, summary) {
  return appFetch(source.runFinishedPath, { method: "POST", body: JSON.stringify({ ...summary, finished: new Date().toISOString() }) });
}

// Portal KRZ (PrimeNG w cross-origin/OOPIF) ignoruje zdarzenia click generowane
// przez content-script. Awaryjnie używamy oficjalnego chrome.debugger/CDP, aby
// wysłać JEDNO zdarzenie myszy do konkretnej zakładki. Debugger jest podpięty
// tylko na czas tego kliknięcia i zawsze odpinany w finally.
function normalizeDomText(value) {
  return String(value || "").replace(/<[^>]*>/g, " ").replace(/&nbsp;/gi, " ")
    .normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/\s+/g, " ").trim();
}

// Sonda w kontekście strony (ta sama sesja CDP): czy zakładka o oczekiwanej
// etykiecie jest zaznaczona? Pozwala ZWERYFIKOWAĆ skutek kliknięcia, zamiast
// uznawać samo wysłanie zdarzenia za sukces.
function krzTabSelectedProbe(expectedLabel) {
  return `(() => {
    const norm = (s) => String(s || "").normalize("NFD").replace(/[\\u0300-\\u036f]/g, "").toLowerCase().replace(/\\s+/g, " ").trim();
    const want = norm(${JSON.stringify(expectedLabel)});
    for (const el of document.querySelectorAll('[role="tab"]')) {
      if (norm(el.textContent).includes(want)) return el.getAttribute("aria-selected") || "";
    }
    return null;
  })()`;
}

async function krzTabSelectedInSession(session, expectedLabel) {
  try {
    const r = await chrome.debugger.sendCommand(session, "Runtime.evaluate", {
      expression: krzTabSelectedProbe(expectedLabel), returnByValue: true,
    });
    return !!(r && r.result && r.result.value === "true");
  } catch (_) { return false; }
}

async function pressEnterInSession(session) {
  const base = { key: "Enter", code: "Enter", windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13 };
  await chrome.debugger.sendCommand(session, "Input.dispatchKeyEvent", { type: "rawKeyDown", ...base });
  await chrome.debugger.sendCommand(session, "Input.dispatchKeyEvent", { type: "char", text: "\r", ...base });
  await chrome.debugger.sendCommand(session, "Input.dispatchKeyEvent", { type: "keyUp", ...base });
}

// Zwraca null, gdy zakładki nie ma w tej sesji; w przeciwnym razie
// { method, verified } — verified=true tylko po potwierdzeniu aria-selected.
async function clickKrzTabInSession(session, expectedLabel) {
  try {
    if (await krzTabSelectedInSession(session, expectedLabel)) return { method: "already-selected", verified: true };
    await chrome.debugger.sendCommand(session, "DOM.enable");
    await chrome.debugger.sendCommand(session, "DOM.getDocument", { depth: -1, pierce: true });
    const search = await chrome.debugger.sendCommand(session, "DOM.performSearch", {
      query: '[role="tab"]', includeUserAgentShadowDOM: true,
    });
    if (!search || !search.resultCount) return null;
    let dispatched = false;
    try {
      const found = await chrome.debugger.sendCommand(session, "DOM.getSearchResults", {
        searchId: search.searchId, fromIndex: 0, toIndex: Math.min(search.resultCount, 50),
      });
      for (const nodeId of (found && found.nodeIds) || []) {
        let html = "";
        try { html = (await chrome.debugger.sendCommand(session, "DOM.getOuterHTML", { nodeId })).outerHTML || ""; }
        catch (_) { continue; }
        if (!normalizeDomText(html).includes(normalizeDomText(expectedLabel))) continue;
        try { await chrome.debugger.sendCommand(session, "DOM.scrollIntoViewIfNeeded", { nodeId }); } catch (_) {}
        const box = await chrome.debugger.sendCommand(session, "DOM.getBoxModel", { nodeId });
        const q = box && box.model && box.model.border;
        if (Array.isArray(q) && q.length >= 8) {
          const x = (q[0] + q[2] + q[4] + q[6]) / 4;
          const y = (q[1] + q[3] + q[5] + q[7]) / 4;
          await chrome.debugger.sendCommand(session, "Input.dispatchMouseEvent", { type: "mouseMoved", x, y, button: "none" });
          await chrome.debugger.sendCommand(session, "Input.dispatchMouseEvent", { type: "mousePressed", x, y, button: "left", buttons: 1, clickCount: 1 });
          await chrome.debugger.sendCommand(session, "Input.dispatchMouseEvent", { type: "mouseReleased", x, y, button: "left", buttons: 0, clickCount: 1 });
          dispatched = true;
          await delay(500);
          if (await krzTabSelectedInSession(session, expectedLabel)) return { method: "cdp-click", verified: true };
        }
        // Współrzędne kliknięcia w OOPIF mogły trafić obok (układ współrzędnych
        // ramki vs strony) — fallback bez współrzędnych: realny fokus + Enter.
        try {
          await chrome.debugger.sendCommand(session, "DOM.focus", { nodeId });
          await pressEnterInSession(session);
          dispatched = true;
          await delay(500);
          if (await krzTabSelectedInSession(session, expectedLabel)) return { method: "cdp-focus-enter", verified: true };
        } catch (_) {}
      }
    } finally {
      try { await chrome.debugger.sendCommand(session, "DOM.discardSearchResults", { searchId: search.searchId }); } catch (_) {}
    }
    return dispatched ? { method: "cdp-click", verified: false } : null;
  } catch (_) {}
  return null;
}

async function trustedKrzTabClick(tabId, kind) {
  const labels = {
    company: "Podmiot niebędący osobą fizyczną",
    business_person: "Osoba fizyczna prowadząca działalność gospodarczą",
    natural_person: "Osoba fizyczna nieprowadząca działalności gospodarczej",
  };
  const expectedLabel = labels[kind];
  if (!expectedLabel) return { ok: false, error: "Nieznany typ zakładki KRZ." };
  const root = { tabId };
  const sessions = [root];
  const sessionKeys = new Set(["root"]);
  let attached = false;
  const onEvent = (source, method, params) => {
    if (source.tabId !== tabId || method !== "Target.attachedToTarget" || !params || !params.sessionId) return;
    const key = params.sessionId;
    if (sessionKeys.has(key)) return;
    sessionKeys.add(key);
    const child = { tabId, sessionId: params.sessionId };
    sessions.push(child);
    // Rekurencyjne auto-attach: moduł KRZ może zawierać kolejny OOPIF.
    chrome.debugger.sendCommand(child, "Target.setAutoAttach", {
      autoAttach: true, waitForDebuggerOnStart: false, flatten: true,
      filter: [{ type: "iframe", exclude: false }],
    }).catch(() => {});
  };
  chrome.debugger.onEvent.addListener(onEvent);
  try {
    await chrome.debugger.attach(root, "1.3");
    attached = true;
    await chrome.debugger.sendCommand(root, "Target.setAutoAttach", {
      autoAttach: true, waitForDebuggerOnStart: false, flatten: true,
      filter: [{ type: "iframe", exclude: false }],
    });
    // Daj Chrome chwilę na zgłoszenie już istniejących OOPIF-ów.
    await delay(600);
    let dispatched = null;
    for (let pass = 0; pass < 3; pass++) {
      for (const session of [...sessions]) {
        const r = await clickKrzTabInSession(session, expectedLabel);
        if (!r) continue;
        if (r.verified) return { ok: true, method: r.method, verified: true };
        dispatched = r;
      }
      await delay(350);
    }
    // Kliknięcie/Enter poszło, ale aria-selected nie potwierdziło przełączenia —
    // portal może nie zarządzać aria-selected; ramka formularza rozstrzygnie
    // po zmianie układu pól (searchTabReadyRelaxed).
    if (dispatched) return { ok: true, method: dispatched.method, verified: false };
    return { ok: false, error: "Chrome nie odnalazł aktywnej kontrolki zakładki KRZ." };
  } catch (e) {
    return { ok: false, error: "Nie udało się wykonać natywnego kliknięcia KRZ: " + String(e && e.message ? e.message : e) };
  } finally {
    chrome.debugger.onEvent.removeListener(onEvent);
    if (attached) { try { await chrome.debugger.detach(root); } catch (_) {} }
  }
}

// Dopasowanie przechwyconego tekstu do podmiotu po twardych identyfikatorach
// (cyfry KRS/NIP/REGON obecne w treści), a w ostateczności po nazwie. Wspólne
// dla KRZ i MSiG — obie wyszukiwarki zwracają zwykłą treść tekstową.
function matchSubject(text, items) {
  const fold = (value) => String(value || "").normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/ł/g, "l")
    .replace(/\s+/g, " ").trim();
  const norm = fold(text);
  const weak = new Set(["firma", "kancelaria", "klient", "kontrahent", "podmiot", "spolka", "spółka", "dluznik", "dłużnik"]);
  // Wyodrębnij z treści izolowane tokeny cyfrowe (z ewentualnymi separatorami:
  // spacja/myślnik) OTOCZONE granicami nie-cyfrowymi. Po usunięciu separatorów
  // zostawiamy tylko te o długości polskich identyfikatorów (REGON 9/14,
  // NIP/KRS 10, PESEL 11). Zapobiega to fałszywym trafieniom, gdy identyfikator
  // powstaje przypadkiem jako podciąg zlepka wszystkich cyfr strony.
  const ID_LENGTHS = new Set([9, 10, 11, 14]);
  const foundIds = new Set();
  const raw = String(text || "");
  const re = /(?<!\d)[\d][\d\s-]{7,18}[\d](?!\d)/g;
  let m;
  while ((m = re.exec(raw)) !== null) {
    const cleaned = m[0].replace(/[^0-9]/g, "");
    if (ID_LENGTHS.has(cleaned.length)) foundIds.add(cleaned);
  }
  for (const it of items) {
    for (const id of [it.krs, it.nip, it.regon, it.pesel]) {
      const d = (id || "").replace(/[^0-9]/g, "");
      if (d && ID_LENGTHS.has(d.length) && foundIds.has(d)) return it.subject_id;
    }
  }
  for (const it of items) {
    const name = fold(it.name);
    const core = name.replace(/\b(sp(\.|ółka)?|z ?o\.?o\.?|s\.?a\.?|spółka|akcyjna|komandytowa|w upadłości|w likwidacji)\b/g, "").trim();
    const tokens = core.split(/[^a-z0-9ąćęłńóśźż]+/i).filter(Boolean);
    if (tokens.length === 1 && weak.has(tokens[0])) continue;
    if (core.length >= 4 && norm.includes(core)) return it.subject_id;
  }
  return null;
}

function captureMatchesSubject(text, captureItems, subject) {
  if (!subject || !subject.subject_id) return false;
  const positive = Array.isArray(captureItems)
    ? captureItems.filter((it) => it && !it.known && String(it.text || "").trim() !== "")
    : [];
  // Każdy NOWY wpis musi samodzielnie wskazywać ten podmiot. Dopasowanie jednego
  // elementu nie może „uświęcić” obcych pozycji z tej samej tablicy.
  if (positive.length) {
    return positive.every((it) => matchSubject(String(it.text || ""), [subject]) === subject.subject_id);
  }
  // Same znaczniki `known` nie zapisują treści ponownie; ich własność zostanie
  // dodatkowo sprawdzona przez task_id po stronie serwera.
  if (Array.isArray(captureItems) && captureItems.length && captureItems.every((it) => it && it.known)) return true;
  return matchSubject(String(text || ""), [subject]) === subject.subject_id;
}

// --- Orkiestracja automatycznego przebiegu --------------------------------
const jobsByTab = {};        // tabId -> { source, taskId, query, subjectId, captures, resolve, done }
let runInProgress = false;
// Przy KAŻDYM ponownym uruchomieniu service workera sprzątnij kartę, której stan
// zniknął razem z poprzednią instancją. Nie obejmuje to ręcznych kart KRZ.
const initialManagedTabsCleanup = cleanupOrphanedManagedTabs().catch(() => {});
// Jedna karta globalnie, ale źródła mogą równolegle czekać w uczciwej kolejce.
// Dzięki temu po jednym zadaniu/timeoutcie KRZ kolej dostaje MSiG, zamiast czekać
// na opróżnienie całej kolejki KRZ.
let tabSlotTail = Promise.resolve();

async function withTabSlot(work) {
  let release;
  const mine = new Promise((resolve) => { release = resolve; });
  const previous = tabSlotTail;
  tabSlotTail = mine;
  await previous;
  try { return await work(); }
  finally { release(); }
}

// Okno robocze automatu (od 1.9.0). Wcześniej każda karta KRZ/MSiG otwierała
// się jako aktywna w bieżącym oknie i wtyczka wywoływała to okno na wierzch —
// w trakcie przebiegu użytkownik tracił klawiaturę i mysz. Teraz automat pracuje
// w OSOBNYM oknie tworzonym bez fokusu (focused:false). Pierwsza karta okna to
// strona-uchwyt: dzięki niej zamknięcie karty zadania nie zamyka całego okna.
// Okno zamykamy po zakończeniu przebiegu (closeAutomationWindow w runAll).
const AUTOMATION_WINDOW_KEY = "duirAutomationWindowV1";
const AUTOMATION_HOLDER_URL = chrome.runtime.getURL("automation_window.html");

async function existingAutomationWindowId() {
  const stored = (await chrome.storage.local.get(AUTOMATION_WINDOW_KEY))[AUTOMATION_WINDOW_KEY];
  const id = stored && Number(stored.windowId);
  if (!Number.isInteger(id)) return null;
  try {
    const win = await chrome.windows.get(id, { populate: true });
    // Ochrona przed ponownym użyciem windowId po restarcie Chrome: okno musi
    // nadal zawierać naszą stronę-uchwyt.
    const ours = (win.tabs || []).some((t) => String(t.url || t.pendingUrl || "") === AUTOMATION_HOLDER_URL);
    return ours ? id : null;
  } catch (_) { return null; }
}

async function openAutomationWindowTab() {
  const windowId = await existingAutomationWindowId();
  if (windowId !== null) {
    // active:true dotyczy tylko karty WEWNĄTRZ okna robocze — nie przenosi fokusu.
    return withTabEditRetry(() => chrome.tabs.create({ windowId, url: AUTOMATION_STAGING_URL, active: true }));
  }
  // Pierwszy adres okna jest kartą aktywną — to karta zadania; uchwyt czeka obok.
  const win = await chrome.windows.create({
    url: [AUTOMATION_STAGING_URL, AUTOMATION_HOLDER_URL], focused: false, type: "normal", width: 1280, height: 900,
  });
  await chrome.storage.local.set({ [AUTOMATION_WINDOW_KEY]: { windowId: win.id, createdAt: Date.now() } });
  const tab = (win.tabs || []).find((t) => String(t.url || t.pendingUrl || "") !== AUTOMATION_HOLDER_URL);
  if (tab) return tab;
  return withTabEditRetry(() => chrome.tabs.create({ windowId: win.id, url: AUTOMATION_STAGING_URL, active: true }));
}

async function closeAutomationWindow() {
  const windowId = await existingAutomationWindowId();
  await chrome.storage.local.remove(AUTOMATION_WINDOW_KEY);
  if (windowId === null) return;
  try {
    const win = await chrome.windows.get(windowId, { populate: true });
    // Zamykamy okno tylko wtedy, gdy zostały w nim wyłącznie nasze strony —
    // karta otwarta tam ręcznie przez użytkownika nie zniknie bez ostrzeżenia.
    const foreign = (win.tabs || []).some((t) => {
      const url = String(t.url || t.pendingUrl || "");
      return url !== AUTOMATION_HOLDER_URL && url !== AUTOMATION_STAGING_URL;
    });
    if (!foreign) await chrome.windows.remove(windowId);
  } catch (_) {}
}

// Chrome działający w tle bez żadnego otwartego okna (wszystkie okna zamknięte,
// proces trzymany przez „Kontynuuj działanie aplikacji w tle") odrzuca
// tabs.create komunikatem "No current window". 18.10.2026 tak przepadł cały
// przebieg KRZ i MSiG. Wtedy otwieramy własne okno z kartą automatu.
async function openAutomationTab() {
  const cfg = await getConfig();
  if (cfg.backgroundWindow !== false) return openAutomationWindowTab();
  try {
    return await withTabEditRetry(() => chrome.tabs.create({ url: AUTOMATION_STAGING_URL, active: true }));
  } catch (e) {
    if (!/no current window/i.test(String((e && e.message) || e))) throw e;
    const win = await chrome.windows.create({ url: AUTOMATION_STAGING_URL, focused: true, type: "normal" });
    const tab = win && win.tabs && win.tabs[0];
    if (!tab) throw e;
    return tab;
  }
}

// Ponawiamy tylko zadania, które nie dały ŻADNEGO rozstrzygnięcia (ani pozycji,
// ani potwierdzonego braku wyników): timeout, brak odpowiedzi portalu, padnięta
// ramka. Portal KRZ bywa chwilowo wolny — świeża karta zwykle przechodzi.
function shouldRetryItem(result) {
  if (!result) return false;
  if (result.captured || result.noResults) return false;
  return !!(result.error || result.timedOut);
}

function waitForTabJob(tabId, timeoutMs) {
  return new Promise((resolve) => {
    const job = jobsByTab[tabId];
    if (!job) return resolve({ captured: 0 });
    job.resolve = resolve;
    job.timer = setTimeout(() => {
      if (jobsByTab[tabId] && !jobsByTab[tabId].done) {
        jobsByTab[tabId].done = true;
        resolve({ captured: job.captures, timedOut: true });
      }
    }, timeoutMs);
  });
}

async function processItemInTab(source, item, portalUrl) {
  let tab;
  try {
    // Najpierw własna, neutralna strona rozszerzenia. Dopiero po zapisaniu jobu
    // przechodzimy do portalu — kolejność nie zależy już od timingu tabs.create().
    // withTabEditRetry pochłania chwilowe "Tabs cannot be edited right now".
    tab = await openAutomationTab();
    // Wywołanie okna na wierzch tylko w trybie zgodności (backgroundWindow=false).
    if ((await getConfig()).backgroundWindow === false) {
      try { await chrome.windows.update(tab.windowId, { focused: true }); } catch (_) {}
    }
  } catch (e) {
    return { task_id: item.task_id, claim_token: item.claim_token, subject_id: item.subject_id, subject: item.name, error: `Nie udało się otworzyć karty ${source.key}: ` + e };
  }
  jobsByTab[tab.id] = {
    source: source.key, taskId: item.task_id, claimToken: item.claim_token, query: item.query,
    queryKey: item.query_key, searchKind: item.search_kind,
    subjectId: item.subject_id, hasKrs: !!String(item.krs || "").replace(/\D/g, ""),
    subject: { subject_id: item.subject_id, name: item.name, krs: item.krs, nip: item.nip, regon: item.regon, pesel: item.pesel },
    seen: Array.isArray(item.seen) ? item.seen : [], captures: 0, errors: [], done: false,
    workerFrameId: null, workerDocumentId: null, workerLastSeen: 0,
    // Moment utworzenia karty — wspólny punkt zerowy zegara zadania, przekazywany
    // każdej ramce (top i formularz) przez krzReady. Każda ramka to OSOBNY kontekst
    // JS (osobne zmienne modułu), więc bez tego pola każda liczyła swój własny
    // budżet drążenia od WŁASNEGO, spóźnionego Date.now() — patrz content_krz.js.
    startedAt: Date.now(),
  };
  try { await rememberManagedTab(tab, source, item); } catch (_) {}
  // Rejestrujemy resolver również PRZED nawigacją. Bardzo szybki brak wyników nie
  // może zakończyć jobu w szczelinie między tabs.update() a waitForTabJob().
  const resultPromise = waitForTabJob(tab.id, 120000);
  try {
    await withTabEditRetry(() => chrome.tabs.update(tab.id, { url: portalUrl, active: true }));
  } catch (e) {
    const stagedJob = jobsByTab[tab.id];
    if (stagedJob && stagedJob.timer) clearTimeout(stagedJob.timer);
    delete jobsByTab[tab.id];
    const closed = await closeAutomationTab(tab.id);
    if (closed) { try { await forgetManagedTab(tab.id); } catch (_) {} }
    return {
      task_id: item.task_id, claim_token: item.claim_token, subject_id: item.subject_id,
      subject: item.name, error: `Nie udało się otworzyć portalu ${source.key}: ` + e,
    };
  }
  const result = await resultPromise;
  const job = jobsByTab[tab.id];
  delete jobsByTab[tab.id];
  const closed = await closeAutomationTab(tab.id);
  if (closed) { try { await forgetManagedTab(tab.id); } catch (_) {} }
  const errors = (job && job.errors) || [];
  const captured = (job && job.captures) || result.captured || 0;
  const noResults = !!(result.noResults || (job && job.noResults));
  let error = errors.length ? `${source.key} odrzucił przechwyconą treść: ${errors.join(", ")}` : (result.error || undefined);
  if (!error && !captured && !noResults && !result.timedOut) {
    error = `Nie potwierdzono ani obwieszczenia, ani braku wyników ${source.key}.`;
  }
  return {
    task_id: item.task_id,
    claim_token: item.claim_token,
    subject_id: item.subject_id,
    subject: item.name,
    query: item.query,
    captured,
    noResults,
    checked: !!(captured || noResults),
    timedOut: !!result.timedOut,
    error,
    pageText: result.pageText || undefined,
  };
}

async function processItem(source, item, portalUrl) {
  return withTabSlot(() => processItemInTab(source, item, portalUrl));
}

// Wynik zadania zgłaszamy serwerowi OD RAZU po jego wykonaniu, a nie zbiorczo na
// końcu przebiegu. Serwer trzyma zadanie jako „running" do zgłoszenia wyniku i po
// 15 min dzierżawy uznaje je za porzucone. 20.10.2026 przebieg KRZ trwał dłużej:
// serwer wydał ponownie pierwsze, jeszcze niezgłoszone zadanie, wtyczka uznała
// to za koniec kolejki i przerwała przebieg o 10:07 — 21 zadań KRZ czekało do
// wieczora. Zgłoszenie bieżące zamyka zadanie, zanim dzierżawa wygaśnie.
async function reportItem(source, result) {
  try {
    const res = await finishRun(source, { ok: !(result.error || result.timedOut), partial: true, items: [result] });
    return !!(res && res.ok !== false);
  } catch (_) { return false; }
}

// Ile razy w jednym przebiegu wolno przyjąć to samo zadanie. Drugi raz serwer
// wyda je tylko wtedy, gdy nasze zgłoszenie wyniku się nie powiodło i dzierżawa
// wygasła — wtedy zadanie ma nowy token i trzeba je wykonać jeszcze raz.
const MAX_TASK_ISSUES_PER_RUN = 2;
// Kolejne puste rundy (tylko zadania już wykonane dwa razy) przed zakończeniem.
const MAX_IDLE_ROUNDS = 3;

async function runSource(source) {
  const summary = { ok: true, items: [] };
  // Serwer wydaje po JEDNYM zadaniu (TASK_BATCH=1) z atomową rezerwacją
  // (claimed_by), żeby wiele wtyczek w kancelarii dzieliło kolejkę. Pętla: weź
  // zadanie -> wykonaj -> zgłoś wynik -> poproś o następne, aż serwer odda pustą
  // listę. MAX_RUN_ROUNDS to tylko bezpiecznik przed nieskończoną pętlą.
  const issued = new Map();
  const unsent = [];
  let idleRounds = 0;
  for (let round = 0; round < MAX_RUN_ROUNDS; round++) {
    const { items, portalUrl } = await getWorklist(source);
    if (!items.length) break;
    // Ponowne wydanie tego samego zadania NIE oznacza końca kolejki (tak było do
    // 1.9.0). Zadanie wydane po raz trzeci pomijamy, żeby nie mielić go w kółko.
    const fresh = items.filter((it) => (issued.get(it.task_id ?? `s${it.subject_id}`) || 0) < MAX_TASK_ISSUES_PER_RUN);
    if (!fresh.length) {
      if (++idleRounds >= MAX_IDLE_ROUNDS) break;
      await delay(2000);
      continue;
    }
    idleRounds = 0;
    for (const item of fresh) {
      const key = item.task_id ?? `s${item.subject_id}`;
      issued.set(key, (issued.get(key) || 0) + 1);
    }
    // MAX_CONCURRENT_TABS=1: oba źródła przeplata uczciwa kolejka withTabSlot,
    // a skalowanie odbywa się przez wiele komputerów.
    for (let i = 0; i < fresh.length; i += MAX_CONCURRENT_TABS) {
      const slice = fresh.slice(i, i + MAX_CONCURRENT_TABS);
      const results = await Promise.all(slice.map(async (item) => {
        const first = await processItem(source, item, portalUrl);
        let result = first;
        if (shouldRetryItem(first)) {
          // Jedna ponowna próba od razu — dzierżawa zadania (15 min) jest jeszcze ważna.
          await delay(5000);
          const second = await processItem(source, item, portalUrl);
          result = shouldRetryItem(second) && second.error ? { ...second, error: second.error + " (po ponownej próbie)" } : second;
        }
        if (!(await reportItem(source, result))) unsent.push(result);
        return result;
      }));
      summary.items.push(...results);
    }
  }
  const failed = summary.items.filter((item) => item && (item.error || item.timedOut));
  if (failed.length) {
    summary.ok = false;
    summary.error = `Niepełny przebieg ${source.key}: ${failed.length} zadań zakończyło się błędem lub timeoutem.`;
  }
  // Na koniec wysyłamy już tylko wyniki, których bieżące zgłoszenie się nie powiodło.
  try { await finishRun(source, { ...summary, items: unsent }); } catch (_) {}
  return summary;
}

async function runAll() {
  if (runInProgress) return { ok: false, error: "Przebieg już trwa" };
  runInProgress = true;
  // Oficjalny wzorzec Chrome dla długiej operacji MV3: wywołanie lekkiego API
  // przed upływem 30 s. Bez tego worker mógł stracić jobsByTab, gdy KRZ długo
  // przechodził między ramkami, a karta szczegółów pozostawała osierocona.
  const keepAlive = setInterval(() => { chrome.runtime.getPlatformInfo().catch(() => {}); }, 20000);
  chrome.runtime.getPlatformInfo().catch(() => {});
  const summary = { ok: true, items: [], started: new Date().toISOString(), bySource: {} };
  try {
    // Nie dopuść, aby asynchroniczny cleanup z początku workera nadpisał listę
    // chwilę po zapamiętaniu pierwszej nowej karty.
    await initialManagedTabsCleanup;
    const p = await ping();
    if (!p.ok) throw new Error("Brak połączenia z programem: " + (p.error || "nieznany błąd"));
    // Oba źródła budują własne kolejki równolegle, a withTabSlot wpuszcza je po
    // jednej karcie. Startujemy od MSiG, żeby długi timeout KRZ nie odsunął całego
    // źródła na koniec przebiegu; kolejne zadania układają się fair w kolejce slotu.
    const sourceRuns = [SOURCES.MSIG, SOURCES.KRZ].map(async (source) => {
      try { return [source, await runSource(source)]; }
      catch (e) { return [source, { ok: false, items: [], error: `Przebieg ${source.key} przerwany błędem: ${String(e)}` }]; }
    });
    for (const [source, r] of await Promise.all(sourceRuns)) {
      summary.bySource[source.key] = r;
      summary.items.push(...r.items.map((it) => ({ ...it, source: source.key })));
      if (!r.ok) { summary.ok = false; summary.error = [summary.error, r.error].filter(Boolean).join(" "); }
    }
    return summary;
  } catch (e) {
    summary.ok = false; summary.error = String(e);
    return summary;
  } finally {
    clearInterval(keepAlive);
    try { await closeAutomationWindow(); } catch (_) {}
    await chrome.storage.local.set({ lastRun: { at: new Date().toISOString(), summary } });
    runInProgress = false;
  }
}

// --- Harmonogram ----------------------------------------------------------
async function rescheduleAlarm() {
  const cfg = await getConfig();
  await chrome.alarms.clear("krzDaily");
  await chrome.alarms.clear("krzPoll");
  // Krótki poll co 3 min — wyłapuje zlecenia „Sprawdź teraz" z panelu programu
  // (działa niezależnie od harmonogramu dziennego, dla KRZ i MSiG naraz).
  chrome.alarms.create("krzPoll", { periodInMinutes: 3 });
  if (!cfg.autoRun) return;
  const now = new Date();
  const next = new Date();
  next.setHours(cfg.scheduleHour, cfg.scheduleMinute, 0, 0);
  if (next <= now) next.setDate(next.getDate() + 1);
  chrome.alarms.create("krzDaily", { when: next.getTime(), periodInMinutes: 1440 });
}

// Data LOKALNA: toISOString() dawało datę UTC, więc między 0:00 a 2:00 czasu
// polskiego „dziś" wtyczki było jeszcze wczoraj.
function todayStr() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}
// Przebieg uznajemy za wykonany, gdy faktycznie przeszedł przez kolejkę — także
// z błędami pojedynczych zadań (te wrócą jutro). Nie liczy się „Przebieg już
// trwa" ani brak połączenia z programem: wtedy poll spróbuje ponownie za 3 min.
function dailyRunCompleted(r) {
  return !!r && !/Przebieg już trwa|Brak połączenia/i.test(String(r.error || ""));
}
async function markRunToday() { try { await chrome.storage.local.set({ lastRunDate: todayStr() }); } catch (_) {} }

// Catch-up: jeśli zaplanowany przebieg na dziś został przegapiony (przeglądarka
// była wyłączona o ustawionej godzinie), wykonaj go teraz — dzięki temu raport w
// programie, który czeka na odczyt KRZ/MSiG, powstaje po włączeniu przeglądarki.
async function maybeCatchUp() {
  const cfg = await getConfig();
  if (!cfg.autoRun) return;
  const { lastRunDate } = await chrome.storage.local.get("lastRunDate");
  const now = new Date();
  const passed = (now.getHours() > cfg.scheduleHour) ||
                 (now.getHours() === cfg.scheduleHour && now.getMinutes() >= cfg.scheduleMinute);
  if (lastRunDate !== todayStr() && passed) {
    const r = await runAll();
    if (dailyRunCompleted(r)) await markRunToday();
  }
}

// Przy instalacji/starcie: harmonogram, ping (rejestracja aktywności) i catch-up.
// Jednorazowe przestawienie godziny przebiegu z dawnego domyślnego 10:00 na 7:00
// (20.10.2026). Godzinę ustawioną ręcznie na inną niż 10:00 zostawiamy.
const SCHEDULE_VERSION = 2;
async function migrateSchedule() {
  const c = await chrome.storage.local.get(["scheduleVersion", "scheduleHour", "scheduleMinute"]);
  if (Number(c.scheduleVersion || 1) >= SCHEDULE_VERSION) return;
  const update = { scheduleVersion: SCHEDULE_VERSION };
  const oldDefault = c.scheduleHour === undefined || (Number(c.scheduleHour) === 10 && Number(c.scheduleMinute || 0) === 0);
  if (oldDefault) { update.scheduleHour = 7; update.scheduleMinute = 0; }
  await chrome.storage.local.set(update);
}

async function onInit() {
  try { await migrateSchedule(); } catch (_) {}
  await rescheduleAlarm();
  try { await ping(); } catch (_) {}
  try { await maybeCatchUp(); } catch (_) {}
}
chrome.runtime.onInstalled.addListener(onInit);
chrome.runtime.onStartup.addListener(onInit);
// Zlecenie „Sprawdź teraz" z panelu: program ustawia sweep_requested_at; gdy się
// zmieni, wtyczka wykonuje przegląd KRZ i MSiG (niezależnie od harmonogramu).
async function checkPendingSweep() {
  let krzInfo, msigInfo;
  try {
    [krzInfo, msigInfo] = await Promise.all([
      appFetch("/api/krz/ping"),
      appFetch("/api/msig/ping").catch(() => null),
    ]);
  } catch (_) { return; }
  // Osobne znaczniki: samodzielne zlecenie MSiG również musi obudzić worker.
  const krzRequested = krzInfo && krzInfo.sweep_requested_at;
  const msigRequested = msigInfo && msigInfo.sweep_requested_at;
  const requested = [krzRequested || "", msigRequested || ""].join("|");
  if (!krzRequested && !msigRequested) return;
  const { lastSweepHandled, sweepRetryState } = await chrome.storage.local.get(["lastSweepHandled", "sweepRetryState"]);
  if (requested === lastSweepHandled) return;
  const r = await runAll();
  // Jeśli inny przebieg właśnie trwa, runAll zwraca kolizję i NIC nie wykonał —
  // nie wolno oznaczać sweep jako obsłużonego, bo żądanie „Sprawdź teraz"
  // przepadłoby. Kolejny poll (krzPoll co 3 min) ponowi próbę po zakończeniu
  // trwającego przebiegu.
  if (r && r.error === "Przebieg już trwa") return;
  const processed = r && Array.isArray(r.items) ? r.items.length : 0;
  // Błąd przed pobraniem choćby jednego zadania (sieć/HTTP) zawsze ponawiamy.
  if ((!r || !r.ok) && processed === 0) return;
  if (processed === 0) {
    const now = Date.now();
    const sameAttempt = sweepRetryState && sweepRetryState.requested === requested;
    const firstSeenAt = sameAttempt ? Number(sweepRetryState.firstSeenAt || now) : now;
    if (now - firstSeenAt < SWEEP_EMPTY_RETRY_MS) {
      await chrome.storage.local.set({ sweepRetryState: { requested, firstSeenAt } });
      return;
    }
  }
  await chrome.storage.local.set({ lastSweepHandled: requested, sweepRetryState: null });
  if (r && r.ok) await markRunToday();
}

chrome.alarms.onAlarm.addListener(async (alarm) => {
  if (alarm.name === "krzPoll") {
    // Poll co 3 min nadrabia też dzienny przebieg: pierwszy komputer, na którym
    // po 7:00 działa Chrome z wtyczką, wykonuje dzisiejsze sprawdzenia.
    try { await maybeCatchUp(); } catch (_) {}
    await checkPendingSweep();
    return;
  }
  if (alarm.name !== "krzDaily") return;
  const cfg = await getConfig();
  if (cfg.autoRun) { const r = await runAll(); if (dailyRunCompleted(r)) await markRunToday(); }
});

// --- Komunikaty -----------------------------------------------------------
async function handleReady(source, sender, sendResponse) {
  const tabId = sender.tab && sender.tab.id;
  const job = tabId != null ? jobsByTab[tabId] : null;
  const matches = !!(job && job.source === source.key);
  sendResponse({ job: matches ? { query: job.query, queryKey: job.queryKey, searchKind: job.searchKind, subjectId: job.subjectId, hasKrs: !!job.hasKrs, seen: Array.isArray(job.seen) ? job.seen : [], startedAt: job.startedAt } : null });
}

function senderOwnsKrzWorker(job, sender) {
  if (!job || job.source !== SOURCES.KRZ.key) return false;
  const frameId = Number.isInteger(sender.frameId) ? sender.frameId : null;
  const documentId = sender.documentId || null;
  if (job.workerFrameId === null || frameId === null) return false;
  if (job.workerFrameId !== frameId) return false;
  if (job.workerDocumentId && documentId && job.workerDocumentId !== documentId) return false;
  return Date.now() - Number(job.workerLastSeen || 0) < 8000;
}

async function handleClaimKrzSearchFrame(sender, sendResponse) {
  const tabId = sender.tab && sender.tab.id;
  const job = tabId != null ? jobsByTab[tabId] : null;
  const frameId = Number.isInteger(sender.frameId) ? sender.frameId : null;
  if (!job || job.source !== SOURCES.KRZ.key || job.done || frameId === null || frameId === 0) {
    sendResponse({ ok: false, reason: "no_active_krz_job" }); return;
  }
  const expired = job.workerFrameId !== null && Date.now() - Number(job.workerLastSeen || 0) >= 8000;
  if (job.workerFrameId === null || expired) {
    job.workerFrameId = frameId;
    job.workerDocumentId = sender.documentId || null;
    job.workerLastSeen = Date.now();
    sendResponse({ ok: true }); return;
  }
  const owns = senderOwnsKrzWorker(job, sender);
  if (owns) job.workerLastSeen = Date.now();
  sendResponse({ ok: owns, reason: owns ? undefined : "runner_already_claimed" });
}

async function handleCapture(source, msg, sender, sendResponse) {
  const subjects = await getSubjectsForMatching().catch(() => []);
  // Content script może przysłać pojedynczy text (tryb ręczny/legacy) albo tablicę
  // pozycji items[] (automat: każde ogłoszenie/obwieszczenie ze szczegółami osobno).
  const captureItems = Array.isArray(msg.items) && msg.items.length ? msg.items : null;
  const matchText = msg.text || (captureItems ? captureItems.map((i) => (i && i.text) || "").join("\n") : "");
  let subjectId = msg.subjectId;
  const tabId = sender.tab && sender.tab.id;
  const job = tabId != null ? jobsByTab[tabId] : null;
  const jobMatches = !!(job && job.source === source.key);
  if (jobMatches && source.key === SOURCES.KRZ.key && !senderOwnsKrzWorker(job, sender)) {
    sendResponse({ ok: false, reason: "wrong_or_stale_krz_frame" }); return;
  }
  // W automacie podmiot zawsze wynika z rekordu zadania; content-script nie może
  // podmienić go własnym subjectId. task_id identyfikuje kontekst, ale NIE dowodzi,
  // że przechwycona treść rzeczywiście dotyczy tego podmiotu.
  if (jobMatches) subjectId = job.subjectId;
  if (!subjectId) subjectId = matchSubject(matchText, subjects);
  if (!subjectId) { sendResponse({ ok: false, reason: "no_subject_match" }); return; }
  const expected = jobMatches ? job.subject : subjects.find((it) => Number(it.subject_id) === Number(subjectId));
  if (!captureMatchesSubject(matchText, captureItems, expected)) {
    if (jobMatches) job.errors.push("wynik nie zawiera identyfikatora ani zgodnej nazwy podmiotu");
    sendResponse({ ok: false, reason: "empty_or_not_matching_subject" }); return;
  }
  const query = jobMatches ? job.query : null;
  const taskId = jobMatches ? job.taskId : null;
  const claimToken = jobMatches ? job.claimToken : null;
  const res = await ingest(source, subjectId, { text: matchText, url: msg.url, items: captureItems }, { trustedMatch: false, query, taskId, claimToken });
  if (jobMatches && res && res.ok) job.captures++;
  if (jobMatches && res && !res.ok) job.errors.push(res.reason || res.error || "odrzucono");
  sendResponse(res);
}

async function handleJobDone(source, msg, sender, sendResponse) {
  const tabId = sender.tab && sender.tab.id;
  const job = tabId != null ? jobsByTab[tabId] : null;
  // KRZ działa w wielu iframe'ach: wynik może zamknąć wyłącznie dzierżawiona ramka
  // formularza. Wyjątek to top-frame watchdog (frameId=0), który zgłasza tylko błąd
  // tuż przed timeoutem. Stara/ukryta ramka nie może już wygrać wyścigu jobDone.
  const allowed = source.key !== SOURCES.KRZ.key
    || (job && senderOwnsKrzWorker(job, sender))
    || (job && sender.frameId === 0 && !!msg.error && !msg.noResults);
  if (job && job.source === source.key && !job.done && allowed) {
    job.done = true;
    if (msg.noResults) job.noResults = true;
    if (msg.error) job.errors.push(msg.error);
    if (job.timer) clearTimeout(job.timer);
    if (job.resolve) job.resolve({
      captured: job.captures,
      noResults: !!msg.noResults,
      error: msg.error || undefined,
      pageText: msg.pageText || undefined,
    });
  }
  sendResponse({ ok: true });
}

async function handleTrustedKrzTabClick(msg, sender, sendResponse) {
  const tabId = sender.tab && sender.tab.id;
  const job = tabId != null ? jobsByTab[tabId] : null;
  if (!job || job.source !== SOURCES.KRZ.key || job.done || !senderOwnsKrzWorker(job, sender)) {
    sendResponse({ ok: false, error: "Brak aktywnego zadania KRZ dla tej karty." });
    return;
  }
  sendResponse(await trustedKrzTabClick(tabId, String(msg.kind || "")));
}

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  (async () => {
    try {
      if (msg.type === "krzReady") { await handleReady(SOURCES.KRZ, sender, sendResponse); return; }
      if (msg.type === "msigReady") { await handleReady(SOURCES.MSIG, sender, sendResponse); return; }
      if (msg.type === "krzCapture") { await handleCapture(SOURCES.KRZ, msg, sender, sendResponse); return; }
      if (msg.type === "msigCapture") { await handleCapture(SOURCES.MSIG, msg, sender, sendResponse); return; }
      if (msg.type === "krzJobDone") { await handleJobDone(SOURCES.KRZ, msg, sender, sendResponse); return; }
      if (msg.type === "msigJobDone") { await handleJobDone(SOURCES.MSIG, msg, sender, sendResponse); return; }
      if (msg.type === "krzClaimSearchFrame") { await handleClaimKrzSearchFrame(sender, sendResponse); return; }
      if (msg.type === "krzTrustedTabClick") { await handleTrustedKrzTabClick(msg, sender, sendResponse); return; }
      if (msg.type === "runNow") { sendResponse(await runAll()); return; }
      if (msg.type === "ping") { sendResponse(await ping()); return; }
      if (msg.type === "getState") {
        const cfg = await getConfig();
        const { lastRun } = await chrome.storage.local.get("lastRun");
        sendResponse({ cfg, lastRun: lastRun || null });
        return;
      }
      if (msg.type === "saveConfig") {
        await chrome.storage.local.set(msg.config);
        await rescheduleAlarm();
        sendResponse({ ok: true });
        return;
      }
      sendResponse({ ok: false, error: "unknown message" });
    } catch (e) {
      sendResponse({ ok: false, error: String(e) });
    }
  })();
  return true; // async response
});
