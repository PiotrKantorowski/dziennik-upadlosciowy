// Pisma KRZ (prototyp) — rozpoznanie wzoru otwartego w Portalu Użytkowników
// Zarejestrowanych. Działa w każdej ramce portalu; odpowiada tylko ramka,
// która widzi formularz. Nic nie wpisuje i nic nie klika.
(() => {
  "use strict";
  // Podręcznik PUZ v1.12: wzór pokazywany jako „20001.1 – Wniosek o …";
  // nowa wersja formularza dostaje kolejny numer po kropce (20001.4).
  const CODE_RE = /(?<![\d.,])(\d{5}[A-Z]{0,2})\.(\d{1,2})\s*[–-]\s*\p{L}/u;
  const KNOWN_TITLES = { "30048": "Zgłoszenie wierzytelności w postępowaniu upadłościowym" };

  function detect() {
    const text = (document.body && document.body.innerText) || "";
    const m = text.match(CODE_RE);
    if (m) return { code: m[1], version: m[1] + "." + m[2], how: "kod wzoru na stronie" };
    for (const [code, title] of Object.entries(KNOWN_TITLES)) {
      if (text.includes(title)) return { code, version: null, how: "tytuł formularza (wersja nieznana)" };
    }
    return null;
  }

  chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
    if (!msg || msg.type !== "duirPuzDetect") return false;
    const found = detect();
    if (!found) return false; // milczymy — odpowie ramka z formularzem
    sendResponse({ ...found, frame: location.hostname });
    return false;
  });
})();
