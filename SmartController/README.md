# SmartHomeControl v2

Das zentrale Gehirn deines Smart Homes. Verwaltet den Haus-Zustand über zwei unabhängige Achsen und stellt zentrale Status-Variablen für alle anderen Module bereit.

## Zustandsmodell

### Achse 1: Anwesenheit (`PresenceMode`)
| Wert | Modus | Icon |
|------|-------|------|
| 0 | Zuhause | 🏠 |
| 1 | Kurz weg | 🚶 |
| 2 | Urlaub | ✈️ |

### Achse 2: Aktivität (`ActivityMode`)
| Wert | Modus | Icon |
|------|-------|------|
| 0 | Normal | ☀️ |
| 1 | Heimkino | 🎬 |
| 2 | Schlafen | 🌙 |
| 3 | Party | 🎉 |

**Auto-Reset**: Wenn `PresenceMode` auf "Kurz weg" oder "Urlaub" wechselt, wird `ActivityMode` automatisch auf "Normal" zurückgesetzt.

### Google Home / Alexa
Der Boolean-Switch `PresenceStatus` schaltet zwischen Zuhause (true) und Kurz weg (false). Urlaub muss manuell oder per Kalender gesetzt werden.

## Zentrale Status-Variablen

Andere Module setzen diese Variablen über die öffentliche API:

| Variable | Typ | Gesetzt von |
|----------|-----|-------------|
| `FireplaceActive` | Boolean | FireplaceSafety |
| `AlarmLevel` | Integer (0-2) | SmartAlarmManager |
| `MediaPlaying` | Boolean | RoonZone / SonyBeamer / Lyngdorf |
| `IrrigationActive` | Boolean | SmartLawnAI |

## Energiepreise

Statische Referenzpreise als zentrale Konfiguration:
- `PriceElectricity` (€/kWh)
- `PriceWater` (€/m³)
- `PriceGas` (€/kWh)

## API

### Setter (von anderen Modulen)
```php
SHC_SetPresenceMode($id, 0);      // 0=Zuhause, 1=Kurz weg, 2=Urlaub
SHC_SetActivityMode($id, 3);      // 0=Normal, 1=Heimkino, 2=Schlafen, 3=Party
SHC_SetFireplaceActive($id, true);
SHC_SetAlarmLevel($id, 1);        // 0=OK, 1=Warnung, 2=Alarm
SHC_SetMediaPlaying($id, true);
SHC_SetIrrigationActive($id, true);
```

### Getter
```php
SHC_GetPresenceMode($id);      // int
SHC_GetActivityMode($id);      // int
SHC_GetPriceElectricity($id);  // float
SHC_GetPriceWater($id);        // float
SHC_GetPriceGas($id);          // float
```

## Sequencer

Jeder Modus (Anwesenheit + Aktivität) kann eine Ein- und Austritts-Sequenz haben. Diese werden über SmartHomeSequencer-Instanzen konfiguriert.

## Kalender-Automatik

Über eine Google Kalender iCal-URL wird automatisch erkannt, ob ein Eintrag "URLAUB" aktiv ist. In diesem Fall wird `PresenceMode` automatisch auf Urlaub (2) gesetzt.
