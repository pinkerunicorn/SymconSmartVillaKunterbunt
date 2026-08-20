<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_HardwareControl.php';
require_once __DIR__ . '/../libs/Trait_CentralStateAware.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';
require_once __DIR__ . '/../libs/Trait_InventoryAware.php';

class SmartGarage extends IPSModuleStrict
{
    use SmartLog_Trait;
    use HardwareControl_Trait;
    use CentralStateAware_Trait;
    use DeviceAvailability_Trait;
    use InventoryAware_Trait;

    private const STATE_CLOSED = 0;
    private const STATE_OPEN = 1;
    private const STATE_MOVING_UP = 2;
    private const STATE_MOVING_DOWN = 3;
    private const STATE_STOPPED = 4;

    public function Create(): void
    {
        parent::Create();
        
        $this->RegisterPropertyInteger('RegistryID', 0);
        $this->DA_RegisterAvailability(900);

        // Properties
        $this->RegisterPropertyInteger('MotorVariableID', 0);
        $this->RegisterPropertyInteger('SensorClosedID', 0);
        $this->RegisterPropertyString('SensorClosedValue', 'true');
        $this->RegisterPropertyInteger('SensorOpenID', 0);
        $this->RegisterPropertyString('SensorOpenValue', 'true');
        
        $this->RegisterPropertyString('ButtonVariables', '[]');
        $this->RegisterPropertyString('LEDInstances', '[]');
        $this->RegisterPropertyInteger('AlarmDelayMinutes', 60);
        $this->RegisterPropertyBoolean('CloseOnAbsence', true);

        // Properties: Außensirene Lichtzeichen bei Bewegung (ohne Ton)
        $this->RegisterPropertyInteger('TargetSiren', 0);
        $this->RegisterPropertyInteger('SirenOpticalMovingUp', 3);   // 3 = Gleichzeitig schnelles Blinken
        $this->RegisterPropertyInteger('SirenOpticalMovingDown', 1); // 1 = Abwechselndes langsames Blinken

        // Attribute for tracking the last direction to guess the next move
        $this->RegisterAttributeInteger('LastDirection', self::STATE_MOVING_UP); // 2=Fährt Auf, 3=Fährt Zu

        // Timer for Relay impulse and Alarm
        $this->RegisterTimer('RelayOffTimer', 0, 'SHG_TurnOffRelay($_IPS[\'TARGET\']);');
        $this->RegisterTimer('OpenAlarmTimer', 0, 'SHG_TriggerOpenAlarm($_IPS[\'TARGET\']);');

        // Variables
        $doorIntervals = json_encode([
            [
                'IntervalMinValue' => 0, 'IntervalMaxValue' => 0,
                'ConstantActive' => true, 'ConstantValue' => 'Zu',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'LockClosed',
                'ColorActive' => false, 'ColorValue' => 0,
                'ContentColorActive' => false, 'ContentColorValue' => 0
            ],
            [
                'IntervalMinValue' => 1, 'IntervalMaxValue' => 1,
                'ConstantActive' => true, 'ConstantValue' => 'Auf',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'LockOpen',
                'ColorActive' => false, 'ColorValue' => 0,
                'ContentColorActive' => false, 'ContentColorValue' => 0
            ],
            [
                'IntervalMinValue' => 2, 'IntervalMaxValue' => 2,
                'ConstantActive' => true, 'ConstantValue' => 'Fährt Auf...',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'ArrowUp',
                'ColorActive' => false, 'ColorValue' => 0,
                'ContentColorActive' => false, 'ContentColorValue' => 0
            ],
            [
                'IntervalMinValue' => 3, 'IntervalMaxValue' => 3,
                'ConstantActive' => true, 'ConstantValue' => 'Fährt Zu...',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'ArrowDown',
                'ColorActive' => false, 'ColorValue' => 0,
                'ContentColorActive' => false, 'ContentColorValue' => 0
            ],
            [
                'IntervalMinValue' => 4, 'IntervalMaxValue' => 4,
                'ConstantActive' => true, 'ConstantValue' => 'Teiloffen / Gestoppt',
                'ConversionFactor' => 1,
                'PrefixActive' => false, 'PrefixValue' => '',
                'SuffixActive' => false, 'SuffixValue' => '',
                'DigitsActive' => false, 'DigitsValue' => 0,
                'IconActive' => true, 'IconValue' => 'triangle-exclamation',
                'ColorActive' => true, 'ColorValue' => 0xFF8000,
                'ContentColorActive' => false, 'ContentColorValue' => 0
            ]
        ]);

        $this->RegisterVariableInteger('DoorState', 'Torstatus', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'warehouse',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS' => $doorIntervals
        ], 1);
        $this->RegisterVariableBoolean('DoorControl', 'Tor Steuerung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'window-maximize'
        ], 2);
        $this->RegisterVariableBoolean('AlarmOpenTooLong', 'Alarm: Tor zu lange offen', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'triangle-exclamation'
        ], 3);
        
        $this->EnableAction('DoorControl');
        $this->EnableAction('AlarmOpenTooLong'); // Allow acknowledging
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        $this->SubscribeToCentralStates(['PresenceMode']);
        // --- Auto-generated References ---
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $ref_MotorVariableID = $this->ReadPropertyInteger('MotorVariableID');
        if ($ref_MotorVariableID > 1 && @IPS_ObjectExists($ref_MotorVariableID)) {
            $this->RegisterReference($ref_MotorVariableID);
        }
        $ref_SensorClosedID = $this->ReadPropertyInteger('SensorClosedID');
        if ($ref_SensorClosedID > 1 && @IPS_ObjectExists($ref_SensorClosedID)) {
            $this->RegisterReference($ref_SensorClosedID);
        }
        $ref_SensorOpenID = $this->ReadPropertyInteger('SensorOpenID');
        if ($ref_SensorOpenID > 1 && @IPS_ObjectExists($ref_SensorOpenID)) {
            $this->RegisterReference($ref_SensorOpenID);
        }
        $targetSiren = $this->ReadPropertyInteger('TargetSiren');
        if ($targetSiren > 1 && @IPS_ObjectExists($targetSiren)) {
            $this->RegisterReference($targetSiren);
        }
        $list_ButtonVariables = $this->safeJsonDecode($this->ReadPropertyString('ButtonVariables'), true);
        if (is_array($list_ButtonVariables)) {
            foreach ($list_ButtonVariables as $item) {
                $vid = $item['VariableID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        $list_LEDInstances = $this->safeJsonDecode($this->ReadPropertyString('LEDInstances'), true);
        if (is_array($list_LEDInstances)) {
            foreach ($list_LEDInstances as $item) {
                $vid = $item['InstanceID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        // ---------------------------------

        // Register messages for sensors
        $sensorClosed = $this->ReadPropertyInteger('SensorClosedID');
        if ($sensorClosed > 0 && IPS_VariableExists($sensorClosed)) {
            $this->RegisterMessage($sensorClosed, VM_UPDATE);
        }
        $sensorOpen = $this->ReadPropertyInteger('SensorOpenID');
        if ($sensorOpen > 0 && IPS_VariableExists($sensorOpen)) {
            $this->RegisterMessage($sensorOpen, VM_UPDATE);
        }

        // Create links for the sensors so they are visible under the instance
        $this->MaintainLink('LinkSensorClosed', 'Sensor Zu', $sensorClosed, 3);
        $this->MaintainLink('LinkSensorOpen', 'Sensor Auf', $sensorOpen, 4);
        
        if (@IPS_GetObjectIDByIdent('LinkSensorClosed', $this->InstanceID) !== false) {
            IPS_SetIcon($this->GetIDForIdent('LinkSensorClosed'), 'LockClosed');
        }
        if (@IPS_GetObjectIDByIdent('LinkSensorOpen', $this->InstanceID) !== false) {
            IPS_SetIcon($this->GetIDForIdent('LinkSensorOpen'), 'LockOpen');
        }

        // Register messages for buttons
        $buttons = $this->safeJsonDecode($this->ReadPropertyString('ButtonVariables'), true);
        if (is_array($buttons)) {
            foreach ($buttons as $btn) {
                $id = $btn['VariableID'];
                if ($id > 0 && IPS_VariableExists($id)) {
                    $this->RegisterMessage($id, VM_UPDATE);
                }
            }
        }

        // Initialize status
        $this->CheckSensors();
        $this->DA_SetAvailable(true);
        $this->SetStatus(102);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'DoorControl') {
            $this->TriggerDoor();
            // Reset control button instantly so it acts like a push button
            $this->SetValue('DoorControl', false);
        } elseif ($Ident === 'AlarmOpenTooLong') {
            $this->SetValue('AlarmOpenTooLong', false);
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($this->HandleCentralStateMessage($SenderID, $Message, $Data)) return;
        if ($Message == VM_UPDATE) {
            $sensorClosed = $this->ReadPropertyInteger('SensorClosedID');
            $sensorOpen = $this->ReadPropertyInteger('SensorOpenID');
            
            if ($SenderID == $sensorClosed || $SenderID == $sensorOpen) {
                $this->CheckSensors();
                return;
            }

            // Check if it's a button
            $buttons = $this->safeJsonDecode($this->ReadPropertyString('ButtonVariables'), true);
            if (is_array($buttons)) {
                foreach ($buttons as $btn) {
                    if ($SenderID == $btn['VariableID']) {
                        $currentVal = GetValue($SenderID);
                        if ($this->ValuesMatch($currentVal, $btn['TriggerValue'])) {
                            $this->SLogInfo( 'Tor-Aktion durch Taster ausgelöst.', "Taster-ID: $SenderID");
                            $this->TriggerDoor();
                        }
                    }
                }
            }
        }
    }

    private function TriggerDoor(): void
    {
        $motorId = $this->ReadPropertyInteger('MotorVariableID');
        if ($motorId > 0 && IPS_VariableExists($motorId)) {
            if (!$this->safeRequestAction($motorId, true)) {
                $devName = @IPS_GetName($motorId) ?: "ID:$motorId";
                $this->SLogWarning( "Aktor fehlgeschlagen: $devName", "ID: $motorId | Ziel: true");
            } else {
                $this->SLogInfo( 'Aktor (Motor) geschaltet.', "ID: $motorId | Wert: true");
            }
            $this->SetTimerInterval('RelayOffTimer', 1000); // Trigger release after 1s
        } else {
            $this->SLogError( 'Tor konnte nicht getriggert werden.', "Grund: Kein Motor-Aktor konfiguriert");
        }

        // Calculate expected state
        $currentState = $this->GetValue('DoorState');
        $nextState = self::STATE_STOPPED; // Default to Gestoppt

        if ($currentState === self::STATE_CLOSED) {
            $nextState = self::STATE_MOVING_UP; // Fährt Auf
        } elseif ($currentState === self::STATE_OPEN) {
            $nextState = self::STATE_MOVING_DOWN; // Fährt Zu
        } elseif ($currentState === self::STATE_MOVING_UP || $currentState === self::STATE_MOVING_DOWN) {
            $nextState = self::STATE_STOPPED; // Gestoppt
        } elseif ($currentState === self::STATE_STOPPED) {
            // Wenn Teiloffen und getriggert wird, raten wir anhand der letzten Fahrtrichtung
            $lastDir = $this->ReadAttributeInteger('LastDirection');
            $nextState = ($lastDir === self::STATE_MOVING_UP) ? self::STATE_MOVING_DOWN : self::STATE_MOVING_UP; 
        }

        if ($nextState === self::STATE_MOVING_UP || $nextState === self::STATE_MOVING_DOWN) {
            $this->WriteAttributeInteger('LastDirection', $nextState);
        }

        $this->SetDoorState($nextState);
    }

    public function TurnOffRelay(): void
    {
        $this->SetTimerInterval('RelayOffTimer', 0); // Disable timer
        $motorId = $this->ReadPropertyInteger('MotorVariableID');
        if ($motorId > 0 && IPS_VariableExists($motorId)) {
            if (!$this->safeRequestAction($motorId, false)) {
                $this->SLogWarning( 'Aktor-Befehl fehlgeschlagen', "ID: $motorId | Wert: false");
            } else {
                $this->SLogInfo( 'Aktor (Motor) ausgeschaltet.', "ID: $motorId | Wert: false");
            }
        }
    }

    private function CheckSensors(): void
    {
        $sensorClosed = $this->ReadPropertyInteger('SensorClosedID');
        $sensorOpen = $this->ReadPropertyInteger('SensorOpenID');

        $isClosed = false;
        $isOpen = false;

        if ($sensorClosed > 0 && IPS_VariableExists($sensorClosed)) {
            $isClosed = $this->ValuesMatch(GetValue($sensorClosed), $this->ReadPropertyString('SensorClosedValue'));
        }
        if ($sensorOpen > 0 && IPS_VariableExists($sensorOpen)) {
            $isOpen = $this->ValuesMatch(GetValue($sensorOpen), $this->ReadPropertyString('SensorOpenValue'));
        }

        $currentState = $this->GetValue('DoorState');
        $newState = $currentState;

        if ($isClosed) {
            $newState = self::STATE_CLOSED; // Zu
        } elseif ($isOpen) {
            $newState = self::STATE_OPEN; // Auf
        } else {
            // Weder Zu noch Auf. 
            // Wenn der letzte Zustand "Zu"(0) oder "Auf"(1) war, 
            // wissen wir, dass es jetzt per Hand bewegt wurde oder der Impuls losgeht.
            // Ist es aber z.B. schon auf "Fährt Auf"(2), belassen wir es dabei.
            if ($currentState === self::STATE_CLOSED) {
                // Es hat "Zu" verlassen -> Es fährt wahrscheinlich auf.
                $newState = self::STATE_MOVING_UP; 
            } elseif ($currentState === self::STATE_OPEN) {
                // Es hat "Auf" verlassen -> Es fährt wahrscheinlich zu.
                $newState = self::STATE_MOVING_DOWN;
            }
        }

        if ($newState !== $currentState) {
            $this->SetDoorState($newState);
        }
    }

    private function SetDoorState(int $state): void
    {
        if ($this->GetValue('DoorState') !== $state) {
            $this->SetValue('DoorState', $state);
            $this->UpdateLEDs($state);
            $this->UpdateSirenLight($state);
            
            // Alarm Logic
            if ($state === self::STATE_OPEN || $state === self::STATE_STOPPED) { // 1 = Auf, 4 = Teiloffen
                $delayMinutes = $this->ReadPropertyInteger('AlarmDelayMinutes');
                if ($delayMinutes > 0 && $this->GetTimerInterval('OpenAlarmTimer') == 0 && !$this->GetValue('AlarmOpenTooLong')) {
                    $this->SetTimerInterval('OpenAlarmTimer', $delayMinutes * 60000);
                }
            } else {
                // If closing or closed, cancel timer
                $this->SetTimerInterval('OpenAlarmTimer', 0);
                if ($state === self::STATE_CLOSED && $this->GetValue('AlarmOpenTooLong')) {
                    $this->SetValue('AlarmOpenTooLong', false);
                }
            }
        }
    }
    
    public function TriggerOpenAlarm(): void
    {
        $this->SetTimerInterval('OpenAlarmTimer', 0);
        $this->SetValueIfChanged('AlarmOpenTooLong', true);
        $this->SLogWarning( 'Alarm ausgelöst!', 'Grund: Garagentor steht zu lange offen');
    }

    private function UpdateLEDs(int $state): void
    {
        $leds = $this->safeJsonDecode($this->ReadPropertyString('LEDInstances'), true);
        if (!is_array($leds) || count($leds) == 0) return;

        // Homematic COMBINED_PARAMETER Strings
        $string = '';
        if ($state === self::STATE_CLOSED) {
            // Zu -> Aus
            $string = 'L=100,DV=31,DU=2,RTV=0,RTU=0,C=0,CB=0,RTTOV=0,RTTOU=3';
        } elseif ($state === self::STATE_OPEN) {
            // Auf -> Weiß, Pulsierend
            $string = 'L=100,DV=31,DU=2,RTV=0,RTU=0,C=7,CB=9,RTTOV=0,RTTOU=3';
        } elseif ($state === self::STATE_MOVING_UP) {
            // Fährt Auf -> Gelb, Blitzen
            $string = 'L=100,DV=31,DU=2,RTV=0,RTU=0,C=6,CB=6,RTTOV=0,RTTOU=3';
        } elseif ($state === self::STATE_MOVING_DOWN) {
            // Fährt Zu -> Rot, Blitzen
            $string = 'L=100,DV=31,DU=2,RTV=0,RTU=0,C=4,CB=6,RTTOV=0,RTTOU=3';
        } elseif ($state === self::STATE_STOPPED) {
            // Gestoppt / Teiloffen -> Blau, Dauerlicht
            $string = 'L=100,DV=31,DU=2,RTV=0,RTU=0,C=1,CB=1,RTTOV=0,RTTOU=3';
        }

        if ($string === '') return;

        foreach ($leds as $led) {
            $instId = $led['InstanceID'];
            if ($instId > 0 && IPS_InstanceExists($instId)) {
                if (!@HM_WriteValueString($instId, 'COMBINED_PARAMETER', $string)) {
                    $this->SLogWarning( 'HM-Befehl fehlgeschlagen', "Instanz: $instId");
                } else {
                    $this->SLogInfo( 'HM-LED Zustand aktualisiert.', "Instanz: $instId | String: $string");
                }
            }
        }
    }

    private function UpdateSirenLight(int $state): void
    {
        $sirenId = $this->ReadPropertyInteger('TargetSiren');
        if ($sirenId <= 0 || !@IPS_InstanceExists($sirenId)) return;

        try {
            if ($state === self::STATE_MOVING_UP) {
                $optical = $this->ReadPropertyInteger('SirenOpticalMovingUp');
                if ($optical > 0) {
                    $this->SLogInfo('Außensirene', 'Starte Optik-Signal bei Garagentor Fährt Auf...');
                    $this->TriggerSirenLight($sirenId, $optical, 60);
                }
            } elseif ($state === self::STATE_MOVING_DOWN) {
                $optical = $this->ReadPropertyInteger('SirenOpticalMovingDown');
                if ($optical > 0) {
                    $this->SLogInfo('Außensirene', 'Starte Optik-Signal bei Garagentor Fährt Zu...');
                    $this->TriggerSirenLight($sirenId, $optical, 60);
                }
            } elseif ($state === self::STATE_CLOSED || $state === self::STATE_OPEN || $state === self::STATE_STOPPED) {
                $this->SLogInfo('Außensirene', 'Stoppe Optik-Signal (Tor gestoppt/angekommen).');
                $this->StopSirenLight($sirenId);
            }
        } catch (\Throwable $e) {
            $this->SLogError('Garagentor Sirenen-Licht Fehler: ' . $e->getMessage());
        }
    }

    private function TriggerSirenLight(int $sirenId, int $optical, int $durationSeconds = 60): void
    {
        if (function_exists('ASIRO_Trigger')) {
            @ASIRO_Trigger($sirenId, 0, $optical, $durationSeconds, 0); // 0 = ACOUSTIC_OFF (kein Ton!)
        } else {
            @HM_WriteValueInteger($sirenId, 'DURATION_UNIT', 0);
            @HM_WriteValueInteger($sirenId, 'DURATION_VALUE', $durationSeconds);
            @HM_WriteValueInteger($sirenId, 'OPTICAL_ALARM_SELECTION', $optical);
            @HM_WriteValueInteger($sirenId, 'ACOUSTIC_ALARM_SELECTION', 0); // 0 = kein Ton!
        }
    }

    private function StopSirenLight(int $sirenId): void
    {
        if (function_exists('ASIRO_Stop')) {
            @ASIRO_Stop($sirenId);
        } else {
            @HM_WriteValueInteger($sirenId, 'DURATION_UNIT', 0);
            @HM_WriteValueInteger($sirenId, 'DURATION_VALUE', 0);
            @HM_WriteValueInteger($sirenId, 'OPTICAL_ALARM_SELECTION', 0);
            @HM_WriteValueInteger($sirenId, 'ACOUSTIC_ALARM_SELECTION', 0);
        }
    }

    private function ValuesMatch($actual, $expected): bool
    {
        if ((string)$expected === '') {
            return true; // Empty string means trigger on ANY update
        }
        if (is_bool($actual)) {
            $targetBool = ($expected === 'true'|| $expected === '1'|| strtolower((string)$expected) === 'wahr');
            return ($actual === $targetBool);
        } elseif (is_int($actual)) {
            return ($actual === (int)$expected);
        } elseif (is_float($actual)) {
            return ($actual === (float)$expected);
        } elseif (is_string($actual)) {
            return (strtolower(trim($actual)) === strtolower(trim((string)$expected)));
        }
        return ($actual == $expected);
    }

    private function MaintainLink(string $ident, string $name, int $targetID, int $position): void
    {
        $linkID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($targetID === 0) {
            if ($linkID !== false) {
                IPS_DeleteLink($linkID);
            }
            return;
        }
        if ($linkID === false) {
            $linkID = IPS_CreateLink();
            IPS_SetParent($linkID, $this->InstanceID);
            IPS_SetIdent($linkID, $ident);
            IPS_SetName($linkID, $name);
            IPS_SetPosition($linkID, $position);
        }
        IPS_SetLinkTargetID($linkID, $targetID);
    }
    
    protected function OnCentralStateChanged(string $stateName, mixed $newValue): void
    {
        if ($stateName === 'PresenceMode') {
            $mode = (int)$newValue;
            if ($mode === 1 || $mode === 2) {
                if ($this->ReadPropertyBoolean('CloseOnAbsence')) {
                    $state = GetValue($this->GetIDForIdent('DoorState'));
                    if ($state != 0 && $state != 3) {
                        $this->SLogInfo( 'Schließe Garagentor automatisch.', "Hausmodus: Abwesenheit aktiv");
                        $this->TriggerDoor();
                    } else {
                        $this->SLogInfo( 'Automatisches Schließen übersprungen.', "Grund: Tor bereits zu");
                    }
                }
            }
        }
    }

    private function SetValueIfChanged(string $Ident, $Value): void
    {
        $id = $this->GetIDForIdent($Ident);
        if (GetValue($id) !== $Value) {
            SetValue($id, $Value);
        }
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "CheckBox",
            "name": "SimulationMode",
            "caption": "Simulationsmodus (Testbetrieb)"
        },
        {
            "type": "Label",
            "caption": " "
        },
        {
            "type": "ExpansionPanel",
            "caption": "🚨 Außensirene Lichtzeichen (Tor-Bewegung)",
            "items": [
                {
                    "type": "SelectInstance",
                    "name": "TargetSiren",
                    "caption": "Außensirene (HmIP_ASIRO Modul oder Kanal 3)"
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "Select",
                            "name": "SirenOpticalMovingUp",
                            "caption": "Lichtzeichen beim Öffnen",
                            "options": [
                                { "caption": "Kein Licht",                        "value": 0 },
                                { "caption": "Abwechselnd langsames Blinken",     "value": 1 },
                                { "caption": "Gleichzeitig langsames Blinken",    "value": 2 },
                                { "caption": "Gleichzeitig schnelles Blinken",    "value": 3 },
                                { "caption": "Gleichzeitig kurzes Blinken",       "value": 4 },
                                { "caption": "Bestätigung 0 (lang lang)",         "value": 5 },
                                { "caption": "Bestätigung 1 (lang kurz)",         "value": 6 },
                                { "caption": "Bestätigung 2 (lang kurz kurz)",    "value": 7 }
                            ]
                        },
                        {
                            "type": "Select",
                            "name": "SirenOpticalMovingDown",
                            "caption": "Lichtzeichen beim Schließen",
                            "options": [
                                { "caption": "Kein Licht",                        "value": 0 },
                                { "caption": "Abwechselnd langsames Blinken",     "value": 1 },
                                { "caption": "Gleichzeitig langsames Blinken",    "value": 2 },
                                { "caption": "Gleichzeitig schnelles Blinken",    "value": 3 },
                                { "caption": "Gleichzeitig kurzes Blinken",       "value": 4 },
                                { "caption": "Bestätigung 0 (lang lang)",         "value": 5 },
                                { "caption": "Bestätigung 1 (lang kurz)",         "value": 6 },
                                { "caption": "Bestätigung 2 (lang kurz kurz)",    "value": 7 }
                            ]
                        }
                    ]
                }
            ]
        },
        {
            "type": "ExpansionPanel",
            "caption": "⚙ Tor Konfiguration",
            "items": [
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "CheckBox",
                            "name": "CloseOnAbsence",
                            "caption": "Bei Abwesenheit automatisch schließen"
                        }
                    ]
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "SelectVariable",
                            "name": "MotorVariableID",
                            "caption": "Motor Relais (Impuls)"
                        }
                    ]
                },
                {
                    "type": "Label",
                    "label": "Sensoren (Endschalter)"
                }
            ]
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "SelectVariable",
                    "name": "SensorClosedID",
                    "caption": "Sensor: Zu-Position"
                },
                {
                    "type": "ValidationTextBox",
                    "name": "SensorClosedValue",
                    "caption": "Auslöse-Wert (z.B. true, CLOSED)"
                }
            ]
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "SelectVariable",
                    "name": "SensorOpenID",
                    "caption": "Sensor: Auf-Position"
                },
                {
                    "type": "ValidationTextBox",
                    "name": "SensorOpenValue",
                    "caption": "Auslöse-Wert (z.B. true, CLOSED)"
                }
            ]
        },
        {
            "type": "NumberSpinner",
            "name": "AlarmDelayMinutes",
            "caption": "Alarm: Tor zu lange offen (Minuten, 0 = aus)",
            "suffix": "min"
        },
        {
            "type": "Label",
            "label": "Taster (Auslöser)"
        },
        {
            "type": "List",
            "name": "ButtonVariables",
            "caption": "Wand- & Funktaster",
            "rowCount": 5,
            "add": true,
            "delete": true,
            "columns": [
                {
                    "caption": "Variable (Sensor)",
                    "name": "VariableID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Auslöse-Wert",
                    "name": "TriggerValue",
                    "width": "150px",
                    "add": "CLOSED",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                }
            ]
        },
        {
            "type": "Label",
            "label": "Homematic LEDs"
        },
        {
            "type": "List",
            "name": "LEDInstances",
            "caption": "Status LEDs",
            "rowCount": 5,
            "add": true,
            "delete": true,
            "columns": [
                {
                    "caption": "Homematic Instanz ID",
                    "name": "InstanceID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectInstance"
                    }
                }
            ]
        }
    ]
}
EOT;
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
