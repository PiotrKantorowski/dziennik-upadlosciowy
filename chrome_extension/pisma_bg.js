// Pisma KRZ (prototyp) — część service workera. Ładowana przez importScripts
// z background.js; z background.js bierze tylko getConfig() (adres DUiR).
// Pisma pobieramy w SESJI użytkownika zalogowanego do DUiR w tej przeglądarce
// (cookie), a nie wspólnym tokenem wtyczki: serwer zwraca wyłącznie pisma
// właściciela konta, a dane trafiają tylko do jego przeglądarki.
// Na tym etapie TYLKO odczyt; wtyczka nigdy nie podpisuje ani nie wysyła pisma.

async function pismaFetch(path) {
  const { appUrl } = await getConfig();
  const resp = await fetch(appUrl.replace(/\/+$/, "") + path, { credentials: "include", redirect: "follow" });
  const type = resp.headers.get("content-type") || "";
  // Bez sesji DUiR przekierowuje na stronę logowania (HTML), więc nie JSON.
  if (!type.includes("application/json")) {
    return { ok: false, error: "Zaloguj się do DUiR w tej przeglądarce, żeby zobaczyć swoje pisma." };
  }
  return resp.json();
}

async function detectPuzForm() {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab || !tab.id) return { ok: false, error: "Brak aktywnej karty." };
  try {
    const r = await chrome.tabs.sendMessage(tab.id, { type: "duirPuzDetect" });
    return r && r.code ? { ok: true, ...r } : { ok: true, code: null };
  } catch (_) {
    // Brak odbiorcy = karta spoza portalu KRZ albo strona jeszcze się ładuje.
    return { ok: true, code: null };
  }
}

async function handlePismaMessage(msg) {
  if (msg.type === "pismaList") return pismaFetch("/krz/pisma.json");
  if (msg.type === "pismaGet") return pismaFetch("/krz/pisma/" + encodeURIComponent(String(msg.id || "")) + ".json");
  if (msg.type === "pismaDetect") return detectPuzForm();
  return { ok: false, error: "unknown pisma message" };
}
