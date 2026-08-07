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
| **Je Modul** | Spannung jeder einzelnen Zelle, Spannungsspanne, Temperaturen |
| **Steuerung** | Laden und Entladen erzwingen, mit Leistungsgrenze, Ladefenster, Schreibbremse und **Totmannschaltung** |
| **Ausgabe** | MQTT über das LoxBerry-Gateway und ein tokengeschützter HTTP-Endpunkt für den Miniserver |
| **Loxone** | Fertige Importdateien für virtuelle Eingänge und Ausgänge, dazu eine vollständige Baustein-Liste zum 1:1-Nachbauen |

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
herunter.

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

## Lizenz

MIT.
