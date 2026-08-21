# Źródła KRS / MSiG

## KRS

Moduł KRS opiera się na publicznym API KRS Ministerstwa Sprawiedliwości, jeżeli jest dostępne w środowisku wdrożeniowym. Dla podmiotu bez KRS aplikacja może próbować ustalić KRS po NIP przez białą listę MF.

## MSiG

MSiG (Monitor Sądowy i Gospodarczy) nie ma darmowego, oficjalnego API do zapytań maszynowych. Jedyne źródło danych to darmowa, oficjalna wyszukiwarka `wyszukiwarka-msig.ms.gov.pl`, odpytywana przez wtyczkę Chrome w realnej sesji użytkownika — dokładnie tak samo jak KRZ. Odpowiada za to `chrome_extension/content_msig.js` razem z endpointami `/api/msig/*`.

Rozwiązanie świadomie NIE korzysta z żadnego płatnego, komercyjnego API do MSiG (np. iMSiG/MGBI) — ani jako źródła podstawowego, ani jako dodatkowego sygnału.

## Zasada dopasowania

Jeżeli podmiot ma KRS/NIP/REGON/PESEL, wynik po samej nazwie nie powinien być uznany za pewne dopasowanie. To chroni przed błędami typu dopasowanie po słowie „Kancelaria".
