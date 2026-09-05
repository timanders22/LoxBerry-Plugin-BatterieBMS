#!/bin/bash
# Batterie-Heimspeicher (BMS) - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Das Plugin ist reines PHP. Der Modbus-Verkehr laeuft ueber PHP-Sockets, das
# Pylontech-Konsolenprotokoll ueber die serielle Schnittstelle. Es wird KEINE
# virtuelle Python-Umgebung gebraucht und damit auch kein Umweg um PEP 668.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-batteriebms}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    # Ableitung aus dem eigenen Ablageort - LoxBerry::System taugt hier nicht,
    # weil es den Pluginordner aus dem Aufrufort ableitet und aus
    # postinstall.sh heraus ueberall Leerstring liefert (belegt in der
    # LoxoneIcons-Sitzung am 02.08.2026).
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"

mkdir -p "$PDATA/befehle" "$PDATA/antworten" "$PDATA/verlauf" "$PDATA/profile" \
         "$PLOG" "$PCONFIG" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null

[ -f "$PCONFIG/batteriebms.json" ] || echo '{}' > "$PCONFIG/batteriebms.json"
chmod 600 "$PCONFIG/batteriebms.json" 2>/dev/null

# Sicherung zurueckspielen (uebersteht Update UND Neuinstallation)
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$PCONFIG/batteriebms.json"
if [ -f "$BK" ]; then
    INHALT=$(cat "$CF" 2>/dev/null)
    if [ ! -s "$CF" ] || [ "$INHALT" = "{}" ]; then
        cp -p "$BK" "$CF" && chmod 600 "$CF" && echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
    fi
fi

# ---------- PHP pruefen ----------
if ! command -v php >/dev/null 2>&1; then
    echo "<FAIL> Es wurde kein PHP gefunden. LoxBerry bringt PHP normalerweise mit -"
    echo "<FAIL> ohne PHP laeuft weder die Oberflaeche noch der Dienst."
    exit 1
fi
echo "<INFO> PHP: $(php -v 2>/dev/null | head -1)"

# Die Erweiterung 'sockets' wird fuer die MQTT-Veroeffentlichung ueber den
# UDP-Eingang des Gateways gebraucht. Modbus selbst laeuft ueber Streams und
# kommt ohne sie aus - deshalb ist ein Fehlen eine Meldung, kein Abbruch.
if php -r 'exit(extension_loaded("sockets") ? 0 : 1);' 2>/dev/null; then
    echo "<OK> PHP-Erweiterung sockets vorhanden."
else
    echo "<INFO> PHP-Erweiterung sockets fehlt. Modbus laeuft trotzdem;"
    echo "<INFO> die Veroeffentlichung ueber MQTT jedoch nicht."
    echo "<INFO> Nachholen mit: sudo apt install php-sockets"
fi

# ---------- Serielle Schnittstelle (nur fuer Pylontech) ----------
# Pylontech spricht kein Modbus, sondern ein eigenes Konsolenprotokoll ueber
# RS485. Dafuer muss der Benutzer loxberry in der Gruppe dialout sein.
# Die Gruppe dialout wird NICHT mehr bei jeder Installation gesetzt.
#
# Bis 0.9.15 lief hier bedingungslos ein usermod. Damit bekam der Benutzer,
# unter dem auch die Oberflaeche laeuft, dauerhaft Zugriff auf ALLE seriellen
# Geraete des Rechners - den Zigbee-Stick, den EnOcean-Stick, was sonst noch
# angeschlossen ist -, obwohl das nur fuer Pylontech ueber RS485 gebraucht
# wird und die meisten Speicher ueber Modbus TCP laufen. Eine Rechteerweiterung
# gehoert nicht ungefragt in eine Installation.
if id -nG loxberry 2>/dev/null | tr ' ' '\n' | grep -qx dialout; then
    echo "<OK> Benutzer loxberry ist in der Gruppe dialout (serielle Schnittstelle)."
else
    echo "<INFO> Benutzer loxberry ist NICHT in der Gruppe dialout."
    echo "<INFO> Das wird nur fuer Pylontech ueber RS485 gebraucht. Wer ein"
    echo "<INFO> serielles Profil einrichtet, holt es einmal nach mit:"
    echo "<INFO>   sudo usermod -a -G dialout loxberry && sudo reboot"
    echo "<INFO> Der Reiter Test sagt, ob es noetig ist."
fi
if command -v stty >/dev/null 2>&1; then
    echo "<OK> stty vorhanden (setzt die Parameter der seriellen Schnittstelle)."
else
    echo "<INFO> stty fehlt - Pylontech ueber RS485 wird nicht funktionieren."
fi

# ---------- Eigene Profile und Verlauf zurueckspielen ----------
DBK="$BASE/config/plugins/$PFOLDER.backup.daten.tar"
if [ -f "$DBK" ]; then
    if ( cd "$PDATA" && tar xf "$DBK" ) 2>/dev/null; then
        ZAHL=$(tar tf "$DBK" 2>/dev/null | grep -c '[^/]$')
        echo "<OK> Eigene Profile und Verlauf zurueckgespielt ($ZAHL Datei(en))."
        rm -f "$DBK"
    else
        echo "<FAIL> Die gesicherten Profile und der Verlauf liessen sich NICHT"
        echo "<FAIL> zurueckspielen. Das Archiv bleibt liegen: $DBK"
    fi
fi

chmod 755 "$PBIN/dienst.sh" 2>/dev/null
chmod 755 "$PBIN/bms_dienst.php" 2>/dev/null
chown -R loxberry:loxberry "$PBIN" "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null

# ---------- Lief der Dienst vor dem Update? Dann wieder starten (B29) ----------
LIEF="$BASE/config/plugins/$PFOLDER.laeuft"
if [ -f "$LIEF" ]; then
    rm -f "$LIEF"
    if [ -x "$PBIN/dienst.sh" ]; then
        AUSGABE=$("$PBIN/dienst.sh" start 2>&1)
        RC=$?
        if [ $RC -eq 0 ]; then
            echo "<OK> Der Dienst lief vor dem Update und wurde wieder gestartet."
            echo "<INFO> $AUSGABE"
        else
            echo "<FAIL> Der Dienst lief vor dem Update, liess sich aber nicht"
            echo "<FAIL> wieder starten (Rueckgabewert $RC): $AUSGABE"
            echo "<FAIL> Bitte im Reiter Einstellungen von Hand starten."
        fi
    else
        echo "<FAIL> Der Dienst lief vor dem Update, aber $PBIN/dienst.sh ist"
        echo "<FAIL> nicht ausfuehrbar. Bitte von Hand starten."
    fi
fi

echo "<OK> Installation abgeschlossen."
echo "<INFO> Bitte die Plugin-Oberflaeche oeffnen, die Speicher eintragen und den"
echo "<INFO> Dienst im Reiter Einstellungen starten. Der Reiter Test sagt danach"
echo "<INFO> Zeile fuer Zeile, ob die Einrichtung traegt."
exit 0
