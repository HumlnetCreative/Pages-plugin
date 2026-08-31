# Kuchařka pro nový web nad Pages Builderem

Tento postup je určený pro menší firemní weby, které sdílejí `HumlnetCreative.Pages`, ale mají vlastní obsahové typy, importy a integrace. Cílem je udržet Pages obecný a vše specifické pro klienta umístit do projektového pluginu a tématu.

## 1. Vstupní informace

Před implementací si potvrďte:

- přesný název firmy, IČO a další povinné údaje;
- sídlo, provozovnu, telefon, e-mail a případná pravidla návštěv;
- hlavní obchodní prioritu webu a pořadí položek navigace;
- rozsah přenášeného obsahu a explicitní výluky;
- zda se přenáší jen aktuálně publikovaný obsah;
- nabídky, dostupnost, ceny a přesné znění režimu DPH;
- požadované sekce, formuláře a kontaktní akce (`tel:` a `mailto:`);
- vlastnictví fotografií, oprávnění k použití materiálů výrobce a úpravy vlastních fotografií;
- logo, dostupné formáty, barevnou paletu a požadovaný charakter nového vzhledu;
- seznam starých URL, jejich nové cíle a pravidla pro 301 přesměrování;
- požadavky na titulky, popisy, canonical URL a sitemapu.

U starého webu vytvořte inventuru z aktuální navigace, sitemap, CMS a přístupných URL. Data z vyhledávačů používejte jen k dohledání historických adres, ne jako zdroj nepublikovaného obsahu.

## 2. Rozdělení odpovědnosti

| Vrstva | Odpovědnost |
| --- | --- |
| `HumlnetCreative.Pages` | Obecný builder, publikace a revize, média, menu typ Builder Page, obecná rozšiřovací rozhraní a životní cyklus URL. |
| Projektový plugin | Doménové modely nebo Tailor blueprinty, import a seed, vlastní typy sekcí, jejich pole a validace, napojení SEO a migrace redirectů. |
| Téma | Twig/HTML vykreslení, responzivní layout, design tokeny, poměry obrázků a projektové CSS. |
| `RainLab.Pages` | Statická menu a Page Finder; položky mají odkazovat na typ `builder-page`, ne na ručně zapsanou absolutní cestu. |
| `Vdlp.Redirect` | 301 přesměrování starých URL a přesměrování vzniklá změnou publikované URL. |
| `Initbiz.SeoStorm` | Meta pole a dynamické položky sitemap pro projektové modely. |

Do Pages nepřidávejte pole typu `product`, `vehicle` nebo `catalog_kind`. Pokud je význam pole omezený na jediný web, patří do projektového pluginu. Konfigurace tématu `config/page-builder.php` má měnit prezentační kontrakt obecných sekcí; nový doménový typ sekce má registrovat plugin.

## 3. Příprava prostředí

Nejdříve ověřte binární soubor PHP, verzi a rozšíření:

```bash
command -v php
php -v
php -m
composer check-platform-reqs
```

Potom doplňte `.env`: October CMS licenci, aplikační URL, databázi, mail a prostředí. `.env` necommitujte. Po instalaci spusťte migrace a zkontrolujte backend i frontend. Chyba dynamické knihovny Homebrew znamená nefunkční binární soubor PHP, ne chybu projektu; opravte `PATH` nebo instalaci dané verze před další diagnostikou.

## 4. Model obsahu a opakovatelný import

Samostatné stránky spravujte v Pages Builderu. Opakované položky, například nabídku výrobků, dejte do Tailor blueprintu nebo projektového modelu. U katalogu typicky potřebujete:

- název a slug;
- druh položky a stav dostupnosti;
- cenu jako zobrazovaný text, pokud je význam DPH součástí původního sdělení;
- krátký a úplný popis;
- hlavní obrázek a galerii;
- pořadí a stav publikace.

Import musí být idempotentní: opakované spuštění aktualizuje známé záznamy a nevytváří duplicity. Stránku nestačí uložit jako pracovní kopii; seed ji musí publikovat přes `PagePublicationService`, jinak na frontendu nebude existovat publikovaná revize. Menu zakládejte až po stránkách, protože položka `builder-page` odkazuje na konkrétní záznam.

## 5. Vlastní sekce Builderu

Projektový plugin může rozšířit Pages třemi událostmi:

```php
Event::listen('humlnetcreative.pages.extendSectionDefinitions', function (): array {
    return [
        'catalog' => [
            'label' => 'Katalog',
            'permission' => 'humlnetcreative.pages.section.catalog',
            'items' => false,
            'category' => 'project',
            'section_fields' => ['heading', 'catalog_options'],
            'section_style_fields' => ['heading'],
        ],
    ];
});

Event::listen('humlnetcreative.pages.extendSectionForm', function ($widget, string $type): ?array {
    if ($type !== 'catalog') {
        return null;
    }

    $fields = Yaml::parseFile(__DIR__.'/models/section/fields.yaml');
    $widget->addTabFields($fields);

    return [
        'field_groups' => ['catalog_options' => array_keys($fields)],
        'specialized_fields' => array_keys($fields),
    ];
});

Event::listen('humlnetcreative.pages.validateSection', function (Section $section): void {
    if ($section->type === 'catalog') {
        // Doménová validace content[...] patří sem.
    }
});
```

Definice určuje dostupnost v builderu a výchozí hodnoty, YAML backendová pole a validátor povolené hodnoty. Téma doplní `partials/page-builder/catalog.htm`; pokud partial neexistuje, vlastní sekce nemá být považována za dokončenou.

## 6. Responzivní obraz a média

Pro každý typ vizuálu definujte očekávaný poměr stran a chování `contain`/`cover`. Hero má mít samostatný desktopový a mobilní slot nebo bezpečný bod ořezu. Samotné `object-fit: cover` neopraví zdrojový obrázek s bílým plátnem nebo nevhodnou kompozicí.

Fotografie výrobce ponechte věrné schválenému zdroji. Vlastní fotografie lze dávkově upravit, ale původní soubor archivujte a automatické vyvážení bílé, expozici, ořez a doostření vizuálně zkontrolujte. U katalogu umožněte editorovi nastavit počet sloupců a poměr obrázku; mobilní rozvržení musí zůstat použitelné bez horizontálního posuvu.

## 7. URL, menu a redirecty

Odkazy generujte přes CMS/Page Finder nebo URL helper. Nepište natvrdo `/nabidka`, pokud aplikace může běžet v podadresáři; stejný princip platí pro breadcrumbs, CTA a assety.

Před migrací vytvořte tabulku `stará URL → nový cíl → stav`. Staré adresy převeďte v `Vdlp.Redirect` jako přesné 301 redirecty. Více zaniklých produktových adres může směřovat na relevantní přehled nabídky, pokud to klient výslovně schválí. Neprovádějte plošný redirect všech 404 na homepage: skrývá chyby, zhoršuje relevanci a komplikuje diagnostiku.

Pages přes `RedirectManagerInterface` řeší redirect vzniklý změnou nebo odstraněním publikované Builder Page. Hromadný import historických URL patří do projektového seedu nebo migrace a musí být opakovatelný.

## 8. SEO Storm a sitemap

Projektový plugin registruje jako Stormed model katalog i `BuilderPage`, pokud je SEO Storm součástí projektu. Scope sitemap má vracet jen publikované stránky s publikovanou revizí a obvykle vynechat homepage, která je v sitemapě uvedena samostatně.

Pozor na dynamickou CMS URL s wildcard parametrem `/:fullslug*`: běžná náhrada parametrů v sitemap pluginu nemusí wildcard zpracovat a může vyrobit chybnou cestu, například `/default`. Pro jednostupňové stránky použijte `/:fullslug`; pro skutečně vnořené slugs doplňte vlastní generátor nebo ověřené rozšíření SEO Storm.

Po změně zdrojů vynuceně přegenerujte sitemapu a zkontrolujte výsledné URL, ne pouze úspěšný návrat příkazu:

```bash
php artisan sitemap:refresh --force
```

## 9. Kontrolní scénáře

Před předáním ověřte:

- homepage, každou Builder Page, detail nabídky a neexistující URL;
- navigaci, aktivní položku, hover stav, breadcrumbs, `tel:` a `mailto:`;
- desktop, tablet a mobil; hero, mřížky, dlouhé ceny a texty bez overflow;
- že jsou vidět jen schválené typy a aktuálně publikované položky;
- právní název, sídlo, provozovnu, kontakty a přesné znění ceny/DPH;
- že backend formulář vlastního typu obsahuje jen jeho pole a data po uložení zůstanou zachována;
- publikaci, koncept, návrat k revizi a změnu slugu;
- všechny importované 301 redirecty včetně řetězení a cílových 200 odpovědí;
- sitemapu bez duplicit, homepage navíc nebo zástupných hodnot typu `/default`;
- konzoli prohlížeče, načítání backend CSS/JS a aplikační log.

U změn sdíleného Pages pluginu spusťte jeho testy. U projektového pluginu přidejte alespoň test definice sekce, povolených hodnot validace a idempotence seedu.

## 10. Časté chyby a doporučené pořadí

Pracujte v pořadí: audit → datový model → plugin → publikovaný seed → téma → menu → redirecty → SEO → responzivní a obsahová kontrola. Tím se minimalizuje přepisování odkazů a vzhledu nad nestabilními daty.

Nejčastější chyby jsou doménová logika ve sdíleném pluginu, absolutní odkazy nefungující v podadresáři, vytvořená stránka bez publikované revize, ne-idempotentní seed po částečném selhání, stale cache sitemap, YAML popisky s neescapovanou dvojtečkou a záměna typu uploadovaného souboru očekávaného MediaService. Každou z nich je levnější zachytit samostatnou kontrolou ihned po příslušné fázi.

### Typické varianty

- **Servisní web s malým katalogem:** servis dejte do homepage a navigace před nabídku; katalog zůstane projektovým typem sekce nad několika záznamy.
- **Obsahový web bez katalogu:** projektový plugin může obsahovat jen seed a integrace; není důvod registrovat vlastní sekci.
- **Více kategorií nabídky:** kategorie patří do datového modelu, ne do kopií Twig partialu; sekce filtruje zdroj pomocí validované volby.
- **Příslušenství odděleně:** použijte stejný model a stejnou sekci s jiným filtrem, pokud se neliší datový kontrakt.
- **Vnořené stránky:** předem otestujte Page Finder, breadcrumbs, canonical URL, redirect při změně rodiče a generování sitemap.
