# Architektura

Aplikacja składa się z czterech warstw:

1. **PHP web app** — panel, routing, endpointy API, raporty, e-mail.
2. **MySQL** — trwały stan podmiotów, zdarzeń, sprawdzeń i zadań KRZ.
3. **Wtyczka Chrome** — wykonuje odczyt KRZ w realnej sesji użytkownika i odsyła tekst do backendu.
4. **Źródła zewnętrzne** — KRS API, LLM API (podsumowania). MSiG NIE korzysta z żadnego płatnego API — wyłącznie z darmowego, oficjalnego portalu przez wtyczkę Chrome.

## Przepływ sprawdzenia

1. Użytkownik klika „Sprawdź teraz”.
2. Backend odpytuje KRS.
3. Backend tworzy zadania KRZ i MSiG dla wtyczki Chrome.
4. Wtyczka pobiera zadania z `/api/krz/worklist` i `/api/msig/worklist`.
5. Wtyczka otwiera KRZ/MSiG, wyszukuje podmiot, schodzi do treści (obwieszczenia/postanowienia w KRZ, szczegóły ogłoszeń w MSiG) i odsyła tekst do `/api/krz/ingest` lub `/api/msig/ingest`.
6. Backend klasyfikuje treść, tworzy zdarzenia i aktualizuje status.
7. Raport PDF/e-mail korzysta z tabeli `events`.
