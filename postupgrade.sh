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
    exit $?
fi
echo "<FAIL> $SELF/postinstall.sh nicht gefunden - Upgrade unvollstaendig."
exit 1
