<?php
declare(strict_types=1);

// Másold config.php néven, majd töltsd ki a saját adataiddal.
return [
    // A teljes cím, alkönyvtárral együtt, a végén perjel nélkül.
    // Példa: https://pelda.hu vagy https://pelda.hu/rovid
    'base_url' => 'https://pelda.hu',
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'cpanel_adatbazis',
        'user' => 'cpanel_felhasznalo',
        'password' => 'ADATBAZIS_JELSZO',
    ],
    // Saját, legalább 12 karakteres jelszó a kezelőfelülethez.
    'admin_password' => 'CSERELD_LE_EGY_SAJAT_JELSZORA',
    'default_length' => 5,
    // false: /aB3xZ ; true: /index.php?code=aB3xZ (ha nincs mod_rewrite).
    'query_links' => false,
];

