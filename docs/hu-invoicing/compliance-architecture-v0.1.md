# Dolibarr 23 HU számlázás – Compliance és architektúra

**0. lépés – NAV-kompatibilis saját számlázóprogram és automatikus ismétlődő számlázás**

- Verzió: 0.1
- Dátum: 2026-09-20
- Kódbázis: `vanyolai/dolibarr`, `23.0` branch
- Érintett modul: `htdocs/custom/navinvoice` (0.8.0-development)
- Célidő: legkésőbb 2027. március; élesre kész állapot január–februárra, utána párhuzamos ellenőrzés

> Ez a dokumentum fejlesztési és megfelelőségi terv. Nem helyettesít egyedi adó- vagy jogi tanácsadást. Az éles indulás előtt a tényleges számlaképet, adózási beállításokat és speciális ügyleteket könyvelő/adótanácsadó bevonásával is ellenőrizni kell.

## 1. Vezetői összefoglaló

A Dolibarr 23 fork alkalmas arra, hogy saját magyar számlázóprogram alapja legyen. A számlamotor, a véglegesítés, a PDF-generálás, az ütemezett feladatok és az ismétlődő számlák motorja már a core-ban rendelkezésre áll. A `navinvoice` modulban ezen felül már jelentős NAV Online Számla olvasási, partner-, termék-, módosítási lánc- és XML-feldolgozási infrastruktúra készült el.

A jelenlegi `navinvoice` ugyanakkor kifejezetten **read-only NAV kliens**: `queryTaxpayer`, `queryInvoiceDigest`, `queryInvoiceData` és `queryInvoiceChainDigest` műveleteket végez, de még nem implementálja a kimenő számlaadat-szolgáltatáshoz szükséges `tokenExchange → manageInvoice → queryTransactionStatus` láncot. Emiatt a fork jelenlegi állapotában még nem tekinthető kész, éles magyar számlázási megoldásnak.

Az automatikus havi átalány-számlázás nem külön „kényelmi” fejlesztés: az első éles verzió alapkövetelménye. A Dolibarr `FactureRec` motorja már tud esedékes sablonokból számlát generálni, automatikusan véglegesíteni (`auto_validate`) és PDF-et készíteni (`generate_pdf`). A szükséges fejlesztés ezért főleg a magyar időszakos elszámolási dátumlogika, a HU compliance preflight, a NAV outbound tranzakciós réteg, az idempotens e-mail kézbesítés és a hibafelügyelet köré épül.

**Fő architekturális döntés:** külső NAV hálózati hívás nem történhet a Dolibarr számlavéglegesítési adatbázis-tranzakcióján belül. A `BILL_VALIDATE` eseményben legfeljebb statikus compliance-ellenőrzés és tranzakciós outbox rekord keletkezik. A NAV-küldés commit után fut. Ez kizárja azt a veszélyes állapotot, amikor a NAV befogadja az adatot, miközben a helyi számlavéglegesítés visszagörgetődik.

## 2. Cél és MVP-hatókör

### 2.1 Első éles verzióban kötelező

- normál belföldi vevőszámla HUF-ban;
- 27%, 18%, 5%, 0% és támogatott adómentes esetek megfelelő kódolása;
- szükség esetén pénzforgalmi elszámolás jelölése;
- időszakos elszámolású / havi átalány szolgáltatások;
- ismétlődő számlasablon → automatikus generálás → automatikus véglegesítés;
- NAV Online Számla 3.0 adatszolgáltatás, státuszlekérdezés és retry;
- végleges PDF létrehozása és változatlan példány archiválása;
- automatikus e-mail kézbesítés, egyszeri/idempotens küldési garanciával;
- módosító és érvénytelenítő számlalánc kezelése;
- „adóhatósági ellenőrzési adatszolgáltatás” export;
- magyar nyelvű, verziózott felhasználói és üzemeltetési dokumentáció;
- auditálható eseménynapló és jól látható hibastátuszok.

### 2.2 Későbbi bővítési kör

Az első éles verzió után bővíthető a támogatás például speciális EU/OSS, összetett devizás, önszámlázási, különbözeti adózási és ritka iparági esetekkel. Ezeket az MVP nem próbálja automatikusan kitalálni: nem támogatott esetben a rendszernek inkább blokkolnia kell a véglegesítést, mint bizonytalan NAV XML-t előállítania.

## 3. A fork jelenlegi állapota

| Terület | Jelenlegi állapot | Rés / teendő | Ajánlott hely |
|---|---|---|---|
| Dolibarr vevőszámla-motor | Megvan | HU preflight szükséges | `navinvoice` + minimális hook |
| Véglegesítés / számlaszám | Megvan | számozási modell audit, immutability szabályok | core konfiguráció + teszt |
| Ismétlődő számlák | Megvan (`FactureRec`) | HU dátumpolitika, idempotencia | `navinvoice` hook/service |
| Automatikus véglegesítés | Megvan (`auto_validate`) | HU preflight legyen előtte | `navinvoice` |
| PDF generálás | Megvan (`generate_pdf`) | kiállított példány hash + archiválás | `navinvoice` |
| Automatikus e-mail | Nincs teljes NAV-aware pipeline | delivery queue, státusz, retry | `navinvoice` |
| NAV technikai user auth | Részben megvan | XML cserekulcs is kell | `navinvoice/admin` |
| NAV lekérdezések | Erős, működő alap | megtartandó | `navinvoice/class` |
| `tokenExchange` | Hiányzik | implementálni | `navinvoice/class` |
| `manageInvoice` | Hiányzik | implementálni | `navinvoice/class` |
| `queryTransactionStatus` | Hiányzik | implementálni | `navinvoice/class` |
| NAV InvoiceData 3.0 builder | Hiányzik | determinisztikus builder + XSD | `navinvoice/class` |
| NAV tranzakciós állapot | Hiányzik | outbox + submission táblák | `navinvoice/sql` |
| Módosítás/storno olvasási lánc | Nagyrészt megvan | outbound mapper kell | `navinvoice` |
| Audit export | Hiányzik | kötelező funkció | `navinvoice` |
| Saját fejlesztés dokumentációja | Részben README/changelog | külön compliance/user manual | `docs/hu-invoicing` |

## 4. Jogi és működési compliance-mátrix

| Követelmény | Forrás | Megvalósítási következmény |
|---|---|---|
| Kihagyás és ismétlés nélküli folyamatos számozás | 23/2014. NGM 8. § (1) a) | A választott Dolibarr számozási modellt és versenyhelyzeteket tesztelni kell; éles számlát nem lehet törléssel „visszacsinálni”. |
| A számla lezárásakor minősül kiállítottnak | 23/2014. NGM 8. § (6) | A Dolibarr `validate()` eseményt kell a kiállítási határpontnak tekinteni. |
| Beépített „adóhatósági ellenőrzési adatszolgáltatás” | 23/2014. NGM 8. §, 11/A. § | Pontosan ezen a néven, dátum- és sorszámtartományos export szükséges. |
| NAV adatszolgáltatás azonnal, XML-ben | 23/2014. NGM 13/A. § (1) | A véglegesítésből automatikusan kimenő outbox tétel keletkezik; post-commit dispatch azonnal indul. |
| Csak NAV sikeres feldolgozási visszaigazolásával teljesített | 23/2014. NGM 13/A. § (3) | `transactionId` önmagában nem „OK”; státuszpolling kötelező. |
| Hibás feldolgozás után ismétlés | 23/2014. NGM 13/A. § (4)–(7) | Retry, operátori feladat és 3 munkanapos eszkaláció szükséges. |
| NAV/internet kiesés után 24 órás pótlás | 23/2014. NGM 13/B. § | Tartós queue, retry és outage monitor szükséges. |
| Saját rendszer 48 órán túli kiesése | 23/2014. NGM 13/B. § (5) | Riasztás és dokumentált manuális vészfolyamat kell. |
| Saját fejlesztésű számlázó dokumentációja | 23/2014. NGM 10. § | Verziózott magyar dokumentáció és módosításnapló szükséges. |
| Kötelező számlaadatok | Áfa tv. 169. § | HU preflight ellenőrizze az eladót, vevőt, adószámot, dátumokat, tételeket, adóalapot, ÁFÁ-t és kötelező szövegeket. |
| Időszakos elszámolás teljesítési dátuma | Áfa tv. 58. § | Külön `HuPeriodicalSettlementCalculator` kell; nem másolható vakon az előző hónap dátuma. |
| Számlakibocsátási határidő | Áfa tv. 163. § | A scheduler és a blokk/escalation figyelje a jogszabályi határidőt is. |
| Elektronikus számla integritása/olvashatósága | Áfa tv. 168/A., 175. § | Kiállított PDF/XML változatlan archiválása, hash, audit trail és befogadói beleegyezés kezelése szükséges. |
| Módosító okirat hivatkozása | Áfa tv. 170. § | Eredeti számla és módosítási lánc explicit kapcsolatban marad. |

## 5. Célarchitektúra

```text
                     DOLIBARR FACTURE
                           │
                 draft / recurring template
                           │
                           ▼
               HU COMPLIANCE PREFLIGHT
        partner • adószám • dátumok • ÁFA • speciális jelölések
                           │
                    BLOCK ─┴─ OK
                             │
                             ▼
                    Facture::validate()
                  számlaszám + lezárás
                             │
                ugyanabban a DB tranzakcióban
                             ▼
                    TRANSACTIONAL OUTBOX
                             │
                           COMMIT
                             │
          ┌──────────────────┼──────────────────┐
          ▼                  ▼                  ▼
      NAV BUILDER       PDF ARCHIVE        DELIVERY QUEUE
          │                  │                  │
       XSD 3.0          SHA-256 hash          e-mail
          │                                     │
          ▼                                     ▼
     tokenExchange                           SENT/ERROR
          │
          ▼
     manageInvoice
          │
       transactionId
          │
          ▼
 queryTransactionStatus
          │
   ┌──────┼────────┐
   ▼      ▼        ▼
 DONE   WARN     ERROR
                  │
            retry/escalation
```

### 5.1 Komponensek

**`NavInvoiceCompliancePreflight`** – minden véglegesítés előtt futó, hálózattól független ellenőrzés. Csak determinisztikus, helyi adatokkal dolgozik, ezért biztonságosan képes blokkolni a számlavéglegesítést.

**`HuPeriodicalSettlementCalculator`** – az elszámolási időszak, számlakelte, fizetési határidő és Áfa tv. 58. § szerinti teljesítési dátum determinisztikus kiszámítása. Külön tesztkészletet kap havi, hóvégi, szökőéves, előre és utólag számlázott esetekre.

**`NavInvoiceDataBuilder`** – Dolibarr `Facture` → NAV InvoiceData 3.0 XML leképezés. Nem UI-ról olvas, hanem a végleges, adatbázisból visszatöltött számlából dolgozik.

**`NavInvoiceSchemaValidator`** – a NAV hivatalos 3.0 XSD-k ellenőrzése még a beküldés előtt. Az XSD-verzió a modul release-éhez rögzített, frissítéskor regressziós teszt fut.

**`NavInvoiceOutboundApi`** – `tokenExchange`, `manageInvoice`, `queryTransactionStatus`; a meglévő read-only API kód közös signing/request rétegét újrahasznosítja.

**`NavInvoiceSubmissionRepository`** – idempotens outbox és NAV-tranzakciós állapot. Azonos számlát/operációt hálózati bizonytalanság miatt sem küldhet kontrollálatlanul duplán.

**`NavInvoiceDispatcher`** – commit után azonnal megpróbálja a küldést; watchdog cron a beragadt vagy újrapróbálandó tételeket is feldolgozza.

**`NavInvoiceStatusPoller`** – a NAV feldolgozási eredményt addig kérdezi le, amíg terminális állapot nem jön. WARN és ERROR üzenetek megőrződnek és megjelennek a számlán.

**`NavInvoiceDeliveryService`** – végleges PDF csatolásával e-mail küldés. Saját delivery outbox, idempotenciakulcs, címzett, sablon, Message-ID és állapot kerül naplózásra.

**`NavInvoiceAuditExport`** – a kötelező „adóhatósági ellenőrzési adatszolgáltatás” funkció implementációja.

## 6. Miért transactional outbox?

A Dolibarr `Facture::validate()` a `BILL_VALIDATE` triggert a saját tranzakcióján belül hívja meg. Ha itt közvetlenül `manageInvoice` hálózati hívást végeznénk, előállhatna:

1. NAV befogadja a számlaadatot;
2. ezután egy másik trigger vagy helyi DB-művelet hibázik;
3. Dolibarr rollbackel;
4. a NAV-ban létezik adatszolgáltatás egy helyben nem véglegesült számláról.

Ez elfogadhatatlan kettős igazságforrás lenne. A helyes minta:

- `BILL_VALIDATE`: preflight + outbox rekord létrehozása ugyanabban a DB tranzakcióban;
- rollback esetén az outbox rekord is eltűnik;
- commit után dispatcher küld NAV felé;
- a recurring workflow `afterCreationOfRecurringInvoice` hookja commit után jó természetes dispatch-pont;
- manuális véglegesítésnél post-commit alkalmazási hookot kell biztosítani a forkban; ha stock Dolibarr 23-ban erre nincs megbízható pont, egy kicsi, dokumentált core patch jobb, mint hálózati I/O a `BILL_VALIDATE` tranzakcióban;
- a watchdog cron mindig biztonsági háló marad.

## 7. Automatikus havi átalány-számlázás

### 7.1 Meglévő Dolibarr alap

A `FactureRec` már kezeli a gyakoriságot (`frequency`, `unit_frequency`), következő generálási időpontot (`date_when`), generálási limitet, felfüggesztést, automatikus véglegesítést (`auto_validate`) és PDF-készítést (`generate_pdf`). A `createRecurringInvoices()` cron-kompatibilis és az esedékes sablonokat feldolgozza.

Ezért nem új recurring engine-t kell írni; a meglévőt kell **HU-biztossá** tenni.

### 7.2 Javasolt sablon-konfiguráció

Egy havi átalány sablonhoz legalább a következő üzleti paramétereket kell rögzíteni:

- elszámolási periódus szabálya: tárgyhó / következő hó / előző hó;
- prepaid vagy postpaid működés;
- generálás napja;
- fizetési határidő szabálya (például +8 nap);
- automatikus véglegesítés igen/nem;
- automatikus PDF igen/nem;
- automatikus e-mail igen/nem;
- e-mail sablon és címzettképzési szabály;
- nem támogatott vagy hiányos partneradat esetén: **blokkolás**, nem automatikus találgatás.

Ahol lehet, ezeket `FactureRec` extrafieldként vagy a meglévő mezőkön tároljuk, hogy ne forkosítsuk szükségtelenül a core adatbázissémát.

### 7.3 Havi automata futás

```text
Esedékes FactureRec sablon
       │
       ▼
Számlaperiódus kiszámítása
       │
       ▼
HU dátumkalkuláció (Áfa tv. 58. §)
       │
       ▼
Draft előállítás + HU preflight
       │
  hiba ├────────► BLOCKED + értesítés
       │OK
       ▼
Automatikus validate
       │
       ▼
Outbox + commit
       │
       ├────────► végleges PDF + hash
       ├────────► NAV azonnali küldés
       └────────► e-mail delivery queue
                         │
                         ▼
                  SENT / ERROR + retry
```

### 7.4 Dátumlogika: a legfontosabb recurring-specifikus rész

Az Áfa tv. 58. § szerint időszakos elszámolásnál a teljesítés főszabály szerint az érintett időszak utolsó napja. Ettől eltérően, ha a számla kibocsátása és a fizetési esedékesség is az időszak vége előtt van, a teljesítés a számla/nyugta kibocsátási időpontja; ha a fizetési esedékesség az időszak vége utánra esik, a teljesítés az esedékesség, de legfeljebb az időszak végét követő 60. nap.

Ebből következik, hogy a rendszernek **nem szabad** egyetlen „teljesítési nap = hónap utolsó napja” szabályt minden havi átalányra ráhúznia. A teljesítési dátumot a tényleges period + issue date + due date kombinációjából kell számítani, és a generálás előtt tesztelhetővé kell tenni.

### 7.5 Idempotencia

A recurring futás idempotenciakulcsa legalább:

`entity + facture_rec_id + billing_period_start + billing_period_end`

Ugyanerre a periódusra automatikus újrafutás nem hozhat létre második számlát. Hiba után a meglévő folyamatot kell folytatni (NAV retry, e-mail retry), nem új számlát generálni.

### 7.6 E-mail küldési politika

Ajánlott alapbeállítás:

- a számla helyileg sikeresen véglegesült és a végleges PDF elkészült;
- a NAV outbox létrejött;
- a NAV első `manageInvoice` próbálkozása azonnal elindul;
- NAV szolgáltatáskiesés **nem akadályozhatja meg** egy már jogszerűen kiállított számla helyi fennmaradását;
- az e-mail küldés külön delivery queue-ból történik, így SMTP-hiba nem generál új számlát;
- ügyfelenként/sablononként konfigurálható, hogy az e-mail az első NAV technikai befogadás után vagy már a helyi véglegesítés után menjen. MVP-ben az első NAV technikai befogadás utáni küldés konzervatívabb alapértelmezés, de outage esetére szükséges dokumentált fallback.

## 8. NAV outbound adatmodell és állapotgép

### 8.1 Javasolt `llx_navinvoice_submission`

Fő mezők:

- `rowid`, `entity`, `fk_facture`;
- `invoice_ref`, `operation` (`CREATE`, `MODIFY`, `STORNO`);
- eredeti számla hivatkozása és `modification_index`;
- `idempotency_key` (UNIQUE);
- `request_id`, `transaction_id`;
- `request_xml_sha256`, opcionális archivált request/response file reference;
- `state`: `QUEUED`, `SENDING`, `SUBMITTED`, `PROCESSING`, `DONE`, `WARN`, `ERROR`, `RETRY_WAIT`, `MANUAL_ACTION`;
- `attempt_count`, `first_attempt_at`, `last_attempt_at`, `next_retry_at`;
- `nav_message_code`, `nav_message`;
- `accepted_at`, `completed_at`;
- audit `datec`, `tms`.

### 8.2 Javasolt `llx_navinvoice_delivery`

- `fk_facture`;
- `channel` (`EMAIL`);
- `recipient`;
- `template_key`;
- `attachment_sha256`;
- `idempotency_key` (UNIQUE);
- `message_id`;
- `state`: `QUEUED`, `SENDING`, `SENT`, `ERROR`, `RETRY_WAIT`;
- próbálkozási és hibainformációk.

### 8.3 Számlakártyán megjelenő állapot

A felhasználó ne logokból derítse ki, hogy egy számlával gond van. A számlakártyán külön HU/NAV panel jelenjen meg például:

- NAV: Beküldésre vár / Feldolgozás alatt / Sikeres / WARN / Hiba / Manuális beavatkozás;
- E-mail: Nem kért / Küldésre vár / Elküldve / Hiba;
- PDF: archivált hash és létrehozási idő;
- utolsó NAV próbálkozás és következő retry;
- közvetlen link a részletes technikai naplóhoz.

## 9. NAV API és biztonság

A jelenlegi modul technikai felhasználói login/password/signing key beállítását ki kell egészíteni az XML **cserekulccsal**. A kimenő API a hivatalos Online Számla 3.0 API és Data XSD-kre épüljön.

Biztonsági minimum:

- password, signing key, exchange key ne kerüljön logba;
- titkok Dolibarr sensitive constantként tárolódjanak;
- kulcsrotáció után a beállítási oldalon azonnal tesztelhető legyen a kapcsolat;
- külön jogosultság a NAV technikai beállításokhoz és a manuális retry/override műveletekhez;
- request/response archiválásból a titkokat és auth elemeket szükség szerint maszkolni kell;
- éles/test környezet státusza jól látható legyen, hogy teszt kulccsal ne lehessen véletlenül production workflow-t indítani.

## 10. NAV XML-generálás

A builder egyetlen igazságforrásból, a végleges Dolibarr számlából dolgozzon. A részletes mezőtérképet külön következő dokumentumban kell elkészíteni (`Facture/FactureLigne → InvoiceData 3.0`). Ennek legalább az alábbi adatcsoportokat kell lefednie:

- számlaazonosító, kelte és teljesítési dátuma;
- eladó és vevő azonosító/név/cím/adószám;
- számla típusa, pénzneme, fizetési mód/határidő, ahol releváns;
- tétel leírása, mennyisége, mértékegysége, egységára;
- adóalap, adómérték, adóösszeg és összesítések;
- adómentes/fordított/speciális jelölések;
- módosítási hivatkozás és módosítási index;
- a 3.0 séma által megkövetelt technikai mezők.

A NAV hivatalos repository-jában jelenleg a 3.0 namespace-ű `invoiceData.xsd` és `invoiceApi.xsd` a referencia. A modulban ezek release-hez kötött példánya vagy ellenőrzött dependency-je legyen, ne futás közben töltsük le az internetről.

## 11. PDF és elektronikus számla

A számla elektronikus vagy papíralapú lehet. Ha a PDF e-mailben az eredeti elektronikus számlaként kerül kibocsátásra, biztosítani kell az eredet hitelességét, az adattartalom sértetlenségét és az olvashatóságot, valamint a számlabefogadó beleegyezését az alkalmazandó szabályok szerint.

Ajánlott technikai kontroll:

- véglegesítés után egy „issued artifact” PDF készül;
- SHA-256 hash és fájlméret rögzül;
- ezt a példányt a rendszer később nem írja felül;
- újragenerált vizuális példány csak másolatként kezelhető, az eredeti kiállított artefact megmarad;
- az e-mail delivery rekord ugyanennek az attachment hash-nek a küldését rögzíti;
- mentés-visszaállítás próbával igazolni kell, hogy a PDF, a Dolibarr adat és a NAV submission metadata együtt visszaállítható.

## 12. Módosítás, érvénytelenítés, stornó

A meglévő `navinvoice` már erős olvasási oldali chain-kezelést tartalmaz (`CREATE/MODIFY/STORNO`, modification index, `queryInvoiceChainDigest`). Az outbound fejlesztésnél ezt kell megfordítani:

- eredeti Dolibarr számla ↔ eredeti NAV invoice number;
- módosító/credit note ↔ forrás számla;
- determinisztikus operation mapping;
- módosítási index sorfolytonossága;
- előzménylánc hiánya esetén blokkolás;
- érvénytelenítés/módosítás PDF-en és NAV XML-ben konzisztens legyen.

Nem szabad minden Dolibarr credit note-ot automatikusan NAV `STORNO`-nak tekinteni: az operationt a jogi/gazdasági jelentésből és a forráskapcsolatból kell képezni.

## 13. „Adóhatósági ellenőrzési adatszolgáltatás”

Ez külön funkció az Online Számla API-tól. A menüpont/funkció neve pontosan:

**adóhatósági ellenőrzési adatszolgáltatás**

Legalább két lekérdezési mód:

1. kezdő és záró dátum;
2. kezdő és záró számlasorszám.

A generált adatexportot az alkalmazandó rendeleti adatszerkezet szerint kell előállítani, és regressziós fixture-rel tesztelni. A funkció akkor is működjön, ha a NAV API pillanatnyilag nem elérhető.

## 14. Hibakezelés és üzemeltetés

A rendszernek a hibát nem elrejtenie, hanem állapotként kezelnie kell.

**Lokális preflight hiba:** a számla draft marad; automatikus recurring esetben `BLOCKED` feladat és értesítés keletkezik.

**NAV hálózati hiba:** a számla kiállított marad, submission `RETRY_WAIT`; exponenciális vagy kontrollált retry, 24 órás deadline figyelés.

**NAV processing ERROR:** automatikus újraküldés csak akkor, ha a hiba technikailag determinisztikusan javítható. Üzleti/tartalmi hiba operátori feladat.

**3 munkanap:** hibás adatszolgáltatás rendeleti eszkalációját külön SLA-számláló figyelje.

**Saját rendszer >48 óra kiesés:** dokumentált manuális vészforgatókönyv és riasztás.

**SMTP hiba:** csak delivery retry; számla és NAV submission változatlan.

## 15. Tesztstratégia

### 15.1 Unit tesztek

- időszakos elszámolás Áfa tv. 58. § dátumesetei;
- hóvége, február 28/29, évváltás;
- fizetési határidő +N nap;
- számlaszám-generátor konkurens véglegesítéssel;
- ÁFA-kód és speciális jelölések;
- idempotenciakulcsok;
- módosítási index és chain policy.

### 15.2 Integrációs tesztek

- NAV test `tokenExchange`;
- `manageInvoice` CREATE;
- `queryTransactionStatus` DONE/WARN/ERROR;
- MODIFY és STORNO;
- szándékosan hibás XSD és üzleti validáció;
- timeout a request előtt/közben/után;
- ugyanazon outbox job újraindítása;
- SMTP timeout és újraküldés;
- recurring cron többszöri futtatása ugyanarra a periódusra.

### 15.3 Shadow/párhuzamos ellenőrzés

A Számlázz.hu előfizetés lejárta előtt legalább 30 napig célszerű párhuzamos „shadow” ellenőrzést futtatni. Ugyanazokra az üzleti esetekre összevethető:

- számlakép és kötelező szövegek;
- teljesítési dátum;
- fizetési határidő;
- nettó/ÁFA/bruttó összegek;
- vevő adóadatai;
- NAV-ba kerülő XML-adatok;
- módosítás/storno lánc.

A Számlázz.hu itt referencia/összehasonlítási pont, nem jogforrás.

## 16. Fejlesztési mérföldkövek 2027. márciusig

| Mérföldkő | Cél | Céldátum |
|---|---|---|
| M0 | Ez a compliance + architecture dokumentum | 2026-09 |
| M1 | `Facture → NAV InvoiceData` részletes mezőtérkép, HU preflight specifikáció | 2026-10 |
| M2 | outbound auth + `tokenExchange` + XML builder + XSD validation | 2026-10/11 |
| M3 | `manageInvoice`, submission outbox, `queryTransactionStatus`, retry | 2026-11 |
| M4 | PDF immutable archive, e-mail delivery queue, számlakártya státuszpanel | 2026-12 |
| M5 | recurring HU dátumpolitika + teljes automatikus havi pipeline | 2026-12/2027-01 |
| M6 | outbound MODIFY/STORNO + kötelező audit export | 2027-01 |
| M7 | NAV tesztkörnyezet, regresszió, hibaszcenáriók | 2027-01/02 |
| M8 | shadow/párhuzamos használat, könyvelői ellenőrzés | 2027-02/03 |
| M9 | kontrollált éles átállás | legkésőbb 2027-03 |

## 17. Go-live elfogadási kritériumok

A Számlázz.hu előfizetés megszüntetése előtt minimum:

- minden MVP számlatípus tesztje zöld;
- NAV test és production dry-run konfiguráció dokumentált;
- legalább 30 nap shadow ellenőrzés érdemi eltérés nélkül;
- nincs megoldatlan NAV ERROR vagy beragadt submission;
- recurring számlázás ugyanarra a periódusra többszöri cron futás mellett sem duplikál;
- e-mail kézbesítés idempotens;
- backup + restore próbán egy kiválasztott számla PDF-je, metadata-ja és NAV státusza visszaáll;
- „adóhatósági ellenőrzési adatszolgáltatás” export tesztelve;
- felhasználói/üzemeltetési dokumentáció verziózva;
- a használt számlasablonokat és speciális adózási jelöléseket könyvelő/adótanácsadó mintaszámlákon ellenőrizte;
- visszaállási/üzemzavari eljárás dokumentálva.

## 18. Kódszervezési javaslat

A fejlesztés döntő része maradjon az önálló `navinvoice` modulban, hogy a Dolibarr core-tól való eltérés minimális legyen.

Javasolt új osztályok:

```text
htdocs/custom/navinvoice/
├── class/
│   ├── navinvoicecompliance.class.php
│   ├── huperiodicalsettlement.class.php
│   ├── navinvoicebuilder.class.php
│   ├── navinvoiceschemavalidator.class.php
│   ├── navinvoiceoutboundapi.class.php
│   ├── navinvoicesubmission.class.php
│   ├── navinvoicedispatcher.class.php
│   ├── navinvoicestatuspoller.class.php
│   ├── navinvoicedelivery.class.php
│   └── navinvoiceauditexport.class.php
├── core/triggers/
│   └── ...BILL_VALIDATE / outbox integration...
├── sql/
│   ├── llx_navinvoice_submission.sql
│   └── llx_navinvoice_delivery.sql
├── admin/
│   └── setup.php   # exchange key + outbound policy
└── docs/
    └── ...
```

A canonical fejlesztési repó továbbra is a `dolibarr-nav-online-invoice` legyen, a Dolibarr fork pedig subtree-n keresztül fogyassza. A konkrét feature branch javasolt neve: `feature/outbound-foundation`.

## 19. Következő konkrét feladat – M1

A dokumentum elfogadása után a következő artefact a **Dolibarr `Facture` / `FactureLigne` → NAV Online Számla 3.0 InvoiceData mezőtérkép** legyen. Soronként tartalmazza:

- NAV XML XPath/mező;
- kötelező/feltételes státusz;
- Dolibarr forrásmező;
- átalakítási szabály;
- validáció;
- hiány esetén BLOCK/WARN politika;
- unit/integration teszteset.

Ezzel párhuzamosan készülhet a `NavInvoiceCompliancePreflight` első implementációja, mert a mezőtérkép megmutatja, mely adatoknak kell már a véglegesítés előtt biztosan rendelkezésre állniuk.

## 20. Források és auditált kódpontok

### Jogszabályok

- **[J1]** 23/2014. (VI. 30.) NGM rendelet – különösen 8. §, 10. §, 11/A. §, 13/A–13/B. §. https://net.jogtar.hu/jogszabaly?docid=a1400023.ngm
- **[J2]** 2007. évi CXXVII. törvény az általános forgalmi adóról – különösen 58. §, 163. §, 168/A. §, 169–170. §, 174–175. §. https://net.jogtar.hu/jogszabaly?docid=a0700127.tv

### NAV technikai referencia

- **[N1]** NAV Online Invoice official repository. https://github.com/nav-gov-hu/Online-Invoice
- **[N2]** InvoiceData 3.0 XSD. https://github.com/nav-gov-hu/Online-Invoice/blob/master/src/schemas/nav/gov/hu/OSA/invoiceData.xsd
- **[N3]** Invoice API 3.0 XSD. https://github.com/nav-gov-hu/Online-Invoice/blob/master/src/schemas/nav/gov/hu/OSA/invoiceApi.xsd

### Auditált fork-kód (2026-09-20, `23.0`)

- `htdocs/custom/navinvoice/README.md`
- `htdocs/custom/navinvoice/class/navapi.class.php`
- `htdocs/custom/navinvoice/admin/setup.php`
- `htdocs/custom/navinvoice/core/triggers/interface_99_modNavInvoice_NavInvoiceTriggers.class.php`
- `htdocs/compta/facture/class/facture.class.php`
- `htdocs/compta/facture/class/facture-rec.class.php`
- `htdocs/core/modules/facture/*`
- `htdocs/cron/*`

---

**Dokumentum státusza:** architekturális baseline / 0. lépés. A jogszabályi és NAV interfész hivatkozásokat minden production release előtt újra kell ellenőrizni.