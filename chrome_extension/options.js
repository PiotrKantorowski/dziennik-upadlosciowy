const $ = (id) => document.getElementById(id);
const DEFAULTS = { appUrl: "http://127.0.0.1:8080", token: "", scheduleHour: 10, scheduleMinute: 0, autoRun: true };


function originPattern(appUrl) {
  try {
    const u = new URL(appUrl);
    if (!["http:", "https:"].includes(u.protocol)) return null;
    return u.origin + "/*";
  } catch (_) { return null; }
}

function hasBuiltInPermission(pattern) {
  return pattern.startsWith("http://127.0.0.1") || pattern.startsWith("http://localhost");
}

async function ensureAppPermission(appUrl) {
  const pattern = originPattern(appUrl);
  if (!pattern || hasBuiltInPermission(pattern)) return true;
  return new Promise((resolve) => {
    chrome.permissions.contains({ origins: [pattern] }, (has) => {
      if (has) return resolve(true);
      chrome.permissions.request({ origins: [pattern] }, (granted) => resolve(!!granted));
    });
  });
}

function showStatus(text, ok) {
  const s = $("status");
  s.textContent = text;
  s.className = ok ? "ok" : "err";
  s.style.display = "block";
}

async function load() {
  const c = await chrome.storage.local.get(DEFAULTS);
  $("appUrl").value = c.appUrl;
  $("token").value = c.token;
  $("scheduleHour").value = c.scheduleHour;
  $("scheduleMinute").value = c.scheduleMinute;
  $("autoRun").checked = !!c.autoRun;
}

function readConfig() {
  return {
    appUrl: $("appUrl").value.trim() || DEFAULTS.appUrl,
    token: $("token").value.trim(),
    scheduleHour: Math.min(23, Math.max(0, parseInt($("scheduleHour").value, 10) || 10)),
    scheduleMinute: Math.min(59, Math.max(0, parseInt($("scheduleMinute").value, 10) || 0)),
    autoRun: $("autoRun").checked,
  };
}

function msg(type, extra) {
  return new Promise((resolve) => chrome.runtime.sendMessage({ type, ...(extra || {}) }, (r) => resolve(r || {})));
}

$("save").addEventListener("click", async () => {
  const config = readConfig();
  const granted = await ensureAppPermission(config.appUrl);
  if (!granted) {
    showStatus("Nie przyznano dostępu do adresu programu. Wtyczka nie połączy się z tym serwerem.", false);
    return;
  }
  await msg("saveConfig", { config });
  showStatus("Zapisano ustawienia.", true);
});

$("test").addEventListener("click", async () => {
  const config = readConfig();
  const granted = await ensureAppPermission(config.appUrl);
  if (!granted) {
    showStatus("Nie przyznano dostępu do adresu programu. Otwórz ustawienia ponownie albo użyj adresu lokalnego.", false);
    return;
  }
  await msg("saveConfig", { config });
  const r = await msg("ping");
  if (r.ok) showStatus("Połączono z programem ✓ (wersja " + ((r.info && r.info.version) || "?") + ")", true);
  else showStatus("Brak połączenia: " + (r.error || "sprawdź adres i token"), false);
});

load();
