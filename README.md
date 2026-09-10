# Rövid – PHP / MySQL linkrövidítő

Egyszerű, magyar nyelvű linkrövidítő cPaneles tárhelyre. Nincs Composer-, Node.js- vagy keretrendszer-függősége, és nem kell hozzá folyamatosan futó külön alkalmazásszerver.

- A generált kód alapból **5 karakter**, létrehozáskor **3–32 karakter** között állítható.
- Kisbetűket, nagybetűket és számokat használ: például `https://pelda.hu/aB3xZ`.
- A hosszt a domain utáni kódra értjük; a domain és a perjel nem számít bele.
- A linkeket MySQL-adatbázis tárolja, újraindítás után is megmaradnak.
- A kezelőfelület jelszóval védett; a rövid linkek nyilvánosan megnyithatók.
- Másolás gomb, lapozható linklista, létrehozási idő és megnyitások száma.
- Mobilon is használható; JavaScript nélkül a létrehozás és az átirányítás is működik.

## Szükséges tárhely

- PHP **8.2 vagy újabb**, engedélyezett **PDO MySQL / pdo_mysql** kiegészítővel és működő PHP-munkamenetekkel.
- MySQL **5.7+ / 8.x**, vagy MariaDB **10.3+**.
- Apache **2.4** / kompatibilis LiteSpeed, `.htaccess` támogatással. A szép URL-ekhez `mod_rewrite` szükséges; van query paraméteres alternatíva is.
- HTTPS a nyilvános tárhelyen; a cPanelben legyen bekapcsolva a **Force HTTPS Redirect**.

## Telepítés cPanelben

### 1. Adatbázis

1. Nyisd meg a cPanel **MySQL Databases / Manage My Databases / MySQL Database Wizard** menüpontját.
2. Hozz létre egy adatbázist és egy adatbázis-felhasználót saját jelszóval.
3. Rendeld a felhasználót az adatbázishoz. Telepítéshez add meg az **ALL PRIVILEGES** jogosultságot.
4. Jegyezd fel a **teljes, cPanel-előtagos neveket**, például `sajatfiok_rovid` és `sajatfiok_linkuser`.
5. A **phpMyAdminban** válaszd ki az adatbázist, majd az **Importálás / Import** fülön importáld a `schema.sql` fájlt.

Az importálás két táblát hoz létre. Nem töröl meglévő adatokat. Futás közben csak `SELECT`, `INSERT`, `UPDATE`, `DELETE` jogosultság szükséges.

### 2. Fájlok feltöltése

1. A cPanel **File Manager / Fájlkezelő** alkalmazásában nyisd meg a kívánt domain dokumentumgyökerét, például a `public_html` mappát.
2. Töltsd fel és csomagold ki a `link-rovidito-cpanel.zip` fájlt. Egy meglévő oldal mellé külön alkönyvtárba (pl. `public_html/rovid`) vagy aldomain gyökerébe telepítsd; ne írd felül a meglévő oldal fájljait.
3. Ellenőrizd, hogy az `index.php`, az `assets` és az `app` könyvtár közvetlenül a kiválasztott mappában van.
4. A Fájlkezelő beállításában kapcsold be a **Show Hidden Files / Rejtett fájlok megjelenítése** opciót. A gyökérben és az `app` könyvtárban található `.htaccess` fájlok is szükségesek.
5. A feltöltött ZIP-et kicsomagolás után törölheted a tárhelyről. A `tests` mappát, a `.git` mappát és a saját mentéseidet ne töltsd fel; a kész ZIP eleve nem tartalmazza ezeket.

### 3. Beállítás

Másold át a `config.example.php` fájlt **`config.php`** néven, majd szerkeszd a cPanel Fájlkezelőjében:

```php
<?php
declare(strict_types=1);

return [
    'base_url' => 'https://pelda.hu',
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'sajatfiok_rovid',
        'user' => 'sajatfiok_linkuser',
        'password' => 'az_adatbazis_felhasznalo_jelszava',
    ],
    'admin_password' => 'CSERELD_LE_EGY_SAJAT_JELSZORA',
    'default_length' => 5,
    'query_links' => false,
];
```

- A `base_url` a tényleges **teljes, HTTPS-es webcím**, záró perjel nélkül. Alkönyvtár esetén például `https://pelda.hu/rovid`; aldomain esetén `https://go.pelda.hu`. Ne legyen a végén `index.php`.
- A MySQL-kiszolgáló általában `localhost`. Ha a tárhelyszolgáltató mást ad meg, azt használd.
- Az `admin_password` külön, saját jelszó a kezelőfelülethez. Ez nem a cPanel-jelszavad és nem az adatbázis-jelszavad. A mintaértéket cseréld le; a program a változatlan `config.example.php` mintajelszavával nem indul el.
- A `default_length` adja meg az űrlap alapértékét; az egyes linkekhez külön választhatsz más hosszt.
- Ha a jelszó aposztrófot (`'`) vagy visszaperjelet (`\`) tartalmaz, PHP-karakterláncban írd `\'`, illetve `\\` alakban.
- A `config.php` titkokat tartalmaz. Ne oszd meg és ne töltsd fel nyilvános kódtárba. Az alkalmazás `.htaccess` fájlja tiltja a közvetlen letöltését.

### 4. Használat

1. Nyisd meg a `base_url`-ban megadott címet, majd jelentkezz be a saját kezelői jelszavaddal.
2. Illeszd be az eredeti `https://…` vagy `http://…` webcímet.
3. Add meg a kód hosszát; alapból **5**.
4. Kattints a **Link rövidítése**, majd a **Másolás** gombra.

A rövid link **302-es átirányítással** nyitja meg az eredeti címet. A kód megkülönbözteti a kis- és nagybetűket: az `aB3xZ` és az `ab3xz` különböző link. A véletlen kódot kriptográfiailag biztonságos PHP-generátor állítja elő, az adatbázis egyedi indexe megakadályozza az ütközést; ütközésnél új kód készül.

A megnyitásszámláló az átirányítást kérő GET-kéréseket számolja, így robotok és linkelőnézetek is növelhetik; nem egyedi látogatószám. A HEAD-kérések nem növelik. A lista időpontjai Budapest időzónájában jelennek meg, az adatbázis UTC-időt használ.

A kezelői munkamenet legfeljebb 8 óráig él. Azonos klienscímről 15 perc alatt 5 belépési kísérlet engedélyezett; sikeres belépéskor a számláló törlődik. A program a közvetlen klienscímet használja, ezért köztes proxy esetén több látogató osztozhat ezen a korláton. Jelszócsere után a korábbi munkamenetek érvénytelenné válnak.

## Ha valami nem működik

**„Már csak a beállítás van hátra.”** Ellenőrizd a `config.php` nevét, PHP-szintaxisát, a teljes webcímet és a saját, legalább 12 karakteres kezelői jelszót.

**„Az oldal most nem érhető el.”** Ellenőrizd az adatbázis nevét, felhasználóját és jelszavát, a jogosultságokat és a `schema.sql` importálását. A részletes hiba a tárhely PHP-hibanaplójában / cPanel **Errors** menüjében található; a program nem jeleníti meg az adatbázisadatokat a látogatóknak.

**A felület működik, de a rövid link 404-et ad.** Ellenőrizd, hogy a gyökér `.htaccess` fájlja is felkerült. Próbáld ki közvetlenül a `https://pelda.hu/index.php?code=aB3xZ` alakot a saját, ténylegesen létrehozott kódoddal. Ha csak ez működik, engedélyeztesd a `mod_rewrite` modult, vagy állítsd a konfigurációban a `query_links` értékét `true`-ra. Alkönyvtárban a cím például `https://pelda.hu/rovid/index.php?code=aB3xZ`. A kód hossza ilyenkor is 5; a teljes URL hosszabb. A korábban megosztott szép URL-ek működéséhez továbbra is szükséges az átírás.

**500-as hiba már a megnyitáskor.** Nézd meg az Apache hibanaplót. Ha a szolgáltató kifejezetten az `Options` direktívát tiltja, a gyökér `.htaccess` fájljából csak az `Options -Indexes -MultiViews` sort vedd ki. A többi védelmi szabály maradjon meg, és a cPanel **Indexes** menüjében tiltsd a könyvtárlistázást.

**Belépés után visszakerülsz a belépőoldalra.** Ellenőrizd, hogy valóban a `base_url`-ban szereplő domainen, alkönyvtárban és HTTPS-sel nyitod meg az oldalt. A HTTPS-es konfigurációhoz a munkamenetsüti csak HTTPS-en kerül elküldésre.

**A Másolás nem ír a vágólapra.** HTTPS-en és böngészőengedéllyel automatikus a másolás; más esetben a program kijelölhető mezőben mutatja a linket a kézi másoláshoz. JavaScript nélkül magát a megjelenített linket másold ki.

## Fejlesztés és ellenőrzés

A `tests/run.py` elkülönített MariaDB 11.4 és PHP 8.3 / Apache konténerekkel ellenőrzi a teljes folyamatot. Docker és Python 3 szükséges hozzá; nem használja a saját `config.php`-dat és az éles adatbázisodat. A tesztkonténereket, hálózatot és ideiglenes adatokat a végén eltávolítja.

```sh
python3 tests/run.py
```

A tárhelyre feltölthető csomag újra elkészíthető Python 3-mal:

```sh
python3 tests/package.py
```

Kimenet: `dist/link-rovidito-cpanel.zip`. A csomag csak a futtatáshoz szükséges fájlokat, a konfigurációmintát, az SQL-sémát és ezt az útmutatót tartalmazza, tényleges jelszavakat nem.
