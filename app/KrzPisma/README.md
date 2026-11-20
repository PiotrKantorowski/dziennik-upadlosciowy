# Pisma KRZ (prototyp)

Moduł przygotowuje dane pisma do Krajowego Rejestru Zadłużonych i układa je w kartę wypełnienia według metryk formularza w Portalu Użytkowników Zarejestrowanych (PUZ). Pierwszy obsługiwany wzór: **30048 – Zgłoszenie wierzytelności w postępowaniu upadłościowym**.

Moduł niczego nie składa. Pismo podpisuje i wysyła pełnomocnik w KRZ.

## Zawartość

| Plik | Rola | Zależności |
|---|---|---|
| `KrzForms.php` | model danych 30048, walidacja (art. 240 p.u., sumy kontrolne PESEL/NIP/IBAN, KRS z zerami), karta wypełnienia, podsumowanie kwot, odczyt katalogu | tylko PHP 8.1 + mbstring |
| `DraftStore.php` | zapis pism roboczych w tabeli `krz_pisma` (tworzy się sama) | PDO MySQL/MariaDB |
| `wzory.tsv` | 367 wzorów KRZ: kod, tytuł, data, kto składa (heurystyka) | — |

Warstwa widoku DUiR: `app/Controllers/KrzPismaController.php` (zakładka „Pisma KRZ”), API dla wtyczki: `KrzApiController::pismaList()` i `pismo()` pod `/api/krz/pisma[/{id}]`, token `X-KRZ-Token`.

Wtyczka: `chrome_extension/pisma_bg.js` (service worker, przez `importScripts`), `content_puz.js` (rozpoznanie wzoru i wersji w PUZ), `popup_pisma.js` (sekcja popupu). Wtyczka na razie nic nie wpisuje.

## Kontrakt danych

`KrzForms::fromPost()` zwraca tablicę, która jest zapisywana jako JSON i wysyłana do wtyczki:

```
sygnatura, upadly, tryb (pelnomocnik|samodzielnie),
wierzyciel{oznaczenie (podmiot|osoba), nazwa, imie, nazwisko, pesel, krs, nip, inne_id, forma_prawna, siedziba, kraj, kod, miejscowosc, ulica, nr_budynku, nr_lokalu, telefon, email},
reprezentanci[{imie, nazwisko, pesel, funkcja}],
pelnomocnik{rodzaj (profesjonalny|inny), do_doreczen, nazwa, imie, nazwisko, id_konta, + adres i kontakt},
wierzytelnosci[{typ (glowna|odsetki|inna), tytul, kwota, waluta, wymagalnosc, odsetki_rodzaj, odsetki_od, odsetki_do, kategoria (I–IV), zabezpieczenie, przedmiot_zabezpieczenia, stan_sprawy, dowody}],
bank, rachunek, zalaczniki (tekst, plik w wierszu)
```

`KrzForms::card()` zwraca wiersze `[metryka, pole, wartość, kto uzupełnia]` w kolejności I–VII formularza PUZ.

## Przeniesienie do LexPilota

Do LexPilota przechodzi wyłącznie ten moduł, bez monitoringu DUiR:

1. Skopiuj `KrzForms.php`, `DraftStore.php`, `wzory.tsv` i testy `tests/test_krz_pisma.php`. Zmień tylko przestrzeń nazw. Test `test_krz_pisma_is_wired_and_read_only` pilnuje, żeby pliki modułu nie importowały niczego z DUiR.
2. Widok: przepisz `KrzPismaController` na komponenty LexPilota. Logika walidacji i karty zostaje w `KrzForms`.
3. Wtyczka: przenieś `pisma_bg.js`, `content_puz.js`, `popup_pisma.js`. Z `background.js` DUiR potrzebna jest tylko funkcja `appFetch` (adres aplikacji + token). Monitoring KRZ/MSiG (`content_krz.js`, `content_msig.js`, worklisty) zostaje w DUiR.
4. Jeżeli LexPilot nie używa PHP, `KrzForms` jest wzorcem do przepisania 1:1, a testy w `tests/test_krz_pisma.php` są specyfikacją przypadków.

## Źródła i ograniczenia

- Sekcje formularza: *Instrukcja zgłaszania wierzytelności w postępowaniu upadłościowym* KRZ v1.02; mechanika PUZ: *Podręcznik PUZ* v1.12 (07.07.2026).
- Treść zgłoszenia: art. 240 p.u.; kategorie: art. 342 ust. 1 p.u.; odsetki: art. 92 p.u. (tekst jednolity Dz.U. 2024 poz. 794; nowszych t.j. 2025/2026 nie porównano).
- Pola typów wierzytelności w KRZ znane są tylko ze zrzutów ekranu instrukcji. Mapowanie na pola portalu wymaga sprawdzenia na żywym PUZ (po przerwie technicznej).
- Kolumna „kto składa” w katalogu jest przypisana automatycznie po tytule wzoru.
