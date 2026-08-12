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

        $this->RegisterPropertyInteger('RegistryID', 0);

        // Target temperature during absence (Fallback)
        $this->RegisterPropertyFloat('TargetTemperature', 17.0);
        $this->RegisterPropertyFloat('FrostWarningThreshold', 5.0);

        // JSON array of thermostat instances: [{"TargetID": "KEY", "TargetTemperature": 17.0}]
        $this->RegisterPropertyString('HeatingInstances', '[]');

        // Internal attribute to save previous states
        $this->RegisterAttributeString('PreviousStates', '{}');
        $this->RegisterAttributeString('DeviceMapCache', '{}');

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
        
        $regId = $this->ReadPropertyInteger('RegistryID');
        if ($regId > 1 && IPS_InstanceExists($regId)) {
            $this->RegisterReference($regId);
        }
        
        // Build Device Map Cache
        $deviceMap = [];
        if ($regId > 0 && IPS_InstanceExists($regId)) {
            if (method_exists($regId, 'GetDevicesByType')) {
                $devices = (array)@SDR_GetDevicesByType($regId, 'DevicesThermostat');
                foreach ($devices as $dev) {
                    if (empty($dev['id'])) continue;
                    $key = $dev['id'];
                    $deviceMap[$key] = [
                        'ActualTemp' => (int)($dev['ActualTemp_VarID'] ?? 0),
                        'TempSet'    => (int)($dev['TempSet_VarID'] ?? 0),
                        'ControlMode'=> (int)($dev['ControlMode_VarID'] ?? 0),
                        'BoostMode'  => (int)($dev['BoostMode_VarID'] ?? 0),
                        'Humidity'   => (int)($dev['Humidity_VarID'] ?? 0)
                    ];
                }
            }
        }
        $this->WriteAttributeString('DeviceMapCache', json_encode($deviceMap));
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
                $targetIdStr = $heating['TargetID'] ?? ($heating['InstanceID'] ?? '');
                $actualTempId = $this->resolveDeviceId((string)$targetIdStr, 'ActualTemp');
                
                if ($actualTempId > 0 && IPS_VariableExists($actualTempId)) {
                    $this->RegisterMessage($actualTempId, VM_UPDATE);
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
            $this->updateHeatingMode();
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
            if ($isVacation) {
                $globalTargetTemp = max(12.0, $globalTargetTemp - 2.0); 
            }
            
            $previousStatesStr = $this->ReadAttributeString('PreviousStates');
            $previousStates = $this->safeJsonDecode($previousStatesStr, true);
            if (!is_array($previousStates)) {
                $previousStates = [];
            }
            
            foreach ($heatingInsts as $heating) {
                $targetIdStr = (string)($heating['TargetID'] ?? ($heating['InstanceID'] ?? ''));
                if ($targetIdStr === '') continue;
                
                $individualTemp = isset($heating['TargetTemperature']) ? (float)$heating['TargetTemperature'] : $globalTargetTemp;
                if ($isVacation) {
                    $individualTemp = max(12.0, $individualTemp - 2.0);
                }

                $targetTempId = $this->resolveDeviceId($targetIdStr, 'TempSet');
                $controlModeId = $this->resolveDeviceId($targetIdStr, 'ControlMode');

                if (!$wasAbsenkbetrieb || !isset($previousStates[$targetIdStr])) {
                    $state = [
                        'tempId'=> $targetTempId,
                        'prevTemp'=> ($targetTempId > 0 && IPS_VariableExists($targetTempId)) ? GetValue($targetTempId) : null,
                        'modeId'=> $controlModeId,
                        'prevMode'=> ($controlModeId > 0 && IPS_VariableExists($controlModeId)) ? GetValue($controlModeId) : null
                    ];
                    $previousStates[$targetIdStr] = $state;
                }

                if ($controlModeId > 0 && IPS_VariableExists($controlModeId)) {
                    $currentMode = GetValue($controlModeId);
                    $manuValue = is_string($currentMode) ? 'MANUAL' : 1;
                    
                    if (!$this->safeRequestAction($controlModeId, $manuValue)) {
                        $devName = @IPS_GetName($controlModeId) ?: "ID:$controlModeId";
                        $this->SLogWarning( "Aktor-Befehl fehlgeschlagen: $devName", "ID: $controlModeId | Wert: " . var_export($manuValue, true));
                    } else {
                        $this->SLogInfo( 'Aktor in MANU Modus versetzt.', "ID: $controlModeId | Wert: " . var_export($manuValue, true));
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
                    foreach ($previousStates as $targetIdStr => $state) {
                        $modeId = isset($state['modeId']) ? $state['modeId'] : 0;
                        $prevMode = isset($state['prevMode']) ? $state['prevMode'] : null;
                        $tempId = isset($state['tempId']) ? $state['tempId'] : 0;
                        $prevTemp = isset($state['prevTemp']) ? $state['prevTemp'] : null;

                        // Im Normalbetrieb schalten wir zusätzlich explizit in den Auto Modus,
                        // falls $prevMode unbekannt war.
                        $autoValue = 'AUTOMATIC';
                        
                        if ($modeId > 0 && IPS_VariableExists($modeId)) {
                            $currentMode = GetValue($modeId);
                            if (!is_string($currentMode)) {
                                $autoValue = 0; // 0 is often AUTO in integer profiles
                            }
                            $targetMode = ($prevMode !== null) ? $prevMode : $autoValue;
                            
                            if (!$this->safeRequestAction($modeId, $targetMode)) {
                                $this->SLogWarning( 'Aktor-Befehl fehlgeschlagen', "ID: $modeId | Wert: " . var_export($targetMode, true));
                            } else {
                                $this->SLogInfo( 'Aktor-Modus wiederhergestellt.', "ID: $modeId | Wert: " . var_export($targetMode, true));
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
            $targetIdStr = (string)($heating['TargetID'] ?? ($heating['InstanceID'] ?? ''));
            if ($targetIdStr === '') continue;

            $actualTempId = $this->resolveDeviceId($targetIdStr, 'ActualTemp');
            
            if ($actualTempId > 0 && IPS_VariableExists($actualTempId)) {
                $val = (float)GetValue($actualTempId);
                if ($val > 0) {
                    $sumTemp += $val;
                    $count++;
                }
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

    private function resolveDeviceId(string|int $idStr, string $field = 'TempSet'): int
    {
        if (is_numeric($idStr)) {
            // Fallback für alte Konfigurationen ohne Registry (wo InstanceID direkt verwendet wird)
            // Dies ist ein Kompatibilitätsmodus, auch wenn wir die Kinder jetzt nicht mehr iterieren.
            // Die Logik funktioniert am besten, wenn auf TargetID (DeviceKey) umgestellt wird.
            return 0; 
        }

        $cache = $this->safeJsonDecode($this->ReadAttributeString('DeviceMapCache'), true);
        if (isset($cache[$idStr]) && isset($cache[$idStr][$field])) {
            return (int)$cache[$idStr][$field];
        }
        return 0;
    }
    
    private function resolveDeviceValue(string $idStr, string $field, mixed $default): mixed
    {
        $cache = $this->safeJsonDecode($this->ReadAttributeString('DeviceMapCache'), true);
        if (isset($cache[$idStr]) && isset($cache[$idStr][$field])) {
            return $cache[$idStr][$field];
        }
        return $default;
    }

    public function GetConfigurationForm(): string
    {
        $regId = $this->ReadPropertyInteger('RegistryID');
        $thermostatOptions = $this->getRegistryThermostatOptions($regId);

        $form = [
            "elements" => [
                [
                    "type" => "SelectInstance",
                    "name" => "RegistryID",
                    "caption" => "Device Registry"
                ],
                [
                    "type" => "CheckBox",
                    "name" => "SimulationMode",
                    "caption" => "Simulationsmodus (Testbetrieb)"
                ],
                [
                    "type" => "Label",
                    "caption" => " "
                ],
                [
                    "type" => "ExpansionPanel",
                    "caption" => "⚙ Allgemeine Einstellungen",
                    "items" => [
                        [
                            "type" => "RowLayout",
                            "items" => [
                                [
                                    "type" => "NumberSpinner",
                                    "name" => "TargetTemperature",
                                    "caption" => "Absenktemperatur (°C)",
                                    "digits" => 1,
                                    "minimum" => 10,
                                    "maximum" => 25
                                ],
                                [
                                    "type" => "NumberSpinner",
                                    "name" => "FrostWarningThreshold",
                                    "caption" => "Frostwarnung unter (°C)",
                                    "digits" => 1,
                                    "minimum" => 1,
                                    "maximum" => 15
                                ]
                            ]
                        ]
                    ]
                ],
                [
                    "type" => "List",
                    "name" => "HeatingInstances",
                    "caption" => "Thermostat-Gruppen (aus Registry)",
                    "rowCount" => 15,
                    "add" => true,
                    "delete" => true,
                    "changeOrder" => true,
                    "columns" => [
                        [
                            "caption" => "Thermostat",
                            "name" => "TargetID",
                            "width" => "300px",
                            "add" => "",
                            "edit" => [
                                "type" => "Select",
                                "options" => $thermostatOptions
                            ]
                        ],
                        [
                            "caption" => "Indiv. Absenktemp. (°C)",
                            "name" => "TargetTemperature",
                            "width" => "auto",
                            "add" => 17,
                            "edit" => [
                                "type" => "NumberSpinner",
                                "digits" => 1,
                                "minimum" => 10,
                                "maximum" => 25
                            ]
                        ]
                    ]
                ]
            ]
        ];
        
        return json_encode($form);
    }
    
    private function getRegistryThermostatOptions(int $regId): array
    {
        $options = [['caption' => '(Nicht zugewiesen)', 'value' => '']];
        if ($regId > 0 && IPS_InstanceExists($regId)) {
            if (method_exists($regId, 'GetDevicesByType')) {
                $devices = (array)@SDR_GetDevicesByType($regId, 'DevicesThermostat');
                
                $dynamicOptions = [];
                foreach ($devices as $dev) {
                    if (empty($dev['id'])) continue;
                    $room = !empty($dev['room']) ? $dev['room'] : 'Unbekannt';
                    $name = !empty($dev['name']) ? $dev['name'] : 'Unbenannt';
                    $caption = "$room: $name";
                    $dynamicOptions[] = ['caption' => $caption, 'value' => $dev['id']];
                }
                
                usort($dynamicOptions, function ($a, $b) {
                    return strnatcasecmp($a['caption'], $b['caption']);
                });
                
                $options = array_merge($options, $dynamicOptions);
            }
        }
        return $options;
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
