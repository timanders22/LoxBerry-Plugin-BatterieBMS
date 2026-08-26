<?php
/**
 * Batterie-Heimspeicher (BMS) - Bedienoberflaeche
 *
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
 *
 * Diese Datei ist NUR Oberflaeche. Der Abruf laeuft im Dienst
 * (bin/bms_dienst.php), der Miniserver spricht mit
 * webfrontend/html/index.php. Ein Plugin, das den Abruf hier erledigt, ist
 * falsch gebaut - auch wenn es funktioniert.
 *
 * Praefix 'bm_', weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Bibliothek einbinden. Sie liegt unter webfrontend/html/, weil Endpunkt und
 * Dienst sie ebenfalls brauchen - installiert unter
 * <home>/webfrontend/html/plugins/<ordner>/, im Archiv unter ../html/. */
$bm_gefunden = false;
foreach (array(
    dirname(dirname(__DIR__)) . '/html/plugins/' . basename(__DIR__) . '/bm_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/bm_lib.php',
    dirname(__DIR__) . '/html/bm_lib.php',
) as $bm_kandidat) {
    if (is_file($bm_kandidat)) {
        require_once $bm_kandidat;
        $bm_gefunden = true;
        break;
    }
}
if (!$bm_gefunden) {
    echo '<p><b>Fehler:</b> bm_lib.php wurde nicht gefunden. Bitte das Plugin neu installieren.</p>';
    exit;
}
require_once __DIR__ . '/bm_test.php';

$bm_p = bm_paths();
if ($bm_p['home'] !== '' && is_file($bm_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $bm_p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $bm_p['home'] . '/libs/phplib/loxberry_web.php';
}

/* Aktiver Reiter. Wer einen Reiter hinzufuegt, muss diese Positivliste
 * mitziehen - sonst springt die Seite nach jedem Absenden zurueck auf
 * Einstellungen, obwohl der Reiter sichtbar und anklickbar ist. */
$bm_muster = '/^tab-(settings|mqtt|loxone|test|log)$/';
$bm_tab = 'tab-settings';
if (isset($_POST['activetab']) && preg_match($bm_muster, (string) $_POST['activetab'])) {
    $bm_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && preg_match($bm_muster, 'tab-' . (string) $_GET['form'])) {
    $bm_tab = 'tab-' . (string) $_GET['form'];
}

$bm_meldungen = array();
$bm_fehler = array();      // gesammelt, nicht ueberschrieben
$bm_testausgabe = '';
$bm_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';

/**
 * Eine hochgeladene Datei annehmen.
 *
 * Rueckgabe: array('name'=>…, 'inhalt'=>…) oder array('fehler'=>Text).
 * Jeder Fehlerfall bekommt einen eigenen Satz - 'Upload fehlgeschlagen' sagt
 * dem Anwender nichts.
 */
function bm_datei_annehmen($feld, $hoechstens)
{
    if (!isset($_FILES[$feld]) || !is_array($_FILES[$feld])) {
        return array('fehler' => bm_t('EINST.FEHLER_DATEI_KEINE'));
    }
    $f = $_FILES[$feld];
    $code = isset($f['error']) ? (int) $f['error'] : UPLOAD_ERR_NO_FILE;
    if ($code === UPLOAD_ERR_NO_FILE) {
        return array('fehler' => bm_t('EINST.FEHLER_DATEI_KEINE'));
    }
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
        return array('fehler' => sprintf(bm_t('EINST.FEHLER_DATEI_GROSS'), $hoechstens));
    }
    if ($code !== UPLOAD_ERR_OK) {
        return array('fehler' => sprintf(bm_t('EINST.FEHLER_DATEI_CODE'), $code));
    }
    if ((int) $f['size'] > $hoechstens) {
        return array('fehler' => sprintf(bm_t('EINST.FEHLER_DATEI_GROSS'), $hoechstens));
    }
    // Nur eine wirklich hochgeladene Datei lesen. Ohne diese Wache liesse
    // sich ueber einen praeparierten Aufruf ein beliebiger Pfad angeben. Die
    // Pruefung der Inhalte oben ist VORHER in beide Richtungen gemessen
    // worden; danach ist dieser Weg aus der Kommandozeile nicht mehr
    // nachstellbar, weil is_uploaded_file() dort immer falsch meldet.
    if (!is_uploaded_file((string) $f['tmp_name'])) {
        return array('fehler' => bm_t('EINST.FEHLER_DATEI_KEINE'));
    }
    $inhalt = @file_get_contents($f['tmp_name']);
    if ($inhalt === false || trim((string) $inhalt) === '') {
        return array('fehler' => bm_t('EINST.FEHLER_DATEI_LEER'));
    }
    return array('name' => (string) $f['name'], 'inhalt' => (string) $inhalt);
}

/** Nur Steuerzeichen, Anfuehrungszeichen und Leerraum entfernen.
 *  Ein hartes preg_replace auf eine Positivliste zerstoert eingefuegte Werte -
 *  belegt am ACTi-Plugin am 26.07.2026, wo aus einer Adresse Zeichensalat wurde. */
function bm_saeubern($wert)
{
    return trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) $wert));
}

/* ---------------- Vorlage herunterladen ---------------- */
if ($bm_post && isset($_POST['vorlage'])) {
    $bm_nr = preg_match('/^[0-9]{1,2}$/', (string) ($_POST['vorlage_geraet'] ?? '1'))
        ? (int) $_POST['vorlage_geraet'] : 1;
    if ((string) $_POST['vorlage'] === 'aus') {
        list($bm_name, $bm_inhalt) = bm_vorlage_ausgang($bm_nr);
    } else {
        list($bm_name, $bm_inhalt) = bm_vorlage_eingaenge($bm_nr);
    }
    header('Content-Type: application/xml; charset=utf-8');
    // Die Anfuehrungszeichen um den Dateinamen sind Pflicht - ohne sie bricht
    // jeder Name, der ein Leerzeichen enthaelt.
    header('Content-Disposition: attachment; filename="' . $bm_name . '"');
    echo $bm_inhalt;
    exit;
}

/* ---------------- Profil als JSON herunterladen ---------------- */
if ($bm_post && isset($_POST['profil_export'])) {
    $bm_pk = (string) $_POST['profil_export'];
    $bm_pr = bm_profil($bm_pk);
    if ($bm_pr === null) {
        $bm_fehler[] = sprintf(bm_t('EINST.FEHLER_PROFIL_WEG'), bm_e($bm_pk));
        $bm_tab = 'tab-settings';
    } else {
        unset($bm_pr['herkunft'], $bm_pr['datei']);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9_]/', '', $bm_pk) . '.json"');
        echo json_encode($bm_pr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/* ---------------- Konfiguration als Sicherung herunterladen (B19) ----------------
 *
 * Die Datei traegt das Aktionstoken. Das ist Absicht: eine Sicherung ohne
 * Token waere nach dem Zurueckspielen wertlos, weil alle Adressen im
 * Miniserver ungueltig wuerden. Der Hinweis steht am Knopf. */
if ($bm_post && isset($_POST['konfig_export'])) {
    $bm_roh = @file_get_contents($bm_p['config']);
    if ($bm_roh === false) {
        $bm_fehler[] = sprintf(bm_t('EINST.FEHLER_SICHERUNG_LESEN'), bm_e($bm_p['config']));
        $bm_tab = 'tab-settings';
    } else {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="batteriebms_'
             . date('Ymd_His') . '.json"');
        echo $bm_roh;
        exit;
    }
}

/* ---------------- Konfiguration zurueckspielen (B19) ----------------
 *
 * Abgewiesen wird, was nicht passt - nie zurechtgebogen. Uebernommen wird die
 * Datei VOLLSTAENDIG, samt Token: nur dann stimmen die Adressen im Miniserver
 * hinterher wieder. */
if ($bm_post && isset($_POST['konfig_import'])) {
    $bm_tab = 'tab-settings';
    $bm_d = bm_datei_annehmen('konfig_datei', 262144);
    if (isset($bm_d['fehler'])) {
        $bm_fehler[] = $bm_d['fehler'];
    } else {
        $bm_neu = json_decode($bm_d['inhalt'], true);
        if (!is_array($bm_neu) || !isset($bm_neu['geraete'])) {
            $bm_fehler[] = bm_t('EINST.FEHLER_KONFIG_FORM');
        } else {
            /* Fremde Schluessel sind eine BEANSTANDUNG, kein stiller Zusatz.
             * array_merge nahm bisher alles auf, was in der Datei stand -
             * auch Schluessel aus einem anderen Plugin oder einer anderen
             * Fassung. Sie landeten in der Konfiguration, taten dort nichts
             * und waren an nichts zu erkennen. */
            $bm_fremd = array_diff(array_keys($bm_neu), array_keys(bm_vorgaben()));
            if ($bm_fremd) {
                $bm_fehler[] = sprintf(bm_t('EINST.FEHLER_KONFIG_FREMD'),
                    bm_e(implode(', ', array_slice($bm_fremd, 0, 8))));
                $bm_zusammen = null;
            } else {
                $bm_zusammen = array_merge(bm_vorgaben(), $bm_neu);
            }
            if ($bm_zusammen === null) {
                /* nichts tun - geaendert wird GAR NICHTS */
            } else
            if (bm_config_speichern($bm_zusammen)) {
                $bm_meldungen[] = sprintf(bm_t('EINST.KONFIG_ZURUECK'),
                    count((array) $bm_zusammen['geraete']));
            } else {
                $bm_fehler[] = sprintf(bm_t('EINST.FEHLER_SPEICHERN'), $bm_p['config']);
            }
        }
    }
}

/* ---------------- Profil hochladen (B9) ----------------
 *
 * Die README verspricht seit jeher, weitere Speicher kaemen als JSON-Profil
 * dazu, ohne Aenderung am Quelltext - einen Weg auf das Geraet gab es bis
 * 0.9.7 aber nur ueber SSH. Angenommen wird nur, was vollstaendig ist:
 * gueltiges JSON, bekannter Uebertragungsweg, Herkunft und Stand benannt,
 * und ausschliesslich Messgroessen, die es gibt. */
if ($bm_post && isset($_POST['profil_import'])) {
    $bm_tab = 'tab-settings';
    $bm_d = bm_datei_annehmen('profil_datei', 262144);
    if (isset($bm_d['fehler'])) {
        $bm_fehler[] = $bm_d['fehler'];
    } else {
        $bm_schl = preg_replace('/[^a-z0-9_]/', '',
            strtolower(pathinfo($bm_d['name'], PATHINFO_FILENAME)));
        $bm_pr = json_decode($bm_d['inhalt'], true);
        $bm_bean = array();
        if ($bm_schl === '') {
            $bm_bean[] = bm_t('EINST.FEHLER_PROFIL_NAME');
        }
        if (!is_array($bm_pr)) {
            $bm_bean[] = bm_t('EINST.FEHLER_PROFIL_JSON');
        } else {
            if (!isset($bm_pr['transport'])
                || !in_array((string) $bm_pr['transport'], bm_transporte(), true)) {
                $bm_bean[] = sprintf(bm_t('EINST.FEHLER_PROFIL_TRANSPORT'),
                    implode(', ', bm_transporte()));
            }
            foreach (array('name', 'quelle', 'stand') as $bm_pflicht) {
                if (!isset($bm_pr[$bm_pflicht]) || trim((string) $bm_pr[$bm_pflicht]) === '') {
                    $bm_bean[] = sprintf(bm_t('EINST.FEHLER_PROFIL_PFLICHT'), $bm_pflicht);
                }
            }
            if (isset($bm_pr['stand'])
                && !in_array((string) $bm_pr['stand'], array('dokumentiert', 'unbestaetigt'), true)) {
                $bm_bean[] = bm_t('EINST.FEHLER_PROFIL_STAND');
            }
            $bm_erlaubt = bm_status_felder();
            foreach (array('felder', 'rechnung') as $bm_ab) {
                foreach ((array) (isset($bm_pr[$bm_ab]) ? $bm_pr[$bm_ab] : array()) as $bm_f => $bm_u) {
                    if (!isset($bm_erlaubt[$bm_f])) {
                        $bm_bean[] = sprintf(bm_t('EINST.FEHLER_PROFIL_FELD'), bm_e($bm_f), $bm_ab);
                    }
                }
            }
        }
        if ($bm_bean) {
            foreach ($bm_bean as $bm_b) {
                $bm_fehler[] = $bm_b;
            }
        } else {
            $bm_ordner = $bm_p['datadir'] . '/profile';
            if (!is_dir($bm_ordner)) {
                @mkdir($bm_ordner, 0775, true);
            }
            $bm_ziel = $bm_ordner . '/' . $bm_schl . '.json';
            $bm_ersetzt = isset(bm_profile_eingebaut()[$bm_schl]);
            if (@file_put_contents($bm_ziel, $bm_d['inhalt']) === false) {
                $bm_fehler[] = sprintf(bm_t('EINST.FEHLER_PROFIL_SCHREIBEN'), bm_e($bm_ziel));
            } else {
                $bm_meldungen[] = sprintf(bm_t('EINST.PROFIL_ANGENOMMEN'), bm_e($bm_schl))
                    . ($bm_ersetzt ? ' ' . bm_t('EINST.PROFIL_ERSETZT') : '');
            }
        }
    }
}

/* ---------------- Mitschnitt des Datenverkehrs (B15) ---------------- */
if ($bm_post && isset($_POST['mitschnitt'])) {
    $bm_sek = ((string) $_POST['mitschnitt'] === 'aus') ? 0 : 120;
    if (bm_mitschnitt_schalten($bm_sek)) {
        $bm_meldungen[] = $bm_sek > 0
            ? sprintf(bm_t('MITSCHNITT.EIN'), $bm_sek) : bm_t('MITSCHNITT.AUS');
    } else {
        $bm_fehler[] = sprintf(bm_t('EINST.FEHLER_SPEICHERN'), $bm_p['config']);
    }
    $bm_tab = 'tab-test';
}

/* ---------------- Trockenlauf (B7) ----------------
 *
 * Spricht mit keinem Geraet und braucht keinen laufenden Dienst - gerade dann
 * will man ja wissen, was ein Befehl taete. */
if ($bm_post && isset($_POST['trockenlauf'])) {
    $bm_tnr = preg_match('/^[0-9]{1,2}$/', (string) ($_POST['test_geraet'] ?? '1'))
        ? (int) $_POST['test_geraet'] : 1;
    $bm_twatt = preg_match('/^[0-9]{1,5}$/', (string) ($_POST['test_watt'] ?? '0'))
        ? (int) $_POST['test_watt'] : 0;
    list($bm_tok, $bm_ttext) = bm_trockenlauf($bm_tnr, (string) $_POST['trockenlauf'], $bm_twatt);
    $bm_testausgabe = $bm_ttext;
    if (!$bm_tok) {
        $bm_fehler[] = bm_t('TROCKEN.NICHT_MOEGLICH');
    }
    $bm_tab = 'tab-test';
}

/* ---------------- Einstellungen speichern ---------------- */
if ($bm_post && isset($_POST['speichern'])) {
    $bm_cfg = bm_config();
    $bm_profile = bm_profile();

    /* Geraetetabelle: bis zu sechs Zeilen. Unvollstaendige Zeilen werden
     * GEMELDET, nicht verschluckt und nicht zurechtgebogen. */
    $bm_neu = array();
    for ($bm_i = 0; $bm_i < 6; $bm_i++) {
        $hol = function ($feld) use ($bm_i) {
            $a = isset($_POST[$feld]) ? (array) $_POST[$feld] : array();
            return isset($a[$bm_i]) ? bm_saeubern($a[$bm_i]) : '';
        };
        $profil = $hol('g_profil');
        $name = $hol('g_name');
        $ip = $hol('g_ip');
        $dev = $hol('g_dev');
        if ($profil === '' && $name === '' && $ip === '' && $dev === '') {
            continue;   // leere Zeile
        }
        if (!isset($bm_profile[$profil])) {
            $bm_fehler[] = sprintf(bm_t('EINST.FEHLER_PROFIL'), $bm_i + 1);
            continue;
        }
        $pr = $bm_profile[$profil];
        $seriell = ($pr['transport'] === 'pylontech_rs485');
        if ($seriell) {
            if ($dev === '') {
                $dev = isset($pr['geraetedatei']) ? $pr['geraetedatei'] : '/dev/ttyUSB0';
            }
            // Der Punkt ist im Muster erlaubt, weil Geraetedateien ihn tragen
            // koennen (/dev/serial/by-id/… enthaelt Punkte). Zwei Punkte
            // hintereinander sind dagegen ein Weg aus /dev heraus: mit
            // /dev/../etc/shadow wuerde der Dienst eine beliebige Datei zum
            // Schreiben oeffnen und stty darauf loslassen. Gefunden bei der
            // Pflichtpruefung vor dem Ausliefern.
            if (!preg_match('#^/dev/[A-Za-z0-9_/\-\.]{1,60}$#', $dev)
                || strpos($dev, '..') !== false) {
                $bm_fehler[] = sprintf(bm_t('EINST.FEHLER_DEV'), $bm_i + 1);
                continue;
            }
        } else {
            if ($ip === '' && isset($pr['ip'])) {
                $ip = $pr['ip'];
            }
            if ($ip === '') {
                $bm_fehler[] = sprintf(bm_t('EINST.FEHLER_IP_FEHLT'), $bm_i + 1);
                continue;
            }
            // IPv4 oder Rechnername zulassen - beides ist gebraeuchlich.
            if (!preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $ip)
                && !preg_match('/^[A-Za-z0-9][A-Za-z0-9\.\-]{1,80}$/', $ip)) {
                $bm_fehler[] = sprintf(bm_t('EINST.FEHLER_IP'), $bm_i + 1);
                continue;
            }
        }
        $zeile = array(
            'name'         => $name,
            'profil'       => $profil,
            'ip'           => $ip,
            'geraetedatei' => $dev,
            'schreiben'    => ($hol('g_schreiben') === '1') ? 1 : 0,
            'vorzeichen'   => ($hol('g_vorzeichen') === '-1') ? -1 : 1,
        );
        // Zahlenfelder: leer heisst 'Vorgabe des Profils nehmen'. Ein Wert,
        // der nicht ins Muster passt, wird abgewiesen - nicht gekappt.
        foreach (array('port' => array(1, 65535), 'unit' => array(0, 247),
                       'baud' => array(300, 921600),
                       'max_laden' => array(0, 30000), 'max_entladen' => array(0, 30000)) as $f => $gr) {
            $w = $hol('g_' . $f);
            if ($w === '') {
                continue;
            }
            if (!preg_match('/^[0-9]{1,6}$/', $w) || (int) $w < $gr[0] || (int) $w > $gr[1]) {
                $bm_fehler[] = sprintf(bm_t('EINST.FEHLER_ZAHL_ZEILE'), $bm_i + 1,
                    bm_t('EINST.T_' . strtoupper($f)), $gr[0], $gr[1]);
                continue;
            }
            $zeile[$f] = (int) $w;
        }
        $w = $hol('g_nennkapaz');
        if ($w !== '') {
            if (!preg_match('/^[0-9]{1,4}([.,][0-9]{1,2})?$/', $w)) {
                $bm_fehler[] = sprintf(bm_t('EINST.FEHLER_KAPAZ'), $bm_i + 1);
            } else {
                $zeile['nennkapaz'] = (float) str_replace(',', '.', $w);
            }
        }
        $bm_neu[] = $zeile;
    }
    $bm_cfg['geraete'] = $bm_neu;

    foreach (array(
        'intervall'           => array(5, 3600),
        'zelltakt'            => array(30, 86400),
        'schreibbremse'       => array(0, 600),
        'totmann'             => array(0, 3600),
        'soc_min'             => array(0, 100),
        'soc_max'             => array(0, 100),
        'verlauf_tage'        => array(1, 365),
        'wartezeit'           => array(0, 30),
        'zeitueberschreitung' => array(1, 30),
        'drift_warnung'       => array(1, 2000),
        'temp_max'            => array(0, 100),
        'temp_min'            => array(0, 100),
    ) as $bm_feld => $bm_grenzen) {
        $bm_wert = isset($_POST[$bm_feld]) ? trim((string) $_POST[$bm_feld]) : '';
        if (!preg_match('/^[0-9]+$/', $bm_wert)) {
            $bm_fehler[] = sprintf(bm_t('EINST.FEHLER_ZAHL'), bm_t('EINST.L_' . strtoupper($bm_feld)));
            continue;
        }
        $bm_zahl = (int) $bm_wert;
        if ($bm_zahl < $bm_grenzen[0] || $bm_zahl > $bm_grenzen[1]) {
            $bm_fehler[] = sprintf(bm_t('EINST.FEHLER_BEREICH'),
                bm_t('EINST.L_' . strtoupper($bm_feld)), $bm_grenzen[0], $bm_grenzen[1]);
            continue;
        }
        $bm_cfg[$bm_feld] = $bm_zahl;
    }
    if ((int) $bm_cfg['soc_min'] >= (int) $bm_cfg['soc_max']) {
        $bm_fehler[] = bm_t('EINST.FEHLER_SOC_FENSTER');
    }

    $bm_cfg['steuerung_ein'] = isset($_POST['steuerung_ein']) ? 1 : 0;
    $bm_cfg['evcc_ein'] = isset($_POST['evcc_ein']) ? 1 : 0;

    foreach (array('evcc_geraet' => array(1, 99), 'evcc_ladewatt' => array(0, 30000))
             as $bm_ef => $bm_eg) {
        $bm_ew = isset($_POST[$bm_ef]) ? trim((string) $_POST[$bm_ef]) : '';
        if ($bm_ew === '') { continue; }
        if (!preg_match('/^[0-9]+$/', $bm_ew)) {
            $bm_fehler[] = sprintf(bm_t('EINST.FEHLER_ZAHL'), bm_t('EINST.L_' . strtoupper($bm_ef)));
            continue;
        }
        if ((int) $bm_ew < $bm_eg[0] || (int) $bm_ew > $bm_eg[1]) {
            $bm_fehler[] = sprintf(bm_t('EINST.FEHLER_BEREICH'),
                bm_t('EINST.L_' . strtoupper($bm_ef)), $bm_eg[0], $bm_eg[1]);
            continue;
        }
        $bm_cfg[$bm_ef] = (int) $bm_ew;
    }
    /* Ein eingeschaltetes EVCC, das auf einen nicht eingerichteten Speicher
     * zeigt, veroeffentlicht nichts - und niemand sieht, warum. Lieber jetzt
     * beanstanden. */
    if (!empty($bm_cfg['evcc_ein'])
        && !isset($bm_cfg['geraete'][(int) $bm_cfg['evcc_geraet'] - 1])) {
        $bm_fehler[] = sprintf(bm_t('EINST.FEHLER_EVCC_GERAET'), (int) $bm_cfg['evcc_geraet']);
    }


    if (!$bm_fehler) {
        if (bm_config_speichern($bm_cfg)) {
            $bm_meldungen[] = bm_t('EINST.GESPEICHERT');
        } else {
            $bm_fehler[] = sprintf(bm_t('EINST.FEHLER_SPEICHERN'), $bm_p['config']);
        }
    }
    $bm_tab = 'tab-settings';

    /* mqtt_ein und mqtt_topic werden hier bewusst NICHT angefasst: sie wohnen im
     * Reiter MQTT und haben dort ein eigenes Formular. Die Konfiguration
     * kommt aus bm_config(), die Werte ueberleben also unveraendert. Stuende
     * hier weiter "isset($_POST['mqtt_ein']) ? 1 : 0", wuerde jedes Speichern
     * der Einstellungen MQTT stillschweigend abschalten. */
}

/* ---------------- MQTT (eigener Reiter, eigenes Formular) ----------------
 *
 * Eigenes Formular UND eigener Handler gehoeren zusammen. Loesten beide
 * Formulare denselben Handler aus, setzte dieser die Haken des jeweils
 * nicht abgeschickten Formulars per isset() auf 0 - der Benutzer verloere
 * Werte, die er nie gesehen hat. Der Handler laedt darum den Bestand und
 * ruehrt ausschliesslich die MQTT-Werte an. */
if ($bm_post && isset($_POST['save_mqtt'])) {
    $bm_mcfg = bm_config();
    $bm_mcfg['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    $bm_mtopic = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '',
        (string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : '')));
    if ($bm_mtopic === '' || !preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $bm_mtopic)) {
        $bm_fehler[] = bm_t('EINST.FEHLER_TOPIC');
    } else {
        $bm_mcfg['mqtt_topic'] = trim($bm_mtopic, '/');
    }
    if (!$bm_fehler) {
        if (bm_config_speichern($bm_mcfg)) {
        $bm_meldungen[] = bm_t('EINST.GESPEICHERT');
        }
    }
    $bm_tab = 'tab-mqtt';
}

/* ---------------- Dienst ---------------- */
if ($bm_post && isset($_POST['dienst'])) {
    $bm_befehl = (string) $_POST['dienst'];
    list($bm_ok, $bm_ausgabe) = bm_dienst($bm_befehl);
    if ($bm_ok) {
        $bm_meldungen[] = bm_t('EINST.DIENST_' . strtoupper($bm_befehl)) . ' ' . bm_e($bm_ausgabe);
    } else {
        $bm_fehler[] = bm_e($bm_ausgabe);
    }
    $bm_tab = 'tab-settings';
}

/* ---------------- Neues Token ---------------- */
if ($bm_post && isset($_POST['token_neu'])) {
    $bm_cfg = bm_config();
    $bm_cfg['aktionstoken'] = bm_token_erzeugen();
    if (bm_config_speichern($bm_cfg)) {
        $bm_meldungen[] = bm_t('LOX.TOKEN_NEU');
    } else {
        $bm_fehler[] = sprintf(bm_t('EINST.FEHLER_SPEICHERN'), $bm_p['config']);
    }
    $bm_tab = 'tab-loxone';
}

/* ---------------- Log leeren ---------------- */
if ($bm_post && isset($_POST['log_leeren'])) {
    @mkdir(dirname($bm_p['log']), 0775, true);
    @file_put_contents($bm_p['log'], '[' . date('Y-m-d H:i:s') . '] ' . bm_t('LOG.GELEERT') . "\n");
    $bm_meldungen[] = bm_t('LOG.GELEERT');
    $bm_tab = 'tab-log';
}

/* ---------------- Reiter Test ---------------- */
if ($bm_post && isset($_POST['test'])) {
    list($bm_stand, $bm_text) = bm_test_aktion((string) $_POST['test']);
    if ($bm_stand === 1) {
        $bm_meldungen[] = bm_e($bm_text);
    } else {
        $bm_fehler[] = bm_e($bm_text);
    }
    $bm_tab = 'tab-test';
}
if ($bm_post && isset($_POST['selbsttest'])) {
    $bm_testausgabe = bm_selbsttest_ausgabe();
    $bm_tab = 'tab-test';
}

/* ---------------- Laden ---------------- */
$bm_cfg = bm_config();
$bm_token = bm_token();
$bm_geraete = bm_geraete();
$bm_werte = bm_werte();
$bm_zustand = bm_zustand();
$bm_alter = bm_alter();
$bm_pid = bm_dienst_pid();
$bm_mqtt = bm_mqtt_zustand();
$bm_profile = bm_profile();
$bm_host = bm_hostname();
$bm_basis = 'http://' . $bm_host . '/plugins/' . $bm_p['plugin'] . '/index.php';
$bm_logzeilen = array();
if (is_file($bm_p['log'])) {
    $bm_logzeilen = array_slice(
        array_reverse(file($bm_p['log'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()),
        0, 400);
}

$bm_rahmen = class_exists('LBWeb', false);
if ($bm_rahmen) {
    LBWeb::lbheader('Batterie-Heimspeicher (BMS)', 'https://wiki.loxberry.de/', 'help.html');
}

?>
<style>
/* Hausstandard, wortgetreu aus VORLAGE_hausstandard.css.html uebernommen.
   Nicht neu erfinden: der Knopf-Fehler vom 30.07.2026 steckte in sieben
   Plugins gleichzeitig, weil jedes seine eigene Kopie hatte. */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; white-space: pre-wrap; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-fehler { border: 1px solid #ef9a9a; background: #ffebee; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, "Courier New", monospace;
    font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto;
    white-space: pre-wrap; }
</style>
<div class="sm-wrap">

<?php foreach ($bm_meldungen as $bm_m) { ?>
<div class="sm-hinweis"><?= $bm_m ?></div>
<?php } ?>
<?php if ($bm_fehler) { ?>
<div class="sm-fehler"><b><?= bm_e(bm_t('ALLG.BEANSTANDUNG')) ?></b>
<ul style="margin:6px 0 0 18px;padding:0;">
<?php foreach ($bm_fehler as $bm_f) { ?><li><?= $bm_f ?></li><?php } ?>
</ul></div>
<?php } ?>

<!-- ================= Statuskacheln ================= -->
<div class="sm-kacheln">
  <div class="sm-kachel"><?= bm_e(bm_t('ALLG.DIENST')) ?>
    <b class="<?= $bm_pid ? 'sm-an' : 'sm-aus' ?>"><?= $bm_pid ? bm_e(bm_t('ALLG.LAEUFT')) : bm_e(bm_t('ALLG.GESTOPPT')) ?></b>
    <span class="sm-hilfe"><?= $bm_pid ? 'PID ' . (int) $bm_pid : bm_e(bm_t('ALLG.KEINE_PID')) ?></span>
  </div>
  <div class="sm-kachel"><?= bm_e(bm_t('ALLG.LETZTER_ABRUF')) ?>
    <b><?= $bm_alter < 0 ? '&ndash;' : (int) $bm_alter . ' s' ?></b>
    <span class="sm-hilfe"><?= $bm_alter < 0 ? bm_e(bm_t('ALLG.NIE')) : bm_e(date('d.m.Y H:i:s', time() - $bm_alter)) ?></span>
  </div>
  <div class="sm-kachel"><?= bm_e(bm_t('ALLG.SPEICHER')) ?>
    <b><?= count($bm_geraete) ?></b>
    <span class="sm-hilfe"><?php
      $bm_ok = 0;
      foreach ($bm_werte as $bm_w) { if (!empty($bm_w['ok'])) { $bm_ok++; } }
      echo (int) $bm_ok . ' ' . bm_e(bm_t('ALLG.ERREICHBAR'));
    ?></span>
  </div>
  <div class="sm-kachel"><?= bm_e(bm_t('ALLG.STEUERUNG')) ?>
    <b class="<?= !empty($bm_cfg['steuerung_ein']) ? 'sm-an' : 'sm-aus' ?>"><?= !empty($bm_cfg['steuerung_ein']) ? bm_e(bm_t('ALLG.FREI')) : bm_e(bm_t('ALLG.GESPERRT')) ?></b>
    <span class="sm-hilfe"><?= sprintf(bm_e(bm_t('ALLG.TOTMANN_KURZ')), (int) $bm_cfg['totmann']) ?></span>
  </div>
  <div class="sm-kachel">MQTT
    <b class="<?= $bm_mqtt['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= $bm_mqtt['autostart'] ? bm_e(bm_t('ALLG.EIN')) : bm_e(bm_t('ALLG.AUS')) ?></b>
    <span class="sm-hilfe"><?= bm_e(bm_t('ALLG.GATEWAY')) ?></span>
  </div>
</div>

<?php if (!empty($bm_zustand['fehler'])) { ?>
<div class="sm-warnung"><b><?= bm_e(bm_t('ALLG.LETZTE_STOERUNG')) ?></b> <?= bm_e($bm_zustand['fehler']) ?></div>
<?php } ?>

<?php foreach ($bm_werte as $bm_nr => $bm_w) { ?>
<div class="sm-hinweis">
<b><?= bm_e($bm_w['name']) ?></b> (<?= bm_e(bm_t('ALLG.SPEICHER_EINZ')) ?> <?= bm_e($bm_nr) ?>,
<span class="sm-mono"><?= bm_e($bm_w['profil']) ?></span>)
&middot; <?= bm_e(bm_t('ALLG.SOC')) ?> <b><?= $bm_w['SOC'] === null ? '&ndash;' : bm_e($bm_w['SOC']) . ' %' ?></b>
&middot; <?= bm_e(bm_t('ALLG.SOH')) ?> <b><?= $bm_w['SOH'] === null ? '&ndash;' : bm_e($bm_w['SOH']) . ' %' ?></b>
&middot; <?= bm_e(bm_t('ALLG.LEISTUNG')) ?> <?= $bm_w['PBAT'] === null ? '&ndash;' : bm_e($bm_w['PBAT']) . ' W' ?>
&middot; <?= bm_e(bm_t('ALLG.DRIFT')) ?> <?= $bm_w['UZDIFF'] === null ? '&ndash;' : bm_e($bm_w['UZDIFF']) . ' mV' ?>
&middot; <?= bm_e(bm_t('ALLG.ZYKLEN')) ?> <?= $bm_w['ZYKLEN'] === null ? '&ndash;' : bm_e($bm_w['ZYKLEN']) ?>
<?php if ($bm_w['sollwert'] !== '') { ?>
&middot; <span class="sm-an"><?= bm_e(bm_t('ALLG.ZWANG')) ?> <?= bm_e($bm_w['sollwert']) ?>
(<?= (int) $bm_w['sollwert_alter'] ?> s)</span>
<?php } ?>
<?php
/* Tagesauswahl (B12). Bis 0.9.7 zeigte das Bild ausschliesslich den heutigen
 * Tag, obwohl die Dateien fuer bis zu 365 Tage vorgehalten werden. */
$bm_tage = bm_verlauf_tage((int) $bm_nr);
$bm_vtag = (isset($_GET['vtag']) && preg_match('/^[0-9]{8}$/', (string) $_GET['vtag']))
    ? (string) $_GET['vtag'] : date('Ymd');
?>
<div style="margin-top:8px;"><?= bm_soc_svg(bm_verlauf_lesen((int) $bm_nr, $bm_vtag), $bm_vtag) ?></div>
<div class="sm-hilfe"><?= bm_e(bm_t('ALLG.VERLAUF_HINWEIS')) ?>
<?php if (count($bm_tage) > 1) { ?>
<br><?= bm_e(bm_t('ALLG.VERLAUF_TAG')) ?>
<?php foreach (array_slice($bm_tage, 0, 14) as $bm_t1) { ?>
<a href="index.php?form=settings&amp;vtag=<?= bm_e($bm_t1) ?>"<?= $bm_t1 === $bm_vtag ? ' style="font-weight:700;"' : '' ?>><?= bm_e(substr($bm_t1, 6, 2) . '.' . substr($bm_t1, 4, 2) . '.') ?></a>
<?php } ?>
<?php } ?>
</div>
<?php if ($bm_w['sollwert'] !== '' && !empty($bm_w['sollwert_quelle'])) { ?>
<div class="sm-hilfe"><?= sprintf(bm_e(bm_t('ALLG.SOLLQUELLE')), bm_e($bm_w['sollwert_quelle'])) ?></div>
<?php } ?>
</div>
<?php } ?>

<!-- Reiterleiste: echte Links, JavaScript faengt den Klick ab. So bleibt jeder
     Reiter verlinkbar, Eingaben in anderen Reitern gehen nicht verloren, und
     faellt das Skript aus, ist die Seite weiterhin bedienbar. -->
<div class="sm-tabs">
	<a class="sm-tab<?= $bm_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings" href="index.php?form=settings"><?= bm_e(bm_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab<?= $bm_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt"     href="index.php?form=mqtt"><?= bm_e(bm_t('REITER.MQTT')) ?></a>
	<a class="sm-tab<?= $bm_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"   href="index.php?form=loxone"><?= bm_e(bm_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= $bm_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"     href="index.php?form=test"><?= bm_e(bm_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= $bm_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"      href="index.php?form=log"><?= bm_e(bm_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $bm_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">

<h2><?= bm_e(bm_t('EINST.H_DIENST')) ?></h2>
<p class="sm-hilfe"><?= bm_t('EINST.DIENST_ERKLAERUNG') ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= bm_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= bm_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="dienst" value="start"><?= bm_e(bm_t('EINST.K_START')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="restart"><?= bm_e(bm_t('EINST.K_NEUSTART')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="stop"><?= bm_e(bm_t('EINST.K_STOPP')) ?></button>
  </form>
</div>

<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="speichern" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?= bm_e(bm_t('EINST.H_GERAETE')) ?></h2>
<div class="sm-hinweis"><?= bm_t('EINST.GERAETE_ERKLAERUNG') ?></div>
<table class="sm-tbl">
<tr><th style="width:24px;">#</th><th><?= bm_e(bm_t('EINST.T_NAME')) ?></th>
    <th><?= bm_e(bm_t('EINST.T_PROFIL')) ?></th>
    <th><?= bm_e(bm_t('EINST.T_IP')) ?></th>
    <th style="width:66px;"><?= bm_e(bm_t('EINST.T_PORT')) ?></th>
    <th style="width:56px;"><?= bm_e(bm_t('EINST.T_UNIT')) ?></th>
    <th><?= bm_e(bm_t('EINST.T_DEV')) ?></th>
    <th style="width:72px;"><?= bm_e(bm_t('EINST.T_BAUD')) ?></th>
    <th style="width:78px;"><?= bm_e(bm_t('EINST.T_NENNKAPAZ')) ?></th>
    <th style="width:78px;"><?= bm_e(bm_t('EINST.T_MAX_LADEN')) ?></th>
    <th style="width:78px;"><?= bm_e(bm_t('EINST.T_MAX_ENTLADEN')) ?></th>
    <th style="width:92px;"><?= bm_e(bm_t('EINST.T_VORZEICHEN')) ?></th>
    <th style="width:64px;"><?= bm_e(bm_t('EINST.T_SCHREIBEN')) ?></th></tr>
<?php
$bm_roh = isset($bm_cfg['geraete']) && is_array($bm_cfg['geraete']) ? $bm_cfg['geraete'] : array();
for ($bm_i = 0; $bm_i < 6; $bm_i++) {
    $bm_z = isset($bm_roh[$bm_i]) && is_array($bm_roh[$bm_i]) ? $bm_roh[$bm_i] : array();
    $bm_v = function ($k) use ($bm_z) { return isset($bm_z[$k]) ? (string) $bm_z[$k] : ''; };
?>
<tr>
<td><?= $bm_i + 1 ?></td>
<td><input data-role="none" type="text" name="g_name[]" value="<?= bm_e($bm_v('name')) ?>" size="10"></td>
<td><select data-role="none" name="g_profil[]">
    <option value=""><?= bm_e(bm_t('EINST.PROFIL_KEIN')) ?></option>
<?php foreach ($bm_profile as $bm_pk => $bm_pr) { ?>
    <option value="<?= bm_e($bm_pk) ?>"<?= $bm_v('profil') === $bm_pk ? ' selected' : '' ?>><?= bm_e($bm_pr['name']) ?><?= $bm_pr['stand'] !== 'dokumentiert' ? ' (!)' : '' ?></option>
<?php } ?>
</select></td>
<td><input data-role="none" type="text" name="g_ip[]" value="<?= bm_e($bm_v('ip')) ?>" size="13"></td>
<td><input data-role="none" type="text" name="g_port[]" value="<?= bm_e($bm_v('port')) ?>" size="4"></td>
<td><input data-role="none" type="text" name="g_unit[]" value="<?= bm_e($bm_v('unit')) ?>" size="3"></td>
<td><input data-role="none" type="text" name="g_dev[]" value="<?= bm_e($bm_v('geraetedatei')) ?>" size="11" list="bm_serielle"></td>
<td><input data-role="none" type="text" name="g_baud[]" value="<?= bm_e($bm_v('baud')) ?>" size="5"></td>
<td><input data-role="none" type="text" name="g_nennkapaz[]" value="<?= bm_e($bm_v('nennkapaz')) ?>" size="4"></td>
<td><input data-role="none" type="text" name="g_max_laden[]" value="<?= bm_e($bm_v('max_laden')) ?>" size="4"></td>
<td><input data-role="none" type="text" name="g_max_entladen[]" value="<?= bm_e($bm_v('max_entladen')) ?>" size="4"></td>
<td><select data-role="none" name="g_vorzeichen[]">
    <option value="1"<?= $bm_v('vorzeichen') !== '-1' ? ' selected' : '' ?>><?= bm_e(bm_t('EINST.VZ_NORMAL')) ?></option>
    <option value="-1"<?= $bm_v('vorzeichen') === '-1' ? ' selected' : '' ?>><?= bm_e(bm_t('EINST.VZ_UMGEKEHRT')) ?></option>
</select></td>
<td><select data-role="none" name="g_schreiben[]">
    <option value="0"<?= $bm_v('schreiben') !== '1' ? ' selected' : '' ?>><?= bm_e(bm_t('ALLG.NEIN')) ?></option>
    <option value="1"<?= $bm_v('schreiben') === '1' ? ' selected' : '' ?>><?= bm_e(bm_t('ALLG.JA')) ?></option>
</select></td>
</tr>
<?php } ?>
</table>
<?php
/* Die tatsaechlich vorhandenen seriellen Schnittstellen zur Auswahl anbieten.
 *
 * /dev/ttyUSB0 ist keine Eigenschaft des Adapters, sondern eine
 * Reihenfolge - sie richtet sich danach, welches Geraet beim Hochfahren
 * zuerst erkannt wurde. Steckt neben dem RS485-Adapter noch ein EnOcean-
 * oder Zigbee-Stick, kann der Speicher nach jedem Neustart woanders liegen,
 * und das Plugin meldet 'keine Antwort', obwohl nichts kaputt ist.
 *
 * Die Namen unter /dev/serial/by-id/ haengen am Geraet selbst und bleiben
 * gleich. Sie stehen deshalb oben in der Liste. Umgestellt wird nichts von
 * selbst: was hier eingetragen ist, bleibt stehen, bis jemand es aendert. */
$bm_ser = function_exists('bm_serielle_geraete') ? bm_serielle_geraete() : array();
?>
<?php if ($bm_ser) { ?>
<datalist id="bm_serielle">
<?php foreach ($bm_ser as $bm_sp => $bm_si) { ?>
    <option value="<?= bm_e($bm_sp) ?>"><?= bm_e($bm_si['stabil'] ? bm_t('EINST.SER_STABIL') : bm_t('EINST.SER_WECHSELND')) ?><?= $bm_si['zeigt'] !== '' ? ' → ' . bm_e($bm_si['zeigt']) : '' ?></option>
<?php } ?>
</datalist>
<div class="sm-hilfe"><b><?= bm_e(bm_t('EINST.SER_H_TITEL')) ?></b> <?= bm_t('EINST.SER_H_TEXT') ?>
<?php
$bm_hinweise = array();
foreach ($bm_geraete as $bm_gn => $bm_gg) {
    if ($bm_gg['transport'] !== 'pylontech_rs485') {
        continue;
    }
    $bm_besser = function_exists('bm_serielle_empfehlung')
        ? bm_serielle_empfehlung($bm_gg['geraetedatei']) : '';
    if ($bm_besser !== '') {
        $bm_hinweise[] = sprintf(bm_t('EINST.SER_H_EMPFEHLUNG'), (int) $bm_gn,
            bm_e($bm_gg['geraetedatei']), bm_e($bm_besser));
    }
}
foreach ($bm_hinweise as $bm_h) {
    echo '<br>' . $bm_h;
}
?>
</div>
<?php } ?>
<div class="sm-hilfe"><?= bm_t('EINST.GERAETE_HILFE') ?></div>

<h2><?= bm_e(bm_t('EINST.H_TAKT')) ?></h2>
<div class="sm-feld">
  <label for="intervall"><?= bm_e(bm_t('EINST.L_INTERVALL')) ?></label>
  <input data-role="none" type="number" id="intervall" name="intervall" value="<?= (int) $bm_cfg['intervall'] ?>" min="5" max="3600">
  <div class="sm-hilfe"><?= bm_t('EINST.H_INTERVALL') ?></div>
</div>
<div class="sm-feld">
  <label for="zelltakt"><?= bm_e(bm_t('EINST.L_ZELLTAKT')) ?></label>
  <input data-role="none" type="number" id="zelltakt" name="zelltakt" value="<?= (int) $bm_cfg['zelltakt'] ?>" min="30" max="86400">
  <div class="sm-hilfe"><?= bm_t('EINST.H_ZELLTAKT') ?></div>
</div>
<div class="sm-feld">
  <label for="zeitueberschreitung"><?= bm_e(bm_t('EINST.L_ZEITUEBERSCHREITUNG')) ?></label>
  <input data-role="none" type="number" id="zeitueberschreitung" name="zeitueberschreitung" value="<?= (int) $bm_cfg['zeitueberschreitung'] ?>" min="1" max="30">
</div>
<div class="sm-feld">
  <label for="drift_warnung"><?= bm_e(bm_t('EINST.L_DRIFT_WARNUNG')) ?></label>
  <input data-role="none" type="number" id="drift_warnung" name="drift_warnung" value="<?= (int) $bm_cfg['drift_warnung'] ?>" min="1" max="2000">
  <div class="sm-hilfe"><?= bm_t('EINST.H_DRIFT_WARNUNG') ?></div>
</div>
<div class="sm-feld">
  <label for="verlauf_tage"><?= bm_e(bm_t('EINST.L_VERLAUF_TAGE')) ?></label>
  <input data-role="none" type="number" id="verlauf_tage" name="verlauf_tage" value="<?= (int) $bm_cfg['verlauf_tage'] ?>" min="1" max="365">
</div>

<div class="sm-feld">
  <label for="temp_max"><?= bm_e(bm_t('EINST.L_TEMP_MAX')) ?></label>
  <input data-role="none" type="number" id="temp_max" name="temp_max" value="<?= (int) $bm_cfg['temp_max'] ?>" min="0" max="100">
  <div class="sm-hilfe"><?= bm_t('EINST.H_TEMP_MAX') ?></div>
</div>
<div class="sm-feld">
  <label for="temp_min"><?= bm_e(bm_t('EINST.L_TEMP_MIN')) ?></label>
  <input data-role="none" type="number" id="temp_min" name="temp_min" value="<?= (int) $bm_cfg['temp_min'] ?>" min="0" max="100">
  <div class="sm-hilfe"><?= bm_t('EINST.H_TEMP_MIN') ?></div>
</div>

<h2><?= bm_e(bm_t('EINST.H_STEUERUNG')) ?></h2>
<div class="sm-warnung"><?= bm_t('EINST.STEUERUNG_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="steuerung_ein" value="1" <?= !empty($bm_cfg['steuerung_ein']) ? 'checked' : '' ?>>
    <?= bm_e(bm_t('EINST.L_STEUERUNG_EIN')) ?>
  </label>
  <div class="sm-hilfe"><?= bm_t('EINST.H_STEUERUNG_EIN') ?></div>
</div>
<div class="sm-feld">
  <label for="totmann"><?= bm_e(bm_t('EINST.L_TOTMANN')) ?></label>
  <input data-role="none" type="number" id="totmann" name="totmann" value="<?= (int) $bm_cfg['totmann'] ?>" min="0" max="3600">
  <div class="sm-hilfe"><?= bm_t('EINST.H_TOTMANN') ?></div>
</div>
<div class="sm-feld">
  <label for="soc_min"><?= bm_e(bm_t('EINST.L_SOC_MIN')) ?></label>
  <input data-role="none" type="number" id="soc_min" name="soc_min" value="<?= (int) $bm_cfg['soc_min'] ?>" min="0" max="100">
  <div class="sm-hilfe"><?= bm_t('EINST.H_SOC_MIN') ?></div>
</div>
<div class="sm-feld">
  <label for="soc_max"><?= bm_e(bm_t('EINST.L_SOC_MAX')) ?></label>
  <input data-role="none" type="number" id="soc_max" name="soc_max" value="<?= (int) $bm_cfg['soc_max'] ?>" min="0" max="100">
  <div class="sm-hilfe"><?= bm_t('EINST.H_SOC_MAX') ?></div>
</div>
<div class="sm-feld">
  <label for="schreibbremse"><?= bm_e(bm_t('EINST.L_SCHREIBBREMSE')) ?></label>
  <input data-role="none" type="number" id="schreibbremse" name="schreibbremse" value="<?= (int) $bm_cfg['schreibbremse'] ?>" min="0" max="600">
  <div class="sm-hilfe"><?= bm_t('EINST.H_SCHREIBBREMSE') ?></div>
</div>
<div class="sm-feld">
  <label for="wartezeit"><?= bm_e(bm_t('EINST.L_WARTEZEIT')) ?></label>
  <input data-role="none" type="number" id="wartezeit" name="wartezeit" value="<?= (int) $bm_cfg['wartezeit'] ?>" min="0" max="30">
  <div class="sm-hilfe"><?= bm_t('EINST.H_WARTEZEIT') ?></div>
</div>

<?php /* MQTT stand hier bis zu dieser Fassung. Es wohnt jetzt
         vollstaendig im Reiter MQTT - eine Sache, eine Stelle. */ ?>

<h2>EVCC</h2>
<div class="sm-step"><?= bm_t('EINST.EVCC_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="evcc_ein" value="1" <?= !empty($bm_cfg['evcc_ein']) ? 'checked' : '' ?>>
    <?= bm_e(bm_t('EINST.L_EVCC_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="evcc_geraet"><?= bm_e(bm_t('EINST.L_EVCC_GERAET')) ?></label>
  <input data-role="none" type="number" id="evcc_geraet" name="evcc_geraet" value="<?= (int) $bm_cfg['evcc_geraet'] ?>" min="1" max="99">
  <span class="sm-hilfe"><?= bm_t('EINST.H_EVCC_GERAET') ?></span>
</div>
<div class="sm-feld">
  <label for="evcc_ladewatt"><?= bm_e(bm_t('EINST.L_EVCC_LADEWATT')) ?></label>
  <input data-role="none" type="number" id="evcc_ladewatt" name="evcc_ladewatt" value="<?= (int) $bm_cfg['evcc_ladewatt'] ?>" min="0" max="30000" step="100">
  <span class="sm-hilfe"><?= bm_t('EINST.H_EVCC_LADEWATT') ?></span>
</div>
<div class="sm-warnung"><?= bm_t('EINST.EVCC_VORZEICHEN') ?></div>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= bm_e(bm_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= bm_e(bm_t('EINST.H_SICHERUNG')) ?></h2>
<p class="sm-hilfe"><?= bm_t('EINST.SICHERUNG_ERKLAERUNG') ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= bm_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= bm_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="konfig_export" value="1"><?= bm_e(bm_t('EINST.K_KONFIG_EXPORT')) ?></button>
  </form>
</div>
<form action="index.php" method="post" enctype="multipart/form-data">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<div class="sm-feld">
  <label for="konfig_datei"><?= bm_e(bm_t('EINST.L_KONFIG_DATEI')) ?></label>
  <input data-role="none" type="file" id="konfig_datei" name="konfig_datei" accept=".json,application/json">
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="konfig_import" value="1"><?= bm_e(bm_t('EINST.K_KONFIG_IMPORT')) ?></button>
</div>
</form>

<h2><?= bm_e(bm_t('EINST.H_PROFILE')) ?></h2>
<p class="sm-hilfe"><?= bm_t('EINST.PROFILE_ERKLAERUNG') ?>
<br><span class="sm-mono"><?= bm_e($bm_p['datadir']) ?>/profile/</span></p>
<table class="sm-tbl">
<tr><th><?= bm_e(bm_t('EINST.T_PROFIL')) ?></th><th><?= bm_e(bm_t('EINST.T_TRANSPORT')) ?></th>
    <th><?= bm_e(bm_t('EINST.T_STAND')) ?></th><th><?= bm_e(bm_t('EINST.T_QUELLE')) ?></th>
    <th style="width:150px;">&nbsp;</th></tr>
<?php foreach ($bm_profile as $bm_pk => $bm_pr) { ?>
<tr>
<td><span class="sm-mono"><?= bm_e($bm_pk) ?></span><br><?= bm_e($bm_pr['name']) ?>
    <?php if (isset($bm_pr['hinweis'])) { ?><div class="sm-hilfe"><?= bm_t($bm_pr['hinweis']) ?></div><?php } ?></td>
<td><span class="sm-mono"><?= bm_e($bm_pr['transport']) ?></span></td>
<td class="<?= $bm_pr['stand'] === 'dokumentiert' ? 'sm-an' : 'sm-aus' ?>">
    <?= bm_e(bm_t('EINST.STAND_' . strtoupper($bm_pr['stand']))) ?><br>
    <span class="sm-hilfe"><?= bm_e($bm_pr['herkunft']) ?></span></td>
<td class="sm-hilfe"><?= bm_e($bm_pr['quelle']) ?></td>
<td><form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-technik" style="min-width:140px;" type="submit" name="profil_export" value="<?= bm_e($bm_pk) ?>"><?= bm_e(bm_t('EINST.K_PROFIL_EXPORT')) ?></button>
</form></td>
</tr>
<?php } ?>
</table>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= bm_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= bm_t('LEGENDE.AKTION') ?></span>
</div>

<h3><?= bm_e(bm_t('EINST.H_PROFIL_IMPORT')) ?></h3>
<div class="sm-hinweis"><?= bm_t('EINST.PROFIL_IMPORT_ERKLAERUNG') ?></div>
<form action="index.php" method="post" enctype="multipart/form-data">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<div class="sm-feld">
  <label for="profil_datei"><?= bm_e(bm_t('EINST.L_PROFIL_DATEI')) ?></label>
  <input data-role="none" type="file" id="profil_datei" name="profil_datei" accept=".json,application/json">
  <div class="sm-hilfe"><?= bm_t('EINST.H_PROFIL_DATEI') ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="profil_import" value="1"><?= bm_e(bm_t('EINST.K_PROFIL_IMPORT')) ?></button>
</div>
</form>

</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $bm_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">

<h2>MQTT</h2>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="save_mqtt" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="mqtt_ein" value="1" <?= !empty($bm_cfg['mqtt_ein']) ? 'checked' : '' ?>>
    <?= bm_e(bm_t('EINST.L_MQTT_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="mqtt_topic"><?= bm_e(bm_t('EINST.L_MQTT_TOPIC')) ?></label>
  <input data-role="none" type="text" id="mqtt_topic" name="mqtt_topic" value="<?= bm_e($bm_cfg['mqtt_topic']) ?>" placeholder="batteriebms">
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= bm_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= bm_e(bm_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<h2><?= bm_e(bm_t('MQTT.H_ZUSTAND')) ?></h2>
<p class="sm-hilfe"><?= bm_t('MQTT.GATEWAY_ERKLAERUNG') ?></p>
<?php if (!$bm_mqtt['gefunden']) { ?>
<div class="sm-fehler"><?= bm_t('MQTT.NICHT_GEFUNDEN') ?></div>
<?php } elseif (!$bm_mqtt['autostart']) { ?>
<div class="sm-fehler"><?= bm_t('MQTT.AUTOSTART_AUS') ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= bm_t('MQTT.AUTOSTART_EIN') ?></div>
<?php } ?>
<table class="sm-tbl">
<tr><th><?= bm_e(bm_t('ALLG.EIGENSCHAFT')) ?></th><th><?= bm_e(bm_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= bm_e(bm_t('MQTT.T_AUTOSTART')) ?></td><td class="<?= $bm_mqtt['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= $bm_mqtt['autostart'] ? bm_e(bm_t('ALLG.EIN')) : bm_e(bm_t('ALLG.AUS')) ?></td></tr>
<tr><td><?= bm_e(bm_t('MQTT.T_BROKER')) ?></td><td><span class="sm-mono"><?= bm_e($bm_mqtt['broker']) ?>:<?= bm_e($bm_mqtt['brokerport']) ?></span></td></tr>
<tr><td><?= bm_e(bm_t('MQTT.T_UDP')) ?></td><td><span class="sm-mono"><?= (int) $bm_mqtt['udpport'] ?></span></td></tr>
<tr><td><?= bm_e(bm_t('MQTT.T_LOKAL')) ?></td><td><?= $bm_mqtt['lokal'] ? bm_e(bm_t('ALLG.JA')) : bm_e(bm_t('ALLG.NEIN')) ?></td></tr>
<tr><td><?= bm_e(bm_t('MQTT.T_PLUGIN')) ?></td><td class="<?= !empty($bm_cfg['mqtt_ein']) ? 'sm-an' : 'sm-aus' ?>"><?= !empty($bm_cfg['mqtt_ein']) ? bm_e(bm_t('ALLG.EIN')) : bm_e(bm_t('ALLG.AUS')) ?></td></tr>
<tr><td><?= bm_e(bm_t('MQTT.T_SOCKETS')) ?></td><td class="<?= extension_loaded('sockets') ? 'sm-an' : 'sm-aus' ?>"><?= extension_loaded('sockets') ? bm_e(bm_t('ALLG.JA')) : bm_e(bm_t('ALLG.NEIN')) ?></td></tr>
</table>

<h2><?= bm_e(bm_t('MQTT.H_ABO')) ?></h2>
<div class="sm-warnung"><?= bm_abo_text() ?></div>
<div class="sm-step"><?= bm_t('MQTT.ABO_SCHRITTE') ?>
<p><span class="sm-mono"><?= bm_e($bm_cfg['mqtt_topic']) ?>/#</span></p>
</div>

<h2><?= bm_e(bm_t('MQTT.H_THEMEN')) ?></h2>
<p class="sm-hilfe"><?= bm_t('MQTT.THEMEN_ERKLAERUNG') ?></p>
<div class="sm-hinweis"><?= bm_t('MQTT.TS_HINWEIS') ?></div>
<table class="sm-tbl">
<tr><th><?= bm_e(bm_t('MQTT.T_THEMA')) ?></th><th><?= bm_e(bm_t('MQTT.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (bm_mqtt_themen() as $bm_thema => $bm_schluessel) { ?>
<tr><td><span class="sm-mono"><?= bm_e($bm_cfg['mqtt_topic'] . '/' . $bm_thema) ?></span></td>
    <td><?= bm_t($bm_schluessel) ?></td></tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= bm_t('MQTT.PLATZHALTER') ?></p>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $bm_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= bm_e(bm_t('LOX.H_TITEL')) ?></h2>
<p><?= bm_t('LOX.EINLEITUNG') ?></p>

<div class="sm-step"><b><?= bm_e(bm_t('LOX.S1_TITEL')) ?></b><br><?= bm_t('LOX.S1_TEXT') ?></div>

<div class="sm-step"><b><?= bm_e(bm_t('LOX.S2_TITEL')) ?></b><br>
<?= bm_t('LOX.S2_TEXT') ?>
<p><span class="sm-mono"><?= bm_e($bm_cfg['mqtt_topic']) ?>/#</span></p>
<div class="sm-warnung"><?= bm_abo_text() ?></div>
</div>

<div class="sm-step"><b><?= bm_e(bm_t('LOX.S3_TITEL')) ?></b><br>
<?= bm_t('LOX.S3_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= bm_e(bm_t('ALLG.EIGENSCHAFT')) ?></th><th><?= bm_e(bm_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= bm_e(bm_t('LOX.T_ADRESSE')) ?></td>
    <td><span class="sm-mono"><?= bm_e($bm_basis) ?>?token=<?= bm_e($bm_token) ?>&amp;aktion=status&amp;geraet=1</span></td></tr>
<tr><td><?= bm_e(bm_t('LOX.T_ZYKLUS')) ?></td><td>60 <?= bm_e(bm_t('ALLG.SEKUNDEN')) ?></td></tr>
</table>
<div class="sm-warnung"><?= bm_t('LOX.ADRESSE_VORSCHLAG') ?></div>
<?= bm_t('LOX.S3_BEFEHLE') ?>
<?php
/* Nur die Messgroessen zeigen, die DIESER Speicher wirklich liefert.
 *
 * Bis 0.9.6 lief die Schleife ueber bm_status_felder() und zeigte damit alle
 * 21 - auch WARNUNG, LADENMAX und ENTLMAX, die kein eingebautes Profil und
 * kein Codepfad je fuellt. Die Importdatei legte sie als virtuelle Eingaenge
 * an, Loxone traegt dort DefVal 0 ein, und eine 0 sieht aus wie ein Messwert.
 *
 * Tabelle und Importdatei holen die Auswahl jetzt aus derselben Funktion -
 * sonst laufen sie auseinander. */
if (!bm_felder_gemessen(1)) { ?>
<div class="sm-warnung"><?= bm_t('LOX.VORLAGE_UNGEMESSEN') ?></div>
<?php } ?>
<table class="sm-tbl">
<tr><th><?= bm_e(bm_t('LOX.T_TITEL')) ?></th><th><?= bm_e(bm_t('LOX.T_BEFEHL')) ?></th>
    <th><?= bm_e(bm_t('LOX.T_EINHEIT')) ?></th><th><?= bm_e(bm_t('LOX.T_GRENZEN')) ?></th>
    <th><?= bm_e(bm_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (bm_felder_geliefert(1) as $bm_feld => $bm_info) { ?>
<tr><td><span class="sm-mono">BMS_1_<?= bm_e($bm_feld) ?></span></td>
    <td><span class="sm-mono"><?= bm_e(bm_check($bm_feld)) ?></span></td>
    <td><?= bm_e($bm_info[0]) ?></td>
    <td><span class="sm-mono"><?= (int) $bm_info[2] ?> &hellip; <?= (int) $bm_info[3] ?></span></td>
    <td><?= bm_t($bm_info[1]) ?></td></tr>
<?php } ?>
<tr><td><span class="sm-mono">BMS_1_SOLLART</span></td>
    <td><span class="sm-mono"><?= bm_e(bm_check('SOLLART')) ?></span></td>
    <td>&mdash;</td><td><span class="sm-mono">0 &hellip; 3</span></td>
    <td><?= bm_t('BM_FELD.SOLLART') ?></td></tr>
<tr><td><span class="sm-mono">BMS_1_SOLLALTER</span></td>
    <td><span class="sm-mono"><?= bm_e(bm_check('SOLLALTER')) ?></span></td>
    <td>s</td><td><span class="sm-mono">-1 &hellip; 86400</span></td>
    <td><?= bm_t('BM_FELD.SOLLALTER') ?></td></tr>
</table>
<div class="sm-warnung"><?= bm_t('LOX.S3_STRICH') ?></div>
<?php if (count($bm_werte) > 1) { ?>
<p><b><?= bm_e(bm_t('LOX.MEHRERE')) ?></b></p>
<table class="sm-tbl">
<tr><th><?= bm_e(bm_t('ALLG.SPEICHER_EINZ')) ?></th><th><?= bm_e(bm_t('EINST.T_NAME')) ?></th><th><?= bm_e(bm_t('LOX.T_ADRESSE')) ?></th></tr>
<?php foreach ($bm_werte as $bm_nr => $bm_w) { ?>
<tr><td><?= bm_e($bm_nr) ?></td><td><?= bm_e($bm_w['name']) ?></td>
    <td><span class="sm-mono"><?= bm_e($bm_basis) ?>?token=<?= bm_e($bm_token) ?>&amp;aktion=status&amp;geraet=<?= bm_e($bm_nr) ?></span></td></tr>
<?php } ?>
</table>
<?php } ?>
<div class="sm-warnung"><?= bm_t('LOX.IMPORT_WARNUNG') ?></div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<div class="sm-feld">
  <label for="vorlage_geraet"><?= bm_e(bm_t('LOX.L_VORLAGE_GERAET')) ?></label>
  <input data-role="none" type="number" id="vorlage_geraet" name="vorlage_geraet" value="1" min="1" max="6">
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="ein"><?= bm_e(bm_t('LOX.K_VORLAGE_EIN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="aus"><?= bm_e(bm_t('LOX.K_VORLAGE_AUS')) ?></button>
</div>
</form>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= bm_t('LEGENDE.TECHNIK') ?></span>
</div>
</div>

<div class="sm-step"><b><?= bm_e(bm_t('LOX.S4_TITEL')) ?></b><br>
<?= bm_t('LOX.S4_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= bm_e(bm_t('ALLG.EIGENSCHAFT')) ?></th><th><?= bm_e(bm_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= bm_e(bm_t('LOX.T_VA_ADRESSE')) ?></td><td><span class="sm-mono">http://<?= bm_e($bm_host) ?></span></td></tr>
<tr><td><?= bm_e(bm_t('LOX.T_VA_LADEN')) ?></td>
    <td><span class="sm-mono">/plugins/<?= bm_e($bm_p['plugin']) ?>/index.php?token=<?= bm_e($bm_token) ?>&amp;aktion=laden&amp;geraet=1&amp;watt=&lt;v.0&gt;</span></td></tr>
<tr><td><?= bm_e(bm_t('LOX.T_VA_ENTLADEN')) ?></td>
    <td><span class="sm-mono">/plugins/<?= bm_e($bm_p['plugin']) ?>/index.php?token=<?= bm_e($bm_token) ?>&amp;aktion=entladen&amp;geraet=1&amp;watt=&lt;v.0&gt;</span></td></tr>
<tr><td><?= bm_e(bm_t('LOX.T_VA_AUTOMATIK')) ?></td>
    <td><span class="sm-mono">/plugins/<?= bm_e($bm_p['plugin']) ?>/index.php?token=<?= bm_e($bm_token) ?>&amp;aktion=automatik&amp;geraet=1</span></td></tr>
<tr><td><?= bm_e(bm_t('LOX.T_VA_LEBEN')) ?></td>
    <td><span class="sm-mono">/plugins/<?= bm_e($bm_p['plugin']) ?>/index.php?token=<?= bm_e($bm_token) ?>&amp;aktion=lebenszeichen&amp;geraet=1</span></td></tr>
</table>
<div class="sm-warnung"><?= bm_t('LOX.S4_WARNUNG') ?></div>
</div>

<div class="sm-step"><b><?= bm_e(bm_t('LOX.S5_TITEL')) ?></b><br>
<?= bm_t('LOX.S5_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= bm_e(bm_t('ALLG.EIGENSCHAFT')) ?></th><th><?= bm_e(bm_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= bm_e(bm_t('LOX.T_TOKEN')) ?></td><td><span class="sm-mono"><?= bm_e($bm_token) ?></span></td></tr>
<tr><td><?= bm_e(bm_t('LOX.T_TOTMANN')) ?></td><td><?= (int) $bm_cfg['totmann'] ?> <?= bm_e(bm_t('ALLG.SEKUNDEN')) ?></td></tr>
</table>
<?= bm_t('LOX.S5_TEXT2') ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= bm_e(bm_t('LOX.K_TOKEN_NEU')) ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= bm_t('LEGENDE.AKTION_TOKEN') ?></span>
</div>
</div>

<div class="sm-step"><b><?= bm_e(bm_t('LOX.S6_TITEL')) ?></b><br><?= bm_t('LOX.S6_TEXT') ?></div>

<?php
/**
 * Die komplette Baustein-Liste. Pflicht im Hausstandard.
 *
 * Anspruch: Wer die Tabelle von oben nach unten abarbeitet, hat die Funktion
 * nachgebaut, ohne nachzudenken. Loxone Config fuehrt alle Bausteine in der
 * Baustein-Suche (F5).
 *
 * Typ, Name und Parameter stehen als Sprachschluessel drin, die Eingangsspalte
 * ist symbolisch und damit sprachfrei.
 */
/**
 * Der Parametertext einer Befehlserkennung - aus DERSELBEN Quelle wie die
 * Importdatei.
 *
 * Bis 0.9.10 stand er acht Mal ausgeschrieben in der Sprachdatei
 * (BAUSTEIN.P01 bis P08), je Sprache - also sechzehn Abschriften eines
 * Musters, das der Quelltext nebenan selbst baut. Der Bestandslauf von
 * Werkzeuge/suchmuster_pruefen.py hat sie als RISIKO gemeldet: sie zeigten
 * den Suchtext ohne Trennzeichen. Der Anwender schreibt aber die Oberflaeche
 * ab, nicht den Quelltext.
 *
 * Die naheliegende Antwort waere gewesen, sechzehn Zeilen zu berichtigen.
 * Diese hier ist besser: eine Abschrift kann nicht auseinanderlaufen, weil es
 * keine mehr gibt. Muster, Einheit und Grenzen kommen aus bm_check() und
 * bm_status_felder() - denselben Stellen, aus denen die Importdatei entsteht.
 */
function bm_baustein_befehl($feld, $zusatz = '')
{
    $felder = bm_status_felder();
    $info = isset($felder[$feld]) ? $felder[$feld] : array('', '', 0, 0);
    $einheit = ($info[0] !== '')
        ? sprintf(bm_t('BAUSTEIN.P_EINHEIT'), bm_e($info[0]))
        : bm_t('BAUSTEIN.P_OHNE_EINHEIT');
    $text = sprintf(bm_t('BAUSTEIN.P_BEFEHL'),
        '<span class="sm-mono">' . bm_e(bm_check($feld)) . '</span>',
        $einheit, (int) $info[2], (int) $info[3]);
    if ($zusatz !== '') {
        $text .= ' ' . bm_t($zusatz);
    }
    return array('text' => $text);
}

function bm_bausteine()
{
    return array(
        array(1,  'BAUSTEIN.T_VE',       'BAUSTEIN.N01', bm_baustein_befehl('SOC'),    '&mdash;'),
        array(2,  'BAUSTEIN.T_VE',       'BAUSTEIN.N02', bm_baustein_befehl('SOH'),    '&mdash;'),
        array(3,  'BAUSTEIN.T_VE',       'BAUSTEIN.N03', bm_baustein_befehl('UZDIFF'), '&mdash;'),
        array(4,  'BAUSTEIN.T_VE',       'BAUSTEIN.N04', bm_baustein_befehl('TMAX'),   '&mdash;'),
        array(5,  'BAUSTEIN.T_VE',       'BAUSTEIN.N05', bm_baustein_befehl('PBAT', 'BAUSTEIN.Z_PBAT'), '&mdash;'),
        array(6,  'BAUSTEIN.T_VE',       'BAUSTEIN.N06', bm_baustein_befehl('ZYKLEN'), '&mdash;'),
        array(7,  'BAUSTEIN.T_VE',       'BAUSTEIN.N07', bm_baustein_befehl('ALTER'),  '&mdash;'),
        array(8,  'BAUSTEIN.T_VE',       'BAUSTEIN.N08', bm_baustein_befehl('OK', 'BAUSTEIN.Z_OK'), '&mdash;'),
        array(9,  'BAUSTEIN.T_SWS',      'BAUSTEIN.N09', 'BAUSTEIN.P09', 'I &larr; #7'),
        array(10, 'BAUSTEIN.T_NICHT',    'BAUSTEIN.N10', '',             'I &larr; #8'),
        array(11, 'BAUSTEIN.T_ODER',     'BAUSTEIN.N11', '',             'I1 &larr; #9, I2 &larr; #10'),
        array(12, 'BAUSTEIN.T_EVZ',      'BAUSTEIN.N12', 'BAUSTEIN.P12', 'I &larr; #11'),
        array(13, 'BAUSTEIN.T_BENACHR',  'BAUSTEIN.N13', 'BAUSTEIN.P13', 'I &larr; #12'),
        array(14, 'BAUSTEIN.T_SWS',      'BAUSTEIN.N14', 'BAUSTEIN.P14', 'I &larr; #3'),
        array(15, 'BAUSTEIN.T_EVZ',      'BAUSTEIN.N15', 'BAUSTEIN.P15', 'I &larr; #14'),
        array(16, 'BAUSTEIN.T_SWS',      'BAUSTEIN.N16', 'BAUSTEIN.P16', 'I &larr; #2'),
        array(17, 'BAUSTEIN.T_SWS',      'BAUSTEIN.N17', 'BAUSTEIN.P17', 'I &larr; #4'),
        array(18, 'BAUSTEIN.T_ODER',     'BAUSTEIN.N18', '',             'I1 &larr; #15, I2 &larr; #16, I3 &larr; #17'),
        array(19, 'BAUSTEIN.T_BENACHR',  'BAUSTEIN.N19', 'BAUSTEIN.P19', 'I &larr; #18'),
        array(20, 'BAUSTEIN.T_STATUS',   'BAUSTEIN.N20', 'BAUSTEIN.P20', 'I1 &larr; #1, I2 &larr; #2'),
        array(21, 'BAUSTEIN.T_VE',       'BAUSTEIN.N21', 'BAUSTEIN.P21', '&mdash;'),
        array(22, 'BAUSTEIN.T_VEZ',      'BAUSTEIN.N22', 'BAUSTEIN.P22', '&mdash;'),
        array(23, 'BAUSTEIN.T_VERGL',    'BAUSTEIN.N23', 'BAUSTEIN.P23', 'I1 &larr; #21, I2 &larr; #22'),
        array(24, 'BAUSTEIN.T_VEZ',      'BAUSTEIN.N24', 'BAUSTEIN.P24', '&mdash;'),
        array(25, 'BAUSTEIN.T_VERGL',    'BAUSTEIN.N25', 'BAUSTEIN.P25', 'I1 &larr; #1, I2 &larr; #24'),
        array(26, 'BAUSTEIN.T_TASTER',   'BAUSTEIN.N26', 'BAUSTEIN.P26', '&mdash;'),
        array(27, 'BAUSTEIN.T_UND',      'BAUSTEIN.N27', '',             'I1 &larr; #23, I2 &larr; #25, I3 &larr; #26'),
        array(28, 'BAUSTEIN.T_VEZ',      'BAUSTEIN.N28', 'BAUSTEIN.P28', '&mdash;'),
        array(29, 'BAUSTEIN.T_FORMEL',   'BAUSTEIN.N29', 'BAUSTEIN.P29', 'I1 &larr; #27, I2 &larr; #28'),
        array(30, 'BAUSTEIN.T_IMPULS',   'BAUSTEIN.N30', 'BAUSTEIN.P30', '&mdash;'),
        array(31, 'BAUSTEIN.T_UND',      'BAUSTEIN.N31', '',             'I1 &larr; #27, I2 &larr; #30'),
        array(32, 'BAUSTEIN.T_ANALOGSP', 'BAUSTEIN.N32', 'BAUSTEIN.P32', 'I &larr; #29, ' . bm_t('BAUSTEIN.TRIGGER') . ' &larr; #31'),
        array(33, 'BAUSTEIN.T_VA',       'BAUSTEIN.N33', 'BAUSTEIN.P33', 'I &larr; #32'),
        array(34, 'BAUSTEIN.T_FLANKE',   'BAUSTEIN.N34', 'BAUSTEIN.P34', 'I &larr; #27'),
        array(35, 'BAUSTEIN.T_VA',       'BAUSTEIN.N35', 'BAUSTEIN.P35', 'I &larr; #34'),
        array(36, 'BAUSTEIN.T_VA',       'BAUSTEIN.N36', 'BAUSTEIN.P36', 'I &larr; #31'),
    );
}
?>

<div class="sm-step"><b><?= bm_e(bm_t('LOX.S7_TITEL')) ?></b><br>
<?= bm_t('LOX.S7_TEXT') ?>
<table class="sm-tbl">
<tr><th>#</th><th><?= bm_e(bm_t('LOX.T_BAUSTEIN')) ?></th><th><?= bm_e(bm_t('LOX.T_NAMENSVORSCHLAG')) ?></th>
    <th><?= bm_e(bm_t('LOX.T_PARAMETER')) ?></th><th><?= bm_e(bm_t('LOX.T_EINGAENGE')) ?></th></tr>
<?php foreach (bm_bausteine() as $bm_b) { ?>
<tr><td><?= (int) $bm_b[0] ?></td><td><?= bm_t($bm_b[1]) ?></td><td><?= bm_t($bm_b[2]) ?></td>
    <td><?= is_array($bm_b[3]) ? $bm_b[3]['text'] : ($bm_b[3] !== '' ? bm_t($bm_b[3]) : '&mdash;') ?></td><td><?= $bm_b[4] ?></td></tr>
<?php } ?>
</table>
<?= bm_t('LOX.S7_ERLAEUTERUNG') ?>
</div>

<div class="sm-step"><b><?= bm_e(bm_t('LOX.S8_TITEL')) ?></b><br>
<?= bm_t('LOX.S8_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= bm_e(bm_t('LOX.T_PRUEFUNG')) ?></th><th><?= bm_e(bm_t('LOX.T_ERWARTUNG')) ?></th></tr>
<tr><td><span class="sm-mono"><?= bm_e($bm_basis) ?>?token=<?= bm_e($bm_token) ?>&amp;aktion=status</span></td>
    <td><span class="sm-mono">BMS;SOC=...;SOH=...;OK=1</span></td></tr>
<tr><td><span class="sm-mono"><?= bm_e($bm_basis) ?>?aktion=status</span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=TOKEN</span> (HTTP 403)</td></tr>
<tr><td><span class="sm-mono"><?= bm_e($bm_basis) ?>?token=<?= bm_e($bm_token) ?>&amp;aktion=quatsch</span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION</span> (HTTP 400)</td></tr>
<tr><td><span class="sm-mono"><?= bm_e($bm_basis) ?>?token=<?= bm_e($bm_token) ?>&amp;aktion=laden&amp;watt=abc</span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=PARAMETER</span> (HTTP 400)</td></tr>
</table>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $bm_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= bm_e(bm_t('TEST.H_SELBSTPRUEFUNG')) ?></h2>
<p class="sm-hilfe"><?= bm_t('TEST.EINLEITUNG') ?></p>
<table class="sm-tbl">
<tr><th style="width:36px;">&nbsp;</th><th><?= bm_e(bm_t('TEST.T_FRAGE')) ?></th><th><?= bm_e(bm_t('TEST.T_BEFUND')) ?></th></tr>
<?php foreach (bm_pruefungen() as $bm_z) { ?>
<tr><td style="text-align:center;"><?php
    if ($bm_z['stand'] === 1) { echo '<span class="sm-an">&#10004;</span>'; }
    elseif ($bm_z['stand'] === 0) { echo '<span class="sm-aus">&#10008;</span>'; }
    else { echo '<span style="color:#888;">&#9679;</span>'; }
?></td><td><?= $bm_z['frage'] ?></td><td><?= $bm_z['antwort'] ?></td></tr>
<?php } ?>
</table>

<?php foreach ($bm_werte as $bm_nr => $bm_w) {
    if (empty($bm_w['module'])) { continue; } ?>
<h3><?= bm_e(bm_t('TEST.H_ZELLEN')) ?>: <?= bm_e($bm_w['name']) ?></h3>
<table class="sm-tbl">
<tr><th><?= bm_e(bm_t('TEST.T_MODUL')) ?></th><th><?= bm_e(bm_t('TEST.T_UZMAX')) ?></th>
    <th><?= bm_e(bm_t('TEST.T_UZMIN')) ?></th><th><?= bm_e(bm_t('TEST.T_UZDIFF')) ?></th>
    <th><?= bm_e(bm_t('TEST.T_TMAX')) ?></th><th><?= bm_e(bm_t('TEST.T_ZELLZAHL')) ?></th></tr>
<?php foreach ($bm_w['module'] as $bm_m => $bm_md) { ?>
<tr><td><?= (int) $bm_m ?></td>
    <td><?= isset($bm_md['uzmax']) ? bm_e($bm_md['uzmax']) . ' mV' : '&ndash;' ?></td>
    <td><?= isset($bm_md['uzmin']) ? bm_e($bm_md['uzmin']) . ' mV' : '&ndash;' ?></td>
    <td><?= isset($bm_md['uzdiff']) ? bm_e($bm_md['uzdiff']) . ' mV' : '&ndash;' ?></td>
    <td><?= isset($bm_md['tmax']) && $bm_md['tmax'] !== null ? bm_e($bm_md['tmax']) . ' &deg;C' : '&ndash;' ?></td>
    <td><?= isset($bm_md['zellen']) ? count($bm_md['zellen']) : 0 ?></td></tr>
<?php } ?>
</table>
<?php foreach ($bm_w['module'] as $bm_m => $bm_md) {
    if (empty($bm_md['zellen'])) { continue; } ?>
<div class="sm-hilfe"><?= sprintf(bm_e(bm_t('TEST.MODUL_BILD')), (int) $bm_m) ?></div>
<?= bm_zellen_svg($bm_md['zellen']) ?>
<?php } ?>
<div class="sm-hilfe"><?= bm_t('TEST.ZELLEN_HILFE') ?></div>
<?php } ?>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= bm_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= bm_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= bm_t('LEGENDE.AKTION') ?></span>
</div>

<h3><?= bm_e(bm_t('TEST.H_LESEN')) ?></h3>
<div class="sm-knopfreihe">
  <a data-role="none" class="sm-btn sm-b-lesen" href="<?= bm_e($bm_basis) ?>?token=<?= bm_e($bm_token) ?>&amp;aktion=status&amp;geraet=1" target="_blank"><?= bm_e(bm_t('TEST.K_STATUS')) ?></a>
  <a data-role="none" class="sm-btn sm-b-lesen" href="<?= bm_e($bm_basis) ?>?token=<?= bm_e($bm_token) ?>&amp;aktion=zellen&amp;geraet=1" target="_blank"><?= bm_e(bm_t('TEST.K_ZELLEN')) ?></a>
  <a data-role="none" class="sm-btn sm-b-lesen" href="<?= bm_e($bm_basis) ?>?token=<?= bm_e($bm_token) ?>&amp;aktion=liste" target="_blank"><?= bm_e(bm_t('TEST.K_LISTE')) ?></a>
</div>

<h3><?= bm_e(bm_t('TEST.H_TECHNIK')) ?></h3>
<p class="sm-hilfe"><?= bm_t('TEST.TECHNIK_ERKLAERUNG') ?></p>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="selbsttest" value="1"><?= bm_e(bm_t('TEST.K_SELBSTTEST')) ?></button>
  </form>
  <a data-role="none" class="sm-btn sm-b-technik" href="<?= bm_e($bm_basis) ?>?token=<?= bm_e($bm_token) ?>&amp;aktion=roh" target="_blank"><?= bm_e(bm_t('TEST.K_ROH_ABBILD')) ?></a>
</div>

<h3><?= bm_e(bm_t('TEST.H_REGISTER')) ?></h3>
<div class="sm-hinweis"><?= bm_t('TEST.REGISTER_ERKLAERUNG') ?></div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<div class="sm-feld">
  <label for="roh_geraet"><?= bm_e(bm_t('TEST.L_GERAET')) ?></label>
  <input data-role="none" type="number" id="roh_geraet" name="test_geraet" value="1" min="1" max="6">
</div>
<div class="sm-feld">
  <label for="roh_start"><?= bm_e(bm_t('TEST.L_START')) ?></label>
  <input data-role="none" type="text" id="roh_start" name="roh_start" value="0x0500" size="8">
  <div class="sm-hilfe"><?= bm_t('TEST.H_START') ?></div>
</div>
<div class="sm-feld">
  <label for="roh_anzahl"><?= bm_e(bm_t('TEST.L_ANZAHL')) ?></label>
  <input data-role="none" type="number" id="roh_anzahl" name="roh_anzahl" value="16" min="1" max="64">
</div>
<div class="sm-feld">
  <label for="roh_fc"><?= bm_e(bm_t('TEST.L_FC')) ?></label>
  <select data-role="none" id="roh_fc" name="roh_fc">
    <option value="3">3 &ndash; <?= bm_e(bm_t('TEST.FC3')) ?></option>
    <option value="4">4 &ndash; <?= bm_e(bm_t('TEST.FC4')) ?></option>
  </select>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="roh"><?= bm_e(bm_t('TEST.K_REGISTER')) ?></button>
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="abruf"><?= bm_e(bm_t('TEST.K_ABRUF')) ?></button>
</div>
</form>
<?php if ($bm_testausgabe !== '') { ?>
<div class="sm-pre"><?= bm_e($bm_testausgabe) ?></div>
<?php } ?>

<h3><?= bm_e(bm_t('TEST.H_TROCKEN')) ?></h3>
<div class="sm-hinweis"><?= bm_t('TEST.TROCKEN_ERKLAERUNG') ?></div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<div class="sm-feld">
  <label for="tl_geraet"><?= bm_e(bm_t('TEST.L_GERAET')) ?></label>
  <input data-role="none" type="number" id="tl_geraet" name="test_geraet" value="1" min="1" max="6">
</div>
<div class="sm-feld">
  <label for="tl_watt"><?= bm_e(bm_t('TEST.L_WATT')) ?></label>
  <input data-role="none" type="number" id="tl_watt" name="test_watt" value="500" min="0" max="30000">
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="trockenlauf" value="laden"><?= bm_e(bm_t('TEST.K_TROCKEN_LADEN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="trockenlauf" value="entladen"><?= bm_e(bm_t('TEST.K_TROCKEN_ENTLADEN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="trockenlauf" value="sperren"><?= bm_e(bm_t('TEST.K_TROCKEN_SPERREN')) ?></button>
</div>
</form>

<h3><?= bm_e(bm_t('TEST.H_MITSCHNITT')) ?></h3>
<div class="sm-hinweis"><?= bm_t('TEST.MITSCHNITT_ERKLAERUNG') ?></div>
<?php $bm_mrest = bm_mitschnitt_laeuft($bm_cfg); ?>
<?php if ($bm_mrest > 0) { ?>
<div class="sm-warnung"><?= sprintf(bm_e(bm_t('TEST.MITSCHNITT_LAEUFT')), (int) $bm_mrest) ?></div>
<?php } ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="mitschnitt" value="ein"><?= bm_e(bm_t('TEST.K_MITSCHNITT_EIN')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="mitschnitt" value="aus"><?= bm_e(bm_t('TEST.K_MITSCHNITT_AUS')) ?></button>
  </form>
</div>
<?php
$bm_mdatei = bm_mitschnitt_datei();
if (is_file($bm_mdatei)) {
    $bm_mz = array_slice(array_reverse(file($bm_mdatei,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()), 0, 60);
    if ($bm_mz) { ?>
<div class="sm-log"><?= bm_e(implode("\n", $bm_mz)) ?></div>
<?php }
} ?>

<h3><?= bm_e(bm_t('TEST.H_SCHALTEN')) ?></h3>
<div class="sm-warnung"><?= bm_t('TEST.SCHALTEN_WARNUNG') ?></div>
<?php if (empty($bm_cfg['steuerung_ein'])) { ?>
<div class="sm-hinweis"><?= bm_t('TEST.SCHALTEN_GESPERRT') ?></div>
<?php } ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<div class="sm-feld">
  <label for="test_geraet"><?= bm_e(bm_t('TEST.L_GERAET')) ?></label>
  <input data-role="none" type="number" id="test_geraet" name="test_geraet" value="1" min="1" max="6">
</div>
<div class="sm-feld">
  <label for="test_watt"><?= bm_e(bm_t('TEST.L_WATT')) ?></label>
  <input data-role="none" type="number" id="test_watt" name="test_watt" value="200" min="0" max="30000">
  <div class="sm-hilfe"><?= bm_t('TEST.H_WATT') ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="laden"><?= bm_e(bm_t('TEST.K_LADEN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="entladen"><?= bm_e(bm_t('TEST.K_ENTLADEN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="automatik"><?= bm_e(bm_t('TEST.K_AUTOMATIK')) ?></button>
</div>
</form>

<div class="sm-warnung"><b><?= bm_e(bm_t('TEST.H_UNGEPRUEFT')) ?></b><br><?= bm_t('TEST.UNGEPRUEFT') ?></div>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $bm_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= bm_e(bm_t('LOG.H_TITEL')) ?></h2>
<?php
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo LBWeb::loglist_html();
}
?>
<p class="sm-hilfe"><?= bm_t('LOG.ERKLAERUNG') ?><br>
<span class="sm-mono"><?= bm_e($bm_p['log']) ?></span></p>
<?php if ($bm_logzeilen) { ?>
<div class="sm-log"><?= bm_e(implode("\n", $bm_logzeilen)) ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= bm_t('LOG.LEER') ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= bm_t('LEGENDE.AKTION_LOG') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="log_leeren" value="1"><?= bm_e(bm_t('LOG.K_LEEREN')) ?></button>
  </form>
</div>
</div>

</div><!-- /sm-wrap -->

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	zeige(<?= json_encode($bm_tab) ?>);
})();
</script>
<?php
if ($bm_rahmen) {
    LBWeb::lbfooter();
}
