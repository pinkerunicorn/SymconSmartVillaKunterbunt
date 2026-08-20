<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_HardwareControl.php';

class SmartController extends IPSModuleStrict
{
    use SmartLog_Trait;
    use HardwareControl_Trait;

    // PresenceMode constants
    private const PRESENCE_HOME     = 0;
    private const PRESENCE_AWAY     = 1;
    private const PRESENCE_VACATION = 2;

    // ActivityMode constants
    private const ACTIVITY_NORMAL  = 0;
    private const ACTIVITY_CINEMA  = 1;
    private const ACTIVITY_SLEEP   = 2;
    private const ACTIVITY_PARTY   = 3;

    // AlarmLevel constants
    private const ALARM_OK      = 0;
    private const ALARM_WARNING = 1;
    private const ALARM_ALARM   = 2;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyInteger('SmartInventoryID', 0);
        $this->RegisterPropertyInteger('SmartNotifierID', 0); // Fuer Motion-Alarm-Erkennung

        // === Main Axes ===
        $this->registerModeVariables();
        
        $this->RegisterPropertyFloat('PriceElectricity', 0.32);
        $this->RegisterPropertyFloat('BasePriceElectricity', 0.0);
        $this->RegisterPropertyFloat('PriceWater', 4.80);
        $this->RegisterPropertyFloat('BasePriceWater', 0.0);
        $this->RegisterPropertyFloat('PriceGas', 0.12);
        $this->RegisterPropertyFloat('BasePriceGas', 0.0);

        // Google Home / Alexa Interface (Boolean Toggle)
        $this->RegisterVariableBoolean('PresenceStatus', 'Anwesenheit (Google Home)', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'info'
        ], 3);
        $this->EnableAction('PresenceStatus');

        // Global Simulation Mode (Dry Run)
        $this->RegisterVariableBoolean('GlobalSimulationMode', 'Simulationsmodus (Dry Run)', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'flask'
        ], 4);
        $this->EnableAction('GlobalSimulationMode');

        // Irrigation Status (set by SmartLawnAI)
        $this->RegisterVariableBoolean('IrrigationActive', 'Bewaesserung aktiv', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'droplet',
            'OPTIONS' => json_encode([
                ['Value' => false, 'Caption' => 'Inaktiv', 'IconValue' => 'faucet', 'IconActive' => true,
                 'ColorActive' => true, 'ColorDisplay' => 0x808080, 'ContentColorActive' => false,
                 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x808080],
                ['Value' => true, 'Caption' => 'Bewaessert', 'IconValue' => 'droplet', 'IconActive' => true,
                 'ColorActive' => true, 'ColorDisplay' => 0x2196F3, 'ContentColorActive' => false,
                 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x2196F3]
            ])
        ], 5);

        // Darkness Status
        $this->RegisterPropertyInteger('BrightnessSensorID', 0);
        $this->RegisterPropertyInteger('DarknessThreshold', 50);
        $this->RegisterVariableBoolean('IsDark', 'Dunkelheit', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'moon',
            'OPTIONS' => json_encode([
                ['Value' => false, 'Caption' => 'Hell', 'IconValue' => 'sun', 'IconActive' => true,
                 'ColorActive' => true, 'ColorDisplay' => 0xFFAA00, 'ContentColorActive' => false,
                 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFFAA00],
                ['Value' => true, 'Caption' => 'Dunkel', 'IconValue' => 'moon', 'IconActive' => true,
                 'ColorActive' => true, 'ColorDisplay' => 0x003388, 'ContentColorActive' => false,
                 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x003388]
            ])
        ], 6);

        // === System Status ===
        $intervals = json_encode([
            [
                'IntervalMinValue' => 0, 'IntervalMaxValue' => 0,
                'ConstantActive' => true, 'ConstantValue' => 'Alles OK',
                'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'circle-check',
                'ColorActive' => true, 'ColorValue' => 0x00CC00,
                'ContentColorActive' => false, 'ContentColorValue' => 0
            ],
            [
                'IntervalMinValue' => 1, 'IntervalMaxValue' => 1,
                'ConstantActive' => true, 'ConstantValue' => 'Info',
                'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'circle-info',
                'ColorActive' => true, 'ColorValue' => 0x0088FF,
                'ContentColorActive' => false, 'ContentColorValue' => 0
            ],
            [
                'IntervalMinValue' => 2, 'IntervalMaxValue' => 2,
                'ConstantActive' => true, 'ConstantValue' => 'Warnung',
                'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'triangle-exclamation',
                'ColorActive' => true, 'ColorValue' => 0xFFAA00,
                'ContentColorActive' => false, 'ContentColorValue' => 0
            ],
            [
                'IntervalMinValue' => 3, 'IntervalMaxValue' => 3,
                'ConstantActive' => true, 'ConstantValue' => 'Alarm',
                'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'bell',
                'ColorActive' => true, 'ColorValue' => 0xFF0000,
                'ContentColorActive' => false, 'ContentColorValue' => 0
            ]
        ]);

        $this->RegisterVariableInteger("SystemStatus", "Haus Status", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'INTERVALS_ACTIVE' => true,
            'INTERVALS' => $intervals
        ], 10);

        $this->RegisterVariableString("SystemMessage", "Aktuelle Meldung", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'circle-info'
        ], 11);

        // Monitor Links
        $this->RegisterPropertyInteger('MonitorPresenceID', 0);
        // === Sequencer Properties ===
        $this->RegisterPropertyString('PresenceSequencers', json_encode([
            ['ModeID' => 0, 'ModeName' => 'Zuhause',  'EntrySequencer' => 0, 'ExitSequencer' => 0],
            ['ModeID' => 1, 'ModeName' => 'Kurz weg', 'EntrySequencer' => 0, 'ExitSequencer' => 0],
            ['ModeID' => 2, 'ModeName' => 'Urlaub',   'EntrySequencer' => 0, 'ExitSequencer' => 0]
        ]));

        $this->RegisterPropertyString('ActivitySequencers', json_encode([
            ['ModeID' => 0, 'ModeName' => 'Normal',   'EntrySequencer' => 0, 'ExitSequencer' => 0],
            ['ModeID' => 1, 'ModeName' => 'Heimkino', 'EntrySequencer' => 0, 'ExitSequencer' => 0],
            ['ModeID' => 2, 'ModeName' => 'Schlafen', 'EntrySequencer' => 0, 'ExitSequencer' => 0],
            ['ModeID' => 3, 'ModeName' => 'Party',    'EntrySequencer' => 0, 'ExitSequencer' => 0]
        ]));

        // === Calendar ===
        $this->RegisterPropertyString('CalendarURL', '');
        $this->RegisterAttributeBoolean('VacationFromCalendar', false);

        // Timer for calendar check
        $this->RegisterTimer('CalendarCheck', 0, 'SHC_CheckCalendar($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        // --- Migration & Fix for stripped IDs in JSON ---
        $presenceDef = [0 => 'Zuhause', 1 => 'Kurz weg', 2 => 'Urlaub'];
        $this->migrateSequencerProperty('PresenceSequencers', $presenceDef);
        $activityDef = [0 => 'Normal', 1 => 'Heimkino', 2 => 'Schlafen', 3 => 'Party'];
        $this->migrateSequencerProperty('ActivitySequencers', $activityDef);

        parent::ApplyChanges();

        // === Auto-generated References ===
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $this->RegisterSequencerReferences('PresenceSequencers');
        $this->RegisterSequencerReferences('ActivitySequencers');

        // === Migration: Legacy Profile -> CustomPresentation ENUMERATION ===
        $presenceProfile = 'SHC.PresenceMode.' . $this->InstanceID;
        if (IPS_VariableProfileExists($presenceProfile)) {
            IPS_SetVariableCustomProfile($this->GetIDForIdent('PresenceMode'), '');
            IPS_DeleteVariableProfile($presenceProfile);
        }

        $activityProfile = 'SHC.ActivityMode.' . $this->InstanceID;
        if (IPS_VariableProfileExists($activityProfile)) {
            IPS_SetVariableCustomProfile($this->GetIDForIdent('ActivityMode'), '');
            IPS_DeleteVariableProfile($activityProfile);
        }
        
        // --- Sicherstellung der Variablen bei "Übernehmen" (falls manuell gelöscht) ---
        $this->registerModeVariables();

        // Sync disabled state for ActivityMode on startup / apply changes
        if ((int)$this->GetValue('PresenceMode') === self::PRESENCE_HOME) {
            IPS_SetDisabled($this->GetIDForIdent('ActivityMode'), false);
        } else {
            IPS_SetDisabled($this->GetIDForIdent('ActivityMode'), true);
        }

        // === Restore Tariff Variables ===
        $this->RegisterVariableFloat('VarPriceElectricity', 'Strompreis', '', 50);
        $this->RegisterVariableFloat('VarBasePriceElectricity', 'Strom Grundpreis', '', 51);
        $this->RegisterVariableFloat('VarPriceWater', 'Wasserpreis', '', 52);
        $this->RegisterVariableFloat('VarBasePriceWater', 'Wasser Grundpreis', '', 53);
        $this->RegisterVariableFloat('VarPriceGas', 'Gaspreis', '', 54);
        $this->RegisterVariableFloat('VarBasePriceGas', 'Gas Grundpreis', '', 55);
        
        $this->SetValue('VarPriceElectricity', $this->ReadPropertyFloat('PriceElectricity'));
        $this->SetValue('VarBasePriceElectricity', $this->ReadPropertyFloat('BasePriceElectricity'));
        $this->SetValue('VarPriceWater', $this->ReadPropertyFloat('PriceWater'));
        $this->SetValue('VarBasePriceWater', $this->ReadPropertyFloat('BasePriceWater'));
        $this->SetValue('VarPriceGas', $this->ReadPropertyFloat('PriceGas'));
        $this->SetValue('VarBasePriceGas', $this->ReadPropertyFloat('BasePriceGas'));
        
        $this->UnregisterVariable('FireplaceActive');
        $this->UnregisterVariable('MediaPlaying');
        $this->UnregisterVariable('AlarmLevel');

        // MonitorPresenceID (noch aktiv, falls vorhanden)
        $presenceMonitorGuid = '{E3405EEF-3ECA-4105-9658-47103378E206}';
        $presenceId = $this->discoverMonitorID($presenceMonitorGuid);
        if ($presenceId === 0) {
            $presenceId = $this->ReadPropertyInteger('MonitorPresenceID');
        }
        if ($presenceId > 1 && @IPS_InstanceExists($presenceId)) {
            $this->RegisterReference($presenceId);
            foreach (IPS_GetChildrenIDs($presenceId) as $childId) {
                if (IPS_VariableExists($childId)) {
                    $this->RegisterMessage($childId, VM_UPDATE);
                }
            }
        }

        $brightnessId = $this->ReadPropertyInteger('BrightnessSensorID');
        if ($brightnessId > 1 && @IPS_VariableExists($brightnessId)) {
            $this->RegisterReference($brightnessId);
            $this->RegisterMessage($brightnessId, VM_UPDATE);

            // Set initial state
            $this->CalculateDarkness(GetValue($brightnessId));
        }

        // SmartNotifier: MotionCount abonnieren fuer Motion-Alarm-Logik
        $notifierId = $this->ReadPropertyInteger('SmartNotifierID');
        if ($notifierId > 1 && @IPS_InstanceExists($notifierId)) {
            $this->RegisterReference($notifierId);
            $motionCountId = @IPS_GetObjectIDByIdent('MotionCount', $notifierId);
            if ($motionCountId) {
                $this->RegisterMessage($motionCountId, VM_UPDATE);
            }
        }
        
        $this->CalculateSystemStatus();
        $this->UnregisterVariable('HouseMode');
        $this->UnregisterVariable('AbsenceStatus');

        // Clean up old profile
        $oldProfile = 'SmartAbsence.HouseMode.' . $this->InstanceID;
        if (IPS_VariableProfileExists($oldProfile)) {
            @IPS_DeleteVariableProfile($oldProfile);
        }

        // === Start calendar timer (every 30 minutes) ===
        $this->SetTimerInterval('CalendarCheck', 30 * 60 * 1000);

        $this->SetStatus(102);
    }

    // =========================================================================
    // RequestAction (WebFront / Google Home)
    // =========================================================================

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE) {
            $brightnessId = $this->ReadPropertyInteger('BrightnessSensorID');
            if ($brightnessId > 1 && $SenderID === $brightnessId) {
                $this->CalculateDarkness($Data[0]);
                return;
            }

            // MotionCount vom SmartNotifier → Motion-Alarm-Logik
            $notifierId = $this->ReadPropertyInteger('SmartNotifierID');
            if ($notifierId > 1) {
                $motionCountId = @IPS_GetObjectIDByIdent('MotionCount', $notifierId);
                if ($motionCountId && $SenderID === $motionCountId) {
                    $newCount = (int)($Data[0] ?? 0);
                    if ($newCount > 0) {
                        $this->HandleMotionAlarm($newCount);
                    }
                    return;
                }
            }

            $this->CalculateSystemStatus();
        }
    }

    private function CalculateDarkness(mixed $lux): void
    {
        $threshold = $this->ReadPropertyInteger('DarknessThreshold');
        $isDark = ((float)$lux < $threshold);
        if ($this->GetValue('IsDark') !== $isDark) {
            $this->SetValue('IsDark', $isDark);
            $this->SLogInfo('Dunkelheit', $isDark ? 'Es ist dunkel geworden.' : 'Es ist hell geworden.');
        }
    }

    /**
     * Reagiert auf MotionCount > 0 vom SmartNotifier.
     * Loest einen Alarm aus wenn niemand zuhause ist (AWAY oder VACATION).
     * Cooldown: max. 1 Alarm pro 10 Minuten.
     */
    private function HandleMotionAlarm(int $motionCount): void
    {
        $presence = (int)$this->GetValue('PresenceMode');

        // Zuhause → keine Aktion
        if ($presence === self::PRESENCE_HOME) {
            return;
        }

        $notifierId = $this->ReadPropertyInteger('SmartNotifierID');
        if ($notifierId < 1 || !@IPS_InstanceExists($notifierId)) {
            return;
        }

        // Cooldown: nicht oefter als alle 10 Minuten alarmieren
        $lastAlarm = (int)$this->GetBuffer('LastMotionAlarmTS');
        if (time() - $lastAlarm < 600) {
            return;
        }
        $this->SetBuffer('LastMotionAlarmTS', (string)time());

        $presenceLabel = match($presence) {
            self::PRESENCE_AWAY     => 'Abwesend',
            self::PRESENCE_VACATION => 'Urlaub',
            default                 => 'Unbekannt',
        };

        $msg = "Bewegung erkannt ($motionCount Melder aktiv) – Haus ist auf '$presenceLabel'!";
        $this->SLogInfo('Motion-Alarm', $msg);

        @NOTIFY_SendEvent($notifierId, json_encode([
            'Title'    => 'Bewegungsalarm',
            'Message'  => $msg,
            'Priority' => 2,
        ]));
    }

    private function CalculateSystemStatus(): void
    {
        $statusLevel = 0;
        $messages    = [];

        // Daten aus SmartNotifier lesen (wenn konfiguriert)
        $notifierId = $this->ReadPropertyInteger('SmartNotifierID');
        if ($notifierId > 1 && @IPS_InstanceExists($notifierId)) {
            $alarmVid   = @IPS_GetObjectIDByIdent('ActiveAlarmCount',  $notifierId);
            $devProbVid = @IPS_GetObjectIDByIdent('DeviceProblems',    $notifierId);
            $contactVid = @IPS_GetObjectIDByIdent('OpenContactCount',  $notifierId);

            $alarms   = ($alarmVid   && @IPS_VariableExists($alarmVid))   ? (int)GetValue($alarmVid)   : 0;
            $devProbs = ($devProbVid && @IPS_VariableExists($devProbVid)) ? (int)GetValue($devProbVid) : 0;
            $contacts = ($contactVid && @IPS_VariableExists($contactVid)) ? (int)GetValue($contactVid) : 0;

            if ($alarms > 0) {
                $statusLevel = 3;
                $messages[]  = "Alarm: $alarms aktive Alarme";
            }
            if ($contacts > 0) {
                if ($statusLevel < 2) $statusLevel = 2;
                $messages[] = "$contacts Kontakte offen";
            }
            if ($devProbs > 0) {
                if ($statusLevel < 1) $statusLevel = 1;
                $messages[] = "$devProbs Geraete-Probleme";
            }
        }

        // Presence Simulation Fehler (MonitorPresenceID bleibt aktiv)
        $presenceMonitorGuid = '{E3405EEF-3ECA-4105-9658-47103378E206}';
        $presenceId = $this->discoverMonitorID($presenceMonitorGuid);
        if ($presenceId === 0) $presenceId = $this->ReadPropertyInteger('MonitorPresenceID');
        if ($presenceId > 1 && @IPS_InstanceExists($presenceId)) {
            $errId = @IPS_GetObjectIDByIdent('GeminiError', $presenceId);
            if ($errId !== false && GetValue($errId)) {
                if ($statusLevel < 2) $statusLevel = 2;
                $messages[] = 'Warnung: Fehler bei Smart Presence Simulation KI';
            }
        }

        $this->SetValue('SystemStatus', $statusLevel);
        $this->SetValue('SystemMessage', empty($messages)
            ? 'Keine besonderen Vorkommnisse'
            : implode(' – ', array_slice($messages, 0, 2)) . (count($messages) > 2 ? ' (+' . (count($messages) - 2) . ' weitere)' : '')
        );
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'PresenceMode':
                $this->SetPresenceMode($Value);
                break;
            case 'ActivityMode':
                $this->SetActivityMode($Value);
                break;
            case 'PresenceStatus':
                $this->SetPresenceMode($Value ? self::PRESENCE_HOME : self::PRESENCE_AWAY);
                break;
            case 'GlobalSimulationMode':
                $this->SetValue($Ident, $Value);
                $this->SLogInfo($Value ? "Globaler Simulationsmodus aktiviert!" : "Globaler Simulationsmodus deaktiviert!");
                break;
            default:
                throw new Exception("Invalid Ident in RequestAction: $Ident");
        }
    }

    // =========================================================================
    // Public Setter Methods (called by other modules)
    // =========================================================================

    public function SetPresenceMode(int $mode): void
    {
        if ($mode < 0 || $mode > 2) {
            $this->SLogError( 'Ungültiger PresenceMode: ' . $mode);
            return;
        }

        if ($mode !== self::PRESENCE_VACATION) {
            $this->WriteAttributeBoolean('VacationFromCalendar', false);
        }

        $oldMode = (int)$this->GetValue('PresenceMode');
        if ($oldMode !== $mode) {
            // Execute exit sequence for old presence mode
            $this->TriggerSequencer('PresenceSequencers', $oldMode, false);
        }

        $this->SetValue('PresenceMode', $mode);
        $this->SetValue('PresenceStatus', $mode === self::PRESENCE_HOME);

        // Dynamisch das Aktivitäts-Objekt aktivieren/deaktivieren
        if ($mode === self::PRESENCE_HOME) {
            IPS_SetDisabled($this->GetIDForIdent('ActivityMode'), false);
        } else {
            IPS_SetDisabled($this->GetIDForIdent('ActivityMode'), true);
        }

        $modeName = match($mode) {
            self::PRESENCE_HOME     => 'Zuhause',
            self::PRESENCE_AWAY     => 'Kurz weg',
            self::PRESENCE_VACATION => 'Urlaub',
            default                 => 'Unbekannt'
        };
        $this->SLogInfo( 'Anwesenheit gewechselt auf: ' . $modeName);

        // Auto-Reset: ActivityMode Ã¢â€ â€™ Normal when leaving
        if ($mode !== self::PRESENCE_HOME) {
            $currentActivity = (int)$this->GetValue('ActivityMode');
            if ($currentActivity !== self::ACTIVITY_NORMAL) {
                $this->TriggerSequencer('ActivitySequencers', $currentActivity, false);
                $this->SetValue('ActivityMode', self::ACTIVITY_NORMAL);
                $this->SLogInfo( 'Auto-Reset: Aktivität zurück auf Normal (Haus verlassen).');
                $this->TriggerSequencer('ActivitySequencers', self::ACTIVITY_NORMAL, true);
            }
        }

        if ($oldMode !== $mode) {
            // Execute entry sequence for new presence mode
            $this->TriggerSequencer('PresenceSequencers', $mode, true);
        }
    }

    public function SetActivityMode(int $mode): void
    {
        if ($mode < 0 || $mode > 3) {
            $this->SLogError( 'Ungültiger ActivityMode: ' . $mode);
            return;
        }

        // ActivityMode kann nur geändert werden wenn jemand Zuhause ist
        if ((int)$this->GetValue('PresenceMode') !== self::PRESENCE_HOME) {
            $this->SLogWarning('Aktivität kann nur geändert werden wenn jemand Zuhause ist.');
            return;
        }

        $oldMode = (int)$this->GetValue('ActivityMode');
        if ($oldMode !== $mode) {
            // Execute exit sequence for old activity mode
            $this->TriggerSequencer('ActivitySequencers', $oldMode, false);
        }

        $this->SetValue('ActivityMode', $mode);

        $modeName = match($mode) {
            self::ACTIVITY_NORMAL => 'Normal',
            self::ACTIVITY_CINEMA => 'Heimkino',
            self::ACTIVITY_SLEEP  => 'Schlafen',
            self::ACTIVITY_PARTY  => 'Party',
            default               => 'Unbekannt'
        };
        $this->SLogInfo( 'Aktivität gewechselt auf: ' . $modeName);

        if ($oldMode !== $mode) {
            // Execute entry sequence for new activity mode
            $this->TriggerSequencer('ActivitySequencers', $mode, true);
        }
    }

    public function SetFireplaceActive(bool $active): void
    {
        $this->SetValue('FireplaceActive', $active);
        $this->SLogInfo( 'Kamin: ' . ($active ? 'Aktiv' : 'Aus'));
    }

    public function SetAlarmLevel(int $level): void
    {
        if ($level < 0 || $level > 2) {
            return;
        }
        $levelName = match($level) {
            self::ALARM_OK      => 'OK',
            self::ALARM_WARNING => 'Warnung',
            self::ALARM_ALARM   => 'Alarm',
            default             => 'Unbekannt'
        };
        $this->SetValue('AlarmLevel', $levelName);
        $this->SLogInfo( 'Alarm-Stufe: ' . $levelName);
    }

    public function SetMediaPlaying(bool $playing): void
    {
        $this->SetValue('MediaPlaying', $playing);
    }

    public function SetIrrigationActive(bool $active): void
    {
        $this->SetValue('IrrigationActive', $active);
    }

    // =========================================================================
    // Public Getter Methods (called by other modules)
    // =========================================================================

    public function GetPresenceMode(): int
    {
        return (int)$this->GetValue('PresenceMode');
    }

    public function GetActivityMode(): int
    {
        return (int)$this->GetValue('ActivityMode');
    }

    // =========================================================================
    // Calendar Automation
    // =========================================================================

    public function CheckCalendar(): void
    {
        $url = $this->ReadPropertyString('CalendarURL');
        if (empty($url)) {
            $this->SLogDebug( 'CheckCalendar: Keine iCal-URL hinterlegt.');
            return;
        }

        $icalData = @Sys_GetURLContentEx($url, ['Timeout' => 5000]);
        if ($icalData === false) {
            $this->SLogError('CheckCalendar: Konnte Kalenderdaten nicht abrufen.', 'Timeout oder Verbindungsfehler');
            return;
        }

        // Simple iCal parser for VEVENT
        $events = [];
        $lines = explode("\n", $icalData);
        $currentEvent = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === 'BEGIN:VEVENT') {
                $currentEvent = [];
            } elseif ($line === 'END:VEVENT') {
                if ($currentEvent !== null) {
                    $events[] = $currentEvent;
                    $currentEvent = null;
                }
            } elseif ($currentEvent !== null) {
                if (strpos($line, 'SUMMARY:') === 0) {
                    $currentEvent['SUMMARY'] = substr($line, 8);
                } elseif (strpos($line, 'DTSTART') === 0) {
                    $parts = explode(':', $line);
                    if (count($parts) >= 2) {
                        $currentEvent['DTSTART'] = strtotime($parts[1]);
                    }
                } elseif (strpos($line, 'DTEND') === 0) {
                    $parts = explode(':', $line);
                    if (count($parts) >= 2) {
                        $currentEvent['DTEND'] = strtotime($parts[1]);
                    }
                }
            }
        }

        $now = time();
        $vacationFound = false;

        foreach ($events as $event) {
            if (isset($event['SUMMARY']) && strtoupper(trim($event['SUMMARY'])) === 'URLAUB') {
                if (isset($event['DTSTART']) && isset($event['DTEND'])) {
                    if ($now >= $event['DTSTART'] && $now <= $event['DTEND']) {
                        $vacationFound = true;
                        break;
                    }
                }
            }
        }

        $currentPresence = (int)$this->GetValue('PresenceMode');

        if ($vacationFound && $currentPresence !== self::PRESENCE_VACATION) {
            $this->SLogInfo( 'Kalender: Urlaubstermin aktiv! Wechsle in den Urlaubs-Modus.');
            $this->WriteAttributeBoolean('VacationFromCalendar', true);
            $this->SetPresenceMode(self::PRESENCE_VACATION);
        } elseif (!$vacationFound && $currentPresence === self::PRESENCE_VACATION) {
            if ($this->ReadAttributeBoolean('VacationFromCalendar')) {
                $this->SLogInfo( 'Kalender: Urlaubstermin beendet! Wechsle zurück auf Zuhause.');
                $this->WriteAttributeBoolean('VacationFromCalendar', false);
                $this->SetPresenceMode(self::PRESENCE_HOME);
            } else {
                $this->SLogDebug( 'CheckCalendar: Kein Urlaub im Kalender, aber manuell gesetzt. ÃƒÅ“berschreibe nicht.');
            }
        }
    }

    // =========================================================================
    // Private Helpers

    private function discoverMonitorID(string $moduleGUID): int {
        $all = @IPS_GetInstanceListByModuleID($moduleGUID);
        if (!is_array($all) || count($all) === 0) return 0;
        // Bei einer Instanz direkt; sonst erste verfuegbare
        return $all[0];
    }

    private function registerModeVariables(): void
    {
        $this->RegisterVariableInteger('PresenceMode', 'Anwesenheit', [
            'ICON' => 'house',
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS' => json_encode([
                ['Value' => self::PRESENCE_HOME, 'Caption' => 'Zuhause', 'IconActive' => true, 'IconValue' => 'House', 'Color' => 0x00CC00],
                ['Value' => self::PRESENCE_AWAY, 'Caption' => 'Kurz weg', 'IconActive' => true, 'IconValue' => 'person-running', 'Color' => 0xFFAA00],
                ['Value' => self::PRESENCE_VACATION, 'Caption' => 'Urlaub', 'IconActive' => true, 'IconValue' => 'Suitcase', 'Color' => 0xFF4400]
            ])
        ], 1);
        $this->EnableAction('PresenceMode');

        $this->RegisterVariableInteger('ActivityMode', 'Aktivität', [
            'ICON' => 'person-running',
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS' => json_encode([
                ['Value' => self::ACTIVITY_NORMAL, 'Caption' => 'Normal', 'IconActive' => true, 'IconValue' => 'sun', 'Color' => -1],
                ['Value' => self::ACTIVITY_CINEMA, 'Caption' => 'Heimkino', 'IconActive' => true, 'IconValue' => 'Movie', 'Color' => 0x6644CC],
                ['Value' => self::ACTIVITY_SLEEP, 'Caption' => 'Schlafen', 'IconActive' => true, 'IconValue' => 'moon', 'Color' => 0x003388],
                ['Value' => self::ACTIVITY_PARTY, 'Caption' => 'Party', 'IconActive' => true, 'IconValue' => 'martini-glass', 'Color' => 0xFF00AA]
            ])
        ], 2);
        $this->EnableAction('ActivityMode');
    }


    private function migrateSequencerProperty(string $propertyName, array $defaults): void
    {
        $json = $this->ReadPropertyString($propertyName);
        $list = $this->safeJsonDecode($json, true) ?: [];
        $map = [];
        foreach ($list as $index => $item) {
            $id = (isset($item['ModeID']) && $item['ModeID'] !== '') ? (int)$item['ModeID'] : $index;
            $map[$id] = $item;
        }
        $values = [];
        foreach ($defaults as $id => $name) {
            $values[] = [
                'ModeID' => $id,
                'ModeName' => $name,
                'EntrySequencer' => $map[$id]['EntrySequencer'] ?? 0,
                'ExitSequencer' => $map[$id]['ExitSequencer'] ?? 0
            ];
        }
        $newJson = json_encode($values);
        if ($newJson !== $json) {
            IPS_SetProperty($this->InstanceID, $propertyName, $newJson);
        }
    }

    // =========================================================================

    private function TriggerSequencer(string $property, int $modeID, bool $isEntry): void
    {
        $sequencersJson = $this->ReadPropertyString($property);
        $sequencers = $this->safeJsonDecode($sequencersJson, true);
        if (!is_array($sequencers)) {
            return;
        }

        foreach ($sequencers as $seq) {
            if (($seq['ModeID'] ?? -1) === $modeID) {
                $key = $isEntry ? 'EntrySequencer' : 'ExitSequencer';
                $seqInst = $seq[$key] ?? 0;
                if ($seqInst > 0 && IPS_InstanceExists($seqInst)) {
                    if ($isEntry && function_exists('SHSQ_RunSequence')) {
                        SHSQ_RunSequence($seqInst);
                        $this->SLogInfo( ($isEntry ? 'Eintritts' : 'Austritts') . '-Sequenz ausgeführt.', 'Instanz: ' . $seqInst);
                    } elseif (!$isEntry && function_exists('SHSQ_RunDeactivationSequence')) {
                        SHSQ_RunDeactivationSequence($seqInst);
                        $this->SLogInfo( ($isEntry ? 'Eintritts' : 'Austritts') . '-Sequenz ausgeführt.', 'Instanz: ' . $seqInst);
                    }
                }
                break;
            }
        }
    }

    private function RegisterSequencerReferences(string $property): void
    {
        $list = $this->safeJsonDecode($this->ReadPropertyString($property), true);
        if (is_array($list)) {
            foreach ($list as $item) {
                foreach (['EntrySequencer', 'ExitSequencer'] as $key) {
                    $id = $item[$key] ?? 0;
                    if ($id > 1 && @IPS_ObjectExists($id)) {
                        $this->RegisterReference($id);
                    }
                }
            }
        }
    }


    // =========================================================================
    // Logging
    // =========================================================================

    // =========================================================================
    // Configuration Form
    // =========================================================================

    public function GetConfigurationForm(): string
    {
        $json = <<<'EOT'
{
    "elements": [
                {
            "type": "ExpansionPanel",
            "caption": "💰 Verbrauchs-Tarife",
            "expanded": false,
            "items": [
                {
                    "type": "NumberSpinner",
                    "name": "PriceElectricity",
                    "caption": "Strompreis (Cent/kWh)",
                    "digits": 4
                },
                {
                    "type": "NumberSpinner",
                    "name": "BasePriceElectricity",
                    "caption": "Strom Grundpreis (€/Jahr)",
                    "digits": 2
                },
                {
                    "type": "NumberSpinner",
                    "name": "PriceWater",
                    "caption": "Wasserpreis (Cent/m³)",
                    "digits": 4
                },
                {
                    "type": "NumberSpinner",
                    "name": "BasePriceWater",
                    "caption": "Wasser Grundpreis (€/Jahr)",
                    "digits": 2
                },
                {
                    "type": "NumberSpinner",
                    "name": "PriceGas",
                    "caption": "Gaspreis (Cent/kWh)",
                    "digits": 4
                },
                {
                    "type": "NumberSpinner",
                    "name": "BasePriceGas",
                    "caption": "Gas Grundpreis (€/Jahr)",
                    "digits": 2
                }
            ]
        },
                {
            "type": "ExpansionPanel",
            "caption": "🔔 Benachrichtigungen & Status",
            "expanded": true,
            "items": [
                {
                    "type": "Label",
                    "caption": "Verknüpfe hier den SmartNotifier und den Anwesenheits-Monitor (Presence Simulation)."
                },
                {
                    "type": "SelectInstance",
                    "name": "SmartNotifierID",
                    "caption": "SmartNotifier",
                    "moduleID": "{B8A7F31D-E1D8-49A4-B9A9-5E9D5B4A1C8F}"
                },
                {
                    "type": "SelectInstance",
                    "name": "MonitorPresenceID",
                    "caption": "SmartPresenceSimulation",
                    "moduleID": "{E3405EEF-3ECA-4105-9658-47103378E206}"
                }
            ]
        },
        {
            "type": "ExpansionPanel",
            "caption": "📍 Anwesenheits-Sequenzen",
            "expanded": false,
            "items": [
                {
                    "type": "List",
                    "name": "PresenceSequencers",
                    "caption": "",
                    "rowCount": 3,
                    "add": false,
                    "delete": false,
                    "columns": [
                        {
                            "caption": "Modus",
                            "name": "ModeName",
                            "width": "150px",
                            "add": ""
                        },
                        {
                            "caption": "ID",
                            "name": "ModeID",
                            "width": "50px",
                            "visible": false
                        },
                        {
                            "caption": "Eintritts-Sequenz",
                            "name": "EntrySequencer",
                            "width": "350px",
                            "add": 0,
                            "edit": {
                                "type": "SelectInstance"
                            }
                        },
                        {
                            "caption": "Austritts-Sequenz",
                            "name": "ExitSequencer",
                            "width": "350px",
                            "add": 0,
                            "edit": {
                                "type": "SelectInstance"
                            }
                        }
                    ]
                }
            ]
        },
        {
            "type": "ExpansionPanel",
            "caption": "🎬 Aktivitäts-Sequenzen",
            "expanded": false,
            "items": [
                {
                    "type": "List",
                    "name": "ActivitySequencers",
                    "caption": "",
                    "rowCount": 4,
                    "add": false,
                    "delete": false,
                    "columns": [
                        {
                            "caption": "Modus",
                            "name": "ModeName",
                            "width": "150px",
                            "add": ""
                        },
                        {
                            "caption": "ID",
                            "name": "ModeID",
                            "width": "50px",
                            "visible": false
                        },
                        {
                            "caption": "Eintritts-Sequenz",
                            "name": "EntrySequencer",
                            "width": "350px",
                            "add": 0,
                            "edit": {
                                "type": "SelectInstance"
                            }
                        },
                        {
                            "caption": "Austritts-Sequenz",
                            "name": "ExitSequencer",
                            "width": "350px",
                            "add": 0,
                            "edit": {
                                "type": "SelectInstance"
                            }
                        }
                    ]
                }
            ]
        },

        {
            "type": "ExpansionPanel",
            "caption": "📅 Urlaubs-Automatik",
            "expanded": false,
            "items": [
                {
                    "type": "ValidationTextBox",
                    "name": "CalendarURL",
                    "caption": "Google Kalender (iCal) URL"
                }
            ]
        },
        {
            "type": "ExpansionPanel",
            "caption": "🌙 Außenhelligkeit",
            "expanded": true,
            "items": [
                {
                    "type": "SelectVariable",
                    "name": "BrightnessSensorID",
                    "caption": "Helligkeitssensor (Lux) Variable"
                },
                {
                    "type": "NumberSpinner",
                    "name": "DarknessThreshold",
                    "caption": "Schwellwert für Dunkelheit (Lux)",
                    "suffix": " Lux"
                }
            ]
        }
    ],
    "actions": [
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "Button",
                    "caption": "📅 Kalender Sync",
                    "onClick": "SHC_CheckCalendar($id);",
                    "icon": "Calendar"
                }
            ]
        }
    ],
    "status": [
        {
            "code": 102,
            "icon": "active",
            "caption": "SmartHomeControl aktiv"
        }
    ]
}
EOT;

        $form = json_decode($json, true);

        // Dynamically inject the mode names so they always display properly
        $presenceDef = [0 => 'Zuhause', 1 => 'Kurz weg', 2 => 'Urlaub'];
        $presenceProp = json_decode($this->ReadPropertyString('PresenceSequencers'), true) ?: [];
        $pValues = [];
        foreach ($presenceDef as $id => $name) {
            $pValues[] = [
                'ModeID' => $id,
                'ModeName' => $name,
                'EntrySequencer' => $presenceProp[$id]['EntrySequencer'] ?? 0,
                'ExitSequencer' => $presenceProp[$id]['ExitSequencer'] ?? 0
            ];
        }
        $form['elements'][1]['items'][0]['values'] = $pValues;

        $activityDef = [0 => 'Normal', 1 => 'Heimkino', 2 => 'Schlafen', 3 => 'Party'];
        $activityProp = json_decode($this->ReadPropertyString('ActivitySequencers'), true) ?: [];
        $aValues = [];
        foreach ($activityDef as $id => $name) {
            $aValues[] = [
                'ModeID' => $id,
                'ModeName' => $name,
                'EntrySequencer' => $activityProp[$id]['EntrySequencer'] ?? 0,
                'ExitSequencer' => $activityProp[$id]['ExitSequencer'] ?? 0
            ];
        }
        $form['elements'][2]['items'][0]['values'] = $aValues;

        return json_encode($form);
    }

    private function safeJsonDecode(string $json, bool $assoc = true) {
        try {
            if (trim($json) === '') return $assoc ? [] : null;
            return json_decode($json, $assoc, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->SLogWarning("JSON Decode Exception", $e->getMessage());
            return $assoc ? [] : null;
        }
    }

}