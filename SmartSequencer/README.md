# SmartHomeSequencer

Das SmartHomeSequencer Modul (Villa Kunterbunt Sequencer) fungiert als flexibler Makro-Baustein, um komplexe Aktionsketten beim Betreten (Eintritt) oder Verlassen (Austritt) eines bestimmten Zustands (z.B. eines Hausmodus) auszuführen.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Ausführung vordefinierter Eintritts- und Austritts-Sequenzen (Makros).
* Unterstützung unterschiedlicher Aktionstypen:
    * Variablen / Geräte schalten (inkl. automatischer Typumwandlung).
    * Ausführen von IP-Symcon Skripten oder grafischen Ablaufplänen.
    * Senden von Wake-On-LAN (WOL) Magic Packets (entweder über das native Symcon WOL-Modul oder direkt per MAC-Adresse).
* **Verzögerungen:** Jede Aktion kann mit einer individuellen Verzögerung in Sekunden versehen werden. Das Modul verarbeitet diese asynchron über eine interne Warteschlange, ohne das System zu blockieren.
* Einzelne Aktionen innerhalb einer Sequenz lassen sich temporär deaktivieren.
* Direkte Test-Buttons im Konfigurationsformular zur Überprüfung der Abläufe.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0

### 3. Installation

* Über den Module Store das Modul `SmartHomeSequencer` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartVillaKunterbunt`

### 4. Konfiguration

Die Modulkonfiguration enthält folgende Einstellungsmöglichkeiten:

* **Sequences (Eintritts-Ablauf)**: Eine Liste von Aktionen, die beim Aufruf der Start-Sequenz abgearbeitet werden.
* **DeactivationSequences (Austritts-Ablauf)**: Eine Liste von Aktionen, die beim Aufruf der Stopp-Sequenz abgearbeitet werden.

Für jeden Eintrag in den Listen können folgende Parameter definiert werden:
* **Aktiv**: Checkbox zum schnellen Deaktivieren einer Aktion.
* **Aktion**: Art der Aktion (Gerät schalten, Skript ausführen, WOL senden).
* **Ziel Instanz / Skript**: Das Ziel-Objekt im Symcon Baum.
* **Wert**: Der zu sendende Wert (nur relevant bei "Gerät schalten").
* **Verzögerung (Sek)**: Zeitliche Verzögerung, bevor die Aktion ausgeführt wird (0 = sofort).

### 5. Statusvariablen und Profile

Dieses Modul legt keine eigenen Statusvariablen für das WebFront an, da es rein als Logik-Baustein im Hintergrund agiert. Die interne Warteschlange für verzögerte Aktionen wird in einem unsichtbaren Attribut (`Queue`) zwischengespeichert.

### 6. PHP-Befehlsreferenz

```php
SHSQ_RunSequence(int $InstanceID);
```
Startet die Abarbeitung aller aktivierten Einträge in der Liste **Eintritts-Ablauf** (Sequences). Wird meist zentral vom übergeordneten Modul aufgerufen.

```php
SHSQ_RunDeactivationSequence(int $InstanceID);
```
Startet die Abarbeitung aller aktivierten Einträge in der Liste **Austritts-Ablauf** (DeactivationSequences).

```php
SHSQ_ProcessQueue(int $InstanceID);
```
Interne Funktion: Wird minütlich / sekündlich durch einen Timer aufgerufen, solange sich Elemente in der Verzögerungs-Warteschlange befinden, und führt fällige Aktionen aus.
