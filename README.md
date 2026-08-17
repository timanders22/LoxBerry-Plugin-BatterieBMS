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
| **Ausgabe** | MQTT über das LoxBerry-Gateway und ein tokengeschützter HTTP-Endpunkt für den Miniserver, beide mit Zeitstempel |
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
  `unbestätigt` (im Umlauf, aber hier nicht gemessen)

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
* ein **Ladefenster** – oberhalb des eingestellten Ladezustands wird ein
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

* der Modbus-CRC16 gegen ein unabhängiges Tabellenverfahren und gegen den
  Prüffall der Modbus-Spezifikation (`02 07` → `4112`);
* der Pylontech-Rahmenbau **byteweise gegen die Originalbibliothek**
  (`Frankkkkk/python-pylontech`) über 448 Kombinationen aus Adresse, Befehl und
  Datenfeld;
* die Registerumrechnung für s16, u32 und Maskenfelder gegen feste Prüfwerte;
* die erzeugten Loxone-Importdateien: wohlgeformt, CRLF, Tabulator,
  Attributreihenfolge, und die Gegenprobe mit Anführungszeichen und Umlaut im
  Gerätenamen.

Diese Prüfungen stecken als `--selbsttest` im Plugin und sind im Reiter Test
als Knopf erreichbar.

**Nicht belegt:**

* ob die Registeradressen zu Ihrer Gerätefirmware passen;
* ob Ihr Gerät den gewählten Übertragungsweg spricht (Modbus TCP mit MBAP-Kopf
  gegen Modbus RTU im TCP-Strom);
* in welcher Richtung Ihr Gerät Strom und Leistung als positiv meldet – dafür
  gibt es einen Schalter statt einer Annahme;
* ob die Schreibbefehle am Gerät wirken und was sie dort tatsächlich tun.

Vor dem ersten Zwangsbefehl: Rohregister lesen, Werte gegen die
Herstelleranzeige halten, mit kleiner Leistung anfangen und am Gerät nachsehen.

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

Der Reiter **Test** prüft alle sechs Korrekturen ohne angeschlossenen Speicher
nach.

## Lizenz

MIT.
