# SmartActiveLighting

Das SmartActiveLighting Modul ermöglicht die intelligente, regelbasierte Steuerung von Beleuchtungen über Bewegungsmelder, Türkontakte, Dämmerungswerte, Szenen und Taster, inklusive automatischer Master-Slave-Synchronisation.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Bewegungsgesteuerte Beleuchtung mit Helligkeitsschwellenwert (Max Lux) und anpassbarem Nachtmodus (z. B. nur 10% Helligkeit nachts).
* Türgesteuerte Beleuchtung (z. B. für Schranklichter) mit Nachlaufzeit.
* Dämmerungsgesteuerte Beleuchtung basierend auf Sonnenuntergang, Sonnenaufgang oder fester Uhrzeit.
* Szenen-Steuerung zum Schalten bestimmter Lichtstimmungen.
* Taster-Regeln mit automatischer Erstellung von virtuellen Gruppen-Variablen zur einfachen Bedienung im WebFront.
* Synchronisation von Leuchten (Master-Slave-Dimming) inklusive Skalierung von Helligkeitswerten.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0

### 3. Installation

* Über den Module Store das Modul `SmartActiveLighting` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartVillaKunterbunt`

### 4. Konfiguration

Die Konfiguration umfasst folgende Bereiche und Eigenschaften:

* **MotionRules**: Definition von Bewegungsmelder-Regeln (Bewegungssensor, Ziel-Licht, Dauer in Sek, Max Lux, Nachtmodus).
* **DoorRules**: Regeln für Tür-Kontakte (Türsensor, Ziel-Licht, Dauer in Sek, Max Lux, Nachtmodus).
* **TwilightRules**: Dämmerungsgesteuerte Regeln (Ziel-Licht, Auslöser-Typ: Sonnenuntergang/Sonnenaufgang/Uhrzeit, Aktion, Aktiv/Inaktiv).
* **SceneRules**: Verknüpfung von Szenen-Triggern mit konkreten Licht-Aktoren (Szenen-Variable, Ziel-Wert, Ziel-Licht).
* **ButtonRules**: Taster-Konfiguration, inklusive Zuweisung zu einer logischen Lichtgruppe (Taster-Variable, Trigger-Wert, Ziel-Licht, Gruppenname).
* **SyncRules**: Synchronisierungsregeln zwischen einer Master-Leuchte und weiteren Ziel-Leuchten.
* **GlobalLuxSensorID**: Globale Helligkeitssensor-Variable (wird bei Bewegung/Tür ausgewertet).
* **SunsetVariableID**: Variable, die die Uhrzeit des Sonnenuntergangs enthält.
* **SunriseVariableID**: Variable, die die Uhrzeit des Sonnenaufgangs enthält.

### 5. Statusvariablen und Profile

Die Statusvariablen werden automatisch anhand der Konfiguration (z. B. vergebene Gruppennamen bei Tastern) generiert.

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| Group_* | Gruppenname | Boolean | Virtuelle Gruppenvariable für Taster (Profil-Präsentation: Switch, Icon: Bulb). |

### 6. PHP-Befehlsreferenz

```php
SAL_SetHouseMode(int $InstanceID, int $mode);
```
Ändert den internen Hausmodus. Bei Abwesenheit, Urlaub oder Schlafmodus (1, 2, 5) werden aktive durch Bewegung gestartete Timer gestoppt und die dazugehörigen Lichter ausgeschaltet.

```php
SAL_CalculateTwilightTimers(int $InstanceID);
```
Berechnet die Dämmerungs-Timer basierend auf aktuellen Sonnenauf- und -untergangszeiten sowie den hinterlegten Zeit-Regeln neu. Wird auch täglich um 00:05 Uhr automatisch aufgerufen.

```php
SAL_ProcessMotionOff(int $InstanceID, int $ruleIndex);
```
Interne Funktion: Beendet den zugehörigen Bewegungsmelder-Timer und schaltet das Ziel-Licht aus.

```php
SAL_ProcessDoorOff(int $InstanceID, int $ruleIndex);
```
Interne Funktion: Beendet den zugehörigen Tür-Timer und schaltet das Ziel-Licht aus.

```php
SAL_ProcessTwilightTrigger(int $InstanceID, int $ruleIndex);
```
Interne Funktion: Führt die konfigurierte Aktion einer Dämmerungsregel aus und berechnet die Timer im Anschluss direkt neu.
