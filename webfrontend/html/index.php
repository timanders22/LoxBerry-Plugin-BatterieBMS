<?php
/**
 * Batterie-Heimspeicher (BMS) - Endpunkt fuer den Miniserver
 *
 * Liegt im unangemeldeten Bereich, damit Loxone ihn ohne Zugangsdaten
 * erreicht, und ist deshalb durch ein Token geschuetzt. Verglichen wird mit
 * hash_equals, also in gleichbleibender Zeit - ein einfaches == liesse sich
 * ueber die Antwortzeit Zeichen fuer Zeichen erraten.
 *
 *   /plugins/<ordner>/index.php?token=<TOKEN>&aktion=<Befehl>
 *
 * Lesend:
 *   status  [&geraet=N]   alle Messgroessen eines Speichers
 *   zellen  [&geraet=N]   Spannungsspanne je Modul
 *   liste                 alle eingerichteten Speicher
 *   roh                   vollstaendiges Abbild als JSON
 *
 * Schaltend (nur wenn zugelassen):
 *   laden          &watt=W  [&geraet=N]   Laden erzwingen; watt=0 gibt die Regie zurueck
 *   entladen       &watt=W  [&geraet=N]   Entladen erzwingen
 *   automatik               [&geraet=N]   Zwang sofort beenden - auch vor dem ersten
 *                                         Lesen (a2, Entscheidungen Nr. 16 und 18;
 *                                         ebenso watt=0 und batteriemodus=1)
 *   lebenszeichen           [&geraet=N]   Sollwert am Leben halten (Totmannschaltung)
 *   sperren                 [&geraet=N]   nicht entladen (erzwungenes Entladen mit 0 W)
 *
 *   Derselbe Sollwert (laden/entladen mit watt > 0, sperren, batteriemodus
 *   2/3) innerhalb von 60 s geht nicht erneut an den Speicher: OK=1 und
 *   hinten ;UNVERAENDERT=1 (X-7, Entscheidung Nr. 19). Ruecknahmen gehen
 *   immer hinaus.
 *
 *   SCHREIBER-WACHE (Energie-1 C1, Entscheidung Nr. 25): schaltende Befehle
 *   tragen optional &von=<kennung> (die Vorlage setzt von=loxone, die
 *   EVCC-Adresse von=evcc); gemerkt wird Kennung@Absender. Mehr als ein
 *   Schreiber im Fenster (ab Werk 15 min): Protokoll, Reiter Test, Antwort
 *   hinten ;SCHREIBER=n - abgewiesen wird nichts. Nur mit "Fremde Schreiber
 *   abweisen" (ab Werk aus) bekommt ein Sollwert, dessen Rolle nicht fuehrt
 *   (Einstellung Fuehrung) oder dessen Schreiber nicht in der Liste steht,
 *   HTTP 409 GRUND=FREMDSCHREIBER und wird nicht eingereiht; Ruecknahmen nie.
 *   Merker nicht nutzbar: der Befehl geht trotzdem, hinten ;WACHE=MERKER.
 *   Eine ungueltige Kennung: HTTP 400 ERR=VON.
 *   abruf                                 sofort abrufen statt auf den Takt zu warten
 *
 * Fuer EVCC:
 *   evcc           [&geraet=N]            soc, power, capacity als JSON -
 *                                         power im Vorzeichen von EVCC
 *   batteriemodus  &modus=1|2|3           1 normal, 2 hold (nicht entladen),
 *                  [&watt=W] [&geraet=N]  3 charge (aus dem Netz laden)
 *
 * Der Endpunkt spricht NIE selbst mit einem Speicher. Lesende Aufrufe
 * beantwortet er aus dem Zwischenspeicher, schaltende legt er in einer
 * Warteschlange ab, die der Dienst abarbeitet. Das ist hier nicht nur eine
 * Frage der Sauberkeit: mehrere Speicher lassen ueberhaupt nur EINE
 * Verbindung gleichzeitig zu.
 *
 * Ein Strich als Wert bedeutet: der Speicher hat dieses Feld nicht geliefert.
 * Es wird bewusst keine 0 gesendet - eine 0 waere eine stille Falschaussage.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/bm_lib.php';

/* Schreibsperre fuer den unangemeldeten Bereich - die ERSTE Anweisung nach
 * dem Einbinden (B03, 04.09.2026).
 *
 * Bis 0.9.15 rief die Zeile darunter bm_config() VOR der Tokenpruefung, und
 * die Selbstheilung darin legte den Konfigurationsordner an und spielte die
 * Zweitschrift zurueck. Gemessen: ein Aufruf ohne jedes Token beantwortete
 * mit 403 - und legte config/plugins/<ordner>/batteriebms.json neu an, samt
 * altem Aktionstoken. Wer die Konfiguration loescht, um das Plugin
 * stillzulegen, hatte sie danach zurueck.
 *
 * Das Protokoll bleibt beschreibbar: eine Zeile ueber einen abgewiesenen
 * Aufruf ist genau das, was hier fehlte (B26). */
bm_nur_lesen(true);

header('Content-Type: text/plain; charset=utf-8');

/**
 * Eine Zeile ueber den Ausgang dieses Aufrufs - gebremst.
 *
 * Anlass (B26): der Endpunkt hatte auf KEINEM Weg einen Protokolleintrag.
 * Damit liess sich 'der Miniserver ruft nicht an' nicht von 'er ruft an und
 * wird abgewiesen' unterscheiden - der Fall, der bei der ACTiKamera am
 * 22.08.2026 Stunden gekostet hat.
 *
 * Erfolgreiche Abrufe bleiben stumm: der Miniserver fragt im Minutentakt,
 * das waeren 1440 Zeilen am Tag. Die Zugangsmarke steht NIE darin, und ein
 * gerade abgewiesener Wert auch nicht - seine Laenge sagt genug.
 */
function bm_ep_log($grund, $zusatz = '')
{
    $von = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '?';
    $von = preg_replace('/[^0-9a-fA-F:.]/', '', $von);
    bm_log_gebremst('ep_' . $grund . '_' . $von,
        'Endpunkt: Aufruf von ' . ($von === '' ? '?' : $von) . ' abgewiesen - '
        . $grund . ($zusatz !== '' ? ' (' . $zusatz . ')' : ''), 3600);
}

$bm_cfg = bm_config();

/* ---------------- Token ----------------
 *
 * ?selftest=1 ist der Hausstandard-Selbsttest (B22): er beantwortet die
 * Tokenfrage, ohne etwas auszuloesen, ohne Geraetekontakt und ohne
 * Schreibzugriff. Drei festgelegte Antworten.
 *
 * Das Token gilt fuer JEDE Aktion, auch fuer die lesenden (status, zellen,
 * liste, roh, evcc, summe). Das ist eine begruendete Ausnahme zu Regeln/05
 * ('abfragende Aufrufe bleiben offen') - Entscheidung des Hausherrn vom
 * 17.09.2026: Ladezustand, Zellspannungen und Zwangszustand eines
 * Heimspeichers sollen im Heimnetz nicht ohne Token lesbar sein. So gebaut
 * seit mindestens 0.9.14; README, Reiter 'Einbindung in Loxone' und die
 * Vorlage schreiben das Token in jede Adresse. */
$bm_selftest = isset($_GET['selftest']) && is_string($_GET['selftest'])
            && $_GET['selftest'] === '1';
/* C9 (Durchgang 29.09.2026): nur ein Token, das taugt (bm_token_gueltig()),
 * gilt. Bis 0.9.29 wurde aus einer Liste im Feld aktionstoken per (string) das
 * Wort "Array" - und ?token=Array oeffnete den Endpunkt (gemessen). Ein
 * untaugliches Token behandelt der Endpunkt wie keines: 403, eine Zeile im
 * Protokoll; ersetzt wird es nur in der angemeldeten Oberflaeche. */
$bm_soll = bm_token_gueltig($bm_cfg['aktionstoken']) ? $bm_cfg['aktionstoken'] : '';
$bm_ist = (isset($_GET['token']) && is_string($_GET['token']))
        ? (string) $_GET['token'] : '';
if ($bm_soll === '') {
    http_response_code(403);
    if ($bm_selftest) {
        echo "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n";
    } else {
        echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
        echo ($bm_cfg['aktionstoken'] === '' || $bm_cfg['aktionstoken'] === null)
            ? "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Token.\n"
            : "Das gespeicherte Token taugt nicht (1 bis 64 Zeichen (Buchstaben, Ziffern, Punkt, Bindestrich, Unterstrich)) - die Oberflaeche erzeugt beim Oeffnen ein neues.\n";
    }
    bm_ep_log(($bm_cfg['aktionstoken'] === '' || $bm_cfg['aktionstoken'] === null)
        ? 'KEIN_TOKEN_EINGERICHTET' : 'TOKEN_UNTAUGLICH');
    exit;
}
if (!hash_equals($bm_soll, $bm_ist)) {
    http_response_code(403);
    echo $bm_selftest ? "SELFTEST;OK=0;ERR=TOKEN\n" : "FEHLER;OK=0;GRUND=TOKEN\n";
    bm_ep_log('TOKEN', strlen($bm_ist) . ' Zeichen uebergeben');
    exit;
}
if ($bm_selftest) {
    echo "SELFTEST;OK=1;TOKEN=OK\n";
    exit;
}

/* ---------------- Aktion (Weissliste) ---------------- */
$bm_lesend = array('status', 'zellen', 'liste', 'roh', 'evcc', 'summe');
$bm_schaltend = array('laden', 'entladen', 'automatik', 'lebenszeichen', 'abruf',
                      'sperren', 'batteriemodus');
// C10: nur Text - ?aktion[]=laden ergab bis 0.9.29 "Array" samt Warnung.
$bm_aktion = !isset($_GET['aktion']) ? 'status'
           : (is_string($_GET['aktion']) ? $_GET['aktion'] : '');
if (!in_array($bm_aktion, array_merge($bm_lesend, $bm_schaltend), true)) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION\n";
    echo 'Erlaubt sind: ' . implode(', ', array_merge($bm_lesend, $bm_schaltend)) . "\n";
    bm_ep_log('UNBEKANNTE_AKTION');
    exit;
}

/* ---------------- Parameter ----------------
 * Was nicht ins Muster passt, wird abgewiesen und gemeldet. Nie Zeichen
 * entfernen, nie zurechtbiegen, nie stillschweigend auf eine Grenze setzen.
 */
function bm_param($name, $muster, $vorgabe = '')
{
    if (!isset($_GET[$name]) || $_GET[$name] === '') {
        return $vorgabe;
    }
    /* Erst is_string, dann alles andere: ?geraet[]=1 macht ein Feld, und
     * (string) darauf ist unter PHP 8 ein TypeError - HTTP 500 mit leerem
     * Rumpf, und der Miniserver liest nichts. */
    if (!is_string($_GET[$name])) {
        http_response_code(400);
        echo "FEHLER;OK=0;GRUND=PARAMETER\n";
        echo 'Der Wert von ' . $name . " passt nicht ins erlaubte Muster.\n";
        bm_ep_log('PARAMETER', $name . ', kein Text');
        exit;
    }
    $w = (string) $_GET[$name];
    if (!preg_match($muster, $w)) {
        http_response_code(400);
        echo "FEHLER;OK=0;GRUND=PARAMETER\n";
        echo 'Der Wert von ' . $name . " passt nicht ins erlaubte Muster.\n";
        // Der abgewiesene Wert selbst gehoert nicht ins Protokoll.
        bm_ep_log('PARAMETER', $name . ', ' . strlen($w) . ' Zeichen');
        exit;
    }
    return $w;
}

$bm_nr   = bm_param('geraet', '/^[0-9]{1,2}$/', '1');
$bm_watt = bm_param('watt', '/^[0-9]{1,5}$/', '');

/* Energie-1 C1: &von=<kennung> (Schreiber-Wache). Fehlt es: '' (ohne Kennung).
 * Eine Kennung, die nicht ins Muster passt (auch leer), wird abgewiesen wie
 * jeder andere falsche Parameter - abweisen statt zurechtbiegen (Nr. 19); ein
 * Tippfehler faellt beim Einrichten auf. Der Wert selbst kommt nicht ins
 * Protokoll. */
$bm_von = '';
if (isset($_GET['von'])) {
    if (!bm_wache_kennung_gueltig($_GET['von'])) {
        http_response_code(400);
        echo "FEHLER;OK=0;ERR=VON\n";
        echo "Die Kennung in von passt nicht ins Muster: 1 bis 32 Zeichen aus A-Z, a-z, 0-9, _ und -.\n";
        bm_ep_log('VON', is_string($_GET['von']) ? strlen($_GET['von']) . ' Zeichen' : 'kein Text');
        exit;
    }
    $bm_von = $_GET['von'];
}

/** Ein Strich statt einer erfundenen 0. Loxone behaelt dann den letzten Wert. */
function bm_w($v)
{
    if ($v === null || $v === '' || !is_numeric($v)) {
        return '-';
    }
    return (string) (0 + $v);
}

$bm_lox = bm_loxone();
$bm_alle = bm_werte();
$bm_alter = bm_alter();
$bm_g = isset($bm_alle[$bm_nr]) ? $bm_alle[$bm_nr] : null;

/* C8 (Entscheidung Nr. 4 vom 29.09.2026): OK=0, sobald ALTER groesser ist als
 * das Dreifache des Abruftakts (bm_ok_grenze()) - fuer ALLE Zeilen (status,
 * liste, zellen, evcc, summe). Bis 0.9.29 blieb OK=1 stehen, waehrend das
 * Abbild veraltete (gemessen: OK=1 bei ALTER=7200). ALTER selbst bleibt
 * daneben stehen, damit Loxone sieht, WARUM OK=0 ist. */
$bm_veraltet = ($bm_alter < 0) || ($bm_alter > bm_ok_grenze($bm_cfg));

/* O3: ALTER wird auf MaxVal der Vorlage (86400, bm_status_felder()) gekappt.
 * Loxone kappt einen groesseren Wert ohnehin auf MaxVal - dann aber still und
 * auf eine Zahl, die nicht gesendet wurde. Gekappt steht hier die Grenze,
 * und OK=0 sagt, dass der Stand nicht mehr gilt. */
$bm_alter_aus = ($bm_alter < 0) ? $bm_alter : min($bm_alter, 86400);

/** OK einer Zeile: gemeldet UND nicht veraltet. */
function bm_ok_aus($ok)
{
    return (!empty($ok) && empty($GLOBALS['bm_veraltet'])) ? 1 : 0;
}

/* ================= Lesende Aktionen ================= */

if ($bm_aktion === 'roh') {
    header('Content-Type: application/json; charset=utf-8');
    /* C8: ohne Daten 503 (Regeln/07, "Faellt die Quelle ganz aus"), wie
     * status und zellen seit B51. Bis 0.9.29 antwortete roh mit 200 und []. */
    if (!$bm_lox) {
        http_response_code(503);
        echo json_encode(array('ok' => 0, 'grund' => 'KEINE_DATEN'));
        exit;
    }
    $bm_json = json_encode($bm_lox, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($bm_json === false) {
        // json_encode gibt bei ungueltigem UTF-8 false zurueck. Ungeprueft
        // waere die Antwort eine voellig leere Seite mit Status 200 - und
        // eine leere Antwort mit Erfolgsmeldung ist das Schlechteste, was
        // eine Schnittstelle liefern kann: die Gegenstelle haelt sie fuer
        // gueltig. Woher solche Bytes kommen: aus der Ausgabe eines
        // Systembefehls (stty) in einer Umgebung ohne UTF-8-Zeichensatz, die
        // als Fehlertext bis hierher durchgereicht wird.
        http_response_code(500);
        echo json_encode(array(
            'ok'     => 0,
            'fehler' => 'Das Abbild liess sich nicht als JSON ausgeben: ' . json_last_error_msg(),
            'hinweis' => 'Vermutlich steht in einem Fehlertext ein Byte, das kein UTF-8 ist. '
                       . 'Der Reiter Logdateien zeigt, welcher Abruf ihn erzeugt hat.',
        ));
        exit;
    }
    echo $bm_json;
    exit;
}

if ($bm_aktion === 'evcc') {
    /* Genau die Groessen, die ein Batteriezaehler in EVCC braucht - nicht
     * mehr. Wer alles will, nimmt 'roh'.
     *
     * Diese Aktion ist LESEND und braucht deshalb weder den Steuerungshaken
     * noch einen laufenden Dienst: EVCC soll den Ladezustand auch dann sehen,
     * wenn nichts geschaltet werden darf. */
    header('Content-Type: application/json; charset=utf-8');
    $bm_e = bm_evcc_werte($bm_nr === '' ? null : (int) $bm_nr);
    /* C8: ohne Daten zu diesem Speicher 503 statt 200 mit lauter null. */
    if (!isset($bm_alle[max(1, (int) $bm_nr)])) {
        http_response_code(503);
        echo json_encode(array('ok' => 0, 'grund' => 'GERAET_UNBEKANNT', 'alter' => $bm_alter_aus));
        exit;
    }
    $bm_e['ok'] = bm_ok_aus($bm_e['ok']);
    $bm_e['alter'] = $bm_alter_aus;
    echo json_encode($bm_e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* Summe ueber alle Speicher.
 *
 * Bei mehreren Speichern brauchte Loxone bisher je einen virtuellen Eingang
 * und musste selbst rechnen. Der Ladezustand wird nach Kapazitaet gewichtet -
 * das ist die einzige richtige Art, zwei verschieden grosse Speicher
 * zusammenzufassen.
 *
 * FAIL CLOSED: fehlt bei EINEM Speicher die Kapazitaet, gibt es keinen
 * gewichteten Ladezustand, sondern einen Strich. Ein ungewichteter
 * Mittelwert waere eine Zahl, die richtig aussieht und es nicht ist.
 */
if ($bm_aktion === 'summe') {
    $bm_kap = 0.0;
    $bm_kwh = 0.0;
    $bm_p = 0.0;
    $bm_n = 0;
    $bm_okn = 0;
    $bm_voll = true;
    $bm_alarm = 0;
    foreach ($bm_alle as $bm_nr2 => $bm_e) {
        $bm_n++;
        if (!empty($bm_e['ok'])) {
            $bm_okn++;
        }
        if (!empty($bm_e['ALARM'])) {
            $bm_alarm = 1;
        }
        if (isset($bm_e['PBAT']) && is_numeric($bm_e['PBAT'])) {
            $bm_p += (float) $bm_e['PBAT'];
        }
        $bm_k = (isset($bm_e['KAPAZ']) && is_numeric($bm_e['KAPAZ']) && $bm_e['KAPAZ'] > 0)
            ? (float) $bm_e['KAPAZ'] : 0.0;
        $bm_r = (isset($bm_e['RESTKWH']) && is_numeric($bm_e['RESTKWH']))
            ? (float) $bm_e['RESTKWH'] : null;
        if ($bm_k <= 0 || $bm_r === null) {
            $bm_voll = false;
            continue;
        }
        $bm_kap += $bm_k;
        $bm_kwh += $bm_r;
    }
    if ($bm_n === 0) {
        /* Ohne einen einzigen Speicher im Abbild gibt es keine Summe (B51):
         * 503 mit Grund in der Zeile, wie bei status und zellen. */
        http_response_code(503);
        printf("SUMME;OK=0;GRUND=KEIN_SPEICHER;N=0;ALTER=%d\n", $bm_alter_aus);
        exit;
    }
    printf("SUMME;OK=%d;N=%d;NOK=%d;SOC=%s;KAPAZ=%s;RESTKWH=%s;PBAT=%s;ALARM=%d;ALTER=%d\n",
        bm_ok_aus($bm_n > 0 && $bm_okn === $bm_n), $bm_n, $bm_okn,
        ($bm_voll && $bm_kap > 0) ? (string) round($bm_kwh / $bm_kap * 100, 1) : '-',
        ($bm_voll && $bm_kap > 0) ? (string) round($bm_kap, 2) : '-',
        ($bm_voll && $bm_kap > 0) ? (string) round($bm_kwh, 2) : '-',
        $bm_n > 0 ? (string) round($bm_p, 0) : '-',
        $bm_alarm, $bm_alter_aus);
    exit;
}

if ($bm_aktion === 'liste') {
    /* C8: ohne einen einzigen Speicher 503 mit Grund, wie summe. */
    if (!$bm_alle) {
        http_response_code(503);
        echo 'LISTE;OK=0;GRUND=KEIN_SPEICHER;N=0;ALTER=' . $bm_alter_aus . "\n";
        exit;
    }
    echo 'LISTE;OK=' . bm_ok_aus($bm_lox['ok'] ?? 0) . ';N=' . count($bm_alle)
       . ';ALTER=' . $bm_alter_aus . "\n";
    foreach ($bm_alle as $nr => $g) {
        echo $nr . ';' . $g['name'] . ';' . $g['profil'] . ';' . $g['transport']
           . ';Stand=' . $g['stand'] . ';OK=' . bm_ok_aus($g['ok']) . "\n";
    }
    exit;
}

/* a2 (Entscheidung Nr. 16 vom 30.09.2026): 'automatik' wird auch dann
 * angenommen und eingereiht, wenn der Speicher noch nicht im Abbild steht -
 * etwa gleich nach dem Dienststart, bevor der erste Abruf fertig ist, oder
 * nach dem Eintragen eines Speichers bis zum naechsten Takt. Bis 0.9.31
 * bekam auch die Ruecknahme dort 503; wer nach einem Neustart als Erstes
 * den Zwang beenden wollte, musste warten. Voraussetzung: die Nummer ist
 * in den Einstellungen eingerichtet (der Dienst schreibt ueber
 * bm_geraet(), nicht ueber das Abbild).
 * Entscheidung Nr. 18 (01.10.2026): dasselbe fuer die beiden anderen
 * Ruecknahmen - laden/entladen mit watt=0 und batteriemodus=1 (EVCC
 * "normal"; jede Eingabe, die bm_evcc_modus() auf automatik abbildet).
 * EVCC schickt batteriemodus=1, nicht automatik. Alles andere bleibt bei
 * 503 (setzen braucht einen gelesenen Ladezustand). Ohne laufenden Dienst
 * bleibt es bei DIENST_LAEUFT_NICHT weiter unten. */
$bm_rueck_vorab = ($bm_aktion === 'automatik')
    || (($bm_aktion === 'laden' || $bm_aktion === 'entladen')
        && $bm_watt !== '' && (int) $bm_watt === 0)
    || ($bm_aktion === 'batteriemodus' && isset($_GET['modus']) && is_string($_GET['modus'])
        && bm_evcc_modus($_GET['modus']) === 'automatik');
$bm_vorab = ($bm_g === null && $bm_rueck_vorab
    && (int) $bm_nr >= 1 && bm_geraet((int) $bm_nr) !== null);

if ($bm_g === null && !$bm_vorab) {
    /* Keine Daten zu dieser Nummer: HTTP 503, nicht 200 (B51, 17.09.2026).
     *
     * Regeln/07, 'Faellt die Quelle ganz aus, liefert der Endpunkt HTTP 503':
     * das gilt ausdruecklich auch vor dem ersten Abruf, 'weder mit 200 und
     * OK=0 noch mit 404'. Loxone schaltet bei 503 den Onlinestatus des
     * Eingangs ab - der Mangel ist sichtbar. Eine 200 mit OK=0 sieht dort aus
     * wie ein gewoehnlicher Zustand. Am Geraet gemessen (17.09.2026, kein
     * Speicher eingerichtet): HTTP 200 'BMS;OK=0;GRUND=GERAET_UNBEKANNT;N=0'.
     *
     * Die Zeile selbst bleibt wortgleich - an GRUND haengen fremde Anlagen.
     * NICHT hierher gehoert ein eingerichteter Speicher, der gerade nicht
     * antwortet: der hat eine Nummer im Abbild, liefert Striche statt Zahlen
     * und OK=0. Er bleibt BEWUSST bei 200 - Entscheidung des Hausherrn vom
     * 17.09.2026, begruendete Ausnahme zu Regeln/07 'Faellt die Quelle ganz
     * aus': bei 503 behielte Loxone die letzten Werte, auch OK=1, und ein
     * Alarm auf OK=0 loeste bei einem ausgefallenen Speicher nie aus. Die
     * Messfelder kommen als Strich, ein alter Stand wird also nicht
     * geliefert. */
    http_response_code(503);
    printf("%s;OK=0;GRUND=GERAET_UNBEKANNT;N=%d;ALTER=%d\n",
        $bm_aktion === 'zellen' ? 'ZELLEN' : 'BMS', count($bm_alle), $bm_alter_aus);
    exit;
}

if ($bm_aktion === 'zellen') {
    $module = isset($bm_g['module']) && is_array($bm_g['module']) ? $bm_g['module'] : array();
    printf("ZELLEN;OK=%d;MODULE=%d;UZMAX=%s;UZMIN=%s;UZDIFF=%s;ALTER=%d\n",
        bm_ok_aus($bm_g['ok']), count($module), bm_w($bm_g['UZMAX']), bm_w($bm_g['UZMIN']),
        bm_w($bm_g['UZDIFF']), $bm_alter_aus);
    foreach ($module as $m => $md) {
        printf("MODUL=%d;UZMAX=%s;UZMIN=%s;UZDIFF=%s;TMAX=%s;TMIN=%s;ZELLEN=%d\n",
            (int) $m, bm_w(isset($md['uzmax']) ? $md['uzmax'] : null),
            bm_w(isset($md['uzmin']) ? $md['uzmin'] : null),
            bm_w(isset($md['uzdiff']) ? $md['uzdiff'] : null),
            bm_w(isset($md['tmax']) ? $md['tmax'] : null),
            bm_w(isset($md['tmin']) ? $md['tmin'] : null),
            isset($md['zellen']) ? count($md['zellen']) : 0);
    }
    exit;
}

/* DIE REIHENFOLGE DER FELDER DARF SICH NICHT AENDERN.
 *
 * Loxone sucht bei einem 'Virtuellen HTTP-Eingang Befehl' den Suchtext
 * WOERTLICH und nimmt den ERSTEN Treffer in der Zeile. Der Suchtext
 * \iALTER=\i\v steckt aber auch in SOLLALTER=. Dass der Baustein trotzdem
 * den richtigen Wert bekommt, liegt einzig daran, dass ALTER aus
 * bm_status_felder() VOR den beiden angehaengten Sollwertfeldern steht.
 *
 * Wer die Reihenfolge umstellt oder ein Feld nach vorn zieht, dessen Name
 * ein bestehendes als Anfangsstueck enthaelt, liefert stillschweigend
 * falsche Zahlen an bestehende Loxone-Projekte - ohne dass irgendwo ein
 * Fehler auftaucht. Neue Felder deshalb immer HINTEN anhaengen, und wenn ein
 * Name sich nicht vermeiden laesst, den Baustein mit fuehrendem Semikolon
 * suchen lassen (\i;FELD=\i\v).
 */
if ($bm_aktion === 'status') {
    $teile = array('BMS');
    foreach (bm_status_felder() as $feld => $unbenutzt) {
        if ($feld === 'ALTER') {
            $teile[] = 'ALTER=' . $bm_alter_aus;
            continue;
        }
        if ($feld === 'OK') {
            $teile[] = 'OK=' . bm_ok_aus($bm_g['ok']);
            continue;
        }
        $teile[] = $feld . '=' . bm_w($bm_g[$feld]);
    }
    // Der Sollwert gehoert dazu: sonst weiss Loxone nicht, ob ein Zwang laeuft.
    //
    // SOLL ist Text und taugt nicht als Analogwert; SOLLART sagt dasselbe als
    // Zahl (0 kein Zwang, 1 laden, 2 entladen, 3 gesperrt, 4 unvollstaendig
    // - O3) und laesst sich
    // deshalb an einen virtuellen Eingang haengen. SOLLART steht ganz hinten,
    // weil neue Felder nach der Regel oben immer hinten angehaengt werden.
    $teile[] = 'SOLL=' . ($bm_g['sollwert'] !== '' ? str_replace(';', '_', $bm_g['sollwert']) : 'automatik');
    $teile[] = 'SOLLALTER=' . (int) $bm_g['sollwert_alter'];
    $teile[] = 'SOLLART=' . bm_sollart($bm_g['sollwert']);
    echo implode(';', $teile) . "\n";
    exit;
}

/* ================= Schaltende Aktionen ================= */

/* C3 (Durchgang 29.09.2026): die Pruefung der Freigabe steht jetzt UNTEN,
 * nach der Uebersetzung von batteriemodus - und sie gilt nie fuer eine
 * Ruecknahme (automatik, laden/entladen mit watt=0, batteriemodus=normal).
 * Bis 0.9.29 wies der Endpunkt bei abgeschalteter Freigabe auch "automatik"
 * mit 403 ab: wer die Freigabe waehrend eines Zwangs abschaltete, konnte ihn
 * danach nicht mehr beenden. */
/* Die Pruefung des DIENSTES stand bis 0.9.15 hier - also VOR der Pruefung der
 * Anfrage. Gemessen: 'laden' ohne watt und 'batteriemodus&modus=99' bekamen
 * beide DIENST_LAEUFT_NICHT, obwohl beide auch mit laufendem Dienst
 * abgewiesen worden waeren. Der Bediener startete daraufhin den Dienst, statt
 * seinen Aufruf zu berichtigen. Sie steht jetzt unten, unmittelbar vor dem
 * Absetzen (B17). */

/* Betriebsart von EVCC in eine Aktion dieses Plugins uebersetzen.
 *
 * Abgewiesen wird, was nicht in der Tabelle steht - nicht auf 'normal'
 * zurechtgebogen. Ein missverstandener Betriebsartwechsel ist schlimmer als
 * ein abgelehnter: EVCC haelt den Speicher dann fuer angehalten, waehrend er
 * weiter entlaedt. */
$bm_war_modus = ($bm_aktion === 'batteriemodus');
if ($bm_aktion === 'batteriemodus') {
    $bm_modus = (isset($_GET['modus']) && is_string($_GET['modus'])) ? $_GET['modus'] : '';
    $bm_ziel = bm_evcc_modus($bm_modus);
    if ($bm_ziel === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=MODUS_UNBEKANNT\n";
        echo "Erlaubt sind 1/normal, 2/hold und 3/charge.\n";
        exit;
    }
    if ($bm_ziel === 'laden' && $bm_watt === '') {
        // Aus dem Netz laden ohne Leistungsangabe waere geraten. Entweder
        // steht sie im Aufruf oder in den Einstellungen - sonst Abbruch.
        $bm_vorgabe = (int) $bm_cfg['evcc_ladewatt'];
        if ($bm_vorgabe <= 0) {
            http_response_code(400);
            echo "SET;OK=0;GRUND=WATT_FEHLT\n";
            echo "Fuer modus=3 braucht es watt=W oder eine Vorgabe in den Einstellungen.\n";
            exit;
        }
        $bm_watt = (string) $bm_vorgabe;
    }
    $bm_aktion = $bm_ziel;
}

/* Woher kam der Befehl? 'batteriemodus' ist der Weg, den EVCC benutzt, alles
 * uebrige kommt vom Miniserver. Steht die Herkunft im Sollwert, laesst sich
 * spaeter beantworten, warum der Speicher gerade laedt. */
$bm_befehl = array('aktion' => $bm_aktion, 'geraet' => (int) $bm_nr,
                   'quelle' => $bm_war_modus ? 'EVCC' : 'Loxone');
if ($bm_aktion === 'laden' || $bm_aktion === 'entladen') {
    if ($bm_watt === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=WATT_FEHLT\n";
        echo "Fuer laden und entladen ist watt Pflicht. watt=0 gibt die Regie zurueck.\n";
        exit;
    }
    $bm_befehl['watt'] = (int) $bm_watt;
}

$bm_ruecknahme = ($bm_aktion === 'automatik')
    || (($bm_aktion === 'laden' || $bm_aktion === 'entladen') && (int) $bm_watt === 0);
if ($bm_aktion !== 'abruf' && !$bm_ruecknahme && empty($bm_cfg['steuerung_ein'])) {
    http_response_code(403);
    echo "SET;OK=0;GRUND=STEUERUNG_AUS\n";
    echo "Schreibende Befehle sind gesperrt. Reiter Einstellungen, Haken "
       . "'Schreibende Befehle zulassen'.\n";
    exit;
}

/**
 * Schreiber-Wache (Energie-1 C1, Entscheidung Nr. 25; Kopf der Funktionen in
 * bm_lib.php): merken und melden, und nur mit "Fremde Schreiber abweisen"
 * einen Sollwert mit 409 abweisen, bevor er eingereiht wird. Ruecknahmen nie.
 * Rueckgabe: Zusatz fuer die Antwortzeile - ;SCHREIBER=n ab zwei Schreibern
 * im Fenster, ;WACHE=MERKER, wenn das Merken nicht ging (der Befehl geht
 * trotzdem). Neue Felder immer hinten (siehe "DIE REIHENFOLGE DER FELDER").
 */
function bm_ep_wache($nr, $von, $evcc_weg, $aktion, $ruecknahme, $watt)
{
    $w = bm_wache_einstellungen(bm_config());
    $ip = bm_wache_absender();
    $rolle = bm_wache_rolle($von, $evcc_weg);
    list($aktiv, $erlaubt, $fehler, $grund) = bm_wache_sperre_urteil($w, $von, $ip, $rolle);
    if ($fehler !== '') {
        bm_log_gebremst('wache_liste', 'Schreiber-Wache: "Fremde Schreiber abweisen" ist eingeschaltet, '
            . 'aber weder eine Fuehrung gewaehlt noch eine brauchbare Liste erlaubter Schreiber da - die '
            . 'Sperre wirkt NICHT, bis das im Reiter Einstellungen berichtigt ist.', 3600);
    }
    $abweisen = $aktiv && !$erlaubt && !$ruecknahme;
    $art = $ruecknahme ? 'ruecknahme'
        : ($aktion . (($aktion === 'laden' || $aktion === 'entladen') ? ' ' . (int) $watt . ' W' : ''));
    $wer = ($von !== '' ? $von : 'ohne Kennung') . '@' . ($ip !== '' ? $ip : '?');
    $zusatz = '';
    if ((int) $w['wache_ein'] === 1) {
        $m = bm_wache_merken((int) $nr, $von, $ip, $rolle, $art, $abweisen, $w);
        if ($m['anzahl'] > 1) {
            $zusatz .= ';SCHREIBER=' . (int) $m['anzahl'];
        }
        if (!$m['merker']) {
            $zusatz .= ';WACHE=MERKER';
        }
        if (!$ruecknahme && !bm_wache_fuehrt($w['fuehrung'], $rolle)) {
            bm_log_gebremst('wache_fuehrung_' . (int) $nr . '_' . $rolle, 'Schreiber-Wache, Speicher '
                . (int) $nr . ': ' . $art . ' von ' . $wer . ' (Rolle ' . bm_wache_rollenname($rolle)
                . '), eingestellt ist "Fuehrung: ' . bm_wache_rollenname($w['fuehrung']) . '" - '
                . ($abweisen ? 'abgewiesen (409).' : ((int) $w['wache_sperren_ein'] === 1
                    ? 'nicht abgewiesen (die Sperre wirkt nicht, siehe die Zeile dazu).'
                    : 'nicht abgewiesen (Sperren aus).')),
                60 * (int) $w['wache_fenster_min']);
        }
    }
    if ($abweisen) {
        http_response_code(409);
        printf("SET;OK=0;GRUND=FREMDSCHREIBER;AKTION=%s;GERAET=%d%s\n", $aktion, (int) $nr, $zusatz);
        echo ($grund === 'FUEHRUNG')
            ? 'Die Fuehrung steht auf ' . bm_wache_rollenname($w['fuehrung']) . '; dieser Sollwert hat die Rolle '
              . bm_wache_rollenname($rolle) . ". Nichts eingereiht (Reiter Einstellungen, Schreiber-Wache).\n"
            : "Dieser Schreiber steht nicht in der Liste der erlaubten Schreiber. Nichts eingereiht "
              . "(Reiter Einstellungen, Schreiber-Wache).\n";
        bm_ep_log('FREMDSCHREIBER', $art . ', ' . $wer . ', ' . $grund);
        exit;
    }
    return $zusatz;
}

/* Erst jetzt, unmittelbar vor dem Absetzen: laeuft der Dienst ueberhaupt?
 * Nicht stillschweigend einreihen - ohne laufenden Dienst passiert nichts,
 * und Loxone haelt den Zwang sonst faelschlich fuer gesetzt. */
/* Energie-1 C1: die Schreiber-Wache - nach der Freigabe (eine abgeschaltete
 * Steuerung bleibt 403), vor dem Dienst und vor dem Einreihen. abruf schreibt
 * nichts und geht vorbei. */
$bm_wz = ($bm_aktion === 'abruf') ? ''
    : bm_ep_wache($bm_nr, $bm_von, $bm_war_modus, $bm_aktion, $bm_ruecknahme,
                  isset($bm_befehl['watt']) ? $bm_befehl['watt'] : 0);

if (bm_dienst_pid() === 0) {
    http_response_code(503);
    echo "SET;OK=0;GRUND=DIENST_LAEUFT_NICHT\n";
    echo "Der Abrufdienst laeuft nicht. Reiter Einstellungen, Knopf 'Dienst starten'.\n";
    bm_ep_log('DIENST_LAEUFT_NICHT', $bm_aktion);
    exit;
}

/* X-7 Gleichwert-Unterdrueckung (B-Nachzug 01.10.2026, Entscheidung Nr. 19):
 * Sollwerte - laden/entladen mit watt > 0, sperren und damit batteriemodus
 * 2/3 - tragen das Merkmal "gleichwert". Der Dienst schickt denselben Wert
 * innerhalb von 60 s nicht erneut an den Speicher, frischt den Sollwert
 * aber auf (bm_gleichwert_seit() in bin/bms_dienst.php). Ruecknahmen
 * (automatik, watt=0, batteriemodus=1) tragen es nie; die Annahme vor dem
 * ersten Lesen (a2, Nr. 16/18) bleibt unberuehrt. Die Antwort traegt dann
 * hinten ;UNVERAENDERT=1 - neue Felder immer hinten (siehe oben). */
if ($bm_aktion === 'sperren'
    || (($bm_aktion === 'laden' || $bm_aktion === 'entladen') && (int) $bm_watt > 0)) {
    $bm_befehl['gleichwert'] = 1;
}
$bm_antwort = bm_befehl_absetzen($bm_befehl);
list($bm_erg, $bm_meldung) = $bm_antwort;
if ($bm_erg === 0) {
    http_response_code(500);
}
printf("SET;OK=%d;AKTION=%s;GERAET=%d;MELDUNG=%s%s%s\n", $bm_erg, $bm_aktion, (int) $bm_nr,
    str_replace(array("\r", "\n", ';'), ' ', $bm_meldung),
    !empty($bm_antwort[2]) ? ';UNVERAENDERT=1' : '', $bm_wz);
