<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie BatterieBMS (Gruppe 31, 0.9.38;
 * Bauart Anker SOLIX 0.9.32).
 *
 * Die Befehlsbruecke (bin/befehlsbruecke.php, byte-gleich aus der Stammfassung Werkzeuge/gemeinsam/, 1.0.1)
 * abonniert <praefix>/befehl/# am Broker und gibt jeden gueltigen Befehl an tun() dieser Tabelle. tun() ruft
 * DIESELBE Funktion wie der HTTP-Endpunkt webfrontend/html/index.php - bm_befehl_annehmen() (Freigabe,
 * Schreiber-Wache, Dienst, Gleichwert-Merkmal X-7, Einreihen in die Warteschlange des Dienstes, Warten auf die
 * Antwort) - mit der Kennung von=mqtt und der Quelle 'mqtt', und gibt array(ok, grund) fuer die Quittung zurueck
 * (bm_befehl_quittung(): trennt "eingereiht (Schreibbremse)" von "keine Antwort", N7). Ein Token gibt es auf
 * diesem Weg nicht: das Plugin ist selbst der Empfaenger. Die Bruecke legt nur in die Warteschlange; der Dienst
 * bleibt der EINZIGE Modbus-Schreiber. Grenzen, Ladezustandsfenster, Temperaturschutz, Schreibbremse und
 * Schreiber-Wache gelten wie beim HTTP-Weg.
 *
 * Sperrminute 0 fuer alle Sollwerte und das Lebenszeichen (Begruendung am Eintrag): das Musterprojekt schickt das
 * Lebenszeichen alle 60 s mit dem Wert 1 (Seite "BatterieBMS Spotpreis-Laden"). Mit der Vorgabe-Sperre 60 s der
 * Bruecke kaeme jedes zweite als "abgelehnt: wiederholt" zurueck, und die Totmannzeit liefe nach 300 s ab - der
 * Zwang fiele, obwohl Loxone lebt (Bauart Anker N2). Gleiche Werte faengt der Dienst selbst ab (X-7, 60 s,
 * UNVERAENDERT=1), zu viele Anfragen die Schreibbremse. abruf: 30 s (ein Abruf belegt die einzige
 * Modbus-Verbindung).
 *
 * automatik ist eine Ruecknahme und haengt NIE am zweiten Haken (C3). Speicher 1 ohne Nummer, Speicher 2 bis 9 als
 * <praefix>/befehl/<nr>/<befehl>. Kein "Sollwert verwerfen" ueber MQTT (E2 ist eine bewusste Handlung nach einer
 * Pruefung am Geraet - nur in der Oberflaeche).
 * Zweiter Haken ist der vorhandene "Schreibende Befehle zulassen" (steuerung_ein); der erste ist "Befehle ueber
 * MQTT annehmen" (befehle_mqtt_ein, ab Werk aus, wirkt nur mit "Werte ueber MQTT" mqtt_ein).
 * Die Datei gibt ein Feld zurueck, setzt keine Konfiguration und schreibt nichts.
 */
if (!function_exists('bm_config')) {
    $bm_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $bm_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/bm_lib.php';
    }
    $bm_kand[] = dirname(__DIR__) . '/webfrontend/html/bm_lib.php';
    foreach ($bm_kand as $bm_k) {
        if (is_file($bm_k)) {
            require_once $bm_k;
            break;
        }
    }
}
$bm_grund = 'Loxone sendet Sollwert und Lebenszeichen im Takt oft mit gleichem Wert; die Gleichwert-'
    . 'Unterdrueckung des Dienstes (X-7, 60 s, UNVERAENDERT=1) und die Schreibbremse sind die eine Pruefung, '
    . 'und nur ein angenommenes Lebenszeichen haelt die Totmannzeit auf.';
$bm_tun = function ($aktion, $feld) {
    return function ($w, $t) use ($aktion, $feld) {
        $b = array('aktion' => $aktion, 'geraet' => isset($t['nr']) ? (int) $t['nr'] : 1);
        if ($feld === 'watt') {
            $b['watt'] = (string) (int) $w;
        } elseif ($feld === 'modus') {
            $b['modus'] = (string) $w;
        }
        return bm_befehl_quittung(bm_befehl_annehmen($b, 'mqtt', 'mqtt'));
    };
};
$bm_befehle = array();
foreach (array('' => '', '{nr}/' => ' Speicher N') as $bm_vor => $bm_tn) {
    $bm_befehle[$bm_vor . 'laden'] = array('art' => 'ganz', 'min' => 0, 'max' => 30000, 'eingreifend' => true,
        'sperre_s' => 0, 'begruendung' => $bm_grund,
        'titel' => 'BatterieBMS Laden erzwingen' . $bm_tn . ' (MQTT)', 'kachel' => 'BEFEHL.K_LADEN',
        'quittung' => 'ausgefuehrt', 'tun' => $bm_tun('laden', 'watt'));
    $bm_befehle[$bm_vor . 'entladen'] = array('art' => 'ganz', 'min' => 0, 'max' => 30000, 'eingreifend' => true,
        'sperre_s' => 0, 'begruendung' => $bm_grund,
        'titel' => 'BatterieBMS Entladen erzwingen' . $bm_tn . ' (MQTT)', 'kachel' => 'BEFEHL.K_ENTLADEN',
        'quittung' => 'ausgefuehrt', 'tun' => $bm_tun('entladen', 'watt'));
    $bm_befehle[$bm_vor . 'sperren'] = array('art' => 'puls', 'eingreifend' => true,
        'sperre_s' => 0, 'begruendung' => $bm_grund,
        'titel' => 'BatterieBMS Entladen sperren' . $bm_tn . ' (MQTT)', 'kachel' => 'BEFEHL.K_SPERREN',
        'quittung' => 'ausgefuehrt', 'tun' => $bm_tun('sperren', ''));
    $bm_befehle[$bm_vor . 'automatik'] = array('art' => 'puls', 'eingreifend' => false,
        'sperre_s' => 0, 'begruendung' => 'Ruecknahme in die Automatik - nie gesperrt (C3)',
        'titel' => 'BatterieBMS Automatik' . $bm_tn . ' (MQTT)', 'kachel' => 'BEFEHL.K_AUTOMATIK',
        'quittung' => 'ausgefuehrt', 'tun' => $bm_tun('automatik', ''));
    $bm_befehle[$bm_vor . 'lebenszeichen'] = array('art' => 'puls', 'eingreifend' => true,
        'sperre_s' => 0, 'begruendung' => $bm_grund,
        'titel' => 'BatterieBMS Lebenszeichen' . $bm_tn . ' (MQTT)', 'kachel' => 'BEFEHL.K_LEBENSZEICHEN',
        'quittung' => 'ausgefuehrt', 'tun' => $bm_tun('lebenszeichen', ''));
    $bm_befehle[$bm_vor . 'batteriemodus'] = array('art' => 'auswahl', 'werte' => array('normal', 'hold', 'charge'),
        'eingreifend' => true, 'sperre_s' => 0, 'begruendung' => $bm_grund,
        'titel' => 'BatterieBMS Batteriemodus EVCC' . $bm_tn . ' (MQTT)', 'kachel' => 'BEFEHL.K_BATTERIEMODUS',
        'quittung' => 'ausgefuehrt', 'tun' => $bm_tun('batteriemodus', 'modus'));
}
$bm_befehle['abruf'] = array('art' => 'puls', 'eingreifend' => false, 'sperre_s' => 30,
    'begruendung' => 'ein Abruf belegt die einzige Modbus-Verbindung (BYD-BCU: eine gleichzeitig) - hoechstens alle 30 s',
    'titel' => 'BatterieBMS Sofort abrufen (MQTT)', 'kachel' => 'BEFEHL.K_ABRUF',
    'quittung' => 'uebergeben', 'tun' => $bm_tun('abruf', ''));
return array(
    'fassung' => 1,
    'linie'   => 'batteriebms',
    'plugin'  => 'Batterie-Heimspeicher (BMS)',
    'geladen' => function_exists('bm_befehl_annehmen') && function_exists('bm_befehl_quittung'),
    'zweiter_haken' => 'steuerung_ein',
    'einstellungen' => function () {
        // Wie der unangemeldete Endpunkt legt die Bruecke nichts an und heilt nichts.
        bm_nur_lesen(true);
        $c = bm_config();
        return array('praefix'     => (string) $c['mqtt_topic'],
                     'annehmen'    => !empty($c['befehle_mqtt_ein']) && !empty($c['mqtt_ein']),
                     'eingreifend' => !empty($c['steuerung_ein']),
                     'mqtt_ein'    => !empty($c['mqtt_ein']));
    },
    'protokoll' => function ($stufe, $text) {
        // Ungebremst ins Protokoll des Plugins - jeder Befehl steht dort. Der Text traegt schon "Befehlsbruecke: ".
        bm_log(((string) $stufe === '' ? 'INFO' : ((string) $stufe === 'WARN' ? 'WARNING' : (string) $stufe))
            . ' ' . $text);
    },
    'teile' => array('nr' => '[2-9]'),
    'befehle' => $bm_befehle,
);
