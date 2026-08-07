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

function bm_signal_behandeln($signal)
{
    global $bm_laeuft;
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
    $s = bm_verbinden($g, $tmo);
    if (is_array($s)) {
        return $s;
    }
    $regs = array();
    foreach ((array) $pr['bloecke'] as $block) {
        $teil = bm_register_lesen($s, $g, (int) $block['fc'], (int) $block['start'],
                                  (int) $block['anzahl']);
        if (isset($teil['_fehler'])) {
            fclose($s);
            return array('_fehler' => 'Block ab Register ' . (int) $block['start'] . ': '
                . $teil['_fehler']);
        }
        $regs += $teil;
    }
    fclose($s);

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
    $s = bm_verbinden($g, $tmo);
    if (is_array($s)) {
        return $s;
    }
    $erg = bm_register_schreiben_mehrere($s, $g, (int) $z['anforderung'],
                                         array($bmsIndex, 0x8100));
    if (is_array($erg)) {
        fclose($s);
        return array('_fehler' => 'Die Zelldatenanforderung liess sich nicht absetzen: '
            . $erg['_fehler']);
    }
    $bereit = false;
    for ($i = 0; $i < 40; $i++) {          // hoechstens 4 Sekunden warten
        usleep(100000);
        $a = bm_register_lesen($s, $g, 3, (int) $z['antwort'], 1);
        if (isset($a['_fehler'])) {
            fclose($s);
            return array('_fehler' => 'Warten auf die Zelldaten: ' . $a['_fehler']);
        }
        if ((int) $a[(int) $z['antwort']] === 0x8801) {
            $bereit = true;
            break;
        }
    }
    if (!$bereit) {
        fclose($s);
        return array('_fehler' => 'Die BCU hat die Zelldatenanforderung nicht innerhalb von '
            . '4 s bestaetigt. Das passiert regelmaessig, wenn eine zweite Anwendung '
            . '(BE Connect Plus, Node-RED) gerade mit der Box spricht.');
    }
    $daten = array();
    for ($b = 0; $b < (int) $z['bloecke']; $b++) {
        $teil = bm_register_lesen($s, $g, 3, (int) $z['daten'], (int) $z['blockgroesse']);
        if (isset($teil['_fehler'])) {
            fclose($s);
            return array('_fehler' => 'Zelldatenblock ' . ($b + 1) . ': ' . $teil['_fehler']);
        }
        $folge = array_values($teil);
        array_shift($folge);               // erstes Wort ist die Paketlaenge
        $daten = array_merge($daten, $folge);
    }
    fclose($s);

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

/** Vorzeichenbehaftete 16-Bit-Zahl aus einem Bytestrom. */
function bm_s16(&$roh, &$pos)
{
    $w = (ord($roh[$pos]) << 8) | ord($roh[$pos + 1]);
    $pos += 2;
    return $w >= 0x8000 ? $w - 0x10000 : $w;
}

function bm_u16(&$roh, &$pos)
{
    $w = (ord($roh[$pos]) << 8) | ord($roh[$pos + 1]);
    $pos += 2;
    return $w;
}

function bm_u24(&$roh, &$pos)
{
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
        if ($pos + 1 > strlen($roh)) {
            return array('_fehler' => 'Die Antwort bricht bei Modul ' . $m . ' ab.');
        }
        $zellzahl = ord($roh[$pos]);
        $pos++;
        if ($zellzahl < 1 || $zellzahl > 32 || $pos + $zellzahl * 2 > strlen($roh)) {
            return array('_fehler' => 'Modul ' . $m . ' meldet ' . $zellzahl . ' Zellen - '
                . 'das passt nicht zur Laenge der Antwort.');
        }
        $zellen = array();
        for ($z = 1; $z <= $zellzahl; $z++) {
            $zellen[$z] = bm_s16($roh, $pos);
        }
        $tempzahl = ord($roh[$pos]);
        $pos++;
        $temps = array();
        for ($t = 0; $t < $tempzahl; $t++) {
            $temps[] = round((bm_s16($roh, $pos) - 2731) / 10.0, 1);
        }
        $strom = bm_s16($roh, $pos) / 10.0;
        $spannung = bm_u16($roh, $pos) / 1000.0;
        $rest = bm_u16($roh, $pos) / 1000.0;
        $eigene = ord($roh[$pos]);
        $pos++;
        $gesamt = bm_u16($roh, $pos) / 1000.0;
        $zyklen = bm_u16($roh, $pos);
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
    if (!isset($st[$aktion]['schritte']) || !is_array($st[$aktion]['schritte'])) {
        return array(0, sprintf(bm_t('DIENST.STEUERUNG_UNBEKANNT'), $aktion, $pr['name']));
    }
    if ($g['transport'] === 'pylontech_rs485') {
        return array(0, bm_t('DIENST.STEUERUNG_SERIELL'));
    }

    $s = bm_verbinden($g, (int) $cfg['zeitueberschreitung']);
    if (is_array($s)) {
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
            fclose($s);
            return array(0, sprintf(bm_t('DIENST.STEUERUNG_SCHRITT'), $nr,
                (int) $schritt['reg'], $erg['_fehler']));
        }
        usleep(150000);   // manche Geraete brauchen eine Atempause
    }
    fclose($s);
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

function bm_soll_schreiben($nr, $aktion, $watt)
{
    return bm_json_schreiben(bm_soll_datei($nr), array(
        'aktion' => $aktion,
        'watt'   => (int) $watt,
        'ts'     => time(),
    ));
}

function bm_soll_loeschen($nr)
{
    @unlink(bm_soll_datei($nr));
}

/* ==================================================================
 * Ein Durchlauf
 * ================================================================== */

function bm_durchlauf(&$letzteZellen)
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

        if ($g['transport'] === 'pylontech_rs485') {
            $erg = bm_abruf_pylontech($g, $pr);
            if (isset($erg['_fehler'])) {
                $eintrag['fehlertext'] = $erg['_fehler'];
                $stoerung = $g['name'] . ': ' . $erg['_fehler'];
            } else {
                foreach ($erg['werte'] as $k => $v) {
                    $eintrag[$k] = $v;
                }
                $eintrag['module'] = $erg['module'];
                $eintrag['ok'] = 1;
            }
        } else {
            $erg = bm_abruf_modbus($g, $pr, $tmo);
            if (isset($erg['_fehler'])) {
                $eintrag['fehlertext'] = $erg['_fehler'];
                $stoerung = $g['name'] . ': ' . $erg['_fehler'];
            } else {
                foreach ($erg['werte'] as $k => $v) {
                    $eintrag[$k] = $v;
                }
                $eintrag['ok'] = 1;

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

        // Sollwert und Totmannschaltung
        $soll = bm_soll_lesen($nr);
        if ($soll && isset($soll['aktion'])) {
            $alterSoll = time() - (int) $soll['ts'];
            $eintrag['sollwert'] = $soll['aktion'] . ':' . (int) $soll['watt'];
            $eintrag['sollwert_alter'] = $alterSoll;
            $tot = max(0, (int) $cfg['totmann']);
            if ($tot > 0 && $alterSoll > $tot) {
                list($ok, $meldung) = bm_steuern($g, $pr, 'automatik', 0);
                bm_soll_loeschen($nr);
                bm_log($g['name'] . ': Totmannschaltung nach ' . $alterSoll . ' s ohne '
                    . 'Lebenszeichen - zurueck in die Automatik. ' . $meldung);
                $eintrag['sollwert'] = '';
                $eintrag['sollwert_alter'] = -1;
            }
        } else {
            $eintrag['sollwert'] = '';
            $eintrag['sollwert_alter'] = -1;
        }

        if ($eintrag['ok']) {
            $okZahl++;
            $eintrag['OK'] = 1;
            bm_verlauf_schreiben($nr, $eintrag);
        } else {
            $eintrag['OK'] = 0;
        }
        $eintrag['ALTER'] = 0;
        $abbild['geraete'][$nr] = $eintrag;
    }

    $abbild['ok'] = ($geraete && $okZahl === count($geraete)) ? 1 : 0;
    bm_json_schreiben(bm_paths()['datadir'] . '/loxone.json', $abbild);
    bm_json_schreiben(bm_paths()['datadir'] . '/zustand.json', array(
        'ts'     => time(),
        'fehler' => $stoerung,
        'ok'     => $okZahl,
        'gesamt' => count($geraete),
    ));

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
    @file_put_contents($datei, time() . ';' . $e['SOC'] . ';'
        . ($e['PBAT'] === null ? '' : $e['PBAT']) . "\n", FILE_APPEND);

    // Alte Tagesdateien wegraeumen
    $tage = max(1, min(365, (int) $cfg['verlauf_tage']));
    foreach ((array) glob($ordner . '/geraet*_*.csv') as $alt) {
        if (time() - filemtime($alt) > $tage * 86400) {
            @unlink($alt);
        }
    }
}

/** Das Abbild ueber das MQTT-Gateway veroeffentlichen. */
function bm_veroeffentlichen(array $abbild, $topic)
{
    $topic = trim($topic, '/');
    if ($topic === '') {
        $topic = 'batteriebms';
    }
    $paare = array(
        'ok'      => (int) $abbild['ok'],
        'geraete' => count($abbild['geraete']),
    );
    foreach ($abbild['geraete'] as $nr => $e) {
        $vor = 'geraet' . (int) $nr;
        foreach (array('SOC' => 'soc', 'SOH' => 'soh', 'UBAT' => 'ubat', 'IBAT' => 'ibat',
                       'PBAT' => 'pbat', 'TMAX' => 'tmax', 'TMIN' => 'tmin',
                       'UZMAX' => 'uzmax', 'UZMIN' => 'uzmin', 'UZDIFF' => 'uzdiff',
                       'ZYKLEN' => 'zyklen', 'KAPAZ' => 'kapaz', 'MODULE' => 'module',
                       'ZELLEN' => 'zellen', 'FEHLER' => 'fehler', 'WARNUNG' => 'warnung',
                       'MODUS' => 'modus') as $feld => $thema) {
            $paare[$vor . '/' . $thema] = $e[$feld];
        }
        $paare[$vor . '/ok'] = (int) $e['ok'];
        $paare[$vor . '/sollwert'] = $e['sollwert'] !== '' ? $e['sollwert'] : 'automatik';
        $paare[$vor . '/sollwert_alter'] = (int) $e['sollwert_alter'];
        foreach ((array) $e['module'] as $m => $md) {
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
    bm_mqtt_senden($paare, $topic);
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
        $s = bm_verbinden($g, (int) $cfg['zeitueberschreitung']);
        if (is_array($s)) {
            return array(0, $s['_fehler']);
        }
        $start = isset($befehl['start']) ? (int) $befehl['start'] : 0;
        $anzahl = max(1, min(64, isset($befehl['anzahl']) ? (int) $befehl['anzahl'] : 8));
        $fc = (isset($befehl['fc']) && (int) $befehl['fc'] === 4) ? 4 : 3;
        $regs = bm_register_lesen($s, $g, $fc, $start, $anzahl);
        fclose($s);
        if (isset($regs['_fehler'])) {
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
        bm_soll_schreiben($nr, $soll['aktion'], (int) $soll['watt']);
        return array(1, sprintf(bm_t('DIENST.LEBENSZEICHEN_OK'), (int) $cfg['totmann']));
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
        bm_soll_schreiben($nr, $aktion, $watt);
        return array(1, sprintf(bm_t('DIENST.BREMSE'), $rest));
    }

    list($ok, $meldung) = bm_steuern($g, $pr, $aktion, $watt);
    if ($ok) {
        $letzteSchreibzeit[$nr] = time();
        bm_soll_schreiben($nr, $aktion, $watt);
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
    $naechster = 0;

    while ($bm_laeuft) {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        $cfg = bm_config();
        $sofort = bm_warteschlange($letzteSchreibzeit);
        if ($sofort || time() >= $naechster) {
            bm_durchlauf($letzteZellen);
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
