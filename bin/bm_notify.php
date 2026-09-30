<?php
/**
 * Batterie-Heimspeicher (BMS) - Meldung in den LoxBerry-Benachrichtigungsbereich legen
 *
 * Aufruf:  php bm_notify.php <Schwere 1-7> <Text> [Pluginordner]
 *
 * Aufgerufen vom Dienst (bin/bms_dienst.php, bm_benachrichtigen()), wenn ein
 * Zwang laenger als die Totmannfrist offen steht und die Ruecknahme in die
 * Automatik gescheitert ist (a1, Verbesserungsbau 30.09.2026) - hoechstens
 * eine Meldung je Zwang; den Merker fuehrt der Dienst.
 *
 * Warum ein eigenes Skript und nicht notify_ext() im Dienst selbst: keine
 * phplib des LoxBerry laedt loxberry_log.php von allein (am Geraet gemessen,
 * Memory "notify_ext() nie erreicht"), und der Dienst ist ein Dauerlaeufer -
 * die LoxBerry-Bibliothek setzt beim Einbinden globale Variablen und
 * Funktionen, die in einem Prozess, der Tage laeuft, nichts zu suchen haben.
 * Das kurze Zwischenstueck laedt sie selbst, ruft notify_ext() und endet.
 *
 * Wortgleich im Aufbau aus LoxBerry-Plugin-AudiConnect-0.9.23
 * (bin/au_notify.php, dort aus APC-UPS 1.2.1 uebernommen) - nicht neu
 * geschrieben, weil die Fassung dort geprueft ist. Geaendert sind nur der
 * Anzeigename und der feste Rueckfallname des Pakets.
 *
 * Der Pluginordner wird als drittes Argument uebergeben, weil der Dienst aus
 * dem Cron oder aus postinstall.sh heraus starten kann und dabei die
 * LoxBerry-Umgebungsvariablen fehlen. Ohne ihn fiele dieses Skript auf den
 * fest eingetragenen Namen zurueck - wer das Plugin in einen anderen Ordner
 * installiert hat, faende seine Warnung dann unter einem Paketnamen, den es
 * nicht gibt, und damit gar nicht.
 *
 * Rueckgabewert 0 = abgelegt, 1 = nicht moeglich.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins, data/plugins UND config/system/general.json traegt (so in
 * AudiConnect seit 0.9.20: mit nur config/plugins und webfrontend galt jeder
 * Rest eines frueheren Pruefstands als Wurzel).
 *
 * DIESER BLOCK STEHT VOR SEINEM AUFRUF. PHP zieht Funktionen, die in einem
 * if-Block stehen, nicht vor: sie entstehen erst, wenn die Zeile ausgefuehrt
 * wird (APC-UPS bis 1.1.6: "Call to undefined function" bei leerem
 * LBHOMEDIR, Regeln/03).
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
                && is_file($d . '/config/system/general.json')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

$home = getenv('LBHOMEDIR');
if (!$home || !is_dir($home . '/config/plugins') || !is_dir($home . '/data/plugins')) {
    $home = lb_wurzel_ermitteln();
}
if (!$home) {
    fwrite(STDERR, "Es wurde kein LoxBerry-Wurzelverzeichnis gefunden - "
        . "es wurde keine Meldung abgesetzt.\n");
    exit(1);
}
$sdk = $home . '/libs/phplib/loxberry_log.php';
if (!file_exists($sdk)) {
    fwrite(STDERR, "LoxBerry-Bibliothek nicht gefunden: " . $sdk . "\n");
    exit(1);
}
require_once $home . '/libs/phplib/loxberry_system.php';
require_once $sdk;

$schwere = isset($argv[1]) && preg_match('/^[0-9]+$/', (string) $argv[1]) ? (int) $argv[1] : 4;
$text    = isset($argv[2]) ? (string) $argv[2] : '';
if (trim($text) === '') {
    fwrite(STDERR, "Kein Text angegeben.\n");
    exit(1);
}

// Reihenfolge: was der Dienst mitgibt, dann die Umgebung, dann der feste
// Name. Das dritte Argument ist der verlaessliche Weg - siehe Kopf.
$paket = isset($argv[3]) ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $argv[3]) : '';
if ($paket === '') {
    $paket = (string) getenv('LBPPLUGINDIR');
}
if (!$paket) {
    $paket = 'batteriebms';
}

if (!function_exists('notify_ext')) {
    fwrite(STDERR, "notify_ext() steht in dieser LoxBerry-Fassung nicht bereit.\n");
    exit(1);
}

notify_ext(array(
    'PACKAGE'  => $paket,
    'NAME'     => 'Batterie-Heimspeicher (BMS)',
    'MESSAGE'  => $text,
    'SEVERITY' => $schwere,
));

exit(0);
