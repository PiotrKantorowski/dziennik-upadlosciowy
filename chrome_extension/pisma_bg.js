// Pisma KRZ (prototyp) — część service workera. Ładowana przez importScripts
// z background.js; korzysta z jego appFetch (ten sam adres DUiR i token).
// Na tym etapie TYLKO odczyt: lista pism, karta pisma i rozpoznanie wzoru
// otwartego w KRZ. Wpisywanie w pola zostanie dodane po zmapowaniu formularza
// na żywym portalu. Wtyczka nigdy nie podpisuje ani nie wysyła pisma.

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
  if (msg.type === "pismaList") return { ok: true, items: (await appFetch("/api/krz/pisma")).items || [] };
  if (msg.type === "pismaGet") return appFetch("/api/krz/pisma/" + encodeURIComponent(String(msg.id || "")));
  if (msg.type === "pismaDetect") return detectPuzForm();
  return { ok: false, error: "unknown pisma message" };
}
