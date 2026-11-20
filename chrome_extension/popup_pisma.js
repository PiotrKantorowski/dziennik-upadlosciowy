// Pisma KRZ (prototyp) — sekcja popupu. Wybór pisma z DUiR i sprawdzenie,
// czy w aktywnej karcie KRZ jest otwarty ten sam wzór. Bez wypełniania pól.
(() => {
  const el = (id) => document.getElementById(id);
  const ask = (payload) => new Promise((resolve) => chrome.runtime.sendMessage(payload, (r) => resolve(r || {})));

  async function load() {
    const r = await ask({ type: "pismaList" });
    const sel = el("pismaSel");
    sel.innerHTML = "";
    if (!r.ok) { el("pismaOut").textContent = "Nie udało się pobrać pism: " + (r.error || ""); return; }
    if (!r.items.length) { el("pismaOut").textContent = "Brak pism roboczych w DUiR (zakładka „Pisma KRZ”)."; return; }
    for (const it of r.items) {
      const o = document.createElement("option");
      o.value = it.id; o.textContent = it.wzor + " · " + it.tytul;
      sel.appendChild(o);
    }
  }

  el("pismaCheck").addEventListener("click", async () => {
    const id = el("pismaSel").value;
    if (!id) return;
    const [pismo, form] = await Promise.all([ask({ type: "pismaGet", id }), ask({ type: "pismaDetect" })]);
    if (!pismo.ok) { el("pismaOut").textContent = "Błąd pisma: " + (pismo.error || ""); return; }
    const braki = (pismo.braki && pismo.braki.errors) || [];
    const lines = [
      "Pismo: " + pismo.tytul,
      "Pól na karcie: " + (pismo.karta || []).length + ", braków wymaganych: " + braki.length,
    ];
    if (!form.code) lines.push("W aktywnej karcie nie widzę formularza KRZ. Otwórz wzór " + pismo.wzor + " w Portalu Użytkowników Zarejestrowanych.");
    else if (form.code !== pismo.wzor) lines.push("Otwarty wzór " + form.code + " nie zgadza się z pismem (" + pismo.wzor + ").");
    else lines.push("Rozpoznano wzór " + (form.version || form.code) + " (" + form.how + "). Wypełnianie pól będzie dostępne po zmapowaniu formularza na żywym portalu.");
    el("pismaOut").textContent = lines.join("\n");
  });

  load();
})();
