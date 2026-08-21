# DUiR 1.8.4 — kolejność wdrożenia

## 1. Wtyczka Chrome — na KAŻDYM komputerze

1. Zamknij automatyczne karty KRZ/MSiG, jeśli właśnie trwa sprawdzanie.
2. Rozpakuj `DUiR_WTYCZKA_CHROME_1.8.4.zip`.
3. Skopiuj **całą zawartość** rozpakowanego folderu do folderu, z którego Chrome
   ma już załadowaną wtyczkę DUiR. Zastąp wszystkie stare pliki. Nie zmieniaj
   samej lokalizacji tego folderu — dzięki temu pozostaną zapisane ustawienia i token.
4. Otwórz `chrome://extensions`, włącz „Tryb dewelopera” i kliknij ikonę
   „Odśwież” przy wtyczce DUiR.
5. Otwórz okno wtyczki i sprawdź, czy pokazuje wersję **1.8.4**.

Powtórz te czynności na każdym komputerze używanym do monitoringu.

## 2. Hosting — po uruchomieniu 1.8.4 przynajmniej na jednym komputerze

Rozpakuj `DUiR_HOSTING_1.8.4.zip` i wgraj na hosting jego zawartość z zachowaniem
ścieżek. Zastępowane są tylko dwa pliki:

- `app/Controllers/KrzApiController.php`
- `app/Controllers/MsigApiController.php`

Nie zastępuj całego folderu `app` i niczego z niego nie usuwaj. Ta aktualizacja
sprawia, że serwer nie wyda zadania komputerowi ze starą wtyczką 1.8.0–1.8.3.

## 3. Kontrola

W DUiR otwórz kartę „RAFAŁ MUCHARSKI Electro Hard” i kliknij „Sprawdź teraz”.
Wynik KRZ powinien zakończyć się realnym wpisem albo potwierdzonym brakiem nowych
wpisów — bez komunikatu „ramka formularza nie zgłosiła wyniku”.

Stary błąd z raportu z 17.07 pozostanie w historii jako zapis poprzedniego
przebiegu; nowy przebieg powinien pojawić się nad nim.
