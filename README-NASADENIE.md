# Admin Hub — serverové nahrávanie dokumentov na hosting

## Čo riešenie robí
- Dokumenty sa posielajú cez PHP endpoint `api/documents.php` na hosting.
- Binárne súbory sa ukladajú do `admin-hub-storage/files/`.
- Zoznam a metadáta sa ukladajú do `admin-hub-storage/metadata.json`.
- Rovnaký zoznam sa dá načítať z iného počítača po zadaní tímového hesla úložiska.
- Podporované sú nahrávanie, zoznam, sťahovanie, prepis rovnako pomenovaného súboru ako nová verzia, obnova verzie a mazanie.
- Limit súboru je 15 MiB. Povolené typy sú uvedené v `api/config.php`.

## Nasadenie
1. Zálohuj aktuálny Admin Hub na hostingu.
2. Nahraj `index.html` do vyhradenej zložky aplikácie, napr. `/public_html/admin-hub/`.
3. Do tej istej zložky nahraj celý priečinok `api/` a `admin-hub-storage/`.
4. Hosting musí podporovať PHP 8.0+ a povoliť zapisovanie PHP procesu do `admin-hub-storage/`.
5. Otvor `api/config.php`. Nahraď `CHANGE-THIS-TO-A-RANDOM-SECRET-AT-LEAST-40-CHARS` vlastným náhodným tímovým heslom s aspoň 40 znakmi. Nepoužívaj heslo do Supabase ani iný používaný secret.
6. Počas nastavenia nastav zložke `admin-hub-storage/` práva tak, aby do nej mohol zapisovať PHP proces, ale nedávaj jej verejné práva na zápis. Typicky 750 alebo 770 podľa nastavenia hostingu.
7. `admin-hub-storage/.htaccess` zakazuje priamy HTTP prístup k súborom pri Apache. Ak hosting používa Nginx alebo `.htaccess` ignoruje, presuň `admin-hub-storage` mimo verejného webrootu alebo požiadaj hosting o zákaz priameho prístupu.
8. Otvor `index.html` cez HTTPS, prejdi do Dokumenty a klikni „Pripojiť / obnoviť hosting“. Zadaj rovnaké tímové heslo, ktoré si nastavil v `api/config.php`.
9. Nahraj malý testovací TXT/PDF súbor; potom otvor aplikáciu v inom prehliadači/počítači, zadaj tímové heslo a stlač „Pripojiť / obnoviť hosting“.

## Kritické bezpečnostné poznámky
- Nepoužívaj tento endpoint bez vlastného silného tímového hesla.
- Endpoint používa spoločné tímové heslo. Súčasná stará verzia Admin Hubu autentifikuje používateľov lokálne v prehliadači; preto `authorId`/meno nahrávateľa poskytuje klient a nejde o kryptograficky overený audit. Pre produkčné riadenie prístupu je potrebné nahradiť staré lokálne prihlasovanie serverovo overenou Supabase Auth identitou a overovať JWT na serveri.
- Kľúč úložiska sa v tejto karte uchováva v `sessionStorage`, nie v HTML súbore. Zadaj ho iba cez HTTPS a nezdieľaj ho verejne.
- Tento PHP endpoint neposiela súbory do Supabase Storage. Fyzicky ich ukladá do samostatného priečinka na webhostingu.
- Zmena alebo zmazanie dokumentov na hostingu neovplyvní staré lokálne DEMO dokumenty a ich IndexedDB dáta.

## Ak upload zlyhá
- `404`: priečinok `api/` nie je na správnej ceste vedľa stránky.
- `503`: nebolo zmenené tímové heslo v `api/config.php`.
- `401`: zadané tímové heslo sa nezhoduje.
- `500` s právami priečinka: povoľ PHP používateľovi zapisovať do `admin-hub-storage/`.
- HTML namiesto JSON/PHP chyby: hosting nemusí vykonávať PHP; kontaktuj poskytovateľa.
- Ak je súbor väčší než PHP `upload_max_filesize` alebo `post_max_size`, uprav limity hostingu (oba musia byť aspoň 15M, prakticky 20M).
