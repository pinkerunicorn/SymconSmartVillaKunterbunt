<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';

class SmartHomeControl extends IPSModuleStrict
{
    use SmartLog_Trait;

    public function Create(): void
    {
        parent::Create();

        $defaultModes = [
            ['ModeID'=> 0, 'ModeName'=> 'Anwesenheit', 'ICON'=> 'House', 'Color'=> -1, 'IsAbsence'=> false, 'IsSleep'=> false, 'SequencerInstance'=> 0],
            ['ModeID'=> 1, 'ModeName'=> 'Abwesenheit', 'ICON'=> 'Motion', 'Color'=> -1, 'IsAbsence'=> true, 'IsSleep'=> false, 'SequencerInstance'=> 0],
            ['ModeID'=> 2, 'ModeName'=> 'Urlaub', 'ICON'=> 'Suitcase', 'Color'=> -1, 'IsAbsence'=> true, 'IsSleep'=> false, 'SequencerInstance'=> 0],
            ['ModeID'=> 5, 'ModeName'=> 'Schlafen', 'ICON'=> 'Moon', 'Color'=> -1, 'IsAbsence'=> false, 'IsSleep'=> true, 'SequencerInstance'=> 0]
        ];
        $this->RegisterPropertyString('HouseModes', json_encode($defaultModes));
        
        $this->RegisterPropertyString('CalendarURL', '');
        
        $this->RegisterVariableInteger('HouseMode', '🏠 Haus Modus', '', 2);
        IPS_SetIcon($this->GetIDForIdent('HouseMode'), 'Gear');
        $this->EnableAction('HouseMode');
        
        // Google Home / Alexa Interface Variable (Boolean)
        $this->RegisterVariableBoolean('PresenceStatus', 'Anwesenheit (Google Home)', '', 1);
        IPS_SetIcon($this->GetIDForIdent('PresenceStatus'), 'Information');
        $this->EnableAction('PresenceStatus');
        
        // Timer für Kalender-Check
        $this->RegisterTimer('CalendarCheck', 0, 'SHC_CheckCalendar($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        // --- Auto-generated References ---
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $list_HouseModes = json_decode($this->ReadPropertyString('HouseModes'), true);
        if (is_array($list_HouseModes)) {
            foreach ($list_HouseModes as $item) {
                $vid = $item['SequencerInstance'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        // ---------------------------------



        $modesJson = $this->ReadPropertyString('HouseModes');
        $modes = json_decode($modesJson, true);
        if (!is_array($modes)) {
            $modes = [];
        }
        $associations = [];
        if (!IPS_VariableProfileExists('SmartAbsence.HouseMode.'. $this->InstanceID)) {
            IPS_CreateVariableProfile('SmartAbsence.HouseMode.'. $this->InstanceID, 1);
        }
        foreach ($modes as $mode) {
            IPS_SetVariableProfileAssociation('SmartAbsence.HouseMode.'. $this->InstanceID, $mode['ModeID'], $mode['ModeName'], $mode['Icon'], $mode['Color']);
        }
        
        IPS_SetVariableCustomProfile($this->GetIDForIdent('HouseMode'), 'SmartAbsence.HouseMode.'. $this->InstanceID);
        
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('PresenceStatus'), [
            'PRESENTATION'  => VARIABLE_PRESENTATION_SWITCH
        ]);
        
        $this->MaintainVariable('AbsenceStatus', '', 0, '', 0, false);

        // Timer starten (alle 30 Minuten)
        $this->SetTimerInterval('CalendarCheck', 30 * 60 * 1000);

        $this->SetStatus(102);
    }

    public function RequestAction(string $Ident, $Value): void
    {
        if ($Ident == 'HouseMode') {
            $this->SetHouseMode($Value);
        }
        
        // Google Home Toggle
        if ($Ident == 'PresenceStatus') {
            $mode = $Value ? 0 : 1; // true = Anwesenheit, false = Abwesenheit
            $this->SetHouseMode($mode);
        }
    }

    public function SetHouseMode(int $newMode, int $vacationEndTime = 0): void
    {
        $oldMode = $this->GetValue('HouseMode');
        if ($oldMode != $newMode) {
            $this->TriggerDeactivationSequence($oldMode);
        }
        $this->SetValue('HouseMode', $newMode);
        $this->SetValue('PresenceStatus', ($newMode != 1 && $newMode != 2));
        
        // Eintritts-Sequenz des neuen Modus
        $modesJson = $this->ReadPropertyString('HouseModes');
        $modes = json_decode($modesJson, true);
        $modeName = 'Unbekannt';
        if (is_array($modes)) {
            foreach ($modes as $m) {
                if ($m['ModeID'] == $newMode) {
                    $modeName = $m['ModeName'];
                    $seqInst = $m['SequencerInstance'] ?? 0;
                    if ($seqInst > 0 && IPS_InstanceExists($seqInst) && function_exists('SHSQ_RunSequence')) {
                        SHSQ_RunSequence($seqInst);
                        $this->SLog('INFO', 'Eintritts-Sequenz ausgeführt.');
                    }
                    break;
                }
            }
        }
        $this->SLog('INFO', 'Modus gewechselt auf: ' . $modeName);
        IPS_LogMessage('SmartVillaKunterbunt', 'SmartHomeControl: Haus-Modus gewechselt auf ' . $modeName);
    }
    
    private function TriggerDeactivationSequence(int $mode): void
    {
        $modesJson = $this->ReadPropertyString('HouseModes');
        $modes = json_decode($modesJson, true);
        if (is_array($modes)) {
            foreach ($modes as $m) {
                if ($m['ModeID'] == $mode) {
                    $seqInst = $m['SequencerInstance'] ?? 0;
                    if ($seqInst > 0 && IPS_InstanceExists($seqInst) && function_exists('SHSQ_RunDeactivationSequence')) {
                        SHSQ_RunDeactivationSequence($seqInst);
                        $this->SLog('INFO', "Austritts-Sequenz für Modus '" . $m['ModeName'] . "' ausgeführt.");
                    }
                    break;
                }
            }
        }
    }


    
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
        
        // Sehr simpler iCal Parser für VEVENT
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
        $vacationEndTime = 0;
        
        foreach ($events as $event) {
            if (isset($event['SUMMARY']) && strtoupper(trim($event['SUMMARY'])) === 'URLAUB') {
                if (isset($event['DTSTART']) && isset($event['DTEND'])) {
                    if ($now >= $event['DTSTART'] && $now <= $event['DTEND']) {
                        $vacationFound = true;
                        $vacationEndTime = $event['DTEND'];
                        break;
                    }
                }
            }
        }
        
        $currentMode = GetValue($this->GetIDForIdent('HouseMode'));
        
        if ($vacationFound && $currentMode !== 2) {
            $this->SLog('INFO', 'Kalender: Urlaubstermin aktiv! Wechsle in den Urlaubs-Modus.', 'Ende: ' . date('d.m. H:i', $vacationEndTime));
            $this->SetHouseMode(2, $vacationEndTime);
        } elseif (!$vacationFound && $currentMode === 2) {
            $this->SLog('INFO', 'Kalender: Urlaubstermin beendet! Wechsle zurück auf Anwesenheit.');
            $this->SetHouseMode(0);
        }
    }


    protected function LogMessage(string $Message, int $Type): bool
    {
        IPS_LogMessage('SmartVillaKunterbunt', 'SmartHomeControl: '. $Message);
        return true;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "List",
            "name": "HouseModes",
            "caption": "Haus-Modi (Matrix & Zuweisungen)",
            "add": true,
            "delete": true,
            "changeOrder": true,
            "columns": [
                {
                    "caption": "ID",
                    "name": "ModeID",
                    "width": "60px",
                    "add": 0,
                    "edit": {
                        "type": "NumberSpinner"
                    }
                },
                {
                    "caption": "Modus Name",
                    "name": "ModeName",
                    "width": "auto",
                    "add": "Neuer Modus",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Icon",
                    "name": "Icon",
                    "width": "150px",
                    "add": "",
                    "edit": {
                        "type": "SelectIcon"
                    }
                },
                {
                    "caption": "Farbe",
                    "name": "Color",
                    "width": "80px",
                    "add": -1,
                    "edit": {
                        "type": "SelectColor"
                    }
                },
                {
                    "caption": "Ist Abwesenheit?",
                    "name": "IsAbsence",
                    "width": "110px",
                    "add": false,
                    "edit": {
                        "type": "CheckBox"
                    }
                },
                {
                    "caption": "Ist Schlafen?",
                    "name": "IsSleep",
                    "width": "100px",
                    "add": false,
                    "edit": {
                        "type": "CheckBox"
                    }
                },
                {
                    "caption": "Sequencer Skript",
                    "name": "SequencerInstance",
                    "width": "150px",
                    "add": 0,
                    "edit": {
                        "type": "SelectInstance"
                    }
                }
            ]
        },
        {
            "type": "Label",
            "caption": "Urlaubs-Automatik"
        },
        {
            "type": "ValidationTextBox",
            "name": "CalendarURL",
            "caption": "Google Kalender (iCal) URL (privater Link)"
        }
    ],
    "actions": [
        {
            "type": "Button",
            "caption": "Manueller Kalender Sync",
            "onClick": "SHC_CheckCalendar($id);",
            "icon": "Play"
        }
    ]
}
EOT;
    }
}


