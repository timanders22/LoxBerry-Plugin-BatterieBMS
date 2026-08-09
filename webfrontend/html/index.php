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
 *   automatik               [&geraet=N]   Zwang sofort beenden
 *   lebenszeichen           [&geraet=N]   Sollwert am Leben halten (Totmannschaltung)
 *   abruf                                 sofort abrufen statt auf den Takt zu warten
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
header('Content-Type: text/plain; charset=utf-8');

$bm_cfg = bm_config();

/* ---------------- Token ---------------- */
$bm_soll = (string) $bm_cfg['aktionstoken'];
$bm_ist = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ($bm_soll === '') {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Token.\n";
    exit;
}
if (!hash_equals($bm_soll, $bm_ist)) {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=TOKEN\n";
    exit;
}

/* ---------------- Aktion (Weissliste) ---------------- */
$bm_lesend = array('status', 'zellen', 'liste', 'roh');
$bm_schaltend = array('laden', 'entladen', 'automatik', 'lebenszeichen', 'abruf');
$bm_aktion = isset($_GET['aktion']) ? (string) $_GET['aktion'] : 'status';
if (!in_array($bm_aktion, array_merge($bm_lesend, $bm_schaltend), true)) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION\n";
    echo 'Erlaubt sind: ' . implode(', ', array_merge($bm_lesend, $bm_schaltend)) . "\n";
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
    $w = (string) $_GET[$name];
    if (!preg_match($muster, $w)) {
        http_response_code(400);
        echo "FEHLER;OK=0;GRUND=PARAMETER\n";
        echo 'Der Wert von ' . $name . " passt nicht ins erlaubte Muster.\n";
        exit;
    }
    return $w;
}

$bm_nr   = bm_param('geraet', '/^[0-9]{1,2}$/', '1');
$bm_watt = bm_param('watt', '/^[0-9]{1,5}$/', '');

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

/* ================= Lesende Aktionen ================= */

if ($bm_aktion === 'roh') {
    header('Content-Type: application/json; charset=utf-8');
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

if ($bm_aktion === 'liste') {
    echo 'LISTE;OK=' . (int) (!empty($bm_lox['ok'])) . ';N=' . count($bm_alle)
       . ';ALTER=' . $bm_alter . "\n";
    foreach ($bm_alle as $nr => $g) {
        echo $nr . ';' . $g['name'] . ';' . $g['profil'] . ';' . $g['transport']
           . ';Stand=' . $g['stand'] . ';OK=' . (int) $g['ok'] . "\n";
    }
    exit;
}

if ($bm_g === null) {
    printf("%s;OK=0;GRUND=GERAET_UNBEKANNT;N=%d;ALTER=%d\n",
        $bm_aktion === 'zellen' ? 'ZELLEN' : 'BMS', count($bm_alle), $bm_alter);
    exit;
}

if ($bm_aktion === 'zellen') {
    $module = isset($bm_g['module']) && is_array($bm_g['module']) ? $bm_g['module'] : array();
    printf("ZELLEN;OK=%d;MODULE=%d;UZMAX=%s;UZMIN=%s;UZDIFF=%s;ALTER=%d\n",
        (int) $bm_g['ok'], count($module), bm_w($bm_g['UZMAX']), bm_w($bm_g['UZMIN']),
        bm_w($bm_g['UZDIFF']), $bm_alter);
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
            $teile[] = 'ALTER=' . $bm_alter;
            continue;
        }
        if ($feld === 'OK') {
            $teile[] = 'OK=' . (int) $bm_g['ok'];
            continue;
        }
        $teile[] = $feld . '=' . bm_w($bm_g[$feld]);
    }
    // Der Sollwert gehoert dazu: sonst weiss Loxone nicht, ob ein Zwang laeuft.
    $teile[] = 'SOLL=' . ($bm_g['sollwert'] !== '' ? str_replace(';', '_', $bm_g['sollwert']) : 'automatik');
    $teile[] = 'SOLLALTER=' . (int) $bm_g['sollwert_alter'];
    echo implode(';', $teile) . "\n";
    exit;
}

/* ================= Schaltende Aktionen ================= */

if ($bm_aktion !== 'abruf' && empty($bm_cfg['steuerung_ein'])) {
    http_response_code(403);
    echo "SET;OK=0;GRUND=STEUERUNG_AUS\n";
    echo "Schreibende Befehle sind gesperrt. Reiter Einstellungen, Haken "
       . "'Schreibende Befehle zulassen'.\n";
    exit;
}
if (bm_dienst_pid() === 0) {
    // Nicht stillschweigend einreihen: ohne laufenden Dienst passiert nichts,
    // und Loxone haelt den Zwang sonst faelschlich fuer gesetzt.
    http_response_code(503);
    echo "SET;OK=0;GRUND=DIENST_LAEUFT_NICHT\n";
    echo "Der Abrufdienst laeuft nicht. Reiter Einstellungen, Knopf 'Dienst starten'.\n";
    exit;
}

$bm_befehl = array('aktion' => $bm_aktion, 'geraet' => (int) $bm_nr);
if ($bm_aktion === 'laden' || $bm_aktion === 'entladen') {
    if ($bm_watt === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=WATT_FEHLT\n";
        echo "Fuer laden und entladen ist watt Pflicht. watt=0 gibt die Regie zurueck.\n";
        exit;
    }
    $bm_befehl['watt'] = (int) $bm_watt;
}

list($bm_erg, $bm_meldung) = bm_befehl_absetzen($bm_befehl);
if ($bm_erg === 0) {
    http_response_code(500);
}
printf("SET;OK=%d;AKTION=%s;GERAET=%d;MELDUNG=%s\n", $bm_erg, $bm_aktion, (int) $bm_nr,
    str_replace(array("\r", "\n", ';'), ' ', $bm_meldung));
