#!/bin/bash
# Batterie-Heimspeicher (BMS) - Start, Stopp und Waechter des Abrufdienstes.
#
# Die Pfade kommen NICHT aus LoxBerry::System. Grund: LoxBerry::System leitet
# den Pluginordner aus dem Aufrufort ab; wird dieses Skript aus postinstall.sh
# oder aus dem Cron gestartet, kommt dort ueberall Leerstring zurueck - das
# Skript werkelt dann gegen /-Pfade und meldet trotzdem Erfolg (belegt am
# 02.08.2026). Woher Wurzel und Ordnername stattdessen kommen, steht unten
# bei "Wurzel und Ordnername".

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
# Als loxberry laufen, nicht als root.
#
# Der minuetliche Waechter kommt aus dem Cron. Laeuft der als root - und je
# nach Ablage des Cronjobs tut er das -, dann gehoerten PID-Datei, Sollmerker
# und Protokoll danach root. Die Oberflaeche laeuft als loxberry und koennte
# den Dienst anschliessend weder anhalten noch neu starten: sie darf die
# Dateien nicht mehr schreiben. Schlimmer noch, 'dienst.sh stop' meldet dann
# Erfolg - das kill scheitert, aber das rm der PID-Datei gelingt, weil das
# Verzeichnis loxberry gehoert. Der Dienst laeuft weiter und ist nur noch
# ueber die Prozessliste zu finden.
#
# Deshalb setzt sich das Skript selbst herunter, EINMAL und bevor es
# irgendetwas anlegt. exec, damit kein zusaetzlicher Prozess stehen bleibt.
# '-s /bin/bash' ausdruecklich: ohne das nimmt su die Login-Shell aus
# /etc/passwd. Steht dort nologin oder /bin/false, endet dieses Skript hier
# still und ohne Meldung - und weil es 'exec' ist, kaeme nicht einmal ein
# Rueckgabewert zurueck. Auf einem regulaeren LoxBerry ist der Zweig ohnehin
# unerreichbar (der Cron laeuft bereits als loxberry); er greift nur, wenn
# jemand von Hand mit sudo aufruft.
#
# Woertlich uebernommen aus LoxBerry-Plugin-Dashboard-0.9.12, dort seit dem
# 16.08.2026 in Betrieb. Ueber den Bestand gezaehlt am 31.08.2026: 15 von 17
# dienst.sh hatten den Abstieg nicht, obwohl REGELN_2 ihn seit langem
# verlangt.
if [ "$(id -u)" = "0" ] && id loxberry >/dev/null 2>&1; then
    exec su -s /bin/bash loxberry -c "$(printf '%q ' "$0" "$@")"
fi

SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)   # <home>/bin/plugins/<ordner>

# ---------- Wurzel und Ordnername: GELESEN, nicht geraten ----------
#
# Bis 0.9.23 stand hier
#     PNAME=$(basename "$SELF")
#     LBHOMEDIR=$(cd "$SELF/../../.." && pwd)
# und gleich darauf ein 'mkdir -p' auf oberster Ebene. Ein gesetztes
# $LBHOMEDIR wurde damit ueberschrieben, der Ordnername kam aus dem
# Verzeichnisnamen, und der geratene Pfad wurde bei JEDEM Aufruf angelegt -
# auch bei 'status'. In WSL gemessen (Pruefung-BatterieBMS-0.9.24,
# messe_h1.sh; Bauart H1 in Bestand-2026-09-18/klasse-H/Ergebnis.md):
#   - 'status' aus einem Pruefarchiv <Wurzel>/pruefung/batteriebms/bin legte
#     in der LAUFENDEN Anlage data/plugins/bin und log/plugins/bin an (H3);
#   - dieselbe Datei aus einem ausgepackten Archiv uebersah das gesetzte
#     $LBHOMEDIR, der laufende Dienst galt als "gestoppt" (H2);
#   - nach purge_installation legte schon ein 'status' den Datenordner wieder
#     an - "der Ordner ist da" sagte in der Upgrade-Luecke nichts mehr (H4).
#
# Stufe 1 ist $LBHOMEDIR aus der Umgebung (am Geraet aus /etc/environment,
# der Cron laedt es ueber pam_env), wenn es eine Wurzel bezeichnet. Stufe 2
# sucht aufwaerts nach einem Verzeichnis mit config/plugins, data/plugins UND
# config/system/general.json (der dritte Nachweis seit dem Raumklima-Vorfall,
# Regeln/06, lb_wurzel_suchen). Eine feste Zahl '..' waere nur die naechste
# Wette; findet keine Stufe etwas, wird abgebrochen, ohne etwas anzulegen (H8).
bm_wurzel_suchen() {
    bm_v="$SELF"
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
if [ -n "${LBHOMEDIR:-}" ] && [ -d "$LBHOMEDIR/config/plugins" ] \
   && [ -d "$LBHOMEDIR/data/plugins" ]; then
    :
else
    LBHOMEDIR=$(bm_wurzel_suchen) || LBHOMEDIR=""
fi
if [ -z "$LBHOMEDIR" ]; then
    echo "FEHLER: Es wurde kein LoxBerry-Wurzelverzeichnis gefunden."
    echo "        \$LBHOMEDIR ist nicht gesetzt, und oberhalb von $SELF"
    echo "        traegt kein Verzeichnis config/plugins, data/plugins und"
    echo "        config/system/general.json. Es wurde nichts angelegt."
    exit 1
fi
# Der Ordnername ebenso: $LBPPLUGINDIR steht am Geraet zwar nie in der
# Umgebung (Regeln/03, am 17.09.2026 gemessen) - wer sie setzt, meint sie
# ernst. Sonst der eigene Ablageort; bei einer regulaeren Installation ist
# das genau richtig.
if [ -n "${LBPPLUGINDIR:-}" ]; then
    PNAME=$(basename "$LBPPLUGINDIR")
else
    PNAME=$(basename "$SELF")
fi
# Die Gegenprobe steht VOR jedem Schreiben: liegt dieses Skript nicht im
# bin-Ordner des Plugins UND gibt es das Plugin unter dieser Wurzel nicht,
# kommt der Aufruf aus einem ausgepackten Archiv oder einem Pruefordner.
# Dann wird abgebrochen - ein Schutz faellt geschlossen aus (CLAUDE.md 4).
LBH_R=$(readlink -f "$LBHOMEDIR" 2>/dev/null)
if [ "$SELF" != "$LBH_R/bin/plugins/$PNAME" ] \
   && [ ! -d "$LBHOMEDIR/config/plugins/$PNAME" ]; then
    echo "FEHLER: '$PNAME' ist unter $LBHOMEDIR kein eingerichtetes Plugin,"
    echo "        und $SELF ist nicht dessen bin-Ordner. Der Aufruf kommt"
    echo "        offenbar aus einem ausgepackten Archiv oder einem Pruefordner."
    echo "        Es wurde nichts angelegt. Abhilfe: LBHOMEDIR und LBPPLUGINDIR"
    echo "        setzen oder dienst.sh aus <Wurzel>/bin/plugins/<ordner> rufen."
    exit 1
fi
PDATA="$LBHOMEDIR/data/plugins/$PNAME"
PLOG="$LBHOMEDIR/log/plugins/$PNAME"
PCONFIG="$LBHOMEDIR/config/plugins/$PNAME"
PID="$PDATA/dienst.pid"
SOLL="$PDATA/soll_laufen"
# Die Marke "Aktualisierung laeuft". Sie liegt NEBEN dem Datenordner, weil
# purge_installation data/plugins/<ordner>/ zwischen preupgrade.sh und
# postinstall.sh restlos abraeumt (Regeln/06) - im Ordner waere sie genau dann
# fort, wenn sie gebraucht wird. preupgrade.sh legt sie als Erstes an,
# postinstall.sh entfernt sie am Ende (trap), postupgrade.sh ebenso.
MARKE="$LBHOMEDIR/data/plugins/$PNAME.upgrade_laeuft"
LOGDATEI="$PLOG/batteriebms.log"
# Eigene Datei fuer alles, was NEBEN dem Protokoll anfaellt: Meldungen des
# Starts und alles, was das PHP-Skript nach stderr schreibt, bevor sein
# Protokoll steht (Parsefehler, fehlende Erweiterung, Abbruch beim Laden).
#
# Bis 0.9.18 ging diese Ausgabe mit ">> $LOGDATEI" in DIESELBE Datei, in die
# bin/bms_dienst.php schreibt. Das haelt einen zweiten, anhaengenden Deskriptor auf diese
# Datei offen. Verschwindet sie - Ramdisk geleert, log_maint - dann zeigt der
# Deskriptor dieser Shell weiter auf die geloeschte Datei, und was er traegt,
# sieht niemand mehr. Am Geraet gemessen (06.09.2026): PID 532248 hielt batteriebms.log auf
# den Deskriptoren 1 und 2 offen, beide auf der geloeschten Datei.
# Regel: genau einer schreibt in eine Protokolldatei.
STARTLOG="$PLOG/batteriebms_start.log"
# Das Dienstskript der GELESENEN Wurzel, nicht das neben diesem Skript: sonst
# verwaltete eine Datei aus einem Archiv den Dienst des Archivs, waehrend der
# Aufrufer die Installation meinte (H2). Aus der Installation aufgerufen ist
# es derselbe Pfad wie bisher.
SKRIPT="$LBHOMEDIR/bin/plugins/$PNAME/bms_dienst.php"
# Zweite Schreibweise desselben Skripts fuer den Vergleich weiter unten: wurde
# der Dienst ueber einen anderen Weg auf dieselbe Datei gestartet (Symlink im
# Pfad, LBHOMEDIR gegen den aufgeloesten Ablageort), steht in seiner
# Befehlszeile eine andere Zeichenkette fuer dieselbe Datei. Ein Vergleich, der
# das uebersieht, meldet "laeuft nicht" und laesst den Dienst stehen - bei
# einem Speicherregler heisst das: der naechste Start findet die Modbus-
# Verbindung belegt.
SKRIPT_R=$(readlink -f "$SKRIPT" 2>/dev/null)
[ -n "$SKRIPT_R" ] || SKRIPT_R="$SKRIPT"
# Der Dienst laeuft als loxberry; wo es den Benutzer nicht gibt, als der
# eigene. Die Suche ueber /proc sieht nur dessen Prozesse an.
DIENST_UID=$(id -u loxberry 2>/dev/null || id -u)

# Angelegt wird erst beim START (in starten()), nicht bei jedem Aufruf. Bis
# 0.9.23 stand hier 'mkdir -p "$PDATA" "$PLOG"' - auch 'status' und 'stop'
# legten damit Ordner an (H3, H4).

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
# KEIN ERSATZWERT, wenn die Abfrage misslingt. Bis 0.9.9 stand hier ein
# Rueckfall auf 180 s mit dem Kommentar, der Waechter greife dann "spaeter,
# aber nie frueher als vorgesehen". Der Satz war falsch, und zwar ab einem
# Takt von 37 s: die echte Grenze ist max(180, 5*Takt), also groesser als
# 180. Der Rueckfall ist dann KLEINER als die echte Grenze - der Waechter
# greift frueher, nicht spaeter.
#
# Wirksam wird das, sobald das Abbild im gesunden Betrieb aelter als 180 s
# ist, also etwa ab einem Takt von drei Minuten. Dann gilt jeder normale
# Durchlauf als Stillstand, und der Waechter startet den Dienst dauerhaft
# im Kreis - ohne dass irgendetwas abstuerzt oder sich meldet. Genau so
# geschehen an Weissware 0.9.11 (dort alle drei Minuten, belegt am
# 22.08.2026).
#
# Eine Untergrenze ist eben kein Fail safe, sondern ein Zuschlagen. Ein
# Rueckfallwert ist nur dann harmlos, wenn er sicher GROESSER ODER GLEICH
# der echten Grenze ist - und das weiss niemand, wenn sich die Grenze nicht
# lesen laesst.
#
# Rueckgabe: die Grenze in Sekunden, oder LEER mit Rueckgabewert 1.
abbild_grenze() {
    LIB="$LBHOMEDIR/webfrontend/html/plugins/$PNAME/bm_lib.php"
    G=""
    if command -v php >/dev/null 2>&1 && [ -f "$LIB" ]; then
        G=$(LBHOMEDIR="$LBHOMEDIR" php -r 'require $argv[1]; echo bm_waechter_grenze();' "$LIB" 2>/dev/null)
    fi
    case "$G" in
        ''|*[!0-9]*) echo ""; return 1 ;;
    esac
    if [ "$G" -lt 1 ]; then echo ""; return 1; fi
    echo "$G"
    return 0
}

# Wer die Grenze nicht kennt, laesst den Dienst in Ruhe - und SAGT ES.
#
# Ein Waechter, der stillsteht, ohne es zu sagen, ist die naechste stille
# Falschaussage. Die Meldung ist gebremst, hoechstens stuendlich: sonst
# ersetzt man den Neustart-Kreisel durch einen Protokoll-Kreisel, und die
# Logdatei liegt auf einer Ramdisk.
STUMMMERKER="$PDATA/waechter_stumm"

waechter_stumm_melden() {
    JETZT=$(date +%s)
    if [ -f "$STUMMMERKER" ]; then
        SM=$(stat -c %Y "$STUMMMERKER" 2>/dev/null)
        if [ -n "$SM" ] && [ $(( JETZT - SM )) -lt 3600 ]; then
            return 0
        fi
    fi
    touch "$STUMMMERKER"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: Die Schwelle liess sich nicht aus bm_lib.php lesen (kein PHP, Datei fehlt, oder ein Fehler darin). Das Alter des Abbilds wird deshalb NICHT bewertet und der Dienst aus diesem Grund NICHT neu gestartet. Ein Ersatzwert waere hier gefaehrlich: er koennte kleiner sein als die echte Grenze und den Dienst im Kreis neu starten. Erwartet wurde: $LIB" >> "$LOGDATEI"
}

abbild_steht() {
    A=$(abbild_alter)
    [ "$A" -ge 0 ] || return 1
    # Erst die Grenze holen, dann vergleichen. Laesst sie sich nicht lesen,
    # wird NICHT bewertet - und die Stille wird gemeldet.
    GRENZE=$(abbild_grenze) || { waechter_stumm_melden; return 1; }
    [ -n "$GRENZE" ] || { waechter_stumm_melden; return 1; }
    [ "$A" -gt "$GRENZE" ] || return 1
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

# ==================================================================
# Die eigenen Prozesse erkennen
#
# Argumentweise, nicht ueber eine Teilzeichenkette (Regeln/03, "Prozesse
# argumentweise erkennen"). Bis 0.9.23 stand hier
#     grep -qa "bms_dienst.php" "/proc/$P/cmdline"
# und das trifft JEDE Befehlszeile, in der die Zeichenkette irgendwo vorkommt:
# einen Editor mit der Datei offen, ein Sicherungsskript, das den Ordner
# durchsucht, und den Einmallauf des Reiters Test. In WSL gemessen
# (Pruefung-BatterieBMS-0.9.23, Fall 1): ein fremder Prozess
# "tail -f <dienstpfad>", dessen Nummer in der PID-Datei stand, galt als
# Dienst - "status" meldete "laeuft 507313", und nach "stop" war er tot.
#
# Ein Treffer hat GENAU zwei Argumente: argv[0] ist ein PHP, argv[1] ist genau
# der eigene Dienstpfad. Das dritte Argument schliesst die Einmallaeufe aus
# (--einmal, --selbsttest): sie laufen als eigener Prozess, sind aber nicht der
# Dauerlaeufer und duerfen von "stop" nicht getroffen werden (Fall 3). Der
# Dauerlaeufer wird an genau einer Stelle gestartet, in starten(), als
# "nohup php $SKRIPT".
#
# Gelesen wird ohne Hilfsprogramm: "read -d ''" zerlegt die Befehlszeile am
# Nullbyte. Das spart je Prozess einen Aufruf von tr - der Waechter laeuft
# minuetlich.
# ==================================================================
ist_dienst() {
    [ -r "/proc/$1/cmdline" ] || return 1
    {
        IFS= read -r -d '' bm_a0 || return 1
        IFS= read -r -d '' bm_a1 || return 1
        case "${bm_a0##*/}" in php|php[0-9]*) ;; *) return 1 ;; esac
        if [ "$bm_a1" != "$SKRIPT" ]; then
            [ "$(readlink -f "$bm_a1" 2>/dev/null)" = "$SKRIPT_R" ] || return 1
        fi
        IFS= read -r -d '' bm_a2 && return 1
        return 0
    # Die Fehlerausgabe wird VOR der Umleitung stillgelegt, nicht danach: ein
    # Prozess kann zwischen der Auflistung und dem Lesen enden, und dann meldet
    # die Schale "read error: No such process" - in WSL gemessen
    # (Pruefung-BatterieBMS-0.9.23, Fall 1). Die Meldung landete in der
    # Ausgabe von "dienst.sh status" und damit in der Oberflaeche.
    } 2>/dev/null < "/proc/$1/cmdline"
}

# Alle eigenen Dienste, aufsteigend und ohne Dubletten.
#
# Zwei Quellen, weil keine allein reicht:
#   - die Suche ueber /proc findet auch einen Dienst OHNE PID-Datei.
#     purge_installation raeumt data/plugins/<ordner>/ bei jedem Upgrade ab
#     (Regeln/06); der Minutentakt kann in der Luecke einen zweiten starten.
#     In WSL gemessen (Pruefung-BatterieBMS-0.9.23, Fall 4 und 5): "stop"
#     meldete "angehalten", und danach lief noch ein eigener Dienst - der
#     sprach weiter mit einem Speicher, der nur EINE Verbindung zulaesst.
#   - die PID-Datei findet auch einen Dienst, der einem anderen Benutzer
#     gehoert (von Hand als root gestartet) und deshalb durch den
#     Benutzerfilter faellt.
dienste() {
    {
        for bm_d in /proc/[0-9]*; do
            ist_dienst "${bm_d#/proc/}" || continue
            [ "$(stat -c %u "$bm_d" 2>/dev/null)" = "$DIENST_UID" ] || continue
            echo "${bm_d#/proc/}"
        done
        bm_p=""
        [ -f "$PID" ] && IFS= read -r bm_p < "$PID" 2>/dev/null
        case "$bm_p" in
            ''|*[!0-9]*) ;;
            *) ist_dienst "$bm_p" && echo "$bm_p" ;;
        esac
    } | sort -un
}

laeuft() {
    [ -n "$(dienste)" ]
}

# ==================================================================
# Laeuft gerade eine Aktualisierung dieses Plugins?
#
# In WSL gemessen (Pruefung-BatterieBMS-0.9.24, Faelle U1 und U2, 18.09.2026):
# ohne diese Frage startete der Knopf "Dienst starten" bzw. "neu starten" der
# Oberflaeche mitten in der Upgrade-Luecke einen Dienst. Der las die eigenen
# Profile, BEVOR postinstall.sh sie aus der Sicherung zurueckspielt, und
# behielt diesen Stand (bm_profile() in bm_lib.php liest nur einmal je
# Prozess): der Speicher mit dem eigenen Profil wurde nach dem Upgrade NICHT
# mehr ausgelesen, bis jemand den Dienst neu startete. postinstall.sh fand
# den Dienst laufend vor und liess ihn stehen.
#
# Ausgaenge, alle gemessen (Faelle C1 bis C9):
#   Marke juenger als 3600 s -> sie gilt, es wird nicht gestartet
#   aelter, aus der Zukunft, ohne Zeitpunkt -> sie gilt NICHT; eine
#                                 abgebrochene Installation darf den Dienst
#                                 nicht fuer immer stilllegen
#   keine lesbare Uhr             -> sie gilt (geschlossen, CLAUDE.md 4)
#   BM_START_TROTZ_MARKE=1        -> Ausnahme fuer postinstall.sh selbst
#
# Die Ausnahme reicht als Umgebungsvariable auch durch den Abstieg ueber
# "su" oben (su ohne -l laesst die Umgebung bis auf HOME, SHELL, USER und
# LOGNAME stehen) - gelesen, nicht gemessen: in WSL gibt es keinen Benutzer
# loxberry, und die Hakenskripte laufen am Geraet ohnehin als loxberry.
# ==================================================================
marke_gilt() {
    [ -f "$MARKE" ] || return 1
    [ "${BM_START_TROTZ_MARKE:-0}" = "1" ] && return 1
    MJ=$(date +%s 2>/dev/null)
    case "$MJ" in
        ''|*[!0-9]*) return 0 ;;   # keine lesbare Uhr - geschlossen
    esac
    MI=$(head -c 32 "$MARKE" 2>/dev/null | tr -d ' \t\n\r')
    case "$MI" in
        ''|*[!0-9]*) return 1 ;;   # kein Zeitpunkt - die Marke gilt nicht
    esac
    [ "$MI" -gt "$MJ" ] && return 1              # aus der Zukunft
    [ $((MJ - MI)) -lt 3600 ]
}
MARKE_TEXT="Eine Aktualisierung dieses Plugins laeuft - es wird jetzt kein Dienst gestartet. postinstall.sh startet ihn am Ende selbst, falls er vorher lief."

starten() {
    LAUFEND=$(dienste)
    if [ -n "$LAUFEND" ]; then
        ERSTE=$(printf '%s\n' "$LAUFEND" | head -n 1)
        # Die PID-Datei nachziehen, wenn sie fehlt oder veraltet ist. Die
        # Nummer ist argumentweise geprueft - eine ungepruefte Nummer aus einer
        # Mustersuche darf hier nie hinein.
        echo "$ERSTE" > "$PID" 2>/dev/null
        echo "laeuft bereits (PID $ERSTE)"
        return 0
    fi
    # VOR dem touch auf soll_laufen weiter unten: stuende die Frage dahinter,
    # legte der abgewiesene Start den Sollmerker trotzdem an, und der Waechter
    # startete den Dienst eine Minute spaeter doch (Fall C1; an Govee 0.9.19
    # so gemessen). Rueckgabewert 0: eine laufende Aktualisierung ist kein
    # Fehlschlag.
    if marke_gilt; then
        echo "$MARKE_TEXT"
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
    # Der Sollmerker wird VOR dem Start gesetzt und bei einem Fehlstart NICHT
    # entfernt. Begruendete Ausnahme zu Regeln/03 ('Der Sollmerker wird erst
    # nach erfolgreicher Pruefung gesetzt oder im Fehlerzweig entfernt') -
    # Entscheidung des Hausherrn vom 17.09.2026: bei einem Speicherregler ist
    # der erneute Versuch die sicherere Richtung. Die Protokollflut, vor der
    # die Regel warnt, verhindert die 600-s-Bremse im Waechterzweig (B31).
    #
    # Hier, und nur hier, werden Daten- und Protokollordner angelegt (H3, H4).
    mkdir -p "$PDATA" "$PLOG" 2>/dev/null
    touch "$SOLL"
    # Die Ausgabe des Dienstes geht in die Startdatei, NICHT in das Protokoll:
    # dort schreibt allein das Programm selbst. Beim Start gekappt, damit sie
    # nur die Ausgabe EINES Laufes sammelt und nicht unbegrenzt waechst.
    : > "$STARTLOG"
    nohup php "$SKRIPT" >> "$STARTLOG" 2>&1 &
    echo $! > "$PID"
    sleep 1
    if laeuft; then
        echo "gestartet (PID $(cat "$PID"))"
        return 0
    fi
    echo "FEHLER: Start fehlgeschlagen - siehe $STARTLOG und $LOGDATEI"
    rm -f "$PID"
    return 1
}

anhalten() {
    rm -f "$SOLL"
    # ALLE eigenen Dienste, nicht nur den aus der PID-Datei. Ein Waise ohne
    # PID-Datei haelt sonst die Modbus-Verbindung, und der neu gestartete
    # Dienst kommt nicht mehr an das Geraet heran.
    ZIEL=$(dienste)
    if [ -z "$ZIEL" ]; then
        rm -f "$PID"
        echo "laeuft nicht"
        return 0
    fi
    # SIGTERM, damit der Dienst einen laufenden Zwang noch zuruecknehmen kann.
    kill $ZIEL 2>/dev/null
    for i in 1 2 3 4 5 6 7 8 9 10; do
        [ -n "$(dienste)" ] || break
        sleep 1
    done
    # Vor dem harten Signal wird NEU gesucht, nicht die Liste von vorhin
    # wiederverwendet: zwischen den beiden Signalen kann ein Prozess enden und
    # seine Nummer neu vergeben werden, und der Minutentakt kann in der
    # Wartezeit einen zweiten Dienst gestartet haben (Fall 6).
    REST=$(dienste)
    if [ -n "$REST" ]; then
        kill -9 $REST 2>/dev/null
        sleep 1
    fi
    rm -f "$PID"
    # "angehalten" ist eine Zusicherung, kein Rueckgabewert: es wird
    # nachgesehen (Kernschicht 2, "Wirkung pruefen, nicht Rueckgabewert").
    UEBRIG=$(dienste)
    if [ -n "$UEBRIG" ]; then
        echo "FEHLER: Dienst laeuft weiter (PID $(printf '%s' "$UEBRIG" | tr '\n' ' '))"
        return 1
    fi
    echo "angehalten"
    return 0
}

case "$1" in
    start)   starten ;;
    stop)    anhalten ;;
    restart)
        # Bei liegender Marke gar nicht erst anhalten: ein Neustart, der nur
        # die erste Haelfte ausfuehrt, ist ein Anhalten (Fall C9).
        if marke_gilt; then
            echo "$MARKE_TEXT"
            exit 0
        fi
        anhalten; sleep 1; starten ;;
    status)
        # Gemeldet werden die GEFUNDENEN Nummern, nicht der Inhalt der
        # PID-Datei: liegt dort eine fremde oder veraltete Nummer, waere sie
        # eine Falschaussage. Laufen zwei, stehen beide da.
        LAUFEND=$(dienste)
        if [ -n "$LAUFEND" ]; then
            echo "laeuft $(printf '%s' "$LAUFEND" | tr '\n' ' ')"
            exit 0
        fi
        echo "gestoppt"
        exit 1
        ;;
    waechter)
        # Waehrend einer Aktualisierung tut der Waechter NICHTS - weder
        # starten noch den Neustart-Zweig unten (der haelt erst an und startet
        # dann; mit der Marke bliebe davon nur das Anhalten). Fall C7.
        if marke_gilt; then
            exit 0
        fi
        # Nur neu starten, wenn der Dienst laufen SOLL. Ein bewusst
        # angehaltener Dienst bleibt angehalten.
        if [ -f "$SOLL" ] && ! laeuft; then
            # B31: dieser Zweig hatte bis 0.9.15 KEINE Bremse, der zweite
            # dagegen schon. Scheitert 'starten' nach dem touch auf $SOLL,
            # bleibt der Merker stehen und der Versuch wiederholt sich im
            # Minutentakt - jede Minute ein PHP-Anlauf, der kurz eine
            # Modbus-Verbindung oeffnet, an einem Geraet, das nur eine
            # zulaesst. Der Kommentar weiter oben verbietet genau das.
            if [ -f "$NEUSTARTMERKER" ] \
               && [ $(( $(date +%s) - $(stat -c %Y "$NEUSTARTMERKER" 2>/dev/null || echo 0) )) -lt 600 ]; then
                exit 0
            fi
            touch "$NEUSTARTMERKER"
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: Dienst lief nicht, wird neu gestartet." >> "$LOGDATEI"
            starten >> "$STARTLOG" 2>&1
        elif [ -f "$SOLL" ] && laeuft && abbild_steht; then
            # Der Prozess lebt, arbeitet aber nicht mehr. Bis 0.9.6 hat der
            # Waechter genau das nicht gesehen: er fragte nur, ob eine PID da
            # ist. Ein Dienst, der seit einer Stunde kein Abbild mehr
            # geschrieben hat, galt damit als gesund - und in Loxone standen
            # die alten Werte weiter, ohne dass irgendwo etwas davon zu lesen
            # gewesen waere.
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: Der Dienst laeuft (PID $(printf '%s' "$(dienste)" | tr '\n' ' ')), hat aber seit $(abbild_alter) s kein Abbild mehr geschrieben (Grenze $(abbild_grenze) s). Er wird neu gestartet." >> "$LOGDATEI"
            touch "$NEUSTARTMERKER"
            anhalten >> "$STARTLOG" 2>&1
            starten >> "$STARTLOG" 2>&1
        fi
        ;;
    *)
        echo "Aufruf: $0 {start|stop|restart|status|waechter}"
        exit 2
        ;;
esac
