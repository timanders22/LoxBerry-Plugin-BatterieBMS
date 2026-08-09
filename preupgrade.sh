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

# Anhalten ueber dienst.sh, nicht mit einem eigenen kill.
#
# Bis 0.9.0 stand hier: SIGTERM, zwei Sekunden warten, kill -9. Zwei Sekunden
# reichen nicht. Ein Durchlauf mit Zelldaten wartet allein bis zu vier
# Sekunden auf die BYD-BCU, dazu die Zeitueberschreitung je Speicher. Der
# Dienst wurde also bei fast jedem Update hart abgeschossen - mitten im
# Schreiben von loxone.json, und ohne die Gelegenheit, einen laufenden
# Lade- oder Entladezwang zurueckzunehmen. Ein Speicher blieb dann mit
# gesetztem Sollwert stehen, waehrend niemand mehr nachfuetterte; erst die
# Totmannschaltung des neu gestarteten Dienstes holte ihn zurueck.
#
# dienst.sh stop schickt SIGTERM und laesst zehn Sekunden Zeit, bevor es
# nachsetzt. Es entfernt ausserdem den Sollmerker, damit der Waechter aus dem
# Cron den Dienst nicht mitten im Update wieder hochzieht.
DIENST="$BASE/bin/plugins/$PFOLDER/dienst.sh"
PID="$BASE/data/plugins/$PFOLDER/dienst.pid"
if [ -x "$DIENST" ]; then
    "$DIENST" stop >/dev/null 2>&1
    echo "<INFO> Laufender Dienst ueber dienst.sh angehalten."
elif [ -f "$PID" ]; then
    # Rueckfallebene, falls dienst.sh fehlt: von Hand, aber mit Geduld.
    P=$(cat "$PID" 2>/dev/null)
    if [ -n "$P" ] && kill -0 "$P" 2>/dev/null; then
        kill "$P" 2>/dev/null || true
        i=0
        while [ $i -lt 15 ] && kill -0 "$P" 2>/dev/null; do
            sleep 1
            i=$((i + 1))
        done
        # Nummernrecycling ausschliessen, bevor mit -9 nachgesetzt wird.
        if kill -0 "$P" 2>/dev/null && grep -qa "bms_dienst.php" "/proc/$P/cmdline" 2>/dev/null; then
            kill -9 "$P" 2>/dev/null || true
        fi
    fi
    rm -f "$PID"
    echo "<INFO> Laufender Dienst angehalten (Rueckfallebene ohne dienst.sh)."
fi

CF="$BASE/config/plugins/$PFOLDER/batteriebms.json"
if [ -f "$CF" ]; then
    cp -p "$CF" "$BASE/config/plugins/$PFOLDER.backup.json"
    chmod 600 "$BASE/config/plugins/$PFOLDER.backup.json" 2>/dev/null
fi
echo "<OK> preupgrade abgeschlossen."
exit 0
