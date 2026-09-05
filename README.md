# LoxBerry-Plugin: Batterie-Heimspeicher (BMS)

Liest das **Batterie-Management-System** eines Hausspeichers unmittelbar aus –
Zellspannungen, Zelltemperaturen, Zyklenzahl, Gesundheitszustand – und kann den
Speicher auf Wunsch zum Laden oder Entladen zwingen, etwa in Verbindung mit
einem Spotpreistarif.

Wechselrichter lassen sich meist per Modbus auslesen. Der Speicher dahinter aber
nur so weit, wie der Wechselrichter ihn durchreicht: Ladezustand und Leistung
ja, die einzelne Zelle so gut wie nie. Wer wissen will, ob eine Zelle abfällt,
muss das BMS selbst fragen.

## Was es kann

| | |
|---|---|
| **Messwerte** | Ladezustand, Gesundheitszustand, Batteriespannung und -strom, Leistung, höchste und niedrigste Zelltemperatur, höchste und niedrigste Zellspannung, **Zelldrift**, Zyklen, Kapazität, Fehler- und Warnbits |
| **Je Modul** | Spannung jeder einzelnen Zelle, Spannungsspanne, Temperaturen — dazu **welche** Zelle die schwächste ist |
| **Abgeleitet** | Restenergie in kWh und geschätzte Restzeit bis voll oder leer; ein **Sammelmerker** mit Klartext, wenn Drift, Temperatur oder Fehlerbits auffällig sind |
| **Steuerung** | Laden und Entladen erzwingen, mit Leistungsgrenze, Ladefenster, Schreibbremse und **Totmannschaltung**. Jeder Schreibbefehl wird gegen die Antwort des Geräts gehalten; ein **Trockenlauf** zeigt vorher, welche Register beschrieben würden |
| **Ausgabe** | MQTT über das LoxBerry-Gateway (mit Zeitstempel `ts`) und ein tokengeschützter HTTP-Endpunkt für den Miniserver (mit `ALTER` in Sekunden – über MQTT wäre das Alter beim Senden immer null) |
| **Loxone** | Fertige Importdateien für virtuelle Eingänge und Ausgänge — nur mit den Größen, die der jeweilige Speicher wirklich liefert —, dazu eine vollständige Baustein-Liste zum 1:1-Nachbauen |
| **Fehlersuche** | Rohregister lesen, Mitschnitt des Datenverkehrs, Selbstprüfung, die den eigenen Endpunkt wirklich aufruft |

## Unterstützte Speicher

| Profil | Weg | Stand |
|---|---|---|
| **BYD Battery-Box Premium** HVS, HVM, LVS, LVL | Modbus RTU im TCP-Strom, `192.168.16.254:8080` | dokumentiert |
| **Huawei LUNA2000** (Grunddaten über SUN2000) | Modbus TCP, Port 502 | dokumentiert |
| **Huawei LUNA2000** (Batteriepakete) | Modbus TCP, Port 502 | **ungeprüft** |
| **Pylontech** US2000, US3000, US5000, Force | RS485-Konsole, 115200 Baud | dokumentiert |
| **Frei einstellbar** | Modbus TCP oder RTU | Vorlage |

Weitere Speicher kommen als **JSON-Profildatei** dazu, ohne Änderung am
Quelltext. Der Reiter Einstellungen lädt jedes vorhandene Profil als Vorlage
herunter und nimmt eine geänderte Datei dort auch wieder entgegen — geprüft
wird sie beim Annehmen, und was nicht vollständig ist, wird abgewiesen und
begründet.

Jedes Profil trägt zwei Angaben, die die Oberfläche ungeschönt anzeigt:

* **quelle** – wo die Registeradressen herkommen
* **stand** – `dokumentiert` (die Quelle ist benannt und nachlesbar) oder
  `unbestaetigt` (im Umlauf, aber hier nicht gemessen). **Genau so
  geschrieben, ohne Umlaut** – der Prüfer der Oberfläche vergleicht mit
  dieser Zeichenkette und weist `unbestätigt` ab.

Eine Zahl, die niemand gemessen hat, darf nicht aussehen wie eine, die jemand
gemessen hat.

## Voraussetzungen

* LoxBerry ab 3.0.0 (das MQTT-Gateway ist seit LoxBerry 3 Systembestandteil,
  kein Plugin)
* PHP – bringt LoxBerry mit. Kein Python, keine virtuelle Umgebung, damit auch
  kein Umweg um PEP 668.
* Für die MQTT-Veröffentlichung die PHP-Erweiterung `sockets`. Fehlt sie, läuft
  Modbus trotzdem; nur veröffentlicht wird nichts.
* Für Pylontech: ein USB-RS485-Adapter, `stty`, und der Benutzer `loxberry` in
  der Gruppe `dialout`. Die Installation trägt ihn ein, die Gruppe wirkt nach
  einem Neustart.

### BYD: statische Route

Die BCU hört fest auf `192.168.16.254` und liegt damit außerhalb des
Heimnetzes. Im Router eine statische Route auf `192.168.16.0/24` über die
DHCP-Adresse der Box eintragen.

**Es kommt immer nur eine Anwendung gleichzeitig an die Box.** Solange
BE Connect Plus oder ein Node-RED-Ablauf läuft, bleibt dieses Plugin draußen –
und umgekehrt.

## Einrichtung

1. **Einstellungen** – Speicher eintragen, Profil wählen, speichern, dann
   *Dienst starten*.
2. **Test** – die Selbstprüfung sagt Zeile für Zeile, ob die Einrichtung trägt.
   Jedes Kreuz nennt die Abhilfe mit.
3. **Test, Rohregister lesen** – bevor Sie einem Profil glauben: Register lesen
   und den Wert gegen die Anzeige des Herstellers halten.
4. **MQTT** – das Abo im MQTT-Gateway eintragen. *Ohne diesen Eintrag kommt am
   Miniserver nichts an.*
5. **Einbindung in Loxone** – Schritt für Schritt, mit Importdatei und
   Baustein-Liste.

## Steuerung

Schreibende Befehle brauchen **zwei** Freigaben: den Haken im Reiter
Einstellungen und die Spalte *Schreiben* beim einzelnen Speicher. Zusätzlich
gelten:

* eine **Leistungsgrenze** je Speicher – ein höherer Wert wird abgewiesen,
  nicht gekappt;
* ein **Ladefenster** – auf oder oberhalb des eingestellten Ladezustands wird ein
  Ladezwang abgewiesen, unterhalb ein Entladezwang;
* eine **Schreibbremse** – Mindestabstand zwischen zwei Befehlen an dasselbe
  Gerät;
* eine **Totmannschaltung** – bleibt länger als die eingestellte Zeit ein
  Lebenszeichen aus, geht der Speicher von selbst in die Automatik zurück.
  Auch beim Anhalten des Dienstes wird jeder laufende Zwang zurückgenommen.

`watt=0` ist etwas anderes als „laden mit 0 Watt": es beendet den Zwang.

## Endpunkt für den Miniserver

```
/plugins/batteriebms/index.php?token=<TOKEN>&aktion=<Befehl>
```

| Aktion | Art | Wirkung |
|---|---|---|
| `status` | lesend | alle Messgrößen eines Speichers, eine Zeile |
| `zellen` | lesend | Spannungsspanne und Temperaturen je Modul |
| `liste` | lesend | alle eingerichteten Speicher |
| `summe` | lesend | alle Speicher zusammengefasst, Ladezustand nach Kapazität gewichtet |
| `roh` | lesend | vollständiges Abbild als JSON |
| `laden` `&watt=` | schaltend | Laden erzwingen; 0 gibt die Regie zurück |
| `entladen` `&watt=` | schaltend | Entladen erzwingen |
| `automatik` | schaltend | Zwang sofort beenden |
| `lebenszeichen` | schaltend | Sollwert am Leben halten |
| `abruf` | schaltend | sofort abrufen statt auf den Takt zu warten |
| `sperren` | schaltend | nicht entladen (erzwungenes Entladen mit 0 W) |
| `evcc` | lesend | `soc`, `power`, `capacity` als JSON, Vorzeichen wie EVCC es erwartet |
| `batteriemodus` `&modus=` | schaltend | Betriebsart für EVCC: 1 normal, 2 halten, 3 aus dem Netz laden |
| `?selftest=1` | – | Selbsttest: `SELFTEST;OK=1;TOKEN=OK`, bei falschem Token 403 `ERR=TOKEN`, ohne eingerichtetes Token 403 `ERR=KEIN_TOKEN_EINGERICHTET`. Löst nichts aus |

Das Token wird beim ersten Öffnen der Oberfläche erzeugt und mit `hash_equals`
verglichen, also in gleichbleibender Zeit. Unbekannte Aktionen und Werte mit
unerlaubten Zeichen werden abgewiesen, nicht zurechtgebogen.

Der Endpunkt spricht **nie** selbst mit einem Speicher: lesende Aufrufe
beantwortet er aus dem Zwischenspeicher, schaltende legt er in einer
Warteschlange ab, die der Dienst abarbeitet. Sonst entstünde eine zweite
Verbindung zum Gerät – und mehrere Speicher lassen nur eine zu.

Ein **Strich** statt einer Zahl bedeutet: der Speicher hat dieses Feld nicht
geliefert. Es wird bewusst keine 0 gesendet.

## Was diese Fassung nicht belegen kann

Sie ist an **keinem** Speicher gemessen worden.

**Belegt:**

* der Modbus-CRC16 gegen den Prüffall der Modbus-Spezifikation
  (`02 07` → `4112`);
* das Längenfeld und die Prüfsumme eines Pylontech-Rahmens **gegen sich
  selbst** – der Rahmen ist in sich stimmig und hat die vorgeschriebene Form
  (20 Zeichen, `~` am Anfang, CR am Ende). **Gegen die Originalbibliothek und
  gegen ein echtes Modul ist er NICHT gemessen**; die Ausgabe des Selbsttests
  sagt das ausdrücklich;
* die Registerumrechnung für s16, u32, f32 und Maskenfelder gegen feste
  Prüfwerte, dazu die Bitentnahme für FC 1 gegen das Beispiel der
  Spezifikation (`CD 6B 05`);
* die Spiegelung einer Schreibantwort in vier Fällen;
* dass kein Profil zwei Messgrößen aus demselben Register liest.

Diese Prüfungen stecken als `--selbsttest` im Plugin und sind im Reiter Test
als Knopf erreichbar; es sind **18 Fälle**. Die erzeugte Loxone-Importdatei
wird gesondert geprüft, im Reiter Test, und zwar auf Wohlgeformtheit – CRLF,
Tabulatoren und Attributreihenfolge prüft dort **keine** Zeile.

> Bis 0.9.15 stand an dieser Stelle mehr, als das Plugin hält: ein Vergleich
> „byteweise gegen die Originalbibliothek über 448 Kombinationen", ein
> „unabhängiges Tabellenverfahren" für den CRC und vier Prüfungen der
> Importdatei. Keines davon steckt im Selbsttest. Die Zahl 448 kommt im
> ganzen Plugin nicht vor.

**Nicht belegt:**

* ob die Registeradressen zu Ihrer Gerätefirmware passen;
* ob Ihr Gerät den gewählten Übertragungsweg spricht (Modbus TCP mit MBAP-Kopf
  gegen Modbus RTU im TCP-Strom);
* in welcher Richtung Ihr Gerät Strom und Leistung als positiv meldet – dafür
  gibt es einen Schalter statt einer Annahme;
* ob die Schreibbefehle am Gerät wirken und was sie dort tatsächlich tun.

Vor dem ersten Zwangsbefehl: Rohregister lesen, Werte gegen die
Herstelleranzeige halten, mit kleiner Leistung anfangen und am Gerät nachsehen.

## Fassung 0.9.16 — die Durchsicht vom 04./05.09.2026

Diese Fassung behebt 43 Befunde aus einer vollständigen Durchsicht. Keiner
davon ist an einem Speicher gemessen worden — es ist keiner angeschlossen.
Gemessen ist alles an den Dateien, an nachgebauten Prüfständen und an zwei
fremden Quellen.

### Was Sie prüfen sollten, wenn Sie aktualisieren

* **Wer das Profil *Huawei LUNA2000 — Batteriepaket 1* benutzt, bekommt
  andere Zahlen.** Sieben der acht Registeradressen waren falsch. Die
  Messgrößen `SOH`, `UZMAX` und `UZMIN` entfallen ersatzlos — es gibt sie in
  diesem Adressraum nicht; die zugehörigen virtuellen Eingänge in Loxone
  bekommen keinen Wert mehr. Sie haben allerdings auch bisher keinen
  richtigen bekommen. Die Importvorlage im Reiter *Einbindung in Loxone* bitte
  neu erzeugen.
* **Neue Messgröße `TBAT`** (Temperatur des Speichers als Ganzes, neben
  `TMAX`/`TMIN` für die Zellen). Sie hängt hinten an, alle bestehenden Felder
  behalten ihre Stelle und ihren Suchtext.
* **Die Gruppe `dialout` wird nicht mehr bei der Installation gesetzt.** Wer
  Pylontech über RS485 fährt, holt das einmal nach:
  `sudo usermod -a -G dialout loxberry && sudo reboot`. Der Reiter *Test* sagt,
  ob es nötig ist.
* **Neu: `uninstall/uninstall`.** Beim Deinstallieren wird jetzt auch die
  Zweitschrift der Konfiguration entfernt. Bisher überlebte sie das Entfernen
  des Plugins, und eine Neuinstallation holte die alten Einstellungen wortlos
  zurück. Wer seine Einstellungen behalten will, sichert sie vorher über den
  Knopf *Einstellungen sichern*.

### Konfiguration und Sicherung

* **Eine beschädigte Konfiguration vernichtet nicht mehr die Zweitschrift.**
  Bisher entschied die Selbstheilung nach der Form („leer oder `{}`"). Eine
  halb geschriebene Datei — der Fall nach einem Stromausfall oder auf einer
  vollen Platte — galt damit als heil, das Aktionstoken war fort, die
  Oberfläche würfelte ein neues, und dieses wurde über die Zweitschrift
  kopiert. Gemessen: nach **einem** Öffnen der Oberfläche waren Konfiguration
  und Zweitschrift auf Werkseinstellung, ohne eine Zeile im Protokoll; jede
  Adresse im Miniserver war stumm ungültig. Jetzt entscheidet der Inhalt, die
  beschädigte Datei bleibt als `.kaputt` liegen, es gibt genau eine
  Protokollzeile, und die Zweitschrift wird nur mitgezogen, wenn der
  geschriebene Stand ein Token trägt.
* **Das Zurückspielen prüft jeden Wert**, nicht nur die Schlüsselnamen — mit
  derselben Tabelle, die auch das Formular benutzt. Bisher ging eine Datei mit
  `soc_max = 9999` durch, und damit war die Ladeschranke des Dienstes
  stillgelegt. Eine halb gültige Datei ändert **gar nichts**.
* **Eine Sicherung ohne Aktionstoken löscht das bestehende nicht mehr.** Sie
  heißt „kein Token gesichert", nicht „Token löschen".
* Ein fehlgeschlagenes Speichern im Reiter *MQTT* wird gemeldet. Bisher blieb
  es stumm.
* Wird das Speichern der Einstellungen wegen einer beanstandeten Gerätezeile
  abgelehnt, steht jetzt dabei, dass auch die **übrigen** Felder nicht
  gespeichert wurden.

### Endpunkt für den Miniserver

* **Der unangemeldete Endpunkt legt nichts mehr an.** Bisher rief er die
  Konfiguration vor der Tokenprüfung; die Selbstheilung darin legte den
  Konfigurationsordner an und spielte die Zweitschrift zurück. Gemessen: ein
  Aufruf ohne jedes Token antwortete mit 403 — und erzeugte die
  Konfigurationsdatei samt altem Token.
* **`?selftest=1` gibt es jetzt**, mit den drei festgelegten Antworten.
* **Die Prüfung der Anfrage steht vor der Prüfung des Dienstes.** Bisher
  bekamen `laden` ohne `watt` und ein unbekannter `modus` die Antwort
  „Dienst läuft nicht" — und der Bediener startete den Dienst, statt seinen
  Aufruf zu berichtigen.
* **Abgewiesene Aufrufe hinterlassen eine Zeile im Protokoll** (gebremst, mit
  der Adresse des Anrufers, nie mit der Zugangsmarke). Bisher gab es auf
  keinem Weg einen Eintrag: „der Miniserver ruft nicht an" ließ sich nicht von
  „er ruft an und wird abgewiesen" unterscheiden.
* Fünf Adressen, die der Endpunkt seit jeher beantwortet, stehen jetzt auch im
  Reiter *Einbindung in Loxone* zum Abschreiben: `summe`, `evcc`, `sperren`,
  `abruf` und `batteriemodus`.

### Steuerung und Dienst

* **Ein Zwang wird nicht mehr vergessen, wenn seine Rücknahme scheitert.**
  Totmannschaltung und Dienstende löschten die Sollwertdatei bisher
  bedingungslos — auch dann, wenn der Speicher gerade nicht erreichbar war.
  Damit blieb das Gerät im Zwang, und die Totmannschaltung konnte es nie
  wieder versuchen. Beim Anhalten läuft dieser Weg bei **jedem** Update.
* **Die Schreibbremse meldet keinen Erfolg mehr für einen Befehl, der nicht
  abgesetzt wurde.** Er wird vorgemerkt und nachgeholt, sobald die Bremse
  abgelaufen ist; verfällt er vorher, steht das im Protokoll.
* **Bricht eine Schrittfolge nach dem ersten Schritt ab**, wird die Automatik
  versucht; gelingt auch die nicht, bleibt ein Merker stehen, damit
  Totmannschaltung und Dienstende es erneut versuchen. Bisher stand das Gerät
  im Zwangsbetrieb, während das Plugin „kein Zwang" meldete.
* **Modbus TCP prüft jetzt den MBAP-Kopf** (Vorgangsnummer, Geräteadresse,
  Funktionscode). Eine verspätete Antwort auf eine frühere Frage wurde bisher
  als Messwert des aktuellen Blocks übernommen.
* **Ein Sollwert über 65535 wird abgewiesen statt gekappt.**
* Die Wortreihenfolge (`wort`) gilt jetzt auch beim **Schreiben**.
* Das Leerräumen der seriellen Schnittstelle hat eine Zeitschranke: ein
  Dauerpegel auf der RS485-Leitung hängt den Dienst nicht mehr fest.

### Registerumrechnung und Profile

* **Maske und Schieben wirken nicht auf 32-Bit-Typen** — das ist unverändert,
  aber jetzt beschrieben: ein Profil mit `maske` auf `u32`/`s32`/`f32` bekam
  kommentarlos den ungemaskten Wert.
* **BYD: `TMAX` und `TMIN` werden vorzeichenbehaftet gelesen.** Bisher `u16` —
  bei Frost meldete `TMIN` 65531 statt −5, und der Übertemperaturalarm feuerte
  genau verkehrt herum. Belegt durch das HVS-Datenblatt (Betrieb bis −10 °C)
  und `ioBroker.bydhvs`.
* **BYD: neu `TBAT`** aus `0x0508`.
* **Huawei-Paketprofil auf die dokumentierten Adressen** (siehe oben).
* **Neue Prüfzeile im Selbsttest:** kein Profil darf zwei Messgrößen aus
  demselben Register lesen. Im Paketprofil lasen `IBAT` und `SOC` beide 38229.

### Installation und Aktualisierung

* **Eigene Profile und der Verlauf überleben jetzt eine Aktualisierung.** Beide
  liegen unter `data/plugins/<ordner>/`, und der Installer räumt dieses
  Verzeichnis bei jedem Upgrade ab; auf der Sicherungsliste stand bisher nur
  die Konfigurations-JSON. Besonders unangenehm daran: die mitgelieferte
  Beispieldatei wird neu ausgeliefert, der Ordner sah hinterher heil aus.
* **Der Dienst läuft nach einem Upgrade wieder an**, wenn er vorher lief.
  Bisher fiel dabei der Sollmerker, kein Hakenskript startete den Dienst, und
  der Wächter tut ohne den Merker nichts — der Speicher wurde nach jedem
  Update nicht mehr ausgelesen, bis jemand die Oberfläche öffnete.
* **`preupgrade.sh` prüft, ob die Sicherung wirklich geschrieben wurde**, und
  bricht sonst ab. Bisher meldete es `<OK>`, ohne den Rückgabewert von `cp`
  anzusehen.
* `preupgrade.sh` meldet nur noch dann „Dienst angehalten", wenn das auch
  stimmt.
* `postupgrade.sh` ruft `postinstall.sh` über `bash` auf, statt am
  Ausführungsrecht zu scheitern und dabei „nicht gefunden" zu melden.
* **Der minütliche Wächter meldet seine Fehler** über `logger`, statt sie nach
  `/dev/null` zu schreiben.
* Der Wächter-Neustart hat jetzt in **beiden** Zweigen eine Bremse.

### Texte

Die README versprach vier Prüfungen, die es nicht gibt — darunter einen
Vergleich des Pylontech-Rahmenbaus „byteweise gegen die Originalbibliothek
über 448 Kombinationen". Der Selbsttest sagt selbst, dass der Rahmen nur gegen
sich selbst geprüft ist. Diese Stellen sind berichtigt; dazu das Ladefenster
(„auf oder über" statt „über"), die Schreibweise `unbestaetigt`, die Warnung
vor der Steuerung („wirkt sofort am Speicher, **sofern die Registeradressen
Ihres Geräts stimmen**") und zwei anwendersichtbare Zeichenketten, die fest auf
Deutsch standen.

### Offen geblieben

* **`ZYKLEN` bei BYD (`0x0511`) ist umstritten.** `sarnau` nennt das Register
  „Charge Cycles", `ioBroker.bydhvs` liest `0x0511/0x0512` als **ein** u32
  geteilt durch 10 und nennt es „Total charge" in kWh — und die dortige
  Protokollmitschrift sagt für dasselbe Byte dasselbe. Trifft das zu, liest
  das Feld das hohe Wort eines Energiezählers: es bliebe jahrelang 0 und
  stiege dann je 6553,6 kWh Durchsatz um 1 — was einem Zyklenzähler zum
  Verwechseln ähnlich sieht. Entschieden wird das an einer einzigen Ablesung:
  ist `0x0512` ungleich 0, ist `ZYKLEN` Unsinn. Bis dahin bleibt das Feld
  unverändert; der Vorbehalt steht im Profil.
* Es ist weiterhin **kein Speicher angeschlossen**. Kein Profil, kein
  Übertragungsweg, keine Vorzeichenrichtung und kein Schreibbefehl ist an
  einem Gerät gemessen.

## Änderungen

Die Freigabenotiz zu jeder Fassung steht bei den Releases:
<https://github.com/timanders22/LoxBerry-Plugin-BatterieBMS/releases>

Dieser Abschnitt stand bis 0.9.7 als „Neu in 0.9.1" hier und war damit zwei
Fassungen alt — eine Fassungsnummer in einer Überschrift wird mit jedem Release
falscher. Der Inhalt bleibt, weil er beschreibt, *warum* die Prüfungen im Reiter
Test so aussehen, wie sie aussehen.

### Behoben in 0.9.1

Eine Durchsicht der Fassung 0.9.0 hat sechs Stellen zutage gefördert, an denen
das Plugin im Störungsfall stillschweigend das Falsche tat. Alle sechs sind
korrigiert; keine davon ändert das Verhalten bei heilen Daten.

- **Der Pylontech-Parser liest nicht mehr über das Ende hinaus.** Die Zahl der
  Temperaturfühler kommt als einzelnes Byte aus dem Datenstrom. Fing die
  RS485-Leitung ein Störbyte `0xFF` ein, waren das 255 Fühler, und die Schleife
  las 510 Bytes weit hinter das Ende der Antwort. Anders als man vermuten
  könnte stürzte PHP dabei **nicht** ab — ein Lesezugriff hinter dem Ende ist
  seit PHP 8 eine Warnung, kein Abbruch. Der Dienst lief weiter, mit 255
  erfundenen Temperaturen, einem um 510 Bytes verschobenen Lesezeiger und
  daraus abgeleiteten Werten für Strom, Spannung, Kapazität und Zyklen, die er
  als gültig an Loxone und MQTT weiterreichte. Nachgemessen: von 20.000
  gestörten Rahmen meldete 0.9.0 in 2.405 Fällen Erfolg, obwohl über das Ende
  gelesen wurde. 0.9.1 weist sie ab. Bei heilen Rahmen liefern beide Fassungen
  in allen 10.108 Vergleichsfällen exakt dasselbe.
- **SIGTERM wirkt sofort.** `pcntl_async_signals(true)` statt einer Abfrage
  einmal je Schleifendurchlauf. Ein Durchlauf kann lange dauern — allein das
  Warten auf die BYD-BCU sind bis zu vier Sekunden. Dazu hielt `preupgrade.sh`
  nach dem SIGTERM nur zwei Sekunden inne und schoss dann mit `kill -9` nach;
  der Dienst wurde also bei fast jedem Update hart abgeschossen, mitten im
  Schreiben und ohne Gelegenheit, einen laufenden Ladezwang zurückzunehmen. Das
  Update ruft jetzt `dienst.sh stop` auf, das zehn Sekunden Zeit lässt.
- **`dienst.sh` löst Symlinks auf** (`readlink -f`). Von
  `system/daemons/plugins/` aus aufgerufen hätte der Dienst PID-Datei und
  Protokoll unter `data/plugins/plugins/` angelegt — die Oberfläche hätte ihn
  nie laufen sehen und der Wächter ihn im Minutentakt ein zweites Mal
  gestartet.
- **Keine Zeilenumbrüche mehr ins MQTT-Gateway.** Der UDP-Eingang wertet einen
  Zeilenumbruch als Ende des Befehls; ein mehrzeiliger Fehlertext zerfiel dort
  in erfundene Topics.
- **Ungültiges UTF-8 legt die Schnittstelle nicht mehr lahm.** Die Ausgabe von
  `stty` hängt an der Spracheinstellung des Systems. Ein einzelnes Latin-1-Byte
  daraus ließ `json_encode` scheitern: `loxone.json` blieb stumm auf dem alten
  Stand stehen, und `?aktion=roh` lieferte eine leere Seite mit Status 200.
  Solcher Text wird jetzt an der Eintrittsstelle bereinigt, das Schreiben des
  Abbilds wird geprüft und gemeldet, und der Endpunkt antwortet im Fehlerfall
  mit 500 statt mit Leere.
- **Nebendateien beim atomaren Schreiben sind eindeutig.** Sie hießen schlicht
  `<datei>.tmp`; schrieben Dienst und Oberfläche gleichzeitig, war das Ergebnis
  eine Mischung aus zwei JSON-Dokumenten.

Dazu drei Verbesserungen, die keine Fehlerbehebung sind:

- **Offene Modbus-Verbindungen werden wiederverwendet.** Bisher schloss der
  Statusabruf die Verbindung, und der Zelldatenabruf öffnete unmittelbar
  danach eine neue. Die BYD-BCU lässt nur **eine** Verbindung gleichzeitig zu
  und gibt die alte nicht in derselben Millisekunde frei — das war die
  häufigste Ursache für „Verbindung abgewiesen" im Protokoll. Schlägt eine
  Anforderung auf einer wiederverwendeten Verbindung fehl, wird sie verworfen
  und genau einmal frisch aufgebaut.
- **Ein totes Gerät blockiert die anderen nicht mehr.** Nach drei
  Fehlschlägen in Folge wird es nur noch alle 60 s gefragt. Bei drei Speichern
  und dem voreingestellten Zeitlimit spart das 45 % der Wartezeit; meldet sich
  das Gerät wieder, ist die Pause sofort aufgehoben.
- **Die serielle Schnittstelle lässt sich aus einer Liste wählen.**
  `/dev/ttyUSB0` ist keine Eigenschaft des Adapters, sondern eine Reihenfolge.
  Die Oberfläche bietet jetzt an, was tatsächlich steckt, stellt die
  gleichbleibenden Namen unter `/dev/serial/by-id/` voran und weist darauf hin,
  wenn für einen eingetragenen Pfad ein stabiler existiert. Umgestellt wird
  nichts von selbst.

Der Reiter **Test** prüft vier der sechs Korrekturen ohne angeschlossenen
Speicher nach: den Pylontech-Parser, die Zeilenumbrüche ins Gateway, das
ungültige UTF-8 und die eindeutigen Nebendateien. Für „SIGTERM wirkt sofort"
und „`dienst.sh` löst Symlinks auf" gibt es **keine** Prüfzeile – dort steht
nur, ob PHP die nötige Funktion überhaupt kennt.

## Fassung 0.9.14 — der Stat-Zwischenspeicher
Die Protokollkappung (262 144 Byte bzw. 512 000 Byte) stand in
`webfrontend/html/bm_lib.php:895`, `webfrontend/html/bm_lib.php:902`,
`webfrontend/html/bm_lib.php:2366`. PHP merkt sich aber die Antworten von
`stat()`: innerhalb **eines** Prozesses sieht `filesize()` die erste Größe
und danach nie wieder eine neue — `file_put_contents(…, FILE_APPEND)` macht
den Eintrag nicht ungültig. Die Kappung fällt dann still aus.

Gemessen am 29.08.2026, 20 000 Zeilen im selben Prozess:

| | ohne `clearstatcache` | mit |
|---|---|---|
| PHP 7.4.33 | 1 220 000 Byte, **nicht gekappt** | 220 332 Byte, gekappt |
| PHP 8.4.24 | 220 332 Byte, gekappt | 220 332 Byte, gekappt |

Die beiden PHP-Fassungen verhalten sich also verschieden — und LoxBerry 3.x
fährt 7.4. Wer nur unter 8.4 misst, sieht den Fehler nie. **Hier war der
Fehler wirksam, nicht nur latent**: `bin/bms_dienst.php` ruft `bm_log()` in
seiner Warteschleife. Das Protokoll wuchs auf der Ramdisk unbegrenzt weiter,
und niemand sah es.

Ein `clearstatcache` stand hier schon **unter der Sperre** — erreicht wurde
es nur nie, weil bereits das äußere Tor am veralteten Wert hängenblieb.
Gemessen mit genau diesem Bau: 1 220 000 Byte, ungekappt.

Abhilfe: `clearstatcache(true, …)` **vor** dem Tor; der zweite Parameter
beschränkt das Leeren auf diese eine Datei. Dasselbe Muster tragen Robonect,
Saugroboter, SignalBot, Octopus, Sprachsteuerung und WärmepumpeCloud schon
länger — es ist am 29.08.2026 im ganzen Bestand nachgezogen worden.

## Lizenz

MIT.
