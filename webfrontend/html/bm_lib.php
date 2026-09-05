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


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
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
        foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
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
        'TMAX'     => array('°C', 'BM_FELD.TMAX',     -40,  100),
        'TMIN'     => array('°C', 'BM_FELD.TMIN',     -40,  100),
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
        /* Ab hier neu in 0.9.8. Neue Groessen werden IMMER hinten
         * angehaengt - siehe den Hinweis zur Suchreihenfolge in
         * webfrontend/html/index.php. Keiner der neuen Namen enthaelt einen
         * bestehenden als Anfangsstueck mit Gleichheitszeichen. */
        'UZMINMOD'   => array('',    'BM_FELD.UZMINMOD',   0, 64),
        'UZMINZELLE' => array('',    'BM_FELD.UZMINZELLE', 0, 64),
        'RESTKWH'    => array('kWh', 'BM_FELD.RESTKWH',    0, 500),
        'RESTZEIT'   => array('min', 'BM_FELD.RESTZEIT',   0, 6000),
        'ALARM'      => array('',    'BM_FELD.ALARM',      0, 1),
        /* Neu in 0.9.16, wieder hinten angehaengt. TBAT ist die Temperatur,
         * die das BMS fuer den Speicher als Ganzes meldet - neben TMAX/TMIN,
         * die die hoechste und niedrigste ZELLtemperatur fuehren. Der Name
         * enthaelt keinen bestehenden als Anfangsstueck, und der Suchtext
         * traegt ohnehin das fuehrende Semikolon. */
        'TBAT'       => array('°C', 'BM_FELD.TBAT',       -40, 100),
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
 *   typ     u16 | s16 | u32 | s32 | f32 | u16hi | u16lo
 *   faktor  Multiplikator auf den Rohwert
 *   nk      Nachkommastellen der Ausgabe
 *   wort    bei u32, s32 und f32: 'hoch' (High Word zuerst, Vorgabe)
 *           oder 'nieder'
 *
 * Bis 0.9.6 stand hier zusaetzlich 'ascii'. Diesen Typ hat bm_wert_aus() nie
 * gekannt: ein Profil mit typ=ascii fiel durch alle Zweige und bekam den
 * rohen Registerwert mal Faktor - also eine Zahl, die aussieht wie ein
 * Messwert. Ein Zeichenkettentyp passt auch nicht in dieses Geruest, denn
 * jede Messgroesse traegt Einheit und Grenzen. Der Eintrag ist deshalb
 * entfernt und nicht nachgebaut worden.
 *
 * Neu ist 'f32': IEEE 754 mit einfacher Genauigkeit ueber zwei Register. Das
 * sprechen viele Wechselrichter und BMS-Gateways; ohne diesen Typ liess sich
 * ein solches Geraet mit einem eigenen Profil nicht auslesen.
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
            'quelle'    => 'github.com/sarnau/BYD-Battery-Box-Infos (Read_Modbus.py) und '
                         . 'github.com/christianh17/ioBroker.bydhvs (main.js, docs/'
                         . 'byd-hexstructure.md); beides Rueckbau aus mitgeschnittener '
                         . 'Kommunikation, KEIN Herstellerdokument. Die Zuordnung Byte zu '
                         . 'Register ist an der Anfragezeile 01030500001984cc belegt (FC 3, ab 0x0500, 25 Register).',
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
                /* Temperaturen sind VORZEICHENBEHAFTET. Bis 0.9.15 standen
                 * sie hier als u16 - bei Frost meldete TMIN dann 65531 statt
                 * -5, und der Uebertemperaturalarm feuerte genau verkehrt
                 * herum. Belegt an drei unabhaengigen Stellen: das
                 * HVS-Datenblatt gibt den Betrieb bis -10 Grad C an (negative
                 * Zelltemperaturen sind also Normalbetrieb), ioBroker.bydhvs
                 * liest beide Register ausdruecklich als int16 signed, und
                 * bm_status_felder() nennt selbst den Bereich -40 bis 100.
                 * sarnaus Skript wandelt hier nicht um - es behauptet aber
                 * auch nichts Gegenteiliges, es druckt den Rohwert. Fuer
                 * 0 bis 32767 ist s16 bitgleich mit u16; die Umstellung
                 * aendert also nur den Frostfall. */
                'TMAX'   => array('reg' => 0x0506, 'typ' => 's16', 'faktor' => 1,    'nk' => 0),
                'TMIN'   => array('reg' => 0x0507, 'typ' => 's16', 'faktor' => 1,    'nk' => 0),
                'TBAT'   => array('reg' => 0x0508, 'typ' => 's16', 'faktor' => 1,    'nk' => 0),
                'FEHLER' => array('reg' => 0x050D, 'typ' => 'u16', 'faktor' => 1,    'nk' => 0),
                /* VORBEHALT (B37, 05.09.2026): ob 0x0511 wirklich ein
                 * Zyklenzaehler ist, ist NICHT geklaert. Die beiden einzigen
                 * Quellen widersprechen sich: sarnau nennt 0x0511 'Charge
                 * Cycles' (u16), ioBroker.bydhvs liest 0x0511/0x0512 als EIN
                 * u32 geteilt durch 10 und nennt es 'chargeTotal' in kWh -
                 * und die dortige Protokollmitschrift sagt fuer dasselbe Byte
                 * 37 ebenfalls 'Total charge of the system'. Trifft das zu,
                 * liest dieses Feld das HOHE Wort eines Energiezaehlers: es
                 * bliebe jahrelang 0 und stiege dann je 6553,6 kWh Durchsatz
                 * um 1 - was einem Zyklenzaehler zum Verwechseln aehnlich
                 * sieht. Entschieden wird das an EINER Ablesung: ist 0x0512
                 * ungleich 0, ist ZYKLEN Unsinn. Bis dahin bleibt das Feld
                 * unveraendert stehen - eine stille Umdeutung waere schlimmer
                 * als der Vorbehalt. */
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
         * Huawei LUNA2000 - Batteriepaket 1
         *
         * BERICHTIGT am 05.09.2026. Bis 0.9.15 stand hier eine Adressliste,
         * von der genau EIN Feld stimmte (SOC). UBAT las den Betriebszustand,
         * IBAT las den SOC, SOH las mitten in die Firmware-Zeichenkette
         * (38210, 15 Register), TMAX las den Strom, TMIN das hohe Wort eines
         * 32-Bit-Energiezaehlers, und UZMAX/UZMIN lasen reservierte Register.
         * Der Speicher haette Zahlen geliefert - falsche.
         *
         * Die Adressen stehen jetzt so, wie sie das Herstellerdokument
         * (Solar Inverter Modbus Interface Definitions, SUN2000MA
         * V100R001C00SPC166, Tabelle 3-2) fuehrt; nachgemessen an
         * github.com/wlcrs/huawei-solar-lib, src/huawei_solar/registers.py,
         * Zweig v2, Zeilen 1208-1216 und 1262-1263.
         *
         * ZWEI BLOECKE, das ist kein Versehen: die Temperaturen der Pakete
         * von Speichereinheit 1 liegen bei 38452/38453 - also HINTER den
         * Paketdaten der zweiten Speichereinheit. Ein Block ab 38228 erreicht
         * sie nicht.
         *
         * Zellspannungen gibt es in diesem Adressraum NICHT. UZMAX/UZMIN sind
         * deshalb ersatzlos entfallen, und ein Paket-SOH ist ebenfalls nicht
         * dokumentiert. Lieber vier richtige Groessen als acht, von denen
         * sieben erfunden sind.
         *
         * Paketabstand 42 Register (38200 / 38242 / 38284), Temperaturabstand
         * 2 (38452 / 38454 / 38456) - wer Paket 2 oder 3 braucht, legt sich
         * ein eigenes Profil mit diesen Abstaenden an.
         *
         * NICHT gemessen: ob ein Wechselrichter OHNE zweite Speichereinheit
         * den Block ab 38452 ueberhaupt beantwortet. Es ist kein Geraet
         * angeschlossen.
         * ----------------------------------------------------------------- */
        'huawei_luna2000_pakete' => array(
            'name'      => 'Huawei LUNA2000 - Batteriepaket 1',
            'transport' => 'modbus_tcp',
            'port'      => 502,
            'unit'      => 1,
            'stand'     => 'dokumentiert',
            'quelle'    => 'Solar Inverter Modbus Interface Definitions (Huawei), '
                         . 'SUN2000MA V100R001C00SPC166, Tabelle 3-2; gegengelesen an '
                         . 'github.com/wlcrs/huawei-solar-lib (registers.py, Zweig v2). '
                         . 'An keinem Geraet gemessen.',
            'hinweis'   => 'BM_PROFIL.H_HUAWEI_PAKETE',
            'bloecke'   => array(
                array('fc' => 3, 'start' => 38228, 'anzahl' => 9),
                array('fc' => 3, 'start' => 38452, 'anzahl' => 2),
            ),
            'felder' => array(
                'SOC'    => array('reg' => 38229, 'typ' => 'u16', 'faktor' => 0.1, 'nk' => 1),
                'UBAT'   => array('reg' => 38235, 'typ' => 'u16', 'faktor' => 0.1, 'nk' => 1),
                'IBAT'   => array('reg' => 38236, 'typ' => 's16', 'faktor' => 0.1, 'nk' => 1),
                // 38233/38234, hohes Wort zuerst - die Vorgabe von bm_wert_aus().
                'PBAT'   => array('reg' => 38233, 'typ' => 's32', 'faktor' => 1,   'nk' => 0),
                'TMAX'   => array('reg' => 38452, 'typ' => 's16', 'faktor' => 0.1, 'nk' => 1),
                'TMIN'   => array('reg' => 38453, 'typ' => 's16', 'faktor' => 0.1, 'nk' => 1),
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
/**
 * Liest ein Profil zwei verschiedene Messgroessen aus DEMSELBEN Register?
 *
 * Anlass (B14, 04.09.2026): im Paketprofil von Huawei standen IBAT und SOC
 * beide auf 38229. Hoechstens eine der beiden Zahlen konnte stimmen, und
 * beide gingen ohne Vorbehalt nach Loxone und MQTT. Ein Stand
 * 'unbestaetigt' deckt eine ungemessene Adresse ab - einen inneren
 * Widerspruch deckt er nicht.
 *
 * Gezaehlt wird nur der Leseblock 'felder'; 'rechnung' darf dieselben
 * Register benutzen (das ist dort der Sinn: PBAT entsteht bei BYD aus Strom
 * und Ausgangsspannung). Register verschiedener BREITE, die sich
 * ueberlappen, faengt diese Pruefung bewusst nicht - dafuer braeuchte es
 * die Breite je Typ, und dieser Fall ist im Bestand nicht aufgetreten.
 *
 * Rueckgabe: Liste von Texten, je einer Doppelbelegung. Leer heisst sauber.
 */
function bm_profil_doppelregister(array $pr)
{
    $gesehen = array();
    $funde = array();
    foreach ((array) (isset($pr['felder']) ? $pr['felder'] : array()) as $name => $feld) {
        if (!is_array($feld) || !isset($feld['reg'])) {
            continue;
        }
        $reg = (int) $feld['reg'];
        if (isset($gesehen[$reg])) {
            $funde[] = $gesehen[$reg] . ' und ' . $name . ' lesen beide Register ' . $reg;
        } else {
            $gesehen[$reg] = $name;
        }
    }
    return $funde;
}

function bm_profile()
{
    static $alle = null;
    if ($alle !== null) {
        return $alle;
    }
    $alle = bm_profile_eingebaut();
    foreach ($alle as $k => $v) {
        // Anwendersichtbar, also uebersetzt - bis 0.9.15 stand hier
        // fest Deutsch, und es erschien auch in der englischen Oberflaeche.
        $alle[$k]['herkunft'] = bm_t('EINST.HERKUNFT_EINGEBAUT');
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
            $d['herkunft'] = bm_t('EINST.HERKUNFT_DATEI');
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
        // Grenzen fuer den Sammelmerker. Das sind EINSTELLUNGEN, keine
        // Messwerte: 45 Grad entspricht der Schwelle, die auch die
        // Baustein-Liste im Reiter Loxone vorschlaegt, 0 Grad der Grenze,
        // unterhalb derer ein Lithiumspeicher nicht geladen werden soll.
        'temp_max'       => 45,
        'temp_min'       => 0,
        // Mitschnitt des Datenverkehrs: Unixzeit, BIS zu der er laeuft.
        // 0 heisst aus - und ab Werk ist er aus.
        'mitschnitt_bis' => 0,
        // EVCC. Aus, bis es jemand einschaltet - es sind zusaetzliche Themen,
        // und wer EVCC nicht benutzt, soll sie nicht im Broker stehen haben.
        'evcc_ein'       => 0,
        'evcc_geraet'    => 1,      // welcher Speicher EVCC als Hausspeicher gilt
        'evcc_ladewatt'  => 0,      // Leistung fuer Betriebsart 3, 0 = watt ist Pflicht
    );
}

/**
 * Die zulaessigen Bereiche der Einstellungen - an EINER Stelle.
 *
 * Anlass (B01, 04.09.2026): dieselbe Tabelle stand nur im Speichern-Zweig der
 * Oberflaeche. Das Zurueckspielen einer Sicherung pruefte keinen einzigen
 * Wert. Gemessen: eine Datei mit intervall = "nicht; eine Zahl" und
 * soc_max = 9999 wurde uebernommen - und soc_max = 9999 legt die Ladeschranke
 * still, weil bms_dienst.php gegen genau diesen Wert prueft.
 */
function bm_wert_grenzen()
{
    return array(
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
        'evcc_geraet'         => array(1, 99),
        'evcc_ladewatt'       => array(0, 30000),
    );
}

/**
 * Einen einzelnen Wert beurteilen. Rueckgabe: '' heisst in Ordnung, sonst
 * der Grund im Klartext.
 *
 * Benutzt vom Speichern-Zweig UND vom Zurueckspielen - das ist der Sinn.
 */
function bm_wert_pruefen($schluessel, $wert)
{
    $grenzen = bm_wert_grenzen();
    if (isset($grenzen[$schluessel])) {
        if (is_array($wert) || is_object($wert) || is_bool($wert) || $wert === null) {
            return sprintf(bm_t('EINST.FEHLER_ZAHL'), $schluessel);
        }
        if (preg_match('/^[0-9]+$/', trim((string) $wert)) !== 1) {
            return sprintf(bm_t('EINST.FEHLER_ZAHL'), $schluessel);
        }
        $z = (int) $wert;
        if ($z < $grenzen[$schluessel][0] || $z > $grenzen[$schluessel][1]) {
            return sprintf(bm_t('EINST.FEHLER_BEREICH'), $schluessel,
                $grenzen[$schluessel][0], $grenzen[$schluessel][1]);
        }
        return '';
    }
    switch ($schluessel) {
        case 'mqtt_ein':
        case 'steuerung_ein':
        case 'evcc_ein':
            return in_array((string) $wert, array('0', '1'), true)
                ? '' : sprintf(bm_t('EINST.FEHLER_ZAHL'), $schluessel);
        case 'mitschnitt_bis':
            return (is_int($wert) || preg_match('/^[0-9]{1,12}$/', (string) $wert) === 1)
                ? '' : sprintf(bm_t('EINST.FEHLER_ZAHL'), $schluessel);
        case 'mqtt_topic':
            return (is_string($wert)
                    && preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $wert) === 1)
                ? '' : bm_t('EINST.FEHLER_TOPIC');
        case 'aktionstoken':
            /* Weit gefasst, wie der Hausstandard es seit VolkswagenID 0.9.12
             * verlangt: zugelassen ist, was ohne Kodierung in eine Adresse
             * passt. Die LEERE Zeichenkette ist zulaessig - 'kein Token
             * gesichert' ist kein unzulaessiger Wert, sondern eine Lage. */
            return (is_string($wert)
                    && preg_match('/^[A-Za-z0-9_.\-]{0,64}$/', $wert) === 1)
                ? '' : sprintf(bm_t('EINST.FEHLER_ZAHL'), $schluessel);
        case 'geraete':
            return is_array($wert) ? '' : sprintf(bm_t('EINST.FEHLER_ZAHL'), $schluessel);
    }
    return '';
}

/**
 * Eine Geraetezeile beurteilen - dieselbe Beurteilung wie im Formular.
 *
 * Anlass (B04): das Muster fuer die Geraetedatei stand nur im
 * Speichern-Zweig. Gemessen: '/dev/../etc/passwd', '/etc/passwd' und ein
 * Geraetename mit Zeilenumbruch gingen durch bm_geraete() glatt durch,
 * waehrend das Formular alle drei abwies. Der Wert landet in
 * bm_seriell_einstellen() und bm_pyl_befehl().
 */
function bm_geraetedatei_taugt($dev)
{
    $dev = (string) $dev;
    return preg_match('#^/dev/[A-Za-z0-9_/\-\.]{1,60}$#', $dev) === 1
        && strpos($dev, '..') === false;
}

function bm_json_lesen($pfad)
{
    if (!is_file($pfad)) {
        return array();
    }
    $d = json_decode((string) @file_get_contents($pfad), true);
    return is_array($d) ? $d : array();
}

/**
 * Erst in eine Nebendatei, dann umbenennen - so liest niemand eine halb
 * geschriebene Datei.
 *
 * Der Name der Nebendatei traegt seit 0.9.1 die Prozessnummer und einen
 * Zufallsanteil. Vorher hiess sie schlicht <datei>.tmp, und die ist NICHT
 * eindeutig: der Dienst schreibt loxone.json im Takt, die Oberflaeche kann
 * im selben Augenblick speichern. Beide schrieben dann in dieselbe
 * Nebendatei, und was am Ende umbenannt wurde, war eine Mischung aus zwei
 * JSON-Dokumenten - also keines.
 *
 * Warum das Ergebnis von json_encode geprueft wird: bei ungueltigem UTF-8
 * liefert es false, und file_put_contents macht daraus eine LEERE Datei -
 * mit Rueckgabe 0, also nicht false. Die Pruefung 'geschrieben === false'
 * greift dann nie, und der Verlust wird als Erfolg gemeldet.
 */
function bm_json_schreiben($pfad, $daten, $rechte = null)
{
    $ordner = dirname($pfad);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return false;
    }
    $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        // Nicht still scheitern. Ein einzelnes ungueltiges Byte - etwa aus
        // der Ausgabe von stty in einer Nicht-UTF-8-Umgebung - liesse sonst
        // das Abbild fuer Loxone auf ewig auf dem alten Stand stehen, ohne
        // dass irgendwo etwas davon zu lesen waere.
        bm_log_gebremst('json_' . basename($pfad),
            'Die Datei ' . basename($pfad) . ' liess sich nicht als JSON schreiben: '
            . json_last_error_msg() . '. Der bisherige Inhalt bleibt unveraendert.', 600);
        return false;
    }
    $tmp = $pfad . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    /* B33: Rechte VOR dem Inhalt. Bis 0.9.15 wurde erst geschrieben und dann
     * chmod gerufen - dazwischen lag ein Fenster, in dem das Aktionstoken fuer
     * jeden lokalen Benutzer lesbar war. Der Kommentar ueber bm_merkwort()
     * stellt genau diese Regel auf; der Code hielt sie an drei Stellen nicht.
     * Erst leer anlegen, dann schuetzen, dann fuellen. */
    if ($rechte !== null) {
        $fh = @fopen($tmp, 'c');
        if ($fh === false) {
            return false;
        }
        fclose($fh);
        @chmod($tmp, $rechte);
    }
    if (@file_put_contents($tmp, $json) !== strlen($json)) {
        @unlink($tmp);
        return false;
    }
    if ($rechte !== null) {
        @chmod($tmp, $rechte);
    }
    if (!@rename($tmp, $pfad)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Liegengebliebene Nebendateien wegraeumen.
 *
 * Ein zur Unzeit abgeschossener Prozess hinterlaesst eine .tmp.*-Datei. Eine
 * einzelne stoert nicht, tausend schon.
 */
function bm_tmp_aufraeumen($ordner, $alter = 3600)
{
    foreach ((array) glob($ordner . '/*.tmp.*') as $t) {
        if (is_file($t) && (time() - filemtime($t)) > $alter) {
            @unlink($t);
        }
    }
}

/**
 * Prozessweite Schreibsperre fuer den unangemeldeten Bereich.
 *
 * Anlass (B03, 04.09.2026): webfrontend/html/index.php ruft bm_config() VOR
 * der Tokenpruefung. Die Selbstheilung darin legte den Konfigurationsordner
 * an und spielte die Zweitschrift zurueck - ein Aufruf OHNE Token genuegte,
 * gemessen. Wer die Konfiguration loescht, um das Plugin stillzulegen, hatte
 * sie danach samt altem Aktionstoken zurueck.
 *
 * Warum eine Sperre und kein Parameter: bm_config() wird auch mittelbar
 * gerufen - ueber bm_geraete(), bm_werte(), bm_token(). Ein Parameter an
 * EINER Aufrufstelle waere von jedem inneren Aufrufer wieder aufgehoben. Die
 * Sperre gilt fuer den ganzen Prozess und wird in der ersten Zeile des
 * Endpunkts gesetzt.
 */
function bm_nur_lesen($setzen = null)
{
    static $an = false;
    if ($setzen !== null) {
        $an = (bool) $setzen;
    }
    return $an;
}

/**
 * Der ZUERST festgestellte Zustand der Konfiguration.
 *
 * Anlass (B02): die Selbstheilung beseitigt den Schaden im selben
 * Seitenaufruf. Eine Pruefzeile, die danach fragt, sieht eine heile Datei und
 * meldet 'in Ordnung' - der Bediener erfaehrt nie, dass etwas war. Der erste
 * Befund wird deshalb festgehalten und von einem spaeteren 'ok' NICHT
 * ueberschrieben.
 *
 * Werte: ok | fehlt | leer | kaputt | aus der Zweitschrift |
 *        kaputt ohne Zweitschrift
 */
function bm_konfig_lage($setzen = null)
{
    static $lage = null;
    if ($setzen !== null && $lage === null) {
        $lage = (string) $setzen;
    }
    return $lage === null ? 'ok' : $lage;
}

/**
 * Taugt eine Zeichenkette als Konfiguration MIT Geheimnis?
 *
 * Die Selbstheilung entscheidet nach INHALT, nicht nach Form. Eine halb
 * geschriebene Datei ist weder leer noch '{}': bis 0.9.15 wurde sie deshalb
 * nicht geheilt, bm_json_lesen() gab stumm ein leeres Feld zurueck, das
 * Aktionstoken war fort, die Oberflaeche wuerfelte ein neues - und
 * bm_config_speichern() kopierte es ueber die Zweitschrift. Gemessen am
 * 04.09.2026: nach EINEM Oeffnen der Oberflaeche waren Konfiguration und
 * Zweitschrift auf Werkseinstellung, ohne eine Zeile im Protokoll.
 */
function bm_konfig_taugt($roh)
{
    $roh = trim((string) $roh);
    if ($roh === '' || $roh === '{}') {
        return false;
    }
    $d = json_decode($roh, true);
    return is_array($d) && isset($d['aktionstoken'])
        && trim((string) $d['aktionstoken']) !== '';
}

function bm_config()
{
    $p = bm_paths();
    $darf = !bm_nur_lesen();
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';

    if ($roh === '') {
        $zustand = is_file($p['config']) ? 'leer' : 'fehlt';
    } elseif ($roh === '{}') {
        $zustand = 'leer';
    } elseif (is_array(json_decode($roh, true))) {
        $zustand = 'ok';
    } else {
        $zustand = 'kaputt';
    }

    if ($zustand !== 'ok') {
        $sicher = is_file($p['sicherung'])
            ? trim((string) @file_get_contents($p['sicherung'])) : '';
        $heilbar = bm_konfig_taugt($sicher);

        if ($zustand === 'kaputt') {
            /* Eine beschaedigte Datei wird NICHT ueberschrieben, sondern zur
             * Seite gelegt. Sie ist das einzige, was von den Einstellungen
             * noch da ist, wenn auch die Zweitschrift nichts taugt. */
            if ($darf && !is_file($p['config'] . '.kaputt')) {
                @copy($p['config'], $p['config'] . '.kaputt');
                @chmod($p['config'] . '.kaputt', 0600);
            }
            bm_log_gebremst('kaputt', 'Die Konfiguration ' . basename($p['config'])
                . ' ist kein gueltiges JSON. Eine Abschrift liegt als '
                . basename($p['config']) . '.kaputt daneben. '
                . ($heilbar ? 'Der Stand wird aus der Zweitschrift wiederhergestellt.'
                            : 'Die Zweitschrift traegt kein Aktionstoken - es gibt '
                            . 'nichts wiederherzustellen. Alle Adressen im Miniserver '
                            . 'muessen nach dem naechsten Speichern neu uebernommen werden.'),
                3600);
        }

        if ($heilbar && $darf) {
            @mkdir($p['configdir'], 0775, true);
            if (@copy($p['sicherung'], $p['config'])) {
                @chmod($p['config'], 0600);   // copy() legt sonst mit der umask an
                bm_konfig_lage('aus der Zweitschrift');
                $roh = $sicher;
                $zustand = 'ok';
            }
        } elseif ($zustand === 'kaputt') {
            bm_konfig_lage($heilbar ? 'kaputt' : 'kaputt ohne Zweitschrift');
        } else {
            bm_konfig_lage($zustand);
        }
    }
    bm_konfig_lage($zustand === 'ok' ? 'ok' : $zustand);

    $d = json_decode($roh, true);
    $cfg = array_merge(bm_vorgaben(), is_array($d) ? $d : array());
    // Die EVCC-Felder werden hier begrenzt und nicht erst beim Benutzen: sie
    // koennen auch aus einer von Hand bearbeiteten Datei kommen.
    $cfg['evcc_ein']      = empty($cfg['evcc_ein']) ? 0 : 1;
    $cfg['evcc_geraet']   = max(1, min(99, (int) $cfg['evcc_geraet']));
    $cfg['evcc_ladewatt'] = max(0, min(30000, (int) $cfg['evcc_ladewatt']));
    return $cfg;
}

function bm_config_speichern($cfg)
{
    $p = bm_paths();
    // Die Konfiguration enthaelt das Aktionstoken - deshalb 0600, nicht 0644.
    if (!bm_json_schreiben($p['config'], $cfg, 0600)) {
        return false;
    }
    /* Die Zweitschrift wird NUR mitgezogen, wenn der geschriebene Stand das
     * Geheimnis wirklich traegt (B02). Sonst ueberschreibt eine
     * Werkseinstellung, die gerade erst aus einem Fehler entstanden ist, die
     * letzte brauchbare Sicherung - und dann ist nichts mehr da. */
    if (isset($cfg['aktionstoken']) && trim((string) $cfg['aktionstoken']) !== '') {
        @copy($p['config'], $p['sicherung']);
        @chmod($p['sicherung'], 0600);
    }
    return true;
}

/**
 * Geraeteliste, 1-basiert, nur vollstaendige Eintraege.
 *
 * Unvollstaendige Zeilen werden uebergangen - gemeldet werden sie schon beim
 * Speichern in der Oberflaeche, nicht erst hier.
 */
/**
 * Die Geraetezeilen einer zurueckgespielten Sicherung beurteilen.
 *
 * Dieselben Massstaebe wie im Formular - das ist der Sinn (B01/B04).
 * Rueckgabe: Liste von Beanstandungen, leer heisst in Ordnung.
 */
function bm_geraetezeilen_pruefen($liste)
{
    $bean = array();
    if (!is_array($liste)) {
        return array(bm_t('EINST.FEHLER_KONFIG_FORM'));
    }
    $profile = bm_profile();
    $nr = 0;
    foreach ($liste as $g) {
        $nr++;
        if (!is_array($g)) {
            $bean[] = sprintf(bm_t('EINST.FEHLER_PROFIL'), $nr);
            continue;
        }
        $pk = isset($g['profil']) ? (string) $g['profil'] : '';
        if (!isset($profile[$pk])) {
            $bean[] = sprintf(bm_t('EINST.FEHLER_PROFIL'), $nr);
            continue;
        }
        $dev = trim((string) (isset($g['geraetedatei']) ? $g['geraetedatei'] : ''));
        if ($dev !== '' && !bm_geraetedatei_taugt($dev)) {
            $bean[] = sprintf(bm_t('EINST.FEHLER_DEV'), $nr);
        }
        $ip = trim((string) (isset($g['ip']) ? $g['ip'] : ''));
        if ($ip !== ''
            && preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $ip) !== 1
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9\.\-]{1,80}$/', $ip) !== 1) {
            $bean[] = sprintf(bm_t('EINST.FEHLER_IP'), $nr);
        }
        foreach (array('port' => array(1, 65535), 'unit' => array(0, 247),
                       'baud' => array(300, 921600),
                       'max_laden' => array(0, 30000),
                       'max_entladen' => array(0, 30000)) as $f => $gr) {
            if (!isset($g[$f]) || $g[$f] === '') {
                continue;
            }
            if (preg_match('/^[0-9]{1,6}$/', (string) $g[$f]) !== 1
                || (int) $g[$f] < $gr[0] || (int) $g[$f] > $gr[1]) {
                $bean[] = sprintf(bm_t('EINST.FEHLER_ZAHL_ZEILE'), $nr,
                    bm_t('EINST.T_' . strtoupper($f)), $gr[0], $gr[1]);
            }
        }
    }
    return $bean;
}

function bm_geraete()
{
    $cfg = bm_config();
    $profile = bm_profile();
    $out = array();
    /* Die Nummer ist eine ADRESSE und kommt aus der STELLE in der Liste,
     * nicht aus einem Zaehler ueber die gueltigen Zeilen (B06, 04.09.2026).
     *
     * Gemessen: Zeile 1 mit einem Profil, das es nicht mehr gab, Zeile 2
     * 'Garage' - bm_geraete() lieferte EINE Zeile, und die trug die Nummer 1.
     * Loxone fragte weiter geraet=1 und bekam ab da die Werte der anderen
     * Batterie, mit OK=1 und ohne jeden Hinweis. An derselben Nummer haengen
     * die Sollwertdatei und der Verlauf: ein Ladezwang traf danach den
     * falschen Speicher.
     *
     * Der Anlass ist nicht ausgedacht - eigene Profile liegen unter
     * data/plugins/<ordner>/profile/, und der Installer raeumt dieses
     * Verzeichnis bei JEDEM Upgrade ab.
     *
     * Auf einer heilen Anlage aendert sich dadurch nichts: sind alle Zeilen
     * gueltig, ist Stelle+1 dieselbe Zahl wie bisher. Faellt eine Zeile aus,
     * entsteht jetzt eine LUECKE statt einer Verschiebung - geraet=1
     * antwortet dann GERAET_UNBEKANNT, statt still einen anderen Speicher zu
     * liefern. */
    $n = 0;
    foreach ((array) $cfg['geraete'] as $g) {
        $n++;
        if (!is_array($g)) {
            continue;
        }
        $pk = isset($g['profil']) ? (string) $g['profil'] : '';
        if (!isset($profile[$pk])) {
            bm_log_gebremst('profil_weg_' . $n, 'Speicher ' . $n . ': das Profil "'
                . $pk . '" gibt es nicht (mehr). Die Zeile wird uebergangen, ihre '
                . 'Nummer bleibt aber belegt - geraet=' . $n . ' antwortet '
                . 'GERAET_UNBEKANNT. Eigene Profile liegen unter '
                . 'data/plugins/<ordner>/profile/ und ueberstehen kein Upgrade.', 3600);
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
            /* Dieselbe Beurteilung wie im Formular (B04). Bis 0.9.15 stand das
             * Muster NUR im Speichern-Zweig; gemessen gingen hier
             * '/dev/../etc/passwd', '/etc/passwd' und ein Pfad mit
             * Zeilenumbruch glatt durch - und der Wert landet in
             * bm_seriell_einstellen() (stty) und bm_pyl_befehl() (fopen r+b).
             * Erreichbar ueber eine zurueckgespielte Sicherung oder eine von
             * Hand bearbeitete Konfigurationsdatei. */
            if (!bm_geraetedatei_taugt($dev)) {
                bm_log_gebremst('dev_' . $n, 'Speicher ' . $n . ': die Geraetedatei '
                    . 'ist keine zulaessige Angabe (' . strlen($dev) . ' Zeichen, '
                    . 'erwartet wird ein Pfad unter /dev ohne ".."). Die Zeile wird '
                    . 'uebergangen.', 3600);
                continue;
            }
        } else {
            if ($ip === '') {
                $ip = isset($pr['ip']) ? $pr['ip'] : '';
            }
            if ($ip === '') {
                continue;
            }
        }
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

/**
 * Das Aktionstoken holen, bei Bedarf erzeugen.
 *
 * Die Erzeugung liegt hinter einer Dateisperre. Ohne sie koennen zwei
 * gleichzeitige Aufrufe - zwei offene Reiter der Oberflaeche genuegen - je
 * ein eigenes Token erzeugen und nacheinander speichern. Der zuerst
 * angezeigte Wert waere dann schon ueberholt, und die daraus gebaute
 * Loxone-Vorlage traege ein Token, das nicht mehr gilt. Der Miniserver
 * bekaeme spaeter HTTP 403 und niemand wuesste, warum.
 *
 * Nach dem Sperren wird die Konfiguration ERNEUT gelesen: der Prozess, der
 * die Sperre vor uns hatte, hat womoeglich schon eines geschrieben.
 *
 * Der Endpunkt index.php erzeugt uebrigens NIE ein Token - er liest nur und
 * antwortet mit 403, solange keines da ist. Diese Funktion laeuft
 * ausschliesslich in der angemeldeten Oberflaeche.
 */
function bm_token()
{
    $cfg = bm_config();
    if (trim((string) $cfg['aktionstoken']) !== '') {
        return (string) $cfg['aktionstoken'];
    }
    /* Die Sperre des unangemeldeten Bereichs gilt auch hier: der Endpunkt
     * antwortet lieber mit 403, als ein Token anzulegen (B03). */
    if (bm_nur_lesen()) {
        return '';
    }
    /* Fehlt das Token, obwohl eine Konfiguration BESTEHT, ist das kein
     * Neuanfang, sondern ein Verlust - meist aus einer unvollstaendigen
     * zurueckgespielten Sicherung (B05). Gemessen: alle Adressen im
     * Miniserver antworten danach 403, und ein Virtueller Ausgang wertet das
     * nicht aus. Genau eine Zeile, damit niemand den Fehler in Loxone sucht. */
    if (bm_konfig_lage() !== 'fehlt' && count($cfg) > count(bm_vorgaben()) - 1
        && is_file(bm_paths()['config'])) {
        bm_log_gebremst('token_neu', 'Es bestand eine Konfiguration, aber kein '
            . 'Aktionstoken. Es wird ein neues erzeugt - alle Adressen im '
            . 'Miniserver muessen im Reiter "Einbindung in Loxone" neu '
            . 'uebernommen werden.', 3600);
    }
    $p = bm_paths();
    if (!is_dir($p['datadir'])) {
        @mkdir($p['datadir'], 0775, true);
    }
    $sperre = $p['datadir'] . '/token.lock';
    $fp = @fopen($sperre, 'c+');
    if ($fp === false) {
        // Ohne Sperre lieber trotzdem eines erzeugen als gar keines - ohne
        // Token laeuft der Miniserver-Endpunkt ueberhaupt nicht.
        bm_log('Die Sperrdatei ' . $sperre . ' liess sich nicht anlegen - das Token '
             . 'wird ohne Sperre erzeugt.');
        $cfg['aktionstoken'] = bm_token_erzeugen();
        bm_config_speichern($cfg);
        return (string) $cfg['aktionstoken'];
    }
    if (@flock($fp, LOCK_EX)) {
        $cfg = bm_config();                      // zweiter Blick unter der Sperre
        if (trim((string) $cfg['aktionstoken']) === '') {
            $cfg['aktionstoken'] = bm_token_erzeugen();
            if (!bm_config_speichern($cfg)) {
                bm_log('Das neu erzeugte Aktionstoken liess sich NICHT speichern. '
                     . 'Beim naechsten Aufruf wird ein anderes erzeugt - die '
                     . 'Loxone-Adressen muessten dann erneut uebernommen werden.');
            }
        }
        @flock($fp, LOCK_UN);
    }
    fclose($fp);
    return (string) $cfg['aktionstoken'];
}

/**
 * Die Art eines laufenden Zwangs als Zahl.
 *
 *   0  kein Zwang, der Speicher regelt selbst
 *   1  Laden erzwungen
 *   2  Entladen erzwungen
 *   3  gesperrt (nicht entladen)
 *
 * Der Sollwert ist eine Zeichenkette wie 'laden:500'. Als Text taugt er weder
 * fuer einen analogen virtuellen Eingang noch fuer einen Vergleicher in
 * Loxone. Der Endpunkt liefert ihn deshalb ZUSAETZLICH als Zahl; die
 * Zeichenkette bleibt unveraendert stehen, damit bestehende Projekte weiter
 * finden, was sie suchen.
 */
function bm_sollart($sollwert)
{
    $s = (string) $sollwert;
    /* 4 = eine Schrittfolge ist mitten im Absetzen abgebrochen, und die
     * Ruecknahme in die Automatik ist ebenfalls gescheitert (B10). Das Geraet
     * steht dann moeglicherweise im Zwangsbetrieb, ohne dass jemand einen
     * Sollwert gesetzt haette. Hinten angehaengt, damit die bestehenden
     * Zahlen 0 bis 3 ihre Bedeutung behalten. */
    if (strpos($s, 'unvollstaendig') === 0) {
        return 4;
    }
    // 'entladen' zuerst: 'laden' steckt darin, wenn auch nicht an Stelle 0.
    if (strpos($s, 'entladen') === 0) {
        return 2;
    }
    if (strpos($s, 'laden') === 0) {
        return 1;
    }
    if (strpos($s, 'sperren') === 0) {
        return 3;
    }
    return 0;
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

/**
 * Eine Zeile ins Protokoll.
 *
 * LOCK_EX ist hier Pflicht, nicht Zierde: in diese eine Datei schreiben der
 * Dienst, der Miniserver-Endpunkt, die Oberflaeche und das Startskript -
 * teils gleichzeitig. Ohne Sperre koennen sich zwei Zeilen ineinander
 * schieben, und im Log stehen dann Bruchstuecke, an denen die Fehlersuche
 * scheitert.
 *
 * Auch die Rotation braucht die Sperre. Sie ist Lesen-Aendern-Schreiben:
 * ohne Sperre kann ein zweiter Prozess zwischen dem Lesen und dem
 * Zurueckschreiben Zeilen anhaengen, die dann verloren sind - oder er
 * rotiert gleichzeitig und die Datei wird doppelt gekuerzt.
 */
function bm_log($text)
{
    $p = bm_paths();
    if (!is_dir($p['logdir'])) {
        @mkdir($p['logdir'], 0775, true);
    }
    $zeile = '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n";

    // Ohne diese Zeile oeffnet das Tor unten in einem langlebigen
    // Prozess nie: filesize() sieht die erste Groesse und danach keine
    // neue. Das clearstatcache UNTER der Sperre hilft dann nicht mehr,
    // weil es nie erreicht wird. Gemessen 29.08.2026 unter PHP 7.4.33.
    clearstatcache(true, $p['log']);
    if (is_file($p['log']) && filesize($p['log']) > 512000) {
        $fp = @fopen($p['log'], 'c+');
        if ($fp !== false) {
            if (@flock($fp, LOCK_EX)) {
                // Unter der Sperre neu bemessen - ein anderer Prozess kann
                // inzwischen rotiert haben.
                clearstatcache(true, $p['log']);
                if (filesize($p['log']) > 512000) {
                    $inhalt = stream_get_contents($fp, -1, 0);
                    $rest = array_slice(explode("\n", (string) $inhalt), -400);
                    ftruncate($fp, 0);
                    rewind($fp);
                    fwrite($fp, implode("\n", $rest) . "\n");
                    fflush($fp);
                }
                @flock($fp, LOCK_UN);
            }
            fclose($fp);
        }
    }
    @file_put_contents($p['log'], $zeile, FILE_APPEND | LOCK_EX);
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
    // escapeshellarg, nicht escapeshellcmd: letzteres maskiert nur einzelne
    // Sonderzeichen und laesst ein Leerzeichen im Pfad unangetastet - der
    // Aufruf zerfiele dann in zwei Worte. Hausregel aus AudiConnect.
    @exec(escapeshellarg($skript) . ' ' . escapeshellarg($befehl) . ' 2>&1', $ausgabe, $code);
    return array($code === 0 ? 1 : 0, implode("\n", $ausgabe));
}

/* ==================================================================
 * Serielle Schnittstellen finden
 *
 * /dev/ttyUSB0 ist keine Zusage, sondern eine Reihenfolge: sie richtet sich
 * danach, welcher Adapter beim Hochfahren zuerst erkannt wurde. Steckt neben
 * dem RS485-Adapter noch ein EnOcean- oder Zigbee-Stick, kann der Speicher
 * nach jedem Neustart woanders liegen - und das Plugin meldet dann
 * 'keine Antwort', obwohl alles heil ist.
 *
 * Die Namen unter /dev/serial/by-id/ haengen dagegen am Geraet selbst und
 * bleiben gleich. Die Oberflaeche bietet sie deshalb zur Auswahl an.
 * ================================================================== */
function bm_serielle_geraete()
{
    $out = array();
    foreach (array('/dev/serial/by-id') as $ordner) {
        foreach ((array) @glob($ordner . '/*') as $pfad) {
            $ziel = @readlink($pfad);
            $out[$pfad] = array(
                'pfad'   => $pfad,
                'zeigt'  => $ziel ? basename($ziel) : '',
                'stabil' => 1,
            );
        }
    }
    foreach ((array) @glob('/dev/tty{USB,ACM,AMA,S}[0-9]*', GLOB_BRACE) as $pfad) {
        if (!isset($out[$pfad])) {
            $out[$pfad] = array('pfad' => $pfad, 'zeigt' => '', 'stabil' => 0);
        }
    }
    ksort($out);
    return $out;
}

/**
 * Zu einem klassischen Namen den gleichwertigen stabilen suchen.
 * Rueckgabe: der by-id-Pfad oder '' - es wird NICHTS umgestellt, nur
 * vorgeschlagen. Ein Pfad, den der Anwender eingetragen hat, wird nicht
 * hinter seinem Ruecken ausgetauscht.
 */
function bm_serielle_empfehlung($pfad)
{
    $pfad = trim((string) $pfad);
    if ($pfad === '' || strpos($pfad, '/dev/serial/by-id/') === 0) {
        return '';
    }
    $echt = @realpath($pfad);
    if ($echt === false) {
        return '';
    }
    foreach ((array) @glob('/dev/serial/by-id/*') as $kand) {
        if (@realpath($kand) === $echt) {
            return $kand;
        }
    }
    return '';
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

/**
 * Einen Tagesverlauf lesen.
 *
 * Bis 0.9.7 standen je Zeile nur Zeit, Ladezustand und Leistung. Ab 0.9.8
 * kommen Zelldrift, hoechste Temperatur, Gesundheitszustand und Zyklenzahl
 * dazu - also gerade die vier Groessen, wegen deren langsamer Veraenderung
 * man ein BMS ueberhaupt ausliest.
 *
 * Aeltere Dateien haben drei Spalten und bleiben lesbar; die fehlenden
 * Spalten sind dann null und werden NICHT durch eine 0 ersetzt.
 *
 * Rueckgabe je Punkt: array(ts, soc, pbat, uzdiff, tmax, soh, zyklen). Die
 * ersten drei Stellen sind absichtlich unveraendert, damit bm_soc_svg()
 * weiterlaeuft.
 */
function bm_verlauf_lesen($nummer, $tag = '')
{
    if ($tag === '') {
        $tag = date('Ymd');
    }
    if (!preg_match('/^[0-9]{8}$/', (string) $tag)) {
        return array();
    }
    $f = bm_paths()['datadir'] . '/verlauf/geraet' . (int) $nummer . '_' . $tag . '.csv';
    $out = array();
    if (is_file($f)) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $zeile) {
            $c = explode(';', $zeile);
            if (count($c) < 2) {
                continue;
            }
            $z = function ($i) use ($c) {
                return (isset($c[$i]) && $c[$i] !== '') ? (float) $c[$i] : null;
            };
            $out[] = array((int) $c[0], (float) $c[1],
                           $z(2) === null ? 0 : $z(2),
                           $z(3), $z(4), $z(5), $z(6));
        }
    }
    return $out;
}

/** Welche Tage liegen fuer diesen Speicher vor? Neueste zuerst. */
function bm_verlauf_tage($nummer)
{
    $muster = bm_paths()['datadir'] . '/verlauf/geraet' . (int) $nummer . '_*.csv';
    $tage = array();
    foreach ((array) glob($muster) as $d) {
        if (preg_match('/_([0-9]{8})\.csv$/', $d, $m)) {
            $tage[] = $m[1];
        }
    }
    rsort($tage);
    return $tage;
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
/**
 * Fremden Text so zurechtmachen, dass er durch json_encode passt.
 *
 * Alles, was von aussen kommt - die Ausgabe eines Systembefehls, ein
 * Fehlertext des Betriebssystems, ein Geraetename aus einer Antwort - kann
 * Bytes enthalten, die kein gueltiges UTF-8 sind. Ein einziges davon laesst
 * json_encode false zurueckgeben, und dann laesst sich weder das Abbild
 * schreiben noch der JSON-Endpunkt beantworten.
 *
 * Deshalb wird solcher Text an der EINTRITTSSTELLE bereinigt, nicht erst
 * beim Ausgeben. Zusaetzlich fliegen Steuerzeichen heraus: sie haetten in
 * einer Meldung nichts verloren und wuerden ueber den UDP-Weg zum
 * MQTT-Gateway ohnehin stoeren.
 */
function bm_text_sauber($text, $hoechstens = 400)
{
    $t = (string) $text;
    if (!preg_match('//u', $t)) {
        if (function_exists('iconv')) {
            $neu = @iconv('WINDOWS-1252', 'UTF-8//TRANSLIT', $t);
            if ($neu !== false) {
                $t = $neu;
            }
        }
        if (!preg_match('//u', $t)) {
            $t = preg_replace('/[\x80-\xFF]/', '?', $t);
        }
    }
    $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $t);
    $t = trim(preg_replace('/\s+/u', ' ', $t));
    if ($hoechstens > 0 && strlen($t) > $hoechstens) {
        $t = substr($t, 0, $hoechstens);
        // Nicht mitten in einem Mehrbyte-Zeichen abschneiden.
        while ($t !== '' && !preg_match('//u', $t)) {
            $t = substr($t, 0, -1);
        }
        $t .= ' …';
    }
    return $t;
}

function bm_fehlertext($text, $errno = 0)
{
    $text = bm_text_sauber($text);
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

/* ==================================================================
 * Offene Verbindungen wiederverwenden
 *
 * Bis 0.9.0 oeffnete JEDER Abruf eine eigene TCP-Verbindung und schloss sie
 * gleich wieder. Bei der BYD-Box hiess das: bm_abruf_modbus() macht auf,
 * liest, macht zu - und bm_zellen_byd() macht im selben Atemzug wieder auf.
 * Die BCU laesst aber nur EINE Verbindung gleichzeitig zu und gibt die alte
 * nicht in derselben Millisekunde frei; der zweite Verbindungsaufbau lief
 * deshalb regelmaessig ins Leere, und im Protokoll stand 'Verbindung
 * abgewiesen', obwohl niemand sonst mit der Box sprach.
 *
 * Deshalb wird eine offene Verbindung je Geraet vorgehalten und ueber die
 * Durchlaeufe hinweg weiterbenutzt.
 *
 * Zur Einordnung der oft genannten Sorge vor TIME_WAIT: bei dem
 * voreingestellten Takt von 30 s waren das rund zwei Verbindungen je Minute.
 * Das erschoepft keinen TCP-Stapel. Der Gewinn liegt woanders - im Wegfall
 * des zweiten Verbindungsaufbaus bei Geraeten, die nur einen zulassen, und
 * in der geringeren Verzoegerung je Durchlauf.
 *
 * Eine wiederverwendete Verbindung kann natuerlich unbemerkt tot sein: das
 * Geraet startet neu, eine Firewall raeumt den Eintrag ab. Darum gilt die
 * Regel: schlaegt eine Anforderung auf einer wiederverwendeten Verbindung
 * fehl, wird sie verworfen und GENAU EINMAL frisch aufgebaut. Erst wenn auch
 * das misslingt, ist es ein Fehler.
 * ================================================================== */

function &bm_verbindungsliste()
{
    static $liste = array();
    return $liste;
}

function bm_verbindungsschluessel(array $g)
{
    return $g['transport'] . '|' . $g['ip'] . '|' . (int) $g['port'] . '|' . (int) $g['unit'];
}

/**
 * Verbindung zu einem Geraet holen - vorhandene wiederverwenden.
 * Rueckgabe: Stream oder array('_fehler' => Text).
 */
function bm_verbindung_holen(array $g, $tmo, $wiederverwenden = true)
{
    $liste = &bm_verbindungsliste();
    $k = bm_verbindungsschluessel($g);
    if ($wiederverwenden && isset($liste[$k])) {
        $s = $liste[$k];
        if (is_resource($s) && !feof($s)) {
            return $s;
        }
        if (is_resource($s)) {
            @fclose($s);
        }
        unset($liste[$k]);
    }
    $s = bm_verbinden($g, $tmo);
    if (is_array($s)) {
        return $s;
    }
    if ($wiederverwenden) {
        $liste[$k] = $s;
    }
    return $s;
}

/** Eine Verbindung verwerfen - nach jedem Fehler darauf. */
function bm_verbindung_verwerfen(array $g)
{
    $liste = &bm_verbindungsliste();
    $k = bm_verbindungsschluessel($g);
    if (isset($liste[$k])) {
        if (is_resource($liste[$k])) {
            @fclose($liste[$k]);
        }
        unset($liste[$k]);
    }
}

/** Alle offenen Verbindungen schliessen (beim Anhalten des Dienstes). */
function bm_verbindungen_schliessen()
{
    $liste = &bm_verbindungsliste();
    foreach ($liste as $k => $s) {
        if (is_resource($s)) {
            @fclose($s);
        }
        unset($liste[$k]);
    }
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
    bm_mitschnitt('>', $rahmen, $g);
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
        if ($fc === 1 || $fc === 2 || $fc === 3 || $fc === 4) {
            // FC 1 und 2 antworten wie 3 und 4 mit einem Laengenbyte. Bis
            // 0.9.7 fielen sie in den Zweig fuer FC 6/16 mit fester Laenge
            // und wurden dadurch falsch gelesen.
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
        bm_mitschnitt('<', $ganz, $g);
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
    /* B16: bis 0.9.15 wurden trans, unit und fc zwar gelesen, aber nie
     * gegengehalten - nur proto, len und das 0x80-Bit. Der RTU-Zweig prueft
     * die Geraeteadresse dagegen sehr wohl. Verbindungen werden ueber
     * Durchlaeufe hinweg offengehalten; traf nach einer Zeitueberschreitung
     * die verspaetete Antwort ein, wurde sie als Messwert des AKTUELLEN
     * Blocks uebernommen - keine Fehlermeldung, sondern die falsche Zahl. */
    if ((int) $teile['trans'] !== (int) $transaktion) {
        return array('_fehler' => 'Die Antwort traegt die Vorgangsnummer '
            . (int) $teile['trans'] . ', gefragt war ' . (int) $transaktion
            . '. Das ist die Antwort auf eine fruehere oder fremde Anforderung.');
    }
    if ((int) $teile['unit'] !== $unit) {
        return array('_fehler' => 'Die Antwort traegt die Geraeteadresse '
            . (int) $teile['unit'] . ', gefragt war ' . $unit . '.');
    }
    if (((int) $teile['fc'] & 0x7F) !== (ord($pdu[0]) & 0x7F)) {
        return array('_fehler' => 'Die Antwort traegt den Funktionscode '
            . ((int) $teile['fc'] & 0x7F) . ', gefragt war ' . (ord($pdu[0]) & 0x7F) . '.');
    }
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
    bm_mitschnitt('<', $kopf . $rest, $g);
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
    $reg  = ((int) $reg) & 0xFFFF;
    /* B42: bis 0.9.15 stand hier '$wert = ((int) $wert) & 0xFFFF;' - der Wert
     * wurde also gekappt, und bm_echo_pruefen() bekam den bereits gekappten
     * Wert zum Vergleich, konnte die Kappung also gar nicht sehen. Der
     * Kommentar im Dienst verspricht ausdruecklich 'ABGEWIESEN, nicht
     * stillschweigend gekappt'; fuer alles ueber 16 Bit hielt er nicht.
     * Erreichbar ist das nur ueber ein selbst geschriebenes Profil, das
     * '@watt' als u16 hinterlegt - die eingebauten fuehren es als u32.
     * Abweisen statt kappen: */
    if ((int) $wert < 0 || (int) $wert > 0xFFFF) {
        return array('_fehler' => 'Der Wert ' . (int) $wert . ' passt nicht in ein '
            . '16-Bit-Register (0 bis 65535). Der Befehl gilt als NICHT ausgefuehrt. '
            . 'Ein Profil, das groessere Werte schreiben muss, hinterlegt den '
            . 'Schritt als u32.');
    }
    $wert = (int) $wert;
    $pdu = pack('Cnn', 6, $reg, $wert);
    $antwort = bm_modbus_anfrage($s, $g, $pdu);
    if (is_array($antwort)) {
        return $antwort;
    }
    // Bis 0.9.6 stand hier 'return true' - geprueft war damit der
    // Rueckgabewert, nicht die Wirkung.
    return bm_echo_pruefen($antwort, $reg, $wert, 'den Wert');
}

/**
 * Die Spiegelung einer Schreibantwort gegen das Gesendete halten.
 *
 * Modbus beantwortet FC 6 mit Registeradresse und Wert, FC 16 mit
 * Registeradresse und Anzahl - in beiden Faellen genau zwei 16-Bit-Zahlen,
 * die das Gesendete wiederholen.
 *
 * Bis 0.9.6 wurde diese Antwort weggeworfen. Gemeldet wurde Erfolg, sobald
 * ueberhaupt ein wohlgeformter Rahmen ankam. Ein Geraet, das den Wert kappt,
 * auf ein anderes Register schreibt oder den Befehl nur teilweise annimmt,
 * galt damit als erledigt: in Loxone stand ein Ladezwang, den niemand
 * ausfuehrte, und im Protokoll stand 'erledigt'.
 *
 * Abweichungen werden GEMELDET, nicht hingenommen. Ein Geraet, das statt der
 * bestellten 5000 W nur 3000 uebernimmt, hat nicht diesen Befehl ausgefuehrt,
 * sondern einen anderen - und der Anwender soll das erfahren, solange er noch
 * davorsteht.
 */
function bm_echo_pruefen($antwort, $reg, $zweite, $was)
{
    if (strlen($antwort) < 4) {
        return array('_fehler' => 'Die Bestaetigung des Schreibbefehls ist mit '
            . strlen($antwort) . ' statt 4 Datenbytes zu kurz. Der Befehl gilt als NICHT '
            . 'ausgefuehrt.');
    }
    $e = unpack('nreg/nwert', substr($antwort, 0, 4));
    if ((int) $e['reg'] !== (int) $reg) {
        return array('_fehler' => 'Das Geraet bestaetigt Register ' . (int) $e['reg']
            . ', geschrieben wurde aber Register ' . (int) $reg . '. Der Befehl gilt als '
            . 'NICHT ausgefuehrt.');
    }
    if ((int) $e['wert'] !== (int) $zweite) {
        return array('_fehler' => 'Das Geraet bestaetigt fuer Register ' . (int) $reg . ' '
            . $was . ' ' . (int) $e['wert'] . ', gesendet wurden ' . (int) $zweite . '. Der '
            . 'Wert wurde also veraendert oder abgewiesen; der Befehl gilt als NICHT '
            . 'ausgefuehrt. Bei einer Leistungsangabe heisst das meist, dass das Geraet '
            . 'eine eigene Obergrenze hat.');
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
    return bm_echo_pruefen($antwort, ((int) $reg) & 0xFFFF, $anz, 'die Registerzahl');
}

/** Rohwerte in eine Zahl umrechnen. Fehlt ein Register, kommt null zurueck. */
/**
 * Wie breit ist das Teilfeld, das eine Maske nach dem Schieben uebriglaesst?
 *
 * Gebraucht fuer das Vorzeichen (B12): nach 'maske 0xF000, schieben 12' ist
 * das Feld vier Bit breit, und 0xF heisst dort -1, nicht 15. Die feste
 * Schwelle 0x8000 traf das nie.
 */
function bm_maskenbreite($maske, $schieben)
{
    $m = ((int) $maske) >> (int) $schieben;
    $b = 0;
    while ($m > 0) {
        $b++;
        $m >>= 1;
    }
    return $b > 0 ? $b : 1;
}

function bm_wert_aus(array $regs, array $feld)
{
    $reg = (int) $feld['reg'];
    if (!isset($regs[$reg])) {
        return null;
    }
    $typ = isset($feld['typ']) ? $feld['typ'] : 'u16';
    $roh = $regs[$reg];

    /* B13: bis 0.9.15 hatte der switch darunter kein default. Ein vertippter
     * oder anders geschriebener Typ ('S16', 'int16', 'i16') fiel still auf
     * u16 durch und ergab den Rohwert mal Faktor - eine Zahl, die aussieht
     * wie ein Messwert. Genau die Fehlerart, wegen der 'ascii' in 0.9.7
     * entfernt wurde. Kein Wert ist besser als ein falscher. */
    $bekannt = array('u16', 's16', 'u32', 's32', 'f32', 'u16hi', 'u16lo');
    if (!in_array($typ, $bekannt, true)) {
        bm_log_gebremst('typ_' . $typ, 'Ein Profil nennt den Registertyp "'
            . bm_text_sauber((string) $typ, 20) . '", den es nicht gibt. Erlaubt sind: '
            . implode(', ', $bekannt) . '. Das Feld bleibt leer - ein Rohwert mal '
            . 'Faktor waere eine Zahl, die aussieht wie ein Messwert.', 3600);
        return null;
    }

    /* B11/B12: Maske und Schieben wirkten bis 0.9.15 NUR auf das erste
     * Register und wurden von den 32-Bit-Zweigen ueberschrieben, weil die
     * $roh vollstaendig neu zuweisen. Gemessen: Register 0xFF01/0x0002 mit
     * typ=u32 und maske=0x00FF ergab 4278255618 - exakt den ungemaskten Wert.
     * Jetzt wird zuerst der volle Wert gebildet und die Maske DANACH
     * angewandt; das Vorzeichen richtet sich nach der Breite des Teilfelds,
     * nicht nach der festen Schwelle 0x8000 (0xF000 mit maske=0xF000 und
     * schieben=12 ergab 15 statt -1). */
    $maske = isset($feld['maske']) ? (int) $feld['maske'] : null;
    $schieben = isset($feld['schieben']) ? (int) $feld['schieben'] : 0;
    $breite = 16;

    switch ($typ) {
        case 's16':
            if ($maske !== null) {
                $roh = ($roh & $maske) >> $schieben;
                $breite = bm_maskenbreite($maske, $schieben);
            }
            $halb = 1 << ($breite - 1);
            $roh = ($roh >= $halb) ? $roh - (1 << $breite) : $roh;
            $maske = null;   // schon angewandt
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
            if ($maske !== null) {
                $roh = ($roh & $maske) >> $schieben;
                $breite = bm_maskenbreite($maske, $schieben);
                $maske = null;
            } else {
                $breite = 32;
            }
            if ($typ === 's32') {
                $halb = 1 << ($breite - 1);
                $roh = ($roh >= $halb) ? $roh - (1 << $breite) : $roh;
            }
            break;
        case 'f32':
            /* IEEE 754, einfache Genauigkeit, ueber zwei Register.
             *
             * NaN und Unendlich sind keine Messwerte. Sie kommen vor, wenn ein
             * Geraet ein Register noch nicht belegt hat - und sie wuerden hier
             * ungebremst nach Loxone durchschlagen. Deshalb null: lieber kein
             * Wert als eine Zahl, die niemand gemessen hat. */
            if (!isset($regs[$reg + 1])) {
                return null;
            }
            $wort = isset($feld['wort']) ? $feld['wort'] : 'hoch';
            $hoch   = ($wort === 'nieder') ? $regs[$reg + 1] : $regs[$reg];
            $nieder = ($wort === 'nieder') ? $regs[$reg] : $regs[$reg + 1];
            $auf = @unpack('Gwert', pack('n2', $hoch & 0xFFFF, $nieder & 0xFFFF));
            if (!is_array($auf) || !isset($auf['wert']) || !is_finite($auf['wert'])) {
                return null;
            }
            $roh = $auf['wert'];
            break;
        case 'u16hi':
            $roh = ($roh >> 8) & 0xFF;
            $maske = null;
            break;
        case 'u16lo':
            $roh = $roh & 0xFF;
            $maske = null;
            break;
    }
    /* u16 und f32: die Maske ist hier noch offen. Bei f32 ergibt eine Maske
     * keinen Sinn (das Bitmuster IST die Zahl) - deshalb wird sie dort
     * ausdruecklich uebergangen und gemeldet, statt still zu wirken. */
    if ($maske !== null) {
        if ($typ === 'f32') {
            bm_log_gebremst('maske_f32', 'Ein Profil setzt eine Maske auf ein '
                . 'f32-Feld (Register ' . $reg . '). Bei einer Gleitkommazahl ist '
                . 'das Bitmuster die Zahl selbst - die Maske wird uebergangen.', 3600);
        } else {
            $roh = ($roh & $maske) >> $schieben;
        }
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
        // Die Ausgabe von stty haengt an der Spracheinstellung des Systems.
        // Auf einem LoxBerry mit de_DE.ISO-8859-1 kommen von dort Bytes, die
        // kein UTF-8 sind - und dieser Text wandert als Fehlermeldung in das
        // Abbild fuer Loxone, das als JSON geschrieben wird. Ein einziges
        // solches Byte liess bis 0.9.0 json_encode scheitern: das Abbild
        // blieb stumm auf dem alten Stand, und ?aktion=roh lieferte eine
        // leere Seite.
        return 'stty konnte die Schnittstelle nicht einstellen: '
             . bm_text_sauber(implode(' ', $ausgabe));
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
    /* Alles wegwerfen, was noch im Puffer liegt - sonst wird die Antwort auf
     * den vorigen Befehl fuer die auf diesen gehalten.
     *
     * B36: bis 0.9.15 hatte diese Schleife KEINE Zeitschranke ($tmo gilt erst
     * fuer die Leseschleife darunter). Ein Dauerpegel auf der RS485-Leitung -
     * zweiter Master, vertauschtes A/B-Paar, fehlender Abschlusswiderstand,
     * also genau die Ursachen, die die Fehlertexte dieses Plugins selbst
     * nennen - haengt den ganzen Dienst hier fest; dienst.sh stop wartet dann
     * seine zehn Sekunden ab und setzt mit kill -9 nach. */
    $bm_leer_bis = microtime(true) + 1.0;
    $bm_weg = 0;
    while (@fread($fh, 4096) !== '') {
        $bm_weg++;
        if (microtime(true) > $bm_leer_bis) {
            @fclose($fh);
            return array('_fehler' => 'Auf ' . $geraetedatei . ' liegt ein Dauerpegel: '
                . 'nach einer Sekunde kamen immer noch Daten (' . $bm_weg . ' Bloecke). '
                . 'Meist ein zweiter Master am Bus, ein vertauschtes A/B-Paar oder ein '
                . 'fehlender Abschlusswiderstand.');
        }
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
    $leer = array('gefunden' => 0, 'autostart' => 0, 'fassung' => 0, 'udpport' => 0, 'broker' => '',
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
        /* Die FASSUNG des MQTT-Gateways, ab Werk 1. Sie entscheidet, was der
         * Anwender eintragen muss: unter V1 jedes Thema von Hand, ab V2
         * erscheint die Themengruppe von selbst in den Subscriptions.
         * 0 heisst "nicht feststellbar" - dann wird nichts behauptet,
         * sondern es werden beide Faelle genannt. */
        'fassung'    => (int) $hol('Gatewayversion', 'gatewayversion'),
        'udpport'    => (int) $hol('Udpinport', 'udpinport'),
        'broker'     => (string) $hol('Brokerhost', 'brokerhost'),
        'brokerport' => (string) $hol('Brokerport', 'brokerport'),
        'user'       => (string) $hol('Brokeruser', 'brokeruser'),
        'lokal'      => in_array((string) $hol('Uselocalbroker', 'uselocalbroker'), array('1', 'true'), true) ? 1 : 0,
    );
}

/**
 * Der Hinweis zum MQTT-Abo - in der Fassung, die zum GATEWAY passt.
 *
 * Bis hierher stand an den Ausgabestellen unbedingt "Ohne diesen Eintrag
 * kommt am Miniserver nichts an". Das gilt fuer Gateway V1, wo jedes Thema
 * von Hand einzutragen ist. Ab V2 erscheint die Themengruppe von selbst in
 * den Subscriptions - der Satz schickte jeden V2-Anwender zu einem
 * Eingabeplatz, den es nicht gibt.
 *
 * Drei Ausgaenge, nicht zwei: ist die Fassung nicht feststellbar, werden
 * BEIDE Faelle genannt statt einer behauptet.
 */
function bm_abo_text()
{
    $m = bm_mqtt_zustand();
    $f = isset($m['fassung']) ? (int) $m['fassung'] : 0;
    if ($f <= 0) {
        return bm_t('MQTT.ABO_UNBEKANNT');
    }
    $gemessen = ' <span class="sm-mono">'
              . sprintf(bm_t('MQTT.ABO_GEMESSEN'), $f) . '</span>';
    return bm_t($f >= 2 ? 'MQTT.ABO_V2' : 'MQTT.ABO_WARNUNG') . $gemessen;
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
        // Der UDP-Eingang des Gateways wertet einen Zeilenumbruch als Ende
        // des Befehls. Ein mehrzeiliger Wert - etwa eine Fehlermeldung des
        // Betriebssystems oder die Ausgabe von stty - zerlegt die Uebertragung
        // deshalb in Bruchstuecke, aus denen das Gateway erfundene Topics
        // bildet. Auch ein Tabulator hat dort nichts zu suchen: Leerzeichen
        // trennt Thema und Wert.
        $wert = bm_mqtt_wert_saeubern($v);
        if ($wert === '') {
            continue;
        }
        /* Auch THEMA und Schluessel saeubern, nicht nur den Wert (B41).
         * Bis 0.9.15 lief nur der Wert durch die Reinigung. Das Praefix kommt
         * aus mqtt_topic, und das Zurueckspielen einer Sicherung pruefte
         * keinen Wert - ein Zeilenumbruch darin haette in jedem Datagramm
         * eine zweite publish-Zeile erzeugt. Dieselbe Bauart wie der
         * Saugroboter-Befund vom 27.08.2026. */
        $thema = bm_mqtt_thema_saeubern($praefix . '/' . $k);
        if ($thema === '') {
            continue;
        }
        $msg = 'publish ' . $thema . ' ' . $wert;
        @socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $z['udpport']);
    }
    socket_close($s);
    return true;
}

/**
 * Dieselbe Bereinigung wie beim Senden - fuer die Selbstpruefung, damit sich
 * nachweisen laesst, dass sie greift.
 */
function bm_mqtt_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

/**
 * Dasselbe fuer die andere Haelfte der Zeile: das Thema.
 *
 * Das Gateway liest 'publish <thema> <wert>' zeilenweise und trennt Thema und
 * Wert am Leerzeichen. Ein Zeilenumbruch im Thema erzeugt eine zweite
 * Anweisung, ein Leerzeichen verschiebt die Grenze. Zugelassen ist deshalb
 * nur, was in einem MQTT-Thema ohnehin vorkommt.
 */
function bm_mqtt_thema_saeubern($t)
{
    $t = preg_replace('#[^A-Za-z0-9_/\-]+#', '_', (string) $t);
    $t = preg_replace('#/{2,}#', '/', $t);
    return trim($t, '/');
}

/**
 * Alle Themen-Wert-Paare eines Abbilds bilden - ohne zu senden.
 *
 * Bis 0.9.6 entstanden diese Paare mitten im Dienst, in bm_veroeffentlichen().
 * Von aussen war damit nicht pruefbar, WAS tatsaechlich veroeffentlicht wird -
 * der Reiter Test konnte nur auflisten, welche Themen es geben koennte. Als
 * eigene Funktion lassen sie sich rechnen und ansehen, ohne ein einziges Paket
 * zu schicken.
 *
 * $melden = false unterdrueckt die Protokollzeile ueber ein fehlendes
 * EVCC-Geraet. Der Reiter Test rechnet die Paare nur zum Ansehen nach, und
 * eine Pruefung darf nichts schreiben.
 */
function bm_mqtt_paare(array $abbild, $melden = true)
{
    $cfg = bm_config();
    $geraete = (isset($abbild['geraete']) && is_array($abbild['geraete']))
        ? $abbild['geraete'] : array();
    $paare = array(
        'ok'      => (int) (isset($abbild['ok']) ? $abbild['ok'] : 0),
        'geraete' => count($geraete),
        /* Der Zeitstempel des Abbilds - neu in 0.9.7.
         *
         * Der Endpunkt fuer den Miniserver liefert seit jeher ALTER; der
         * MQTT-Zweig lieferte nichts dergleichen. Bei einem Push-Weg ist das
         * Alter zum Sendezeitpunkt auch immer 0 - was fehlte, ist der
         * Zeitstempel, aus dem sich das Alter auf der Gegenseite bilden laesst.
         *
         * Ohne ihn ist ein toter Dienst von einem gesunden nicht zu
         * unterscheiden: es wird schlicht nichts mehr gesendet, die zuletzt
         * gesendeten Werte stehen weiter im Broker, und geraetN/ok behaelt die
         * 1, die es zuletzt hatte. Das ist die stille Falschaussage, und die
         * ist schlimmer als eine Fehlermeldung.
         *
         * Loxone rechnet in Sekunden seit dem 01.01.2009. Das Alter ergibt
         * sich dort als (Loxone-Zeit + 1230768000) - ts. Der Satz steht auch
         * im Reiter Einbindung in Loxone. */
        'ts'      => (int) (isset($abbild['ts']) ? $abbild['ts'] : 0),
    );
    foreach ($geraete as $nr => $e) {
        $vor = 'geraet' . (int) $nr;
        foreach (array('SOC' => 'soc', 'SOH' => 'soh', 'UBAT' => 'ubat', 'IBAT' => 'ibat',
                       'PBAT' => 'pbat', 'TMAX' => 'tmax', 'TMIN' => 'tmin',
                       'UZMAX' => 'uzmax', 'UZMIN' => 'uzmin', 'UZDIFF' => 'uzdiff',
                       'ZYKLEN' => 'zyklen', 'KAPAZ' => 'kapaz', 'MODULE' => 'module',
                       'ZELLEN' => 'zellen', 'FEHLER' => 'fehler', 'WARNUNG' => 'warnung',
                       'MODUS' => 'modus', 'UZMINMOD' => 'uzminmod',
                       'UZMINZELLE' => 'uzminzelle', 'RESTKWH' => 'restkwh',
                       'RESTZEIT' => 'restzeit', 'ALARM' => 'alarm') as $feld => $thema) {
            $paare[$vor . '/' . $thema] = isset($e[$feld]) ? $e[$feld] : null;
        }
        $paare[$vor . '/alarmtext'] = isset($e['alarmtext']) ? $e['alarmtext'] : null;
        $paare[$vor . '/sollquelle'] = isset($e['sollwert_quelle']) ? $e['sollwert_quelle'] : null;
        $paare[$vor . '/ok'] = (int) (isset($e['ok']) ? $e['ok'] : 0);
        /* Zeitstempel des letzten ERFOLGREICHEN Abrufs dieses Speichers.
         * Steht ein Geraet in der Ausfallpause, altert dieser Wert, waehrend
         * seine Messwerte im Broker unveraendert stehen bleiben. */
        $paare[$vor . '/ts'] = (int) (isset($e['ok_ts']) ? $e['ok_ts'] : 0);
        /* Der Fehlertext im Klartext. Die Zahlen FEHLER und WARNUNG sind
         * Geraetebits; was der Abruf selbst gemeldet hat, stand bisher nur in
         * der Logdatei. bm_mqtt_senden() bereinigt Zeilenumbrueche. */
        $paare[$vor . '/fehlertext'] = isset($e['fehlertext']) ? $e['fehlertext'] : null;
        $soll = isset($e['sollwert']) ? (string) $e['sollwert'] : '';
        $paare[$vor . '/sollwert'] = $soll !== '' ? $soll : 'automatik';
        $paare[$vor . '/sollwert_alter'] = (int) (isset($e['sollwert_alter'])
            ? $e['sollwert_alter'] : -1);
        $paare[$vor . '/sollart'] = bm_sollart($soll);
        foreach ((array) (isset($e['module']) ? $e['module'] : array()) as $m => $md) {
            $mv = $vor . '/modul/' . (int) $m;
            $paare[$mv . '/uzmax'] = isset($md['uzmax']) ? $md['uzmax'] : null;
            $paare[$mv . '/uzmin'] = isset($md['uzmin']) ? $md['uzmin'] : null;
            $paare[$mv . '/uzdiff'] = isset($md['uzdiff']) ? $md['uzdiff'] : null;
            $paare[$mv . '/tmax'] = isset($md['tmax']) ? $md['tmax'] : null;
            foreach ((array) (isset($md['zellen']) ? $md['zellen'] : array()) as $z => $wert) {
                $paare[$mv . '/zelle/' . (int) $z] = $wert;
            }
        }
    }
    /* Der EVCC-Zweig. Dieselben Werte, eine Anschrift ohne Geraetenummer und
     * das Vorzeichen umgedreht - siehe bm_evcc_leistung(). Nur wenn
     * eingeschaltet: wer EVCC nicht benutzt, soll die Themen nicht im Broker
     * stehen haben. */
    if (!empty($cfg['evcc_ein'])) {
        $nr = max(1, (int) $cfg['evcc_geraet']);
        if (isset($geraete[$nr])) {
            $e = $geraete[$nr];
            $paare['evcc/soc']      = isset($e['SOC']) ? $e['SOC'] : null;
            $paare['evcc/capacity'] = isset($e['KAPAZ']) ? $e['KAPAZ'] : null;
            $paare['evcc/power']    = bm_evcc_leistung(isset($e['PBAT']) ? $e['PBAT'] : null);
            $soll = isset($e['sollwert']) ? (string) $e['sollwert'] : '';
            $paare['evcc/mode'] = (strpos($soll, 'sperren') === 0) ? 'hold'
                : ((strpos($soll, 'laden') === 0) ? 'charge' : 'normal');
        } elseif ($melden) {
            bm_log_gebremst('evcc_geraet_fehlt', 'EVCC: Speicher Nummer ' . $nr
                . ' ist eingestellt, aber nicht eingerichtet - es wird nichts '
                . 'unter evcc/ veroeffentlicht.');
        }
    }
    return $paare;
}

/** Alle Themen, die der Dienst veroeffentlicht, mit ihrer Bedeutung. */
function bm_mqtt_themen()
{
    return array(
        'ok'                       => 'BM_MQTT.OK',
        'geraete'                  => 'BM_MQTT.GERAETE',
        'ts'                       => 'BM_MQTT.TS',
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
        'geraetN/ts'               => 'BM_MQTT.GTS',
        'geraetN/fehlertext'       => 'BM_MQTT.FEHLERTEXT',
        'geraetN/sollwert'         => 'BM_MQTT.SOLLWERT',
        'geraetN/sollwert_alter'   => 'BM_MQTT.SOLLALTER',
        'geraetN/sollart'          => 'BM_MQTT.SOLLART',
        'geraetN/sollquelle'       => 'BM_MQTT.SOLLQUELLE',
        'geraetN/uzminmod'         => 'BM_MQTT.UZMINMOD',
        'geraetN/uzminzelle'       => 'BM_MQTT.UZMINZELLE',
        'geraetN/restkwh'          => 'BM_MQTT.RESTKWH',
        'geraetN/restzeit'         => 'BM_MQTT.RESTZEIT',
        'geraetN/alarm'            => 'BM_MQTT.ALARM',
        'geraetN/alarmtext'        => 'BM_MQTT.ALARMTEXT',
        'geraetN/modul/M/uzmax'    => 'BM_MQTT.M_UZMAX',
        'geraetN/modul/M/uzmin'    => 'BM_MQTT.M_UZMIN',
        'geraetN/modul/M/uzdiff'   => 'BM_MQTT.M_UZDIFF',
        'geraetN/modul/M/tmax'     => 'BM_MQTT.M_TMAX',
        'geraetN/modul/M/zelle/Z'  => 'BM_MQTT.M_ZELLE',
        // Nur wenn EVCC eingeschaltet ist. Eigener Zweig, weil EVCC genau
        // diese Groessen liest und das Vorzeichen ANDERS zaehlt - siehe
        // bm_evcc_leistung().
        'evcc/soc'                 => 'BM_MQTT.EVCC_SOC',
        'evcc/power'               => 'BM_MQTT.EVCC_POWER',
        'evcc/capacity'            => 'BM_MQTT.EVCC_CAPACITY',
        'evcc/mode'                => 'BM_MQTT.EVCC_MODE',
    );
}

/* ==================================================================
 * EVCC
 *
 * EVCC steuert das Laden von Elektroautos und will dabei wissen, was der
 * Hausspeicher gerade tut - und ihn waehrend einer Schnellladung anhalten
 * koennen, damit der Speicher nicht ins Auto entlaedt.
 *
 * DREI DINGE, DIE MAN VORHER WISSEN MUSS:
 *
 * 1. DAS VORZEICHEN IST GENAU ANDERSHERUM. Dieses Plugin fuehrt PBAT nach
 *    der Hausvorgabe "positiv = laden". EVCC zaehlt bei einem
 *    Batteriezaehler "negativ = laden, positiv = entladen". Wer PBAT
 *    unveraendert nach EVCC gibt, bekommt dort eine Anlage, die genau dann
 *    laedt, wenn sie entlaedt - und EVCC trifft daraufhin die falschen
 *    Entscheidungen, ohne dass irgendwo ein Fehler auftaucht.
 *
 *    Deshalb gibt es evcc/power: dasselbe wie pbat, nur mit umgedrehtem
 *    Vorzeichen. Wer lieber pbat nimmt, schreibt in der EVCC-Konfiguration
 *    "scale: -1" dazu. Beides ist richtig, eines von beiden ist Pflicht.
 *
 * 2. SOC, LEISTUNG UND KAPAZITAET GAB ES SCHON. Sie stehen seit jeher unter
 *    geraetN/soc, geraetN/pbat und geraetN/kapaz. Der Zweig evcc/ ist keine
 *    neue Messgroesse, sondern eine zweite Anschrift fuer dieselben Werte -
 *    an EINER Stelle, ohne Geraetenummer, im Vorzeichen von EVCC.
 *
 * 3. ANHALTEN IST NICHT ABSCHALTEN. EVCC kennt drei Betriebsarten:
 *      1 normal   der Speicher regelt selbst
 *      2 hold     nicht entladen (laden darf er)
 *      3 charge   aus dem Netz laden
 *
 *    "hold" wird hier als erzwungenes Entladen mit NULL WATT umgesetzt. Das
 *    ist kein Kunstgriff, sondern der einzige Weg, der ohne ein einziges
 *    neues Register auskommt: gefahren wird die Schrittfolge "entladen" des
 *    Profils mit @watt = 0. Ein Geraet, das erzwungenes Entladen kennt, kann
 *    auch null Watt entladen - und genau das heisst "nicht entladen".
 *    Erfundene Register waeren die Alternative gewesen; die gibt es hier
 *    nicht.
 *
 *    Die Totmannschaltung gilt unveraendert: bleibt das Lebenszeichen aus,
 *    faellt der Speicher nach der eingestellten Zeit in die Automatik
 *    zurueck. Ein Hausspeicher, der stehen bleibt, weil ein anderes Programm
 *    abgestuerzt ist, waere im Winter teuer.
 * ================================================================== */

/** Die Leistung in der Zaehlweise von EVCC: negativ = laden. */
function bm_evcc_leistung($pbat)
{
    if ($pbat === null || $pbat === '' || !is_numeric($pbat)) {
        return null;
    }
    $w = -(float) $pbat;
    // Die Null bekommt kein Vorzeichen: -0 laesst sich in JSON darstellen und
    // sieht in einem Protokoll aus wie ein Fehler.
    return $w == 0 ? 0 : $w;
}

/**
 * Die Werte, die EVCC von einem Batteriezaehler liest.
 *
 * Rueckgabe: array('soc','power','capacity','controllable','mode','ok','name')
 * Fehlende Groessen sind null - EVCC bekommt lieber nichts als eine erfundene 0.
 */
function bm_evcc_werte($nr = null)
{
    $cfg = bm_config();
    if ($nr === null) {
        $nr = (int) $cfg['evcc_geraet'];
    }
    $nr = max(1, (int) $nr);
    $alle = bm_werte();
    $aus = array('soc' => null, 'power' => null, 'capacity' => null,
                 'controllable' => 0, 'mode' => 'normal', 'ok' => 0, 'name' => '');
    if (!isset($alle[$nr])) {
        return $aus;
    }
    $g = $alle[$nr];
    $aus['ok']   = (int) $g['ok'];
    $aus['name'] = isset($g['name']) ? (string) $g['name'] : '';
    $aus['soc']      = (isset($g['SOC'])   && is_numeric($g['SOC']))   ? 0 + $g['SOC']   : null;
    $aus['capacity'] = (isset($g['KAPAZ']) && is_numeric($g['KAPAZ'])) ? 0 + $g['KAPAZ'] : null;
    $aus['power']    = bm_evcc_leistung(isset($g['PBAT']) ? $g['PBAT'] : null);

    /* Steuerbar ist der Speicher nur, wenn beide Haken stehen UND das Profil
     * ueberhaupt eine Schrittfolge fuers Entladen kennt. Ohne diese Pruefung
     * meldete das Plugin eine Steuerung, die es nicht gibt - EVCC schickte
     * dann Betriebsartwechsel, die nichts bewirken, und nichts davon waere
     * von aussen zu sehen. */
    $geraet = bm_geraet($nr);
    $profil = $geraet ? bm_profil($geraet['profil']) : null;
    $aus['controllable'] = (!empty($cfg['steuerung_ein'])
        && $geraet && !empty($geraet['schreiben'])
        && $profil && !empty($profil['steuerung']['entladen']['schritte'])) ? 1 : 0;

    $soll = isset($g['sollwert']) ? (string) $g['sollwert'] : '';
    if (strpos($soll, 'sperren') === 0) {
        $aus['mode'] = 'hold';
    } elseif (strpos($soll, 'laden') === 0) {
        $aus['mode'] = 'charge';
    }
    return $aus;
}

/**
 * Eine EVCC-Betriebsart in eine Aktion dieses Plugins uebersetzen.
 *
 * EVCC schickt je nach Anbindung die Zahl oder das Wort. Beides wird
 * angenommen; alles andere ergibt einen leeren Rueckgabewert und wird vom
 * Endpunkt abgewiesen - nicht auf "normal" zurechtgebogen. Ein
 * missverstandener Betriebsartwechsel waere schlimmer als ein abgelehnter.
 */
function bm_evcc_modus($eingabe)
{
    $tabelle = array(
        '1' => 'automatik', 'normal' => 'automatik', 'unknown' => 'automatik',
        '2' => 'sperren',   'hold'   => 'sperren',
        '3' => 'laden',     'charge' => 'laden',
    );
    $e = strtolower(trim((string) $eingabe));
    return isset($tabelle[$e]) ? $tabelle[$e] : '';
}

/* ==================================================================
 * Neu in 0.9.8
 * ================================================================== */

/**
 * Ab welchem Alter des Abbilds gilt der Dienst als stehengeblieben?
 *
 * EINE Quelle fuer zwei Verbraucher: der Waechter in bin/dienst.sh holt den
 * Wert ueber 'php -r' hier ab, der Reiter Test zeigt ihn an. Bis 0.9.7 stand
 * die Formel an beiden Stellen ausgeschrieben, jeweils mit einem Kommentar,
 * der auf die andere verwies - genau die Bauart, aus der Widersprueche
 * entstehen (Hausregel: Widersprueche in der eigenen Dokumentation sind
 * Fehlerquellen).
 *
 * Fuenffacher Takt, mindestens 180 s. Die Grenze muss deutlich ueber dem Takt
 * liegen, damit ein einzelner langsamer Durchlauf nichts ausloest.
 */
function bm_waechter_grenze($cfg = null)
{
    if (!is_array($cfg)) {
        $cfg = bm_config();
    }
    $takt = max(5, min(3600, (int) $cfg['intervall']));
    return max(180, $takt * 5);
}

/**
 * Restenergie und Restzeit.
 *
 * Rueckgabe: array(kWh, Minuten). Beides null, wo es sich nicht belegen
 * laesst - eine Reichweite, die niemand rechnen kann, wird nicht geraten.
 *
 * Die Kapazitaet kommt vom Geraet (KAPAZ), sonst aus der vom Anwender
 * eingetragenen Nennkapazitaet. Fehlt beides, gibt es keine Zahl. Unter 25 W
 * Leistung wird keine Restzeit gebildet: bei einem fast ruhenden Speicher
 * ergaeben sich Tausende von Stunden, und das sieht in Loxone aus wie ein
 * Messwert.
 */
function bm_restwerte(array $e, array $g)
{
    $kapaz = (isset($e['KAPAZ']) && is_numeric($e['KAPAZ']) && $e['KAPAZ'] > 0)
        ? (float) $e['KAPAZ']
        : ((isset($g['nennkapaz']) && $g['nennkapaz'] > 0) ? (float) $g['nennkapaz'] : 0.0);
    $soc = (isset($e['SOC']) && is_numeric($e['SOC'])) ? (float) $e['SOC'] : null;
    $p   = (isset($e['PBAT']) && is_numeric($e['PBAT'])) ? (float) $e['PBAT'] : null;
    if ($kapaz <= 0 || $soc === null) {
        return array(null, null);
    }
    $restkwh = round($kapaz * $soc / 100.0, 2);
    if ($p === null || abs($p) < 25) {
        return array($restkwh, null);
    }
    $offen = ($p > 0) ? ($kapaz * (100.0 - $soc) / 100.0) : $restkwh;
    $min = (int) round($offen * 1000.0 / abs($p) * 60.0);
    return array($restkwh, max(0, min(6000, $min)));
}

/**
 * Sammelmerker: liegt an diesem Speicher etwas an?
 *
 * Rueckgabe: Liste von Klartextgruenden, leer wenn nichts anliegt. Es wird
 * NUR gemeldet, was gemessen vorliegt - ein fehlender Wert erzeugt keinen
 * Alarm und auch keine Entwarnung.
 *
 * Die Schwellen fuer Drift und Temperatur stehen im Reiter Einstellungen.
 * Der Merker ersetzt die Bausteine #14 bis #19 in Loxone nicht, er ergaenzt
 * sie fuer alles, was ohne Loxone auskommen muss.
 */
function bm_alarm(array $e, ?array $cfg = null)
{
    if (!is_array($cfg)) {
        $cfg = bm_config();
    }
    $g = array();
    if (empty($e['ok'])) {
        $t = isset($e['fehlertext']) ? trim((string) $e['fehlertext']) : '';
        $g[] = $t !== '' ? $t : bm_t('ALARM.STUMM');
    }
    if (isset($e['UZDIFF']) && is_numeric($e['UZDIFF'])
        && $e['UZDIFF'] > max(1, (int) $cfg['drift_warnung'])) {
        $g[] = sprintf(bm_t('ALARM.DRIFT'), (int) $e['UZDIFF'], (int) $cfg['drift_warnung']);
    }
    if (isset($e['FEHLER']) && is_numeric($e['FEHLER']) && (int) $e['FEHLER'] > 0) {
        $g[] = sprintf(bm_t('ALARM.FEHLERBITS'), (int) $e['FEHLER']);
    }
    if (isset($e['WARNUNG']) && is_numeric($e['WARNUNG']) && (int) $e['WARNUNG'] > 0) {
        $g[] = sprintf(bm_t('ALARM.WARNBITS'), (int) $e['WARNUNG']);
    }
    if (isset($e['TMAX']) && is_numeric($e['TMAX']) && $e['TMAX'] > (int) $cfg['temp_max']) {
        $g[] = sprintf(bm_t('ALARM.WARM'), $e['TMAX'], (int) $cfg['temp_max']);
    }
    if (isset($e['TMIN']) && is_numeric($e['TMIN']) && $e['TMIN'] < (int) $cfg['temp_min']) {
        $g[] = sprintf(bm_t('ALARM.KALT'), $e['TMIN'], (int) $cfg['temp_min']);
    }
    return $g;
}

/* ---------------- Mitschnitt des Datenverkehrs ----------------
 *
 * Traegt ein Profil nicht, gab es bis 0.9.7 keinen Weg, die gesendeten und
 * empfangenen Bytes zu sehen - der Rohregister-Leser zeigt nur ausgewertete
 * Werte. Der Mitschnitt ist AB WERK AUS und laeuft immer nur eine begrenzte
 * Zeit; er endet von selbst, damit ihn niemand versehentlich stehen laesst.
 */
function bm_mitschnitt_datei()
{
    return bm_paths()['datadir'] . '/mitschnitt.log';
}

function bm_mitschnitt_laeuft($cfg = null)
{
    if (!is_array($cfg)) {
        $cfg = bm_config();
    }
    return ((int) $cfg['mitschnitt_bis'] > time()) ? ((int) $cfg['mitschnitt_bis'] - time()) : 0;
}

/** Mitschnitt fuer $sekunden einschalten. 0 schaltet ihn sofort ab. */
function bm_mitschnitt_schalten($sekunden)
{
    $cfg = bm_config();
    $sekunden = max(0, min(600, (int) $sekunden));
    $cfg['mitschnitt_bis'] = $sekunden > 0 ? (time() + $sekunden) : 0;
    if ($sekunden > 0) {
        @file_put_contents(bm_mitschnitt_datei(),
            '[' . date('Y-m-d H:i:s') . '] ' . sprintf(bm_t('MITSCHNITT.START'), $sekunden) . "
");
    }
    return bm_config_speichern($cfg);
}

/** Eine Zeile in den Mitschnitt. Tut nichts, solange er nicht laeuft. */
function bm_mitschnitt($richtung, $roh, array $g = array())
{
    static $an = null, $bis = 0;
    if ($an === null || time() > $bis) {
        $cfg = bm_config();
        $bis = (int) $cfg['mitschnitt_bis'];
        $an = ($bis > time());
    }
    if (!$an) {
        return;
    }
    $d = bm_mitschnitt_datei();
    // Harte Obergrenze: ein Mitschnitt darf die Ramdisk nicht vollschreiben.
    // Ohne diese Zeile oeffnet das Tor unten in einem langlebigen
    // Prozess nie: filesize() sieht die erste Groesse und danach keine
    // neue. Das clearstatcache UNTER der Sperre hilft dann nicht mehr,
    // weil es nie erreicht wird. Gemessen 29.08.2026 unter PHP 7.4.33.
    clearstatcache(true, $d);
    if (is_file($d) && filesize($d) > 262144) {
        return;
    }
    $ziel = isset($g['name']) ? $g['name'] : '';
    @file_put_contents($d, '[' . date('H:i:s') . '] ' . $richtung . ' ' . $ziel . ' '
        . strlen((string) $roh) . ' B  ' . strtoupper(bin2hex(substr((string) $roh, 0, 128)))
        . "
", FILE_APPEND | LOCK_EX);
}

/* ---------------- Modbus: Bits lesen (FC 1 und FC 2) ----------------
 *
 * Spulen und Eingaenge kommen bitweise statt wortweise: ein Byte je acht
 * Adressen, niedrigstes Bit zuerst. Bis 0.9.7 kannte das Plugin nur FC 3
 * und FC 4; ein Profil mit Statusbits war damit nicht abbildbar.
 */
function bm_bits_auspacken($daten, $start, $anzahl)
{
    $out = array();
    for ($i = 0; $i < $anzahl; $i++) {
        $byte = $i >> 3;
        if (!isset($daten[$byte])) {
            return array('_fehler' => 'Die Bitantwort ist zu kurz: Bit ' . $i . ' von '
                . $anzahl . ' liegt hinter dem Ende.');
        }
        $out[$start + $i] = (ord($daten[$byte]) >> ($i & 7)) & 1;
    }
    return $out;
}

function bm_bits_lesen($s, array $g, $fc, $start, $anzahl)
{
    $out = array();
    $offen = (int) $anzahl;
    $adr = (int) $start;
    while ($offen > 0) {
        $jetzt = min(2000, $offen);
        $antwort = bm_modbus_anfrage($s, $g, pack('Cnn', (int) $fc, $adr, $jetzt));
        if (is_array($antwort)) {
            return $antwort;
        }
        $bytes = strlen($antwort) > 0 ? ord($antwort[0]) : 0;
        $daten = substr($antwort, 1);
        $soll = intdiv($jetzt + 7, 8);
        if ($bytes !== $soll || strlen($daten) < $bytes) {
            return array('_fehler' => 'Die Bitantwort enthaelt ' . strlen($daten)
                . ' Datenbytes, erwartet waren ' . $soll . ' fuer ' . $jetzt . ' Bits ab '
                . 'Adresse ' . $adr . '.');
        }
        $teil = bm_bits_auspacken($daten, $adr, $jetzt);
        if (isset($teil['_fehler'])) {
            return $teil;
        }
        $out += $teil;
        $adr += $jetzt;
        $offen -= $jetzt;
    }
    return $out;
}

/**
 * Trockenlauf: was WUERDE ein Schaltbefehl tun?
 *
 * Es wird nichts gesendet und keine Verbindung geoeffnet. Ausgegeben werden
 * erst die Sperren, die greifen wuerden, dann die Schrittfolge des Profils
 * mit eingesetztem Wattwert.
 *
 * Vor dem ersten Zwangsbefehl an eine fremde Anlage ist das die Stufe VOR
 * 'mit kleiner Leistung anfangen und am Geraet nachsehen'.
 */
function bm_trockenlauf($nr, $aktion, $watt)
{
    $cfg = bm_config();
    $g = bm_geraet($nr);
    if ($g === null) {
        return array(0, sprintf(bm_t('DIENST.GERAET_UNBEKANNT'), (int) $nr));
    }
    $pr = bm_profil($g['profil']);
    if ($pr === null) {
        return array(0, sprintf(bm_t('DIENST.PROFIL_FEHLT'), $g['profil']));
    }
    $z = array();
    $z[] = sprintf(bm_t('TROCKEN.KOPF'), $g['name'], $g['profil'], $aktion, (int) $watt);
    $z[] = '';
    $z[] = bm_t('TROCKEN.SPERREN');
    $z[] = '  ' . (!empty($cfg['steuerung_ein']) ? '[OK]  ' : '[HALT]') . ' '
         . bm_t('TROCKEN.S_GLOBAL');
    $z[] = '  ' . (!empty($g['schreiben']) ? '[OK]  ' : '[HALT]') . ' '
         . bm_t('TROCKEN.S_GERAET');
    $grenze = ($aktion === 'laden') ? (int) $g['max_laden'] : (int) $g['max_entladen'];
    $z[] = '  ' . (($grenze > 0 && $watt > $grenze) ? '[HALT]' : '[OK]  ') . ' '
         . sprintf(bm_t('TROCKEN.S_GRENZE'), $grenze > 0 ? $grenze : 0);
    $werte = bm_werte();
    $soc = isset($werte[(int) $nr]['SOC']) ? $werte[(int) $nr]['SOC'] : null;
    if ($soc !== null) {
        $sperrt = ($aktion === 'laden' && $soc >= (int) $cfg['soc_max'])
               || ($aktion === 'entladen' && $soc <= (int) $cfg['soc_min']);
        $z[] = '  ' . ($sperrt ? '[HALT]' : '[OK]  ') . ' '
             . sprintf(bm_t('TROCKEN.S_FENSTER'), $soc, (int) $cfg['soc_min'], (int) $cfg['soc_max']);
    } else {
        $z[] = '  [?]   ' . bm_t('TROCKEN.S_FENSTER_UNBEKANNT');
    }
    if ($g['transport'] === 'pylontech_rs485') {
        $z[] = '  [HALT] ' . bm_t('DIENST.STEUERUNG_SERIELL');
    }
    $z[] = '';
    $st = isset($pr['steuerung']) && is_array($pr['steuerung']) ? $pr['steuerung'] : array();
    $wirk = $aktion;
    $wwatt = (int) $watt;
    if ($aktion === 'sperren' && !isset($st['sperren']['schritte'])) {
        $wirk = 'entladen';
        $wwatt = 0;
        $z[] = bm_t('TROCKEN.SPERREN_ERSATZ');
    }
    if (!isset($st[$wirk]['schritte']) || !is_array($st[$wirk]['schritte'])) {
        $z[] = sprintf(bm_t('DIENST.STEUERUNG_UNBEKANNT'), $aktion, $pr['name']);
        return array(0, implode("
", $z));
    }
    $z[] = sprintf(bm_t('TROCKEN.SCHRITTE'), count($st[$wirk]['schritte']));
    $n = 0;
    foreach ($st[$wirk]['schritte'] as $schritt) {
        $n++;
        $w = ($schritt['wert'] === '@watt') ? $wwatt : (int) $schritt['wert'];
        $typ = isset($schritt['typ']) ? $schritt['typ'] : 'u16';
        if ($typ === 'u32' || $typ === 's32') {
            $z[] = sprintf('  %d. FC 16  Register %5d (0x%04X)  %s = %d  ->  0x%04X 0x%04X',
                $n, (int) $schritt['reg'], (int) $schritt['reg'], $typ, $w,
                ($w >> 16) & 0xFFFF, $w & 0xFFFF);
        } else {
            $z[] = sprintf('  %d. FC 6   Register %5d (0x%04X)  %s = %d  ->  0x%04X',
                $n, (int) $schritt['reg'], (int) $schritt['reg'], $typ, $w, $w & 0xFFFF);
        }
    }
    $z[] = '';
    $z[] = bm_t('TROCKEN.FUSS');
    if (isset($pr['steuerung_stand']) && $pr['steuerung_stand'] !== 'dokumentiert') {
        $z[] = bm_t('TROCKEN.UNGEPRUEFT');
    }
    return array(1, implode("
", $z));
}

/**
 * Den eigenen Endpunkt WIRKLICH aufrufen.
 *
 * Alle uebrigen Pruefungen sehen sich Dateien an. Diese eine spricht die
 * Stelle an, die spaeter der Miniserver anspricht - und nur sie findet die
 * Fehlerklasse, bei der html/ und htmlauth/ installiert in getrennten
 * Baeumen liegen. Der Endpunkt gibt dann HTTP 500 aus, und niemand merkt es,
 * weil ihn sonst nur der Miniserver aufruft und der kein Protokoll liest.
 *
 * Rueckgabe: array(stand, code, text, url).
 *   stand  1 = geantwortet und plausibel
 *          0 = geantwortet, aber falsch
 *         -1 = NICHT FESTSTELLBAR (weder curl noch allow_url_fopen)
 *
 * Der dritte Ausgang ist Pflicht: 'ich kann es nicht messen' darf nicht wie
 * 'es ist in Ordnung' aussehen.
 */
function bm_selbsttest_endpunkt($aktion = 'status')
{
    $p = bm_paths();
    $url = 'http://127.0.0.1/plugins/' . $p['plugin'] . '/index.php?token='
         . rawurlencode(bm_token()) . '&aktion=' . rawurlencode($aktion);
    $text = '';
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        $text = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fehler = curl_error($ch);
        curl_close($ch);
        if ($code === 0) {
            return array(-1, 0, $fehler !== '' ? $fehler : bm_t('TEST.A_EP_KEINE_ANTWORT'), $url);
        }
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(array('http' => array('timeout' => 8,
            'ignore_errors' => true)));
        $text = (string) @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0])
            && preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        if ($code === 0) {
            return array(-1, 0, bm_t('TEST.A_EP_KEINE_ANTWORT'), $url);
        }
    } else {
        return array(-1, 0, bm_t('TEST.A_EP_NICHT_MESSBAR'), $url);
    }
    $erste = trim(strtok($text, "
"));
    $gut = ($code === 200) && (strpos($erste, 'BMS;') === 0 || strpos($erste, 'LISTE;') === 0
                               || strpos($erste, 'SUMME;') === 0);
    return array($gut ? 1 : 0, $code, $erste !== '' ? $erste : bm_t('TEST.A_EP_LEER'), $url);
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

/**
 * Beruht die Feldauswahl fuer diesen Speicher auf einer MESSUNG?
 *
 * false heisst: das Geraet hat noch nie erfolgreich geantwortet. Dann laesst
 * sich nur aufzaehlen, was das Profil erklaert - und das ist bei den Profilen,
 * deren Werte der Code bildet (Pylontech), gar nichts. In diesem Fall wird
 * bewusst ALLES ausgeliefert und der Anwender darauf hingewiesen, die Vorlage
 * nach dem ersten erfolgreichen Abruf erneut zu holen.
 */
function bm_felder_gemessen($nummer)
{
    $werte = bm_werte();
    $nummer = (int) $nummer;
    return (isset($werte[$nummer]) && !empty($werte[$nummer]['ok'])) ? true : false;
}

/**
 * Welche Messgroessen dieser Speicher tatsaechlich liefert.
 *
 * Bis 0.9.6 legte die Importdatei fuer Loxone ALLE 21 Messgroessen als
 * virtuelle Eingaenge an - auch die, die kein Profil und kein Codepfad je
 * fuellt. Ausgezaehlt ueber alle eingebauten Profile waren das WARNUNG,
 * LADENMAX und ENTLMAX; bei einem Huawei-Speicher standen sogar 15 von 19
 * Eingaengen dauerhaft leer. Loxone traegt fuer einen virtuellen Eingang ohne
 * Wert die DefVal 0 ein - und eine 0 sieht aus wie ein Messwert. Genau die
 * stille Falschaussage, die dieses Plugin sonst ueberall vermeidet, indem es
 * einen Strich statt einer 0 sendet.
 *
 * Gebildet wird die Menge aus ZWEI Quellen, und das mit Absicht:
 *
 *   - was das Profil an Registern erklaert (felder, rechnung),
 *   - was in der letzten erfolgreichen Messung wirklich einen Wert trug.
 *
 * Die zweite Quelle ist noetig, weil etliche Groessen kein Profil erklaert,
 * sondern der Code bildet: saemtliche Pylontech-Werte, die aus den Moduldaten
 * abgeleitete Zelldrift, Zell- und Modulzahl, der aus der Nennkapazitaet
 * gerechnete Gesundheitszustand.
 *
 * Eine von Hand gepflegte Liste waere eine dritte Stelle, die mit dem Code
 * auseinanderlaeuft. Deshalb wird gemessen statt erklaert.
 *
 * ALTER und OK sind immer dabei - die bildet der Endpunkt selbst.
 */
function bm_felder_geliefert($nummer)
{
    $felder = bm_status_felder();
    $nummer = (int) $nummer;
    if (!bm_felder_gemessen($nummer)) {
        return $felder;
    }
    $hat = array('ALTER' => 1, 'OK' => 1);
    $g = bm_geraet($nummer);
    $pr = ($g !== null) ? bm_profil($g['profil']) : null;
    if ($pr !== null) {
        foreach (array('felder', 'rechnung') as $abschnitt) {
            $teil = isset($pr[$abschnitt]) && is_array($pr[$abschnitt])
                ? $pr[$abschnitt] : array();
            foreach ($teil as $feld => $unbenutzt) {
                if (isset($felder[$feld])) {
                    $hat[$feld] = 1;
                }
            }
        }
    }
    $werte = bm_werte();
    foreach ($felder as $feld => $unbenutzt) {
        if (isset($werte[$nummer][$feld]) && $werte[$nummer][$feld] !== null) {
            $hat[$feld] = 1;
        }
    }
    // Die Reihenfolge aus bm_status_felder() bleibt massgeblich - siehe den
    // Hinweis zur Suchreihenfolge in webfrontend/html/index.php.
    $aus = array();
    foreach ($felder as $feld => $info) {
        if (isset($hat[$feld])) {
            $aus[$feld] = $info;
        }
    }
    return $aus;
}

/**
 * Der Suchtext einer Befehlserkennung - die EINE Quelle.
 *
 * Loxone nimmt bei einem virtuellen HTTP-Eingang die ERSTE Fundstelle des
 * Suchtextes in der Zeile. Ohne fuehrendes Trennzeichen trifft \iKM=\i\v
 * deshalb auch INSPKM= - genau das ist am 20.08.2026 an Volkswagen ID und
 * Skoda Connect aufgetreten: der Kilometerstand las die Inspektionsvorgabe,
 * 15000 statt 48210. Beide Zahlen sehen aus wie ein Kilometerstand, und der
 * Fehler meldet sich nie.
 *
 * In diesem Plugin gibt es dasselbe Paar: ALTER ist Endstueck von SOLLALTER.
 * Bisher hat allein die REIHENFOLGE in der Statuszeile gerettet, dass der
 * Baustein den richtigen Wert bekam - ALTER steht vor SOLLALTER. Das ist eine
 * Zusicherung, die beim naechsten neuen Feld unbemerkt faellt.
 *
 * Mit dem Semikolon davor ist die Frage strukturell erledigt: jedes Feld der
 * Statuszeile steht hinter einem ';', denn die Zeile beginnt mit 'BMS;' und
 * trennt mit ';'. Bestehende Loxone-Projekte laufen unveraendert weiter - die
 * ausgegebene Zeile aendert sich nicht, nur der empfohlene Suchtext.
 */
function bm_check($feld)
{
    return '\i;' . $feld . '=\i\v';
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
    foreach (bm_felder_geliefert($nummer) as $feld => $info) {
        // Der Text laeuft gleich durch bm_x() und wuerde dort ein zweites Mal
        // maskiert. Deshalb erst Auszeichnung entfernen und Entitaeten
        // aufloesen - sonst stuende in Loxone Config wortwoertlich 'l&auml;dt'.
        $bedeutung = trim(strip_tags(html_entity_decode(bm_t($info[1]), ENT_QUOTES, 'UTF-8')));
        $cmds[] = array(
            'title'   => 'BMS_' . (int) $nummer . '_' . $feld,
            'comment' => $bedeutung . ($info[0] !== '' ? ' [' . $info[0] . ']' : ''),
            'check'   => bm_check($feld),
            'min'     => (string) $info[2],
            'max'     => (string) $info[3],
        );
    }
    /* Der Zwangszustand gehoert in die Importdatei.
     *
     * Der Endpunkt sendet SOLL, SOLLALTER und SOLLART seit jeher mit der
     * Begruendung, Loxone muesse wissen, ob ein Zwang laeuft - in die Vorlage
     * kamen sie bis 0.9.6 nicht, weil die Schleife nur ueber
     * bm_status_felder() lief. Wer die Vorlage benutzte, hatte den
     * Zwangszustand also gerade nicht.
     *
     * SOLL selbst bleibt draussen: das ist ein Text ('laden:500') und taugt
     * nicht als Analogwert. Dafuer gibt es SOLLART als Zahl.
     *
     * Beide stehen am ENDE der Statuszeile. Neue Felder werden immer hinten
     * angehaengt - das bleibt so, weil bestehende Projekte sonst an anderer
     * Stelle suchen muessten.
     *
     * Bis 0.9.10 war die Reihenfolge darueber hinaus die einzige Rettung:
     * \iALTER=\i\v steckt auch in SOLLALTER=, und nur weil ALTER vorher
     * steht, fand der Baustein den richtigen Wert. Seit bm_check() das
     * Trennzeichen setzt, traegt die Reihenfolge diese Last nicht mehr. */
    $cmds[] = array(
        'title'   => 'BMS_' . (int) $nummer . '_SOLLART',
        'comment' => trim(strip_tags(html_entity_decode(bm_t('BM_FELD.SOLLART'),
                          ENT_QUOTES, 'UTF-8'))),
        'check'   => bm_check('SOLLART'),
        'min'     => '0',
        'max'     => '3',
    );
    $cmds[] = array(
        'title'   => 'BMS_' . (int) $nummer . '_SOLLALTER',
        'comment' => trim(strip_tags(html_entity_decode(bm_t('BM_FELD.SOLLALTER'),
                          ENT_QUOTES, 'UTF-8'))) . ' [s]',
        'check'   => bm_check('SOLLALTER'),
        'min'     => '-1',
        'max'     => '86400',
    );
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
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
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


/* ==================================================================
 * WACHPOSTEN GEGEN FREMDE FORMULARE
 * ==================================================================
 *
 * htmlauth/ schuetzt gegen den UNANGEMELDETEN Aufruf. Es schuetzt nicht
 * dagegen, dass der Browser eines angemeldeten Bedieners ein Formular
 * abschickt, das auf einer fremden Seite steht - die Anmeldung schickt er
 * automatisch mit.
 *
 * Gemessen an Schwesterlinien (Skoda Connect 0.9.12, Midea 4.2.12, beide
 * am 27.08.2026): ein einziger fremder POST genuegte, um das Aktionstoken
 * neu zu wuerfeln. Danach beantwortet der Endpunkt jeden Virtuellen Eingang
 * mit 403 - und ein Virtueller Eingang wertet die Antwort NICHT aus. Der
 * Ausfall bleibt still.
 *
 * Der leere Fall wird eigens abgefangen: hash_equals('', '') ist in PHP
 * TRUE. Wer das Feld nicht vor dem Vergleich auf leer prueft, hat einen
 * Posten gebaut, den jeder passiert, der das Feld leer laesst.
 *
 * Das Merkmal wird aus $_POST und $_GET gelesen, nie aus $_REQUEST:
 * $_REQUEST enthaelt je nach variables_order auch Cookies.
 * ================================================================== */

function bm_merkwort()
{
    static $wort = null;
    if ($wort !== null) {
        return $wort;
    }
    $pfade = bm_paths();
    $verz  = isset($pfade['datadir']) ? $pfade['datadir'] : '';
    if ($verz === '') {
        return '';
    }
    $datei = $verz . '/formmerkwort';
    if (is_readable($datei)) {
        $roh = trim((string) @file_get_contents($datei));
        if (preg_match('/^[0-9a-f]{32,64}$/', $roh)) {
            $wort = $roh;
            return $wort;
        }
    }
    if (function_exists('random_bytes')) {
        $neu = bin2hex(random_bytes(24));
    } else {
        $neu = substr(hash('sha256', uniqid((string) mt_rand(), true) . microtime(true)), 0, 48);
    }
    if (!is_dir($verz)) {
        @mkdir($verz, 0775, true);
    }
    /* Rechte VOR dem Inhalt: zwischen Anlegen und chmod laege sonst ein
     * Fenster, in dem das Merkwort fuer alle lesbar ist. */
    $tmp = $datei . '.tmp';
    // Leer anlegen, schuetzen, dann fuellen - so, wie der Kommentar darueber
    // es verlangt (B33).
    $fh = @fopen($tmp, 'c');
    if ($fh !== false) {
        fclose($fh);
        @chmod($tmp, 0600);
    }
    if (@file_put_contents($tmp, $neu) !== false) {
        @chmod($tmp, 0600);
        if (@rename($tmp, $datei)) {
            @chmod($datei, 0600);
        } else {
            @unlink($tmp);
        }
    }
    $wort = $neu;
    return $wort;
}

function bm_formtoken()
{
    $grund = bm_merkwort();
    return $grund === '' ? '' : hash_hmac('sha256', 'formular-v1', $grund);
}

/* Das versteckte Feld. Bewusst OHNE den Escape-Helfer des Plugins: der
 * steht bei einigen Linien in index.php und waere von hier aus nicht da.
 * Der Wert ist hexadezimal. */
function bm_fmt()
{
    return '<input data-role="none" type="hidden" name="fmt" value="'
         . htmlspecialchars(bm_formtoken(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Rueckgabe: '' wenn die Anfrage durchgelassen wird, sonst der Grund. */
function bm_wachposten()
{
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return '';
    }
    $soll = bm_formtoken();
    $ist = isset($_POST['fmt']) ? $_POST['fmt']
         : (isset($_GET['fmt']) ? $_GET['fmt'] : null);
    if (!is_string($ist) || $ist === '' || $soll === '') {
        return bm_t('WACHE.FEHLT');
    }
    if (!hash_equals($soll, $ist)) {
        return bm_t('WACHE.FALSCH');
    }
    return '';
}
