# Symcon Device Registry

Die **Single Source of Truth** für alle physischen und logischen Geräte in IP-Symcon.

Dieses Modul verwaltet zentral alle Geräte (Lichter, Schalter, Dimmer, Alarme, Jalousien, etc.) im Haus. Andere Fach-Module (wie das GoogleHomeGateway, Alarm-Systeme oder Präsenz-Module) können sich aus dieser Registry bedienen. 

Dadurch entfällt die doppelte Pflege von Geräten in verschiedenen Modulen.

## Features
- **Auto-Discovery:** Findet automatisch Lichter, Schalter, Dimmer und Sensoren anhand von IP-Symcon Heuristiken (Variablen-Profile, Aktionsskripte, Typen).
- **Zentrale ID:** Jedes Gerät erhält eine eindeutige ID, auf die andere Module (z.B. für Blacklists) referenzieren können.
- **Typisierung:** Geräte werden nach ihrer "Fähigkeit" (Capability) eingeteilt, nicht nach ihrem Zweck.
- **Striktes Mapping:** Zuordnung der exakten Variablen (OnOff, Brightness, Color, Sensor-Status), um maximale Ausfallsicherheit zu garantieren.

## Öffentliche API für andere Module

Andere Module oder Skripte können sehr einfach auf die Registry zugreifen:

```php
// Holt alle registrierten Geräte als Array
$devices = SDR_GetDevices(12345); 

// Holt nur bestimmte Geräte (z.B. Lichter)
$lights = SDR_GetDevicesByType(12345, 'DevicesLight');

// Holt alle Variablen-IDs (z.B. OnOff, Brightness) eines bestimmten Geräts anhand seiner ID
$vars = SDR_GetDeviceVariables(12345, '12345-67890');
```

## Unterstützte Kategorien
- Schalter (`DevicesSwitch`)
- Steckdose (`DevicesSocket`)
- Licht Schalter (`DevicesLight`)
- Licht Dimmer (`DevicesLightDimmer`)
- Licht Farbe (`DevicesLightColor`)
- Jalousie/Rolllade (`DevicesBlind`)
- Thermostat (`DevicesThermostat`)
- Szene (`DevicesScene`)
- Bewegungsmelder (`DevicesMotionSensor`)
- Fenster-/Türkontakt (`DevicesContactSensor`)
- Rauchmelder (`DevicesSmokeSensor`)
- Wassermelder (`DevicesWaterSensor`)
