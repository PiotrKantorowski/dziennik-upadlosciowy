// Asystent KRZ — content script. Działa na stronach portalu KRZ w realnej sesji
// użytkownika. Automatyczny przebieg jest dostrojony do aktualnego portalu KRZ:
// start -> Wyszukiwanie podmiotów i przeglądanie postępowań -> właściwa zakładka
// -> właściwe pole identyfikatora/nazwy -> wynik -> rozwinięta tabela postępowań.

(() => {
  "use strict";
  const LOG = (...a) => console.log("[DUiR/KRZ]", ...a);
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  // Druga warstwa ochrony startu. Nawet jeśli komunikat ramki wyprzedzi chwilowo
  // odtworzenie stanu workera, ramka ponowi krzReady zamiast bezpowrotnie dostać null.
  const READY_RETRY_WINDOW_MS = 8000;
  const READY_RETRY_INTERVAL_MS = 300;
  let JOB_RUN_STARTED = false;
  const SIG_RE = /\b(?:[A-Z]{2}\d[A-Z]|[A-Z]{2}\d{2})\/[A-Za-zĄĆĘŁŃÓŚŻŹąćęłńóśżź]{1,8}(?:-[A-Za-zĄĆĘŁŃÓŚŻŹąćęłńóśżź]{1,8})?\/\d{1,7}\/\d{2,4}\b/u;
  // Data w formacie KRZ (05.06.2025 / 2025-06-05). Odróżnia WIERSZ DANYCH
  // postępowania od samego nagłówka kolumn (nagłówek ma etykiety, nie ma daty).
  const DATE_RE = /(?<!\d)(?:\d{1,2}[.\-/]\d{1,2}[.\-/]\d{4}|\d{4}-\d{2}-\d{2})(?!\d)/;
  // Hash modułu wyszukiwania. Segment WERSJI aplikacji (np. "1.9") wyciągamy z
  // bieżącego adresu strony głównej — zweryfikowane na żywym portalu 2026-07-11:
  // stała wersja "current" NIE istnieje i nawigacja nią wyzwalała modal
  // "Czy na pewno wyjść z aplikacji?" zamiast otwierać moduł.
  function subjectSearchHash() {
    const m = (location.hash || "").match(/#!\/application\/KRZPortalPUB\/([^/]+)\//);
    const ver = m ? m[1] : "1.9";
    return "#!/application/KRZPortalPUB/" + ver + "/KrzRejPubGui.WyszukiwaniePodmiotow?params=JTdCJTdE&seq=1";
  }

  // Czy ta instancja skryptu działa w ramce głównej? Moduły portalu KRZ renderują
  // się w OSOBNYCH IFRAME'ACH niedostępnych z góry (contentDocument === null),
  // więc skrypt wstrzykuje się do wszystkich ramek (manifest: all_frames) i dzieli
  // role: top nawiguje, ramka z formularzem wyszukuje.
  const IS_TOP = (() => { try { return window === window.top; } catch (_) { return true; } })();

  // Powłoka KRZ utrzymuje równocześnie kilka buforowanych iframe'ów tego samego
  // modułu. Content-script wewnątrz ukrytej ramki sam nie widzi, że jej rodzic ma
  // display:none, więc wcześniej również rozpoczynał zadanie i potrafił jako
  // pierwszy zgłosić błąd. Top-frame wskazuje teraz wyłącznie aktywne drzewo ramek.
  const ACTIVE_FRAME_MESSAGE = "duir-krz-active-frame-v2";
  // Sygnał aktywności jest dzierżawą, a nie trwałą flagą. Powłoka KRZ buforuje
  // stare iframe'y; po zmianie widoku dawny kod zostawiał je aktywne na zawsze,
  // więc kilka ramek wykonywało to samo zadanie i pierwsza odpowiedź wygrywała.
  let ACTIVE_FRAME_SEEN_AT = IS_TOP ? Number.POSITIVE_INFINITY : 0;
  const isActivePortalFrame = () => IS_TOP || (Date.now() - ACTIVE_FRAME_SEEN_AT < 1600);

  function relayActiveFrameSignal() {
    for (const frame of document.querySelectorAll("iframe")) {
      try { frame.contentWindow && frame.contentWindow.postMessage({ type: ACTIVE_FRAME_MESSAGE }, "*"); } catch (_) {}
    }
  }

  if (!IS_TOP) {
    window.addEventListener("message", (event) => {
      if (event.source !== window.parent || !event.data || event.data.type !== ACTIVE_FRAME_MESSAGE) return;
      ACTIVE_FRAME_SEEN_AT = Date.now();
      relayActiveFrameSignal();
    });
  }

  // Limity drążenia — dobrane tak, aby zmieścić się w 120s timeout karty (background.js).
  const MAX_PROCEEDINGS = 6;            // maks. liczba postępowań przetwarzanych na podmiot
  const MAX_NOTICES_PER_PROCEEDING = 4; // maks. liczba obwieszczeń otwieranych na postępowanie
  // Twardy budżet DRĄŻENIA treści (wchodzenia w obwieszczenia/postanowienia).
  // Watchdog ramki głównej pada po 105 s (WATCHDOG_MS), a budżet karty w background
  // to 120 s. Drążenie MUSI się zmieścić z zapasem: po tym progu (liczonym od startu
  // zadania) przestajemy otwierać kolejne treści i oddajemy to, co już zebrano —
  // metadane postępowań (sygnatura, rodzaj, daty) i tak są już przechwycone.
  // Dzięki temu ramka formularza ZAWSZE zgłosi wynik przed watchdogiem, zamiast go
  // przekroczyć (to był błąd „ramka formularza nie zgłosiła wyniku" u podmiotów,
  // które jako jedyne miały realny wpis w KRZ i wymagały wejścia w treść).
  const DRILL_DEADLINE_MS = 80000;
  // Ustawiane na starcie runJob w KAŻDEJ ramce (top i formularza) — wspólny zegar
  // odniesienia do budżetu drążenia, spójny z watchdogiem ramki głównej.
  let JOB_STARTED_AT = 0;

  function visible(el) {
    if (!el) return false;
    const r = el.getBoundingClientRect();
    const s = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && s.visibility !== "hidden" && s.display !== "none";
  }

  function norm(text) {
    return (text || "")
      .normalize("NFD").replace(/[\u0300-\u036f]/g, "")
      // \u0142/\u0141 NIE maj\u0105 dekompozycji NFD (to osobna litera, nie \u201el + znak"), wi\u0119c NFD ich
      // nie sk\u0142ada \u2014 trzeba jawnie. Bez tego wzorce z \u201edzialalnosc"/\u201ezlozyl" nie \u0142apa\u0142y
      // etykiet KRZ z \u201e\u0142" (np. zak\u0142adka \u201e\u2026prowadz\u0105ca dzia\u0142alno\u015b\u0107"), co k\u0142ad\u0142o JDG.
      .toLowerCase().replace(/\u0142/g, "l").replace(/\s+/g, " ").trim();
  }

  function textOf(el) {
    return ((el && (el.innerText || el.textContent || el.value)) || "").replace(/\s+/g, " ").trim();
  }

  function byText(selector, re) {
    return [...document.querySelectorAll(selector)].filter((el) => visible(el) && re.test(textOf(el)));
  }

  function setNativeValue(el, value) {
    try {
      const proto = Object.getPrototypeOf(el);
      const desc = Object.getOwnPropertyDescriptor(proto, "value");
      if (desc && desc.set) desc.set.call(el, value); else el.value = value;
    } catch (_) { el.value = value; }
    el.dispatchEvent(new Event("input", { bubbles: true }));
    el.dispatchEvent(new Event("change", { bubbles: true }));
    el.dispatchEvent(new KeyboardEvent("keyup", { bubbles: true, key: "0" }));
    el.blur && el.blur();
  }

  function labelText(el) {
    if (!el) return "";
    const parts = [];
    if (el.id) {
      try {
        const lab = document.querySelector(`label[for="${CSS.escape(el.id)}"]`);
        if (lab) parts.push(textOf(lab));
      } catch (_) {}
    }
    const wrap = el.closest("label");
    if (wrap) parts.push(textOf(wrap));
    // Tylko najbliższy kontener pola. `closest("div, form, section")` potrafił
    // zwrócić cały formularz wraz z etykietami WSZYSTKICH zakładek, przez co pole
    // osoby wyglądało jak pole spółki (i odwrotnie).
    const field = el.closest("p-floatlabel, .p-float-label, .p-field, .form-group, .field, td, th");
    if (field) parts.push(textOf(field).slice(0, 180));
    const prev = el.previousElementSibling;
    if (prev && /^(LABEL|SPAN|DIV)$/.test(prev.tagName)) parts.push(textOf(prev).slice(0, 100));
    parts.push(el.placeholder || "", el.getAttribute("aria-label") || "", el.getAttribute("formcontrolname") || "", el.getAttribute("name") || "", el.id || "");
    return parts.filter(Boolean).join(" ");
  }

  function waitFor(predicate, timeoutMs = 15000, intervalMs = 350) {
    return new Promise((resolve) => {
      const started = Date.now();
      const tick = () => {
        let value = null;
        try { value = predicate(); } catch (_) { value = null; }
        if (value) return resolve(value);
        if (Date.now() - started >= timeoutMs) return resolve(null);
        setTimeout(tick, intervalMs);
      };
      tick();
    });
  }

  function clickElement(el) {
    if (!el) return false;
    try { el.scrollIntoView({ block: "center", inline: "center" }); } catch (_) {}
    // Jedna akcja użytkownika = dokładnie jeden event `click`. Poprzednio najpierw
    // wywoływaliśmy el.click(), a potem wysyłaliśmy drugi MouseEvent("click"), co
    // w PrimeNG potrafiło przełączyć zakładkę dwukrotnie albo uruchomić dwa szukania.
    try {
      for (const type of ["pointerdown", "mousedown", "pointerup", "mouseup"]) {
        el.dispatchEvent(new MouseEvent(type, { bubbles: true, cancelable: true, view: window }));
      }
      el.click();
      return true;
    } catch (_) { return false; }
  }

  // Najmniejszy widoczny element, którego tekst pasuje do wzorca — kandydat na
  // etykietę kafla. Kontenery (długi tekst) odpadają.
  function deepestByText(re, maxLen = 260) {
    let best = null;
    for (const el of document.querySelectorAll("main *, body *")) {
      if (!visible(el)) continue;
      const t = textOf(el);
      if (!t || t.length > maxLen) continue;
      if (!re.test(norm(t))) continue;
      if (!best || t.length < textOf(best).length) best = el;
    }
    return best;
  }

  function serviceTile() {
    const exact = /wyszukiwanie podmiotow i przegladanie postepowan|wyszukiwanie podmiotów i przeglądanie postępowań/i;
    const broad = /wyszukiwanie podmiot(ow|ów)/i;
    const selectorHit = document.querySelector("a[href*='WyszukiwaniePodmiotow' i], [routerlink*='WyszukiwaniePodmiotow' i], [ng-reflect-router-link*='WyszukiwaniePodmiotow' i]");
    if (selectorHit && visible(selectorHit)) return selectorHit;
    const clickable = [...document.querySelectorAll("a, button, [role=button], [role=link]")].filter(visible);
    // 1) Klikalny element z PEŁNĄ etykietą kafla.
    const exactClickable = clickable.find((el) => exact.test(textOf(el)));
    if (exactClickable) return exactClickable;
    // 2) Kafel jest zwykłym <div> bez roli (zweryfikowane na żywym portalu):
    //    najgłębszy element z pełną etykietą + wspinaczka do klikalnego przodka.
    //    To MUSI iść przed dopasowaniem skróconej nazwy, bo menu boczne ma martwy
    //    link "Wyszukiwanie podmiotów", którego kliknięcie nic nie robi.
    const exactLabel = deepestByText(exact);
    if (exactLabel) return exactLabel.closest("a, button, [role=button], [role=link], [tabindex], [routerlink]") || exactLabel;
    // 3) Dopiero na końcu skrócona nazwa (menu/kafle alternatywne).
    const broadClickable = clickable.find((el) => broad.test(textOf(el)) && !/wspolnik|wspólnik|skladnik|składnik|doradc|obwieszcz/i.test(textOf(el)));
    if (broadClickable) return broadClickable;
    const broadLabel = deepestByText(broad);
    if (broadLabel) return broadLabel.closest("a, button, [role=button], [role=link], [tabindex], [routerlink]") || broadLabel;
    return null;
  }

  // Diagnostyka nawigacji: co w ogóle da się kliknąć na stronie (do raportu błędu).
  function navCandidates() {
    const texts = [...document.querySelectorAll("a, button, [role=button], [role=link]")]
      .filter(visible).map((el) => textOf(el)).filter((t) => t && t.length <= 120);
    const uniq = [...new Set(texts)];
    const hits = uniq.filter((t) => /wyszuk|podmiot|post[eę]powan|rejestr|us[łl]ug/i.test(t));
    return (hits.length ? hits : uniq).slice(0, 20).join(" | ");
  }

  function onSubjectSearchPage() {
    // Uwaga: nagłówek "WYSZUKIWANIE PODMIOTÓW" renderuje ramka GŁÓWNA, a formularz
    // żyje w osobnym iframe — instancja skryptu w ramce formularza widzi tylko
    // treść formularza (kryteria wyszukiwania, pola), bez nagłówka modułu.
    const body = norm(document.body && document.body.innerText);
    return /identyfikator/.test(body)
      && /(nazwa podmiotu|kryteriach wyszukiwania|wyszukiwanie podmiotow)/.test(body);
  }

  // Portal KRZ przy przejściu między modułami pokazuje modal "POTWIERDZENIE:
  // Czy na pewno wyjść z aplikacji Portal publiczny KRZ?" z przyciskami WYJDŹ/ANULUJ.
  // Automat musi kliknąć WYJDŹ (jak człowiek), inaczej stoi przed modalem do timeoutu —
  // to była PRZYCZYNA błędu "Nie udało się przejść do modułu".
  function confirmExitDialog() {
    const body = norm((document.body && document.body.innerText) || "");
    if (!/czy na pewno wyjsc z aplikacji/.test(body)) return false;
    const btn = [...document.querySelectorAll("button, a, [role=button]")]
      .filter(visible)
      .find((el) => /^wyjd[źz]$/i.test(textOf(el).trim()));
    if (btn) { LOG("Potwierdzam wyjście z aplikacji (modal KRZ)."); clickElement(btn); return true; }
    return false;
  }

  // Zamyka nakładki, które przesłaniają aplikację i blokują klikanie kafla:
  // banery cookies/RODO, komunikaty powitalne, dialogi "rozumiem/akceptuję".
  function dismissOverlays() {
    const re = /^\s*(akceptuj[eę]?( wszystkie)?|zgadzam si[eę]|rozumiem|zamknij|ok|przejd[źz] do (serwisu|portalu)|kontynuuj)\s*$/i;
    let clicked = 0;
    for (const el of [...document.querySelectorAll("button, a, [role=button]")].filter(visible)) {
      if (re.test(textOf(el))) { clickElement(el); clicked++; if (clicked >= 3) break; }
    }
    return clicked;
  }

  // Ślad nawigacji do diagnostyki: każdy krok (klik, hash, modal) zostawia wpis,
  // a przy porażce całość trafia do raw_json błędu na serwerze.
  let NAV_TRACE = [];

  // Nawigacja ramki GŁÓWNEJ do modułu wyszukiwania. Top nie widzi formularza
  // (żyje w iframe innej instancji), ale widzi WŁASNY adres — sukcesem jest
  // hash zawierający "WyszukiwaniePodmiotow". Samo wyszukiwanie wykona instancja
  // skryptu w ramce formularza.
  async function topNavigateToModule() {
    NAV_TRACE = [];
    const t0 = Date.now();
    const step = (msg) => { NAV_TRACE.push(Math.round((Date.now() - t0) / 1000) + "s: " + msg); LOG(msg); };
    // Portal KRZ (SPA) potrafi wstawać KILKANAŚCIE sekund — budżet karty to 120 s.
    await waitFor(() => document.body && document.body.innerText.trim().length > 200, 30000, 500);
    dismissOverlays();
    step("start, url=…" + location.href.slice(-70));
    let modalConfirmed = false;
    const urlReady = () => {
      if (confirmExitDialog()) { modalConfirmed = true; step("potwierdzono modal WYJDŹ"); }
      return location.href.includes("WyszukiwaniePodmiotow");
    };
    if (urlReady()) { step("już w module"); return true; }
    const base = location.origin + location.pathname.replace(/\/+$/, "/");
    let tile = serviceTile() || await waitFor(() => serviceTile(), 12000, 500);
    if (tile) {
      step("klikam: \"" + textOf(tile).slice(0, 60) + "\" <" + tile.tagName.toLowerCase() + ">");
      clickElement(tile);
      if (await waitFor(urlReady, 15000, 500)) { step("moduł otwarty po kliknięciu"); return true; }
      step("po kliknięciu wciąż url=…" + location.href.slice(-70));
      // Modal mógł przechwycić nawigację z kliknięcia — po potwierdzeniu klikamy raz jeszcze.
      if (modalConfirmed) {
        tile = serviceTile();
        if (tile) {
          step("klikam ponownie po WYJDŹ");
          clickElement(tile);
          if (await waitFor(urlReady, 12000, 500)) { step("moduł otwarty po 2. kliknięciu"); return true; }
        }
      }
    } else {
      step("nie znaleziono kafla/linku modułu");
    }
    // Fallback hashem (z wersją aplikacji z bieżącego adresu). Po potwierdzeniu
    // WYJDŹ portal potrafi wrócić na stronę główną PORZUCAJĄC docelowy hash —
    // wtedy nawigujemy ponownie, już bez modalu po drodze.
    if (!location.href.includes("WyszukiwaniePodmiotow")) {
      step("fallback: ustawiam hash modułu");
      modalConfirmed = false;
      location.href = base + subjectSearchHash();
      if (await waitFor(urlReady, 20000, 500)) { step("moduł otwarty hashem"); return true; }
      if (modalConfirmed) {
        step("po WYJDŹ ponawiam hash modułu");
        location.href = base + subjectSearchHash();
        if (await waitFor(urlReady, 15000, 500)) { step("moduł otwarty hashem (2. próba)"); return true; }
      }
    }
    step("PORAŻKA, url=…" + location.href.slice(-70));
    return location.href.includes("WyszukiwaniePodmiotow");
  }

  function tabLabelsFor(kind) {
    if (kind === "company") return [/podmiot niebedacy osoba fizyczna/i, /podmiot niebędący osobą fizyczną/i];
    if (kind === "business_person") return [/osoba fizyczna prowadzaca dzialalnosc/i, /osoba fizyczna prowadząca działalność/i];
    if (kind === "natural_person") return [/osoba fizyczna nieprowadzaca/i, /osoba fizyczna nieprowadząca/i, /^osoba fizyczna$/i];
    return [];
  }

  function searchTab(kind) {
    const labels = tabLabelsFor(kind);
    return [...document.querySelectorAll("a[role=tab], [role=tab]")]
      .filter(visible)
      .find((el) => labels.some((re) => re.test(norm(textOf(el))))) || null;
  }

  // Sygnatura formularza sprzed przełączania — punkt odniesienia dla wykrycia, że
  // zakładka realnie się zmieniła (ustawiana w selectSearchTab przed klikaniem).
  let INITIAL_FORM_SIG = null;

  function searchTabReady(kind) {
    const sig = formSignature();
    if (!sig || !findAnySearchInput()) return false;
    const tab = searchTab(kind);
    if (!tab) return false;
    const panelId = tab.getAttribute("aria-controls") || "";
    const panel = panelId ? document.getElementById(panelId) : null;
    const selected = tab.getAttribute("aria-selected") === "true"
      || /(^|\s)(active|p-highlight|p-tabview-selected)(\s|$)/i.test(tab.className || "")
      || (!!panel && visible(panel));
    // Typ podmiotu jest wiążący. Nie uznajemy formularza za właściwy wyłącznie na
    // podstawie podobnych pól — aktywna musi być też dokładnie żądana zakładka.
    if (!selected) return false;
    if (kind === "company") return /nazwa podmiotu/.test(sig) && /identyfikator/.test(sig);
    // Zakładki OSÓB: formularz nie może już być formularzem spółek...
    if (/nazwa podmiotu/.test(sig)) return false;
    // ...a poza tym gotowość potwierdza charakterystyczne pole albo realna zmiana
    // układu pól. Samo `changed` nigdy nie wystarcza bez potwierdzenia aktywnej karty.
    const changed = INITIAL_FORM_SIG !== null && sig !== INITIAL_FORM_SIG;
    if (kind === "business_person") return /firma|nip|regon|identyfikator/.test(sig) || changed;
    if (kind === "natural_person") return /(imie|imię|nazwisko|pesel)/.test(sig) || changed;
    return selected;
  }

  let LAST_TAB_SWITCH_ERROR = "";

  // searchTabReady jest oparte na widocznych polach + zmianie układu i działa dla
  // WSZYSTKICH zakładek — osobny „relaxed" wariant nie jest już potrzebny (delegujemy).
  function searchTabReadyRelaxed(kind) {
    return searchTabReady(kind);
  }

  // Stan zakładki do diagnostyki — przy porażce od razu widać w panelu, czy
  // zakładka istnieje w tej ramce, czy jest zaznaczona i jakie pola widać.
  function tabSwitchDiagnostics(kind) {
    const tab = searchTab(kind);
    if (!tab) {
      const allTabs = [...document.querySelectorAll("[role=tab]")].filter(visible).map((el) => textOf(el).slice(0, 45));
      return "zakładka nieobecna w tej ramce; role=tab: [" + (allTabs.join(" | ").slice(0, 220) || "brak") + "]";
    }
    const panelId = tab.getAttribute("aria-controls") || "";
    const panel = panelId ? document.getElementById(panelId) : null;
    return "aria-selected=" + (tab.getAttribute("aria-selected") || "brak")
      + "; panel=" + (panelId ? (panel ? (visible(panel) ? "widoczny" : "ukryty") : "nie znaleziono") : "bez aria-controls")
      + "; pola=[" + formSignature().slice(0, 180) + "]";
  }

  async function selectSearchTab(kind) {
    const labels = tabLabelsFor(kind);
    if (!labels.length) return true;
    // Zapamiętaj układ pól SPRZED przełączania — searchTabReady użyje go do wykrycia,
    // że zakładka realnie się zmieniła (kluczowe dla JDG/osób, gdy aria nie drga).
    INITIAL_FORM_SIG = formSignature();
    if (searchTabReady(kind)) return true;
    const ready = () => searchTabReady(kind);
    // PrimeNG przebudowuje elementy tablist po kliknięciu. Dlatego w każdej
    // iteracji odnajdujemy NOWY element, wysyłamy dokładnie jeden click() (stary
    // helper wysyłał dwa kliknięcia) i potwierdzamy jednocześnie aria-selected,
    // aktywny panel oraz charakterystyczne pola właściwego formularza.
    for (let attempt = 0; attempt < 20; attempt++) {
      const tab = searchTab(kind);
      if (!tab) { await sleep(400); continue; }
      try { tab.scrollIntoView({ block: "center", inline: "center" }); tab.click(); } catch (_) {}
      if (await waitFor(ready, 700, 100)) return true;
    }
    // Ostatnia warstwa: rzeczywiste zdarzenie wejściowe wysłane przez Chrome
    // DevTools Protocol do cross-origin iframe (klik myszą, a przy braku
    // potwierdzenia fokus + Enter — niezależny od układu współrzędnych OOPIF).
    const trusted = await send("krzTrustedTabClick", { kind });
    if (trusted && trusted.ok && await waitFor(ready, 8000, 150)) return true;
    LAST_TAB_SWITCH_ERROR = ((trusted && trusted.error) || "Natywne kliknięcie nie przełączyło formularza KRZ.")
      + " [CDP: " + ((trusted && (trusted.method || trusted.error)) || "brak odpowiedzi")
      + (trusted && trusted.ok && !trusted.verified ? ", bez potwierdzenia aria-selected" : "") + "]"
      + " [Stan: " + tabSwitchDiagnostics(kind) + "]";
    return false;
  }

  function formSignature() {
    return [...document.querySelectorAll("input, textarea")].filter(visible).map((el) => norm(labelText(el))).join("|");
  }

  function findAnySearchInput() {
    return [...document.querySelectorAll("input, textarea")].find((i) => visible(i) && ["text", "search", "number", "tel", ""].includes((i.getAttribute("type") || "").toLowerCase())) || null;
  }

  function findInputForJob(job) {
    const inputs = [...document.querySelectorAll("input, textarea")]
      .filter((i) => visible(i) && ["text", "search", "number", "tel", ""].includes((i.getAttribute("type") || "").toLowerCase()))
      .map((el, idx) => ({ el, idx, lab: norm(labelText(el)) }));
    const queryKey = norm(job.queryKey || job.query_key || "");
    const kind = norm(job.searchKind || job.search_kind || "");
    const isIdentifier = ["krs", "nip", "regon", "pesel"].includes(queryKey) || /^\d{6,}$/.test(String(job.query || ""));
    if (isIdentifier) {
      const ident = inputs.find((x) => /identyfikator|krs|nip|regon|pesel/.test(x.lab) && !/nazwa podmiotu|firma|imie|imię|nazwisko/.test(x.lab));
      if (ident) return ident.el;
      // W zakładce osób fizycznych nieprowadzących działalności jest często tylko jeden input.
      if (kind === "natural_person" && inputs.length === 1) return inputs[0].el;
      if (inputs.length >= 2) return inputs[1].el; // w formularzach KRZ drugi input to zwykle identyfikator
    }
    const name = inputs.find((x) => /nazwa podmiotu|firma|nazwa|podmiot/.test(x.lab) && !/identyfikator|pesel|nip|krs|regon/.test(x.lab));
    if (name) return name.el;
    return inputs[0] ? inputs[0].el : null;
  }

  function findSearchButton() {
    const exact = byText("button, a, input[type=submit]", /^\s*(szukaj|wyszukaj)\s*$/i)[0];
    if (exact) return exact;
    const candidates = [...document.querySelectorAll("button, a, input[type=submit]")].filter(visible);
    return candidates.find((el) => /szukaj|wyszukaj/i.test(textOf(el) || el.value || el.getAttribute("aria-label") || "")) || null;
  }

  async function submitSearch(job) {
    const selected = await selectSearchTab(job.searchKind || job.search_kind);
    if (!selected) return { ok: false, error: "Nie udało się wybrać właściwej zakładki wyszukiwania KRZ. " + LAST_TAB_SWITCH_ERROR };
    await sleep(400);
    const input = findInputForJob(job);
    if (!input) return { ok: false, error: "Nie znaleziono właściwego pola wyszukiwania KRZ." };
    // Usuń wynik poprzedniego zadania ZANIM wpiszemy nowe kryterium. Inaczej stary
    // komunikat „brak wyników” albo stara tabela spełniały waitFor natychmiast,
    // zanim portal w ogóle odpowiedział na bieżące zapytanie.
    const staleText = visiblePageText(8000);
    if (looksLikeNoResultsText(staleText) || /liczba podmiotow|liczba podmiotów|wyniki wyszukiwania/.test(norm(staleText)) || looksLikeAnnouncementText(staleText)) {
      const clear = byText("button, a, [role=button]", /^\s*wyczy[śs][ćc]\s*$/i)[0];
      if (clear) {
        clickElement(clear);
        await waitFor(() => {
          const t = visiblePageText(8000);
          return !looksLikeNoResultsText(t) && !/liczba podmiotow|liczba podmiotów|wyniki wyszukiwania/.test(norm(t));
        }, 5000, 150);
      }
    }
    setNativeValue(input, job.query);
    await sleep(400);
    const expectedQuery = String(job.query || "").replace(/\s+/g, " ").trim();
    const valueMatches = () => {
      const actual = String(input.value || "").replace(/\s+/g, " ").trim();
      if (/^\d{6,}$/.test(expectedQuery)) return actual.replace(/\D/g, "") === expectedQuery.replace(/\D/g, "");
      return norm(actual) === norm(expectedQuery);
    };
    if (!valueMatches()) return { ok: false, error: "Portal KRZ nie przyjął bieżącego kryterium wyszukiwania." };
    const btn = findSearchButton();
    const submittedAt = Date.now();
    if (btn) clickElement(btn);
    else if (input.form && input.form.requestSubmit) input.form.requestSubmit();
    else input.dispatchEvent(new KeyboardEvent("keydown", { bubbles: true, key: "Enter" }));
    const loaded = await waitFor(() => {
      // Minimalne okno na reakcję portalu + korelacja z wartością nadal obecną
      // we właściwym polu. Chroni przed odczytaniem pozostałości poprzedniego joba.
      if (Date.now() - submittedAt < 650 || !valueMatches() || !searchTabReady(job.searchKind || job.search_kind)) return null;
      const txt = visiblePageText(8000);
      if (looksLikeNoResultsText(txt)) return "no_results";
      if (/liczba podmiotow|liczba podmiotów|wyniki wyszukiwania/.test(norm(txt))) return "results";
      if (looksLikeAnnouncementText(txt)) return "announcement";
      return null;
    }, 20000);
    return { ok: true, loaded };
  }

  function expandableCandidate() {
    const attr = "button[aria-expanded='false'], a[aria-expanded='false'], [role=button][aria-expanded='false'], button[title*='Rozwiń' i], button[aria-label*='Rozwiń' i], button[title*='rozwin' i], button[aria-label*='rozwin' i]";
    const byAttr = [...document.querySelectorAll(attr)].filter(visible)[0];
    if (byAttr) return byAttr;
    const textual = byText("button, a", /rozwiń|rozwin|szczeg|pokaż|pokaz|zobacz|otwórz|otworz|więcej|wiecej/i)[0];
    if (textual) return textual;
    const tableButtons = [...document.querySelectorAll("table button, table a, tr button, tr a")].filter((el) => {
      if (!visible(el)) return false;
      const t = norm(textOf(el) + " " + (el.getAttribute("title") || "") + " " + (el.getAttribute("aria-label") || ""));
      if (/szukaj|wyczysc|wyczyść/.test(t)) return false;
      return true;
    });
    return tableButtons[tableButtons.length - 1] || null;
  }

  async function openResultDetailsIfNeeded() {
    let text = extractAnnouncementText();
    if (looksLikeAnnouncementText(text)) return true;
    const opener = expandableCandidate();
    if (!opener) return false;
    LOG("Rozwijam pierwszy wynik KRZ.");
    clickElement(opener);
    await sleep(1200);
    await waitFor(() => looksLikeAnnouncementText(extractAnnouncementText()), 6000);
    return looksLikeAnnouncementText(extractAnnouncementText());
  }

  // Najlepszy kandydat na tekst obwieszczenia/wyniku KRZ. Dla osób fizycznych
  // portal pokazuje często tabelę „Postępowania restrukturyzacyjne” z sygnaturą,
  // datą rejestracji, datą zakończenia i statusem, czasem po rozwinięciu wiersza.
  function extractAnnouncementText() {
    const kw = /(obwieszcz|upad|restruktur|syndyk|sygn|postępowan|postepowan|wierzytel|dłużnik|dluznik|data rejestracji|data zakończenia|status)/i;
    const rowKw = /(postępowania restrukturyzacyjne|postepowania restrukturyzacyjne|postępowania upadłościowe|postepowania upadlosciowe|data rejestracji|data zakończenia|data zakonczenia|rodzaj postępowania|rodzaj postepowania)/i;
    const containers = ["article", "section", "main", ".content", ".panel", ".card", "table", "tbody", "tr", "div"].join(",");
    const rows = [...document.querySelectorAll(containers)]
      .filter((el) => visible(el))
      .map((el) => ({ el, t: (el.innerText || "").trim() }))
      .filter((x) => x.t.length > 50 && (SIG_RE.test(x.t) || rowKw.test(x.t)));
    if (rows.length) {
      rows.sort((a, b) => {
        const as = (SIG_RE.test(a.t) ? 2000 : 0) + (rowKw.test(a.t) ? 1500 : 0) + Math.min(a.t.length, 7000);
        const bs = (SIG_RE.test(b.t) ? 2000 : 0) + (rowKw.test(b.t) ? 1500 : 0) + Math.min(b.t.length, 7000);
        return bs - as;
      });
      let el = rows[0].el;
      for (let i = 0; i < 6 && el && el.parentElement; i++) {
        const pt = (el.parentElement.innerText || "").trim();
        if (pt.length > rows[0].t.length && pt.length < 14000 && (SIG_RE.test(pt) || rowKw.test(pt))) el = el.parentElement;
      }
      const resultText = (el && el.innerText ? el.innerText : rows[0].t).trim();
      if (resultText) return resultText.slice(0, 12000);
    }
    const candidates = [...document.querySelectorAll("article, section, main, .content, .panel, .card, .obwieszczenie, div")]
      .filter((el) => visible(el))
      .map((el) => ({ el, t: (el.innerText || "").trim() }))
      .filter((x) => x.t.length > 120 && (SIG_RE.test(x.t) || kw.test(x.t)));
    candidates.sort((a, b) => {
      const as = (SIG_RE.test(a.t) ? 1000 : 0) + Math.min(a.t.length, 6000);
      const bs = (SIG_RE.test(b.t) ? 1000 : 0) + Math.min(b.t.length, 6000);
      return bs - as;
    });
    const best = candidates[0] ? candidates[0].t : (document.body.innerText || "").trim();
    return best.slice(0, 12000);
  }

  // ==== Drążenie wielu postępowań i obwieszczeń (best-effort dla SPA KRZ) ====

  // Wyciąga i normalizuje pierwszą sensowną datę z tekstu do formatu RRRR-MM-DD.
  // Obsługuje "2025-01-31", "31.01.2025", "31-01-2025", "31 stycznia 2025".
  function extractPublicationDate(text) {
    const t = text || "";
    let m = t.match(/\b(\d{4})-(\d{2})-(\d{2})\b/);
    if (m) return `${m[1]}-${m[2]}-${m[3]}`;
    m = t.match(/\b(\d{1,2})[.\-/](\d{1,2})[.\-/](\d{4})\b/);
    if (m) {
      const d = String(m[1]).padStart(2, "0");
      const mo = String(m[2]).padStart(2, "0");
      return `${m[3]}-${mo}-${d}`;
    }
    const months = {
      stycznia: "01", lutego: "02", marca: "03", kwietnia: "04", maja: "05", czerwca: "06",
      lipca: "07", sierpnia: "08", wrzesnia: "09", pazdziernika: "10", listopada: "11", grudnia: "12"
    };
    m = norm(t).match(/\b(\d{1,2})\s+(stycznia|lutego|marca|kwietnia|maja|czerwca|lipca|sierpnia|wrzesnia|pazdziernika|listopada|grudnia)\s+(\d{4})\b/);
    if (m && months[m[2]]) return `${m[3]}-${months[m[2]]}-${String(m[1]).padStart(2, "0")}`;
    return null;
  }

  // Próba rozpoznania typu postanowienia/obwieszczenia z treści (best-effort).
  function guessNoticeTitle(text) {
    const t = norm(text || "");
    if (/oglosz(enia|eniu|enie) upadlosci|postanowienie.*upadlosc/.test(t)) return "Postanowienie o ogłoszeniu upadłości";
    if (/otwarci(a|e|u) postepowania (restrukturyzacyjnego|sanacyjnego|ukladowego)|otwarci.*restrukturyzac/.test(t)) return "Postanowienie o otwarciu restrukturyzacji";
    if (/zatwierdz(enia|eniu) ukladu|uklad zostal zatwierdzony/.test(t)) return "Postanowienie o zatwierdzeniu układu";
    if (/umorzeni(a|e|u) postepowania|postepowanie.*umorzon/.test(t)) return "Postanowienie o umorzeniu postępowania";
    if (/zakonczeni(a|e|u) postepowania/.test(t)) return "Postanowienie o zakończeniu postępowania";
    if (/ogloszenie o.*plan(ie)? podzialu|plan podzialu/.test(t)) return "Obwieszczenie o planie podziału";
    if (/lista wierzytelnosci/.test(t)) return "Obwieszczenie o liście wierzytelności";
    if (/postanowieni/.test(t)) return "Postanowienie sądu";
    if (/obwieszcz/.test(t)) return "Obwieszczenie";
    return null;
  }

  // Czy tekst wygląda na TREŚĆ obwieszczenia/postanowienia (a nie tylko wiersz metadanych).
  function looksLikeNoticeContent(text) {
    const t = norm(text || "");
    if ((text || "").length < 120) return false;
    const sentence = /postanawia|postanowienie|sentencj|niniejszym|oglasza sie|ogłasza|w sprawie|akt sprawy/.test(t);
    const court = /\bsad\b|referendarz|sedzia|sedziego|syndyk|nadzorca|zarzadca/.test(t);
    const sig = SIG_RE.test(text || "");
    return (sentence && (court || sig)) || (sig && sentence);
  }

  // Zbiera wszystkie wiersze/pozycje postępowań na aktualnym widoku listy.
  // Defensywnie: wiersze tabeli zawierające sygnaturę albo słowa kluczowe postępowań.
  function collectProceedingRows() {
    const rowKw = /(postepowania restrukturyzacyjne|postepowania upadlosciowe|data rejestracji|data zakonczenia|rodzaj postepowania)/i;
    const rows = [...document.querySelectorAll("table tr, tbody tr, [role=row], .row")]
      .filter((el) => visible(el))
      .filter((el) => {
        const t = norm(textOf(el));
        return textOf(el).length > 20 && (SIG_RE.test(textOf(el)) || rowKw.test(t));
      })
      // odrzuć wiersze nagłówkowe (same etykiety kolumn, bez sygnatury i krótkie)
      .filter((el) => SIG_RE.test(textOf(el)) || textOf(el).length > 60)
      // ...oraz PRECYZYJNIE wiersz nagłówka tabeli: ma etykiety kolumn, ale nie ma
      // żadnej wartości (sygnatury ani daty). To on generował fałszywe zdarzenie
      // "KRZ: informacja o postępowaniu" z pustą treścią nagłówka.
      .filter((el) => {
        const t = textOf(el);
        const looksHeader = /(rodzaj postepowania|data rejestracji|data zakonczenia)/.test(norm(t))
          && !SIG_RE.test(t) && !DATE_RE.test(t);
        return !looksHeader;
      });
    // deduplikacja: wiersz zawarty w innym (zagnieżdżenie) — zostaw najbardziej wewnętrzny z sygnaturą
    const uniq = [];
    for (const el of rows) {
      if (uniq.some((u) => u !== el && u.contains(el))) continue;
      uniq.push(el);
    }
    return uniq.slice(0, MAX_PROCEEDINGS);
  }

  // Znajduje w obrębie wiersza/kontenera postępowania przyciski/linki otwierające obwieszczenia.
  function findNoticeOpenersIn(scope) {
    const root = scope || document;
    const re = /obwieszcz|dokument|szczeg|postanow|pokaz|pokaż|zobacz|otworz|otwórz|wyswietl|wyświetl/i;
    const clickable = [...root.querySelectorAll("button, a, [role=button], [role=link]")].filter((el) => visible(el));
    const notFile = (el) => !/szukaj|wyczysc|wyczyść|drukuj|pobierz plik|pobierz pdf|\.pdf/.test(norm(textOf(el) + " " + (el.getAttribute("title") || "") + " " + (el.getAttribute("aria-label") || "")));
    // 1) Klasyczne otwieracze: "obwieszczenie/szczegóły/postanowienie/pokaż...".
    const openers = clickable.filter(notFile).filter((el) => re.test(norm(textOf(el) + " " + (el.getAttribute("title") || "") + " " + (el.getAttribute("aria-label") || ""))));
    // 2) LINK SYGNATURY (np. "KR1S/GRz-nu/175/2025") — w wynikach KRZ to naturalny
    //    element wchodzący w szczegóły postępowania/treść postanowienia. Bez tego
    //    postępowanie było odczytywane tylko jako wiersz listy, bez treści.
    //    Dokładany PO keyword-openerach (te mają pierwszeństwo), z deduplikacją.
    const sigOpeners = clickable.filter(notFile).filter((el) => SIG_RE.test(textOf(el)));
    return [...new Set([...openers, ...sigOpeners])].slice(0, MAX_NOTICES_PER_PROCEEDING + 2);
  }

  // Ekstrakcja TREŚCI otwartego obwieszczenia/postanowienia (najlepszy widoczny kontener).
  function extractNoticeText() {
    const containers = ["article", "section", "main", ".content", ".panel", ".card", ".obwieszczenie", "[role=dialog]", ".modal", ".dialog", "div"].join(",");
    const candidates = [...document.querySelectorAll(containers)]
      .filter((el) => visible(el))
      .map((el) => ({ el, t: (el.innerText || "").trim() }))
      .filter((x) => x.t.length > 120 && looksLikeNoticeContent(x.t));
    candidates.sort((a, b) => {
      const score = (x) => (SIG_RE.test(x.t) ? 1500 : 0) + (/postanawia|postanowienie/.test(norm(x.t)) ? 1200 : 0) + Math.min(x.t.length, 8000);
      return score(b) - score(a);
    });
    if (candidates.length) return candidates[0].t.slice(0, 12000);
    // Fallback — najdłuższy sensowny fragment strony.
    return extractAnnouncementText();
  }

  // Otwiera pojedyncze obwieszczenie, czeka na treść, ekstrahuje ją i wraca do listy.
  // Zwraca string z treścią albo null. Obsługuje oba przypadki: osobny widok i in-line.
  async function openAndExtractNotice(opener) {
    const beforeUrl = location.href;
    const beforeLen = (document.body.innerText || "").length;
    clickElement(opener);
    // Czekamy aż pojawi się treść obwieszczenia (osobny widok, modal albo rozwinięcie in-line).
    const got = await waitFor(() => {
      const txt = extractNoticeText();
      return looksLikeNoticeContent(txt) ? txt : null;
    }, 8000);
    const text = got || (looksLikeNoticeContent(extractNoticeText()) ? extractNoticeText() : null);
    // Powrót do widoku listy, jeśli nawigacja zmieniła adres (osobny widok).
    if (location.href !== beforeUrl) {
      try { history.back(); } catch (_) {}
      await waitFor(() => location.href === beforeUrl || collectProceedingRows().length > 0, 8000);
      await sleep(300);
    }
    // Jeśli otworzył się modal/dialog — spróbuj go zamknąć, by nie zasłaniał kolejnych klików.
    if ((document.body.innerText || "").length !== beforeLen) {
      const closer = byText("button, a, [role=button]", /^\s*(zamknij|powrót|powrot|wstecz|zamknj|x)\s*$/i)[0]
        || [...document.querySelectorAll("[role=dialog] button, .modal button, .dialog button")].filter(visible).find((b) => /zamknij|powrot|powrót|x/i.test(textOf(b) + (b.getAttribute("aria-label") || "")));
      if (closer && location.href === beforeUrl) { clickElement(closer); await sleep(300); }
    }
    return text;
  }

  // Główna procedura drążenia: postępowania -> obwieszczenia -> treść.
  // Zwraca tablicę items zgodną z nowym kontraktem krzCapture (może być pusta).
  //
  // BŁĄD znaleziony 2026-08-21 (user: „działa gdy KRZ puste, zawodzi gdy ma wpisy" —
  // trafna obserwacja, jedyny podmiot z realnym wpisem to jedyny, który wymaga
  // klikania w obwieszczenia). Poprzednio items zbierane były TYLKO W PAMIĘCI i
  // wysyłane RAZEM na końcu tej funkcji. Klikanie w obwieszczenie to interakcja z
  // elementem PORTALU poza naszą kontrolą — jeśli poskutkuje pełną nawigacją modułu
  // (iframe dostaje nową treść) zamiast wewnętrznej trasy SPA, kontekst JS tej ramki
  // ginie W TRAKCIE działania, bez żadnego wyjątku do złapania — cały local `items`
  // przepada, a serwer po watchdogu widzi czyste milczenie → błąd. Dla podmiotów BEZ
  // wyników ten kod nigdy się nie wykonuje (kończą się wcześniej na „brak wyników"),
  // więc zawsze działały. FIX: każda pozycja jest wysyłana OD RAZU (funkcja `flush`),
  // ZANIM ryzykowne kliknięcie w kolejne obwieszczenie. Nawet jeśli TO kliknięcie
  // zabije ramkę, to co już zdobyto jest bezpiecznie zapisane — a `job.captures` po
  // stronie background.js (który PRZETRWA śmierć ramki/karty, bo żyje w service
  // workerze) sprawia, że finalny wynik zadania ma captured>=1 niezależnie od tego,
  // czy krzJobDone kiedykolwiek zostanie wywołane. Serwer (KrzApiController::
  // runFinished) już wcześniej sprawdzał `captured` PRZED `error` — więc późniejszy
  // błąd/watchdog jest poprawnie ignorowany, bez żadnej zmiany po stronie PHP.
  async function collectItems(job) {
    const items = [];
    let truncatedProceedings = false;
    let truncatedNotices = false;
    // Po tym momencie NIE otwieramy już kolejnych treści — metadane postępowań są
    // tanie i przechwytywane niezależnie, więc mimo przerwania drążenia wynik jest
    // kompletny co do sygnatur/rodzajów/dat, a ramka zdąży zgłosić go przed watchdogiem.
    const drillDeadline = (JOB_STARTED_AT || Date.now()) + DRILL_DEADLINE_MS;

    // Metadane samego wiersza postępowania (rodzaj/sygnatura/daty/status) z natury
    // NIE zawierają identyfikatora ani nazwy DŁUŻNIKA — KRZ pokazuje w wierszu dane
    // sprawy, nie strony. Bramka `captureMatchesSubject` w background.js (i serwerowe
    // `textMatchesSubject`) wymagają twardego identyfikatora/nazwy w treści, więc taki
    // fragment sam w sobie zostałby odrzucony, mimo że strona wyników JUŻ została
    // zweryfikowana (`resultRowsMatchCurrentQuery`) jako dotycząca właściwego podmiotu.
    // Dopisujemy więc jawnie kryterium wyszukiwania — ten sam, już zaufany wzorzec co
    // w `visiblePageText()` przy potwierdzonym braku wyników.
    const criterionSuffix = job && job.query ? " [Kryterium wyszukiwania: " + job.query + "]" : "";
    const flush = async (item) => {
      const withCriterion = criterionSuffix ? { ...item, text: String(item.text || "") + criterionSuffix } : item;
      items.push(withCriterion);
      try { await send("krzCapture", { items: [withCriterion], url: location.href, subjectId: (job && job.subjectId) || null }); }
      catch (_) {}
    };

    let rows = collectProceedingRows();
    // Jeśli lista nie jest jeszcze rozwinięta, spróbuj rozwinąć pierwszy wynik (jak dotychczas),
    // co często odsłania tabelę postępowań.
    if (!rows.length) {
      try { await openResultDetailsIfNeeded(); } catch (_) {}
      rows = collectProceedingRows();
    }

    const allRows = [...document.querySelectorAll("table tr, tbody tr, [role=row], .row")]
      .filter((el) => visible(el) && (SIG_RE.test(textOf(el)) || /(data rejestracji|rodzaj postepowania)/i.test(norm(textOf(el)))));
    if (allRows.length > MAX_PROCEEDINGS) truncatedProceedings = true;

    for (let i = 0; i < rows.length && i < MAX_PROCEEDINGS; i++) {
      let row = rows[i];
      let proceedingMeta = "";
      let proceedingSig = null;
      try {
        proceedingMeta = extractProceedingMeta(row);
        const sm = (proceedingMeta || "").match(SIG_RE);
        proceedingSig = sm ? sm[0] : null;

        // BEZPIECZEŃSTWO NAJPIERW: metadane wiersza (sygnatura, rodzaj, daty, status)
        // wysyłamy OD RAZU — ZANIM klikniemy cokolwiek ryzykownego. Jeśli klik zabije
        // ramkę, ta pozycja jest już bezpiecznie zapisana; jeśli drążenie się powiedzie,
        // wysłanie bogatszej treści niżej NADPISZE ją (ten sam dedupe_hash: sygnatura +
        // pierwsze 180 znaków opisu — combined zaczyna się tym samym proceedingMeta).
        if (proceedingMeta && proceedingMeta.length > 30) {
          await flush({
            text: proceedingMeta.slice(0, 12000),
            url: location.href,
            signature: proceedingSig || null,
            publication_date: extractPublicationDate(proceedingMeta) || null,
            title: guessNoticeTitle(proceedingMeta) || null
          });
        }

        // Znajdź obwieszczenia w obrębie wiersza (i najbliższego kontenera).
        let scope = row;
        for (let up = 0; up < 3 && scope && scope.parentElement && findNoticeOpenersIn(scope).length === 0; up++) {
          scope = scope.parentElement;
        }
        let openers = findNoticeOpenersIn(scope);
        if (openers.length > MAX_NOTICES_PER_PROCEEDING) truncatedNotices = true;
        openers = openers.slice(0, MAX_NOTICES_PER_PROCEEDING);
        // Budżet czasu wyczerpany — nie wchodzimy już w treść tego (ani kolejnych)
        // postępowania; metadane są już bezpiecznie wysłane wyżej.
        if (openers.length && Date.now() > drillDeadline) { truncatedNotices = true; openers = []; }

        for (let j = 0; j < openers.length; j++) {
          if (Date.now() > drillDeadline) { truncatedNotices = true; break; }
          try {
            const noticeText = await openAndExtractNotice(openers[j]);
            if (noticeText && looksLikeNoticeContent(noticeText)) {
              const combined = (proceedingMeta ? proceedingMeta + "\n\n--- TREŚĆ OBWIESZCZENIA ---\n" : "") + noticeText;
              const sig = proceedingSig || ((combined.match(SIG_RE) || [null])[0]);
              await flush({
                text: combined.slice(0, 12000),
                url: location.href,
                signature: sig || null,
                publication_date: extractPublicationDate(noticeText) || extractPublicationDate(proceedingMeta) || null,
                title: guessNoticeTitle(noticeText) || null
              });
            }
            // Po powrocie do listy odśwież referencję wiersza (DOM mógł się przebudować).
            const fresh = collectProceedingRows();
            if (fresh[i]) { row = fresh[i]; scope = row; }
          } catch (eNotice) {
            LOG("Pominięto obwieszczenie (błąd):", eNotice && eNotice.message ? eNotice.message : eNotice);
          }
        }
      } catch (eRow) {
        LOG("Pominięto postępowanie (błąd):", eRow && eRow.message ? eRow.message : eRow);
      }
    }

    // Uczciwa informacja o obcięciu (nie ukrywamy limitu).
    if ((truncatedProceedings || truncatedNotices) && items.length) {
      await flush({
        text: "Uwaga: przetworzono część postępowań/obwieszczeń (limit wtyczki) — sprawdź portal KRZ ręcznie, aby zobaczyć wszystkie pozycje.",
        url: location.href,
        signature: null,
        publication_date: null,
        title: "Informacja o limicie"
      });
    }
    return items;
  }

  // Metadane pojedynczego postępowania — bazuje na dotychczasowej ekstrakcji wiersza.
  function extractProceedingMeta(row) {
    let el = row;
    // rozszerz do kontenera, jeśli sam wiersz jest bardzo krótki
    for (let i = 0; i < 3 && el && el.parentElement; i++) {
      const pt = (el.parentElement.innerText || "").trim();
      if (pt.length > (el.innerText || "").length && pt.length < 6000 && (SIG_RE.test(pt) || /(data rejestracji|rodzaj postepowania)/i.test(norm(pt)))) el = el.parentElement;
      else break;
    }
    return ((el && el.innerText) || (row && row.innerText) || "").trim().slice(0, 6000);
  }

  function looksLikeAnnouncementText(text) {
    const t = (text || "").toLowerCase();
    if (SIG_RE.test(text || "")) return true;
    const strong = /(upad|restruktur|syndyk|nadzorca|zarządca|zarzadca|układ|uklad|wierzytel|masa upadłości|masa upadlosci|postępowanie|postepowanie)/i.test(t);
    const formal = /(obwieszcz|postanow|sąd|sad|referendarz|krz|sygnatura|sygn\. akt|dłużnik|dluznik|data rejestracji|data zakończenia|data zakonczenia|status|rodzaj postępowania|rodzaj postepowania)/i.test(t);
    const resultRow = /(postępowania restrukturyzacyjne|postepowania restrukturyzacyjne|postępowania upadłościowe|postepowania upadlosciowe)/i.test(t) && /(zakończone|zakonczone|w toku|aktywne|umorzon|oddalon|odmow)/i.test(t);
    return (text || "").length >= 50 && ((strong && formal) || resultRow);
  }

  function looksLikeNoResultsText(text) {
    const t = norm(text || "");
    return /brak wynik|brak danych spelniajacych|0 wynik|lista jest pusta|liczba podmiotow: 0|nie zosta[l]y znalezione (zadne )?pozycje|nie znaleziono (zadnych )?(wynikow|danych|podmiotow|pozycji)|nie odnaleziono (zadnych )?(wynikow|danych|podmiotow)/.test(t);
  }

  function visiblePageText(limit = 4000) {
    const body = ((document.body && document.body.innerText) || "").replace(/\s+/g, " ").trim();
    // innerText nie zawiera wartości inputów. Do diagnostyki/no-results dokładamy
    // więc jawnie widoczne kryterium; dzięki temu wynik można skorelować z jobem.
    const values = [...document.querySelectorAll("input, textarea")]
      .filter((el) => visible(el) && String(el.value || "").trim() !== "")
      .map((el) => String(el.value || "").trim()).slice(0, 4);
    const text = body + (values.length ? " [Kryterium wyszukiwania: " + values.join(" | ") + "]" : "");
    return text.slice(0, limit);
  }

  // Tekst WYŁĄCZNIE wierszy wyników (bez inputu z kryterium). Wcześniej samo
  // pole nadal zawierało właściwy NIP/nazwę, lecz pod nim mógł zostać stary wiersz
  // innej osoby. Automat klikał wtedy jego sygnaturę i dopiero backend odrzucał
  // obcą treść — za późno, bo karta szczegółów Rafała była już otwarta.
  function subjectResultRowsText() {
    return [...document.querySelectorAll("[role=row], table tr, tbody tr")]
      .filter(visible)
      .map((el) => textOf(el))
      .filter((text) => text.length > 5)
      .join("\n").slice(0, 16000);
  }

  function resultRowsMatchCurrentQuery(job) {
    const rows = subjectResultRowsText();
    const query = String(job.query || "").trim();
    if (!rows || !query) return false;
    const queryDigits = query.replace(/\D/g, "");
    if (queryDigits.length >= 6) {
      const ids = rows.match(/(?<!\d)\d[\d\s-]{4,18}\d(?!\d)/g) || [];
      return ids.some((value) => value.replace(/\D/g, "") === queryDigits);
    }
    return norm(rows).includes(norm(query));
  }

  function send(type, payload) {
    return new Promise((resolve) => {
      try { chrome.runtime.sendMessage({ type, ...payload }, (r) => resolve(r || {})); }
      catch (e) { resolve({ ok: false, error: String(e) }); }
    });
  }

  function toast(text, ok = true) {
    const t = document.createElement("div");
    t.textContent = text;
    t.style.cssText = `position:fixed;z-index:2147483647;right:16px;bottom:64px;max-width:380px;padding:12px 14px;border-radius:10px;font:600 13px system-ui,Segoe UI,Arial;color:#fff;box-shadow:0 6px 24px rgba(0,0,0,.25);background:${ok ? "#059669" : "#dc2626"}`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 7000);
  }

  async function sendCurrentAnnouncement(subjectId) {
    await openResultDetailsIfNeeded();
    const text = extractAnnouncementText();
    if (!looksLikeAnnouncementText(text)) {
      // Tryb ręczny obsługuje też POTWIERDZONY BRAK WYNIKÓW: strona z komunikatem
      // "Nie zostały znalezione żadne pozycje..." jest pełnoprawnym wynikiem
      // monitoringu (serwer dopasuje podmiot po identyfikatorze wpisanym w polu).
      const pt = visiblePageText(6000);
      if (looksLikeNoResultsText(pt)) {
        const res = await send("krzCapture", { text: pt, url: location.href, subjectId: subjectId || null });
        if (res && res.ok) toast("Wysłano potwierdzenie braku wyników KRZ ✓");
        else if (res && res.reason === "no_subject_match") toast("Nie dopasowano monitorowanego podmiotu do tej strony (brak identyfikatora w treści).", false);
        else toast("Nie udało się wysłać potwierdzenia braku wyników.", false);
        return res;
      }
      toast("Nie znalazłem konkretnego wyniku/obwieszczenia KRZ. Sprawdź, czy wtyczka jest w module Wyszukiwanie podmiotów i czy wynik został rozwinięty.", false);
      return { ok: false, reason: "not_announcement" };
    }
    const res = await send("krzCapture", { text, url: location.href, subjectId: subjectId || null });
    if (res && res.ok) toast("Wysłano wynik KRZ do programu ✓");
    else if (res && res.reason === "no_subject_match") toast("Wysłano, ale nie dopasowano podmiotu — sprawdź, czy to monitorowany podmiot.", false);
    else if (res && res.reason === "empty_or_not_matching_subject") toast("Program odrzucił stronę: wynik KRZ nie pasuje do monitorowanego podmiotu.", false);
    else if (res && res.reason === "empty_or_not_announcement") toast("Program odrzucił stronę, bo nie wygląda jak wynik KRZ.", false);
    else toast("Nie udało się połączyć z programem — sprawdź ustawienia wtyczki.", false);
    return res;
  }

  // Role ramek (moduły KRZ żyją w iframe'ach niedostępnych z góry):
  //  - ramka główna: nawiguje do modułu i pełni rolę watchdoga (błąd + diagnostyka,
  //    jeśli żadna ramka nie zamknie zadania w czasie),
  //  - ramka z formularzem: wykonuje wyszukiwanie i wysyła wynik.
  // Background ignoruje drugie i kolejne krzJobDone (job.done), więc watchdog nie
  // nadpisze wyniku ramki formularza.
  // Portal KRZ rejestruje onbeforeunload ("Czy na pewno wyjść z aplikacji?") — przy
  // programowym zamknięciu karty przez wtyczkę (background.js po zakończeniu zadania)
  // ten dialog potrafi ZABLOKOWAĆ zamknięcie (MSiG go nie ma, dlatego zamykał się
  // normalnie). Wołane WYŁĄCZNIE w karcie z automatycznym zadaniem — ręczne
  // przeglądanie portalu przez człowieka zachowuje ostrzeżenie portalu bez zmian.
  function suppressBeforeUnload() {
    try {
      window.onbeforeunload = null;
      // Listener w fazie PRZECHWYTYWANIA + stopImmediatePropagation blokuje handlery
      // portalu (faza target/bubbling), więc żaden nie ustawi returnValue → brak dialogu.
      window.addEventListener("beforeunload", (e) => {
        try { e.stopImmediatePropagation(); } catch (_) {}
        try { delete e.returnValue; } catch (_) {}
      }, true);
    } catch (_) {}
  }

  async function runJob(job) {
    // Znacznik startu zadania — punkt odniesienia dla budżetu drążenia (DRILL_DEADLINE_MS)
    // i dla watchdogu ramki głównej. MUSI pochodzić z background.js (moment utworzenia
    // karty, job.startedAt), NIE z lokalnego Date.now(): każda ramka (top i formularz)
    // to OSOBNY kontekst JS z własnymi zmiennymi modułu, więc ramka formularza
    // wyrenderowana przez SPA po kilkunastu-kilkudziesięciu sekundach dostawała
    // wcześniej świeże 80s drążenia, mimo że watchdog ramki głównej liczył 105s od
    // startu KARTY, a nie od startu TEJ ramki. Rozjazd dwóch niezależnych zegarów —
    // nie sam budżet DRILL_DEADLINE_MS (1.8.3) — powodował „ramka formularza nie
    // zgłosiła wyniku" u jedynego podmiotu z realnym wpisem w KRZ (Mucharski).
    JOB_STARTED_AT = Number(job.startedAt) || Date.now();
    // Zadanie automatyczne → po jego zakończeniu background zamknie kartę; usuwamy
    // blokadę beforeunload, żeby zamknięcie było natychmiastowe i bezwarunkowe.
    suppressBeforeUnload();
    if (IS_TOP) { await runJobTopFrame(job); return; }
    // Ramki obserwujące nie kończą już zadania samodzielnie. Tylko jedna ramka
    // formularza dostanie dzierżawę od backgroundu (frameId + documentId).
    await runJobSearchFrame(job);
  }

  // Czy TA ramka prowadzi wyszukiwanie? Obserwator wyniku nie może wtedy zgłaszać
  // "braku wyników" — ubijałby zadanie w połowie pętli po zakładkach (wyścig:
  // komunikat z zakładki podmiotów kończył job, zanim sprawdziliśmy zakładkę osób).
  let IS_SEARCH_FRAME = false;

  function watchOutcomeInThisFrame(job) {
    // Przy planie wielozakładkowym komunikat „brak wyników” po pierwszej
    // zakładce jest tylko wynikiem CZĄSTKOWYM. Obca ramka nie zna postępu pętli
    // ramki formularza, więc nie może wtedy bezpiecznie kończyć całego zadania.
    const baseKind = job.searchKind || job.search_kind || "company";
    if (baseKind === "company" && !job.hasKrs) return;
    (async () => {
      const seen = await waitFor(() => {
        if (!isActivePortalFrame()) return null;
        if (IS_SEARCH_FRAME) return "stop";
        const t = visiblePageText(6000);
        return looksLikeNoResultsText(t) ? t : null;
      }, 100000, 700);
      if (seen && seen !== "stop" && !IS_SEARCH_FRAME) {
        LOG("Ramka-obserwator: potwierdzony brak wyników KRZ (komunikat w innej ramce niż formularz).");
        await send("krzJobDone", { noResults: true, pageText: "[wtyczka v" + chrome.runtime.getManifest().version + " / ramka-obserwator] " + seen.slice(0, 4000) });
      }
    })().catch(() => {});
  }

  // Watchdog czeka na wynik do t0+WATCHDOG_MS. Sygnał aktywności MUSI być nadawany
  // niemal do samego watchdoga (nie krócej) — 2026-07-14 wykryta na produkcji luka
  // 75s/105s: gdy iframe formularza wyrenderował się PO 75. sekundzie (np. portal
  // wolny/zdegradowany — KRZ sam informował o „opóźnieniach" tego dnia), nigdy nie
  // dostawał sygnału aktywności i jego własne 60s oczekiwanie na gotowość musiało
  // zawieść NIEZALEŻNIE od tego, ile jeszcze budżetu zostało. Rozjazd okien = błąd
  // "ramka formularza nie zgłosiła wyniku" mimo poprawnej nawigacji (2s, sukces).
  const WATCHDOG_MS = 105000;
  async function runJobTopFrame(job) {
    // JOB_STARTED_AT (ustawione w runJob z job.startedAt) — ten sam zegar zerowy,
    // który dostanie też ramka formularza, niezależnie od tego, kiedy się wyrenderuje.
    const t0 = JOB_STARTED_AT;
    LOG("Zadanie (ramka główna, nawigacja):", job);
    try { await topNavigateToModule(); } catch (e) { LOG("Błąd nawigacji:", e); }
    // Powłoka ACP buforuje wiele iframe'ów. Przez PRAWIE CAŁY budżet zadania
    // regularnie oznaczamy jedyny iframe z klasą active-view-container; sygnał
    // jest przekazywany również do jego ewentualnych ramek potomnych. Zostawiamy
    // krótki margines na ostatnie sprawdzenie gotowości ramki formularza (własny
    // interwał 400ms) i wysyłkę krzJobDone, zanim padnie watchdog.
    const signalUntil = t0 + (WATCHDOG_MS - 2000);
    let activeSeenAt = null;
    while (Date.now() < signalUntil) {
      const active = document.querySelector("iframe.active-view-container");
      if (active) activeSeenAt = activeSeenAt ?? Date.now() - t0;
      try { active && active.contentWindow && active.contentWindow.postMessage({ type: ACTIVE_FRAME_MESSAGE }, "*"); } catch (_) {}
      await sleep(400);
    }
    // Watchdog: budżet karty w background to 120 s — zgłoś diagnostykę tuż przed nim.
    await sleep(Math.max(1000, WATCHDOG_MS - (Date.now() - t0)));
    // Diagnostyka STRUKTURALNA: odróżnia „portal po prostu wolny" (iframe istniał,
    // sygnał szedł, ale formularz nie zdążył się przygotować) od „zmiana wersji
    // portalu złamała selektor" (iframe.active-view-container NIGDY nie znaleziony
    // — wtedy żadna ramka formularza nie mogła dostać sygnału aktywności).
    const activeFrameNow = !!document.querySelector("iframe.active-view-container");
    const frameStatus = activeSeenAt === null
      ? "iframe.active-view-container NIGDY nie znaleziony w budżecie zadania (możliwa zmiana struktury portalu)"
      : "iframe.active-view-container widziany po " + Math.round(activeSeenAt / 1000) + "s"
        + (activeFrameNow ? ", nadal obecny na koniec" : ", zniknął przed końcem budżetu");
    await send("krzJobDone", {
      error: "Nie udało się ukończyć wyszukiwania w module KRZ (ramka formularza nie zgłosiła wyniku).",
      pageText: "[wtyczka v" + chrome.runtime.getManifest().version + "] [Przebieg nawigacji]: " + NAV_TRACE.join(" | ")
        + "\n[Klikalne elementy nawigacji]: " + navCandidates()
        + "\n[Stan aktywnej ramki]: " + frameStatus
        + "\n---\n" + visiblePageText(1500),
    });
  }

  // Jedna próba wyszukania w KONKRETNEJ zakładce. Zwraca payload cząstkowy:
  // {captured} | {noResults, pageText} | {error, pageText}.
  async function searchInKind(job, kind, kindIndex = 0) {
    const attempt = { ...job, searchKind: kind, search_kind: kind };
    // Przy DRUGIEJ zakładce najpierw "Wyczyść": komunikat "brak wyników" z poprzedniej
    // zakładki potrafi zostać na stronie i zostać błędnie policzony jako wynik nowego
    // wyszukiwania, zanim portal odświeży widok.
    if (kindIndex > 0) {
      const czysc = byText("button, a, [role=button]", /^\s*wyczy[śs][ćc]\s*$/i)[0];
      if (czysc) { clickElement(czysc); await sleep(700); }
    }
    const submitted = await submitSearch(attempt);
    if (!submitted.ok) return { error: submitted.error };
    // Brak wyników rozstrzygamy najpierw — zanim spróbujemy cokolwiek drążyć.
    const earlyText = visiblePageText(8000);
    if (looksLikeNoResultsText(earlyText)) return { noResults: true, pageText: earlyText };
    // Nie wchodzimy w szczegóły żadnej sygnatury, dopóki sam WIERSZ podmiotu nie
    // potwierdzi bieżącego kryterium. To zatrzymuje stary wynik z buforowanej ramki.
    const boundResult = await waitFor(() => resultRowsMatchCurrentQuery(job), 8000, 200);
    if (!boundResult) {
      return {
        error: "KRZ pokazał wiersz niezgodny z bieżącym kryterium — szczegóły nie zostały otwarte.",
        pageText: visiblePageText(8000),
      };
    }
    let items = [];
    try { items = await collectItems(job); } catch (e) { LOG("collectItems błąd:", e); items = []; }
    // Każda pozycja została już wysłana NA BIEŻĄCO wewnątrz collectItems (flush()) —
    // dzięki temu śmierć ramki W TRAKCIE drążenia nie gubi tego, co już zdobyto.
    // Nie wysyłamy więc drugi raz (byłoby to tylko nieszkodliwe, ale zbędne powtórzenie).
    if (items && items.length) return { captured: 1 };
    // FALLBACK: nie udało się otworzyć żadnego postępowania/obwieszczenia —
    // wyślij pojedynczy wynik jak w dotychczasowej wersji (bez regresji).
    const res = await sendCurrentAnnouncement(job.subjectId);
    if (res && res.ok) return { captured: 1 };
    const pageText = visiblePageText(8000);
    if (looksLikeNoResultsText(pageText)) return { noResults: true, pageText };
    return {
      error: (res && (res.reason || res.error)) ? `Nie potwierdzono wyniku KRZ: ${res.reason || res.error}` : "Nie potwierdzono ani wyniku KRZ, ani braku wyników.",
      pageText,
    };
  }

  async function runJobSearchFrame(job) {
    // Czy to ramka z formularzem? Formularz pojawia się dopiero, gdy ramka główna
    // dokona nawigacji — czekamy cierpliwie; jeśli się nie pojawi, to po prostu
    // nie nasza ramka (kończymy PO CICHU, bez krzJobDone).
    const ready = await waitFor(() => isActivePortalFrame() && findAnySearchInput() && onSubjectSearchPage(), 60000, 400);
    if (!ready) return;
    // all_frames jest konieczne (formularz żyje w OOPIF), ale tylko JEDNA świeżo
    // aktywna ramka może prowadzić zadanie. Background wiąże dzierżawę z konkretnym
    // frameId/documentId i odrzuci capture/jobDone pochodzące ze starego iframe'u.
    const claim = await send("krzClaimSearchFrame", {});
    if (!claim || !claim.ok) return;
    const leaseHeartbeat = setInterval(() => { send("krzClaimSearchFrame", {}).catch(() => {}); }, 2000);
    IS_SEARCH_FRAME = true; // wyłącza ramkowego obserwatora wyniku w TEJ ramce
    LOG("Zadanie (ramka formularza, wyszukiwanie):", job);
    // Typ zapisany w zadaniu jest kontraktem. Automatyczna korekta company/JDG
    // odbywa się wcześniej po KRS/CEIDG po stronie aplikacji; wtyczka nie może
    // samodzielnie przejść do obcej zakładki i przypisać znalezionej tam osoby.
    const baseKind = job.searchKind || job.search_kind || "company";
    const kinds = [baseKind];
    // Ślad wykonania pętli — trafia do diagnostyki na serwerze; bez niego nie da się
    // z panelu ustalić, ile zakładek faktycznie przeszukano i z jakim wynikiem.
    const trace = ["plan=" + kinds.join("+") + " hasKrs=" + String(job.hasKrs) + " baza=" + baseKind];
    let donePayload = {};
    const results = [];
    try {
      for (let i = 0; i < kinds.length; i++) {
        if (i > 0) LOG("KRZ: przechodzę do kolejnej zakładki: " + kinds[i]);
        const r = await searchInKind(job, kinds[i], i);
        results.push(r);
        trace.push(kinds[i] + "=" + (r.captured ? "WYNIK" : (r.noResults ? "brak" : "błąd:" + String(r.error || "").slice(0, 60))));
        // TYLKO realny wynik kończy pętlę wcześniej. Błąd jednej zakładki NIE może
        // blokować sprawdzenia następnej (dokładnie to ucinało zakładkę osób,
        // gdy zakładka podmiotów skończyła się błędem zamiast "brakiem wyników").
        if (r.captured) { donePayload = r; break; }
      }
      if (!donePayload.captured) {
        const allConfirmedEmpty = results.length === kinds.length
          && results.every((r) => r && r.noResults && !r.error);
        if (allConfirmedEmpty) {
          donePayload = {
            noResults: true,
            pageText: results.map((r) => r.pageText || "").filter(Boolean).join("\n---\n"),
          };
        } else {
          const failures = results.filter((r) => !r || r.error || !r.noResults);
          donePayload = {
            error: failures.map((r) => (r && r.error) || "zakładka bez rozstrzygnięcia").join("; ")
              || "Nie wszystkie zakładki KRZ zostały jednoznacznie sprawdzone.",
            pageText: results.map((r) => (r && r.pageText) || "").filter(Boolean).join("\n---\n"),
          };
        }
      }
    } catch (e) {
      donePayload = { error: String(e && e.message ? e.message : e) };
      trace.push("wyjątek:" + String(e && e.message ? e.message : e).slice(0, 80));
      LOG("Błąd zadania:", e);
    } finally {
      donePayload.pageText = "[Ślad wyszukiwania: " + trace.join(" | ") + "]\n" + (donePayload.pageText || "");
      // Znacznik wersji wtyczki w diagnostyce — jednoznacznie widać na serwerze,
      // która kopia kodu wykonała przebieg (koniec zgadywania po objawach).
      donePayload.pageText = "[wtyczka v" + chrome.runtime.getManifest().version + "] "
        + (donePayload.pageText || "");
      await send("krzJobDone", donePayload);
      clearInterval(leaseHeartbeat);
    }
  }

  function mountButton() {
    if (document.getElementById("duir-krz-btn")) return;
    const b = document.createElement("button");
    b.id = "duir-krz-btn";
    b.textContent = "📤 Wyślij wynik KRZ do DUiR";
    b.title = "Przekaż wyświetlony wynik/obwieszczenie do programu Dziennik Upadłościowy";
    b.style.cssText = "position:fixed;z-index:2147483647;right:16px;bottom:16px;padding:11px 15px;border:0;border-radius:10px;background:#1d4ed8;color:#fff;font:600 13px system-ui,Segoe UI,Arial;cursor:pointer;box-shadow:0 6px 24px rgba(29,78,216,.4)";
    b.addEventListener("click", () => { b.disabled = true; sendCurrentAnnouncement(null).finally(() => { b.disabled = false; }); });
    document.body.appendChild(b);
  }

  async function init() {
    if (IS_TOP) mountButton();
    const deadline = Date.now() + READY_RETRY_WINDOW_MS;
    do {
      const r = await send("krzReady", {});
      if (r && r.job) {
        if (JOB_RUN_STARTED) return;
        JOB_RUN_STARTED = true;
        await runJob(r.job);
        return;
      }
      await sleep(READY_RETRY_INTERVAL_MS);
    } while (Date.now() < deadline && !JOB_RUN_STARTED);
  }

  if (document.readyState === "complete" || document.readyState === "interactive") init();
  else window.addEventListener("DOMContentLoaded", init);
})();
