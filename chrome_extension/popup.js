const $ = (id) => document.getElementById(id);
function msg(type) {
  return new Promise((resolve) => chrome.runtime.sendMessage({ type }, (r) => resolve(r || {})));
}

function renderLast(lastRun) {
  if (!lastRun || !lastRun.summary) { $("last").textContent = "Brak wcześniejszych przebiegów."; return; }
  const s = lastRun.summary;
  if (!s.ok) { $("last").textContent = "Ostatni przebieg: błąd — " + (s.error || ""); return; }
  const total = (s.items || []).reduce((n, it) => n + (it.captured || 0), 0);
  $("last").textContent = `Ostatni przebieg: ${new Date(lastRun.at).toLocaleString("pl-PL")}\nPodmiotów: ${(s.items || []).length}, przechwycono: ${total}`;
}

async function refresh() {
  const st = await msg("getState");
  renderLast(st.lastRun);
  const p = await msg("ping");
  // Wersja WTYCZKI w popupie — jednoznaczna weryfikacja, którą kopię kodu
  // Chrome faktycznie wykonuje (mylenie wersji kosztowało całą noc debugowania).
  const v = chrome.runtime.getManifest().version;
  $("conn").innerHTML = (p.ok
    ? '<span class="dot ok"></span>Połączono z programem ✓'
    : '<span class="dot err"></span>Brak połączenia — otwórz Ustawienia')
    + ' <span style="color:#94a3b8">· wtyczka v' + v + '</span>';
}

$("run").addEventListener("click", async () => {
  $("run").disabled = true; $("run").textContent = "Sprawdzam KRZ…";
  const r = await msg("runNow");
  $("run").disabled = false; $("run").textContent = "🔍 Sprawdź KRZ teraz";
  if (!r.ok) $("last").textContent = "Błąd: " + (r.error || "nieznany");
  else renderLast({ at: new Date().toISOString(), summary: r });
});

$("opts").addEventListener("click", () => chrome.runtime.openOptionsPage());

refresh();
