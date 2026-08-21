// Asystent MSiG — content script. Działa na oficjalnej, darmowej wyszukiwarce
// MSiG (Ministerstwo Sprawiedliwości) w realnej sesji użytkownika. Ten sam
// wzorzec co content_krz.js: bez płatnego API, bez omijania zabezpieczeń —
// wypełnia formularz wyszukiwania i odczytuje wynik tak, jak zrobiłby to
// człowiek. Selektory są defensywne (wiele wariantów), bo portal to SPA i
// bywa zmieniany — patrz "Dostrojenie do żywego portalu" w README wtyczki.

(() => {
  "use strict";
  const LOG = (...a) => console.log("[DUiR/MSIG]", ...a);
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const SIG_RE = /\bBMSiG[-\s]?[A-Z]*[-\s]?\d{1,8}\/20\d{2}\b/i;

  function visible(el) {
    if (!el) return false;
    const r = el.getBoundingClientRect();
    const s = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && s.visibility !== "hidden" && s.display !== "none";
  }

  function norm(text) {
    return (text || "")
      .normalize("NFD").replace(/[̀-ͯ]/g, "")
      .toLowerCase().replace(/\s+/g, " ").trim();
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
    const parent = el.closest("div, td, th, form, section");
    if (parent) parts.push(textOf(parent).slice(0, 180));
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
    try { el.click(); return true; } catch (_) {}
    try {
      el.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true, view: window }));
      return true;
    } catch (_) { return false; }
  }

  // Wybór pola: najpierw dopasowanie po etykiecie identyfikatora (KRS/NIP/REGON),
  // potem po nazwie podmiotu, a na końcu pierwszy widoczny input tekstowy —
  // ta sama, defensywna kolejność co w dawnym providerze Playwright
  // (dziennik/providers/live_common.py, metoda _fill_search_terms).
  function findInputForJob(job) {
    const inputs = [...document.querySelectorAll("input, textarea")]
      .filter((i) => visible(i) && ["text", "search", "number", "tel", ""].includes((i.getAttribute("type") || "").toLowerCase()))
      .map((el) => ({ el, lab: norm(labelText(el)) }));
    const queryKey = norm(job.queryKey || job.query_key || "");
    if (["krs", "nip", "regon", "pesel"].includes(queryKey)) {
      const byKey = inputs.find((x) => x.lab.includes(queryKey));
      if (byKey) return byKey.el;
    }
    const ident = inputs.find((x) => /identyfikator|krs|nip|regon|pesel/.test(x.lab) && !/nazwa podmiotu|firma|imie|imię|nazwisko/.test(x.lab));
    if (ident && queryKey !== "name") return ident.el;
    const name = inputs.find((x) => /nazwa podmiotu|firma|nazwa|podmiot|imie i nazwisko|imię i nazwisko/.test(x.lab));
    if (name) return name.el;
    return inputs[0] ? inputs[0].el : null;
  }

  function findSearchButton() {
    const exact = byText("button, a, input[type=submit]", /^\s*(szukaj|wyszukaj|filtruj|poka[żz]|zastosuj)\s*$/i)[0];
    if (exact) return exact;
    const candidates = [...document.querySelectorAll("button, a, input[type=submit]")].filter(visible);
    return candidates.find((el) => /szukaj|wyszukaj|filtruj|poka[żz]|zastosuj/i.test(textOf(el) || el.value || el.getAttribute("aria-label") || "")) || null;
  }

  async function submitSearch(job) {
    const input = findInputForJob(job);
    if (!input) return { ok: false, error: "Nie znaleziono właściwego pola wyszukiwania MSiG." };
    const staleText = visiblePageText(8000);
    if (looksLikeNoResultsText(staleText) || looksLikeAnnouncementText(staleText)) {
      const clear = byText("button, a, [role=button]", /^\s*wyczy[śs][ćc]\s*$/i)[0];
      if (clear) {
        clickElement(clear);
        await waitFor(() => !looksLikeNoResultsText(visiblePageText(8000)) && !looksLikeAnnouncementText(extractAnnouncementText()), 5000, 150);
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
    if (!valueMatches()) return { ok: false, error: "Portal MSiG nie przyjął bieżącego kryterium wyszukiwania." };
    const btn = findSearchButton();
    const submittedAt = Date.now();
    if (btn) clickElement(btn);
    else if (input.form && input.form.requestSubmit) input.form.requestSubmit();
    else input.dispatchEvent(new KeyboardEvent("keydown", { bubbles: true, key: "Enter" }));
    const loaded = await waitFor(() => {
      if (Date.now() - submittedAt < 650 || !valueMatches()) return null;
      const txt = visiblePageText(8000);
      if (looksLikeNoResultsText(txt)) return "no_results";
      if (looksLikeAnnouncementText(txt)) return "announcement";
      return null;
    }, 20000);
    return { ok: true, loaded };
  }

  // Wyniki MSiG są zwykle w tabeli/liście wierszy. Kolejność selektorów: od
  // najbardziej precyzyjnych (wiersze tabeli) do ogólnych (karty/artykuły) —
  // ta sama strategia co RESULT_SELECTOR_GROUPS w dawnym providerze Playwright.
  const RESULT_SELECTOR_GROUPS = [
    ["table tbody tr", ".ui-table-tbody tr", ".p-datatable-tbody tr", ".mat-row", "[role='row']"],
    ["table tr", "[class*='result' i]", "[class*='wynik' i]"],
    [".result", ".results li", "article", "li"],
  ];

  function extractAnnouncementText() {
    for (const selectors of RESULT_SELECTOR_GROUPS) {
      const blocks = [];
      for (const selector of selectors) {
        for (const el of document.querySelectorAll(selector)) {
          if (!visible(el)) continue;
          const t = (el.innerText || "").trim();
          if (t.length >= 20) blocks.push(t);
        }
      }
      if (blocks.length) return blocks.join("\n---\n").slice(0, 12000);
    }
    return "";
  }

  const MAX_DETAILS = 10; // limit ogłoszeń na jeden przebieg (mieści się w 120s timeout karty)

  // Zwraca widoczne wiersze/pozycje wyników — pierwszą niepustą grupę selektorów
  // (ta sama defensywna kolejność co extractAnnouncementText). Odsiewa wiersze
  // nagłówkowe tabeli i puste kontenery.
  function findResultRows() {
    for (const selectors of RESULT_SELECTOR_GROUPS) {
      const rows = [];
      for (const selector of selectors) {
        for (const el of document.querySelectorAll(selector)) {
          if (!visible(el)) continue;
          if (el.querySelector("th") && !el.querySelector("td")) continue; // wiersz nagłówka
          const t = (el.innerText || "").trim();
          if (t.length >= 20) rows.push(el);
        }
      }
      if (rows.length) return rows;
    }
    return [];
  }

  // W obrębie jednego wiersza szuka klikalnego elementu otwierającego szczegóły:
  // link/przycisk z tekstem typu numeru MSiG/BMSiG, "szczegóły", "pokaż",
  // "treść", "zobacz", albo pierwszy sensowny link. Defensywnie — portal to SPA.
  function findDetailTrigger(row) {
    if (!row) return null;
    const clickables = [...row.querySelectorAll("a[href], a, button, [role='link'], [role='button'], [onclick]")].filter(visible);
    if (!clickables.length) return null;
    const wanted = /(szczeg|poka[żz]|tre[śs][ćc]|zobacz|otw[óo]rz|wy[śs]wietl|bmsig|msig|\bnr\b|numer)/i;
    const byLabel = clickables.find((el) => wanted.test(textOf(el) || el.getAttribute("aria-label") || el.getAttribute("title") || ""));
    if (byLabel) return byLabel;
    // Link z widocznym numerem ogłoszenia (BMSiG-.../MSiG) w tekście.
    const bySig = clickables.find((el) => /BMSiG|MSiG|\d{1,8}\/20\d{2}/i.test(textOf(el)));
    if (bySig) return bySig;
    // Ostatecznie pierwszy link z href (nie kotwica #, nie javascript:void).
    const realLink = clickables.find((el) => {
      const h = (el.getAttribute && el.getAttribute("href")) || "";
      return h && h !== "#" && !/^javascript:/i.test(h);
    });
    return realLink || clickables[0];
  }

  // Ekstrakcja PEŁNEJ treści pojedynczego ogłoszenia. Nastawiona na jeden,
  // kompletny dokument, a nie listę: bierze najbardziej "treściwy" widoczny
  // kontener (najdłuższy innerText spełniający looksLikeSingleAnnouncementDetail),
  // z fallbackiem do najdłuższego bloku, a na końcu do całej strony.
  function extractDetailText() {
    const containers = [
      "main", "article", "[role='main']", ".content", ".tresc", ".tresc-ogloszenia",
      ".announcement", ".ogloszenie", ".detail", ".szczegoly", ".modal", ".dialog",
      "[role='dialog']", ".p-dialog", ".mat-dialog-content", "section", ".card", "div",
    ];
    let best = "";
    for (const selector of containers) {
      for (const el of document.querySelectorAll(selector)) {
        if (!visible(el)) continue;
        const t = (el.innerText || el.textContent || "").replace(/\r/g, "").trim();
        if (t.length < 200) continue;
        if (looksLikeSingleAnnouncementDetail(t) && t.length > best.length) best = t;
      }
    }
    if (best) return best.slice(0, 12000);
    // Fallback: najdłuższy sensowny blok, choćby nie przeszedł twardego predykatu.
    for (const selector of ["article", "main", "section", ".content", "div"]) {
      for (const el of document.querySelectorAll(selector)) {
        if (!visible(el)) continue;
        const t = (el.innerText || "").trim();
        if (t.length > best.length && t.length >= 200) best = t;
      }
    }
    if (best) return best.slice(0, 12000);
    // Ostatecznie: widoczny tekst całej strony.
    return visiblePageText(12000);
  }

  // Normalizuje datę publikacji do RRRR-MM-DD; jeśli się nie da → null.
  function extractPublicationDate(text) {
    const raw = text || "";
    let m = raw.match(/\b(20\d{2})-(\d{2})-(\d{2})\b/);
    if (m) return `${m[1]}-${m[2]}-${m[3]}`;
    m = raw.match(/\b(\d{2})\.(\d{2})\.(20\d{2})\b/);
    if (m) return `${m[3]}-${m[2]}-${m[1]}`;
    return null;
  }

  // Wyłuskuje sygnaturę BMSiG-.../MSiG z treści (jeśli jest); w przeciwnym razie null.
  function extractSignature(text) {
    const m = (text || "").match(SIG_RE);
    return m ? m[0].replace(/\s+/g, "").toUpperCase() : null;
  }

  // STABILNY identyfikator ogłoszenia WIDOCZNY w wierszu listy = „id" z linku POBIERZ
  // (…/api/Monitor/Download?id=NNNN). Zweryfikowane na żywym portalu: sygnatura BMSiG
  // jest dopiero w SZCZEGÓŁACH (przycisk, bez href), a tekst wiersza (numer monitora +
  // data) NIE jest unikalny — dwa ogłoszenia dzielą „16/2022 2022-01-25". Dlatego to
  // „id" jest jedynym pewnym kluczem do pomijania już znanych ogłoszeń bez otwierania.
  function rowDownloadHref(row) {
    const a = row && row.querySelector('a[href*="Monitor/Download"], a[href*="/Download?"]');
    const h = (a && a.getAttribute("href")) || "";
    return h ? (/^https?:/i.test(h) ? h : new URL(h, location.href).href) : null;
  }
  function rowDownloadId(row) {
    const h = rowDownloadHref(row) || "";
    const m = h.match(/[?&]id=(\d+)/);
    return m ? m[1] : null;
  }

  // Krótki nagłówek/typ ogłoszenia z pierwszej sensownej linii treści (best-effort).
  function extractTitle(text) {
    const lines = (text || "").split(/\n+/).map((l) => l.trim()).filter((l) => l.length >= 6);
    for (const l of lines) {
      if (/(obwieszcz|postanow|ogłoszen|ogloszen|upad|restruktur|wezwan|umorzen|zakończ|zakoncz|otwarci)/i.test(l)) {
        return l.slice(0, 160);
      }
    }
    return lines[0] ? lines[0].slice(0, 160) : null;
  }

  // Iteruje po wynikach listy i schodzi do szczegółów każdego ogłoszenia.
  // Zwraca tablicę items zgodną z nowym kontraktem backendu. Każdy wiersz jest
  // przetwarzany w try/catch — pojedynczy problem nie wywraca całego przebiegu.
  async function collectAnnouncementDetails(seen) {
    const items = [];
    const known = seen instanceof Set ? seen : new Set();
    const rowsSnapshot = findResultRows();
    const total = rowsSnapshot.length;
    if (!total) return items;
    const limit = Math.min(total, MAX_DETAILS);

    for (let i = 0; i < limit; i++) {
      try {
        // Wiersze pobieramy świeżo za każdym razem — po history.back() lub
        // przerysowaniu SPA stare referencje bywają nieaktualne.
        const rows = findResultRows();
        const row = rows[i];
        if (!row) { LOG(`Wiersz #${i} zniknął — pomijam.`); continue; }

        // OPTYMALIZACJA: jeśli ogłoszenie (po stabilnym „id" z linku POBIERZ) jest już
        // znane serwerowi, NIE otwieramy szczegółów — ogłoszenie MSiG się nie zmienia.
        // Wysyłamy lekki znacznik {known:true}, żeby serwer domknął zadanie i NIE
        // nadpisał istniejącej, bogatej treści. Klucz liczymy PRZED kliknięciem
        // (po history.back() referencja wiersza bywa nieaktualna).
        const rowId = rowDownloadId(row);
        const downloadHref = rowDownloadHref(row);
        if (rowId && known.has(rowId)) {
          items.push({ msig_id: rowId, known: true });
          continue;
        }

        const trigger = findDetailTrigger(row);
        if (!trigger) { LOG(`Brak elementu otwierającego dla wiersza #${i} — pomijam.`); continue; }

        const detailHref = (trigger.getAttribute && trigger.getAttribute("href")) || null;
        const listUrl = location.href;

        clickElement(trigger);

        // Czekamy aż pojawi się pełna treść pojedynczego ogłoszenia (nowy widok,
        // modal albo rozwinięcie in-line). waitFor sam ma timeout.
        const detailText = await waitFor(() => {
          const t = extractDetailText();
          return looksLikeSingleAnnouncementDetail(t) ? t : null;
        }, 12000, 350);

        if (detailText) {
          items.push({
            text: detailText,
            // źródło = link POBIERZ (zawiera stabilne id) — po nim serwer rozpozna
            // to ogłoszenie przy kolejnym przebiegu i go nie otworzy ponownie.
            url: downloadHref
              || (detailHref && !/^(#|javascript:)/i.test(detailHref) ? new URL(detailHref, location.href).href : location.href),
            msig_id: rowId,
            signature: extractSignature(detailText),
            publication_date: extractPublicationDate(detailText),
            title: extractTitle(detailText),
          });
        } else {
          LOG(`Nie doczekałem się treści szczegółów dla wiersza #${i} — pomijam.`);
        }

        // Powrót do listy jeśli szczegóły otworzyły osobny widok. Jeśli treść
        // była in-line/modalem, lista wciąż jest widoczna i back() nie jest
        // potrzebny — sprawdzamy defensywnie po URL i obecności wierszy.
        if (location.href !== listUrl) {
          try { history.back(); } catch (_) {}
          await waitFor(() => findResultRows().length > 0, 8000, 300);
          await sleep(200);
        } else {
          // Być może otwarł się modal — spróbuj go zamknąć (Esc), by nie
          // przesłaniał kolejnych wierszy. Nieszkodliwe, jeśli nic nie ma.
          try {
            const closeBtn = byText("button, a, [role='button']", /^\s*(zamknij|close|×|✕|x)\s*$/i)[0];
            if (closeBtn) clickElement(closeBtn);
            else document.body.dispatchEvent(new KeyboardEvent("keydown", { bubbles: true, key: "Escape" }));
          } catch (_) {}
          await sleep(150);
        }
      } catch (e) {
        LOG(`Błąd przy wierszu #${i} — pomijam:`, e && e.message ? e.message : e);
      }
    }

    // Nie ukrywaj obcięcia: jeśli wyników było więcej niż limit, dołóż pozycję
    // informacyjną, żeby prawnik wiedział, że lista była dłuższa.
    if (total > limit && items.length) {
      items.push({
        text: `Uwaga: przetworzono pierwsze ${limit} z ${total} ogłoszeń — sprawdź portal ręcznie.`,
        url: location.href,
        signature: null,
        publication_date: null,
        title: "Uwaga: lista wyników obcięta",
      });
    }

    return items;
  }

  function looksLikeAnnouncementText(text) {
    const t = (text || "").toLowerCase();
    if (SIG_RE.test(text || "")) return true;
    const strong = /(upad|restruktur|syndyk|nadzorca|zarządca|zarzadca|układ|uklad|wierzytel|masa upadłości|masa upadlosci|dłużnik|dluznik)/i.test(t);
    const formal = /(obwieszcz|postanow|sąd|sad|sygnatura|sygn\. akt|data rejestracji|data zakończenia|data zakonczenia)/i.test(t);
    return (text || "").length >= 50 && strong && formal;
  }

  // Rozróżnia "jestem w PEŁNEJ TREŚCI pojedynczego ogłoszenia" od "jestem na
  // LIŚCIE wyników". Pełna treść jest znacząco dłuższa i zawiera sformułowania
  // sentencji/wezwania, oprócz samej sygnatury. Używane przez waitFor przy
  // schodzeniu do szczegółów, żeby wiedzieć, że treść się faktycznie załadowała.
  function looksLikeSingleAnnouncementDetail(text) {
    const raw = text || "";
    if (raw.length < 400) return false;
    const t = raw.toLowerCase();
    // Charakterystyczne zwroty sentencji/treści ogłoszenia sądowego.
    const phrases = /(postanaw|postanowi|wzywa wierzycieli|wzywa się|w terminie|sędzia-komisarz|sedzia-komisarz|syndyk|nadzorca sądowy|nadzorca sadowy|zarządca|zarzadca|zgłasza[ćc] wierzytelno|ogłasza upadło|oglasza upadlo|otwarcie postępowania|otwarcie postepowania|masa upadłości|masa upadlosci)/i;
    let hits = 0;
    for (const re of [
      /postanaw|postanowi/i,
      /wzywa (wierzycieli|się|sie)/i,
      /w terminie/i,
      /sędzia-komisarz|sedzia-komisarz/i,
      /syndyk|nadzorca|zarządc|zarzadc/i,
      /zgłasza[ćc] wierzytelno|zglasza[ćc] wierzytelno|zgłoszeni|zgloszeni/i,
    ]) {
      if (re.test(t)) hits++;
    }
    // Wymagamy sygnatury LUB co najmniej dwóch charakterystycznych zwrotów —
    // to odróżnia pełną treść od skrótowego wiersza listy.
    return SIG_RE.test(raw) || (phrases.test(t) && hits >= 2);
  }

  function looksLikeNoResultsText(text) {
    const t = norm(text || "");
    return /brak wynik|brak danych spelniajacych|0 wynik|lista jest pusta|brak ogloszen|nie zosta[l]y znalezione (zadne )?pozycje|nie znaleziono (zadnych )?(wynikow|danych|podmiotow|ogloszen|pozycji)|nie odnaleziono (zadnych )?(wynikow|danych|podmiotow)/.test(t);
  }

  function visiblePageText(limit = 4000) {
    const body = ((document.body && document.body.innerText) || "").replace(/\s+/g, " ").trim();
    const values = [...document.querySelectorAll("input, textarea")]
      .filter((el) => visible(el) && String(el.value || "").trim() !== "")
      .map((el) => String(el.value || "").trim()).slice(0, 5);
    return (body + (values.length ? " [Kryterium wyszukiwania: " + values.join(" | ") + "]" : "")).slice(0, limit);
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
    const text = extractAnnouncementText();
    if (!looksLikeAnnouncementText(text)) {
      toast("Nie znalazłem konkretnego wyniku/ogłoszenia MSiG na tej stronie.", false);
      return { ok: false, reason: "not_announcement" };
    }
    const res = await send("msigCapture", { text, url: location.href, subjectId: subjectId || null });
    if (res && res.ok) toast("Wysłano wynik MSiG do programu ✓");
    else if (res && res.reason === "no_subject_match") toast("Wysłano, ale nie dopasowano podmiotu — sprawdź, czy to monitorowany podmiot.", false);
    else if (res && res.reason === "empty_or_not_matching_subject") toast("Program odrzucił stronę: wynik MSiG nie pasuje do monitorowanego podmiotu.", false);
    else toast("Nie udało się połączyć z programem — sprawdź ustawienia wtyczki.", false);
    return res;
  }

  async function runJob(job) {
    let donePayload = {};
    try {
      LOG("Zadanie:", job);
      const submitted = await submitSearch(job);
      if (!submitted.ok) { donePayload.error = submitted.error; return; }

      await sleep(600);

      // Najpierw wykryj brak wyników — bez sensu schodzić do szczegółów.
      const listText = visiblePageText(8000);
      if (looksLikeNoResultsText(listText)) {
        donePayload.noResults = true;
        donePayload.pageText = listText;
        return;
      }

      // Nowy przepływ: zejdź do szczegółów maks. MAX_DETAILS ogłoszeń i wyślij
      // JEDNĄ wiadomość z tablicą items.
      // Identyfikatory ogłoszeń („id" z linku POBIERZ) już znane serwerowi —
      // pozwalają pominąć otwieranie znanych ogłoszeń bez utraty nowych.
      const seen = new Set((Array.isArray(job.seen) ? job.seen : [])
        .map((s) => String(s || "").trim()).filter(Boolean));
      let items = [];
      try { items = await collectAnnouncementDetails(seen); }
      catch (e) { LOG("Błąd zbierania szczegółów:", e); items = []; }

      if (items && items.length) {
        const res = await send("msigCapture", { items, url: location.href, subjectId: job.subjectId || null });
        if (res && res.ok) {
          donePayload.captured = 1;
        } else {
          const pageText = visiblePageText(8000);
          donePayload.error = (res && (res.reason || res.error))
            ? `Backend odrzucił szczegóły MSiG: ${res.reason || res.error}`
            : "Backend nie potwierdził szczegółów MSiG.";
          donePayload.pageText = pageText;
        }
        return;
      }

      // FALLBACK (brak regresji): nie udało się otworzyć żadnych szczegółów →
      // wyślij pojedynczy capture z tekstem listy, dokładnie jak stara wersja.
      LOG("Brak szczegółów — fallback do capture listy wyników.");
      const res = await sendCurrentAnnouncement(job.subjectId);
      if (res && res.ok) {
        donePayload.captured = 1;
      } else {
        const pageText = visiblePageText(8000);
        if (looksLikeNoResultsText(pageText)) {
          donePayload.noResults = true;
          donePayload.pageText = pageText;
        } else {
          donePayload.error = (res && (res.reason || res.error))
            ? `Nie potwierdzono wyniku MSiG: ${res.reason || res.error}`
            : "Nie potwierdzono ani wyniku MSiG, ani braku wyników.";
          donePayload.pageText = pageText;
        }
      }
    } catch (e) {
      donePayload.error = String(e && e.message ? e.message : e);
      LOG("Błąd zadania:", e);
    } finally {
      await send("msigJobDone", donePayload);
    }
  }

  function mountButton() {
    if (document.getElementById("duir-msig-btn")) return;
    const b = document.createElement("button");
    b.id = "duir-msig-btn";
    b.textContent = "📤 Wyślij wynik MSiG do DUiR";
    b.title = "Przekaż wyświetlony wynik/ogłoszenie do programu Dziennik Upadłościowy";
    b.style.cssText = "position:fixed;z-index:2147483647;right:16px;bottom:16px;padding:11px 15px;border:0;border-radius:10px;background:#1d4ed8;color:#fff;font:600 13px system-ui,Segoe UI,Arial;cursor:pointer;box-shadow:0 6px 24px rgba(29,78,216,.4)";
    b.addEventListener("click", () => { b.disabled = true; sendCurrentAnnouncement(null).finally(() => { b.disabled = false; }); });
    document.body.appendChild(b);
  }

  async function init() {
    mountButton();
    const r = await send("msigReady", {});
    if (r && r.job) runJob(r.job);
  }

  if (document.readyState === "complete" || document.readyState === "interactive") init();
  else window.addEventListener("DOMContentLoaded", init);
})();
