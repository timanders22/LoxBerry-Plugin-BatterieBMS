#!/bin/bash
# Batterie-Heimspeicher (BMS) - preupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Wichtig: Der Dienst haelt eine Modbus-Verbindung offen. Manche Speicher
# lassen nur EINE Verbindung gleichzeitig zu (belegt fuer die BYD-BCU). Bleibt
# ein alter Prozess stehen, kommt der neue nicht mehr an das Geraet heran.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-batteriebms}"
BASE="${ARGV5:-$LBHOMEDIR}"

PID="$BASE/data/plugins/$PFOLDER/dienst.pid"
if [ -f "$PID" ]; then
    kill "$(cat "$PID")" 2>/dev/null || true
    sleep 2
    kill -9 "$(cat "$PID")" 2>/dev/null || true
    rm -f "$PID"
    echo "<INFO> Laufender Dienst angehalten."
fi

CF="$BASE/config/plugins/$PFOLDER/batteriebms.json"
if [ -f "$CF" ]; then
    cp -p "$CF" "$BASE/config/plugins/$PFOLDER.backup.json"
    chmod 600 "$BASE/config/plugins/$PFOLDER.backup.json" 2>/dev/null
fi
echo "<OK> preupgrade abgeschlossen."
exit 0
