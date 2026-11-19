<?php
// Zbiór wzorcowy dopasowania treści portalu (KRZ/MSiG) do monitorowanego podmiotu.
// Wszystkie nazwy, numery i teksty są fikcyjne. Format: [opis, podmiot, tekst strony, czy ma pasować].
return [
    // Osoby fizyczne
    ['osoba: imię i nazwisko', ['type'=>'natural_person','name'=>'Jan Kowalski'], 'Dłużnik: Jan Kowalski, zam. Kraków. Otwarto postępowanie upadłościowe.', true],
    ['osoba: kolejność nazwisko imię', ['type'=>'natural_person','name'=>'Jan Kowalski'], 'Upadły: KOWALSKI JAN, sygn. KR1K/GU/10/2026', true],
    ['osoba: odmiana (dopełniacz)', ['type'=>'natural_person','name'=>'Jan Kowalski'], 'ogłasza upadłość Jana Kowalskiego jako konsumenta', true],
    ['osoba: odmiana z ruchomym e', ['type'=>'natural_person','name'=>'Paweł Wróbel'], 'w sprawie z wniosku Pawła Wróbla o ogłoszenie upadłości', true],
    ['osoba: nazwisko złożone', ['type'=>'natural_person','name'=>'Anna Nowak-Zielińska'], 'Dłużniczka: Anna Nowak-Zielińska', true],
    ['osoba: nazwisko złożone w odmianie', ['type'=>'natural_person','name'=>'Anna Nowak-Zielińska'], 'upadłość Anny Nowak-Zielińskiej', true],
    ['osoba: drugie imię na stronie', ['type'=>'natural_person','name'=>'Jan Kowalski'], 'Dłużnik: Jan Adam Kowalski', true],
    ['osoba: Nowak vs Nowakowski', ['type'=>'natural_person','name'=>'Jan Nowak'], 'Dłużnik: Jan Nowakowski, Warszawa', false],
    ['osoba: Kowalski vs Kowalska', ['type'=>'natural_person','name'=>'Jan Kowalski'], 'Dłużniczka: Jan Kowalska', false],
    ['osoba: Kowalska vs Kowalski', ['type'=>'natural_person','name'=>'Anna Kowalska'], 'Dłużnik: Anna Kowalski', false],
    ['osoba: inne imię', ['type'=>'natural_person','name'=>'Jan Kowalski'], 'Dłużnik: Janusz Kowalski', false],
    ['osoba: imię i nazwisko daleko od siebie', ['type'=>'natural_person','name'=>'Jan Kowalski'], 'Syndyk: Jan Malinowski. Dłużnik: Piotr Kowalski.', false],
    ['osoba: nazwisko złożone, strona tylko z pierwszym członem', ['type'=>'natural_person','name'=>'Anna Nowak-Zielińska'], 'Dłużniczka: Anna Nowak', false],
    ['osoba: Kowalski vs Kowalczyk', ['type'=>'natural_person','name'=>'Jan Kowalski'], 'Dłużnik: Jan Kowalczyk', false],
    ['osoba: PESEL', ['type'=>'natural_person','name'=>'Jan Kowalski','pesel'=>'80010112345'], 'PESEL 80010112345, Kowalski', true],

    // JDG
    ['JDG: imię nazwisko na stronie', ['type'=>'business_person','name'=>'Jan Kowalski Usługi Remontowe JANBUD'], 'Dłużnik: Jan Kowalski prowadzący działalność gospodarczą', true],
    ['JDG: sama firma na stronie', ['type'=>'business_person','name'=>'Jan Kowalski Usługi Remontowe JANBUD'], 'JANBUD Usługi Remontowe Jan Kowalski — postanowienie', true],
    ['JDG: obca osoba o podobnym nazwisku', ['type'=>'business_person','name'=>'Jan Kowalski Usługi Remontowe JANBUD'], 'Dłużnik: Jan Kowalskiewicz, Usługi Transportowe', false],
    ['JDG: NIP z kreskami', ['type'=>'business_person','name'=>'Jan Kowalski JANBUD','nip'=>'6821752158'], 'NIP: 682-175-21-58', true],
    ['JDG: obce słowa rozrzucone po stronie', ['type'=>'business_person','name'=>'Ewa Malinowska Remonty Dachów'], 'Malinowska ulica. Kierownik: Ewa Wiśniewska. Remonty dachów w ofercie konkurencji.', false],

    // Spółki
    ['sp. z o.o.: pełna nazwa', ['type'=>'company','name'=>'Meble Biurowe Kraków sp. z o.o.'], 'Dłużnik: MEBLE BIUROWE KRAKÓW SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', true],
    ['S.A.: pełna nazwa', ['type'=>'company','name'=>'Zakłady Drzewne Borówka S.A.'], 'Zakłady Drzewne Borówka Spółka Akcyjna w restrukturyzacji', true],
    ['s.c.: wspólnicy w nazwie', ['type'=>'company','name'=>'Hurtownia Owocowa Malina s.c.'], 'Hurtownia Owocowa Malina s.c. Jan Kowalski, Anna Nowak', true],
    ['spółka: słowo jako część dłuższego', ['type'=>'company','name'=>'Meble Biurowe Kraków sp. z o.o.'], 'Dłużnik: Meblex Biurowetech Krakowiak sp. z o.o.', false],
    ['spółka: słowa rozrzucone po stronie', ['type'=>'company','name'=>'Meble Biurowe Kraków sp. z o.o.'], 'Sąd Rejonowy dla Krakowa. '.str_repeat('Treść ogłoszenia o innym podmiocie. ', 4).'Sprzedam meble. '.str_repeat('Dalsza treść. ', 4).'Wyposażenie biurowe.', false],
    ['spółka: podobna nazwa innej spółki', ['type'=>'company','name'=>'Zakłady Drzewne Borówka S.A.'], 'Zakłady Mięsne Borówka-Pol S.A.', false],

    // Identyfikatory
    ['KRS z zerami na stronie', ['type'=>'company','name'=>'ABC','krs'=>'0000123456'], 'KRS 0000123456', true],
    ['KRS bez zer na stronie', ['type'=>'company','name'=>'ABC','krs'=>'0000888175'], 'Numer KRS: 888175, sygn. KR1K/GR/5/2026', true],
    ['KRS w karcie bez zer, na stronie z zerami', ['type'=>'company','name'=>'ABC','krs'=>'888175'], 'KRS 0000888175', true],
    ['KRS obcy, krótszy', ['type'=>'company','name'=>'ABC','krs'=>'0000888175'], 'KRS 0000088817, data 2026-01-05', false],
    ['NIP spółki z kreskami', ['type'=>'company','name'=>'ABC','nip'=>'6821752158'], 'NIP 682-17-52-158', true],
];
