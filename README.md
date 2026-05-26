# Prestashop DB Cleaner

**Prestashop DB Cleaner** je bezplatný modul pre PrestaShop, ktorý pomáha bezpečne zmenšiť databázu eshopu odstránením starých štatistických záznamov a voliteľným vyčistením cache tabuľky pre faceted/layered navigation.

Repozitár: https://github.com/JOO-INTERNET-MEDIA-LTD/jooim_dbcleaner  
Autor: JOO INTERNET MEDIA LTD  
Modul na FTP: `jooim_dbcleaner`  
Verzia: `1.00.00`

## Čo modul robí

Modul čistí najmä staré štatistiky návštevnosti, ktoré v mnohých PrestaShop obchodoch zbytočne zväčšujú databázu:

- maže staré záznamy z `ps_connections`,
- maže súvisiace záznamy z `ps_connections_source`,
- maže súvisiace záznamy z `ps_connections_page`,
- vie pred zmazaním uložiť jednoduché denné súhrny zdrojov návštevnosti,
- voliteľne čistí tabuľku `ps_layered_filter_block`,
- zobrazuje približnú veľkosť databázy,
- zapisuje log posledných behov čistenia,
- poskytuje CLI aj HTTP cron s tokenom.

## Čo modul nerobí

Zámerne nie sú zahrnuté rizikové alebo príliš agresívne operácie:

- nespúšťa automaticky `OPTIMIZE TABLE`,
- nemaže `ps_layered_price_index`,
- nemení produkty, objednávky, zákazníkov ani kategórie,
- nemonitoruje fyzický disk servera,
- neukladá IP adresy, celé referrer URL ani user-agent reťazce do vlastných súhrnných štatistík.

## Inštalácia pre laika

1. Stiahnite ZIP súbor modulu.
2. V administrácii PrestaShopu otvorte **Moduly → Správca modulov**.
3. Kliknite na **Nahrať modul**.
4. Vyberte ZIP súbor `jooim_dbcleaner.zip`.
5. Po nahratí kliknite na **Inštalovať**.
6. Otvorte nastavenia modulu.
7. Skontrolujte základné hodnoty a uložte nastavenia.
8. Skopírujte cron príkaz alebo HTTP cron URL z nastavení modulu.
9. Nastavte cron na hostingu.
10. Po prvom behu skontrolujte logy modulu a veľkosť databázy.

## Odporúčané prvé nastavenie

Pre veľkú databázu nezačínajte agresívne. Najprv modul otestujte bezpečne:

- **Enable cleanup:** Áno
- **Retention days for connection statistics:** 60
- **Batch size:** 1000
- **Maximum batches per run:** 5
- **Clear ps_layered_filter_block:** Nie pri prvom teste, zapnúť až po overení základného čistenia
- **Aggregate traffic source stats before deletion:** Áno, ak chcete zachovať jednoduchý prehľad zdrojov návštevnosti
- **Aggregated stats retention days:** 730
- **Stale lock timeout in seconds:** 7200

Ak cron prebehne rýchlo a eshop sa nespomalí, môžete postupne zvýšiť `Batch size` na 5000 alebo 10000.

## Vysvetlenie nastavení

### Enable cleanup

Zapína alebo vypína samotné čistenie. Keď je vypnuté, modul môže zostať nainštalovaný, ale cron ani manuálne spustenie nebudú mazať staré štatistiky.

### Retention days for connection statistics

Počet dní, počas ktorých sa majú zachovať pôvodné PrestaShop štatistiky návštevnosti. Napríklad hodnota `60` znamená, že modul ponechá posledných 60 dní a staršie záznamy zmaže.

### Batch size

Počet hlavných záznamov z `ps_connections`, ktoré sa môžu spracovať v jednej dávke. Menšia hodnota je bezpečnejšia, ale čistenie trvá dlhšie. Väčšia hodnota čistí rýchlejšie, ale môže viac zaťažiť databázu.

### Maximum batches per run

Koľko dávok môže modul vykonať počas jedného spustenia cronu. Približný objem práce za jeden beh je `Batch size × Maximum batches per run`.

### Clear ps_layered_filter_block

Voliteľne vyprázdni cache tabuľku `ps_layered_filter_block`. Toto nemaže produkty, kategórie ani cenový index. Cache si PrestaShop alebo modul faceted search následne vytvorí znova.

### Aggregate traffic source stats before deletion

Pred zmazaním starých záznamov uloží denný súhrn zdrojov návštevnosti. Ide o úsporný prehľad, nie plnú analytiku.

### Aggregated stats retention days

Určuje, ako dlho sa majú uchovávať denné súhrny v tabuľke modulu `ps_jooim_dbcleaner_traffic_daily`.

### Stale lock timeout in seconds

Ochrana proti tomu, aby naraz bežali dve čistenia. Ak predchádzajúci beh zostane zablokovaný, po tomto čase sa lock považuje za neplatný.

## Cron

Odporúčaný je CLI cron, pretože je stabilnejší pri väčších databázach:

```bash
php /cesta/k/prestashop/modules/jooim_dbcleaner/cron.php --token=TOKEN
```

HTTP cron je len náhradná možnosť:

```text
https://vas-eshop.sk/module/jooim_dbcleaner/cron?token=TOKEN
```

Token nájdete v nastaveniach modulu. Token nezverejňujte.

## Frontend odkaz na Joobox

Modul zobrazuje vo footeri nenápadný odkaz:

```text
Prestashop modul od joobox.eu
```

Text `Prestashop modul od` je preložiteľný. Text `joobox.eu` je pevný a odkazuje na `https://joobox.eu`.

Modul sa najprv pokúsi použiť hook `displayFooterAfter`. Ak tento hook v danej inštalácii PrestaShopu neexistuje, použije `displayFooter`.

Ak už iný modul `jooim_` zobrazil Joobox odkaz vo footeri, tento modul ďalší duplicitný odkaz nezobrazí.

## Licencia

Použitie modulu sa riadi súborom [LICENSE](LICENSE). Modul je bezplatný, ale podmienkou používania je ponechanie viditeľného frontend odkazu na `https://joobox.eu` tak, ako ho modul štandardne zobrazuje.

