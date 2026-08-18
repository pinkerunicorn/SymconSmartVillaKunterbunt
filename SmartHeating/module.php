<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_HardwareControl.php';
require_once __DIR__ . '/../libs/Trait_CentralStateAware.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';
require_once __DIR__ . '/../libs/Trait_RegistryAware.php';
require_once __DIR__ . '/../libs/Trait_DeviceRegistration.php';

class SmartHeating extends IPSModuleStrict
{
    use SmartLog_Trait;
    use HardwareControl_Trait;
    use CentralStateAware_Trait;
    use DeviceAvailability_Trait;
    use RegistryAware_Trait;
    use DeviceRegistration_Trait;

    public function Create(): void
    {
        parent::Create();
        
        $this->DA_RegisterAvailability(900);

        $this->RegisterPropertyInteger('RegistryID', 0);
        $this->RegisterPropertyBoolean('SimulationMode', false);

        // Target temperature during absence (Fallback)
        $this->RegisterPropertyFloat('TargetTemperature', 17.0);
        $this->RegisterPropertyFloat('FrostWarningThreshold', 5.0);

        // JSON array of thermostat instances: [{"TargetID": "KEY", "TargetTemperature": 17.0}]
        $this->RegisterPropertyString('HeatingInstances', '[]');

        // Fireplace
        $this->RegisterPropertyInteger('FireplaceSafetyID', 0);
        $this->RegisterPropertyString('FireplaceRoom', '');

        // Outdoor temperature & Season
        $this->RegisterPropertyInteger('OutdoorTempVarID', 0);
        $this->RegisterPropertyFloat('SeasonThresholdSummer', 18.0);
        $this->RegisterPropertyFloat('SeasonThresholdWinter', 15.0);
        $this->RegisterPropertyInteger('SeasonAvgDays', 3);

        // Window
        $this->RegisterPropertyInteger('WindowDebounceSeconds', 120);
        $this->RegisterPropertyFloat('WindowFrostTemp', 12.0);

        // Boost
        $this->RegisterPropertyInteger('BoostDurationMinutes', 30);
        $this->RegisterPropertyFloat('BoostTemperature', 24.0);

        // EMSESP / Boiler
        $this->RegisterPropertyInteger('EMSESPID', 0);

        // Internal attributes
        $this->RegisterAttributeString('PreviousStates', '{}');
        $this->RegisterAttributeString('DeviceMapCache', '{}');
        $this->RegisterAttributeString('WindowPrevStates', '{}');
        $this->RegisterAttributeString('FireplacePrevState', '{}');
        $this->RegisterAttributeString('BoostPrevStates', '{}');
        $this->RegisterAttributeString('OutdoorTempHistory', '[]');
        $this->RegisterAttributeString('ContactSensorCache', '{}');
        $this->RegisterAttributeString('MessageSourceMap', '{}');
        $this->RegisterAttributeString('WindowDebounceStates', '{}'); // To track debouncing windows
        $this->RegisterAttributeString('BoostedRooms', '{}');

        // GUI Variables
        $this->RegisterVariableString('HeatingStatus', 'Status', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'        => 'info'
        ], 1);
        
        $this->RegisterVariableFloat('AverageTemperature', 'Durchschnitt Haus-Temperatur', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'        => 'temperature-half',
            'SUFFIX'      => '°C'
        ], 2);
        
        $this->RegisterVariableFloat('OutdoorTempAvg', 'Durchschnitt Aussen-Temperatur', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'        => 'temperature-half',
            'SUFFIX'      => '°C'
        ], 3);

        $this->RegisterVariableString('ActiveOverrides', 'Aktive Ueberschreibungen', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'        => 'circle-info'
        ], 4);
        
        $this->RegisterVariableFloat('BoilerFlowTemp', 'Kessel Vorlauftemperatur', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'        => 'temperature-half',
            'SUFFIX'      => '°C'
        ], 5);
        
        $this->RegisterVariableFloat('BoilerReturnTemp', 'Kessel Ruecklauftemperatur', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'        => 'temperature-half',
            'SUFFIX'      => '°C'
        ], 6);
        
        $this->RegisterVariableBoolean('BoilerActive', 'Brenner aktiv', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'        => 'fire-flame-curved'
        ], 7);

        $this->RegisterVariableBoolean('HeatingSeason', 'Heizperiode aktiv', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'snowflake'
        ], 10);
        $this->EnableAction('HeatingSeason');
        
        $this->RegisterVariableBoolean('IsAbsenkbetrieb', 'Absenkbetrieb', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'temperature-arrow-down',
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
        
        $this->RegisterVariableBoolean('AlarmFrostWarning', 'Alarm Frostgefahr', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'icicles'
        ], 20);
        $this->EnableAction('AlarmFrostWarning');
        
        $this->RegisterVariableBoolean('BoostActive', 'Global Boost', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'fire'
        ], 25);
        $this->EnableAction('BoostActive');

        // Timers
        $this->RegisterTimer('SeasonCheckTimer', 0, 'SHH_CheckSeason($_IPS["TARGET"]);');
        $this->RegisterTimer('WindowCheckTimer', 0, 'SHH_CheckWindowDebounce($_IPS["TARGET"]);');
        $this->RegisterTimer('BoostEndTimer', 0, 'SHH_EndBoost($_IPS["TARGET"]);');
        
        $this->DR_Register();
    }

    public function Destroy(): void
    {
        $this->DR_Unregister();
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        $this->SubscribeToCentralStates(['PresenceMode', 'ActivityMode']);
        
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        
        $regId = $this->ReadPropertyInteger('RegistryID');
        if ($regId > 1 && IPS_InstanceExists($regId)) {
            $this->RegisterReference($regId);
        }
        
        // Build Device Map Cache and Contact Sensor Cache
        $deviceMap = [];
        $contactMap = [];
        if ($regId > 0 && IPS_InstanceExists($regId)) {
            if (function_exists('SDR_GetDevicesByType')) {
                $devices = (array)@SDR_GetDevicesByType($regId, 'DevicesThermostat');
                foreach ($devices as $dev) {
                    if (empty($dev['id'])) continue;
                    $key = $dev['id'];
                    $deviceMap[$key] = [
                        'ActualTemp' => (int)($dev['ActualTemp_VarID'] ?? 0),
                        'TempSet'    => (int)($dev['TempSet_VarID'] ?? 0),
                        'ControlMode'=> (int)($dev['ControlMode_VarID'] ?? 0),
                        'BoostMode'  => (int)($dev['BoostMode_VarID'] ?? 0),
                        'Humidity'   => (int)($dev['Humidity_VarID'] ?? 0),
                        'Room'       => $dev['room'] ?? ''
                    ];
                }
                
                $contacts = (array)@SDR_GetDevicesByType($regId, 'DevicesContactSensor');
                foreach ($contacts as $c) {
                    $room = $c['room'] ?? '';
                    $varId = (int)($c['Status_VarID'] ?? 0);
                    $closedVal = $c['ClosedValue'] ?? 'CLOSED';
                    if ($room !== '' && $varId > 0) {
                        $contactMap[$room][] = ['varId' => $varId, 'closedValue' => $closedVal];
                    }
                }
            }
        }
        $this->WriteAttributeString('DeviceMapCache', json_encode($deviceMap));
        $this->WriteAttributeString('ContactSensorCache', json_encode($contactMap));

        // Variable Aggregation (Logging)
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

        $messageMap = [];

        // Register messages for actual temperatures
        $heatingInsts = $this->safeJsonDecode($this->ReadPropertyString('HeatingInstances'), true);
        if (is_array($heatingInsts)) {
            foreach ($heatingInsts as $heating) {
                $targetIdStr = (string)($heating['TargetID'] ?? ($heating['InstanceID'] ?? ''));
                $actualTempId = $this->resolveDeviceId($targetIdStr, 'ActualTemp');
                
                if ($actualTempId > 0 && IPS_VariableExists($actualTempId)) {
                    $this->RegisterMessage($actualTempId, VM_UPDATE);
                    $messageMap[$actualTempId] = 'actualTemp';
                }
            }
        }

        // Register messages for Contact Sensors
        foreach ($contactMap as $room => $sensors) {
            foreach ($sensors as $sensor) {
                $varId = (int)$sensor['varId'];
                if ($varId > 0 && IPS_VariableExists($varId)) {
                    $this->RegisterMessage($varId, VM_UPDATE);
                    $messageMap[$varId] = 'contactSensor';
                }
            }
        }

        // Register message for OvenStatus
        $fsId = $this->ReadPropertyInteger('FireplaceSafetyID');
        if ($fsId > 0 && IPS_InstanceExists($fsId)) {
            $ovenStatusId = @IPS_GetObjectIDByIdent('OvenStatus', $fsId);
            if ($ovenStatusId !== false) {
                $this->RegisterMessage($ovenStatusId, VM_UPDATE);
                $messageMap[$ovenStatusId] = 'ovenStatus';
            }
        }
        
        // Register messages for EMSESP
        $emsId = $this->ReadPropertyInteger('EMSESPID');
        if ($emsId > 0 && IPS_InstanceExists($emsId)) {
            $flowId = @IPS_GetObjectIDByIdent('thermostat_curflowtemp', $emsId);
            $retId = @IPS_GetObjectIDByIdent('thermostat_rettemp', $emsId);
            $burnId = @IPS_GetObjectIDByIdent('boiler_heatingactive', $emsId);
            
            if ($flowId !== false) {
                $this->RegisterMessage($flowId, VM_UPDATE);
                $messageMap[$flowId] = 'emsesp_flow';
            }
            if ($retId !== false) {
                $this->RegisterMessage($retId, VM_UPDATE);
                $messageMap[$retId] = 'emsesp_return';
            }
            if ($burnId !== false) {
                $this->RegisterMessage($burnId, VM_UPDATE);
                $messageMap[$burnId] = 'emsesp_burner';
            }
        }

        $this->WriteAttributeString('MessageSourceMap', json_encode($messageMap));

        // Start Season Timer (every 6 hours)
        $this->SetTimerInterval('SeasonCheckTimer', 6 * 60 * 60 * 1000);

        $this->UpdateAverageTemperature();
        $this->updateHeatingMode();
        $this->UpdateActiveOverridesText();
        
        // Mirror current EMSESP values if possible
        if ($emsId > 0 && IPS_InstanceExists($emsId)) {
            $flowId = @IPS_GetObjectIDByIdent('thermostat_curflowtemp', $emsId);
            $retId = @IPS_GetObjectIDByIdent('thermostat_rettemp', $emsId);
            $burnId = @IPS_GetObjectIDByIdent('boiler_heatingactive', $emsId);
            if ($flowId !== false && IPS_VariableExists($flowId)) $this->SetValueIfChanged('BoilerFlowTemp', (float)GetValue($flowId));
            if ($retId !== false && IPS_VariableExists($retId)) $this->SetValueIfChanged('BoilerReturnTemp', (float)GetValue($retId));
            if ($burnId !== false && IPS_VariableExists($burnId)) $this->SetValueIfChanged('BoilerActive', (bool)GetValue($burnId));
        }

        $this->SetStatus(102);
        $this->DA_SetAvailable(true);
    }
    
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($this->HandleCentralStateMessage($SenderID, $Message, $Data)) return;
        if ($Message == VM_UPDATE) {
            $mapStr = $this->ReadAttributeString('MessageSourceMap');
            $messageMap = $this->safeJsonDecode($mapStr, true);
            
            if (isset($messageMap[$SenderID])) {
                $type = $messageMap[$SenderID];
                switch ($type) {
                    case 'actualTemp':
                        $this->UpdateAverageTemperature();
                        break;
                    case 'contactSensor':
                        $this->handleWindowEvent($SenderID);
                        break;
                    case 'ovenStatus':
                        $this->handleFireplaceEvent();
                        break;
                    case 'emsesp_flow':
                        $this->SetValueIfChanged('BoilerFlowTemp', (float)$Data[0]);
                        break;
                    case 'emsesp_return':
                        $this->SetValueIfChanged('BoilerReturnTemp', (float)$Data[0]);
                        break;
                    case 'emsesp_burner':
                        $this->SetValueIfChanged('BoilerActive', (bool)$Data[0]);
                        break;
                }
            }
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'HeatingSeason') {
            $this->SetValue($Ident, $Value);
            $this->updateHeatingMode();
        } elseif ($Ident === 'AlarmFrostWarning') {
            $this->SetValue($Ident, false);
        } elseif ($Ident === 'BoostActive') {
            $this->SetValue($Ident, $Value);
            if ($Value) {
                // Boost all
                $heatingInsts = $this->safeJsonDecode($this->ReadPropertyString('HeatingInstances'), true);
                if (is_array($heatingInsts)) {
                    foreach ($heatingInsts as $heating) {
                        $targetIdStr = (string)($heating['TargetID'] ?? ($heating['InstanceID'] ?? ''));
                        if ($targetIdStr !== '') {
                            $this->BoostRoom($targetIdStr);
                        }
                    }
                }
            } else {
                $this->EndBoost();
            }
        }
    }

    protected function OnCentralStateChanged(string $stateName, mixed $newValue): void
    {
        $this->updateHeatingMode();
        
        // On Vacation: Set EMSESP mode to 'eco'
        if ($stateName === 'PresenceMode' && $this->IsVacation()) {
            $emsId = $this->ReadPropertyInteger('EMSESPID');
            if ($emsId > 0 && IPS_InstanceExists($emsId)) {
                $modeId = @IPS_GetObjectIDByIdent('thermostat_mode', $emsId);
                if ($modeId !== false && IPS_VariableExists($modeId)) {
                    @RequestAction($modeId, 0); // Assuming 0 is eco, adjust as needed. Standard EMS-ESP mode mapping: 0=auto, 1=day, 2=night/eco
                    $this->SLogInfo('Kessel-Modus', 'Urlaub erkannt, Kessel-Modus aktualisiert.');
                }
            }
        }
    }

    public function CheckSeason(): void
    {
        $outdoorVarId = $this->ReadPropertyInteger('OutdoorTempVarID');
        if ($outdoorVarId <= 0 || !IPS_VariableExists($outdoorVarId)) return;
        
        $days = $this->ReadPropertyInteger('SeasonAvgDays');
        if ($days <= 0) $days = 3;
        
        $archiveIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($archiveIDs) == 0) return;
        
        $archiveID = $archiveIDs[0];
        $values = AC_GetAggregatedValues($archiveID, $outdoorVarId, 1 /* daily */, strtotime('-' . $days . ' days'), time(), 0);
        
        if (empty($values)) return;
        
        $sum = 0;
        $count = 0;
        foreach ($values as $val) {
            $sum += $val['Avg'];
            $count++;
        }
        $avg = $sum / $count;
        $this->SetValueIfChanged('OutdoorTempAvg', $avg);
        
        $summerThresh = $this->ReadPropertyFloat('SeasonThresholdSummer');
        $winterThresh = $this->ReadPropertyFloat('SeasonThresholdWinter');
        
        $isHeatingSeason = $this->GetValue('HeatingSeason');
        
        if ($isHeatingSeason && $avg > $summerThresh) {
            $this->SetValue('HeatingSeason', false);
            $this->updateHeatingMode();
            $this->SLogInfo('Saison-Automatik', "Aussentemperatur Ø {$avg}°C > {$summerThresh}°C -> Sommerbetrieb aktiviert.");
        } elseif (!$isHeatingSeason && $avg < $winterThresh) {
            $this->SetValue('HeatingSeason', true);
            $this->updateHeatingMode();
            $this->SLogInfo('Saison-Automatik', "Aussentemperatur Ø {$avg}°C < {$winterThresh}°C -> Heizperiode aktiviert.");
        }
    }

    public function CheckWindowDebounce(): void
    {
        $debounces = $this->safeJsonDecode($this->ReadAttributeString('WindowDebounceStates'), true);
        if (empty($debounces)) {
            $this->SetTimerInterval('WindowCheckTimer', 0);
            return;
        }

        $now = time();
        $changed = false;
        
        foreach ($debounces as $room => $expiryTime) {
            if ($now >= $expiryTime) {
                // Timer expired -> apply window open override
                $this->applyWindowOpen($room);
                unset($debounces[$room]);
                $changed = true;
            }
        }
        
        if ($changed) {
            $this->WriteAttributeString('WindowDebounceStates', json_encode($debounces));
        }
        
        if (empty($debounces)) {
            $this->SetTimerInterval('WindowCheckTimer', 0);
        }
    }

    private function handleWindowEvent(int $sensorId): void
    {
        $contactMap = $this->safeJsonDecode($this->ReadAttributeString('ContactSensorCache'), true);
        $room = '';
        $closedVal = 'CLOSED';
        
        foreach ($contactMap as $r => $sensors) {
            foreach ($sensors as $sensor) {
                if ($sensor['varId'] == $sensorId) {
                    $room = $r;
                    $closedVal = $sensor['closedValue'];
                    break 2;
                }
            }
        }
        
        if ($room === '') return;
        
        // Check if ANY window in the room is open
        $anyOpen = false;
        foreach ($contactMap[$room] as $sensor) {
            $vId = $sensor['varId'];
            if (IPS_VariableExists($vId)) {
                $val = GetValue($vId);
                if ((string)$val !== $sensor['closedValue']) {
                    $anyOpen = true;
                    break;
                }
            }
        }
        
        $debounces = $this->safeJsonDecode($this->ReadAttributeString('WindowDebounceStates'), true);
        
        if ($anyOpen) {
            // Window opened -> start debounce
            $debounceSecs = $this->ReadPropertyInteger('WindowDebounceSeconds');
            $debounces[$room] = time() + $debounceSecs;
            $this->WriteAttributeString('WindowDebounceStates', json_encode($debounces));
            $this->SetTimerInterval('WindowCheckTimer', 5000);
            $this->SendDebug('Fenster Status', "Fenster im Raum {$room} geoeffnet. Entprellung gestartet ({$debounceSecs}s).", 0);
        } else {
            // All windows closed -> cancel debounce if active, or restore if already applied
            if (isset($debounces[$room])) {
                unset($debounces[$room]);
                $this->WriteAttributeString('WindowDebounceStates', json_encode($debounces));
                $this->SendDebug('Fenster Status', "Fenster im Raum {$room} wieder geschlossen vor Ablauf der Entprellung.", 0);
            } else {
                $this->restoreWindowClose($room);
            }
        }
    }

    private function applyWindowOpen(string $room): void
    {
        $frostTemp = $this->ReadPropertyFloat('WindowFrostTemp');
        $deviceMap = $this->safeJsonDecode($this->ReadAttributeString('DeviceMapCache'), true);
        $windowPrevStates = $this->safeJsonDecode($this->ReadAttributeString('WindowPrevStates'), true);
        
        $thermostatIdStr = $this->findThermostatIdByRoom($room);
        if ($thermostatIdStr !== '') {
            $targetTempId = $this->resolveDeviceId($thermostatIdStr, 'TempSet');
            $controlModeId = $this->resolveDeviceId($thermostatIdStr, 'ControlMode');
            
            if (!isset($windowPrevStates[$thermostatIdStr])) {
                $windowPrevStates[$thermostatIdStr] = [
                    'tempId' => $targetTempId,
                    'prevTemp' => ($targetTempId > 0 && IPS_VariableExists($targetTempId)) ? GetValue($targetTempId) : null,
                    'modeId' => $controlModeId,
                    'prevMode' => ($controlModeId > 0 && IPS_VariableExists($controlModeId)) ? GetValue($controlModeId) : null
                ];
                $this->WriteAttributeString('WindowPrevStates', json_encode($windowPrevStates));
            }
            
            if ($controlModeId > 0 && IPS_VariableExists($controlModeId)) {
                $currentMode = GetValue($controlModeId);
                $manuValue = is_string($currentMode) ? 'MANUAL' : 1;
                $this->safeRequestAction($controlModeId, $manuValue);
            }
            if ($targetTempId > 0 && IPS_VariableExists($targetTempId)) {
                $this->safeRequestAction($targetTempId, $frostTemp);
            }
            
            $this->SLogInfo('Fenster-Override', "Fenster im Raum {$room} offen -> Frostschutz {$frostTemp}°C aktiviert.");
            $this->UpdateActiveOverridesText();
        }
    }

    private function restoreWindowClose(string $room): void
    {
        $windowPrevStates = $this->safeJsonDecode($this->ReadAttributeString('WindowPrevStates'), true);
        $thermostatIdStr = $this->findThermostatIdByRoom($room);
        
        if ($thermostatIdStr !== '' && isset($windowPrevStates[$thermostatIdStr])) {
            $state = $windowPrevStates[$thermostatIdStr];
            $modeId = $state['modeId'] ?? 0;
            $prevMode = $state['prevMode'] ?? null;
            $tempId = $state['tempId'] ?? 0;
            $prevTemp = $state['prevTemp'] ?? null;
            
            if ($modeId > 0 && $prevMode !== null && IPS_VariableExists($modeId)) {
                $this->safeRequestAction($modeId, $prevMode);
            }
            if ($tempId > 0 && $prevTemp !== null && IPS_VariableExists($tempId)) {
                $this->safeRequestAction($tempId, $prevTemp);
            }
            
            unset($windowPrevStates[$thermostatIdStr]);
            $this->WriteAttributeString('WindowPrevStates', json_encode($windowPrevStates));
            $this->SLogInfo('Fenster-Override', "Fenster im Raum {$room} geschlossen -> Vorherigen Zustand wiederhergestellt.");
            
            $this->UpdateActiveOverridesText();
            // Re-apply global mode just in case
            $this->updateHeatingMode();
        }
    }

    private function handleFireplaceEvent(): void
    {
        $fsId = $this->ReadPropertyInteger('FireplaceSafetyID');
        if ($fsId <= 0 || !IPS_InstanceExists($fsId)) return;
        
        $ovenStatusId = @IPS_GetObjectIDByIdent('OvenStatus', $fsId);
        if ($ovenStatusId === false || !IPS_VariableExists($ovenStatusId)) return;
        
        $isOn = (bool)GetValue($ovenStatusId);
        $room = $this->ReadPropertyString('FireplaceRoom');
        if ($room === '') return;
        
        $thermostatIdStr = $this->findThermostatIdByRoom($room);
        if ($thermostatIdStr === '') return;
        
        $fireplacePrevState = $this->safeJsonDecode($this->ReadAttributeString('FireplacePrevState'), true);
        
        if ($isOn) {
            // Check if not already overridden
            if (!isset($fireplacePrevState[$thermostatIdStr])) {
                $targetTempId = $this->resolveDeviceId($thermostatIdStr, 'TempSet');
                $controlModeId = $this->resolveDeviceId($thermostatIdStr, 'ControlMode');
                
                $fireplacePrevState[$thermostatIdStr] = [
                    'tempId' => $targetTempId,
                    'prevTemp' => ($targetTempId > 0 && IPS_VariableExists($targetTempId)) ? GetValue($targetTempId) : null,
                    'modeId' => $controlModeId,
                    'prevMode' => ($controlModeId > 0 && IPS_VariableExists($controlModeId)) ? GetValue($controlModeId) : null
                ];
                $this->WriteAttributeString('FireplacePrevState', json_encode($fireplacePrevState));
                
                $ecoTemp = $this->ReadPropertyFloat('TargetTemperature');
                if ($controlModeId > 0 && IPS_VariableExists($controlModeId)) {
                    $currentMode = GetValue($controlModeId);
                    $manuValue = is_string($currentMode) ? 'MANUAL' : 1;
                    $this->safeRequestAction($controlModeId, $manuValue);
                }
                if ($targetTempId > 0 && IPS_VariableExists($targetTempId)) {
                    $this->safeRequestAction($targetTempId, $ecoTemp);
                }
                
                $this->SLogInfo('Kamin-Override', "Kamin im Raum {$room} an -> Eco-Temperatur {$ecoTemp}°C aktiviert.");
            }
        } else {
            // Restore
            if (isset($fireplacePrevState[$thermostatIdStr])) {
                $state = $fireplacePrevState[$thermostatIdStr];
                $modeId = $state['modeId'] ?? 0;
                $prevMode = $state['prevMode'] ?? null;
                $tempId = $state['tempId'] ?? 0;
                $prevTemp = $state['prevTemp'] ?? null;
                
                if ($modeId > 0 && $prevMode !== null && IPS_VariableExists($modeId)) {
                    $this->safeRequestAction($modeId, $prevMode);
                }
                if ($tempId > 0 && $prevTemp !== null && IPS_VariableExists($tempId)) {
                    $this->safeRequestAction($tempId, $prevTemp);
                }
                
                unset($fireplacePrevState[$thermostatIdStr]);
                $this->WriteAttributeString('FireplacePrevState', json_encode($fireplacePrevState));
                $this->SLogInfo('Kamin-Override', "Kamin im Raum {$room} aus -> Vorherigen Zustand wiederhergestellt.");
                
                $this->updateHeatingMode();
            }
        }
        $this->UpdateActiveOverridesText();
    }

    public function BoostRoom(string $thermostatId): void
    {
        $boostTemp = $this->ReadPropertyFloat('BoostTemperature');
        $boostDuration = $this->ReadPropertyInteger('BoostDurationMinutes');
        
        $boostPrevStates = $this->safeJsonDecode($this->ReadAttributeString('BoostPrevStates'), true);
        $boostedRooms = $this->safeJsonDecode($this->ReadAttributeString('BoostedRooms'), true);
        
        $targetTempId = $this->resolveDeviceId($thermostatId, 'TempSet');
        $controlModeId = $this->resolveDeviceId($thermostatId, 'ControlMode');
        
        if (!isset($boostPrevStates[$thermostatId])) {
            $boostPrevStates[$thermostatId] = [
                'tempId' => $targetTempId,
                'prevTemp' => ($targetTempId > 0 && IPS_VariableExists($targetTempId)) ? GetValue($targetTempId) : null,
                'modeId' => $controlModeId,
                'prevMode' => ($controlModeId > 0 && IPS_VariableExists($controlModeId)) ? GetValue($controlModeId) : null
            ];
            $this->WriteAttributeString('BoostPrevStates', json_encode($boostPrevStates));
        }
        
        if ($controlModeId > 0 && IPS_VariableExists($controlModeId)) {
            $currentMode = GetValue($controlModeId);
            $manuValue = is_string($currentMode) ? 'MANUAL' : 1;
            $this->safeRequestAction($controlModeId, $manuValue);
        }
        if ($targetTempId > 0 && IPS_VariableExists($targetTempId)) {
            $this->safeRequestAction($targetTempId, $boostTemp);
        }
        
        $boostedRooms[$thermostatId] = time() + ($boostDuration * 60);
        $this->WriteAttributeString('BoostedRooms', json_encode($boostedRooms));
        
        $this->SetTimerInterval('BoostEndTimer', $boostDuration * 60 * 1000);
        $this->SetValueIfChanged('BoostActive', true);
        
        $this->SLogInfo('Boost-Modus', "Boost für Thermostat {$thermostatId} gestartet ({$boostTemp}°C für {$boostDuration} Min).");
        $this->UpdateActiveOverridesText();
    }

    public function CancelBoost(string $thermostatId): void
    {
        $boostPrevStates = $this->safeJsonDecode($this->ReadAttributeString('BoostPrevStates'), true);
        $boostedRooms = $this->safeJsonDecode($this->ReadAttributeString('BoostedRooms'), true);
        
        if (isset($boostPrevStates[$thermostatId])) {
            $state = $boostPrevStates[$thermostatId];
            $modeId = $state['modeId'] ?? 0;
            $prevMode = $state['prevMode'] ?? null;
            $tempId = $state['tempId'] ?? 0;
            $prevTemp = $state['prevTemp'] ?? null;
            
            if ($modeId > 0 && $prevMode !== null && IPS_VariableExists($modeId)) {
                $this->safeRequestAction($modeId, $prevMode);
            }
            if ($tempId > 0 && $prevTemp !== null && IPS_VariableExists($tempId)) {
                $this->safeRequestAction($tempId, $prevTemp);
            }
            
            unset($boostPrevStates[$thermostatId]);
            $this->WriteAttributeString('BoostPrevStates', json_encode($boostPrevStates));
            
            unset($boostedRooms[$thermostatId]);
            $this->WriteAttributeString('BoostedRooms', json_encode($boostedRooms));
            
            $this->SLogInfo('Boost-Modus', "Boost für Thermostat {$thermostatId} abgebrochen.");
            
            if (empty($boostedRooms)) {
                $this->SetTimerInterval('BoostEndTimer', 0);
                $this->SetValueIfChanged('BoostActive', false);
            }
            
            $this->updateHeatingMode();
            $this->UpdateActiveOverridesText();
        }
    }

    public function EndBoost(): void
    {
        $boostedRooms = $this->safeJsonDecode($this->ReadAttributeString('BoostedRooms'), true);
        $now = time();
        $changed = false;
        
        foreach ($boostedRooms as $thermostatId => $expiryTime) {
            if ($now >= $expiryTime || $this->GetValue('BoostActive') == false) { // Global abort or expired
                $this->CancelBoost((string)$thermostatId);
                $changed = true;
            }
        }
        
        // Ensure UI is synced
        if (empty($this->safeJsonDecode($this->ReadAttributeString('BoostedRooms'), true))) {
            $this->SetTimerInterval('BoostEndTimer', 0);
            $this->SetValueIfChanged('BoostActive', false);
        } else {
            // Find next closest expiry
            $minExpiry = 0;
            foreach ($boostedRooms as $thermostatId => $expiryTime) {
                if ($minExpiry === 0 || $expiryTime < $minExpiry) $minExpiry = $expiryTime;
            }
            if ($minExpiry > 0) {
                $diff = $minExpiry - $now;
                if ($diff < 1) $diff = 1;
                $this->SetTimerInterval('BoostEndTimer', $diff * 1000);
            }
        }
    }

    private function findThermostatIdByRoom(string $room): string
    {
        $deviceMap = $this->safeJsonDecode($this->ReadAttributeString('DeviceMapCache'), true);
        foreach ($deviceMap as $key => $dev) {
            if (isset($dev['Room']) && $dev['Room'] === $room) {
                return (string)$key;
            }
        }
        return '';
    }

    private function updateHeatingMode(): void
    {
        $heatingInsts = $this->safeJsonDecode($this->ReadPropertyString('HeatingInstances'), true);
        if (!is_array($heatingInsts)) return;
        
        $roomCount = count($heatingInsts);

        $isVacation = $this->IsVacation();
        $isAbsence = ($this->IsAway() || $isVacation || $this->IsSleeping());
        
        if ($isAbsence || $isVacation) {
            $isHeatingSeason = GetValue($this->GetIDForIdent('HeatingSeason'));
            if (!$isHeatingSeason) {
                $this->SetValue('IsAbsenkbetrieb', false);
                $this->SetValue('HeatingStatus', 'Heizpause (Sommer) - Keine Absenkung');
                $this->SLogInfo('Sommerbetrieb aktiv.', 'Heizkörper werden nicht abgesenkt');
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
            
            $windowStates = $this->safeJsonDecode($this->ReadAttributeString('WindowPrevStates'), true);
            $fireplaceStates = $this->safeJsonDecode($this->ReadAttributeString('FireplacePrevState'), true);
            $boostStates = $this->safeJsonDecode($this->ReadAttributeString('BoostPrevStates'), true);
            
            foreach ($heatingInsts as $heating) {
                $targetIdStr = (string)($heating['TargetID'] ?? ($heating['InstanceID'] ?? ''));
                if ($targetIdStr === '') continue;
                
                // Skip if higher priority override is active
                if (isset($windowStates[$targetIdStr]) || isset($fireplaceStates[$targetIdStr]) || isset($boostStates[$targetIdStr])) {
                    continue;
                }
                
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
                    $this->safeRequestAction($controlModeId, $manuValue);
                }

                if ($targetTempId > 0 && IPS_VariableExists($targetTempId)) {
                    $this->safeRequestAction($targetTempId, $individualTemp);
                }
            }
            $this->WriteAttributeString('PreviousStates', json_encode($previousStates));
            
            if ($isVacation) {
                $this->SetValue('HeatingStatus', 'Urlaub aktiv ('. $roomCount . ' Raeume tief abgesenkt)');
                $this->SLogInfo('Urlaubs-Absenktemperatur aktiviert.', "Ziel-Temp: $globalTargetTemp | Raeume: $roomCount");
            } else {
                $this->SetValue('HeatingStatus', 'Abwesenheit aktiv ('. $roomCount . ' Raeume manuell abgesenkt)');
                $this->SLogInfo('Absenktemperatur aktiviert.', "Ziel-Temp: $globalTargetTemp | Raeume: $roomCount");
            }
        } else {
            $wasAbsenkbetrieb = $this->GetValue('IsAbsenkbetrieb');
            $this->SetValue('IsAbsenkbetrieb', false);
            $isHeatingSeason = GetValue($this->GetIDForIdent('HeatingSeason'));
            if (!$isHeatingSeason) {
                $this->SetValue('HeatingStatus', 'Heizpause (Sommer) - Inaktiv');
                $this->SLogInfo('Sommerbetrieb aktiv.', 'Keine Aenderungen beim Statuswechsel.');
                return;
            }

            if ($wasAbsenkbetrieb) {
                $previousStatesStr = $this->ReadAttributeString('PreviousStates');
                $previousStates = $this->safeJsonDecode($previousStatesStr, true);
                
                $windowStates = $this->safeJsonDecode($this->ReadAttributeString('WindowPrevStates'), true);
                $fireplaceStates = $this->safeJsonDecode($this->ReadAttributeString('FireplacePrevState'), true);
                $boostStates = $this->safeJsonDecode($this->ReadAttributeString('BoostPrevStates'), true);
                
                if (is_array($previousStates)) {
                    foreach ($previousStates as $targetIdStr => $state) {
                        // Do not restore if overridden by higher priority
                        if (isset($windowStates[$targetIdStr]) || isset($fireplaceStates[$targetIdStr]) || isset($boostStates[$targetIdStr])) {
                            continue;
                        }
                        
                        $modeId = isset($state['modeId']) ? $state['modeId'] : 0;
                        $prevMode = isset($state['prevMode']) ? $state['prevMode'] : null;
                        $tempId = isset($state['tempId']) ? $state['tempId'] : 0;
                        $prevTemp = isset($state['prevTemp']) ? $state['prevTemp'] : null;

                        $autoValue = 'AUTOMATIC';
                        
                        if ($modeId > 0 && IPS_VariableExists($modeId)) {
                            $currentMode = GetValue($modeId);
                            if (!is_string($currentMode)) {
                                $autoValue = 0;
                            }
                            $targetMode = ($prevMode !== null) ? $prevMode : $autoValue;
                            $this->safeRequestAction($modeId, $targetMode);
                        } elseif ($tempId > 0 && $prevTemp !== null && IPS_VariableExists($tempId)) {
                            $this->safeRequestAction($tempId, $prevTemp);
                        }
                    }
                }
                $this->WriteAttributeString('PreviousStates', '{}');
            }
            $this->SetValue('HeatingStatus', 'Normalbetrieb (Profil gesteuert)');
            $this->SLogInfo('Normaltemperatur / Auto-Modus wiederhergestellt.', "Raeume: $roomCount");
        }
        $this->UpdateAverageTemperature();
    }

    private function UpdateActiveOverridesText(): void
    {
        $overrides = [];
        
        $windowStates = $this->safeJsonDecode($this->ReadAttributeString('WindowPrevStates'), true);
        if (!empty($windowStates)) {
            $overrides[] = count($windowStates) . ' Fenster offen';
        }
        
        $fireplaceStates = $this->safeJsonDecode($this->ReadAttributeString('FireplacePrevState'), true);
        if (!empty($fireplaceStates)) {
            $overrides[] = 'Kamin aktiv';
        }
        
        $boostStates = $this->safeJsonDecode($this->ReadAttributeString('BoostPrevStates'), true);
        if (!empty($boostStates)) {
            $overrides[] = count($boostStates) . ' Raume(e) Boost';
        }
        
        if (empty($overrides)) {
            $this->SetValueIfChanged('ActiveOverrides', 'Keine');
        } else {
            $this->SetValueIfChanged('ActiveOverrides', implode(', ', $overrides));
        }
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
                    $this->SLogWarning('Frostgefahr erkannt!', "Ø-Temperatur: $avg °C");
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
            return 0; 
        }

        $cache = $this->safeJsonDecode($this->ReadAttributeString('DeviceMapCache'), true);
        if (isset($cache[$idStr]) && isset($cache[$idStr][$field])) {
            return (int)$cache[$idStr][$field];
        }
        return 0;
    }

    public function GetConfigurationForm(): string
    {
        $regId = $this->ReadPropertyInteger('RegistryID');
        $thermostatOptions = $this->getRegistryThermostatOptions($regId);
        
        $roomOptions = [['caption' => '(Nicht zugewiesen)', 'value' => '']];
        if ($regId > 0 && IPS_InstanceExists($regId) && function_exists('SDR_GetRooms')) {
            $rooms = (array)@SDR_GetRooms($regId);
            foreach ($rooms as $r) {
                if (!empty($r['name'])) {
                    $roomOptions[] = ['caption' => $r['name'], 'value' => $r['name']];
                }
            }
        }

        $form = [
            "elements" => [
                [
                    "type" => "SelectModule",
                    "name" => "RegistryID",
                    "caption" => "Device Registry",
                    "moduleID" => "{F3B4A7D9-C59E-401A-B826-17D3B5C2849E}"
                ],
                [
                    "type" => "CheckBox",
                    "name" => "SimulationMode",
                    "caption" => "Simulationsmodus (Testbetrieb)"
                ],
                [
                    "type" => "ExpansionPanel",
                    "caption" => "Allgemeine Einstellungen",
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
                    "type" => "ExpansionPanel",
                    "caption" => "Kaminofen",
                    "items" => [
                        [
                            "type" => "SelectInstance",
                            "name" => "FireplaceSafetyID",
                            "caption" => "Kamin-Sicherheit (FireplaceSafety)"
                        ],
                        [
                            "type" => (count($roomOptions) > 1) ? "Select" : "ValidationTextBox",
                            "name" => "FireplaceRoom",
                            "caption" => "Kamin-Raum",
                            "options" => (count($roomOptions) > 1) ? $roomOptions : null
                        ]
                    ]
                ],
                [
                    "type" => "ExpansionPanel",
                    "caption" => "Aussentemperatur & Saison",
                    "items" => [
                        [
                            "type" => "SelectVariable",
                            "name" => "OutdoorTempVarID",
                            "caption" => "Aussentemperatur Variable"
                        ],
                        [
                            "type" => "RowLayout",
                            "items" => [
                                [
                                    "type" => "NumberSpinner",
                                    "name" => "SeasonThresholdSummer",
                                    "caption" => "Sommer ab (°C)",
                                    "digits" => 1
                                ],
                                [
                                    "type" => "NumberSpinner",
                                    "name" => "SeasonThresholdWinter",
                                    "caption" => "Winter unter (°C)",
                                    "digits" => 1
                                ],
                                [
                                    "type" => "NumberSpinner",
                                    "name" => "SeasonAvgDays",
                                    "caption" => "Tage für Ø-Berechnung",
                                    "minimum" => 1
                                ]
                            ]
                        ]
                    ]
                ],
                [
                    "type" => "ExpansionPanel",
                    "caption" => "Fenster-Erkennung",
                    "items" => [
                        [
                            "type" => "RowLayout",
                            "items" => [
                                [
                                    "type" => "NumberSpinner",
                                    "name" => "WindowDebounceSeconds",
                                    "caption" => "Entprellung (Sekunden)",
                                    "minimum" => 0
                                ],
                                [
                                    "type" => "NumberSpinner",
                                    "name" => "WindowFrostTemp",
                                    "caption" => "Fenster-offen Temperatur (°C)",
                                    "digits" => 1
                                ]
                            ]
                        ]
                    ]
                ],
                [
                    "type" => "ExpansionPanel",
                    "caption" => "Boost",
                    "items" => [
                        [
                            "type" => "RowLayout",
                            "items" => [
                                [
                                    "type" => "NumberSpinner",
                                    "name" => "BoostDurationMinutes",
                                    "caption" => "Dauer (Minuten)",
                                    "minimum" => 1
                                ],
                                [
                                    "type" => "NumberSpinner",
                                    "name" => "BoostTemperature",
                                    "caption" => "Temperatur (°C)",
                                    "digits" => 1
                                ]
                            ]
                        ]
                    ]
                ],
                [
                    "type" => "ExpansionPanel",
                    "caption" => "Kessel (EMS-ESP)",
                    "items" => [
                        [
                            "type" => "SelectInstance",
                            "name" => "EMSESPID",
                            "caption" => "EMS-ESP Instanz"
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
            if (function_exists('SDR_GetDevicesByType')) {
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
