#!/bin/bash
# Batterie-Heimspeicher (BMS) - Start, Stopp und Waechter des Abrufdienstes.
#
# Die Pfade werden aus dem EIGENEN Ablageort abgeleitet, nicht ueber
# LoxBerry::System. Grund: LoxBerry::System leitet den Pluginordner aus dem
# Aufrufort ab; wird dieses Skript aus postinstall.sh oder aus dem Cron
# gestartet, kommt dort ueberall Leerstring zurueck - das Skript werkelt dann
# gegen /-Pfade und meldet trotzdem Erfolg (belegt am 02.08.2026).

# readlink -f loest Symlinks auf, BEVOR das Verzeichnis bestimmt wird.
#
# Ohne das ist der Ablageort der Aufrufort: LoxBerry legt Daemons als Symlink
# unter system/daemons/plugins/ ab. Von dort aufgerufen ergaebe
# dirname "$0" den Pfad .../system/daemons/plugins, PNAME waere buchstaeblich
# "plugins", und der Dienst legte PID-Datei, Sollmerker und Logdatei unter
# <home>/data/plugins/plugins/ an - also neben, nicht in seinem eigenen
# Ordner. Die Oberflaeche saehe den Dienst dann nie laufen, der Waechter
# startete ihn im Minutentakt ein zweites Mal, und beide sprachen gleichzeitig
# mit einem Speicher, der nur eine Verbindung zulaesst.
SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)   # <home>/bin/plugins/<ordner>
PNAME=$(basename "$SELF")
LBHOMEDIR=$(cd "$SELF/../../.." && pwd)
PDATA="$LBHOMEDIR/data/plugins/$PNAME"
PLOG="$LBHOMEDIR/log/plugins/$PNAME"
PCONFIG="$LBHOMEDIR/config/plugins/$PNAME"
PID="$PDATA/dienst.pid"
SOLL="$PDATA/soll_laufen"
LOGDATEI="$PLOG/batteriebms.log"
SKRIPT="$SELF/bms_dienst.php"

mkdir -p "$PDATA" "$PLOG" 2>/dev/null

# ==================================================================
# Arbeitet der Dienst noch, oder lebt nur sein Prozess?
#
# 'systemctl is-active' beantwortet nicht, ob ein Dienst seine Arbeit tut -
# und eine PID-Datei erst recht nicht. Massgeblich ist das, was der Dienst
# hinterlaesst: loxone.json wird in JEDEM Durchlauf geschrieben, auch wenn
# kein einziger Speicher eingerichtet ist.
#
# Die Grenze ist das Fuenffache des eingestellten Takts, mindestens aber
# 180 s. Sie muss deutlich ueber dem Takt liegen, damit ein einzelner
# langsamer Durchlauf - die BYD-BCU laesst bis zu vier Sekunden auf sich
# warten, mal der Zahl der Speicher - keinen Neustart ausloest.
#
# Fail safe: laesst sich das Alter nicht bestimmen (kein stat, kein Abbild,
# kein PHP), wird NICHT neu gestartet. Ein Waechter, der im Zweifel
# zuschlaegt, ist schlimmer als keiner.
# ==================================================================
NEUSTARTMERKER="$PDATA/waechter_neustart"

abbild_alter() {
    ABBILD="$PDATA/loxone.json"
    [ -f "$ABBILD" ] || { echo -1; return; }
    MT=$(stat -c %Y "$ABBILD" 2>/dev/null)
    [ -n "$MT" ] || { echo -1; return; }
    echo $(( $(date +%s) - MT ))
}

# Die Grenze kommt aus bm_waechter_grenze() in der Bibliothek - EINE Quelle
# fuer den Waechter und fuer die Prueffrage im Reiter Test. Bis 0.9.7 stand
# die Formel hier UND dort ausgeschrieben, jeweils mit einem Kommentar, der
# auf die andere Stelle verwies.
#
# Fail safe: laesst sich die Bibliothek nicht befragen - kein PHP, Datei nicht
# da, Fehler beim Einlesen -, gilt die Untergrenze von 180 s. Der Waechter
# greift dann spaeter, aber nie frueher als vorgesehen.
abbild_grenze() {
    LIB="$LBHOMEDIR/webfrontend/html/plugins/$PNAME/bm_lib.php"
    G=""
    if command -v php >/dev/null 2>&1 && [ -f "$LIB" ]; then
        G=$(LBHOMEDIR="$LBHOMEDIR" php -r 'require $argv[1]; echo bm_waechter_grenze();' "$LIB" 2>/dev/null)
    fi
    case "$G" in
        ''|*[!0-9]*) G=180 ;;
    esac
    if [ "$G" -lt 180 ]; then G=180; fi
    echo "$G"
}

abbild_steht() {
    A=$(abbild_alter)
    [ "$A" -ge 0 ] || return 1
    [ "$A" -gt "$(abbild_grenze)" ] || return 1
    # Nicht im Minutentakt nachsetzen: hilft der Neustart nicht, wuerde der
    # Waechter sonst jede Minute erneut zuschlagen und das Protokoll fluten.
    if [ -f "$NEUSTARTMERKER" ]; then
        M=$(stat -c %Y "$NEUSTARTMERKER" 2>/dev/null)
        if [ -n "$M" ] && [ $(( $(date +%s) - M )) -lt 600 ]; then
            return 1
        fi
    fi
    return 0
}

laeuft() {
    [ -f "$PID" ] || return 1
    P=$(cat "$PID" 2>/dev/null)
    [ -n "$P" ] || return 1
    kill -0 "$P" 2>/dev/null || return 1
    # Nummernrecycling ausschliessen: der Prozess muss unser Skript sein
    grep -qa "bms_dienst.php" "/proc/$P/cmdline" 2>/dev/null || return 1
    return 0
}

starten() {
    if laeuft; then
        echo "laeuft bereits (PID $(cat "$PID"))"
        return 0
    fi
    if ! command -v php >/dev/null 2>&1; then
        echo "FEHLER: PHP nicht gefunden - ohne PHP laeuft der Dienst nicht."
        return 1
    fi
    if [ ! -f "$SKRIPT" ]; then
        echo "FEHLER: $SKRIPT fehlt. Plugin neu installieren."
        return 1
    fi
    if [ ! -f "$PCONFIG/batteriebms.json" ]; then
        echo "FEHLER: Konfiguration fehlt ($PCONFIG/batteriebms.json). Erst die Oberflaeche oeffnen."
        return 1
    fi
    touch "$SOLL"
    # Ausgabe geht in die Logdatei. Das PHP-Skript protokolliert deshalb NICHT
    # zusaetzlich nach stdout - sonst stuende jede Zeile doppelt darin.
    nohup php "$SKRIPT" >> "$LOGDATEI" 2>&1 &
    echo $! > "$PID"
    sleep 1
    if laeuft; then
        echo "gestartet (PID $(cat "$PID"))"
        return 0
    fi
    echo "FEHLER: Start fehlgeschlagen - siehe $LOGDATEI"
    rm -f "$PID"
    return 1
}

anhalten() {
    rm -f "$SOLL"
    if ! laeuft; then
        rm -f "$PID"
        echo "laeuft nicht"
        return 0
    fi
    P=$(cat "$PID")
    # SIGTERM, damit der Dienst einen laufenden Zwang noch zuruecknehmen kann.
    kill "$P" 2>/dev/null
    for i in 1 2 3 4 5 6 7 8 9 10; do
        laeuft || break
        sleep 1
    done
    if laeuft; then
        kill -9 "$P" 2>/dev/null
        sleep 1
    fi
    rm -f "$PID"
    echo "angehalten"
    return 0
}

case "$1" in
    start)   starten ;;
    stop)    anhalten ;;
    restart) anhalten; sleep 1; starten ;;
    status)
        if laeuft; then
            echo "laeuft $(cat "$PID")"
            exit 0
        fi
        echo "gestoppt"
        exit 1
        ;;
    waechter)
        # Nur neu starten, wenn der Dienst laufen SOLL. Ein bewusst
        # angehaltener Dienst bleibt angehalten.
        if [ -f "$SOLL" ] && ! laeuft; then
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: Dienst lief nicht, wird neu gestartet." >> "$LOGDATEI"
            starten >> "$LOGDATEI" 2>&1
        elif [ -f "$SOLL" ] && laeuft && abbild_steht; then
            # Der Prozess lebt, arbeitet aber nicht mehr. Bis 0.9.6 hat der
            # Waechter genau das nicht gesehen: er fragte nur, ob eine PID da
            # ist. Ein Dienst, der seit einer Stunde kein Abbild mehr
            # geschrieben hat, galt damit als gesund - und in Loxone standen
            # die alten Werte weiter, ohne dass irgendwo etwas davon zu lesen
            # gewesen waere.
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: Der Dienst laeuft (PID $(cat "$PID" 2>/dev/null)), hat aber seit $(abbild_alter) s kein Abbild mehr geschrieben (Grenze $(abbild_grenze) s). Er wird neu gestartet." >> "$LOGDATEI"
            touch "$NEUSTARTMERKER"
            anhalten >> "$LOGDATEI" 2>&1
            starten >> "$LOGDATEI" 2>&1
        fi
        ;;
    *)
        echo "Aufruf: $0 {start|stop|restart|status|waechter}"
        exit 2
        ;;
esac
