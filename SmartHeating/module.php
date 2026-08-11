<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_HardwareControl.php';
require_once __DIR__ . '/../libs/Trait_CentralStateAware.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class SmartHeating extends IPSModuleStrict
{
    use SmartLog_Trait;
    use HardwareControl_Trait;
    use CentralStateAware_Trait;
    use DeviceAvailability_Trait;

    public function Create(): void
    {
        parent::Create();
        
        $this->DA_RegisterAvailability(900);


        // Target temperature during absence (Fallback)
        $this->RegisterPropertyFloat('TargetTemperature', 17.0);
        $this->RegisterPropertyFloat('FrostWarningThreshold', 5.0);

        // JSON array of thermostat instances: [{"InstanceID": 12345, "TargetTemperature": 17.0}]
        $this->RegisterPropertyString('HeatingInstances', '[]');

        // Internal attribute to save previous states
        $this->RegisterAttributeString('PreviousStates', '{}');

        // GUI Variables
        $this->RegisterVariableString('HeatingStatus', 'Status', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'        => 'Information'
        ], 1);
        $this->RegisterVariableFloat('AverageTemperature', 'Ø Haus-Temperatur', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'        => 'Temperature',
            'SUFFIX'      => '°C'
        ], 2);
        
        $this->RegisterVariableBoolean('HeatingSeason', 'Heizperiode aktiv', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'Snowflake'
        ], 10);
        $this->EnableAction('HeatingSeason');
        
        $this->RegisterVariableBoolean('IsAbsenkbetrieb', 'Absenkbetrieb', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Temperature',
            'COLOR' => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => json_encode([
                ['Value' => false, 'Caption' => 'Normalbetrieb', 'IconValue' => 'Radiator', 'IconActive' => false, 'ColorActive' => false, 'ColorDisplay' => 0x00CC00, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00CC00],
                ['Value' => true, 'Caption' => 'Absenkung aktiv', 'IconValue' => 'Radiator', 'IconActive' => false, 'ColorActive' => false, 'ColorDisplay' => 0x0088FF, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x0088FF]
            ])
        ], 15);
        $this->RegisterVariableBoolean('AlarmFrostWarning', 'Alarm: Frostgefahr', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'Warning'
        ], 20);
        $this->EnableAction('AlarmFrostWarning');

    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        $this->SubscribeToCentralStates(['PresenceMode', 'ActivityMode']);
        // --- Auto-generated References ---
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $list_HeatingInstances = $this->safeJsonDecode($this->ReadPropertyString('HeatingInstances'), true);
        if (is_array($list_HeatingInstances)) {
            foreach ($list_HeatingInstances as $item) {
                $vid = $item['InstanceID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        // ---------------------------------
        

        // Variable Aggregation (Logging) für Ø Haus-Temperatur aktivieren
        $avgTempId = $this->GetIDForIdent('AverageTemperature');
        $archiveIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($archiveIDs) > 0) {
            $archiveID = $archiveIDs[0];
            $changed = false;
            if (!AC_GetLoggingStatus($archiveID, $avgTempId)) {
                AC_SetLoggingStatus($archiveID, $avgTempId, true);
                $changed = true;
            }
            if (AC_GetAggregationType($archiveID, $avgTempId) !== 0) { // 0 = Standard (Ø)
                AC_SetAggregationType($archiveID, $avgTempId, 0);
                $changed = true;
            }
            if ($changed) {
                IPS_ApplyChanges($archiveID);
            }
        }

        // Unregister old messages
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                if ($message == VM_UPDATE) {
                    $this->UnregisterMessage($senderID, $message);
                }
            }
        }

        // Register new messages for actual temperatures
        $heatingInsts = $this->safeJsonDecode($this->ReadPropertyString('HeatingInstances'), true);
        if (is_array($heatingInsts)) {
            foreach ($heatingInsts as $heating) {
                $instId = $heating['InstanceID'];
                if ($instId > 0 && IPS_InstanceExists($instId)) {
                    foreach (IPS_GetChildrenIDs($instId) as $childId) {
                        $obj = IPS_GetObject($childId);
                        $ident = $obj['ObjectIdent'];
                        $name = $obj['ObjectName'];
                        if (strpos($name, 'Aktuelle Temperatur') !== false || strpos($name, 'Ventil-Ist-Temperatur') !== false || $ident === 'ACTUAL_TEMPERATURE'|| $ident === 'VALVE_ACTUAL_TEMPERATURE') {
                            $this->RegisterMessage($childId, VM_UPDATE);
                        }
                    }
                }
            }
        }

        $this->UpdateAverageTemperature();
        $this->updateHeatingMode();

        $this->SetStatus(102);
        $this->DA_SetAvailable(true);
    }
    
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($this->HandleCentralStateMessage($SenderID, $Message, $Data)) return;
        if ($Message == VM_UPDATE) {
            $this->UpdateAverageTemperature();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'HeatingSeason') {
            $this->SetValue($Ident, $Value);
        } elseif ($Ident === 'AlarmFrostWarning') {
            $this->SetValue($Ident, false);
        }
    }

    protected function OnCentralStateChanged(string $stateName, mixed $newValue): void
    {
        $this->updateHeatingMode();
    }

    private function updateHeatingMode(): void
    {
        $vacationEndTime = 0;
        $heatingInsts = $this->safeJsonDecode($this->ReadPropertyString('HeatingInstances'), true);
        if (!is_array($heatingInsts)) return;
        
        $roomCount = count($heatingInsts);

        $isVacation = $this->IsVacation();
        $isAbsence = ($this->IsAway() || $isVacation || $this->IsSleeping());
        
        if ($isAbsence || $isVacation) {
            $isHeatingSeason = GetValue($this->GetIDForIdent('HeatingSeason'));
            if (!$isHeatingSeason) {
                $this->SetValue('IsAbsenkbetrieb', false);
                $this->SetValue('HeatingStatus', '☀ Heizpause (Sommer) - Keine Absenkung');
                $this->SLogInfo( 'Sommerbetrieb aktiv.', 'Heizkörper werden nicht abgesenkt');
                return;
            }
            
            $wasAbsenkbetrieb = $this->GetValue('IsAbsenkbetrieb');
            $this->SetValue('IsAbsenkbetrieb', true);
            
            $globalTargetTemp = $this->ReadPropertyFloat('TargetTemperature');
            // Bei Urlaub noch weiter absenken (2 Grad kühler als normale Abwesenheit)
            if ($isVacation) {
                $globalTargetTemp = max(12.0, $globalTargetTemp - 2.0); 
            }
            
            $previousStatesStr = $this->ReadAttributeString('PreviousStates');
            $previousStates = $this->safeJsonDecode($previousStatesStr, true);
            if (!is_array($previousStates)) {
                $previousStates = [];
            }
            
            foreach ($heatingInsts as $heating) {
                $instId = $heating['InstanceID'];
                if ($instId <= 0 || !IPS_InstanceExists($instId)) continue;
                
                $individualTemp = isset($heating['TargetTemperature']) ? (float)$heating['TargetTemperature'] : $globalTargetTemp;
                if ($isVacation) {
                    $individualTemp = max(12.0, $individualTemp - 2.0);
                }

                $targetTempId = 0;
                $controlModeId = 0;

                // Variablen unterhalb der Instanz suchen
                foreach (IPS_GetChildrenIDs($instId) as $childId) {
                    $obj = IPS_GetObject($childId);
                    $ident = $obj['ObjectIdent'];
                    $name = $obj['ObjectName'];
                    
                    if (strpos($name, 'Sollwert Temperatur') !== false || $ident === 'SET_POINT_TEMPERATURE'|| $ident === 'POINT_TEMPERATURE') {
                        $targetTempId = $childId;
                    }
                    if (strpos($name, 'Kontrollmodus') !== false || strpos($name, 'Control Mode') !== false || $ident === 'CONTROL_MODE'|| $ident === 'SET_POINT_MODE') {
                        $controlModeId = $childId;
                    }
                }

                if (!$wasAbsenkbetrieb || !isset($previousStates[$instId])) {
                    $state = [
                        'tempId'=> $targetTempId,
                        'prevTemp'=> ($targetTempId > 0 && IPS_VariableExists($targetTempId)) ? GetValue($targetTempId) : null,
                        'modeId'=> $controlModeId,
                        'prevMode'=> ($controlModeId > 0 && IPS_VariableExists($controlModeId)) ? GetValue($controlModeId) : null
                    ];
                    $previousStates[$instId] = $state;
                }

                if ($controlModeId > 0 && IPS_VariableExists($controlModeId)) {
                    $currentMode = GetValue($controlModeId);
                    if (is_string($currentMode)) {
                        if (!$this->safeRequestAction($controlModeId, 'MANUAL')) {
                            $devName = @IPS_GetName($controlModeId) ?: "ID:$controlModeId";
                            $this->SLogWarning( "Aktor-Befehl fehlgeschlagen: $devName", "ID: $controlModeId | Wert: 'MANUAL'");
                        } else {
                            $this->SLogInfo( 'Aktor in MANU Modus versetzt.', "ID: $controlModeId | Wert: 'MANUAL'");
                        }
                    } else {
                        if (!$this->safeRequestAction($controlModeId, 1)) {
                            $this->SLogWarning( 'Aktor-Befehl fehlgeschlagen', "ID: $controlModeId | Wert: 1");
                        } else {
                            $this->SLogInfo( 'Aktor in MANU Modus versetzt.', "ID: $controlModeId | Wert: 1");
                        } // Meistens 1 = Manu
                    }
                }

                if ($targetTempId > 0 && IPS_VariableExists($targetTempId)) {
                    if (!$this->safeRequestAction($targetTempId, $individualTemp)) {
                        $this->SLogWarning( 'Aktor-Befehl fehlgeschlagen', "ID: $targetTempId | Wert: " . var_export($individualTemp, true));
                    } else {
                        $this->SLogInfo( 'Ziel-Temperatur gesetzt.', "ID: $targetTempId | Wert: " . var_export($individualTemp, true));
                    }
                }
            }
            $this->WriteAttributeString('PreviousStates', json_encode($previousStates));
            
            if ($isVacation) {
                $dateStr = ($vacationEndTime > 0) ? "bis ". date('d.m. H:i', $vacationEndTime) : "";
                $this->SetValue('HeatingStatus', '🧳 Urlaub aktiv'. $dateStr . '('. $roomCount . 'Räume tief abgesenkt)');
                $this->SLogInfo( 'Urlaubs-Absenktemperatur aktiviert.', "Ziel-Temp: $globalTargetTemp | Räume: $roomCount");
            } else {
                $this->SetValue('HeatingStatus', '🌙 Abwesenheit aktiv ('. $roomCount . 'Räume manuell abgesenkt)');
                $this->SLogInfo( 'Absenktemperatur aktiviert.', "Ziel-Temp: $globalTargetTemp | Räume: $roomCount");
            }
        } else {
            // Modus 0 (Anwesenheit), 3 (Party), 4 (Heimkino), 6 (Putzen) -> Heizung normal!
            $wasAbsenkbetrieb = $this->GetValue('IsAbsenkbetrieb');
            $this->SetValue('IsAbsenkbetrieb', false);
            $isHeatingSeason = GetValue($this->GetIDForIdent('HeatingSeason'));
            if (!$isHeatingSeason) {
                $this->SetValue('HeatingStatus', '☀ Heizpause (Sommer) - Inaktiv');
                $this->SLogInfo( 'Sommerbetrieb aktiv.', 'Keine Änderungen beim Statuswechsel.');
                return;
            }

            if ($wasAbsenkbetrieb) {
                $previousStatesStr = $this->ReadAttributeString('PreviousStates');
                $previousStates = $this->safeJsonDecode($previousStatesStr, true);
                if (is_array($previousStates)) {
                    foreach ($previousStates as $instId => $state) {
                        $modeId = isset($state['modeId']) ? $state['modeId'] : 0;
                        $prevMode = isset($state['prevMode']) ? $state['prevMode'] : null;
                        $tempId = isset($state['tempId']) ? $state['tempId'] : 0;
                        $prevTemp = isset($state['prevTemp']) ? $state['prevTemp'] : null;

                        if ($modeId > 0 && $prevMode !== null && IPS_VariableExists($modeId)) {
                            if (!$this->safeRequestAction($modeId, $prevMode)) {
                                $this->SLogWarning( 'Aktor-Befehl fehlgeschlagen', "ID: $modeId | Wert: " . var_export($prevMode, true));
                            } else {
                                $this->SLogInfo( 'Aktor-Modus wiederhergestellt.', "ID: $modeId | Wert: " . var_export($prevMode, true));
                            }
                        } elseif ($tempId > 0 && $prevTemp !== null && IPS_VariableExists($tempId)) {
                            if (!$this->safeRequestAction($tempId, $prevTemp)) {
                                $this->SLogWarning( 'Aktor-Befehl fehlgeschlagen', "ID: $tempId | Wert: " . var_export($prevTemp, true));
                            } else {
                                $this->SLogInfo( 'Ziel-Temperatur wiederhergestellt.', "ID: $tempId | Wert: " . var_export($prevTemp, true));
                            }
                        }
                    }
                }
                $this->WriteAttributeString('PreviousStates', '{}');
            }
            $this->SetValue('HeatingStatus', '🟢 Normalbetrieb (Profil gesteuert)');
            $this->SLogInfo( 'Normaltemperatur / Auto-Modus wiederhergestellt.', "Räume: $roomCount");
        }
        $this->UpdateAverageTemperature();
    }

    public function UpdateAverageTemperature(): void
    {
        $heatingInsts = $this->safeJsonDecode($this->ReadPropertyString('HeatingInstances'), true);
        if (!is_array($heatingInsts) || count($heatingInsts) == 0) return;

        $sumTemp = 0.0;
        $count = 0;

        foreach ($heatingInsts as $heating) {
            $instId = $heating['InstanceID'];
            if ($instId <= 0 || !IPS_InstanceExists($instId)) continue;

            $actualTemp = 0.0;
            $fallbackTemp = 0.0;

            foreach (IPS_GetChildrenIDs($instId) as $childId) {
                $obj = IPS_GetObject($childId);
                $ident = $obj['ObjectIdent'];
                $name = $obj['ObjectName'];

                if (strpos($name, 'Aktuelle Temperatur') !== false || $ident === 'ACTUAL_TEMPERATURE') {
                    $val = (float)GetValue($childId);
                    if ($val > 0) $actualTemp = $val;
                }
                if (strpos($name, 'Ventil-Ist-Temperatur') !== false || $ident === 'VALVE_ACTUAL_TEMPERATURE') {
                    $val = (float)GetValue($childId);
                    if ($val > 0) $fallbackTemp = $val;
                }
            }

            if ($actualTemp > 0) {
                $sumTemp += $actualTemp;
                $count++;
            } elseif ($fallbackTemp > 0) {
                $sumTemp += $fallbackTemp;
                $count++;
            }
        }

        if ($count > 0) {
            $avg = round($sumTemp / $count, 1);
            $this->SetValueIfChanged('AverageTemperature', $avg);
            
            $frostThreshold = $this->ReadPropertyFloat('FrostWarningThreshold');
            if ($avg < $frostThreshold) {
                if (!$this->GetValue('AlarmFrostWarning')) {
                    $this->SetValue('AlarmFrostWarning', true);
                    $this->SLogWarning( 'Frostgefahr erkannt!', "Ø-Temperatur: $avg °C");
                }
            } else {
                if ($this->GetValue('AlarmFrostWarning')) {
                    $this->SetValue('AlarmFrostWarning', false);
                }
            }
        }
    }
    
    private function SetValueIfChanged(string $Ident, $Value): void
    {
        $id = $this->GetIDForIdent($Ident);
        if (GetValue($id) !== $Value) {
            $this->SetValue($Ident, $Value);
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
            "caption": "⚙ Allgemeine Einstellungen",
            "items": [
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "NumberSpinner",
                            "name": "TargetTemperature",
                            "caption": "Absenktemperatur (°C)",
                            "digits": 1,
                            "minimum": 10,
                            "maximum": 25
                        },
                        {
                            "type": "NumberSpinner",
                            "name": "FrostWarningThreshold",
                            "caption": "Frostwarnung unter (°C)",
                            "digits": 1,
                            "minimum": 1,
                            "maximum": 15
                        }
                    ]
                }
            ]
        },
        {
            "type": "List",
            "name": "HeatingInstances",
            "caption": "Thermostat-Gruppen (HCU Instanzen)",
            "rowCount": 15,
            "add": true,
            "delete": true,
            "changeOrder": true,
            "columns": [
                {
                    "caption": "Thermostat-Instanz",
                    "name": "InstanceID",
                    "width": "300px",
                    "add": 0,
                    "edit": {
                        "type": "SelectInstance"
                    }
                },
                {
                    "caption": "Indiv. Absenktemp. (°C)",
                    "name": "TargetTemperature",
                    "width": "auto",
                    "add": 17,
                    "edit": {
                        "type": "NumberSpinner",
                        "digits": 1,
                        "minimum": 10,
                        "maximum": 25
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