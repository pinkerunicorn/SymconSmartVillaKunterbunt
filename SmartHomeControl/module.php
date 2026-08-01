<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';

class SmartHomeControl extends IPSModuleStrict
{
    use SmartLog_Trait;

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

        // === Main Axes ===
        $this->RegisterVariableInteger('PresenceMode', 'Anwesenheit', '', 1);
        $this->EnableAction('PresenceMode');

        $this->RegisterVariableInteger('ActivityMode', 'AktivitÃ¤t', '', 2);
        $this->EnableAction('ActivityMode');

        // Google Home / Alexa Interface (Boolean Toggle)
        $this->RegisterVariableBoolean('PresenceStatus', 'Anwesenheit (Google Home)', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'Information'
        ], 3);
        $this->EnableAction('PresenceStatus');

        // === Central State Variables (read-only, set by other modules via public API) ===
        $this->RegisterVariableBoolean('FireplaceActive', 'Kamin aktiv', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Flame',
            'DIGITS' => 2
        ], 10);

        $this->RegisterVariableString('AlarmLevel', 'Alarm-Stufe', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Alert'
        ], 11);

        $this->RegisterVariableBoolean('MediaPlaying', 'Medien aktiv', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Speaker'
        ], 12);

        $this->RegisterVariableBoolean('IrrigationActive', 'BewÃ¤sserung aktiv', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Drops'
        ], 13);

        // === Energy Price Properties ===
        $this->RegisterPropertyFloat('PriceElectricity', 0.32);
        $this->RegisterPropertyFloat('BasePriceElectricity', 0.0);
        $this->RegisterPropertyFloat('PriceWater', 4.80);
        $this->RegisterPropertyFloat('BasePriceWater', 0.0);
        $this->RegisterPropertyFloat('PriceGas', 0.12);
        $this->RegisterPropertyFloat('BasePriceGas', 0.0);

        // Export Variables for Energy Calculators (Read-Only)
        $this->RegisterVariableFloat('VarPriceElectricity', 'Strompreis', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' Cent/kWh',
            'ICON' => 'Electricity',
            'DIGITS' => 2
        ], 200);
        $this->RegisterVariableFloat('VarBasePriceElectricity', 'Strom Grundpreis', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' â‚¬/Jahr',
            'ICON' => 'Electricity',
            'DIGITS' => 2
        ], 201);
        
        $this->RegisterVariableFloat('VarPriceWater', 'Wasserpreis', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' Cent/mÂ³',
            'ICON' => 'Tap',
            'DIGITS' => 2
        ], 202);
        $this->RegisterVariableFloat('VarBasePriceWater', 'Wasser Grundpreis', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' â‚¬/Jahr',
            'ICON' => 'Tap',
            'DIGITS' => 2
        ], 203);
        
        $this->RegisterVariableFloat('VarPriceGas', 'Gaspreis', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' Cent/kWh',
            'ICON' => 'Flame',
            'DIGITS' => 2
        ], 204);
        $this->RegisterVariableFloat('VarBasePriceGas', 'Gas Grundpreis', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' â‚¬/Jahr',
            'ICON' => 'Flame',
            'DIGITS' => 2
        ], 205);

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
        $presenceJson = $this->ReadPropertyString('PresenceSequencers');
        $presenceList = json_decode($presenceJson, true) ?: [];
        $mapP = [];
        foreach ($presenceList as $index => $p) {
            $id = (isset($p['ModeID']) && $p['ModeID'] !== '') ? (int)$p['ModeID'] : $index;
            $mapP[$id] = $p;
        }
        $presenceValues = [];
        foreach ($presenceDef as $id => $name) {
            $presenceValues[] = [
                'ModeID' => $id,
                'ModeName' => $name,
                'EntrySequencer' => $mapP[$id]['EntrySequencer'] ?? 0,
                'ExitSequencer' => $mapP[$id]['ExitSequencer'] ?? 0
            ];
        }
        $newPresenceJson = json_encode($presenceValues);
        if ($newPresenceJson !== $presenceJson) {
            IPS_SetProperty($this->InstanceID, 'PresenceSequencers', $newPresenceJson);
        }

        $activityDef = [0 => 'Normal', 1 => 'Heimkino', 2 => 'Schlafen', 3 => 'Party'];
        $activityJson = $this->ReadPropertyString('ActivitySequencers');
        $activityList = json_decode($activityJson, true) ?: [];
        $mapA = [];
        foreach ($activityList as $index => $a) {
            $id = (isset($a['ModeID']) && $a['ModeID'] !== '') ? (int)$a['ModeID'] : $index;
            $mapA[$id] = $a;
        }
        $activityValues = [];
        foreach ($activityDef as $id => $name) {
            $activityValues[] = [
                'ModeID' => $id,
                'ModeName' => $name,
                'EntrySequencer' => $mapA[$id]['EntrySequencer'] ?? 0,
                'ExitSequencer' => $mapA[$id]['ExitSequencer'] ?? 0
            ];
        }
        $newActivityJson = json_encode($activityValues);
        if ($newActivityJson !== $activityJson) {
            IPS_SetProperty($this->InstanceID, 'ActivitySequencers', $newActivityJson);
        }

        parent::ApplyChanges();

        // === Auto-generated References ===
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $this->RegisterSequencerReferences('PresenceSequencers');
        $this->RegisterSequencerReferences('ActivitySequencers');

        // === Create PresenceMode Profile (has EnableAction Ã¢â€ â€™ Legacy Profile) ===
        $presenceProfile = 'SHC.PresenceMode.' . $this->InstanceID;
        if (!IPS_VariableProfileExists($presenceProfile)) {
            IPS_CreateVariableProfile($presenceProfile, 1);
            IPS_SetVariableProfileIcon($presenceProfile, 'House');
        }
        // Clear existing associations
        foreach (IPS_GetVariableProfile($presenceProfile)['Associations'] as $a) {
            IPS_SetVariableProfileAssociation($presenceProfile, $a['Value'], '', '', -1);
        }
        IPS_SetVariableProfileAssociation($presenceProfile, self::PRESENCE_HOME, 'Zuhause', 'House', 0x00CC00);
        IPS_SetVariableProfileAssociation($presenceProfile, self::PRESENCE_AWAY, 'Kurz weg', 'Motion', 0xFFAA00);
        IPS_SetVariableProfileAssociation($presenceProfile, self::PRESENCE_VACATION, 'Urlaub', 'Suitcase', 0xFF4400);
        IPS_SetVariableCustomProfile($this->GetIDForIdent('PresenceMode'), $presenceProfile);

        // === Create ActivityMode Profile (has EnableAction Ã¢â€ â€™ Legacy Profile) ===
        $activityProfile = 'SHC.ActivityMode.' . $this->InstanceID;
        if (!IPS_VariableProfileExists($activityProfile)) {
            IPS_CreateVariableProfile($activityProfile, 1);
            IPS_SetVariableProfileIcon($activityProfile, 'Gear');
        }
        foreach (IPS_GetVariableProfile($activityProfile)['Associations'] as $a) {
            IPS_SetVariableProfileAssociation($activityProfile, $a['Value'], '', '', -1);
        }
        IPS_SetVariableProfileAssociation($activityProfile, self::ACTIVITY_NORMAL, 'Normal', 'Sun', -1);
        IPS_SetVariableProfileAssociation($activityProfile, self::ACTIVITY_CINEMA, 'Heimkino', 'Movie', 0x6644CC);
        IPS_SetVariableProfileAssociation($activityProfile, self::ACTIVITY_SLEEP, 'Schlafen', 'Moon', 0x003388);
        IPS_SetVariableProfileAssociation($activityProfile, self::ACTIVITY_PARTY, 'Party', 'Party', 0xFF00AA);
        IPS_SetVariableCustomProfile($this->GetIDForIdent('ActivityMode'), $activityProfile);

        // Sync Action state for ActivityMode on startup / apply changes
        if ((int)$this->GetValue('PresenceMode') === self::PRESENCE_HOME) {
            $this->EnableAction('ActivityMode');
        } else {
            $this->DisableAction('ActivityMode');
        }

        // === CustomPresentation for read-only central state variables ===
        // Sync properties to variables for Energy Calculator
        $this->SetValue('VarPriceElectricity', $this->ReadPropertyFloat('PriceElectricity') * 100);
        $this->SetValue('VarBasePriceElectricity', $this->ReadPropertyFloat('BasePriceElectricity'));
        
        $this->SetValue('VarPriceWater', $this->ReadPropertyFloat('PriceWater') * 100);
        $this->SetValue('VarBasePriceWater', $this->ReadPropertyFloat('BasePriceWater'));
        
        $this->SetValue('VarPriceGas', $this->ReadPropertyFloat('PriceGas') * 100);
        $this->SetValue('VarBasePriceGas', $this->ReadPropertyFloat('BasePriceGas'));

        $this->ApplyFireplacePresentation();
        $this->ApplyMediaPlayingPresentation();
        $this->ApplyIrrigationPresentation();

        // === Remove old legacy variables ===
        $this->MaintainVariable('HouseMode', '', 0, '', 0, false);
        $this->MaintainVariable('AbsenceStatus', '', 0, '', 0, false);

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

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'PresenceMode':
                $this->SetPresenceMode((int)$Value);
                break;
            case 'ActivityMode':
                $this->SetActivityMode((int)$Value);
                break;
            case 'PresenceStatus':
                // Google Home Toggle: true = Zuhause, false = Kurz weg
                $this->SetPresenceMode($Value ? self::PRESENCE_HOME : self::PRESENCE_AWAY);
                break;
        }
    }

    // =========================================================================
    // Public Setter Methods (called by other modules)
    // =========================================================================

    public function SetPresenceMode(int $mode): void
    {
        if ($mode < 0 || $mode > 2) {
            $this->SLog('ERROR', 'UngÃ¼ltiger PresenceMode: ' . $mode);
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

        // Dynamisch die Steuerung der AktivitÃ¤t aktivieren/deaktivieren
        if ($mode === self::PRESENCE_HOME) {
            $this->EnableAction('ActivityMode');
        } else {
            $this->DisableAction('ActivityMode');
        }

        $modeName = match($mode) {
            self::PRESENCE_HOME     => 'Zuhause',
            self::PRESENCE_AWAY     => 'Kurz weg',
            self::PRESENCE_VACATION => 'Urlaub',
            default                 => 'Unbekannt'
        };
        $this->SLog('INFO', 'Anwesenheit gewechselt auf: ' . $modeName);

        // Auto-Reset: ActivityMode Ã¢â€ â€™ Normal when leaving
        if ($mode !== self::PRESENCE_HOME) {
            $currentActivity = (int)$this->GetValue('ActivityMode');
            if ($currentActivity !== self::ACTIVITY_NORMAL) {
                $this->TriggerSequencer('ActivitySequencers', $currentActivity, false);
                $this->SetValue('ActivityMode', self::ACTIVITY_NORMAL);
                $this->SLog('INFO', 'Auto-Reset: AktivitÃ¤t zurÃ¼ck auf Normal (Haus verlassen).');
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
            $this->SLog('ERROR', 'UngÃ¼ltiger ActivityMode: ' . $mode);
            return;
        }

        // ActivityMode kann nur geÃƒÂ¤ndert werden wenn jemand Zuhause ist
        if ((int)$this->GetValue('PresenceMode') !== self::PRESENCE_HOME) {
            $this->SLog('WARNING', 'AktivitÃ¤t kann nur geÃƒÂ¤ndert werden wenn jemand Zuhause ist.');
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
        $this->SLog('INFO', 'AktivitÃ¤t gewechselt auf: ' . $modeName);

        if ($oldMode !== $mode) {
            // Execute entry sequence for new activity mode
            $this->TriggerSequencer('ActivitySequencers', $mode, true);
        }
    }

    public function SetFireplaceActive(bool $active): void
    {
        $this->SetValue('FireplaceActive', $active);
        $this->SLog('INFO', 'Kamin: ' . ($active ? 'Aktiv' : 'Aus'));
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
        $this->SLog('INFO', 'Alarm-Stufe: ' . $levelName);
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

    public function GetPriceElectricity(): float
    {
        return $this->ReadPropertyFloat('PriceElectricity');
    }

    public function GetPriceWater(): float
    {
        return $this->ReadPropertyFloat('PriceWater');
    }

    public function GetPriceGas(): float
    {
        return $this->ReadPropertyFloat('PriceGas');
    }

    // =========================================================================
    // Calendar Automation
    // =========================================================================

    public function CheckCalendar(): void
    {
        $url = $this->ReadPropertyString('CalendarURL');
        if (empty($url)) {
            $this->SLog('DEBUG', 'CheckCalendar: Keine iCal-URL hinterlegt.');
            return;
        }

        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $icalData = @file_get_contents($url, false, $ctx);
        if ($icalData === false) {
            $error = error_get_last();
            $this->SLog('ERROR', 'CheckCalendar: Konnte Kalenderdaten nicht abrufen.', $error['message'] ?? 'Unbekannter Fehler');
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
            $this->SLog('INFO', 'Kalender: Urlaubstermin aktiv! Wechsle in den Urlaubs-Modus.');
            $this->WriteAttributeBoolean('VacationFromCalendar', true);
            $this->SetPresenceMode(self::PRESENCE_VACATION);
        } elseif (!$vacationFound && $currentPresence === self::PRESENCE_VACATION) {
            if ($this->ReadAttributeBoolean('VacationFromCalendar')) {
                $this->SLog('INFO', 'Kalender: Urlaubstermin beendet! Wechsle zurÃ¼ck auf Zuhause.');
                $this->WriteAttributeBoolean('VacationFromCalendar', false);
                $this->SetPresenceMode(self::PRESENCE_HOME);
            } else {
                $this->SLog('DEBUG', 'CheckCalendar: Kein Urlaub im Kalender, aber manuell gesetzt. ÃƒÅ“berschreibe nicht.');
            }
        }
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    private function TriggerSequencer(string $property, int $modeID, bool $isEntry): void
    {
        $sequencersJson = $this->ReadPropertyString($property);
        $sequencers = json_decode($sequencersJson, true);
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
                        $this->SLog('INFO', ($isEntry ? 'Eintritts' : 'Austritts') . '-Sequenz ausgefÃ¼hrt.', 'Instanz: ' . $seqInst);
                    } elseif (!$isEntry && function_exists('SHSQ_RunDeactivationSequence')) {
                        SHSQ_RunDeactivationSequence($seqInst);
                        $this->SLog('INFO', ($isEntry ? 'Eintritts' : 'Austritts') . '-Sequenz ausgefÃ¼hrt.', 'Instanz: ' . $seqInst);
                    }
                }
                break;
            }
        }
    }

    private function RegisterSequencerReferences(string $property): void
    {
        $list = json_decode($this->ReadPropertyString($property), true);
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

    private function ApplyFireplacePresentation(): void
    {
        $options = json_encode([
            ['Value' => false, 'Caption' => 'Aus', 'IconValue' => 'Flame', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0x888888, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x888888],
            ['Value' => true, 'Caption' => 'Aktiv', 'IconValue' => 'Flame', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0xFF4400, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF4400]
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('FireplaceActive'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON' => 'Flame',
            'DIGITS' => 2, 'COLOR' => -1, 'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0, 'PREVIEW_STYLE' => 1, 'SHOW_PREVIEW' => true,
            'OPTIONS' => $options
        ]);
    }

    private function ApplyMediaPlayingPresentation(): void
    {
        $options = json_encode([
            ['Value' => false, 'Caption' => 'Aus', 'IconValue' => 'Speaker', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0x888888, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x888888],
            ['Value' => true, 'Caption' => 'Aktiv', 'IconValue' => 'Speaker', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0x00AAFF, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00AAFF]
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('MediaPlaying'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON' => 'Speaker', 'COLOR' => -1, 'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0, 'PREVIEW_STYLE' => 1, 'SHOW_PREVIEW' => true,
            'OPTIONS' => $options
        ]);
    }

    private function ApplyIrrigationPresentation(): void
    {
        $options = json_encode([
            ['Value' => false, 'Caption' => 'Aus', 'IconValue' => 'Drops', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0x888888, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x888888],
            ['Value' => true, 'Caption' => 'Aktiv', 'IconValue' => 'Drops', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0x0088FF, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x0088FF]
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('IrrigationActive'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON' => 'Drops', 'COLOR' => -1, 'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0, 'PREVIEW_STYLE' => 1, 'SHOW_PREVIEW' => true,
            'OPTIONS' => $options
        ]);
    }

    // =========================================================================
    // Logging
    // =========================================================================

    protected function LogMessage(string $Message, int $Type): bool
    {
        IPS_LogMessage('SmartVillaKunterbunt', 'SmartHomeControl: ' . $Message);
        return true;
    }

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
                            "width": "150px"
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
                            "width": "150px"
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
            "caption": "💰 Energiepreise",
            "expanded": false,
            "items": [
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "NumberSpinner",
                            "name": "PriceElectricity",
                            "caption": "Strompreis (€/kWh)",
                            "digits": 4,
                            "minimum": 0,
                            "suffix": "€/kWh"
                        },
                        {
                            "type": "NumberSpinner",
                            "name": "BasePriceElectricity",
                            "caption": "Grundpreis (€/Jahr)",
                            "digits": 2,
                            "minimum": 0,
                            "suffix": "€/Jahr"
                        }
                    ]
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "NumberSpinner",
                            "name": "PriceWater",
                            "caption": "Wasserpreis (€/m³)",
                            "digits": 4,
                            "minimum": 0,
                            "suffix": "€/m³"
                        },
                        {
                            "type": "NumberSpinner",
                            "name": "BasePriceWater",
                            "caption": "Grundpreis (€/Jahr)",
                            "digits": 2,
                            "minimum": 0,
                            "suffix": "€/Jahr"
                        }
                    ]
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "NumberSpinner",
                            "name": "PriceGas",
                            "caption": "Gaspreis (€/kWh)",
                            "digits": 4,
                            "minimum": 0,
                            "suffix": "€/kWh"
                        },
                        {
                            "type": "NumberSpinner",
                            "name": "BasePriceGas",
                            "caption": "Grundpreis (€/Jahr)",
                            "digits": 2,
                            "minimum": 0,
                            "suffix": "€/Jahr"
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

        return $json;
    }
}
