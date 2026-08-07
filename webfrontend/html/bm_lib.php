<?php
/**
 * Batterie-Heimspeicher (BMS) - gemeinsame Bibliothek
 *
 * Liegt unter webfrontend/html/, weil der Miniserver-Endpunkt sie ebenso
 * braucht wie die Oberflaeche und der Dienst. So gibt es EINE Datei statt
 * dreier Kopien, die auseinanderlaufen (Hausregel: vor dem Anlegen einer
 * Bibliothek nachsehen, ob es schon eine gibt - in BEIDEN Zweigen).
 *
 * ---------------------------------------------------------------------------
 * Worum es geht
 * ---------------------------------------------------------------------------
 * Wechselrichter lassen sich meist per Modbus auslesen - der Speicher dahinter
 * aber nur so weit, wie der Wechselrichter ihn durchreicht. Zellspannungen,
 * Zelltemperaturen, Zyklenzahl und der Gesundheitszustand (SoH) bleiben dabei
 * regelmaessig aussen vor. Dieses Plugin spricht deshalb, wo es geht, das
 * Batterie-Management-System (BMS) unmittelbar an.
 *
 * Drei Uebertragungswege, weil die Hersteller drei verschiedene Wege gehen:
 *
 *   modbus_tcp       Modbus TCP mit MBAP-Kopf, Port 502.
 *                    Huawei LUNA2000 ueber den SUN2000-Wechselrichter.
 *
 *   modbus_rtu_tcp   Modbus RTU (Adresse + PDU + CRC16) in einem TCP-Strom,
 *                    OHNE MBAP-Kopf. Das ist kein Schoenheitsfehler, sondern
 *                    genau das, was die BYD-BCU auf Port 8080 spricht.
 *
 *   pylontech_rs485  Kein Modbus. Ein zeilenbasiertes ASCII-Protokoll ueber
 *                    RS485, Rahmen zwischen '~' und CR.
 *
 * ---------------------------------------------------------------------------
 * Herkunft der Registerangaben
 * ---------------------------------------------------------------------------
 * Jedes eingebaute Profil traegt ein Feld 'quelle' und ein Feld 'stand'.
 * 'stand' ist entweder
 *
 *   'dokumentiert'  - die Adresse steht in einer benannten, oeffentlich
 *                     nachlesbaren Quelle, die unter 'quelle' genannt ist;
 *   'unbestaetigt'  - die Adresse ist im Umlauf, aber hier NICHT gegen ein
 *                     Geraet gemessen worden.
 *
 * Die Oberflaeche zeigt diesen Stand ungeschoent an. Das ist Absicht: eine
 * Zahl, die niemand gemessen hat, darf nicht aussehen wie eine, die jemand
 * gemessen hat (Hausregel aus der LoxoneIcons-Sitzung).
 *
 * Praefix 'bm_', weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

if (!function_exists('bm_e')) {
    function bm_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

function bm_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        foreach (array('/opt/loxberry', '/home/loxberry/loxberry') as $k) {
            if (is_dir($k)) {
                $home = $k;
                break;
            }
        }
    }
    // Der Pluginordner ergibt sich aus dem Ablageort dieser Datei. Der
    // MD5-Schluessel aus der plugindatabase.json wird bewusst NICHT benutzt -
    // er wird aus Autorenname, E-Mail und Plugin-Name gebildet und aendert
    // sich bei jedem Fork.
    $dir = basename(dirname(__FILE__));
    if ($home && !is_dir($home . '/config/plugins/' . $dir)) {
        foreach (array(getenv('LBPPLUGINDIR'), 'batteriebms') as $kand) {
            if ($kand && is_dir($home . '/config/plugins/' . $kand)) {
                $dir = $kand;
                break;
            }
        }
    }
    if ($home) {
        $p = array(
            'home'      => $home,
            'plugin'    => $dir,
            'configdir' => $home . '/config/plugins/' . $dir,
            'config'    => $home . '/config/plugins/' . $dir . '/batteriebms.json',
            'sicherung' => $home . '/config/plugins/' . $dir . '.backup.json',
            'datadir'   => $home . '/data/plugins/' . $dir,
            'bindir'    => $home . '/bin/plugins/' . $dir,
            'logdir'    => $home . '/log/plugins/' . $dir,
            'log'       => $home . '/log/plugins/' . $dir . '/batteriebms.log',
        );
    } else {
        $basis = dirname(dirname(__DIR__));
        $p = array(
            'home'      => '',
            'plugin'    => $dir,
            'configdir' => $basis . '/config',
            'config'    => $basis . '/config/batteriebms.json',
            'sicherung' => $basis . '/config/batteriebms.backup.json',
            'datadir'   => $basis . '/data',
            'bindir'    => $basis . '/bin',
            'logdir'    => $basis . '/log',
            'log'       => $basis . '/log/batteriebms.log',
        );
    }
    return $p;
}

/* ==================================================================
 * Die einheitlichen Messgroessen
 *
 * Jedes Profil bildet seine Register auf DIESE Namen ab. Dadurch sieht die
 * Loxone-Seite bei jedem Hersteller gleich aus - der Anwender baut seine
 * Bausteine einmal und kann den Speicher spaeter tauschen.
 *
 * Ein Wert, den ein Geraet nicht liefert, bleibt null. Er wird NIE zu 0
 * gemacht: eine 0 waere eine stille Falschaussage, und genau die ist die
 * schlimmste Art von Fehler.
 * ================================================================== */
function bm_status_felder()
{
    return array(
        'SOC'      => array('%',   'BM_FELD.SOC',      0,    100),
        'SOH'      => array('%',   'BM_FELD.SOH',      0,    100),
        'UBAT'     => array('V',   'BM_FELD.UBAT',     0,    1000),
        'IBAT'     => array('A',   'BM_FELD.IBAT',     -600, 600),
        'PBAT'     => array('W',   'BM_FELD.PBAT',     -30000, 30000),
        'TMAX'     => array('C',   'BM_FELD.TMAX',     -40,  100),
        'TMIN'     => array('C',   'BM_FELD.TMIN',     -40,  100),
        'UZMAX'    => array('V',   'BM_FELD.UZMAX',    0,    5),
        'UZMIN'    => array('V',   'BM_FELD.UZMIN',    0,    5),
        'UZDIFF'   => array('mV',  'BM_FELD.UZDIFF',   0,    2000),
        'ZYKLEN'   => array('',    'BM_FELD.ZYKLEN',   0,    20000),
        'KAPAZ'    => array('kWh', 'BM_FELD.KAPAZ',    0,    500),
        'MODULE'   => array('',    'BM_FELD.MODULE',   0,    64),
        'ZELLEN'   => array('',    'BM_FELD.ZELLEN',   0,    1024),
        'FEHLER'   => array('',    'BM_FELD.FEHLER',   0,    65535),
        'WARNUNG'  => array('',    'BM_FELD.WARNUNG',  0,    65535),
        'LADENMAX' => array('W',   'BM_FELD.LADENMAX', 0,    30000),
        'ENTLMAX'  => array('W',   'BM_FELD.ENTLMAX',  0,    30000),
        'MODUS'    => array('',    'BM_FELD.MODUS',    0,    10),
        'ALTER'    => array('s',   'BM_FELD.ALTER',    0,    86400),
        'OK'       => array('',    'BM_FELD.OK',       0,    1),
    );
}

/* ==================================================================
 * Eingebaute Geraeteprofile
 *
 * Aufbau eines Profils:
 *   name        Klartext fuer die Oberflaeche
 *   transport   modbus_tcp | modbus_rtu_tcp | pylontech_rs485
 *   port        Vorgabe-Port (TCP) - der Anwender kann ihn ueberschreiben
 *   unit        Vorgabe-Geraeteadresse (Modbus Slave / Unit ID)
 *   stand       dokumentiert | unbestaetigt   (siehe Kopf der Datei)
 *   quelle      Wo die Adressen herkommen. Pflicht.
 *   bloecke     Zusammenhaengende Leseanforderungen: fc, start, anzahl
 *   felder      Abbildung Messgroesse -> Register
 *   zellen      Verfahren fuer die Zelldaten (oder leer)
 *   steuerung   Schreibbefehle (oder leer, wenn das Geraet keine kennt)
 *
 * Registerbeschreibung:
 *   reg     Registeradresse
 *   typ     u16 | s16 | u32 | s32 | u16hi | u16lo | ascii
 *   faktor  Multiplikator auf den Rohwert
 *   nk      Nachkommastellen der Ausgabe
 *   wort    bei 32 Bit: 'hoch' (High Word zuerst, Vorgabe) oder 'nieder'
 * ================================================================== */
function bm_profile_eingebaut()
{
    return array(

        /* -----------------------------------------------------------------
         * BYD Battery-Box Premium HVS / HVM / LVS / LVL
         *
         * Die BCU (Battery Control Unit) spricht Modbus RTU in einem TCP-Strom
         * auf 192.168.16.254:8080, Unit ID 1. Die Adresse 192.168.16.254 ist
         * fest im Geraet vergeben und liegt NICHT im Heimnetz - dorthin muss
         * eine statische Route zeigen. Das steht so im Reiter Einstellungen.
         *
         * WICHTIG: Die BCU laesst nur EINE Verbindung gleichzeitig zu. Laeuft
         * die Windows-Software 'BE Connect Plus' oder ein Node-RED-Ablauf, so
         * bekommt dieses Plugin keine Antwort - und umgekehrt.
         * ----------------------------------------------------------------- */
        'byd_battery_box' => array(
            'name'      => 'BYD Battery-Box Premium (HVS, HVM, LVS, LVL)',
            'transport' => 'modbus_rtu_tcp',
            'port'      => 8080,
            'unit'      => 1,
            'ip'        => '192.168.16.254',
            'stand'     => 'dokumentiert',
            'quelle'    => 'github.com/sarnau/BYD-Battery-Box-Infos, Read_Modbus.py',
            'hinweis'   => 'BM_PROFIL.H_BYD',
            'bloecke'   => array(
                array('fc' => 3, 'start' => 0x0000, 'anzahl' => 0x13),
                array('fc' => 3, 'start' => 0x0500, 'anzahl' => 25),
            ),
            'felder' => array(
                'SOC'    => array('reg' => 0x0500, 'typ' => 'u16', 'faktor' => 1,    'nk' => 0),
                'UZMAX'  => array('reg' => 0x0501, 'typ' => 'u16', 'faktor' => 0.01, 'nk' => 2),
                'UZMIN'  => array('reg' => 0x0502, 'typ' => 'u16', 'faktor' => 0.01, 'nk' => 2),
                'SOH'    => array('reg' => 0x0503, 'typ' => 'u16', 'faktor' => 1,    'nk' => 0),
                'IBAT'   => array('reg' => 0x0504, 'typ' => 's16', 'faktor' => 0.1,  'nk' => 1),
                'UBAT'   => array('reg' => 0x0505, 'typ' => 'u16', 'faktor' => 0.01, 'nk' => 2),
                'TMAX'   => array('reg' => 0x0506, 'typ' => 'u16', 'faktor' => 1,    'nk' => 0),
                'TMIN'   => array('reg' => 0x0507, 'typ' => 'u16', 'faktor' => 1,    'nk' => 0),
                'FEHLER' => array('reg' => 0x050D, 'typ' => 'u16', 'faktor' => 1,    'nk' => 0),
                'ZYKLEN' => array('reg' => 0x0511, 'typ' => 'u16', 'faktor' => 1,    'nk' => 0),
                'MODULE' => array('reg' => 0x0010, 'typ' => 'u16', 'faktor' => 1,    'nk' => 0,
                                  'maske' => 0x000F),
            ),
            // Leistung ist kein eigenes Register: Strom mal Ausgangsspannung.
            // 0x0510 ist die Ausgangsspannung in 0.01 V.
            'rechnung' => array(
                'PBAT' => array('art' => 'produkt', 'a' => 0x0504, 'a_typ' => 's16', 'a_faktor' => 0.1,
                                'b' => 0x0510, 'b_typ' => 'u16', 'b_faktor' => 0.01, 'nk' => 0),
            ),
            'zellen' => array(
                'verfahren'   => 'byd_bms',
                'anforderung' => 0x0550,   // hierhin [BMS-Index, 0x8100] schreiben
                'antwort'     => 0x0551,   // hier auf 0x8801 warten
                'daten'       => 0x0558,   // 5 x 0x41 Register lesen, je das erste verwerfen
                'blockgroesse' => 0x41,
                'bloecke'     => 5,
                'zellen_ab'   => 48,       // Data[48 + Modul*16 + Zelle] = mV
                'zellen_je_modul' => 16,
                'temp_ab'     => 177,      // Data[177 + Modul*4 + i], zwei Werte je Wort
                'temp_je_modul' => 4,
            ),
            // Die BCU nimmt keine Lade- oder Entladebefehle entgegen. Das ist
            // keine Luecke dieses Plugins: die Regie hat der Wechselrichter.
            'steuerung' => array(),
        ),

        /* -----------------------------------------------------------------
         * Huawei LUNA2000 ueber den SUN2000-Wechselrichter
         *
         * Es gibt keinen unmittelbaren Zugang zum BMS. Der Wechselrichter
         * reicht die Speicherwerte auf seinem Modbus durch - ueber den Smart
         * Dongle auf Port 502, im WLAN-Zugangspunkt des Wechselrichters auf
         * Port 6607 beziehungsweise 502 je nach Firmware.
         *
         * Die Speicherregister ab 37760 sind in den 'Solar Inverter Modbus
         * Interface Definitions' beschrieben. Die Register der einzelnen
         * Batteriepakete ab 38200 sind im Umlauf, hier aber NICHT gegen ein
         * Geraet gemessen - deshalb stehen sie in einem eigenen Profil mit
         * dem Stand 'unbestaetigt'.
         * ----------------------------------------------------------------- */
        'huawei_luna2000' => array(
            'name'      => 'Huawei LUNA2000 (ueber SUN2000, Grunddaten)',
            'transport' => 'modbus_tcp',
            'port'      => 502,
            'unit'      => 1,
            'stand'     => 'dokumentiert',
            'quelle'    => 'Solar Inverter Modbus Interface Definitions (Huawei); '
                         . 'gegengelesen an github.com/wlcrs/huawei-solar-lib',
            'hinweis'   => 'BM_PROFIL.H_HUAWEI',
            'bloecke'   => array(
                array('fc' => 3, 'start' => 37760, 'anzahl' => 30),
            ),
            'felder' => array(
                'SOC'   => array('reg' => 37760, 'typ' => 'u16', 'faktor' => 0.1, 'nk' => 1),
                'MODUS' => array('reg' => 37762, 'typ' => 'u16', 'faktor' => 1,   'nk' => 0),
                'PBAT'  => array('reg' => 37765, 'typ' => 's32', 'faktor' => 1,   'nk' => 0),
                'UBAT'  => array('reg' => 37763, 'typ' => 'u16', 'faktor' => 0.1, 'nk' => 1),
            ),
            'rechnung' => array(),
            'zellen'   => array(),
            /* Schreibbefehle als Schrittfolge.
             *
             * Jeder Schritt ist ein Registerschreibvorgang. 'wert' ist
             * entweder eine feste Zahl oder der Platzhalter '@watt' fuer den
             * uebergebenen Sollwert.
             *
             * STAND: unbestaetigt. Die Registeradressen der Speichersteuerung
             * sind im Umlauf, aber in diesem Plugin nie an einem Geraet
             * ausgeloest worden. Wer sie benutzt, prueft zuerst mit dem
             * Rohregister-Leser im Reiter Test, welcher Wert dort steht,
             * bevor er schreibt. Die Schrittfolge laesst sich in einem
             * eigenen Profil ohne Aenderung am Quelltext ersetzen.
             */
            'steuerung_stand' => 'unbestaetigt',
            'steuerung' => array(
                'laden' => array(
                    'text' => 'BM_STEUER.HW_LADEN',
                    'schritte' => array(
                        array('reg' => 47086, 'typ' => 'u16', 'wert' => 2),
                        array('reg' => 47075, 'typ' => 'u32', 'wert' => '@watt'),
                    ),
                ),
                'entladen' => array(
                    'text' => 'BM_STEUER.HW_ENTLADEN',
                    'schritte' => array(
                        array('reg' => 47086, 'typ' => 'u16', 'wert' => 2),
                        array('reg' => 47077, 'typ' => 'u32', 'wert' => '@watt'),
                    ),
                ),
                'automatik' => array(
                    'text' => 'BM_STEUER.HW_AUTOMATIK',
                    'schritte' => array(
                        array('reg' => 47086, 'typ' => 'u16', 'wert' => 0),
                    ),
                ),
            ),
        ),

        /* -----------------------------------------------------------------
         * Huawei LUNA2000 - Batteriepakete (Zelldaten)
         *
         * ACHTUNG: Stand 'unbestaetigt'. Die Adressen sind im Umlauf, aber in
         * diesem Plugin nie gegen ein Geraet gehalten worden. Wer sie benutzt,
         * prueft sie zuerst im Reiter Test mit dem Rohregister-Leser gegen die
         * Anzeige der FusionSolar-App.
         * ----------------------------------------------------------------- */
        'huawei_luna2000_pakete' => array(
            'name'      => 'Huawei LUNA2000 - Batteriepaket 1 (ungeprueft)',
            'transport' => 'modbus_tcp',
            'port'      => 502,
            'unit'      => 1,
            'stand'     => 'unbestaetigt',
            'quelle'    => 'Im Umlauf befindliche Registerlisten; hier NICHT gemessen.',
            'hinweis'   => 'BM_PROFIL.H_HUAWEI_PAKETE',
            'bloecke'   => array(
                array('fc' => 3, 'start' => 38200, 'anzahl' => 50),
            ),
            'felder' => array(
                'UBAT'   => array('reg' => 38228, 'typ' => 'u16', 'faktor' => 0.1, 'nk' => 1),
                'IBAT'   => array('reg' => 38229, 'typ' => 's16', 'faktor' => 0.1, 'nk' => 1),
                'SOC'    => array('reg' => 38229, 'typ' => 'u16', 'faktor' => 0.1, 'nk' => 1),
                'SOH'    => array('reg' => 38214, 'typ' => 'u16', 'faktor' => 0.1, 'nk' => 1),
                'TMAX'   => array('reg' => 38236, 'typ' => 's16', 'faktor' => 0.1, 'nk' => 1),
                'TMIN'   => array('reg' => 38238, 'typ' => 's16', 'faktor' => 0.1, 'nk' => 1),
                'UZMAX'  => array('reg' => 38232, 'typ' => 'u16', 'faktor' => 0.001, 'nk' => 3),
                'UZMIN'  => array('reg' => 38234, 'typ' => 'u16', 'faktor' => 0.001, 'nk' => 3),
            ),
            'rechnung'  => array(),
            'zellen'    => array(),
            'steuerung' => array(),
        ),

        /* -----------------------------------------------------------------
         * Pylontech US2000 / US3000 / US5000 / Force
         *
         * Kein Modbus. Der Konsolenanschluss spricht ein zeilenbasiertes
         * ASCII-Protokoll ueber RS485, 115200 8N1 (aeltere Fassungen 9600).
         *
         * Rahmen:  ~ VER(2) ADR(2) CID1(2) CID2(2) LENID(4) INFO CHKSUM(4) CR
         * VER = 20, CID1 = 46. Alle Felder als Hex-ZIFFERN, nicht als Bytes.
         *
         * CID2 = 42  Analogwerte je Modul: Zellspannungen, Temperaturen,
         *            Strom, Spannung, Rest- und Gesamtkapazitaet, Zyklen
         * CID2 = 47  Systemgrenzen
         * CID2 = 92  Lade-/Entladevorgaben und Statusflags
         * CID2 = 93  Seriennummer eines Moduls
         *
         * Ein SoH-Register gibt es nicht. Der Gesundheitszustand ergibt sich
         * aus Gesamtkapazitaet geteilt durch Nennkapazitaet; die Nennkapazitaet
         * traegt der Anwender ein. Ohne diese Angabe bleibt SOH leer, statt
         * eine Zahl zu erfinden.
         * ----------------------------------------------------------------- */
        'pylontech_konsole' => array(
            'name'      => 'Pylontech US2000/US3000/US5000/Force (RS485-Konsole)',
            'transport' => 'pylontech_rs485',
            'port'      => 0,
            'unit'      => 2,
            'geraetedatei' => '/dev/ttyUSB0',
            'baud'      => 115200,
            'stand'     => 'dokumentiert',
            'quelle'    => 'RS485-protocol-pylon-low-voltage V3.3 (2018-08-21); '
                         . 'gegengelesen an github.com/Frankkkkk/python-pylontech',
            'hinweis'   => 'BM_PROFIL.H_PYLONTECH',
            'bloecke'   => array(),
            'felder'    => array(),
            'rechnung'  => array(),
            'zellen'    => array('verfahren' => 'pylontech'),
            'steuerung' => array(),
        ),

        /* -----------------------------------------------------------------
         * Freies Modbus-Profil
         *
         * Vorlage zum Herunterladen und Anpassen. Ein eigenes Profil ist eine
         * JSON-Datei im Ordner data/plugins/<ordner>/profile/ und braucht
         * KEINE Aenderung am Quelltext.
         * ----------------------------------------------------------------- */
        'generisch' => array(
            'name'      => 'Frei einstellbares Modbus-Profil',
            'transport' => 'modbus_tcp',
            'port'      => 502,
            'unit'      => 1,
            'stand'     => 'dokumentiert',
            'quelle'    => 'Vorlage - die Adressen traegt der Anwender ein.',
            'hinweis'   => 'BM_PROFIL.H_GENERISCH',
            'bloecke'   => array(
                array('fc' => 3, 'start' => 0, 'anzahl' => 10),
            ),
            'felder' => array(
                'SOC' => array('reg' => 0, 'typ' => 'u16', 'faktor' => 1, 'nk' => 0),
            ),
            'rechnung'  => array(),
            'zellen'    => array(),
            'steuerung' => array(),
        ),
    );
}

/**
 * Alle Profile: eingebaute plus eigene aus data/.../profile/*.json
 *
 * Eine eigene Datei mit demselben Schluessel ersetzt das eingebaute Profil
 * vollstaendig. Das ist gewollt: wer ein Profil korrigiert, will nicht mit
 * einer halb ueberschriebenen Mischung dastehen.
 */
function bm_profile()
{
    static $alle = null;
    if ($alle !== null) {
        return $alle;
    }
    $alle = bm_profile_eingebaut();
    foreach ($alle as $k => $v) {
        $alle[$k]['herkunft'] = 'eingebaut';
    }
    $ordner = bm_paths()['datadir'] . '/profile';
    if (is_dir($ordner)) {
        foreach ((array) glob($ordner . '/*.json') as $datei) {
            $schluessel = preg_replace('/[^a-z0-9_]/', '', strtolower(basename($datei, '.json')));
            if ($schluessel === '') {
                continue;
            }
            $d = json_decode((string) @file_get_contents($datei), true);
            if (!is_array($d) || !isset($d['transport'])) {
                // Eine unlesbare Profildatei ist ein Fehler, kein leeres
                // Profil. Sie wird gemeldet und uebergangen, nicht ergaenzt.
                bm_log_gebremst('profil_' . $schluessel,
                    'Profildatei nicht lesbar oder ohne Feld transport, uebergangen: ' . $datei);
                continue;
            }
            $d['herkunft'] = 'eigene Datei';
            $d['datei'] = $datei;
            if (!isset($d['name'])) {
                $d['name'] = $schluessel;
            }
            if (!isset($d['stand'])) {
                $d['stand'] = 'unbestaetigt';
            }
            if (!isset($d['quelle'])) {
                $d['quelle'] = basename($datei);
            }
            foreach (array('bloecke', 'felder', 'rechnung', 'zellen', 'steuerung') as $f) {
                if (!isset($d[$f]) || !is_array($d[$f])) {
                    $d[$f] = array();
                }
            }
            $alle[$schluessel] = $d;
        }
    }
    return $alle;
}

function bm_profil($schluessel)
{
    $alle = bm_profile();
    return isset($alle[$schluessel]) ? $alle[$schluessel] : null;
}

function bm_transporte()
{
    return array('modbus_tcp', 'modbus_rtu_tcp', 'pylontech_rs485');
}

/* ==================================================================
 * Konfiguration
 * ================================================================== */

function bm_vorgaben()
{
    return array(
        'geraete'        => array(),
        'intervall'      => 30,     // Sekunden zwischen zwei Abrufen
        'zelltakt'       => 300,    // Sekunden zwischen zwei Zelldaten-Abrufen
        'mqtt_ein'       => 0,
        'mqtt_topic'     => 'batteriebms',
        'steuerung_ein'  => 0,
        'soc_min'        => 10,     // untere Grenze fuer erzwungenes Entladen
        'soc_max'        => 95,     // obere Grenze fuer erzwungenes Laden
        'totmann'        => 300,    // Sekunden ohne neuen Sollwert bis Automatik
        'schreibbremse'  => 20,     // Sekunden zwischen zwei Schreibbefehlen je Geraet
        'verlauf_tage'   => 14,
        'aktionstoken'   => '',
        'wartezeit'      => 8,
        'zeitueberschreitung' => 4, // Sekunden je Modbus-Anforderung
        'drift_warnung'  => 50,     // mV Zellspannungsdrift, ab der gewarnt wird
    );
}

function bm_json_lesen($pfad)
{
    if (!is_file($pfad)) {
        return array();
    }
    $d = json_decode((string) @file_get_contents($pfad), true);
    return is_array($d) ? $d : array();
}

/** Erst in eine Nebendatei, dann umbenennen - so liest niemand eine halb
 *  geschriebene Datei. */
function bm_json_schreiben($pfad, $daten, $rechte = null)
{
    $ordner = dirname($pfad);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return false;
    }
    $tmp = $pfad . '.tmp';
    $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        @unlink($tmp);
        return false;
    }
    if ($rechte !== null) {
        @chmod($tmp, $rechte);
    }
    return @rename($tmp, $pfad);
}

function bm_config()
{
    $p = bm_paths();
    // Selbstheilung: fehlende oder leere Konfiguration aus der Sicherung holen.
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    if (($roh === '' || $roh === '{}') && is_file($p['sicherung'])) {
        @mkdir($p['configdir'], 0775, true);
        @copy($p['sicherung'], $p['config']);
    }
    return array_merge(bm_vorgaben(), bm_json_lesen($p['config']));
}

function bm_config_speichern($cfg)
{
    $p = bm_paths();
    // Die Konfiguration enthaelt das Aktionstoken - deshalb 0600, nicht 0644.
    if (!bm_json_schreiben($p['config'], $cfg, 0600)) {
        return false;
    }
    @copy($p['config'], $p['sicherung']);
    @chmod($p['sicherung'], 0600);
    return true;
}

/**
 * Geraeteliste, 1-basiert, nur vollstaendige Eintraege.
 *
 * Unvollstaendige Zeilen werden uebergangen - gemeldet werden sie schon beim
 * Speichern in der Oberflaeche, nicht erst hier.
 */
function bm_geraete()
{
    $cfg = bm_config();
    $profile = bm_profile();
    $out = array();
    $n = 0;
    foreach ((array) $cfg['geraete'] as $g) {
        if (!is_array($g)) {
            continue;
        }
        $pk = isset($g['profil']) ? (string) $g['profil'] : '';
        if (!isset($profile[$pk])) {
            continue;
        }
        $pr = $profile[$pk];
        $seriell = ($pr['transport'] === 'pylontech_rs485');
        $ip = trim((string) (isset($g['ip']) ? $g['ip'] : ''));
        $dev = trim((string) (isset($g['geraetedatei']) ? $g['geraetedatei'] : ''));
        if ($seriell) {
            if ($dev === '') {
                $dev = isset($pr['geraetedatei']) ? $pr['geraetedatei'] : '/dev/ttyUSB0';
            }
        } else {
            if ($ip === '') {
                $ip = isset($pr['ip']) ? $pr['ip'] : '';
            }
            if ($ip === '') {
                continue;
            }
        }
        $n++;
        $out[$n] = array(
            'nr'           => $n,
            'name'         => trim((string) (isset($g['name']) ? $g['name'] : '')) !== ''
                              ? trim((string) $g['name']) : ('Speicher ' . $n),
            'profil'       => $pk,
            'profilname'   => $pr['name'],
            'transport'    => $pr['transport'],
            'stand'        => $pr['stand'],
            'ip'           => $ip,
            'port'         => isset($g['port']) && $g['port'] !== ''
                              ? max(1, min(65535, (int) $g['port'])) : (int) $pr['port'],
            'unit'         => isset($g['unit']) && $g['unit'] !== ''
                              ? max(0, min(247, (int) $g['unit'])) : (int) $pr['unit'],
            'geraetedatei' => $dev,
            'baud'         => isset($g['baud']) && $g['baud'] !== ''
                              ? (int) $g['baud'] : (int) (isset($pr['baud']) ? $pr['baud'] : 115200),
            // Nennkapazitaet in kWh. Ohne sie bleibt SOH bei Pylontech leer.
            'nennkapaz'    => isset($g['nennkapaz']) && $g['nennkapaz'] !== ''
                              ? (float) $g['nennkapaz'] : 0.0,
            // Vorzeichen von Strom und Leistung. Welche Richtung ein Geraet
            // als positiv meldet, steht in keinem Datenblatt verlaesslich -
            // deshalb ein Schalter statt einer Annahme. Hausvorgabe:
            // positiv = laden.
            'vorzeichen'   => (isset($g['vorzeichen']) && (int) $g['vorzeichen'] === -1) ? -1 : 1,
            'schreiben'    => !empty($g['schreiben']) ? 1 : 0,
            'max_laden'    => isset($g['max_laden']) && $g['max_laden'] !== ''
                              ? max(0, min(30000, (int) $g['max_laden'])) : 0,
            'max_entladen' => isset($g['max_entladen']) && $g['max_entladen'] !== ''
                              ? max(0, min(30000, (int) $g['max_entladen'])) : 0,
        );
    }
    return $out;
}

function bm_geraet($nr)
{
    $g = bm_geraete();
    $nr = max(1, (int) $nr);
    return isset($g[$nr]) ? $g[$nr] : null;
}

/** Zufallstoken fuer den unangemeldeten Endpunkt. */
function bm_token_erzeugen($laenge = 24)
{
    $zeichen = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) {
        $t .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    return $t;
}

function bm_token()
{
    $cfg = bm_config();
    if (trim((string) $cfg['aktionstoken']) === '') {
        $cfg['aktionstoken'] = bm_token_erzeugen();
        bm_config_speichern($cfg);
    }
    return (string) $cfg['aktionstoken'];
}

/* ---------------- Zwischenspeicher ---------------- */

function bm_loxone()
{
    return bm_json_lesen(bm_paths()['datadir'] . '/loxone.json');
}

function bm_zustand()
{
    return bm_json_lesen(bm_paths()['datadir'] . '/zustand.json');
}

function bm_werte()
{
    $l = bm_loxone();
    return isset($l['geraete']) && is_array($l['geraete']) ? $l['geraete'] : array();
}

/** Alter des Abbilds in Sekunden, oder -1 wenn es keines gibt. */
function bm_alter()
{
    $l = bm_loxone();
    return isset($l['ts']) ? max(0, time() - (int) $l['ts']) : -1;
}

/* ---------------- Protokollierung ---------------- */

function bm_log($text)
{
    $p = bm_paths();
    if (!is_dir($p['logdir'])) {
        @mkdir($p['logdir'], 0775, true);
    }
    if (is_file($p['log']) && filesize($p['log']) > 512000) {
        // Rotation: die letzten 400 Zeilen behalten
        $rest = array_slice(file($p['log'], FILE_IGNORE_NEW_LINES) ?: array(), -400);
        @file_put_contents($p['log'], implode("\n", $rest) . "\n");
    }
    @file_put_contents($p['log'], '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n", FILE_APPEND);
}

/** Dieselbe Meldung hoechstens einmal je Zeitfenster - sonst wird die
 *  Logdatei durch eine Dauerstoerung unlesbar. */
function bm_log_gebremst($schluessel, $text, $sekunden = 3600)
{
    $f = bm_paths()['datadir'] . '/.meld_' . preg_replace('/[^a-z0-9_]/i', '', $schluessel);
    $letzte = is_file($f) ? (int) @file_get_contents($f) : 0;
    if (time() - $letzte >= $sekunden) {
        @file_put_contents($f, (string) time());
        bm_log($text);
    }
}

/* ---------------- Dienst ---------------- */

function bm_dienst_pid()
{
    $f = bm_paths()['datadir'] . '/dienst.pid';
    if (!is_file($f)) {
        return 0;
    }
    $pid = (int) trim((string) @file_get_contents($f));
    if ($pid <= 0 || !is_dir('/proc/' . $pid)) {
        return 0;
    }
    $cmd = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
    return strpos($cmd, 'bms_dienst.php') !== false ? $pid : 0;
}

function bm_dienst_soll()
{
    return is_file(bm_paths()['datadir'] . '/soll_laufen') ? 1 : 0;
}

/** $befehl ist 'start', 'stop' oder 'restart'. Rueckgabe: array(ok, Ausgabe) */
function bm_dienst($befehl)
{
    if (!in_array($befehl, array('start', 'stop', 'restart'), true)) {
        return array(0, 'Unbekannter Befehl.');
    }
    $skript = bm_paths()['bindir'] . '/dienst.sh';
    if (!is_file($skript)) {
        return array(0, 'dienst.sh nicht gefunden: ' . $skript);
    }
    $ausgabe = array();
    $code = 0;
    @exec(escapeshellcmd($skript) . ' ' . escapeshellarg($befehl) . ' 2>&1', $ausgabe, $code);
    return array($code === 0 ? 1 : 0, implode("\n", $ausgabe));
}

/* ---------------- Befehlswarteschlange ----------------
 *
 * Sowohl der Miniserver-Endpunkt als auch der Reiter Test setzen Befehle ueber
 * diese eine Funktion ab. Zwei Kopien derselben Logik laufen zwangslaeufig
 * auseinander.
 *
 * Der Endpunkt spricht NIE selbst mit einem Speicher. Grund ist nicht nur die
 * Sauberkeit: mehrere Speicher lassen nur eine Verbindung gleichzeitig zu.
 * Wuerde der Endpunkt nebenher eine zweite oeffnen, verloere der Dienst seine.
 *
 * Rueckgabe: array(ok, Meldung). ok = 1 erledigt, 0 abgelehnt,
 * 2 eingereiht, aber ohne Antwort in der Wartezeit - Ergebnis unbekannt.
 * Es wird nie ein Erfolg gemeldet, den niemand geprueft hat.
 */
function bm_befehl_absetzen($befehl, $wartezeit = null)
{
    $p = bm_paths();
    $cfg = bm_config();
    if ($wartezeit === null) {
        $wartezeit = (int) $cfg['wartezeit'];
    }
    $wartezeit = max(0, min(30, (int) $wartezeit));

    $ordner = $p['datadir'] . '/befehle';
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return array(0, 'Der Ordner fuer die Warteschlange liess sich nicht anlegen: ' . $ordner);
    }
    $kennung = bin2hex(random_bytes(8));
    $datei = $ordner . '/' . $kennung . '.json';
    $tmp = $datei . '.tmp';
    if (@file_put_contents($tmp, json_encode($befehl)) === false || !@rename($tmp, $datei)) {
        @unlink($tmp);
        return array(0, 'Der Befehl liess sich nicht ablegen: ' . $datei);
    }
    $antwort = $p['datadir'] . '/antworten/' . $kennung . '.json';
    for ($i = 0; $i < $wartezeit * 10; $i++) {
        if (is_file($antwort)) {
            $a = bm_json_lesen($antwort);
            @unlink($antwort);
            return array((int) (isset($a['ok']) ? $a['ok'] : 0),
                         (string) (isset($a['meldung']) ? $a['meldung'] : ''));
        }
        usleep(100000);
    }
    return array(2, 'Eingereiht, aber der Dienst hat innerhalb von ' . $wartezeit . ' s nicht geantwortet.');
}

/* ---------------- Verlauf ---------------- */

function bm_verlauf_lesen($nummer, $tag = '')
{
    if ($tag === '') {
        $tag = date('Ymd');
    }
    $f = bm_paths()['datadir'] . '/verlauf/geraet' . (int) $nummer . '_' . $tag . '.csv';
    $out = array();
    if (is_file($f)) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $zeile) {
            $c = explode(';', $zeile);
            if (count($c) >= 2) {
                $out[] = array((int) $c[0], (float) $c[1], isset($c[2]) && $c[2] !== '' ? (float) $c[2] : 0);
            }
        }
    }
    return $out;
}

/* ==================================================================
 * Modbus
 *
 * Zwei Rahmenformen, ein Befehlssatz. Beide bauen dieselbe PDU
 * (Funktionscode + Nutzdaten) und unterscheiden sich nur in dem, was davor
 * und dahinter steht:
 *
 *   modbus_tcp       MBAP-Kopf: Transaktion(2) Protokoll(2) Laenge(2) Unit(1)
 *   modbus_rtu_tcp   Adresse(1) + PDU + CRC16 (Modbus-Polynom, klein zuerst)
 *
 * Alles hier arbeitet mit Streams statt mit der Erweiterung sockets - die ist
 * fuer die MQTT-Veroeffentlichung noetig, fuer Modbus aber entbehrlich.
 * ================================================================== */

/** CRC16 nach Modbus (Polynom 0xA001), Rueckgabe als 2 Bytes, klein zuerst. */
function bm_crc16($daten)
{
    $crc = 0xFFFF;
    $n = strlen($daten);
    for ($i = 0; $i < $n; $i++) {
        $crc ^= ord($daten[$i]);
        for ($b = 0; $b < 8; $b++) {
            if ($crc & 1) {
                $crc = (($crc >> 1) ^ 0xA001);
            } else {
                $crc >>= 1;
            }
        }
    }
    return chr($crc & 0xFF) . chr(($crc >> 8) & 0xFF);
}

/**
 * Betriebssystemfehler uebersetzen.
 *
 * Der nackte Errno-Text hilft niemandem. ECONNREFUSED (erreichbar, aber kein
 * Dienst) bedeutet etwas voellig anderes als eine Zeitueberschreitung (nichts
 * antwortet) oder EHOSTUNREACH (kein Weg dorthin) - und die wahrscheinlichste
 * Ursache gehoert dazugesagt.
 */
function bm_fehlertext($text, $errno = 0)
{
    $t = strtolower((string) $text);
    if ($errno === 111 || strpos($t, 'connection refused') !== false) {
        return 'Verbindung abgewiesen: der Rechner ist erreichbar, aber auf diesem Port '
             . 'lauscht nichts. Haeufigste Ursachen: falscher Port, Modbus im Geraet nicht '
             . 'eingeschaltet, oder eine andere Anwendung haelt die einzige erlaubte '
             . 'Verbindung besetzt.';
    }
    if ($errno === 113 || strpos($t, 'no route to host') !== false) {
        return 'Kein Weg zu diesem Rechner. Bei der BYD-BCU ist das der Regelfall, solange '
             . 'keine statische Route auf 192.168.16.0/24 eingerichtet ist.';
    }
    if ($errno === 110 || strpos($t, 'timed out') !== false || strpos($t, 'timeout') !== false) {
        return 'Zeitueberschreitung: es kam keine Antwort. Entweder ist die Adresse falsch, '
             . 'das Geraet aus, oder eine Firewall verschluckt die Pakete.';
    }
    if ($errno === 101 || strpos($t, 'network is unreachable') !== false) {
        return 'Das Netz ist nicht erreichbar - der LoxBerry hat keinen Weg in dieses Teilnetz.';
    }
    if (strpos($t, 'name or service not known') !== false || strpos($t, 'getaddrinfo') !== false) {
        return 'Der Rechnername liess sich nicht aufloesen. Statt des Namens die IP-Adresse eintragen.';
    }
    return trim((string) $text) !== '' ? (string) $text : 'Unbekannter Fehler ohne Text.';
}

/**
 * Verbindung oeffnen. Rueckgabe: Stream oder array('_fehler' => Text)
 */
function bm_verbinden(array $g, $tmo)
{
    $errno = 0;
    $errstr = '';
    $s = @stream_socket_client('tcp://' . $g['ip'] . ':' . (int) $g['port'],
                               $errno, $errstr, $tmo);
    if (!$s) {
        return array('_fehler' => bm_fehlertext($errstr, $errno));
    }
    stream_set_timeout($s, (int) $tmo, 0);
    return $s;
}

/** Genau $anzahl Bytes lesen oder mit einer Meldung aufgeben. */
function bm_lesen_genau($s, $anzahl)
{
    $puffer = '';
    while (strlen($puffer) < $anzahl) {
        $teil = @fread($s, $anzahl - strlen($puffer));
        if ($teil === false || $teil === '') {
            $m = stream_get_meta_data($s);
            if (!empty($m['timed_out'])) {
                return array('_fehler' => 'Zeitueberschreitung beim Lesen der Antwort: es kamen '
                    . strlen($puffer) . ' von ' . $anzahl . ' erwarteten Bytes an.');
            }
            return array('_fehler' => 'Die Gegenstelle hat die Verbindung geschlossen, nachdem '
                . strlen($puffer) . ' von ' . $anzahl . ' Bytes angekommen waren.');
        }
        $puffer .= $teil;
    }
    return $puffer;
}

function bm_modbus_ausnahme($code)
{
    $t = array(
        1 => 'Funktionscode wird vom Geraet nicht unterstuetzt',
        2 => 'Registeradresse liegt ausserhalb des gueltigen Bereichs',
        3 => 'Der uebergebene Wert ist unzulaessig',
        4 => 'Das Geraet meldet einen internen Fehler',
        5 => 'Angenommen, aber noch nicht ausgefuehrt',
        6 => 'Das Geraet ist beschaeftigt',
        8 => 'Fehler im Speicher des Geraets',
        11 => 'Das Gateway hat keine Antwort vom Zielgeraet bekommen',
    );
    return isset($t[$code]) ? $t[$code] : ('Ausnahmecode ' . (int) $code);
}

/**
 * Eine Modbus-Anforderung absetzen.
 *
 * $pdu ist bereits Funktionscode + Nutzdaten. Rueckgabe: Antwort-PDU ohne
 * Funktionscode, oder array('_fehler' => Text).
 */
function bm_modbus_anfrage($s, array $g, $pdu)
{
    static $transaktion = 0;
    $rtu = ($g['transport'] === 'modbus_rtu_tcp');
    $unit = (int) $g['unit'];

    if ($rtu) {
        $rahmen = chr($unit) . $pdu;
        $rahmen .= bm_crc16($rahmen);
    } else {
        $transaktion = ($transaktion + 1) & 0xFFFF;
        $rahmen = pack('nnnC', $transaktion, 0, strlen($pdu) + 1, $unit) . $pdu;
    }
    if (@fwrite($s, $rahmen) === false) {
        return array('_fehler' => 'Die Anforderung liess sich nicht senden.');
    }

    // Antwortkopf lesen. Bei RTU steht die Laenge erst im dritten Byte, bei
    // TCP im MBAP-Kopf - deshalb zwei Wege.
    if ($rtu) {
        $kopf = bm_lesen_genau($s, 3);
        if (is_array($kopf)) {
            return $kopf;
        }
        $fc = ord($kopf[1]);
        if ($fc & 0x80) {
            // Ausnahmeantwort: Adresse, FC, Code, CRC - zwei Bytes fehlen noch.
            $rest = bm_lesen_genau($s, 2);
            if (is_array($rest)) {
                return $rest;
            }
            return array('_fehler' => 'Das Geraet hat die Anforderung abgelehnt: '
                . bm_modbus_ausnahme(ord($kopf[2])));
        }
        if ($fc === 3 || $fc === 4) {
            $rest = bm_lesen_genau($s, ord($kopf[2]) + 2);   // Daten + CRC
            if (is_array($rest)) {
                return $rest;
            }
            $ganz = $kopf . $rest;
        } else {
            // FC 6 und 16 antworten mit fester Laenge: Adr FC + 4 Byte + CRC
            $rest = bm_lesen_genau($s, 5);
            if (is_array($rest)) {
                return $rest;
            }
            $ganz = $kopf . $rest;
        }
        $nutz = substr($ganz, 0, strlen($ganz) - 2);
        $crc = substr($ganz, -2);
        if (bm_crc16($nutz) !== $crc) {
            return array('_fehler' => 'Die Pruefsumme der Antwort stimmt nicht. Meist spricht '
                . 'die Gegenstelle Modbus TCP mit MBAP-Kopf statt Modbus RTU im TCP-Strom - '
                . 'dann im Profil den anderen Uebertragungsweg waehlen.');
        }
        if (ord($nutz[0]) !== $unit) {
            return array('_fehler' => 'Die Antwort traegt die Geraeteadresse ' . ord($nutz[0])
                . ', gefragt war ' . $unit . '.');
        }
        return substr($nutz, 2);
    }

    $kopf = bm_lesen_genau($s, 8);
    if (is_array($kopf)) {
        return $kopf;
    }
    $teile = unpack('ntrans/nproto/nlen/Cunit/Cfc', $kopf);
    if ($teile['proto'] !== 0) {
        return array('_fehler' => 'Die Antwort traegt die Protokollkennung ' . $teile['proto']
            . ' statt 0. Das ist keine Modbus-TCP-Antwort - womoeglich antwortet ein '
            . 'Web-Server auf diesem Port.');
    }
    $offen = (int) $teile['len'] - 2;   // Unit und FC sind schon gelesen
    if ($offen < 0 || $offen > 260) {
        return array('_fehler' => 'Die Laengenangabe der Antwort ist unglaubwuerdig (' . $offen . ').');
    }
    $rest = $offen > 0 ? bm_lesen_genau($s, $offen) : '';
    if (is_array($rest)) {
        return $rest;
    }
    if ($teile['fc'] & 0x80) {
        return array('_fehler' => 'Das Geraet hat die Anforderung abgelehnt: '
            . bm_modbus_ausnahme(strlen($rest) > 0 ? ord($rest[0]) : 0));
    }
    return $rest;
}

/**
 * Register lesen. Rueckgabe: array(adresse => rohwert) oder array('_fehler' => …)
 *
 * Modbus erlaubt hoechstens 125 Register je Anforderung. Groessere Bloecke
 * werden aufgeteilt, statt sie stillschweigend zu kuerzen.
 */
function bm_register_lesen($s, array $g, $fc, $start, $anzahl)
{
    $out = array();
    $offen = (int) $anzahl;
    $adr = (int) $start;
    while ($offen > 0) {
        $jetzt = min(125, $offen);
        $pdu = pack('Cnn', (int) $fc, $adr, $jetzt);
        $antwort = bm_modbus_anfrage($s, $g, $pdu);
        if (is_array($antwort)) {
            return $antwort;
        }
        $bytes = strlen($antwort) > 0 ? ord($antwort[0]) : 0;
        $daten = substr($antwort, 1);
        if ($bytes !== $jetzt * 2 || strlen($daten) < $bytes) {
            return array('_fehler' => 'Die Antwort enthaelt ' . strlen($daten) . ' Datenbytes, '
                . 'erwartet waren ' . ($jetzt * 2) . '. Registerbereich '
                . $adr . ' bis ' . ($adr + $jetzt - 1) . '.');
        }
        for ($i = 0; $i < $jetzt; $i++) {
            $out[$adr + $i] = (ord($daten[$i * 2]) << 8) | ord($daten[$i * 2 + 1]);
        }
        $adr += $jetzt;
        $offen -= $jetzt;
    }
    return $out;
}

/** Ein Register schreiben (FC 6). Rueckgabe: true oder array('_fehler' => …) */
function bm_register_schreiben($s, array $g, $reg, $wert)
{
    $pdu = pack('Cnn', 6, (int) $reg, ((int) $wert) & 0xFFFF);
    $antwort = bm_modbus_anfrage($s, $g, $pdu);
    if (is_array($antwort)) {
        return $antwort;
    }
    return true;
}

/** Mehrere Register schreiben (FC 16). $werte ist eine Liste von 16-Bit-Zahlen. */
function bm_register_schreiben_mehrere($s, array $g, $reg, array $werte)
{
    $anz = count($werte);
    if ($anz < 1 || $anz > 123) {
        return array('_fehler' => 'Es lassen sich 1 bis 123 Register auf einmal schreiben, '
            . 'nicht ' . $anz . '.');
    }
    $pdu = pack('CnnC', 16, (int) $reg, $anz, $anz * 2);
    foreach ($werte as $w) {
        $pdu .= pack('n', ((int) $w) & 0xFFFF);
    }
    $antwort = bm_modbus_anfrage($s, $g, $pdu);
    if (is_array($antwort)) {
        return $antwort;
    }
    return true;
}

/** Rohwerte in eine Zahl umrechnen. Fehlt ein Register, kommt null zurueck. */
function bm_wert_aus(array $regs, array $feld)
{
    $reg = (int) $feld['reg'];
    if (!isset($regs[$reg])) {
        return null;
    }
    $typ = isset($feld['typ']) ? $feld['typ'] : 'u16';
    $roh = $regs[$reg];
    if (isset($feld['maske'])) {
        $roh = $roh & (int) $feld['maske'];
        if (isset($feld['schieben'])) {
            $roh = $roh >> (int) $feld['schieben'];
        }
    }
    switch ($typ) {
        case 's16':
            $roh = ($roh >= 0x8000) ? $roh - 0x10000 : $roh;
            break;
        case 'u32':
        case 's32':
            if (!isset($regs[$reg + 1])) {
                return null;
            }
            $wort = isset($feld['wort']) ? $feld['wort'] : 'hoch';
            $roh = ($wort === 'nieder')
                ? (($regs[$reg + 1] << 16) | $regs[$reg])
                : (($regs[$reg] << 16) | $regs[$reg + 1]);
            if ($typ === 's32' && $roh >= 0x80000000) {
                $roh -= 0x100000000;
            }
            break;
        case 'u16hi':
            $roh = ($roh >> 8) & 0xFF;
            break;
        case 'u16lo':
            $roh = $roh & 0xFF;
            break;
    }
    $faktor = isset($feld['faktor']) ? (float) $feld['faktor'] : 1.0;
    $nk = isset($feld['nk']) ? (int) $feld['nk'] : 0;
    return round($roh * $faktor, $nk);
}

/* ==================================================================
 * Pylontech-Konsolenprotokoll
 *
 * Rahmen:  ~ VER(2) ADR(2) CID1(2) CID2(2) LENID(4) INFO CHKSUM(4) CR
 * Alle Felder sind Hex-ZIFFERN in ASCII, nicht rohe Bytes. VER = 20,
 * CID1 = 46.
 *
 * LENID traegt in den oberen vier Bit eine Pruefziffer ueber die Laenge:
 *   Quersumme der drei Nibbles der Laenge, modulo 16, invertiert, plus 1.
 *
 * CHKSUM ist die Summe aller ASCII-Bytes zwischen '~' und der Pruefsumme,
 * invertiert, modulo 0x10000, plus 1.
 * ================================================================== */

function bm_pyl_lenid($info)
{
    $len = strlen($info);
    if ($len === 0) {
        return 0;
    }
    $summe = ($len & 0xF) + (($len >> 4) & 0xF) + (($len >> 8) & 0xF);
    $pruef = (0xF - ($summe % 16) + 1) & 0xF;
    return ($pruef << 12) + $len;
}

function bm_pyl_chksum($rahmen)
{
    $summe = 0;
    $n = strlen($rahmen);
    for ($i = 0; $i < $n; $i++) {
        $summe += ord($rahmen[$i]);
    }
    $summe = (~$summe) & 0xFFFF;
    return ($summe + 1) & 0xFFFF;
}

function bm_pyl_rahmen($adresse, $cid2, $info = '')
{
    $kern = sprintf('%02X%02X%02X%02X%04X', 0x20, (int) $adresse, 0x46, (int) $cid2,
                    bm_pyl_lenid($info)) . $info;
    return '~' . $kern . sprintf('%04X', bm_pyl_chksum($kern)) . "\r";
}

/**
 * Die serielle Schnittstelle einstellen.
 *
 * PHP hat keine eigene Schnittstelle dafuer; stty ist der uebliche Weg. Der
 * Rueckgabewert wird geprueft - ein stilles Scheitern hier fuehrt sonst zu
 * einer Zeitueberschreitung, deren Ursache niemand findet.
 */
function bm_seriell_einstellen($geraetedatei, $baud)
{
    if (!is_readable($geraetedatei)) {
        return 'Die Geraetedatei ' . $geraetedatei . ' ist nicht lesbar. Steckt der '
             . 'RS485-Adapter, und ist der Benutzer loxberry in der Gruppe dialout? '
             . 'Pruefen mit: ls -l ' . $geraetedatei . ' und id loxberry';
    }
    $ausgabe = array();
    $code = 0;
    @exec('stty -F ' . escapeshellarg($geraetedatei) . ' ' . (int) $baud
        . ' cs8 -cstopb -parenb -icanon -echo -ixon min 0 time 20 2>&1', $ausgabe, $code);
    if ($code !== 0) {
        return 'stty konnte die Schnittstelle nicht einstellen: ' . implode(' ', $ausgabe);
    }
    return '';
}

/**
 * Einen Befehl an ein Pylontech-Modul senden und die Antwort einlesen.
 * Rueckgabe: INFO-Feld als rohe Bytes, oder array('_fehler' => Text).
 */
function bm_pyl_befehl($geraetedatei, $baud, $adresse, $cid2, $info = '', $tmo = 3)
{
    $fehler = bm_seriell_einstellen($geraetedatei, $baud);
    if ($fehler !== '') {
        return array('_fehler' => $fehler);
    }
    $fh = @fopen($geraetedatei, 'r+b');
    if (!$fh) {
        return array('_fehler' => 'Die Geraetedatei ' . $geraetedatei . ' liess sich nicht oeffnen.');
    }
    stream_set_blocking($fh, false);
    // Alles wegwerfen, was noch im Puffer liegt - sonst wird die Antwort auf
    // den vorigen Befehl fuer die auf diesen gehalten.
    while (@fread($fh, 4096) !== '') {
        usleep(1000);
    }
    @fwrite($fh, bm_pyl_rahmen($adresse, $cid2, $info));
    @fflush($fh);

    $puffer = '';
    $ende = microtime(true) + $tmo;
    while (microtime(true) < $ende) {
        $teil = @fread($fh, 4096);
        if ($teil !== false && $teil !== '') {
            $puffer .= $teil;
            if (strpos($puffer, "\r") !== false) {
                break;
            }
        } else {
            usleep(20000);
        }
    }
    fclose($fh);

    $anfang = strpos($puffer, '~');
    if ($anfang === false) {
        return array('_fehler' => 'Keine Antwort vom Modul ' . (int) $adresse . '. Es kamen '
            . strlen($puffer) . ' Bytes an. Meist stimmt entweder die Baudrate nicht '
            . '(115200 bei US2000C/US3000C und neuer, 9600 bei aelteren) oder A und B '
            . 'des RS485-Paares sind vertauscht.');
    }
    $puffer = substr($puffer, $anfang);
    $ende_pos = strpos($puffer, "\r");
    if ($ende_pos === false) {
        return array('_fehler' => 'Die Antwort brach ab, bevor das Endezeichen kam ('
            . strlen($puffer) . ' Bytes).');
    }
    $rahmen = substr($puffer, 1, $ende_pos - 1);
    if (strlen($rahmen) < 16) {
        return array('_fehler' => 'Die Antwort ist mit ' . strlen($rahmen) . ' Zeichen zu kurz.');
    }
    $kern = substr($rahmen, 0, strlen($rahmen) - 4);
    $soll = substr($rahmen, -4);
    if (strtoupper($soll) !== sprintf('%04X', bm_pyl_chksum($kern))) {
        return array('_fehler' => 'Die Pruefsumme der Antwort stimmt nicht - die Verbindung '
            . 'ist gestoert oder es antwortet ein anderes Geraet.');
    }
    $rtn = hexdec(substr($kern, 6, 2));
    if ($rtn !== 0) {
        return array('_fehler' => 'Das Modul hat den Befehl abgelehnt (RTN 0x'
            . strtoupper(substr($kern, 6, 2)) . ').');
    }
    $infohex = substr($kern, 12);
    if (strlen($infohex) < 2) {
        return '';
    }
    $roh = @hex2bin(strlen($infohex) % 2 === 0 ? $infohex : substr($infohex, 0, -1));
    return $roh === false ? array('_fehler' => 'Das Datenfeld der Antwort ist kein Hex.') : $roh;
}

/* ==================================================================
 * MQTT-Gateway des LoxBerry
 *
 * Das MQTT-Gateway ist seit LoxBerry 3 Bestandteil des Systems, kein Plugin.
 * Es wird nicht nachinstalliert, sondern unter System -> MQTT Gateway
 * eingeschaltet.
 *
 * Mqtt.Brokerhost ist ab Werk auf 'localhost' gesetzt. Eine Pruefung darauf
 * beantwortet also NICHT die Frage, ob Nachrichten ankommen koennen -
 * massgeblich ist Gatewayautostart.
 * ================================================================== */
function bm_mqtt_zustand()
{
    $p = bm_paths();
    $leer = array('gefunden' => 0, 'autostart' => 0, 'udpport' => 0, 'broker' => '',
                  'brokerport' => '', 'user' => '', 'lokal' => 0);
    if ($p['home'] === '') {
        return $leer;
    }
    $gen = bm_json_lesen($p['home'] . '/config/system/general.json');
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) {
        $m = $gen['Mqtt'];
    } elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) {
        $m = $gen['mqtt'];
    }
    if (!$m) {
        return $leer;
    }
    $hol = function ($gross, $klein) use ($m) {
        if (isset($m[$gross])) {
            return $m[$gross];
        }
        return isset($m[$klein]) ? $m[$klein] : '';
    };
    return array(
        'gefunden'   => 1,
        'autostart'  => in_array((string) $hol('Gatewayautostart', 'gatewayautostart'), array('1', 'true'), true) ? 1 : 0,
        'udpport'    => (int) $hol('Udpinport', 'udpinport'),
        'broker'     => (string) $hol('Brokerhost', 'brokerhost'),
        'brokerport' => (string) $hol('Brokerport', 'brokerport'),
        'user'       => (string) $hol('Brokeruser', 'brokeruser'),
        'lokal'      => in_array((string) $hol('Uselocalbroker', 'uselocalbroker'), array('1', 'true'), true) ? 1 : 0,
    );
}

/**
 * Werte ueber das LoxBerry-Gateway veroeffentlichen.
 *
 * Bewusst ueber den UDP-Eingang des Gateways und nicht mit einem eigenen
 * MQTT-Client: so muss das Plugin ueberhaupt keine Broker-Zugangsdaten
 * kennen, um zu senden. Das Gateway hat sie ohnehin.
 */
function bm_mqtt_senden(array $paare, $praefix)
{
    if (!function_exists('socket_create')) {
        bm_log_gebremst('mqtt_keine_sockets',
            'MQTT: die PHP-Erweiterung sockets fehlt - es wird nichts veroeffentlicht. '
            . 'Abhilfe: sudo apt install php-sockets');
        return false;
    }
    $z = bm_mqtt_zustand();
    if (!$z['udpport']) {
        bm_log_gebremst('mqtt_kein_port',
            'MQTT: kein UDP-Eingangsport in der general.json gefunden - nichts gesendet.');
        return false;
    }
    if (!$z['autostart']) {
        bm_log_gebremst('mqtt_aus', 'MQTT: das Gateway ist nicht auf Autostart gestellt '
            . '(System, MQTT Gateway). Es wird gesendet, aber vermutlich hoert niemand zu.');
    }
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$s) {
        bm_log_gebremst('mqtt_socket', 'MQTT: Socket nicht moeglich.');
        return false;
    }
    foreach ($paare as $k => $v) {
        if ($v === null || $v === '') {
            continue;   // fehlender Wert: nichts senden statt eine erfundene 0
        }
        $msg = 'publish ' . $praefix . '/' . $k . ' ' . $v;
        @socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $z['udpport']);
    }
    socket_close($s);
    return true;
}

/** Alle Themen, die der Dienst veroeffentlicht, mit ihrer Bedeutung. */
function bm_mqtt_themen()
{
    return array(
        'ok'                       => 'BM_MQTT.OK',
        'geraete'                  => 'BM_MQTT.GERAETE',
        'geraetN/soc'              => 'BM_MQTT.SOC',
        'geraetN/soh'              => 'BM_MQTT.SOH',
        'geraetN/ubat'             => 'BM_MQTT.UBAT',
        'geraetN/ibat'             => 'BM_MQTT.IBAT',
        'geraetN/pbat'             => 'BM_MQTT.PBAT',
        'geraetN/tmax'             => 'BM_MQTT.TMAX',
        'geraetN/tmin'             => 'BM_MQTT.TMIN',
        'geraetN/uzmax'            => 'BM_MQTT.UZMAX',
        'geraetN/uzmin'            => 'BM_MQTT.UZMIN',
        'geraetN/uzdiff'           => 'BM_MQTT.UZDIFF',
        'geraetN/zyklen'           => 'BM_MQTT.ZYKLEN',
        'geraetN/kapaz'            => 'BM_MQTT.KAPAZ',
        'geraetN/module'           => 'BM_MQTT.MODULE',
        'geraetN/zellen'           => 'BM_MQTT.ZELLEN',
        'geraetN/fehler'           => 'BM_MQTT.FEHLER',
        'geraetN/warnung'          => 'BM_MQTT.WARNUNG',
        'geraetN/modus'            => 'BM_MQTT.MODUS',
        'geraetN/ok'               => 'BM_MQTT.GOK',
        'geraetN/sollwert'         => 'BM_MQTT.SOLLWERT',
        'geraetN/sollwert_alter'   => 'BM_MQTT.SOLLALTER',
        'geraetN/modul/M/uzmax'    => 'BM_MQTT.M_UZMAX',
        'geraetN/modul/M/uzmin'    => 'BM_MQTT.M_UZMIN',
        'geraetN/modul/M/uzdiff'   => 'BM_MQTT.M_UZDIFF',
        'geraetN/modul/M/tmax'     => 'BM_MQTT.M_TMAX',
        'geraetN/modul/M/zelle/Z'  => 'BM_MQTT.M_ZELLE',
    );
}

/* ==================================================================
 * Loxone-Vorlagen
 *
 * Nachbau der Bausteine aus LoxBerry::LoxoneTemplateBuilder; das Modul gibt es
 * nur in Perl. Attributreihenfolge, CRLF als Zeilenende und der Tabulator vor
 * den Kindelementen entsprechen dem Original. Wortgleich uebernommen aus
 * LoxBerry-Plugin-APC-UPS-1.0.0 (ap_xml_virtual_in_http) - nicht neu
 * geschrieben, weil die Fassung dort geprueft ist.
 *
 * Maskieren ist Pflicht: ein Anfuehrungszeichen im Geraetenamen zerlegt sonst
 * die Datei, und Loxone Config meldet dazu nichts Brauchbares.
 * ================================================================== */

function bm_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function bm_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'Title="' . bm_x($kopf['title']) . '" ';
    $o .= 'Comment="' . bm_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . bm_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . bm_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . bm_x($c['title']) . '" ';
        $o .= 'Comment="' . bm_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . bm_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        // Grenzen realistisch, nicht pauschal +/-2147483647: Loxone zieht
        // daraus die Reglergrenzen und die Plausibilitaetspruefung.
        $o .= 'MinVal="' . bm_x(isset($c['min']) ? $c['min'] : '-2147483647') . '" ';
        $o .= 'MaxVal="' . bm_x(isset($c['max']) ? $c['max'] : '2147483647') . '"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Virtueller Ausgang.
 *
 * Attributreihenfolge und Auswahl entsprechen den geprueften Mustern aus dem
 * Arbeitsordner (VQ_KEBA_P30_UDP.xml, VQ_GOVEE_*.xml), die aus der laufenden
 * Anlage stammen: Wurzel mit Title, Comment, Address, CloseAfterSend, CmdSep;
 * Kindelement mit Title, Comment (nur wenn gesetzt), CmdOn, CmdOff (nur wenn
 * gesetzt), Analog.
 *
 * BEWUSST NICHT gesetzt: CmdOnMethod und CmdOffMethod. In keinem der
 * geprueften Muster kommen sie vor - beide sind UDP-Ausgaenge. Ob Loxone
 * Config sie bei einem HTTP-Ausgang braucht, ist hier nicht geprueft worden;
 * im Zweifel gilt die Datei, nicht die Erinnerung. Nach dem Import deshalb
 * einmal nachsehen, ob als Methode GET eingetragen ist. Der Hinweis steht
 * auch im Reiter Einbindung in Loxone.
 */
function bm_xml_virtual_out($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut ';
    $o .= 'Title="' . bm_x($kopf['title']) . '" ';
    $o .= 'Comment="' . bm_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . bm_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'CloseAfterSend="true" ';
    $o .= 'CmdSep=""';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . bm_x($c['title']) . '" ';
        if (isset($c['comment']) && $c['comment'] !== '') {
            $o .= 'Comment="' . bm_x($c['comment']) . '" ';
        }
        // Der Wertplatzhalter in einem Analogbefehl heisst <v.0>. Er wandert
        // maskiert ins Attribut - genau wie im geprueften Govee-Muster, wo aus
        // <v.0> ein &lt;v.0&gt; wird.
        $o .= 'CmdOn="' . bm_x(isset($c['on']) ? $c['on'] : '') . '" ';
        if (isset($c['off']) && $c['off'] !== '') {
            $o .= 'CmdOff="' . bm_x($c['off']) . '" ';
        }
        $o .= 'Analog="' . (empty($c['analog']) ? 'false' : 'true') . '"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/** Vorlage der virtuellen Eingaenge. Rueckgabe: array(name, inhalt) */
function bm_vorlage_eingaenge($nummer = 1)
{
    $p = bm_paths();
    $host = bm_hostname();
    $token = bm_token();
    $g = bm_geraet($nummer);
    $bez = $g !== null ? $g['name'] : ('Speicher ' . (int) $nummer);
    $cmds = array();
    foreach (bm_status_felder() as $feld => $info) {
        // Der Text laeuft gleich durch bm_x() und wuerde dort ein zweites Mal
        // maskiert. Deshalb erst Auszeichnung entfernen und Entitaeten
        // aufloesen - sonst stuende in Loxone Config wortwoertlich 'l&auml;dt'.
        $bedeutung = trim(strip_tags(html_entity_decode(bm_t($info[1]), ENT_QUOTES, 'UTF-8')));
        $cmds[] = array(
            'title'   => 'BMS_' . (int) $nummer . '_' . $feld,
            'comment' => $bedeutung . ($info[0] !== '' ? ' [' . $info[0] . ']' : ''),
            'check'   => '\i' . $feld . '=\i\v',
            'min'     => (string) $info[2],
            'max'     => (string) $info[3],
        );
    }
    $adresse = 'http://' . $host . '/plugins/' . $p['plugin']
             . '/index.php?token=' . $token . '&aktion=status&geraet=' . (int) $nummer;
    return array(
        'VI_BMS_' . (int) $nummer . '.xml',
        bm_xml_virtual_in_http(array(
            'title'   => 'BMS ' . (int) $nummer . ' ' . $bez,
            'address' => $adresse,
            'polling' => '60',
            'comment' => 'Erzeugt vom LoxBerry-Plugin Batterie-Heimspeicher (BMS), '
                       . date('d.m.Y'),
        ), $cmds),
    );
}

/** Vorlage des virtuellen Ausgangs (Lade- und Entladezwang). */
function bm_vorlage_ausgang($nummer = 1)
{
    $p = bm_paths();
    $host = bm_hostname();
    $token = bm_token();
    $g = bm_geraet($nummer);
    $bez = $g !== null ? $g['name'] : ('Speicher ' . (int) $nummer);
    $basis = '/plugins/' . $p['plugin'] . '/index.php?token=' . $token;
    $n = (int) $nummer;
    $cmds = array(
        array('title' => 'Laden erzwingen (W)', 'analog' => 1,
              'comment' => 'Analogwert in Watt. 0 gibt die Regie zurueck.',
              'on' => $basis . '&aktion=laden&geraet=' . $n . '&watt=<v.0>', 'off' => ''),
        array('title' => 'Entladen erzwingen (W)', 'analog' => 1,
              'comment' => 'Analogwert in Watt. 0 gibt die Regie zurueck.',
              'on' => $basis . '&aktion=entladen&geraet=' . $n . '&watt=<v.0>', 'off' => ''),
        array('title' => 'Automatik', 'analog' => 0,
              'comment' => 'Beendet den Zwang sofort; der Speicher regelt wieder selbst.',
              'on' => $basis . '&aktion=automatik&geraet=' . $n, 'off' => ''),
        array('title' => 'Lebenszeichen', 'analog' => 0,
              'comment' => 'Haelt den Sollwert am Leben. Ohne Lebenszeichen faellt der '
                         . 'Speicher nach der eingestellten Totmannzeit in die Automatik.',
              'on' => $basis . '&aktion=lebenszeichen&geraet=' . $n, 'off' => ''),
    );
    return array(
        'VQ_BMS_' . $n . '.xml',
        bm_xml_virtual_out(array(
            'title'   => 'BMS ' . $n . ' ' . $bez . ' Steuerung',
            'address' => 'http://' . $host,
            'comment' => 'Erzeugt vom LoxBerry-Plugin Batterie-Heimspeicher (BMS), '
                       . date('d.m.Y'),
        ), $cmds),
    );
}

/**
 * Der Rechnername, unter dem der Miniserver den LoxBerry erreicht.
 *
 * gethostname() liefert nicht zwingend den richtigen - deshalb wird die
 * Adresse in der Oberflaeche ausdruecklich als Vorschlag bezeichnet.
 */
function bm_hostname()
{
    if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '') {
        return preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST']);
    }
    $h = gethostname();
    return $h ? $h : 'loxberry';
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini immer
 * vollstaendig sein.
 *
 * Die Funktion setzt kein bm_paths() voraus, damit derselbe Block in jedes
 * Plugin passt. Der Pfad wird zweistufig gesucht:
 *   installiert: <home>/templates/plugins/<ordner>/lang
 *   Archiv:      <pluginwurzel>/templates/lang
 * ================================================================== */

function bm_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

function bm_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array('/opt/loxberry', '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) {
                    $home = $k;
                    break;
                }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . bm_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) {
            $texte = array();
        }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) {
            $texte = array_replace_recursive($rueck, $texte);
        }
        // INI_SCANNER_RAW liefert die Werte samt der Anfuehrungszeichen
        // zurueck, in die sie in der Datei stehen muessen. Die gehoeren nicht
        // in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) {
                continue;
            }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    $teile = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$teile[0]][$teile[1]]) ? $texte[$teile[0]][$teile[1]] : $schluessel;
}
