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
# Merken, OB der Dienst lief - NEBEN dem Datenverzeichnis, denn das raeumt
# der Installer beim Upgrade ab (B29). Bis 0.9.15 fiel dabei der Sollmerker
# soll_laufen, kein Hakenskript startete den Dienst wieder, und der Waechter
# tut ohne den Merker nichts: der Speicher wurde nach jedem Update nicht mehr
# ausgelesen, bis jemand die Oberflaeche oeffnete. In Loxone sah alles normal
# aus, weil virtuelle Eingaenge ihren letzten Wert behalten.
LIEF="$BASE/config/plugins/$PFOLDER.laeuft"
rm -f "$LIEF"
if [ -f "$BASE/data/plugins/$PFOLDER/soll_laufen" ]; then
    : > "$LIEF"
    echo "<INFO> Der Dienst lief - er wird nach dem Update wieder gestartet."
fi

if [ -x "$DIENST" ]; then
    # Rueckgabewert UND Ausgabe auswerten (B28). Bis 0.9.15 wurde beides
    # verworfen und trotzdem "angehalten" gemeldet - auch dann, wenn gar kein
    # Dienst lief oder das kill scheiterte. Wer danach das Protokoll las,
    # schloss einen noch offenen Modbus-Prozess als Ursache aus.
    AUSGABE=$("$DIENST" stop 2>&1)
    RC=$?
    if [ $RC -eq 0 ]; then
        echo "<INFO> dienst.sh stop: $AUSGABE"
    else
        echo "<FAIL> dienst.sh stop meldete Rueckgabewert $RC: $AUSGABE"
        echo "<FAIL> Ein alter Prozess haelt moeglicherweise noch die Verbindung"
        echo "<FAIL> zum Speicher. Manche Geraete lassen nur EINE zu."
    fi
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

# ---------- Konfiguration sichern ----------
# B27: bis 0.9.15 wurde der Rueckgabewert von cp nicht geprueft und danach
# bedingungslos "<OK>" gemeldet. Scheitert das Kopieren (volle Platte,
# Rechte), ist die gesamte Konfiguration weg - Geraete, Adressen,
# Aktionstoken - und im Installationsprotokoll steht, alles sei in Ordnung.
FEHLER=0
CF="$BASE/config/plugins/$PFOLDER/batteriebms.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
if [ -f "$CF" ]; then
    if cp -p "$CF" "$BK"; then
        chmod 600 "$BK" 2>/dev/null
        A=$(wc -c < "$CF" 2>/dev/null)
        B=$(wc -c < "$BK" 2>/dev/null)
        if [ -n "$A" ] && [ "$A" = "$B" ]; then
            echo "<OK> Konfiguration gesichert ($A Byte)."
        else
            echo "<FAIL> Die Sicherung der Konfiguration ist unvollstaendig"
            echo "<FAIL> ($A Byte gelesen, $B Byte geschrieben). Das Update wird"
            echo "<FAIL> abgebrochen, damit die Einstellungen nicht verlorengehen."
            FEHLER=1
        fi
    else
        echo "<FAIL> Die Konfiguration liess sich NICHT sichern ($CF)."
        echo "<FAIL> Das Update wird abgebrochen - der Installer wuerde"
        echo "<FAIL> config/plugins/$PFOLDER/ sonst gleich abraeumen."
        FEHLER=1
    fi
fi

# ---------- Eigene Profile und Verlauf sichern ----------
# Beides liegt unter data/plugins/<ordner>/ und wird vom Installer bei JEDEM
# Upgrade abgeraeumt. Bis 0.9.15 stand nur die Konfigurations-JSON auf der
# Liste: hochgeladene Profile und der ganze Verlauf waren nach einer
# Aktualisierung weg, und kein Text sagte es dem Anwender. Schlimmer noch,
# die mitgelieferte Beispieldatei wird neu ausgeliefert - der Ordner sieht
# hinterher heil aus.
PDATA="$BASE/data/plugins/$PFOLDER"
DBK="$BASE/config/plugins/$PFOLDER.backup.daten.tar"
rm -f "$DBK"
TEILE=""
[ -d "$PDATA/profile" ] && TEILE="$TEILE profile"
[ -d "$PDATA/verlauf" ] && TEILE="$TEILE verlauf"
if [ -n "$TEILE" ]; then
    if ( cd "$PDATA" && tar cf "$DBK" $TEILE ) 2>/dev/null; then
        chmod 600 "$DBK" 2>/dev/null
        ZAHL=$(tar tf "$DBK" 2>/dev/null | grep -c '[^/]$')
        echo "<OK> Eigene Profile und Verlauf gesichert ($ZAHL Datei(en):$TEILE)."
    else
        echo "<FAIL> Profile und Verlauf liessen sich nicht sichern ($DBK)."
        echo "<FAIL> Sie wuerden beim Update verlorengehen - Abbruch."
        FEHLER=1
    fi
else
    echo "<INFO> Keine eigenen Profile und kein Verlauf vorhanden."
fi

if [ $FEHLER -ne 0 ]; then
    exit 1
fi
echo "<OK> preupgrade abgeschlossen."
exit 0
