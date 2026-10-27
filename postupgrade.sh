#!/bin/bash
# Batterie-Heimspeicher (BMS) - postupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
SELF=$(cd "$(dirname "$0")" && pwd)
# Das letzte Hakenskript entfernt die Upgrade-Marke (preupgrade.sh) - auch
# dann, wenn postinstall.sh fehlt oder scheitert (Fall C14). Im Regelfall hat
# postinstall.sh sie schon entfernt; "rm -f" ist dann ein Leerlauf.
# Die Wurzel nach derselben Regel wie postinstall.sh (dort ausfuehrlich):
# fuenftes Argument, $LBHOMEDIR mit config/plugins und data/plugins, sonst
# aufwaerts mit general.json. Ohne Wurzel wird nichts entfernt.
bm_wurzel_suchen() {
    bm_v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd -P)
    bm_i=0
    while [ -n "$bm_v" ] && [ "$bm_v" != "/" ] && [ "$bm_i" -lt 8 ]; do
        if [ -d "$bm_v/config/plugins" ] && [ -d "$bm_v/data/plugins" ] \
           && [ -f "$bm_v/config/system/general.json" ]; then
            echo "$bm_v"
            return 0
        fi
        bm_v=$(dirname "$bm_v")
        bm_i=$((bm_i + 1))
    done
    return 1
}
BM_BASE="${5:-}"
if [ -z "$BM_BASE" ] || [ ! -d "$BM_BASE" ]; then
    if [ -n "${LBHOMEDIR:-}" ] && [ -d "$LBHOMEDIR/config/plugins" ] \
       && [ -d "$LBHOMEDIR/data/plugins" ]; then
        BM_BASE="$LBHOMEDIR"
    else
        BM_BASE=$(bm_wurzel_suchen) || BM_BASE=""
    fi
fi
BM_MARKE="$BM_BASE/data/plugins/${3:-batteriebms}.upgrade_laeuft"
trap '[ -n "$BM_BASE" ] && rm -f "$BM_MARKE"' EXIT
# B32: bis 0.9.15 stand hier eine -x-Pruefung, gemeldet wurde aber "nicht
# gefunden". Fehlte nur das Ausfuehrungsrecht, brach das Upgrade mit einer
# Meldung ab, die auf die falsche Ursache zeigte - und die Rueckspielung der
# Konfiguration unterblieb, obwohl die Sicherung dalag. Ueber bash aufgerufen
# spielt das x-Bit keine Rolle mehr.
# B47: LoxBerry 4.0.0.15 ruft beim Upgrade BEIDE Haken auf - postinstall lief
# hier deshalb zweimal (am Geraet gemessen am 06.09.2026, 08:06:33 und
# 08:06:35). Die Weiterleitung bleibt stehen, weil aeltere Fassungen beim
# Upgrade moeglicherweise nur postupgrade aufrufen; postinstall.sh selbst
# erkennt am Merker, dass es in diesem Einbau schon gelaufen ist, und beendet
# sich still.
if [ -f "$SELF/postinstall.sh" ]; then
    bash "$SELF/postinstall.sh" "$@"
    BM_RC=$?
    # Gruppe 31 (Entscheidung 3, E-E13): bestehende Anlagen behalten den HTTP-Lesezweig fuer Loxone -
    # endpunkt_ein = 1 in der Konfiguration und in der Zweitschrift, je wenn eingerichtet (Schluessel ausser
    # aktionstoken und _...) und der Schluessel fehlt. Nebendatei .neu (umask 077), Gegenprobe VOR dem mv
    # (gleich bis auf endpunkt_ein = 1), mv -f, chmod 600. Scheitert es, gilt die Lese-Regel in bm_config().
    if [ -n "$BM_BASE" ] && command -v php >/dev/null 2>&1; then
        for BM_F in "$BM_BASE/config/plugins/${3:-batteriebms}/batteriebms.json" \
                    "$BM_BASE/config/plugins/${3:-batteriebms}.backup.json"; do
            [ -f "$BM_F" ] || continue
            ( umask 077; php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
                if (!is_array($d) || array_key_exists("endpunkt_ein", $d)) { exit(3); }
                $e = 0; foreach (array_keys($d) as $k) { $k = (string) $k;
                    if ($k !== "aktionstoken" && !($k !== "" && $k[0] === "_")) { $e = 1; break; } }
                if (!$e) { exit(3); }
                $d["endpunkt_ein"] = 1;
                $j = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit(is_string($j) && file_put_contents($argv[1] . ".neu", $j) === strlen($j) ? 0 : 1);' "$BM_F" 2>/dev/null )
            BM_E=$?
            [ "$BM_E" = "3" ] && continue
            if [ "$BM_E" = "0" ] && php -r '$a = json_decode((string) @file_get_contents($argv[1]), true);
                    $b = json_decode((string) @file_get_contents($argv[1] . ".neu"), true);
                    if (!is_array($a) || !is_array($b) || !isset($b["endpunkt_ein"]) || $b["endpunkt_ein"] !== 1) { exit(1); }
                    unset($b["endpunkt_ein"]); exit($a == $b ? 0 : 1);' "$BM_F" 2>/dev/null \
               && mv -f "$BM_F.neu" "$BM_F" && chmod 600 "$BM_F"; then
                echo "<INFO> Konfiguration ergaenzt: endpunkt_ein=1 in $(basename "$BM_F") (bestehende Anlage - der HTTP-Lesezweig"
                echo "<INFO> fuer Loxone bleibt erreichbar; abschalten im Reiter Einbindung)."
            else
                rm -f "$BM_F.neu" 2>/dev/null
                echo "<WARNING> endpunkt_ein liess sich in $(basename "$BM_F") nicht ergaenzen - es gilt die Lese-Regel"
                echo "<WARNING> (bestehende Anlage: Lesezweig an)."
            fi
        done
    fi
    # Die Marke ist nach postinstall.sh weg (dessen trap); sicherheitshalber hier auch.
    [ -n "$BM_BASE" ] && rm -f "$BM_MARKE"
    if [ -n "$BM_BASE" ]; then
        BM_PF="${3:-batteriebms}"
        # Gruppe 31 (0.9.38, Entscheidung 3): die Befehlsbruecke starten - befehlsbruecke.sh startet nur mit dem Haken
        # "Befehle ueber MQTT annehmen" (ab Werk aus) und "Werte ueber MQTT", und nicht, solange eine frische
        # Upgrade-Marke liegt. Der Waechter im Minutentakt (cron/cron.01min) holt einen ausgelassenen Start binnen
        # einer Minute nach.
        BBSH="$BM_BASE/bin/plugins/$BM_PF/befehlsbruecke.sh"
        if [ -f "$BBSH" ]; then
            BB_AUS=$(timeout 40 bash "$BBSH" start 2>&1 | head -n 1)
            case "$BB_AUS" in
                *gestartet*|*laeuft*) echo "<OK> Befehlsbruecke: $BB_AUS" ;;
                "Haken aus - kein Start.") echo "<INFO> Befehlsbruecke: aus (Reiter MQTT, Haken 'Befehle ueber MQTT annehmen')." ;;
                *) echo "<WARNING> Befehlsbruecke: $BB_AUS - der Minutentakt versucht es erneut." ;;
            esac
        fi
    fi
    exit $BM_RC
fi
echo "<FAIL> $SELF/postinstall.sh nicht gefunden - Upgrade unvollstaendig."
exit 1
