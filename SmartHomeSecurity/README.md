# SmartHomeSecurity

Das SmartHomeSecurity Modul überwacht Fenster- und Türkontakte und steuert smarte Türschlösser (z.B. Nuki) vollautomatisch basierend auf dem Hausmodus oder Zeitplänen.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Lückenlose Überwachung aller konfigurierten Fenster- und Türkontakte mit Zähler und Namensliste (welche Elemente stehen aktuell offen?).
* "Sicherheits-Check": Warnung (Alarm-Variable), wenn das Haus verlassen wird (Hausmodus Abwesenheit) und noch Fenster oder Türen offen stehen.
* Automatische Ver- und Entriegelung von Türschlössern beim Wechsel des Hausmodus (z. B. Verriegeln bei Abwesenheit/Schlafmodus).
* Integrierter "Door-Check": Ein Türschloss wird niemals verriegelt, wenn die Tür laut Sensor noch offen steht.
* Zeitgesteuerte Auto-Lock und Auto-Unlock Funktionen (z. B. jeden Abend um 22:00 Uhr verriegeln, morgens um 07:00 Uhr aufsperren).
* Option: Automatisches Aufsperren am Morgen nur durchführen, wenn Personen anwesend sind (nicht im Urlaub/Abwesenheit).
* Generierung eines Kurztextes für externe Anzeigen (z.B. Vestaboard).

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0

### 3. Installation

* Über den Module Store das Modul `SmartHomeSecurity` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartVillaKunterbunt`

### 4. Konfiguration

Die Modulkonfiguration enthält folgende Parameter:

* **DoorVariables**: Konfiguration der Türen. Enthält den Sensor (Tür-Kontakt) zur Statusprüfung und den Aktor (Türschloss) inkl. der Werte, die gesendet werden müssen. Außerdem lassen sich hier Flags setzen: `LockOnAbsence` (beim Verlassen absperren) und `UnlockOnPresence` (bei Heimkehr aufsperren).
* **WindowVariables**: Liste der Fenster-Kontakte mit Namen und dem Wert, der "Geschlossen" repräsentiert.
* **AutoLockActive / AutoLockTime**: Aktiviert das zeitgesteuerte automatische Verriegeln aller konfigurierten Türen.
* **AutoUnlockActive / AutoUnlockTime**: Aktiviert das zeitgesteuerte automatische Aufsperren.
* **AutoUnlockOnlyWhenPresent**: Verhindert, dass das Auto-Unlock am Morgen stattfindet, falls das Haus auf "Abwesenheit" steht.

### 5. Statusvariablen und Profile

Das Modul stellt folgende Variablen bereit:

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| OpenWindowsCount | 🚪 Offene Fenster / Türen (Zähler) | Integer | Anzahl der aktuell offenen Elemente. |
| OpenWindowsList | 📝 Offene Fenster / Türen (Namen) | String | Liste der aktuell offenen Elemente. |
| AlarmWindowsOpenDuringAbsence | Alarm: Fenster/Tür offen bei Abwesenheit | Boolean | Wird auf True gesetzt, wenn das Haus auf Abwesenheit geschaltet wird, obwohl noch Fenster auf sind. Lässt sich manuell quittieren. |
| VestaboardStatus | Kurz-Status (Vestaboard) | String | Text-Info für Displays (z. B. "2 offen"). |

### 6. PHP-Befehlsreferenz

```php
SHS_SetHouseMode(int $InstanceID, int $mode, bool $isAbsence = false, bool $isSleep = false);
```
Wird üblicherweise zentral durch das `SmartHomeControl` Modul aufgerufen. Führt abhängig vom Modus die Ver- oder Entriegelung der Türen durch und prüft auf offene Fenster beim Verlassen.

```php
SHS_TimerAutoLock(int $InstanceID);
```
Interne Funktion: Wird vom Timer zur eingestellten Auto-Lock Uhrzeit getriggert und verriegelt alle Türen, die derzeit nicht offen stehen.

```php
SHS_TimerAutoUnlock(int $InstanceID);
```
Interne Funktion: Wird vom Timer zur eingestellten Auto-Unlock Uhrzeit getriggert und entsperrt die Türen (unter Berücksichtigung der Anwesenheits-Regel).
