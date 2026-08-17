<?php
/**
 * Batterie-Heimspeicher (BMS) - Abrufdienst
 *
 * Aufrufe:
 *   bms_dienst.php                Daemon, laeuft bis SIGTERM
 *   bms_dienst.php --einmal       ein Durchlauf, dann Ende
 *   bms_dienst.php --selbsttest   Pruefungen ohne Geraet, Klartextausgabe
 *
 * Der Dienst ist die EINZIGE Stelle, die mit einem Speicher spricht. Weder die
 * Oberflaeche noch der Miniserver-Endpunkt oeffnen selbst eine Verbindung.
 * Das ist nicht nur Sauberkeit: mehrere Speicher lassen ueberhaupt nur eine
 * Verbindung gleichzeitig zu - eine zweite wuerde die erste verdraengen.
 *
 * Er protokolliert NICHT nach stdout. Das Startskript leitet stdout ohnehin in
 * die Logdatei um; ein zweiter Kanal schriebe jede Zeile doppelt.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Bibliothek einbinden - installiert unter <home>/webfrontend/html/plugins/…,
 * im Archiv unter ../webfrontend/html/. */
$bm_gefunden = false;
foreach (array(
    dirname(__DIR__) . '/webfrontend/html/bm_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/bm_lib.php',
    dirname(dirname(__DIR__)) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/bm_lib.php',
) as $bm_kandidat) {
    if (is_file($bm_kandidat)) {
        require_once $bm_kandidat;
        $bm_gefunden = true;
        break;
    }
}
if (!$bm_gefunden) {
    fwrite(STDERR, "bm_lib.php nicht gefunden - Plugin neu installieren.\n");
    exit(1);
}

$bm_laeuft = true;

/* ==================================================================
 * Signale
 *
 * pcntl_async_signals(true) statt pcntl_signal_dispatch() in der Schleife.
 *
 * Warum das mehr als Geschmack ist: bis 0.9.0 wurde genau EINMAL je
 * Schleifendurchlauf nach Signalen gesehen. Ein Durchlauf kann aber lange
 * dauern - bm_zellen_byd() wartet bis zu vier Sekunden auf die BCU, jeder
 * Verbindungsaufbau bis zur eingestellten Zeitueberschreitung, und das mal
 * der Zahl der Speicher. In dieser ganzen Zeit blieb ein SIGTERM liegen.
 *
 * Mit asynchronen Signalen ruft PHP den Behandler auf, sobald die virtuelle
 * Maschine wieder an der Reihe ist - auch aus usleep() und aus einem
 * blockierenden Lesevorgang heraus. Gibt es seit PHP 7.1; LB_MINIMUM 3.0.0
 * bedeutet mindestens PHP 7.3. Die Abfrage auf function_exists bleibt
 * trotzdem stehen, damit die Datei auch ohne pcntl einbindbar ist.
 *
 * Der zweite Teil des Problems lag nicht hier, sondern in preupgrade.sh:
 * das schickte SIGTERM, wartete zwei Sekunden und schoss dann mit kill -9
 * nach. Zwei Sekunden reichten bei einem Durchlauf mit Zelldaten nie. Das
 * Skript ruft jetzt dienst.sh stop auf, das zehn Sekunden Zeit laesst.
 * ================================================================== */

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
}

function bm_signal_behandeln($signal)
{
    global $bm_laeuft;
    if (!$bm_laeuft) {
        return;    // schon im Anhalten - ein zweites Signal aendert nichts
    }
    $bm_laeuft = false;
    bm_log('Signal ' . (int) $signal . ' empfangen - der Dienst haelt an.');
}

/* ==================================================================
 * Abruf eines Geraets
 * ================================================================== */

/**
 * Modbus-Geraet auslesen.
 * Rueckgabe: array('werte' => …, 'regs' => …) oder array('_fehler' => Text)
 */
function bm_abruf_modbus(array $g, array $pr, $tmo)
{
    // Zwei Anlaeufe: der erste darf eine vorgehaltene Verbindung benutzen,
    // der zweite baut in jedem Fall eine frische auf. Eine offene Verbindung
    // kann unbemerkt tot sein - das Geraet hat neu gestartet, eine Firewall
    // hat den Eintrag abgeraeumt. Erst wenn auch die frische Verbindung nicht
    // traegt, ist es ein Fehler.
    $letzter = array('_fehler' => 'Unbekannt.');
    for ($versuch = 1; $versuch <= 2; $versuch++) {
        $s = bm_verbindung_holen($g, $tmo, true);
        if (is_array($s)) {
            bm_verbindung_verwerfen($g);
            $letzter = $s;
            continue;
        }
        $regs = array();
        $fehler = '';
        foreach ((array) $pr['bloecke'] as $block) {
            $bfc = (int) $block['fc'];
            // FC 1 und 2 liefern Bits, FC 3 und 4 Woerter. Beide landen in
            // derselben Adresstabelle; das ist bequem, aber die Adressraeume
            // sind verschieden. Eine Ueberschneidung wird deshalb GEMELDET
            // statt stillschweigend verschluckt - sonst liest ein Feld den
            // Wert eines ganz anderen Registers.
            $teil = ($bfc === 1 || $bfc === 2)
                ? bm_bits_lesen($s, $g, $bfc, (int) $block['start'], (int) $block['anzahl'])
                : bm_register_lesen($s, $g, $bfc, (int) $block['start'], (int) $block['anzahl']);
            if (isset($teil['_fehler'])) {
                $fehler = 'Block ab Register ' . (int) $block['start'] . ': ' . $teil['_fehler'];
                break;
            }
            $doppelt = array_intersect_key($teil, $regs);
            if ($doppelt) {
                $fehler = 'Zwei Bloecke dieses Profils belegen dieselben Adressen ('
                    . implode(', ', array_slice(array_keys($doppelt), 0, 5))
                    . '). Bits (FC 1/2) und Register (FC 3/4) haben getrennte Adressraeume - '
                    . 'im Profil einen der beiden Bloecke versetzen.';
                break;
            }
            $regs += $teil;
        }
        if ($fehler !== '') {
            // Nach JEDEM Fehler die Verbindung wegwerfen: nach einer
            // abgebrochenen Anforderung steht womoeglich noch eine halbe
            // Antwort im Puffer, die sonst als Antwort auf die naechste Frage
            // gelesen wuerde.
            bm_verbindung_verwerfen($g);
            $letzter = array('_fehler' => $fehler);
            continue;
        }
        return bm_modbus_auswerten($regs, $pr);
    }
    return $letzter;
}

/** Rohregister in die einheitlichen Messgroessen umrechnen. */
function bm_modbus_auswerten(array $regs, array $pr)
{
    $werte = array();
    foreach (bm_status_felder() as $feld => $unbenutzt) {
        $werte[$feld] = null;
    }
    foreach ((array) $pr['felder'] as $feld => $beschreibung) {
        if (!isset($werte[$feld])) {
            continue;   // ein Profil darf keine eigenen Messgroessen erfinden
        }
        $werte[$feld] = bm_wert_aus($regs, $beschreibung);
    }
    foreach ((array) $pr['rechnung'] as $feld => $r) {
        if (!isset($werte[$feld]) || !isset($r['art']) || $r['art'] !== 'produkt') {
            continue;
        }
        $a = bm_wert_aus($regs, array('reg' => $r['a'], 'typ' => $r['a_typ'],
                                      'faktor' => $r['a_faktor'], 'nk' => 4));
        $b = bm_wert_aus($regs, array('reg' => $r['b'], 'typ' => $r['b_typ'],
                                      'faktor' => $r['b_faktor'], 'nk' => 4));
        if ($a !== null && $b !== null) {
            $werte[$feld] = round($a * $b, isset($r['nk']) ? (int) $r['nk'] : 0);
        }
    }
    return array('werte' => $werte, 'regs' => $regs);
}

/**
 * Zelldaten der BYD-BCU holen.
 *
 * Ablauf laut Quelle: [BMS-Index, 0x8100] nach 0x0550 schreiben, dann 0x0551
 * abfragen, bis dort 0x8801 steht, danach fuenfmal 0x41 Register ab 0x0558
 * lesen und jeweils das erste Wort (die Paketlaenge) verwerfen.
 */
function bm_zellen_byd(array $g, array $pr, $tmo, $bmsIndex = 1)
{
    $z = $pr['zellen'];
    // Dieselbe Verbindung wie beim Statusabruf. Bis 0.9.0 schloss
    // bm_abruf_modbus() die Verbindung und diese Funktion oeffnete
    // unmittelbar danach eine neue - bei der BYD-BCU, die nur EINE
    // Verbindung gleichzeitig zulaesst, war das die haeufigste Ursache fuer
    // 'Verbindung abgewiesen' im Protokoll.
    $s = bm_verbindung_holen($g, $tmo, true);
    if (is_array($s)) {
        bm_verbindung_verwerfen($g);
        return $s;
    }
    $erg = bm_register_schreiben_mehrere($s, $g, (int) $z['anforderung'],
                                         array($bmsIndex, 0x8100));
    if (is_array($erg)) {
        bm_verbindung_verwerfen($g);
        return array('_fehler' => 'Die Zelldatenanforderung liess sich nicht absetzen: '
            . $erg['_fehler']);
    }
    $bereit = false;
    for ($i = 0; $i < 40; $i++) {          // hoechstens 4 Sekunden warten
        usleep(100000);
        $a = bm_register_lesen($s, $g, 3, (int) $z['antwort'], 1);
        if (isset($a['_fehler'])) {
            bm_verbindung_verwerfen($g);
            return array('_fehler' => 'Warten auf die Zelldaten: ' . $a['_fehler']);
        }
        if ((int) $a[(int) $z['antwort']] === 0x8801) {
            $bereit = true;
            break;
        }
    }
    if (!$bereit) {
        return array('_fehler' => 'Die BCU hat die Zelldatenanforderung nicht innerhalb von '
            . '4 s bestaetigt. Das passiert regelmaessig, wenn eine zweite Anwendung '
            . '(BE Connect Plus, Node-RED) gerade mit der Box spricht.');
    }
    $daten = array();
    for ($b = 0; $b < (int) $z['bloecke']; $b++) {
        $teil = bm_register_lesen($s, $g, 3, (int) $z['daten'], (int) $z['blockgroesse']);
        if (isset($teil['_fehler'])) {
            bm_verbindung_verwerfen($g);
            return array('_fehler' => 'Zelldatenblock ' . ($b + 1) . ': ' . $teil['_fehler']);
        }
        $folge = array_values($teil);
        array_shift($folge);               // erstes Wort ist die Paketlaenge
        $daten = array_merge($daten, $folge);
    }

    $module = array();
    $jeModul = (int) $z['zellen_je_modul'];
    $ab = (int) $z['zellen_ab'];
    $tab = (int) $z['temp_ab'];
    $tje = (int) $z['temp_je_modul'];
    $modulzahl = 0;
    // Wie viele Module tatsaechlich bestueckt sind, sagt Register 0x0010;
    // hier wird nur gelesen, was auch angekommen ist.
    while (isset($daten[$ab + $modulzahl * $jeModul + $jeModul - 1]) && $modulzahl < 8) {
        $modulzahl++;
    }
    for ($m = 0; $m < $modulzahl; $m++) {
        $spannungen = array();
        for ($i = 0; $i < $jeModul; $i++) {
            $w = isset($daten[$ab + $m * $jeModul + $i]) ? (int) $daten[$ab + $m * $jeModul + $i] : null;
            // 0 mV ist keine gueltige Zellspannung, sondern ein leerer Platz.
            if ($w !== null && $w > 0) {
                $spannungen[$i + 1] = $w;
            }
        }
        if (!$spannungen) {
            continue;
        }
        $temps = array();
        for ($i = 0; $i < $tje; $i++) {
            if (!isset($daten[$tab + $m * $tje + $i])) {
                continue;
            }
            $wort = (int) $daten[$tab + $m * $tje + $i];
            $temps[] = ($wort >> 8) & 0xFF;
            $temps[] = $wort & 0xFF;
        }
        $module[$m + 1] = array(
            'zellen' => $spannungen,
            'uzmax'  => max($spannungen),
            'uzmin'  => min($spannungen),
            'uzdiff' => max($spannungen) - min($spannungen),
            'tmax'   => $temps ? max($temps) : null,
            'tmin'   => $temps ? min($temps) : null,
        );
    }
    return array('module' => $module);
}

/* ==================================================================
 * Bytes aus dem Pylontech-Datenstrom lesen
 *
 * Diese vier Funktionen lesen NIE ueber das Ende hinaus. Das ist der Punkt,
 * an dem die Fassung 0.9.0 nachlaessig war.
 *
 * Was dort geschah: die Zahl der Temperaturfuehler kommt als einzelnes Byte
 * aus dem Datenstrom. Faengt die RS485-Leitung ein Stoerbyte 0xFF ein, sind
 * das 255 Fuehler, und die anschliessende Schleife las 510 Bytes weit ueber
 * das Ende der Antwort hinaus.
 *
 * ANDERS ALS OFT BEHAUPTET stuerzt PHP dabei nicht ab. Ein Lesezugriff
 * hinter dem Ende einer Zeichenkette ist seit PHP 8 eine WARNUNG
 * ('Uninitialized string offset'), keine Ausnahme; der Ausdruck ergibt eine
 * leere Zeichenkette, und ord('') ist 0. Der Dienst lief also weiter - und
 * genau das ist das Schlimmere. Er lief weiter mit 255 erfundenen
 * Temperaturen, einem um 510 Bytes verschobenen Lesezeiger und daraus
 * abgeleiteten Werten fuer Strom, Spannung, Kapazitaet und Zyklen, die er
 * anschliessend als gueltig an Loxone und MQTT weiterreichte. Dazu 510
 * Warnungen je Durchlauf in der Logdatei.
 *
 * Eine Pruefung nur vor der Temperaturschleife - so lautete der Vorschlag -
 * genuegt dafuer nicht: hinter den Temperaturen folgen elf weitere Bytes
 * (Strom, Spannung, Restkapazitaet, Feldzahl, Gesamtkapazitaet, Zyklen), die
 * ebenso ungeprueft gelesen wurden, und das Byte mit der Fuehlerzahl selbst
 * war es auch. Deshalb pruefen jetzt die Lesefunktionen, und zwar jede fuer
 * sich.
 *
 * $pos wird bei einem Fehlgriff auf -1 gesetzt. Das ist die Merkfaehnchen:
 * bm_pyl_ende() fragt sie ab, und der Aufrufer bricht mit einer Meldung ab,
 * statt mit erfundenen Zahlen weiterzurechnen.
 * ================================================================== */

/** Wurde ueber das Ende hinaus gelesen? */
function bm_pyl_ende($pos)
{
    return $pos < 0;
}

/** Ein Byte. Bei Ueberlauf: 0 und $pos = -1. */
function bm_u8(&$roh, &$pos)
{
    if ($pos < 0 || $pos + 1 > strlen($roh)) {
        $pos = -1;
        return 0;
    }
    $w = ord($roh[$pos]);
    $pos += 1;
    return $w;
}

/** Vorzeichenbehaftete 16-Bit-Zahl aus einem Bytestrom. */
function bm_s16(&$roh, &$pos)
{
    if ($pos < 0 || $pos + 2 > strlen($roh)) {
        $pos = -1;
        return 0;
    }
    $w = (ord($roh[$pos]) << 8) | ord($roh[$pos + 1]);
    $pos += 2;
    return $w >= 0x8000 ? $w - 0x10000 : $w;
}

function bm_u16(&$roh, &$pos)
{
    if ($pos < 0 || $pos + 2 > strlen($roh)) {
        $pos = -1;
        return 0;
    }
    $w = (ord($roh[$pos]) << 8) | ord($roh[$pos + 1]);
    $pos += 2;
    return $w;
}

function bm_u24(&$roh, &$pos)
{
    if ($pos < 0 || $pos + 3 > strlen($roh)) {
        $pos = -1;
        return 0;
    }
    $w = (ord($roh[$pos]) << 16) | (ord($roh[$pos + 1]) << 8) | ord($roh[$pos + 2]);
    $pos += 3;
    return $w;
}

/**
 * Pylontech: Analogwerte aller Module (CID2 0x42, Info 'FF').
 *
 * Aufbau des Datenfeldes nach dem uebersprungenen Kennbyte:
 *   Modulzahl(1)
 *   je Modul: Zellzahl(1), Zellspannungen(n x s16, mV),
 *             Temperaturzahl(1), mittlere BMS-Temperatur(s16, 0.1 K),
 *             weitere Temperaturen((n-1) x s16, 0.1 K),
 *             Strom(s16, 0.1 A), Spannung(u16, mV),
 *             Restkapazitaet(u16, mAh), benutzerdefinierte Felder(1),
 *             Gesamtkapazitaet(u16, mAh), Zyklen(u16),
 *             falls benutzerdefinierte Felder > 2:
 *               Restkapazitaet(u24, mAh), Gesamtkapazitaet(u24, mAh)
 *
 * Temperaturen stehen in Kelvin mal 10: Grad Celsius = (Wert - 2731) / 10.
 */
/** Einheitliche Meldung, wenn die Antwort mitten in einem Feld endet. */
function bm_pyl_kurz($modul, $stelle, $roh)
{
    return 'Die Antwort endet bei Modul ' . (int) $modul . ' mitten in ' . $stelle
         . ' (' . strlen($roh) . ' Bytes insgesamt). Der Abruf wird verworfen. '
         . 'Wiederholt sich das, stimmt etwas an der RS485-Verbindung nicht: '
         . 'Baudrate, Adernpaar A/B, Abschlusswiderstand oder Schirmung.';
}

function bm_abruf_pylontech(array $g, array $pr)
{
    $roh = bm_pyl_befehl($g['geraetedatei'], $g['baud'], (int) $g['unit'], 0x42, 'FF');
    if (is_array($roh)) {
        return $roh;
    }
    if (strlen($roh) < 4) {
        return array('_fehler' => 'Die Antwort auf die Analogwertabfrage ist mit '
            . strlen($roh) . ' Bytes zu kurz.');
    }
    $pos = 1;                               // Kennbyte ueberspringen
    $modulzahl = ord($roh[$pos]);
    $pos++;
    if ($modulzahl < 1 || $modulzahl > 32) {
        return array('_fehler' => 'Die Antwort meldet ' . $modulzahl . ' Module. Das ist '
            . 'unglaubwuerdig - vermutlich stimmt die Geraeteadresse nicht.');
    }
    $module = array();
    $restSumme = 0.0;
    $gesamtSumme = 0.0;
    $stromSumme = 0.0;
    $zyklenMax = 0;
    $spannung = null;
    $alleZellen = array();
    $alleTemps = array();
    $zellzahlGesamt = 0;

    for ($m = 1; $m <= $modulzahl; $m++) {
        $zellzahl = bm_u8($roh, $pos);
        if (bm_pyl_ende($pos)) {
            return array('_fehler' => bm_pyl_kurz($m, 'der Zellzahl', $roh));
        }
        if ($zellzahl < 1 || $zellzahl > 32 || $pos + $zellzahl * 2 > strlen($roh)) {
            return array('_fehler' => 'Modul ' . $m . ' meldet ' . $zellzahl . ' Zellen - '
                . 'das passt nicht zur Laenge der Antwort (' . strlen($roh) . ' Bytes). '
                . 'Bei einer gestoerten RS485-Leitung ist das der Regelfall: A und B '
                . 'vertauscht, fehlender Abschlusswiderstand oder ein ungeschirmtes Kabel '
                . 'neben einer Leistungsleitung.');
        }
        $zellen = array();
        for ($z = 1; $z <= $zellzahl; $z++) {
            $zellen[$z] = bm_s16($roh, $pos);
        }
        if (bm_pyl_ende($pos)) {
            return array('_fehler' => bm_pyl_kurz($m, 'der Zellspannungen', $roh));
        }

        // Die Zahl der Temperaturfuehler kommt als einzelnes Byte. Genau hier
        // lief 0.9.0 bei einem Stoerbyte 0xFF 255 Durchlaeufe weit ueber das
        // Ende der Antwort hinaus. Beide Groessen werden jetzt geprueft: die
        // Zahl selbst auf Glaubwuerdigkeit, und der Platz auf Vorhandensein.
        $tempzahl = bm_u8($roh, $pos);
        if (bm_pyl_ende($pos)) {
            return array('_fehler' => bm_pyl_kurz($m, 'der Fuehlerzahl', $roh));
        }
        if ($tempzahl > 16) {
            return array('_fehler' => 'Modul ' . $m . ' meldet ' . $tempzahl
                . ' Temperaturfuehler. Kein Pylontech-Modul hat mehr als 16 - das ist '
                . 'ein Stoerbyte auf der RS485-Leitung, kein Messwert. Der Abruf wird '
                . 'verworfen, statt daraus Zahlen zu erfinden.');
        }
        if ($pos + $tempzahl * 2 > strlen($roh)) {
            return array('_fehler' => 'Modul ' . $m . ' meldet ' . $tempzahl
                . ' Temperaturfuehler, aber die Antwort ist dafuer zu kurz ('
                . strlen($roh) . ' Bytes, Lesezeiger bei ' . $pos . ').');
        }
        $temps = array();
        for ($t = 0; $t < $tempzahl; $t++) {
            $temps[] = round((bm_s16($roh, $pos) - 2731) / 10.0, 1);
        }

        $strom = bm_s16($roh, $pos) / 10.0;
        $spannung = bm_u16($roh, $pos) / 1000.0;
        $rest = bm_u16($roh, $pos) / 1000.0;
        $eigene = bm_u8($roh, $pos);
        $gesamt = bm_u16($roh, $pos) / 1000.0;
        $zyklen = bm_u16($roh, $pos);
        if (bm_pyl_ende($pos)) {
            return array('_fehler' => bm_pyl_kurz($m, 'der Kapazitaetswerte', $roh));
        }
        // Die erweiterten Kapazitaetsfelder werden NUR gelesen, wenn auch
        // Platz dafuer da ist - genau wie in 0.9.0. Diese Bedingung sieht wie
        // eine vergessene Pruefung aus, ist aber Absicht und bleibt deshalb
        // Wort fuer Wort erhalten: 'eigene' zaehlt herstellereigene Felder,
        // und welche Bedeutung ein Wert groesser 2 hat, ist von Firmware zu
        // Firmware verschieden. Ist kein Platz, gelten die zuvor gelesenen
        // 16-Bit-Werte weiter, statt den ganzen Abruf zu verwerfen.
        //
        // Der Unterschied faellt ins Gewicht: waeren die Felder hier Pflicht,
        // wuerde eine Anlage, bei der das bisher lief, ploetzlich dauerhaft
        // Fehler melden. Die Laengenpruefung in bm_u24() bleibt trotzdem als
        // Netz darunter liegen.
        if ($eigene > 2 && $pos + 6 <= strlen($roh)) {
            $rest = bm_u24($roh, $pos) / 1000.0;
            $gesamt = bm_u24($roh, $pos) / 1000.0;
        }
        // Die erste Temperatur ist die des BMS, nicht die einer Zelle. Sie
        // gehoert nicht in die Spanne der Zelltemperaturen.
        $zelltemps = count($temps) > 1 ? array_slice($temps, 1) : $temps;
        $module[$m] = array(
            'zellen' => $zellen,
            'uzmax'  => max($zellen),
            'uzmin'  => min($zellen),
            'uzdiff' => max($zellen) - min($zellen),
            'tmax'   => $zelltemps ? max($zelltemps) : null,
            'tmin'   => $zelltemps ? min($zelltemps) : null,
            'tbms'   => count($temps) > 0 ? $temps[0] : null,
            'strom'  => round($strom, 1),
            'spannung' => round($spannung, 3),
            'rest'   => round($rest, 3),
            'gesamt' => round($gesamt, 3),
            'zyklen' => $zyklen,
        );
        $restSumme += $rest;
        $gesamtSumme += $gesamt;
        $stromSumme += $strom;
        $zyklenMax = max($zyklenMax, $zyklen);
        $alleZellen = array_merge($alleZellen, array_values($zellen));
        $alleTemps = array_merge($alleTemps, $zelltemps);
        $zellzahlGesamt += $zellzahl;
    }

    $werte = array();
    foreach (bm_status_felder() as $feld => $unbenutzt) {
        $werte[$feld] = null;
    }
    $werte['SOC']    = $gesamtSumme > 0 ? round($restSumme / $gesamtSumme * 100, 1) : null;
    $werte['UBAT']   = $spannung !== null ? round($spannung, 2) : null;
    $werte['IBAT']   = round($stromSumme, 1);
    $werte['PBAT']   = $spannung !== null ? round($spannung * $stromSumme, 0) : null;
    $werte['UZMAX']  = $alleZellen ? round(max($alleZellen) / 1000.0, 3) : null;
    $werte['UZMIN']  = $alleZellen ? round(min($alleZellen) / 1000.0, 3) : null;
    $werte['UZDIFF'] = $alleZellen ? (max($alleZellen) - min($alleZellen)) : null;
    $werte['TMAX']   = $alleTemps ? max($alleTemps) : null;
    $werte['TMIN']   = $alleTemps ? min($alleTemps) : null;
    $werte['ZYKLEN'] = $zyklenMax;
    $werte['MODULE'] = $modulzahl;
    $werte['ZELLEN'] = $zellzahlGesamt;
    // Kapazitaet in kWh: Amperestunden mal Spannung, geteilt durch 1000.
    $werte['KAPAZ']  = $spannung !== null ? round($gesamtSumme * $spannung / 1000.0, 2) : null;
    // Einen SoH liefert das Protokoll nicht. Er ergibt sich aus der
    // Gesamtkapazitaet gegen die Nennkapazitaet - und nur dann, wenn der
    // Anwender die Nennkapazitaet eingetragen hat. Ohne sie bleibt das Feld
    // leer, statt eine Zahl zu erfinden.
    if ($g['nennkapaz'] > 0 && $werte['KAPAZ'] !== null) {
        $werte['SOH'] = round($werte['KAPAZ'] / $g['nennkapaz'] * 100, 1);
    }
    return array('werte' => $werte, 'module' => $module);
}

/* ==================================================================
 * Schreibende Befehle
 * ================================================================== */

/**
 * Eine Schrittfolge aus dem Profil abarbeiten.
 * Rueckgabe: array(ok, Meldung)
 */
function bm_steuern(array $g, array $pr, $aktion, $watt)
{
    $cfg = bm_config();
    if (empty($cfg['steuerung_ein'])) {
        return array(0, bm_t('DIENST.STEUERUNG_GLOBAL_AUS'));
    }
    if (empty($g['schreiben'])) {
        return array(0, sprintf(bm_t('DIENST.STEUERUNG_GERAET_AUS'), $g['name']));
    }
    $st = isset($pr['steuerung']) && is_array($pr['steuerung']) ? $pr['steuerung'] : array();

    /* 'sperren' (EVCC: hold) ist kein eigener Registersatz, sondern die
     * Schrittfolge fuers Entladen mit NULL WATT. Ein Geraet, das erzwungenes
     * Entladen kennt, kann auch null Watt entladen - und das heisst "nicht
     * entladen".
     *
     * Bewusst KEINE eigenen Register: die muesste man je Profil erfinden, und
     * ein erfundenes Register schreibt in eine fremde Anlage. Ein Profil, das
     * eine eigene Sperre kennt, darf sie trotzdem hinterlegen - dann greift
     * sie, weil zuerst nach $st['sperren'] gesehen wird. */
    if ($aktion === 'sperren' && !isset($st['sperren']['schritte'])) {
        if (!isset($st['entladen']['schritte'])) {
            return array(0, sprintf(bm_t('DIENST.STEUERUNG_UNBEKANNT'), $aktion, $pr['name']));
        }
        $aktion = 'entladen';
        $watt = 0;
    }

    if (!isset($st[$aktion]['schritte']) || !is_array($st[$aktion]['schritte'])) {
        return array(0, sprintf(bm_t('DIENST.STEUERUNG_UNBEKANNT'), $aktion, $pr['name']));
    }
    if ($g['transport'] === 'pylontech_rs485') {
        return array(0, bm_t('DIENST.STEUERUNG_SERIELL'));
    }

    $s = bm_verbindung_holen($g, (int) $cfg['zeitueberschreitung'], true);
    if (is_array($s)) {
        bm_verbindung_verwerfen($g);
        return array(0, $s['_fehler']);
    }
    $nr = 0;
    foreach ($st[$aktion]['schritte'] as $schritt) {
        $nr++;
        $wert = $schritt['wert'];
        if ($wert === '@watt') {
            $wert = (int) $watt;
        }
        $wert = (int) $wert;
        $typ = isset($schritt['typ']) ? $schritt['typ'] : 'u16';
        if ($typ === 'u32' || $typ === 's32') {
            $erg = bm_register_schreiben_mehrere($s, $g, (int) $schritt['reg'],
                array(($wert >> 16) & 0xFFFF, $wert & 0xFFFF));
        } else {
            $erg = bm_register_schreiben($s, $g, (int) $schritt['reg'], $wert);
        }
        if (is_array($erg)) {
            bm_verbindung_verwerfen($g);
            return array(0, sprintf(bm_t('DIENST.STEUERUNG_SCHRITT'), $nr,
                (int) $schritt['reg'], $erg['_fehler']));
        }
        usleep(150000);   // manche Geraete brauchen eine Atempause
    }
    return array(1, sprintf(bm_t('DIENST.STEUERUNG_OK'), $aktion, (int) $watt, $g['name']));
}

/** Sollwertdatei eines Geraets - sie traegt die Totmannschaltung. */
function bm_soll_datei($nr)
{
    return bm_paths()['datadir'] . '/soll_geraet' . (int) $nr . '.json';
}

function bm_soll_lesen($nr)
{
    return bm_json_lesen(bm_soll_datei($nr));
}

function bm_soll_schreiben($nr, $aktion, $watt, $quelle = '')
{
    return bm_json_schreiben(bm_soll_datei($nr), array(
        'aktion' => $aktion,
        'watt'   => (int) $watt,
        'ts'     => time(),
        // Woher kam der Befehl? Der Endpunkt traegt es ein, der Reiter Test
        // ebenso. Ohne diese Angabe ist bei drei moeglichen Absendern nicht
        // zu klaeren, warum der Speicher gerade laedt.
        'quelle' => bm_text_sauber((string) $quelle, 40),
    ));
}

function bm_soll_loeschen($nr)
{
    @unlink(bm_soll_datei($nr));
}

/* ==================================================================
 * Ein Durchlauf
 * ================================================================== */

/* ==================================================================
 * Ausfallsperre
 *
 * Wer drei Speicher eingerichtet hat und bei dem der erste offline geht,
 * wartete bis 0.9.0 in JEDEM Durchlauf erst dessen Zeitueberschreitung ab,
 * bevor der zweite ueberhaupt gefragt wurde. Bei drei Geraeten und der
 * Voreinstellung von 4 s sind das 12 s von 30 - die Werte der gesunden
 * Speicher altern also mit, obwohl mit ihnen alles in Ordnung ist.
 *
 * Statt einer asynchronen Abfrage ueber stream_select - das waere ein
 * Umbau der gesamten Anforderungsschicht und hilft dem seriellen Weg
 * ohnehin nicht - bekommt ein dauerhaft stummes Geraet eine Pause: nach
 * drei Fehlschlaegen in Folge wird es nur noch alle 60 s gefragt. Meldet es
 * sich wieder, ist die Sperre sofort aufgehoben.
 *
 * Der zuletzt bekannte Fehlertext bleibt am Eintrag stehen; das Geraet gilt
 * weiterhin als gestoert. Es wird nichts beschoenigt, nur seltener gefragt.
 * ================================================================== */

define('BM_SPERRE_AB', 3);        // Fehlschlaege in Folge
define('BM_SPERRE_DAUER', 60);    // Sekunden Pause danach

function bm_gesperrt($nr, array &$ausfall)
{
    if (!isset($ausfall[$nr]) || $ausfall[$nr]['zahl'] < BM_SPERRE_AB) {
        return false;
    }
    return (time() - $ausfall[$nr]['ts']) < BM_SPERRE_DAUER;
}

function bm_ausfall_merken($nr, array &$ausfall, $text)
{
    if (!isset($ausfall[$nr])) {
        $ausfall[$nr] = array('zahl' => 0, 'ts' => 0, 'text' => '');
    }
    $ausfall[$nr]['zahl']++;
    $ausfall[$nr]['ts'] = time();
    $ausfall[$nr]['text'] = $text;
    if ($ausfall[$nr]['zahl'] === BM_SPERRE_AB) {
        bm_log('Geraet ' . (int) $nr . ' antwortet zum ' . BM_SPERRE_AB . '. Mal nicht - '
             . 'es wird nur noch alle ' . BM_SPERRE_DAUER . ' s gefragt, damit die uebrigen '
             . 'Speicher nicht mitwarten. Grund: ' . $text);
    }
}

function bm_ausfall_loeschen($nr, array &$ausfall)
{
    if (isset($ausfall[$nr])) {
        if ($ausfall[$nr]['zahl'] >= BM_SPERRE_AB) {
            bm_log('Geraet ' . (int) $nr . ' antwortet wieder - die Pause ist aufgehoben.');
        }
        unset($ausfall[$nr]);
    }
}

function bm_durchlauf(&$letzteZellen, array &$ausfall = array())
{
    $cfg = bm_config();
    $geraete = bm_geraete();
    $profile = bm_profile();
    $tmo = max(1, min(30, (int) $cfg['zeitueberschreitung']));
    $abbild = array('ts' => time(), 'ok' => 0, 'geraete' => array());
    $stoerung = '';
    $okZahl = 0;

    foreach ($geraete as $nr => $g) {
        $pr = isset($profile[$g['profil']]) ? $profile[$g['profil']] : null;
        if ($pr === null) {
            continue;
        }
        $eintrag = array(
            'nr'         => $nr,
            'name'       => $g['name'],
            'profil'     => $g['profil'],
            'profilname' => $g['profilname'],
            'transport'  => $g['transport'],
            'stand'      => $g['stand'],
            'ok'         => 0,
            'alter'      => 0,
            'fehlertext' => '',
            'module'     => array(),
        );
        foreach (bm_status_felder() as $feld => $unbenutzt) {
            $eintrag[$feld] = null;
        }

        // Ein Geraet in der Ausfallpause wird uebersprungen. Es behaelt
        // seinen Fehlertext und gilt weiter als gestoert - es wird nur nicht
        // erneut angefragt, damit die uebrigen nicht mitwarten muessen.
        if (bm_gesperrt($nr, $ausfall)) {
            $eintrag['fehlertext'] = $ausfall[$nr]['text'] . ' (Pause bis zum naechsten Versuch, '
                . 'noch ' . max(0, BM_SPERRE_DAUER - (time() - $ausfall[$nr]['ts'])) . ' s)';
            $stoerung = $g['name'] . ': ' . $ausfall[$nr]['text'];
            $eintrag['OK'] = 0;
            $eintrag['ALTER'] = 0;
            $abbild['geraete'][$nr] = $eintrag;
            continue;
        }

        if ($g['transport'] === 'pylontech_rs485') {
            $erg = bm_abruf_pylontech($g, $pr);
            if (isset($erg['_fehler'])) {
                $eintrag['fehlertext'] = $erg['_fehler'];
                $stoerung = $g['name'] . ': ' . $erg['_fehler'];
                bm_ausfall_merken($nr, $ausfall, $erg['_fehler']);
            } else {
                foreach ($erg['werte'] as $k => $v) {
                    $eintrag[$k] = $v;
                }
                $eintrag['module'] = $erg['module'];
                $eintrag['ok'] = 1;
                bm_ausfall_loeschen($nr, $ausfall);
            }
        } else {
            $erg = bm_abruf_modbus($g, $pr, $tmo);
            if (isset($erg['_fehler'])) {
                $eintrag['fehlertext'] = $erg['_fehler'];
                $stoerung = $g['name'] . ': ' . $erg['_fehler'];
                bm_ausfall_merken($nr, $ausfall, $erg['_fehler']);
            } else {
                foreach ($erg['werte'] as $k => $v) {
                    $eintrag[$k] = $v;
                }
                $eintrag['ok'] = 1;
                bm_ausfall_loeschen($nr, $ausfall);

                // Zelldaten seltener holen: sie kosten bei BYD fuenf
                // zusaetzliche Anforderungen und eine Wartezeit.
                $zt = max(30, (int) $cfg['zelltakt']);
                $kannZellen = isset($pr['zellen']['verfahren'])
                              && $pr['zellen']['verfahren'] === 'byd_bms';
                $faellig = !isset($letzteZellen[$nr]) || (time() - $letzteZellen[$nr]) >= $zt;
                if ($kannZellen && $faellig) {
                    $zellen = bm_zellen_byd($g, $pr, $tmo);
                    $letzteZellen[$nr] = time();
                    if (isset($zellen['_fehler'])) {
                        bm_log_gebremst('zellen' . $nr, $g['name'] . ' - Zelldaten: '
                            . $zellen['_fehler']);
                        // Abruf misslungen: die vorigen Zelldaten behalten,
                        // aber NICHT so tun, als waeren sie frisch.
                        $eintrag['module'] = bm_module_vorher($nr);
                    } else {
                        $eintrag['module'] = $zellen['module'];
                    }
                } elseif ($kannZellen) {
                    // Zwischen zwei Zelldatenabrufen die vorigen behalten,
                    // statt sie auf leer zu setzen.
                    $eintrag['module'] = bm_module_vorher($nr);
                }
            }
        }

        // Aus den Modulwerten die Gesamtspanne bilden, falls das Geraet sie
        // nicht selbst liefert.
        if ($eintrag['module']) {
            $max = array();
            $min = array();
            $zellzahl = 0;
            foreach ($eintrag['module'] as $m) {
                if (isset($m['uzmax'])) {
                    $max[] = (float) $m['uzmax'];
                }
                if (isset($m['uzmin'])) {
                    $min[] = (float) $m['uzmin'];
                }
                $zellzahl += isset($m['zellen']) ? count($m['zellen']) : 0;
            }
            if ($max && $min) {
                // Die Modulwerte stehen in mV, die Gesamtwerte in V.
                if ($eintrag['UZMAX'] === null) {
                    $eintrag['UZMAX'] = round(max($max) / 1000.0, 3);
                }
                if ($eintrag['UZMIN'] === null) {
                    $eintrag['UZMIN'] = round(min($min) / 1000.0, 3);
                }
                $eintrag['UZDIFF'] = (int) round(max($max) - min($min));
            }
            if ($eintrag['ZELLEN'] === null && $zellzahl > 0) {
                $eintrag['ZELLEN'] = $zellzahl;
            }
            if ($eintrag['MODULE'] === null) {
                $eintrag['MODULE'] = count($eintrag['module']);
            }
        }
        if ($eintrag['UZDIFF'] === null && $eintrag['UZMAX'] !== null && $eintrag['UZMIN'] !== null) {
            $eintrag['UZDIFF'] = (int) round(($eintrag['UZMAX'] - $eintrag['UZMIN']) * 1000);
        }

        // Vorzeichen auf die Hausvorgabe bringen: positiv = laden.
        if ($g['vorzeichen'] === -1) {
            foreach (array('IBAT', 'PBAT') as $feld) {
                if ($eintrag[$feld] !== null) {
                    $eintrag[$feld] = -$eintrag[$feld];
                }
            }
        }

        /* Die schwaechste Zelle benennen.
         *
         * Die Modulwerte enthalten laengst den niedrigsten Wert je Modul -
         * nirgends stand aber, WELCHE Zelle es ist. Wer eine Zelle beobachten
         * oder tauschen will, musste sie im Balkenbild suchen. */
        if ($eintrag['module']) {
            $minmv = null;
            foreach ($eintrag['module'] as $m => $md) {
                foreach ((array) (isset($md['zellen']) ? $md['zellen'] : array()) as $z => $mv) {
                    if ($mv > 0 && ($minmv === null || $mv < $minmv)) {
                        $minmv = $mv;
                        $eintrag['UZMINMOD'] = (int) $m;
                        $eintrag['UZMINZELLE'] = (int) $z;
                    }
                }
            }
        }

        // Restenergie und Restzeit. Beides bleibt leer, wo es sich nicht
        // belegen laesst - siehe bm_restwerte().
        list($restkwh, $restzeit) = bm_restwerte($eintrag, $g);
        $eintrag['RESTKWH'] = $restkwh;
        $eintrag['RESTZEIT'] = $restzeit;

        // Sammelmerker samt Klartext.
        $gruende = bm_alarm($eintrag, $cfg);
        $eintrag['ALARM'] = $gruende ? 1 : 0;
        $eintrag['alarmtext'] = $gruende ? bm_text_sauber(implode(' | ', $gruende)) : '';
        if ($gruende) {
            bm_log_gebremst('alarm' . $nr, $g['name'] . ': ' . implode(' | ', $gruende), 1800);
        }

        // Sollwert und Totmannschaltung
        $soll = bm_soll_lesen($nr);
        if ($soll && isset($soll['aktion'])) {
            $alterSoll = time() - (int) $soll['ts'];
            $eintrag['sollwert'] = $soll['aktion'] . ':' . (int) $soll['watt'];
            $eintrag['sollwert_alter'] = $alterSoll;
            // Wer hat den Zwang gesetzt? Loxone, EVCC und der Reiter Test
            // schreiben in dieselbe Datei; bis 0.9.7 gewann der letzte, und
            // niemand sah, wer es war.
            $eintrag['sollwert_quelle'] = isset($soll['quelle']) ? (string) $soll['quelle'] : '';
            $tot = max(0, (int) $cfg['totmann']);
            if ($tot > 0 && $alterSoll > $tot) {
                list($ok, $meldung) = bm_steuern($g, $pr, 'automatik', 0);
                bm_soll_loeschen($nr);
                bm_log($g['name'] . ': Totmannschaltung nach ' . $alterSoll . ' s ohne '
                    . 'Lebenszeichen - zurueck in die Automatik. ' . $meldung);
                $eintrag['sollwert'] = '';
                $eintrag['sollwert_alter'] = -1;
                $eintrag['sollwert_quelle'] = '';
            }
        } else {
            $eintrag['sollwert'] = '';
            $eintrag['sollwert_alter'] = -1;
            $eintrag['sollwert_quelle'] = '';
        }

        /* Zeitstempel des letzten ERFOLGREICHEN Abrufs.
         *
         * Er wird ueber die Durchlaeufe hinweg mitgeschleppt, damit sich der
         * MQTT-Zweig darauf beziehen kann. Ein Geraet in der Ausfallpause
         * behaelt seinen alten Zeitstempel - dessen Alter waechst dann
         * sichtbar, waehrend die Messwerte im Broker unveraendert stehen. */
        $vorher = bm_werte();
        if ($eintrag['ok']) {
            $okZahl++;
            $eintrag['OK'] = 1;
            $eintrag['ok_ts'] = time();
            bm_verlauf_schreiben($nr, $eintrag);
        } else {
            $eintrag['OK'] = 0;
            $eintrag['ok_ts'] = isset($vorher[$nr]['ok_ts']) ? (int) $vorher[$nr]['ok_ts'] : 0;
        }
        $eintrag['ALTER'] = 0;
        $abbild['geraete'][$nr] = $eintrag;
    }

    $abbild['ok'] = ($geraete && $okZahl === count($geraete)) ? 1 : 0;
    // Das Ergebnis des Schreibens wird ausgewertet. Ohne diese Pruefung
    // bliebe das Abbild fuer Loxone stumm auf dem alten Stand stehen, wenn
    // json_encode an einem ungueltigen Byte scheitert - etwa an einer
    // Fehlermeldung des Betriebssystems in einer Nicht-UTF-8-Umgebung. Der
    // Miniserver bekaeme immer aeltere Werte, ohne dass irgendwo etwas davon
    // zu lesen waere.
    if (!bm_json_schreiben(bm_paths()['datadir'] . '/loxone.json', $abbild)) {
        bm_log_gebremst('abbild_schreiben',
            'Das Abbild loxone.json liess sich NICHT schreiben. Der Miniserver bekommt '
            . 'weiterhin die zuletzt gespeicherten Werte - deren Feld ALTER waechst.', 600);
    }
    bm_json_schreiben(bm_paths()['datadir'] . '/zustand.json', array(
        'ts'     => time(),
        'fehler' => $stoerung,
        'ok'     => $okZahl,
        'gesamt' => count($geraete),
    ));
    bm_tmp_aufraeumen(bm_paths()['datadir']);

    if (!empty($cfg['mqtt_ein'])) {
        bm_veroeffentlichen($abbild, (string) $cfg['mqtt_topic']);
    }
    return $abbild;
}

/** Die Zelldaten aus dem vorigen Abbild. Leeres Array, wenn es keine gibt. */
function bm_module_vorher($nr)
{
    $alt = bm_werte();
    return (isset($alt[$nr]['module']) && is_array($alt[$nr]['module']))
        ? $alt[$nr]['module'] : array();
}

/** Einen Messpunkt in die Tagesdatei schreiben, hoechstens alle vier Minuten. */
function bm_verlauf_schreiben($nr, array $e)
{
    $cfg = bm_config();
    $ordner = bm_paths()['datadir'] . '/verlauf';
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return;
    }
    $datei = $ordner . '/geraet' . (int) $nr . '_' . date('Ymd') . '.csv';
    if (is_file($datei) && (time() - filemtime($datei)) < 240) {
        return;
    }
    if ($e['SOC'] === null) {
        return;
    }
    /* Sieben Spalten statt drei: Zeit, Ladezustand, Leistung, Zelldrift,
     * hoechste Temperatur, Gesundheitszustand, Zyklen. Die ersten drei stehen
     * unveraendert an ihrer Stelle, damit aeltere Dateien und das
     * Tagesdiagramm weiterlaufen. Ein fehlender Wert bleibt LEER - eine 0
     * waere hier eine stille Falschaussage ueber eine Messreihe. */
    $sp = function ($w) {
        return ($w === null || $w === '') ? '' : $w;
    };
    @file_put_contents($datei, time() . ';' . $e['SOC'] . ';'
        . $sp($e['PBAT']) . ';' . $sp($e['UZDIFF']) . ';' . $sp($e['TMAX']) . ';'
        . $sp($e['SOH']) . ';' . $sp($e['ZYKLEN']) . "\n", FILE_APPEND);

    // Alte Tagesdateien wegraeumen
    $tage = max(1, min(365, (int) $cfg['verlauf_tage']));
    foreach ((array) glob($ordner . '/geraet*_*.csv') as $alt) {
        if (time() - filemtime($alt) > $tage * 86400) {
            @unlink($alt);
        }
    }
}

/** Das Abbild ueber das MQTT-Gateway veroeffentlichen.
 *
 * Die Paare selbst bildet bm_mqtt_paare() in der Bibliothek. Getrennt, weil
 * der Reiter Test dann nachrechnen kann, WAS veroeffentlicht wuerde, ohne ein
 * Paket zu schicken - und weil zwei Kopien derselben Zuordnung zwangslaeufig
 * auseinanderlaufen.
 */
function bm_veroeffentlichen(array $abbild, $topic)
{
    $topic = trim($topic, '/');
    if ($topic === '') {
        $topic = 'batteriebms';
    }
    bm_mqtt_senden(bm_mqtt_paare($abbild), $topic);
}

/* ==================================================================
 * Befehlswarteschlange abarbeiten
 * ================================================================== */

function bm_warteschlange(&$letzteSchreibzeit)
{
    $p = bm_paths();
    $cfg = bm_config();
    $ordner = $p['datadir'] . '/befehle';
    $antworten = $p['datadir'] . '/antworten';
    if (!is_dir($ordner)) {
        return false;
    }
    if (!is_dir($antworten)) {
        @mkdir($antworten, 0775, true);
    }
    $sofortAbruf = false;
    foreach ((array) glob($ordner . '/*.json') as $datei) {
        $kennung = basename($datei, '.json');
        $befehl = bm_json_lesen($datei);
        @unlink($datei);
        if (!$befehl || !isset($befehl['aktion'])) {
            continue;
        }
        list($ok, $meldung) = bm_befehl_ausfuehren($befehl, $letzteSchreibzeit, $sofortAbruf);
        bm_json_schreiben($antworten . '/' . $kennung . '.json',
                          array('ok' => $ok, 'meldung' => $meldung));
        bm_log('Befehl ' . $befehl['aktion'] . ': ' . ($ok ? 'erledigt' : 'abgelehnt')
            . ' - ' . $meldung);
    }
    // Herrenlose Antworten wegraeumen: der Fragende ist laengst weg.
    foreach ((array) glob($antworten . '/*.json') as $alt) {
        if (time() - filemtime($alt) > 120) {
            @unlink($alt);
        }
    }
    return $sofortAbruf;
}

function bm_befehl_ausfuehren(array $befehl, &$letzteSchreibzeit, &$sofortAbruf)
{
    $cfg = bm_config();
    $aktion = (string) $befehl['aktion'];
    $bm_quelle = isset($befehl['quelle']) ? (string) $befehl['quelle'] : '';

    if ($aktion === 'abruf') {
        $sofortAbruf = true;
        return array(1, bm_t('DIENST.ABRUF_EINGEREIHT'));
    }

    $nr = isset($befehl['geraet']) ? (int) $befehl['geraet'] : 1;
    $g = bm_geraet($nr);
    if ($g === null) {
        return array(0, sprintf(bm_t('DIENST.GERAET_UNBEKANNT'), $nr));
    }
    $pr = bm_profil($g['profil']);
    if ($pr === null) {
        return array(0, sprintf(bm_t('DIENST.PROFIL_FEHLT'), $g['profil']));
    }

    /* Rohregister lesen - fuer den Reiter Test. Rein lesend, deshalb ohne
     * Steuerungsfreigabe, aber nur ueber die Oberflaeche erreichbar. */
    if ($aktion === 'roh') {
        if ($g['transport'] === 'pylontech_rs485') {
            return array(0, bm_t('DIENST.ROH_SERIELL'));
        }
        $s = bm_verbindung_holen($g, (int) $cfg['zeitueberschreitung'], true);
        if (is_array($s)) {
            bm_verbindung_verwerfen($g);
            return array(0, $s['_fehler']);
        }
        $start = isset($befehl['start']) ? (int) $befehl['start'] : 0;
        $anzahl = max(1, min(64, isset($befehl['anzahl']) ? (int) $befehl['anzahl'] : 8));
        $fc = (isset($befehl['fc']) && (int) $befehl['fc'] === 4) ? 4 : 3;
        $regs = bm_register_lesen($s, $g, $fc, $start, $anzahl);
        if (isset($regs['_fehler'])) {
            bm_verbindung_verwerfen($g);
            return array(0, $regs['_fehler']);
        }
        $zeilen = array();
        foreach ($regs as $adr => $wert) {
            $vorz = $wert >= 0x8000 ? $wert - 0x10000 : $wert;
            $zeilen[] = sprintf('%5d (0x%04X) = %5u  0x%04X  s16 %6d', $adr, $adr, $wert, $wert, $vorz);
        }
        return array(1, implode(' | ', $zeilen));
    }

    if ($aktion === 'automatik') {
        list($ok, $meldung) = bm_steuern($g, $pr, 'automatik', 0);
        if ($ok) {
            bm_soll_loeschen($nr);
            $sofortAbruf = true;
        }
        return array($ok, $meldung);
    }

    if ($aktion === 'lebenszeichen') {
        $soll = bm_soll_lesen($nr);
        if (!$soll || !isset($soll['aktion'])) {
            return array(0, bm_t('DIENST.LEBENSZEICHEN_OHNE_SOLL'));
        }
        bm_soll_schreiben($nr, $soll['aktion'], (int) $soll['watt'],
            isset($soll['quelle']) ? $soll['quelle'] : '');
        return array(1, sprintf(bm_t('DIENST.LEBENSZEICHEN_OK'), (int) $cfg['totmann']));
    }

    if ($aktion === 'sperren') {
        /* Anders als bei laden/entladen wird hier NICHT gegen soc_min
         * geprueft. Es wird nichts entnommen - die Sperre kann den Speicher
         * nicht leerer machen, als er ist. Eine Grenzpruefung haette hier nur
         * die Wirkung, dass ein fast leerer Speicher nicht angehalten werden
         * koennte, und das ist genau verkehrt herum. */
        list($ok, $meldung) = bm_steuern($g, $pr, 'sperren', 0);
        if ($ok) {
            bm_soll_schreiben($nr, 'sperren', 0, $bm_quelle);
            $sofortAbruf = true;
        }
        return array($ok, $meldung);
    }

    if ($aktion !== 'laden' && $aktion !== 'entladen') {
        return array(0, sprintf(bm_t('DIENST.AKTION_UNBEKANNT'), $aktion));
    }

    $watt = isset($befehl['watt']) ? (int) $befehl['watt'] : -1;
    if ($watt < 0) {
        return array(0, bm_t('DIENST.WATT_FEHLT'));
    }
    // 0 Watt heisst: Regie zurueckgeben. Das ist etwas anderes als 'mit
    // 0 Watt laden' und wird deshalb ausdruecklich so behandelt.
    if ($watt === 0) {
        list($ok, $meldung) = bm_steuern($g, $pr, 'automatik', 0);
        if ($ok) {
            bm_soll_loeschen($nr);
            $sofortAbruf = true;
        }
        return array($ok, $meldung);
    }

    // Obergrenze des Geraets. Ein zu hoher Wert wird ABGEWIESEN, nicht
    // stillschweigend gekappt - sonst glaubt Loxone, es habe 5000 W bestellt.
    $grenze = ($aktion === 'laden') ? (int) $g['max_laden'] : (int) $g['max_entladen'];
    if ($grenze > 0 && $watt > $grenze) {
        return array(0, sprintf(bm_t('DIENST.WATT_ZU_GROSS'), $watt, $grenze, $g['name']));
    }

    // Ladezustandsfenster. Auch das weist ab, statt zu kappen.
    $werte = bm_werte();
    $soc = isset($werte[$nr]['SOC']) ? $werte[$nr]['SOC'] : null;
    if ($soc !== null) {
        if ($aktion === 'laden' && $soc >= (int) $cfg['soc_max']) {
            return array(0, sprintf(bm_t('DIENST.SOC_ZU_HOCH'), $soc, (int) $cfg['soc_max']));
        }
        if ($aktion === 'entladen' && $soc <= (int) $cfg['soc_min']) {
            return array(0, sprintf(bm_t('DIENST.SOC_ZU_NIEDRIG'), $soc, (int) $cfg['soc_min']));
        }
    }

    // Schreibbremse je Geraet
    $bremse = max(0, (int) $cfg['schreibbremse']);
    $zuletzt = isset($letzteSchreibzeit[$nr]) ? $letzteSchreibzeit[$nr] : 0;
    if ($bremse > 0 && (time() - $zuletzt) < $bremse) {
        $rest = $bremse - (time() - $zuletzt);
        // Der Sollwert wird trotzdem aufgefrischt - sonst laeuft die
        // Totmannschaltung ab, nur weil die Bremse gegriffen hat.
        bm_soll_schreiben($nr, $aktion, $watt, $bm_quelle);
        return array(1, sprintf(bm_t('DIENST.BREMSE'), $rest));
    }

    list($ok, $meldung) = bm_steuern($g, $pr, $aktion, $watt);
    if ($ok) {
        $letzteSchreibzeit[$nr] = time();
        bm_soll_schreiben($nr, $aktion, $watt, $bm_quelle);
        $sofortAbruf = true;
    }
    return array($ok, $meldung);
}

/* ==================================================================
 * Hauptschleife
 * ================================================================== */

function bm_dienst_schleife($einmal = false)
{
    global $bm_laeuft;
    $p = bm_paths();
    foreach (array($p['datadir'], $p['datadir'] . '/befehle', $p['datadir'] . '/antworten',
                   $p['datadir'] . '/verlauf', $p['datadir'] . '/profile', $p['logdir']) as $d) {
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
    }
    $cfg = bm_config();
    $geraete = bm_geraete();
    bm_log('Dienst gestartet. ' . count($geraete) . ' Speicher, Takt '
        . (int) $cfg['intervall'] . ' s, Zelldaten alle ' . (int) $cfg['zelltakt'] . ' s.');
    if (!$geraete) {
        bm_log('Es ist kein Speicher eingerichtet - der Dienst laeuft, hat aber nichts zu tun.');
    }

    $letzteZellen = array();
    $letzteSchreibzeit = array();
    $ausfall = array();
    $naechster = 0;

    while ($bm_laeuft) {
        // Ohne asynchrone Signale (sehr alte PHP-Fassung) hier nachsehen.
        // Mit ihnen ist der Aufruf ueberfluessig, schadet aber nicht.
        if (!function_exists('pcntl_async_signals') && function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        $cfg = bm_config();
        $sofort = bm_warteschlange($letzteSchreibzeit);
        if ($sofort || time() >= $naechster) {
            bm_durchlauf($letzteZellen, $ausfall);
            $naechster = time() + max(5, (int) $cfg['intervall']);
        }
        if ($einmal) {
            break;
        }
        usleep(300000);
    }

    // Beim Anhalten jeden Zwang zuruecknehmen. Ein Speicher, der mit einem
    // Sollwert stehen bleibt, waehrend niemand mehr nachfuettert, ist das
    // gefaehrlichste Ergebnis dieses Plugins.
    foreach (bm_geraete() as $nr => $g) {
        $soll = bm_soll_lesen($nr);
        if ($soll && isset($soll['aktion'])) {
            $pr = bm_profil($g['profil']);
            if ($pr !== null) {
                list($ok, $meldung) = bm_steuern($g, $pr, 'automatik', 0);
                bm_log($g['name'] . ': beim Anhalten in die Automatik zurueckgestellt. ' . $meldung);
            }
            bm_soll_loeschen($nr);
        }
    }
    bm_verbindungen_schliessen();
    bm_log('Dienst beendet.');
    return 0;
}

/* ==================================================================
 * Selbstpruefung ohne Geraet
 * ================================================================== */

function bm_selbsttest()
{
    $p = bm_paths();
    $cfg = bm_config();
    $geraete = bm_geraete();
    $profile = bm_profile();
    $zeilen = array();
    $fehler = 0;

    $zeilen[] = 'Batterie-Heimspeicher (BMS) - Selbstpruefung';
    $zeilen[] = str_repeat('-', 62);
    $zeilen[] = '[OK]   PHP ' . PHP_VERSION;

    foreach (array('json') as $erw) {
        if (extension_loaded($erw)) {
            $zeilen[] = '[OK]   PHP-Erweiterung ' . $erw . ' geladen';
        } else {
            $fehler++;
            $zeilen[] = '[FEHL] PHP-Erweiterung ' . $erw . ' fehlt';
        }
    }
    if (extension_loaded('sockets')) {
        $zeilen[] = '[OK]   PHP-Erweiterung sockets geladen (fuer MQTT)';
    } else {
        $zeilen[] = '[INFO] PHP-Erweiterung sockets fehlt. Modbus laeuft trotzdem, '
                  . 'die MQTT-Veroeffentlichung nicht. Abhilfe: sudo apt install php-sockets';
    }

    foreach (array('Konfiguration' => $p['configdir'], 'Daten' => $p['datadir'],
                   'Log' => $p['logdir']) as $name => $pfad) {
        $ok = is_dir($pfad) && is_writable($pfad);
        $zeilen[] = ($ok ? '[OK]   ' : '[FEHL] ') . 'Ordner ' . $name . ' beschreibbar: ' . $pfad;
        if (!$ok) {
            $fehler++;
        }
    }

    $rechte = is_file($p['config']) ? (fileperms($p['config']) & 0777) : -1;
    if ($rechte < 0) {
        $fehler++;
        $zeilen[] = '[FEHL] Die Konfigurationsdatei fehlt: ' . $p['config'];
    } elseif (($rechte & 0077) === 0) {
        $zeilen[] = '[OK]   Rechte der Konfiguration: 0' . decoct($rechte) . ' (sie traegt das Token)';
    } else {
        $fehler++;
        $zeilen[] = '[FEHL] Rechte der Konfiguration: 0' . decoct($rechte) . ' - erwartet 0600, '
                  . 'weil dort das Aktionstoken steht';
    }

    $zeilen[] = '[INFO] Profile insgesamt: ' . count($profile);
    foreach ($profile as $k => $pr) {
        $zeilen[] = sprintf('[%s] %-26s %-16s %-13s %s',
            $pr['stand'] === 'dokumentiert' ? 'OK  ' : 'WARN',
            $k, $pr['transport'], $pr['stand'], $pr['herkunft']);
    }

    if (!$geraete) {
        $fehler++;
        $zeilen[] = '[FEHL] Es ist kein Speicher eingerichtet';
    } else {
        $zeilen[] = '[OK]   ' . count($geraete) . ' Speicher eingerichtet';
        foreach ($geraete as $nr => $g) {
            $ziel = ($g['transport'] === 'pylontech_rs485')
                ? $g['geraetedatei'] . ' @ ' . $g['baud']
                : $g['ip'] . ':' . $g['port'] . ' Unit ' . $g['unit'];
            $zeilen[] = '[INFO]   ' . $nr . ') ' . $g['name'] . ' - ' . $g['profil']
                      . ', ' . $ziel
                      . ', Schreiben ' . ($g['schreiben'] ? 'zugelassen' : 'gesperrt')
                      . ', Vorzeichen ' . ($g['vorzeichen'] > 0 ? 'positiv = laden' : 'umgekehrt');
            if ($g['transport'] === 'pylontech_rs485') {
                if (!file_exists($g['geraetedatei'])) {
                    $fehler++;
                    $zeilen[] = '[FEHL]      Die Geraetedatei ' . $g['geraetedatei']
                              . ' gibt es nicht. Steckt der RS485-Adapter?';
                } elseif (!is_readable($g['geraetedatei']) || !is_writable($g['geraetedatei'])) {
                    $fehler++;
                    $zeilen[] = '[FEHL]      Auf ' . $g['geraetedatei'] . ' fehlen die Rechte. '
                              . 'Abhilfe: sudo usermod -a -G dialout loxberry, dann neu starten.';
                } else {
                    $zeilen[] = '[OK]        ' . $g['geraetedatei'] . ' ist les- und beschreibbar';
                }
            }
            $pr = isset($profile[$g['profil']]) ? $profile[$g['profil']] : null;
            if ($pr !== null && $pr['stand'] !== 'dokumentiert') {
                $zeilen[] = '[WARN]      Das Profil ' . $g['profil'] . ' ist NICHT gegen ein '
                          . 'Geraet geprueft. Die Werte im Reiter Test gegen die Anzeige des '
                          . 'Herstellers halten, bevor Loxone darauf regelt.';
            }
            if ($g['schreiben'] && $pr !== null
                && isset($pr['steuerung_stand']) && $pr['steuerung_stand'] !== 'dokumentiert') {
                $zeilen[] = '[WARN]      Die Schreibbefehle dieses Profils sind ungeprueft. '
                          . 'Erst mit kleinen Werten und unter Aufsicht ausloesen.';
            }
        }
    }

    if (!bm_command_da('stty')) {
        $braucht = false;
        foreach ($geraete as $g) {
            if ($g['transport'] === 'pylontech_rs485') {
                $braucht = true;
            }
        }
        if ($braucht) {
            $fehler++;
            $zeilen[] = '[FEHL] stty fehlt - ohne das laesst sich die serielle Schnittstelle '
                      . 'nicht einstellen';
        } else {
            $zeilen[] = '[INFO] stty fehlt - hier aber nicht gebraucht, weil kein Geraet seriell laeuft';
        }
    } else {
        $zeilen[] = '[OK]   stty vorhanden';
    }

    $m = bm_mqtt_zustand();
    if (!$m['gefunden']) {
        $fehler++;
        $zeilen[] = '[FEHL] In der general.json des LoxBerry ist kein MQTT-Abschnitt zu finden';
    } elseif ($m['autostart']) {
        $zeilen[] = '[OK]   MQTT-Gateway auf Autostart, Broker ' . $m['broker'] . ':'
                  . $m['brokerport'] . ', UDP-Eingang ' . $m['udpport'];
    } else {
        $fehler++;
        $zeilen[] = '[FEHL] Das MQTT-Gateway ist nicht auf Autostart gestellt (System, '
                  . 'MQTT Gateway). Ohne das kommt am Miniserver nichts an.';
    }

    /* Rechenwege pruefen, die sich ohne Geraet pruefen LASSEN. Das ist keine
     * Zierde: die Pruefsummen der beiden Protokolle sind die Stelle, an der
     * ein Nachbau typischerweise falsch ist, und ein Fehler dort sieht von
     * aussen aus wie ein Verkabelungsproblem. */
    $zeilen[] = '';
    $zeilen[] = 'Nachgebaute Protokolle gegen Pruefwerte:';

    // Modbus-CRC16: der in der Modbus-Spezifikation angefuehrte Prueffall.
    $crc = bm_crc16("\x02\x07");
    $crcHex = strtoupper(bin2hex($crc));
    $crcOk = ($crcHex === '4112');
    $zeilen[] = ($crcOk ? '[OK]   ' : '[FEHL] ') . 'Modbus-CRC16 ueber 02 07 ergibt 0x'
              . $crcHex . ' (erwartet 0x4112)';
    if (!$crcOk) {
        $fehler++;
    }

    // Pylontech: Laengenfeld und Pruefsumme eines Rahmens, der in der
    // Protokollbeschreibung als Beispiel steht.
    $lenid = bm_pyl_lenid('02');
    $lenOk = ($lenid === 0xE002);
    $zeilen[] = ($lenOk ? '[OK]   ' : '[FEHL] ') . 'Pylontech-LENID fuer 2 Zeichen ergibt 0x'
              . strtoupper(dechex($lenid)) . ' (erwartet 0xE002)';
    if (!$lenOk) {
        $fehler++;
    }
    $rahmen = bm_pyl_rahmen(2, 0x42, 'FF');
    $formOk = (substr($rahmen, 0, 1) === '~' && substr($rahmen, -1) === "\r"
               && strlen($rahmen) === 20);
    $zeilen[] = ($formOk ? '[OK]   ' : '[FEHL] ') . 'Pylontech-Rahmen fuer CID2 0x42: '
              . trim($rahmen) . ' (' . strlen($rahmen) . ' Zeichen)';
    if (!$formOk) {
        $fehler++;
    }
    // Die eigene Pruefsumme gegen die eigene Pruefung: hier wird nur belegt,
    // dass der Rahmen in sich stimmig ist. Gegen die ORIGINALFASSUNG gemessen
    // ist er nicht - das steht unten ausdruecklich dabei.
    $kern = substr($rahmen, 1, strlen($rahmen) - 6);
    $eigen = sprintf('%04X', bm_pyl_chksum($kern));
    $summeOk = ($eigen === substr($rahmen, -5, 4));
    $zeilen[] = ($summeOk ? '[OK]   ' : '[FEHL] ') . 'Pylontech-Pruefsumme in sich stimmig ('
              . $eigen . ')';
    if (!$summeOk) {
        $fehler++;
    }

    // Registerumrechnung
    $probe = array(100 => 0xFFFE, 101 => 0x1234, 102 => 0x0001, 103 => 0x86A0);
    $t1 = bm_wert_aus($probe, array('reg' => 100, 'typ' => 's16', 'faktor' => 0.1, 'nk' => 1));
    $t2 = bm_wert_aus($probe, array('reg' => 102, 'typ' => 'u32', 'faktor' => 1, 'nk' => 0));
    $t3 = bm_wert_aus($probe, array('reg' => 100, 'typ' => 'u16', 'faktor' => 1, 'nk' => 0,
                                    'maske' => 0x000F));
    $umOk = (abs($t1 - (-0.2)) < 0.001) && ((int) $t2 === 100000) && ((int) $t3 === 14);
    $zeilen[] = ($umOk ? '[OK]   ' : '[FEHL] ') . 'Registerumrechnung: s16 0xFFFE x 0,1 = '
              . $t1 . ' (erwartet -0,2), u32 0x0001_86A0 = ' . $t2
              . ' (erwartet 100000), Maske 0x000F von 0xFFFE = ' . $t3 . ' (erwartet 14)';
    if (!$umOk) {
        $fehler++;
    }

    /* --- Die vier Stellen, an denen 0.9.0 stillschweigend das Falsche tat.
     *
     * Alle vier lassen sich ohne Geraet pruefen, und alle vier sind so
     * beschaffen, dass ein Rueckfall im Betrieb nicht auffiele: das
     * Ergebnis waere nicht 'Fehler', sondern 'Zahl' - nur die falsche. */
    $zeilen[] = '';
    $zeilen[] = 'Schutz vor gestoerten Daten (neu in 0.9.1):';

    // 1. Der Pylontech-Parser darf nicht ueber das Ende hinauslesen.
    //    Nachgestellt wird genau der Fall aus dem Befund: ein Stoerbyte 0xFF
    //    als Zahl der Temperaturfuehler.
    $stoer = "\x00\x01"                    // Kennbyte, 1 Modul
           . "\x02" . "\x0C\xE4\x0C\xE6"   // 2 Zellen a 3300/3302 mV
           . "\xFF";                       // 255 Temperaturfuehler - Stoerbyte
    $pylOk = false;
    $pylMeldung = '';
    $g0 = array('geraetedatei' => '', 'baud' => 0, 'unit' => 0, 'nennkapaz' => 0.0);
    if (function_exists('bm_u8')) {
        /* Stelle 7, nicht 5.
         *
         * Der nachgestellte Rahmen ist: Kennbyte(1) Modulzahl(1) Zellzahl(1)
         * zwei Zellspannungen(4) Fuehlerzahl(1) - das Stoerbyte 0xFF steht
         * also an Index 7. Bis 0.9.6 stand hier 5; dort liegt das hohe Byte
         * der zweiten Zellspannung (0x0C = 12). Die Pruefung verlangt aber
         * $zahl === 255 und stand deshalb auf JEDER Anlage auf FEHL - fuer
         * eine Korrektur, die in Wahrheit greift.
         *
         * Ein rotes Kreuz, das nichts bedeutet, ist schlimmer als keine
         * Pruefung: man sucht dann dort. */
        $pos = 7;
        $zahl = bm_u8($stoer, $pos);
        $platzt = ($pos + $zahl * 2) > strlen($stoer);
        // Weiterlesen muss scheitern und sich merken, dass es gescheitert ist
        $p2 = $pos;
        for ($t = 0; $t < $zahl; $t++) {
            bm_s16($stoer, $p2);
        }
        $pylOk = ($zahl === 255) && $platzt && bm_pyl_ende($p2);
        $pylMeldung = 'gelesene Fuehlerzahl ' . $zahl . ', Ueberlauf erkannt: '
                    . ($platzt ? 'ja' : 'NEIN') . ', Lesezeiger meldet Ende: '
                    . (bm_pyl_ende($p2) ? 'ja' : 'NEIN');
    }
    $zeilen[] = ($pylOk ? '[OK]   ' : '[FEHL] ') . 'Pylontech: Stoerbyte 0xFF als Fuehlerzahl '
              . 'wird abgefangen (' . $pylMeldung . ')';
    if (!$pylOk) {
        $fehler++;
    }
    // Gegenprobe am ganzen Parser: er muss einen Fehler MELDEN, nicht Zahlen
    // erfinden. bm_abruf_pylontech spricht selbst mit der Schnittstelle,
    // deshalb wird hier nur der Rahmen geprueft, den es dafuer braucht.
    $zeilen[] = '[INFO] Die Laengenpruefungen greifen an sechs Stellen je Modul: Zellzahl, '
              . 'Zellspannungen, Fuehlerzahl, Temperaturen, Kapazitaeten, erweiterte Kapazitaet';

    // 2. Zeilenumbrueche duerfen nicht ins UDP-Gateway.
    $roh = "Fehler in Zeile 1\nund Zeile 2\r\nund\tnoch was";
    $sauber = bm_mqtt_wert_saeubern($roh);
    $mqttOk = (strpos($sauber, "\n") === false && strpos($sauber, "\r") === false
               && strpos($sauber, "\t") === false && $sauber !== '');
    $zeilen[] = ($mqttOk ? '[OK]   ' : '[FEHL] ') . 'MQTT: Zeilenumbrueche werden entfernt -> "'
              . $sauber . '"';
    if (!$mqttOk) {
        $fehler++;
    }

    // 3. Ungueltiges UTF-8 darf json_encode nicht zu Fall bringen.
    $krumm = "stty: ung\xFCltiges Argument";
    $glatt = bm_text_sauber($krumm);
    $utfOk = (preg_match('//u', $glatt) === 1) && (json_encode(array('t' => $glatt)) !== false);
    $zeilen[] = ($utfOk ? '[OK]   ' : '[FEHL] ') . 'Fremder Text wird UTF-8-tauglich gemacht -> "'
              . $glatt . '"';
    if (!$utfOk) {
        $fehler++;
    }

    // 4. Unteilbares Schreiben mit eindeutiger Nebendatei.
    $probe = $p['datadir'] . '/selbsttest.json';
    $schreibOk = bm_json_schreiben($probe, array('a' => 1, 'b' => 'Grüße'));
    $gelesen = $schreibOk ? bm_json_lesen($probe) : array();
    $kaputt = bm_json_schreiben($probe, array('x' => "\xFF\xFE roh"));
    $danach = bm_json_lesen($probe);
    $atomOk = $schreibOk && isset($gelesen['a']) && $gelesen['a'] === 1
              && $kaputt === false && isset($danach['a']) && $danach['a'] === 1;
    $zeilen[] = ($atomOk ? '[OK]   ' : '[FEHL] ') . 'Unteilbares Schreiben: gueltiges JSON geht '
              . 'durch, ungueltiges wird abgelehnt und laesst den alten Inhalt stehen';
    if (!$atomOk) {
        $fehler++;
    }
    @unlink($probe);
    $liegen = count((array) glob($p['datadir'] . '/*.tmp.*'));
    $zeilen[] = ($liegen === 0 ? '[OK]   ' : '[WARN] ') . 'Liegengebliebene Nebendateien: ' . $liegen;

    // 5. Signalbehandlung
    if (function_exists('pcntl_async_signals')) {
        $zeilen[] = '[OK]   Asynchrone Signale verfuegbar - SIGTERM wirkt auch waehrend eines '
                  . 'laufenden Abrufs, nicht erst am Ende der Schleife';
    } else {
        $zeilen[] = '[WARN] pcntl_async_signals fehlt (PHP aelter als 7.1). Der Dienst sieht '
                  . 'dann nur einmal je Schleifendurchlauf nach Signalen.';
    }

    // 6. Serielle Schnittstellen
    if (function_exists('bm_serielle_geraete')) {
        $ser = bm_serielle_geraete();
        $stabil = 0;
        foreach ($ser as $si) {
            if ($si['stabil']) {
                $stabil++;
            }
        }
        $zeilen[] = '[INFO] Serielle Schnittstellen gefunden: ' . count($ser)
                  . ' (davon ' . $stabil . ' mit gleichbleibendem Namen unter /dev/serial/by-id/)';
        foreach ($geraete as $nr => $g) {
            if ($g['transport'] !== 'pylontech_rs485') {
                continue;
            }
            $besser = bm_serielle_empfehlung($g['geraetedatei']);
            if ($besser !== '') {
                $zeilen[] = '[WARN]      Speicher ' . $nr . ' steht auf ' . $g['geraetedatei']
                          . '. Dieser Name kann sich nach einem Neustart auf einen anderen '
                          . 'Adapter beziehen. Gleichbleibend waere: ' . $besser;
            }
        }
    }

    $zeilen[] = '';
    $zeilen[] = '[INFO] Takt ' . (int) $cfg['intervall'] . ' s, Zelldaten alle '
              . (int) $cfg['zelltakt'] . ' s, Zeitueberschreitung '
              . (int) $cfg['zeitueberschreitung'] . ' s';
    $zeilen[] = '[INFO] Schreibende Befehle: '
              . (!empty($cfg['steuerung_ein']) ? 'zugelassen' : 'gesperrt')
              . ', Totmannzeit ' . (int) $cfg['totmann'] . ' s'
              . ', Ladefenster ' . (int) $cfg['soc_min'] . ' bis ' . (int) $cfg['soc_max'] . ' %';
    $alter = bm_alter();
    $zeilen[] = $alter < 0 ? '[INFO] Es hat noch kein Abruf stattgefunden'
                           : '[INFO] Letztes Abbild vor ' . $alter . ' s geschrieben';

    /* --- Neu in 0.9.7. Alle drei ohne Geraet pruefbar, und alle drei so
     * beschaffen, dass ein Rueckfall im Betrieb NICHT auffiele: das Ergebnis
     * waere keine Fehlermeldung, sondern eine Zahl - nur die falsche. */
    $zeilen[] = '';
    $zeilen[] = 'Neu in 0.9.7:';

    // Registertyp f32. 0x42C8_0000 ist die 100.0 nach IEEE 754.
    $pf = array(200 => 0x42C8, 201 => 0x0000,      // 100.0, hohes Wort zuerst
                202 => 0x0000, 203 => 0x42C8,      // dasselbe, Worte vertauscht
                204 => 0x7FC0, 205 => 0x0000);     // NaN
    $f1 = bm_wert_aus($pf, array('reg' => 200, 'typ' => 'f32', 'faktor' => 1, 'nk' => 2));
    $f2 = bm_wert_aus($pf, array('reg' => 202, 'typ' => 'f32', 'faktor' => 1, 'nk' => 2,
                                 'wort' => 'nieder'));
    $f3 = bm_wert_aus($pf, array('reg' => 204, 'typ' => 'f32', 'faktor' => 1, 'nk' => 2));
    $fOk = ($f1 !== null && abs($f1 - 100.0) < 0.001)
        && ($f2 !== null && abs($f2 - 100.0) < 0.001)
        && ($f3 === null);
    $zeilen[] = ($fOk ? '[OK]   ' : '[FEHL] ') . 'Registertyp f32: 0x42C8_0000 = '
              . var_export($f1, true) . ' (erwartet 100), Worte vertauscht = '
              . var_export($f2, true) . ', NaN = ' . var_export($f3, true)
              . ' (erwartet NULL - NaN ist kein Messwert)';
    if (!$fOk) {
        $fehler++;
    }

    // Die Spiegelung der Schreibantwort. Modbus wiederholt bei FC 6 Register
    // und Wert; bis 0.9.6 wurde diese Antwort weggeworfen.
    $echoGut    = bm_echo_pruefen(pack('nn', 47075, 500), 47075, 500, 'den Wert');
    $echoAnders = bm_echo_pruefen(pack('nn', 47075, 300), 47075, 500, 'den Wert');
    $echoFalsch = bm_echo_pruefen(pack('nn', 47076, 500), 47075, 500, 'den Wert');
    $echoKurz   = bm_echo_pruefen('abc', 47075, 500, 'den Wert');
    $eOk = ($echoGut === true) && is_array($echoAnders) && is_array($echoFalsch)
        && is_array($echoKurz);
    $zeilen[] = ($eOk ? '[OK]   ' : '[FEHL] ') . 'Schreibbestaetigung: gleiche Antwort '
              . 'angenommen, gekappter Wert abgewiesen, falsches Register abgewiesen, '
              . 'zu kurze Antwort abgewiesen';
    if (!$eOk) {
        $fehler++;
    }

    // Der Zwangszustand als Zahl - der Text 'laden:500' taugt nicht als
    // Analogwert fuer einen virtuellen Eingang.
    $sOk = (bm_sollart('laden:500') === 1) && (bm_sollart('entladen:800') === 2)
        && (bm_sollart('sperren:0') === 3) && (bm_sollart('') === 0)
        && (bm_sollart('automatik') === 0);
    $zeilen[] = ($sOk ? '[OK]   ' : '[FEHL] ') . 'Zwangszustand als Zahl: laden 1, '
              . 'entladen 2, sperren 3, kein Zwang 0';
    if (!$sOk) {
        $fehler++;
    }

    /* --- Neu in 0.9.8. Auch diese drei lassen sich ohne Geraet pruefen. */
    $zeilen[] = '';
    $zeilen[] = 'Neu in 0.9.8:';

    /* Bits aus einer FC-1-Antwort. Der Prueffall stammt aus der
     * Modbus-Spezifikation: die Antwort CD 6B 05 auf 19 Spulen ab Adresse 20.
     * Niedrigstes Bit zuerst, also ergibt 0xCD die Folge 1 0 1 1 0 0 1 1. */
    $bits = bm_bits_auspacken("\xCD\x6B\x05", 20, 19);
    $erw = array(1,0,1,1,0,0,1,1, 1,1,0,1,0,1,1,0, 1,0,1);
    $bitOk = !isset($bits['_fehler']) && (array_values($bits) === $erw);
    $zeilen[] = ($bitOk ? '[OK]   ' : '[FEHL] ') . 'Modbus FC 1: CD 6B 05 ergibt 19 Bits ab '
              . 'Adresse 20 -> ' . implode('', array_values(is_array($bits)
                 && !isset($bits['_fehler']) ? $bits : array()))
              . ' (erwartet ' . implode('', $erw) . ')';
    if (!$bitOk) {
        $fehler++;
    }
    // Und die Gegenprobe: eine zu kurze Antwort muss abgewiesen werden, nicht
    // mit Nullen aufgefuellt.
    $kurzBits = bm_bits_auspacken("\xCD", 0, 19);
    $kurzOk = is_array($kurzBits) && isset($kurzBits['_fehler']);
    $zeilen[] = ($kurzOk ? '[OK]   ' : '[FEHL] ') . 'Modbus FC 1: eine zu kurze Bitantwort '
              . 'wird abgewiesen statt mit Nullen aufgefuellt';
    if (!$kurzOk) {
        $fehler++;
    }

    /* Restenergie und Restzeit. 10 kWh, 50 Prozent, 1000 W Entladung: 5 kWh
     * drin, das reicht 300 Minuten. Ohne Kapazitaet gibt es KEINE Zahl. */
    list($rk1, $rz1) = bm_restwerte(array('KAPAZ' => 10.0, 'SOC' => 50.0, 'PBAT' => -1000),
                                    array('nennkapaz' => 0.0));
    list($rk2, $rz2) = bm_restwerte(array('KAPAZ' => 10.0, 'SOC' => 50.0, 'PBAT' => 1000),
                                    array('nennkapaz' => 0.0));
    list($rk3, $rz3) = bm_restwerte(array('SOC' => 50.0, 'PBAT' => -1000),
                                    array('nennkapaz' => 0.0));
    list($rk4, $rz4) = bm_restwerte(array('KAPAZ' => 10.0, 'SOC' => 50.0, 'PBAT' => 3),
                                    array('nennkapaz' => 0.0));
    $restOk = ($rk1 === 5.0 && $rz1 === 300) && ($rk2 === 5.0 && $rz2 === 300)
           && ($rk3 === null && $rz3 === null) && ($rk4 === 5.0 && $rz4 === null);
    $zeilen[] = ($restOk ? '[OK]   ' : '[FEHL] ') . 'Restenergie und Restzeit: 10 kWh bei 50 % '
              . 'und 1000 W -> ' . var_export($rk1, true) . ' kWh, ' . var_export($rz1, true)
              . ' min; ohne Kapazitaet -> ' . var_export($rk3, true) . '; bei 3 W Ruhe -> '
              . var_export($rz4, true) . ' (jeweils NULL erwartet, keine erfundene Zahl)';
    if (!$restOk) {
        $fehler++;
    }

    /* Der Sammelmerker. Er darf NUR anschlagen, was gemessen vorliegt -
     * ein fehlender Wert erzeugt weder Alarm noch Entwarnung. */
    $acfg = array_merge(bm_vorgaben(), array('drift_warnung' => 50, 'temp_max' => 45,
                                             'temp_min' => 0));
    $a1 = bm_alarm(array('ok' => 1, 'UZDIFF' => 20, 'TMAX' => 30, 'TMIN' => 10), $acfg);
    $a2 = bm_alarm(array('ok' => 1, 'UZDIFF' => 120, 'TMAX' => 30, 'TMIN' => 10), $acfg);
    $a3 = bm_alarm(array('ok' => 1, 'TMAX' => 60, 'TMIN' => 10), $acfg);
    $a4 = bm_alarm(array('ok' => 1), $acfg);
    $a5 = bm_alarm(array('ok' => 0, 'fehlertext' => 'Zeitueberschreitung'), $acfg);
    $alOk = (count($a1) === 0) && (count($a2) === 1) && (count($a3) === 1)
         && (count($a4) === 0) && (count($a5) === 1);
    $zeilen[] = ($alOk ? '[OK]   ' : '[FEHL] ') . 'Sammelmerker: heil 0, Drift 1, zu warm 1, '
              . 'ohne Messwerte 0 (kein Alarm aus fehlenden Werten), stummes Geraet 1 - '
              . 'gemessen ' . count($a1) . '/' . count($a2) . '/' . count($a3) . '/'
              . count($a4) . '/' . count($a5);
    if (!$alOk) {
        $fehler++;
    }

    $zeilen[] = '';
    $zeilen[] = 'Nicht geprueft, weil dafuer ein Speicher noetig ist:';
    $zeilen[] = '  - ob die Registeradressen des gewaehlten Profils zu DIESER Firmware passen';
    $zeilen[] = '  - ob das Geraet den gewaehlten Uebertragungsweg spricht';
    $zeilen[] = '    (Modbus TCP mit MBAP-Kopf gegen Modbus RTU im TCP-Strom)';
    $zeilen[] = '  - ob die Schreibbefehle am Geraet wirken und was sie dort tatsaechlich tun';
    $zeilen[] = '  - in welcher Richtung das Geraet Strom und Leistung als positiv meldet';
    $zeilen[] = '  - ob die Pylontech-Rahmen von einem echten Modul angenommen werden';
    $zeilen[] = '    (der Rahmenbau ist hier nur gegen sich selbst geprueft, nicht gegen';
    $zeilen[] = '     die Originalbibliothek und nicht gegen ein Modul)';
    echo implode("\n", $zeilen) . "\n";
    return $fehler ? 1 : 0;
}

function bm_command_da($name)
{
    $a = array();
    @exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $a);
    return count($a) > 0;
}

/* ------------------------------------------------------------------ */

/* Nur ausfuehren, wenn die Datei unmittelbar aufgerufen wurde. Wird sie
 * eingebunden - etwa zum Pruefen der einzelnen Funktionen -, soll der Dienst
 * NICHT losrennen. */
$bm_direkt = !isset($_SERVER['SCRIPT_FILENAME'])
    || realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__);
if (!$bm_direkt) {
    return;
}

$bm_argv = isset($argv) ? $argv : array();
if (in_array('--selbsttest', $bm_argv, true)) {
    exit(bm_selbsttest());
}
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, 'bm_signal_behandeln');
    pcntl_signal(SIGINT, 'bm_signal_behandeln');
}
exit(bm_dienst_schleife(in_array('--einmal', $bm_argv, true)));
