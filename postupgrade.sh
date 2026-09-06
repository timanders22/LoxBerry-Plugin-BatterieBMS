#!/bin/bash
# Batterie-Heimspeicher (BMS) - postupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
SELF=$(cd "$(dirname "$0")" && pwd)
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
