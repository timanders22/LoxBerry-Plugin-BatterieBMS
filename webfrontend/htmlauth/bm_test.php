<?php
/**
 * Batterie-Heimspeicher (BMS) - die Aktionen des Reiters Test
 *
 * Die Selbstpruefung beantwortet OHNE Loxone, ob die Einrichtung traegt. Was
 * sich nur mit Speicher pruefen liesse, wird als solches benannt statt
 * geraten.
 */

function bm_pruefzeile($stand, $frage, $antwort)
{
    return array('stand' => $stand, 'frage' => $frage, 'antwort' => $antwort);
}

function bm_pruefungen()
{
    $p = bm_paths();
    $cfg = bm_config();
    $geraete = bm_geraete();
    $werte = bm_werte();
    $profile = bm_profile();
    $zeilen = array();

    $pid = bm_dienst_pid();
    $zeilen[] = bm_pruefzeile($pid > 0 ? 1 : 0, bm_t('TEST.F_DIENST'),
        $pid > 0 ? bm_t('TEST.A_DIENST_LAEUFT') . ' ' . $pid
                 : (bm_dienst_soll() ? bm_t('TEST.A_DIENST_SOLL_TOT') : bm_t('TEST.A_DIENST_GESTOPPT')));

    $zeilen[] = bm_pruefzeile(count($geraete) > 0 ? 1 : 0, bm_t('TEST.F_GERAETE'),
        count($geraete) > 0 ? sprintf(bm_t('TEST.A_GERAETE'), count($geraete))
                            : bm_t('TEST.A_KEINE_GERAETE'));

    // Rechte der Konfiguration: dort steht das Aktionstoken.
    $rechte = is_file($p['config']) ? (fileperms($p['config']) & 0777) : -1;
    if ($rechte < 0) {
        $zeilen[] = bm_pruefzeile(0, bm_t('TEST.F_RECHTE'), bm_t('TEST.A_RECHTE_FEHLT'));
    } elseif (($rechte & 0077) === 0) {
        $zeilen[] = bm_pruefzeile(1, bm_t('TEST.F_RECHTE'),
            sprintf(bm_t('TEST.A_RECHTE_OK'), '0' . decoct($rechte)));
    } else {
        $zeilen[] = bm_pruefzeile(0, bm_t('TEST.F_RECHTE'),
            sprintf(bm_t('TEST.A_RECHTE_OFFEN'), '0' . decoct($rechte)));
    }

    // Je Speicher: antwortet er, und ist sein Profil geprueft?
    foreach ($werte as $nr => $w) {
        $zeilen[] = bm_pruefzeile($w['ok'] ? 1 : 0,
            bm_e($w['name']) . ' <span class="sm-mono">' . bm_e($w['transport']) . '</span>',
            $w['ok'] ? sprintf(bm_t('TEST.A_GERAET_OK'),
                          $w['SOC'] === null ? '&ndash;' : bm_e($w['SOC']),
                          $w['SOH'] === null ? '&ndash;' : bm_e($w['SOH']))
                     : bm_e($w['fehlertext'] !== '' ? $w['fehlertext'] : bm_t('TEST.A_GERAET_NIE')));
    }
    foreach ($geraete as $nr => $g) {
        $pr = isset($profile[$g['profil']]) ? $profile[$g['profil']] : null;
        if ($pr === null) {
            continue;
        }
        if ($pr['stand'] !== 'dokumentiert') {
            $zeilen[] = bm_pruefzeile(0,
                sprintf(bm_t('TEST.F_PROFILSTAND'), bm_e($g['name'])),
                sprintf(bm_t('TEST.A_PROFIL_UNBESTAETIGT'), bm_e($g['profil'])));
        } else {
            $zeilen[] = bm_pruefzeile(1,
                sprintf(bm_t('TEST.F_PROFILSTAND'), bm_e($g['name'])),
                bm_e($pr['quelle']));
        }
        if ($g['transport'] === 'pylontech_rs485') {
            $dev = $g['geraetedatei'];
            if (!file_exists($dev)) {
                $zeilen[] = bm_pruefzeile(0, bm_t('TEST.F_SERIELL'),
                    sprintf(bm_t('TEST.A_SERIELL_FEHLT'), bm_e($dev)));
            } elseif (!is_readable($dev) || !is_writable($dev)) {
                $zeilen[] = bm_pruefzeile(0, bm_t('TEST.F_SERIELL'),
                    sprintf(bm_t('TEST.A_SERIELL_RECHTE'), bm_e($dev)));
            } else {
                $zeilen[] = bm_pruefzeile(1, bm_t('TEST.F_SERIELL'),
                    sprintf(bm_t('TEST.A_SERIELL_OK'), bm_e($dev), (int) $g['baud']));
            }
        }
        // Zellspannungsdrift: das ist der Wert, wegen dessen man ein BMS
        // ueberhaupt ausliest.
        $d = isset($werte[$nr]['UZDIFF']) ? $werte[$nr]['UZDIFF'] : null;
        if ($d !== null) {
            $grenze = max(1, (int) $cfg['drift_warnung']);
            $zeilen[] = bm_pruefzeile($d <= $grenze ? 1 : 0,
                sprintf(bm_t('TEST.F_DRIFT'), bm_e($g['name'])),
                sprintf(bm_t('TEST.A_DRIFT'), bm_e($d), $grenze));
        }
    }

    $zu = bm_zustand();
    if (!empty($zu['fehler'])) {
        $zeilen[] = bm_pruefzeile(0, bm_t('TEST.F_LETZTER_FEHLER'), bm_e($zu['fehler']));
    }

    $m = bm_mqtt_zustand();
    if (!$m['gefunden']) {
        $zeilen[] = bm_pruefzeile(0, bm_t('TEST.F_MQTT'), bm_t('TEST.A_MQTT_NICHT_GEFUNDEN'));
    } elseif ($m['autostart']) {
        $zeilen[] = bm_pruefzeile(1, bm_t('TEST.F_MQTT'),
            bm_e($m['broker']) . ':' . bm_e($m['brokerport']) . ' (UDP ' . (int) $m['udpport'] . ')');
    } else {
        $zeilen[] = bm_pruefzeile(0, bm_t('TEST.F_MQTT'), bm_t('TEST.A_MQTT_AUS'));
    }
    $zeilen[] = bm_pruefzeile(extension_loaded('sockets') ? 1 : (!empty($cfg['mqtt_ein']) ? 0 : -1),
        bm_t('TEST.F_SOCKETS'),
        extension_loaded('sockets') ? bm_t('TEST.A_SOCKETS_DA') : bm_t('TEST.A_SOCKETS_FEHLT'));

    $zeilen[] = bm_pruefzeile(!empty($cfg['steuerung_ein']) ? 1 : -1, bm_t('TEST.F_STEUERUNG'),
        !empty($cfg['steuerung_ein']) ? bm_t('TEST.A_STEUERUNG_EIN') : bm_t('TEST.A_STEUERUNG_AUS'));

    $zeilen[] = bm_pruefzeile(-1, bm_t('TEST.F_TOTMANN'),
        sprintf(bm_t('TEST.A_TOTMANN'), (int) $cfg['totmann'],
                (int) $cfg['soc_min'], (int) $cfg['soc_max']));

    // Die Loxone-Vorlage gegen den Parser halten - wohlgeformt oder nicht.
    // Das gehoert hierher und nicht erst in die Pflichtpruefung: der Anwender
    // merkt eine kaputte Vorlage sonst erst in Loxone Config, und dort sucht
    // er den Fehler bei sich.
    list($vname, $vinhalt) = bm_vorlage_eingaenge(1);
    $vorher = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($vinhalt);
    libxml_clear_errors();
    libxml_use_internal_errors($vorher);
    $zeilen[] = bm_pruefzeile($xml !== false ? 1 : 0, bm_t('TEST.F_XML'),
        $xml !== false ? sprintf(bm_t('TEST.A_XML_OK'), bm_e($vname), count($xml->children()))
                       : bm_t('TEST.A_XML_KAPUTT'));

    /* ---------------------------------------------------------------
     * Vier Pruefungen zu den Korrekturen der Fassung 0.9.7.
     *
     * Alle vier sind in beide Richtungen geeicht: mit der Korrektur gruen,
     * mit zurueckgebauter Korrektur rot. Eine Pruefung, die auch ohne die
     * Korrektur bestanden haette, prueft nichts.
     * --------------------------------------------------------------- */

    /* Arbeitet der Dienst noch, oder lebt nur sein Prozess?
     *
     * Dieselbe Frage stellt der Waechter in bin/dienst.sh, und er benutzt
     * dieselbe Grenze: das Fuenffache des Takts, mindestens 180 s. Wer eine
     * der beiden Stellen aendert, aendert die andere mit. */
    $abbildalter = bm_alter();
    $grenze = max(180, 5 * max(5, (int) $cfg['intervall']));
    if ($abbildalter < 0) {
        $zeilen[] = bm_pruefzeile(-1, bm_t('TEST.F_ABBILD'), bm_t('TEST.A_ABBILD_NIE'));
    } else {
        $zeilen[] = bm_pruefzeile($abbildalter <= $grenze ? 1 : 0, bm_t('TEST.F_ABBILD'),
            sprintf(bm_t('TEST.A_ABBILD'), (int) $abbildalter, (int) $grenze));
    }

    /* Traegt die MQTT-Veroeffentlichung einen Zeitstempel?
     *
     * Ohne ihn ist ein toter Dienst von einem gesunden nicht zu unterscheiden:
     * es wird nichts mehr gesendet, die letzten Werte stehen weiter im Broker.
     * Gerechnet wird ohne zu senden - eine Pruefung darf nichts ausloesen. */
    $paare = bm_mqtt_paare(bm_loxone(), false);
    $ts = isset($paare['ts']) ? (int) $paare['ts'] : 0;
    if ($abbildalter < 0) {
        $zeilen[] = bm_pruefzeile(-1, bm_t('TEST.F_MQTT_TS'), bm_t('TEST.A_MQTT_TS_NIE'));
    } else {
        $zeilen[] = bm_pruefzeile($ts > 0 ? 1 : 0, bm_t('TEST.F_MQTT_TS'),
            $ts > 0 ? sprintf(bm_t('TEST.A_MQTT_TS_OK'), date('d.m.Y H:i:s', $ts))
                    : bm_t('TEST.A_MQTT_TS_FEHLT'));
    }

    /* Bekommt jeder virtuelle Eingang der Importdatei auch einen Wert?
     *
     * Gelesen wird aus der ERZEUGTEN Datei, nicht aus der Feldliste - sonst
     * prueft sich die Auswahl selbst. ALTER, OK, SOLLART und SOLLALTER bildet
     * der Endpunkt, die stehen nie im Abbild. */
    $leer = array();
    $gemessene = 0;
    foreach ($geraete as $nr => $g) {
        if (!bm_felder_gemessen($nr)) {
            continue;   // ohne eine einzige Messung laesst sich das nicht sagen
        }
        $gemessene++;
        list($vn, $vi) = bm_vorlage_eingaenge($nr);
        $tr = array();
        preg_match_all('/Title="BMS_[0-9]+_([A-Z]+)"/', $vi, $tr);
        foreach ((array) (isset($tr[1]) ? $tr[1] : array()) as $feld) {
            if (in_array($feld, array('ALTER', 'OK', 'SOLLART', 'SOLLALTER'), true)) {
                continue;
            }
            if (!isset($werte[$nr][$feld]) || $werte[$nr][$feld] === null) {
                $leer[] = bm_e($g['name']) . ': ' . bm_e($feld);
            }
        }
    }
    if ($gemessene === 0) {
        $zeilen[] = bm_pruefzeile(-1, bm_t('TEST.F_VORLAGE_LEER'),
            bm_t('TEST.A_VORLAGE_LEER_NIE'));
    } else {
        $zeilen[] = bm_pruefzeile($leer ? 0 : 1, bm_t('TEST.F_VORLAGE_LEER'),
            $leer ? sprintf(bm_t('TEST.A_VORLAGE_LEER'), implode(', ', $leer))
                  : sprintf(bm_t('TEST.A_VORLAGE_LEER_OK'), $gemessene));
    }

    /* Antwortet der eigene Endpunkt wirklich? (B8)
     *
     * Alle uebrigen Zeilen sehen sich Dateien an. Diese eine spricht die
     * Stelle an, die spaeter der Miniserver anspricht - und nur sie findet
     * die Klasse, bei der html/ und htmlauth/ installiert in getrennten
     * Baeumen liegen. Drei Ausgaenge: geantwortet und plausibel, geantwortet
     * und falsch, NICHT FESTSTELLBAR. Der dritte ist Pflicht - 'ich kann es
     * nicht messen' darf nicht wie 'in Ordnung' aussehen. */
    list($ep_stand, $ep_code, $ep_text, $ep_url) = bm_selbsttest_endpunkt('status');
    $zeilen[] = bm_pruefzeile($ep_stand, bm_t('TEST.F_ENDPUNKT'),
        $ep_stand === 1 ? sprintf(bm_t('TEST.A_EP_OK'), (int) $ep_code, bm_e($ep_text))
        : ($ep_stand === 0 ? sprintf(bm_t('TEST.A_EP_FALSCH'), (int) $ep_code, bm_e($ep_text))
                           : bm_e($ep_text)));

    /* Liegt an einem Speicher etwas an? (B20) */
    $alarme = array();
    foreach ($werte as $nr => $w) {
        $gr = bm_alarm($w, $cfg);
        if ($gr) {
            $alarme[] = bm_e($w['name']) . ': ' . bm_e(implode(', ', $gr));
        }
    }
    if (!$werte) {
        $zeilen[] = bm_pruefzeile(-1, bm_t('TEST.F_ALARM'), bm_t('TEST.A_ALARM_NIE'));
    } else {
        $zeilen[] = bm_pruefzeile($alarme ? 0 : 1, bm_t('TEST.F_ALARM'),
            $alarme ? implode(' &middot; ', $alarme)
                    : sprintf(bm_t('TEST.A_ALARM_KEINER'), count($werte)));
    }

    /* Laeuft gerade ein Mitschnitt? Er soll nicht aus Versehen stehenbleiben. */
    $mrest = bm_mitschnitt_laeuft($cfg);
    $zeilen[] = bm_pruefzeile($mrest > 0 ? 0 : -1, bm_t('TEST.F_MITSCHNITT'),
        $mrest > 0 ? sprintf(bm_t('TEST.A_MITSCHNITT_AN'), (int) $mrest)
                   : bm_t('TEST.A_MITSCHNITT_AUS'));

    /* Steht der Zwangszustand in der Importdatei?
     *
     * Der Endpunkt sendet ihn seit jeher; in die Vorlage kam er bis 0.9.6
     * nicht. Wer die Vorlage benutzte, hatte ihn also gerade nicht. */
    list($vn2, $vi2) = bm_vorlage_eingaenge(1);
    $hatSoll = (strpos($vi2, 'SOLLART=') !== false) && (strpos($vi2, 'SOLLALTER=') !== false);
    $zeilen[] = bm_pruefzeile($hatSoll ? 1 : 0, bm_t('TEST.F_VORLAGE_SOLL'),
        $hatSoll ? bm_t('TEST.A_VORLAGE_SOLL_OK') : bm_t('TEST.A_VORLAGE_SOLL_FEHLT'));

    return $zeilen;
}

/** Ausgabe von bms_dienst.php --selbsttest. */
function bm_selbsttest_ausgabe()
{
    $p = bm_paths();
    $skript = $p['bindir'] . '/bms_dienst.php';
    if (!is_file($skript)) {
        return "[FEHL] bms_dienst.php fehlt.\n       Erwartet: " . $skript
             . "\n       Abhilfe: Plugin neu installieren.";
    }
    $ausgabe = array();
    @exec('php ' . escapeshellarg($skript) . ' --selbsttest 2>&1', $ausgabe);
    return implode("\n", $ausgabe);
}

/**
 * Fuehrt eine Aktion des Reiters Test aus.
 * Rueckgabe: array(stand, Meldung) - stand wie bei bm_befehl_absetzen.
 */
function bm_test_aktion($aktion)
{
    $nr = isset($_POST['test_geraet']) ? (string) $_POST['test_geraet'] : '1';
    if (!preg_match('/^[0-9]{1,2}$/', $nr)) {
        return array(0, bm_t('TEST.M_GERAET_UNGUELTIG'));
    }
    switch ($aktion) {
        case 'abruf':
            return bm_befehl_absetzen(array('aktion' => 'abruf'), 12);

        case 'automatik':
            return bm_befehl_absetzen(array('aktion' => 'automatik', 'geraet' => (int) $nr));

        case 'roh':
            $start = isset($_POST['roh_start']) ? trim((string) $_POST['roh_start']) : '';
            $anzahl = isset($_POST['roh_anzahl']) ? trim((string) $_POST['roh_anzahl']) : '8';
            // Hexadezimal ist bei Registerlisten ueblich - deshalb wird es
            // angenommen, aber ausdruecklich als solches erkannt und nicht
            // durch Zeichenentfernen zurechtgebogen.
            if (preg_match('/^0[xX][0-9A-Fa-f]{1,4}$/', $start)) {
                $start = hexdec(substr($start, 2));
            } elseif (preg_match('/^[0-9]{1,5}$/', $start)) {
                $start = (int) $start;
            } else {
                return array(0, bm_t('TEST.M_START_UNGUELTIG'));
            }
            if (!preg_match('/^[0-9]{1,2}$/', $anzahl) || (int) $anzahl < 1) {
                return array(0, bm_t('TEST.M_ANZAHL_UNGUELTIG'));
            }
            $fc = (isset($_POST['roh_fc']) && (string) $_POST['roh_fc'] === '4') ? 4 : 3;
            return bm_befehl_absetzen(array('aktion' => 'roh', 'geraet' => (int) $nr,
                'start' => (int) $start, 'anzahl' => (int) $anzahl, 'fc' => $fc), 12);

        case 'laden':
        case 'entladen':
            $watt = isset($_POST['test_watt']) ? (string) $_POST['test_watt'] : '';
            if (!preg_match('/^[0-9]{1,5}$/', $watt)) {
                return array(0, bm_t('TEST.M_WATT_UNGUELTIG'));
            }
            return bm_befehl_absetzen(array('aktion' => $aktion, 'geraet' => (int) $nr,
                'watt' => (int) $watt));

        default:
            return array(0, bm_t('TEST.M_UNBEKANNT'));
    }
}

/** Mini-SVG: Ladezustand ueber den heutigen Tag (0 bis 24 h, 0 bis 100 %). */
function bm_soc_svg($punkte, $tag = '')
{
    $w = 720; $h = 120; $x0 = 34; $y0 = 8; $pw = $w - $x0 - 8; $ph = $h - $y0 - 20;
    // Der Tagesanfang richtet sich nach dem GEZEIGTEN Tag, nicht nach heute -
    // sonst faellt bei der Tagesauswahl jeder Punkt aus dem Bild.
    $tag0 = (preg_match('/^[0-9]{8}$/', (string) $tag))
        ? (int) strtotime(substr($tag, 0, 4) . '-' . substr($tag, 4, 2) . '-' . substr($tag, 6, 2))
        : strtotime('today 00:00');
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%;max-width:' . $w
         . 'px;height:auto;background:#fafafa;border:1px solid #e0e0e0;border-radius:8px;"'
         . ' xmlns="http://www.w3.org/2000/svg">';
    foreach (array(0, 25, 50, 75, 100) as $pct) {
        $y = $y0 + $ph - $ph * $pct / 100;
        $svg .= '<line x1="' . $x0 . '" y1="' . $y . '" x2="' . ($x0 + $pw) . '" y2="' . $y
              . '" stroke="#e5e5e5" stroke-width="1"/>';
        $svg .= '<text x="' . ($x0 - 5) . '" y="' . ($y + 3)
              . '" font-size="9" fill="#999" text-anchor="end">' . $pct . '</text>';
    }
    foreach (array(0, 6, 12, 18, 24) as $hh) {
        $x = $x0 + $pw * $hh / 24;
        $svg .= '<line x1="' . $x . '" y1="' . $y0 . '" x2="' . $x . '" y2="' . ($y0 + $ph)
              . '" stroke="#eeeeee" stroke-width="1"/>';
        $svg .= '<text x="' . $x . '" y="' . ($h - 6)
              . '" font-size="9" fill="#999" text-anchor="middle">' . $hh . ':00</text>';
    }
    $poly = array();
    foreach ($punkte as $pt) {
        $anteil = ($pt[0] - $tag0) / 86400;
        if ($anteil < 0 || $anteil > 1) {
            continue;
        }
        $poly[] = round($x0 + $pw * $anteil, 1) . ','
                . round($y0 + $ph - $ph * max(0, min(100, $pt[1])) / 100, 1);
    }
    if (count($poly) >= 2) {
        $erst = explode(',', $poly[0]);
        $letzt = explode(',', $poly[count($poly) - 1]);
        $svg .= '<polygon points="' . $erst[0] . ',' . ($y0 + $ph) . ' ' . implode(' ', $poly) . ' '
              . $letzt[0] . ',' . ($y0 + $ph) . '" fill="#6dac20" opacity="0.15"/>';
        $svg .= '<polyline points="' . implode(' ', $poly) . '" fill="none" stroke="#6dac20" stroke-width="2"/>';
        $svg .= '<circle cx="' . $letzt[0] . '" cy="' . $letzt[1] . '" r="3" fill="#6dac20"/>';
    } else {
        $svg .= '<text x="' . ($x0 + $pw / 2) . '" y="' . ($y0 + $ph / 2)
              . '" font-size="11" fill="#aaa" text-anchor="middle">'
              . bm_e(bm_t('TEST.KEINE_MESSPUNKTE')) . '</text>';
    }
    return $svg . '</svg>';
}

/**
 * Balkenbild der Zellspannungen eines Moduls.
 *
 * Die Skala setzt bei der kleinsten Zelle an, nicht bei 0 - sonst sind alle
 * Balken gleich hoch und man sieht die Drift nicht, um die es geht.
 */
function bm_zellen_svg(array $zellen, $breite = 720)
{
    if (!$zellen) {
        return '';
    }
    $n = count($zellen);
    $min = min($zellen);
    $max = max($zellen);
    $spanne = max(10, $max - $min);          // mindestens 10 mV, sonst Rauschen
    $unten = $min - $spanne * 0.3;
    $oben = $max + $spanne * 0.3;
    $h = 110; $y0 = 6; $ph = $h - $y0 - 18;
    $bw = max(4, ($breite - 8) / $n - 3);
    $svg = '<svg viewBox="0 0 ' . $breite . ' ' . $h . '" style="width:100%;max-width:'
         . $breite . 'px;height:auto;background:#fafafa;border:1px solid #e0e0e0;'
         . 'border-radius:8px;" xmlns="http://www.w3.org/2000/svg">';
    $i = 0;
    foreach ($zellen as $nr => $mv) {
        $anteil = ($mv - $unten) / max(1, $oben - $unten);
        $bh = max(2, $ph * $anteil);
        $x = 4 + $i * (($breite - 8) / $n);
        $y = $y0 + $ph - $bh;
        // Die niedrigste und die hoechste Zelle abheben - das sind die
        // beiden, die man sucht.
        $farbe = ($mv === $min) ? '#e0620d' : (($mv === $max) ? '#546e7a' : '#6dac20');
        $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($bw, 1)
              . '" height="' . round($bh, 1) . '" fill="' . $farbe . '" rx="2"/>';
        if ($n <= 20) {
            $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($h - 5)
                  . '" font-size="8" fill="#999" text-anchor="middle">' . (int) $nr . '</text>';
        }
        $i++;
    }
    $svg .= '<text x="' . ($breite - 6) . '" y="' . ($y0 + 10)
          . '" font-size="9" fill="#777" text-anchor="end">' . (int) $min . ' &#8211; '
          . (int) $max . ' mV</text>';
    return $svg . '</svg>';
}
