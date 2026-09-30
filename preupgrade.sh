#!/bin/bash
# Batterie-Heimspeicher (BMS) - preupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Wichtig: Der Dienst haelt eine Modbus-Verbindung offen. Manche Speicher
# lassen nur EINE Verbindung gleichzeitig zu (belegt fuer die BYD-BCU). Bleibt
# ein alter Prozess stehen, kommt der neue nicht mehr an das Geraet heran.
ARGV3=$3
PFOLDER="${ARGV3:-batteriebms}"
# Wurzel, in dieser Reihenfolge (Regeln/06: ohne brauchbare Wurzel warnen
# statt vollziehen; dieselbe Regel wie bin/dienst.sh):
#   1. das fuenfte Argument - der Installer uebergibt dort die LoxBerry-Wurzel
#      (plugininstall.pl:1158 und :1577, am Geraet nachgesehen 17.09.2026),
#   2. $LBHOMEDIR, wenn darunter config/plugins und data/plugins liegen,
#   3. vom eigenen Ablageort aufwaerts das erste Verzeichnis mit
#      config/plugins, data/plugins UND config/system/general.json.
# Bauart ZendureSolarFlow 0.9.25/0.9.26.
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
BASE="${5:-}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    if [ -n "${LBHOMEDIR:-}" ] && [ -d "$LBHOMEDIR/config/plugins" ] \
       && [ -d "$LBHOMEDIR/data/plugins" ]; then
        BASE="$LBHOMEDIR"
    else
        BASE=$(bm_wurzel_suchen) || BASE=""
    fi
fi
# Bis 0.9.27 stand hier BASE="${5:-$LBHOMEDIR}" ohne Pruefung: ohne beides
# begannen alle Pfade mit /config/ und /data/, und "rm -f" traf
# /config/plugins/<ordner>.laeuft an der Laufwerkswurzel (in WSL gemessen,
# Pruefung-BatterieBMS-0.9.28, Fall K3). Ohne Wurzel wird jetzt nichts
# angelegt, nichts angehalten und nichts geloescht.
if [ -z "$BASE" ]; then
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden - keine Upgrade-Marke,"
    echo "<WARNING> kein Dienst angehalten, keine Sicherung angelegt."
    exit 1
fi

# ---------- Die Marke "Aktualisierung laeuft" - als Erstes ----------
# Zwischen diesem Skript und postinstall.sh liegt am Geraet fast eine Minute
# (Regeln/06: preupgrade 03:31:30, Cron neu 03:31:32, postinstall 03:32:24);
# die neuen Dateien liegen dann schon bereit, der Datenordner ist leer. Der
# Knopf "Dienst starten" startete dort einen Dienst, der den Speicher mit
# einem eigenen Profil danach nicht mehr auslas (Pruefung-BatterieBMS-0.9.24,
# Faelle U1/U2). bin/dienst.sh startet nicht, solange die Marke juenger als
# 3600 s ist; postinstall.sh entfernt sie am Ende.
#
# NEBEN dem Datenordner, sonst loescht purge_installation sie mit. Nur, wenn
# der Ablageort bekannt ist - ohne BASE entstuende sie unter /data.
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -n "$BASE" ] && [ -d "$BASE/data" ]; then
    mkdir -p "$BASE/data/plugins" 2>/dev/null
    date +%s > "$MARKE" 2>/dev/null
fi
if [ -n "$BASE" ] && [ -s "$MARKE" ]; then
    echo "<OK> Dienststart bis zum Ende der Aktualisierung gesperrt."
else
    echo "<WARNING> Die Marke $MARKE liess sich nicht anlegen - der Dienst"
    echo "<WARNING> koennte waehrend der Aktualisierung anlaufen."
fi

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

# ---------- Die eigenen Prozesse erkennen ----------
#
# Argumentweise (Regeln/03, "Prozesse argumentweise erkennen"): argv[0] ist ein
# PHP, argv[1] genau der eigene Dienstpfad, ein drittes Argument gibt es nicht.
# Bis 0.9.23 stand im Rueckfallweg unten das erste Signal ganz ohne Pruefung
# und vor dem harten eine Teilzeichenkettensuche (grep -qa "bms_dienst.php").
# In WSL gemessen (Pruefung-BatterieBMS-0.9.23, Fall 9): ein fremder Prozess
# "tail -f <dienstpfad>", dessen Nummer in der PID-Datei stand, war nach
# preupgrade.sh tot.
#
# Die zweite Schreibweise deckt den Fall ab, dass der Dienst ueber einen
# anderen Pfad auf dieselbe Datei gestartet wurde (bin/dienst.sh loest seinen
# Ablageort mit readlink -f auf, hier kommt er aus $5).
BM_SKRIPT="$BASE/bin/plugins/$PFOLDER/bms_dienst.php"
BM_SKRIPT_R=$(readlink -f "$BM_SKRIPT" 2>/dev/null)
[ -n "$BM_SKRIPT_R" ] || BM_SKRIPT_R="$BM_SKRIPT"
BM_UID=$(id -u loxberry 2>/dev/null || id -u)

bm_ist_dienst() {
    [ -r "/proc/$1/cmdline" ] || return 1
    {
        IFS= read -r -d '' bm_a0 || return 1
        IFS= read -r -d '' bm_a1 || return 1
        case "${bm_a0##*/}" in php|php[0-9]*) ;; *) return 1 ;; esac
        if [ "$bm_a1" != "$BM_SKRIPT" ]; then
            [ "$(readlink -f "$bm_a1" 2>/dev/null)" = "$BM_SKRIPT_R" ] || return 1
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

# Alle eigenen Dienste des eigenen Benutzers - auch die ohne PID-Datei.
bm_dienste() {
    for bm_d in /proc/[0-9]*; do
        bm_ist_dienst "${bm_d#/proc/}" || continue
        [ "$(stat -c %u "$bm_d" 2>/dev/null)" = "$BM_UID" ] || continue
        echo "${bm_d#/proc/}"
    done
    return 0
}

# Beendet sie: freundlich, bis zu fuenfzehn Sekunden Zeit, dann hart - und vor
# JEDEM Signal wird neu gesucht, auch vor dem kill -9. Gibt die Nummern aus,
# die beim ersten Signal gemeint waren.
bm_dienste_beenden() {
    bm_ziel=$(bm_dienste)
    [ -n "$bm_ziel" ] || return 0
    kill $bm_ziel 2>/dev/null
    bm_i=0
    while [ $bm_i -lt 15 ] && [ -n "$(bm_dienste)" ]; do
        sleep 1
        bm_i=$((bm_i + 1))
    done
    bm_rest=$(bm_dienste)
    [ -n "$bm_rest" ] && kill -9 $bm_rest 2>/dev/null
    echo $bm_ziel
}
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
    # LBHOMEDIR ausdruecklich: dienst.sh haelt nur an, was unter DIESER
    # Wurzel installiert ist (INSTALLIERT in bin/dienst.sh).
    AUSGABE=$(LBHOMEDIR="$BASE" "$DIENST" stop 2>&1)
    RC=$?
    if [ $RC -eq 0 ]; then
        echo "<INFO> dienst.sh stop: $AUSGABE"
    elif [ $RC -eq 3 ]; then
        # C5/I4: angehalten, aber ein Zwang blieb offen - die <WARNING> dazu
        # folgt unten mit dem Speichernamen.
        echo "<INFO> dienst.sh stop: $AUSGABE"
    else
        echo "<FAIL> dienst.sh stop meldete Rueckgabewert $RC: $AUSGABE"
        echo "<FAIL> Ein alter Prozess haelt moeglicherweise noch die Verbindung"
        echo "<FAIL> zum Speicher. Manche Geraete lassen nur EINE zu."
    fi
else
    # Rueckfallebene, falls dienst.sh fehlt: von Hand, aber mit Geduld.
    if [ -f "$PID" ]; then
        P=$(cat "$PID" 2>/dev/null)
        # Geprueft wird VOR dem ersten Signal, nicht erst vor dem harten.
        # Prozessnummern werden wiederverwendet: liegt eine alte PID-Datei
        # herum und traegt ihre Zahl inzwischen einen fremden Vorgang, traf
        # das erste Signal genau den.
        if [ -n "$P" ] && kill -0 "$P" 2>/dev/null && bm_ist_dienst "$P"; then
            kill "$P" 2>/dev/null || true
            i=0
            while [ $i -lt 15 ] && kill -0 "$P" 2>/dev/null && bm_ist_dienst "$P"; do
                sleep 1
                i=$((i + 1))
            done
            # Vor dem harten Signal erneut pruefen - der Dienst kann inzwischen
            # weg und die Nummer neu vergeben sein.
            if kill -0 "$P" 2>/dev/null && bm_ist_dienst "$P"; then
                kill -9 "$P" 2>/dev/null || true
            fi
            # Nur HIER gemeldet: eine liegengebliebene PID-Datei allein ist kein
            # laufender Dienst. Bis 0.9.19 stand die Zeile hinter dem schliessenden
            # fi - der Zweig darueber wertet Rueckgabewert UND Ausgabe aus (B28),
            # diese Rueckfallebene tat es nicht.
            echo "<INFO> Laufender Dienst angehalten (Rueckfallebene ohne dienst.sh)."
        elif [ -n "$P" ] && kill -0 "$P" 2>/dev/null; then
            echo "<INFO> Die Nummer $P aus der PID-Datei gehoert einem fremden"
            echo "<INFO> Vorgang - es wurde nichts beendet, die Datei wird entfernt."
        else
            echo "<INFO> Der Dienst lief nicht - es war nichts anzuhalten."
        fi
        rm -f "$PID"
    fi
    # Dazu jeder eigene Dienst OHNE PID-Datei. purge_installation raeumt
    # data/plugins/<ordner>/ bei jedem Upgrade ab (Regeln/06), der Minutentakt
    # kann in der Luecke einen zweiten starten. In WSL gemessen
    # (Pruefung-BatterieBMS-0.9.23, Fall 9): ohne diesen Schritt lief er durch
    # das ganze Upgrade weiter - mit offener Modbus-Verbindung an einem
    # Speicher, der nur eine zulaesst.
    WAISEN=$(bm_dienste_beenden)
    if [ -n "$WAISEN" ]; then
        echo "<INFO> Ein Dienst ohne PID-Datei lief und wurde beendet (PID $WAISEN)."
    fi
fi

# ---------- Offener Zwang nach dem Anhalten (I4, Durchgang 29.09.2026) ----------
# Liegt nach "dienst.sh stop" noch eine Sollwertdatei, hat der Dienst einen
# Zwang NICHT zuruecknehmen koennen (Speicher antwortet nicht). Bis 0.9.29
# ging sie mit dem Datenordner verloren (purge_installation), und der neue
# Dienst wusste nichts mehr davon (gemessen, Faelle u5, u5b, u6). Jetzt wird
# sie mitgesichert (unten), und diese Zeile nennt den Speicher.
PDATA="$BASE/data/plugins/$PFOLDER"
CF="$BASE/config/plugins/$PFOLDER/batteriebms.json"
bm_zwang_klartext() {
    set -- "$PDATA"/soll_geraet*.json
    [ -e "$1" ] || return 0
    if command -v php >/dev/null 2>&1; then
        php -r '$g = json_decode((string) @file_get_contents($argv[2]), true); $t = array();
            foreach ((array) glob($argv[1] . "/soll_geraet*.json") as $f) {
                if (!preg_match("/soll_geraet([0-9]{1,3})\.json$/", $f, $m)) { continue; }
                $n = (int) $m[1];
                $s = json_decode((string) @file_get_contents($f), true);
                $name = "";
                if (is_array($s) && isset($s["anschrift"]["name"]) && is_string($s["anschrift"]["name"])) {
                    $name = $s["anschrift"]["name"];
                } elseif (is_array($g) && isset($g["geraete"][$n - 1]["name"]) && is_string($g["geraete"][$n - 1]["name"])) {
                    $name = $g["geraete"][$n - 1]["name"];
                }
                $a = (is_array($s) && isset($s["aktion"]) && is_string($s["aktion"])) ? $s["aktion"] : "?";
                $name = preg_replace("/[\\x00-\\x1F\\x7F]/", "", $name);
                $t[] = ($name !== "" ? $name . " " : "") . "(Speicher " . $n . ", " . $a . ")";
            }
            echo implode(", ", $t);' "$PDATA" "$CF" 2>/dev/null && return 0
    fi
    for f in "$@"; do printf '%s ' "${f##*/}"; done
}
ZWANG_OFFEN=$(bm_zwang_klartext)
if [ -n "$ZWANG_OFFEN" ]; then
    echo "<WARNING> Zwang NICHT zurueckgenommen: $ZWANG_OFFEN. Der Speicher steht moeglicherweise"
    echo "<WARNING> noch im Lade- oder Entladezwang - bitte am Geraet pruefen. Der Sollwert wird"
    echo "<WARNING> gesichert, und der Dienst nach dem Update versucht die Ruecknahme erneut."
fi

# ---------- Konfiguration sichern ----------
# B27: bis 0.9.15 wurde der Rueckgabewert von cp nicht geprueft und danach
# bedingungslos "<OK>" gemeldet. Scheitert das Kopieren (volle Platte,
# Rechte), ist die gesamte Konfiguration weg - Geraete, Adressen,
# Aktionstoken - und im Installationsprotokoll steht, alles sei in Ordnung.
#
# I2 (Durchgang 29.09.2026): VOR dem Sichern den Inhalt pruefen, wie
# postinstall.sh vor dem Zurueckspielen (bm_hat_inhalt). Bis 0.9.29
# ueberschrieb eine beschaedigte Konfiguration die gute Zweitschrift - die
# einzige heilbare Rueckfallkopie (gemessen, Fall u3). Taugt die
# Konfiguration nicht, bleibt die Zweitschrift stehen, und das Bruchstueck
# geht nach .backup.kaputt.
#
# I3: die Sicherung wird NEBEN ihrem Platz gebaut (.neu), geprueft und erst
# dann per mv ersetzt (Regeln/06). Bis 0.9.29 schrieb cp -p unmittelbar auf
# die bisherige Zweitschrift. Ein Abbruch endet mit exit 2: plugininstall.pl
# bricht erst bei einem Rueckgabewert groesser 1 VOR purge_installation ab
# (:856-866); mit exit 1 wurde trotzdem abgeraeumt (Fall u8).
bm_hat_inhalt() {
    command -v php >/dev/null 2>&1 || return 1
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
            exit(is_array($d) && isset($d["aktionstoken"]) && is_string($d["aktionstoken"])
                 && preg_match("/^[A-Za-z0-9_.\-]{1,64}\z/", $d["aktionstoken"]) ? 0 : 1);' "$1" 2>/dev/null
}
FEHLER=0
BK="$BASE/config/plugins/$PFOLDER.backup.json"
if [ -f "$CF" ]; then
    A=$(wc -c < "$CF" 2>/dev/null)
    if ! bm_hat_inhalt "$CF"; then
        if ( umask 077; cp "$CF" "$BK.kaputt" ) 2>/dev/null; then
            KAPUTT=" Das Bruchstueck liegt unter $BK.kaputt."
        else
            KAPUTT=""
        fi
        if [ -f "$BK" ] && bm_hat_inhalt "$BK"; then
            echo "<WARNING> Die Konfiguration traegt keinen gueltigen Inhalt (kein lesbares JSON mit"
            echo "<WARNING> Aktionstoken, $A Byte). Die bisherige Zweitschrift bleibt stehen und wird"
            echo "<WARNING> nach dem Update zurueckgespielt.$KAPUTT"
        else
            echo "<WARNING> Die Konfiguration traegt keinen gueltigen Inhalt ($A Byte), und es gibt"
            echo "<WARNING> keine brauchbare Zweitschrift - nach dem Update muessen die Speicher neu"
            echo "<WARNING> eingetragen werden.$KAPUTT"
        fi
    else
        rm -f "$BK.neu"
        if ( umask 077; cp "$CF" "$BK.neu" ) 2>/dev/null && cmp -s "$CF" "$BK.neu" \
           && chmod 600 "$BK.neu" 2>/dev/null && mv -fT "$BK.neu" "$BK" 2>/dev/null && cmp -s "$CF" "$BK"; then
            echo "<OK> Konfiguration gesichert ($A Byte)."
        else
            rm -f "$BK.neu"
            echo "<FAIL> Die Konfiguration liess sich NICHT vollstaendig sichern ($CF)."
            echo "<FAIL> Die bisherige Zweitschrift bleibt unveraendert. Das Update wird"
            echo "<FAIL> abgebrochen - der Installer wuerde config/plugins/$PFOLDER/ sonst abraeumen."
            FEHLER=1
        fi
    fi
fi

# ---------- Eigene Profile, Verlauf und offene Sollwerte sichern ----------
# Alles liegt unter data/plugins/<ordner>/ und wird vom Installer bei JEDEM
# Upgrade abgeraeumt. Bis 0.9.15 stand nur die Konfigurations-JSON auf der
# Liste: hochgeladene Profile und der ganze Verlauf waren nach einer
# Aktualisierung weg, und kein Text sagte es dem Anwender. Schlimmer noch,
# die mitgelieferte Beispieldatei wird neu ausgeliefert - der Ordner sieht
# hinterher heil aus.
# I4: dazu die offenen Sollwerte und die Nachholauftraege.
DBK="$BASE/config/plugins/$PFOLDER.backup.daten.tar"
TEILE=""
[ -d "$PDATA/profile" ] && TEILE="$TEILE profile"
[ -d "$PDATA/verlauf" ] && TEILE="$TEILE verlauf"
for f in "$PDATA"/soll_geraet*.json "$PDATA"/nachhol_geraet*.json; do
    [ -f "$f" ] && TEILE="$TEILE ${f##*/}"
done
if [ -n "$TEILE" ]; then
    rm -f "$DBK.neu"
    if ( umask 077; cd "$PDATA" && tar cf "$DBK.neu" $TEILE ) 2>/dev/null \
       && tar tf "$DBK.neu" >/dev/null 2>&1 && chmod 600 "$DBK.neu" 2>/dev/null \
       && mv -fT "$DBK.neu" "$DBK" 2>/dev/null && [ -f "$DBK" ]; then
        ZAHL=$(tar tf "$DBK" 2>/dev/null | grep -c '[^/]$')
        echo "<OK> Eigene Profile, Verlauf und offene Sollwerte gesichert ($ZAHL Datei(en):$TEILE)."
    else
        rm -f "$DBK.neu"
        echo "<FAIL> Profile, Verlauf und offene Sollwerte liessen sich nicht sichern ($DBK)."
        echo "<FAIL> Sie wuerden beim Update verlorengehen - Abbruch."
        FEHLER=1
    fi
else
    rm -f "$DBK"
    echo "<INFO> Keine eigenen Profile, kein Verlauf und kein offener Sollwert vorhanden."
fi

if [ $FEHLER -ne 0 ]; then
    exit 2
fi
if [ -n "$ZWANG_OFFEN" ]; then
    # I4: mit offenem Zwang kein <OK> - die <WARNING> oben ist die Aussage.
    echo "<INFO> preupgrade beendet - mit offenem Zwang (siehe <WARNING> oben)."
    exit 0
fi
echo "<OK> preupgrade abgeschlossen."
exit 0
