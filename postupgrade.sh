#!/bin/bash
# Batterie-Heimspeicher (BMS) - postupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
SELF=$(cd "$(dirname "$0")" && pwd)
# B32: bis 0.9.15 stand hier eine -x-Pruefung, gemeldet wurde aber "nicht
# gefunden". Fehlte nur das Ausfuehrungsrecht, brach das Upgrade mit einer
# Meldung ab, die auf die falsche Ursache zeigte - und die Rueckspielung der
# Konfiguration unterblieb, obwohl die Sicherung dalag. Ueber bash aufgerufen
# spielt das x-Bit keine Rolle mehr.
if [ -f "$SELF/postinstall.sh" ]; then
    bash "$SELF/postinstall.sh" "$@"
    exit $?
fi
echo "<FAIL> $SELF/postinstall.sh nicht gefunden - Upgrade unvollstaendig."
exit 1
