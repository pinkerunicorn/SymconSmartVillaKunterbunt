<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_HardwareControl.php';
require_once __DIR__ . '/../libs/Trait_CentralStateAware.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class SmartShading extends IPSModuleStrict
{
    use SmartLog_Trait;
    use HardwareControl_Trait;
    use CentralStateAware_Trait;
    use DeviceAvailability_Trait;
    public function Create(): void
    {
        parent::Create();
        
        // DeviceRegistry
        $this->RegisterPropertyInteger('RegistryID', 0);
        
        $this->DA_RegisterAvailability(900);


        // 1. Globale Sensorik
        $this->RegisterPropertyInteger('AzimuthVariableID', 0);
        $this->RegisterPropertyInteger('ElevationVariableID', 0);
        $this->RegisterPropertyInteger('BrightnessVariableID', 0);
        $this->RegisterPropertyInteger('BrightnessThreshold', 40000);
        $this->RegisterPropertyInteger('OutdoorTempVariableID', 0);
        $this->RegisterPropertyFloat('TempThreshold', 24.0);
        
        $this->RegisterPropertyInteger('WindVariableID', 0);
        $this->RegisterPropertyFloat('WindThreshold', 50.0);
        
        $this->RegisterPropertyInteger('SunriseVariableID', 0);
        $this->RegisterPropertyInteger('SunsetVariableID', 0);

        // 2. Rollläden Liste
        $this->RegisterPropertyString('BlindVariables', '[]');

        // Interne Attribute für Sperren und Queue
        $this->RegisterAttributeString('CurrentState', '{}'); // Aktueller Beschattungs-Zustand pro Rollladen
        
        // Status Variablen
        $this->RegisterVariableBoolean('Active', 'Automatik Aktiv', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'power-off'
        ], 0);
        $this->EnableAction('Active');
        
        $this->RegisterVariableBoolean('AlarmWindWarning', 'Alarm: Sturmschutz aktiv', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'wind'
        ], 1);
        $this->EnableAction('AlarmWindWarning');
        
        $this->RegisterVariableInteger('ActiveShadingCount', 'Schatten aktiv (Anzahl)', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'        => 'blinds'
        ], 2);
        
        $this->RegisterVariableBoolean('StatusIsNight', 'Status: Es ist Nacht', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'moon',
            'COLOR' => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => json_encode([
                ['Value' => false, 'Caption' => 'Tag', 'IconValue' => 'moon', 'IconActive' => false, 'ColorActive' => false, 'ColorDisplay' => 0xFFCC00, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFFCC00],
                ['Value' => true, 'Caption' => 'Nacht', 'IconValue' => 'moon', 'IconActive' => false, 'ColorActive' => false, 'ColorDisplay' => 0x003399, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x003399]
            ])
        ], 10);
        $this->RegisterVariableBoolean('StatusIsHotAndBright', 'Status: Hitze & Helligkeit erreicht', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'sun',
            'COLOR' => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => json_encode([
                ['Value' => false, 'Caption' => 'Normal', 'IconValue' => 'sun', 'IconActive' => false, 'ColorActive' => false, 'ColorDisplay' => -1, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => -1],
                ['Value' => true, 'Caption' => 'Heiss & hell!', 'IconValue' => 'sun', 'IconActive' => false, 'ColorActive' => false, 'ColorDisplay' => 0xFF6600, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF6600]
            ])
        ], 11);
        $this->RegisterVariableInteger('StatusSunInSectorCount', 'Status: Rollläden in der Sonne (Anzahl)', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'        => 'hashtag'
        ], 12);
        $this->RegisterVariableInteger('StatusLastEvaluation', 'Status: Letzte Berechnung', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_DATE_TIME,
            'ICON'        => 'clock'
        ], 13);
        
        // Timer für Evaluierung (alle 3 Minuten)
        $this->RegisterTimer('ShadingEvaluator', 0, 'SHSH_EvaluateConditions($_IPS[\'TARGET\']);');
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
        $ref_AzimuthVariableID = $this->ReadPropertyInteger('AzimuthVariableID');
        if ($ref_AzimuthVariableID > 1 && @IPS_ObjectExists($ref_AzimuthVariableID)) {
            $this->RegisterReference($ref_AzimuthVariableID);
        }
        $ref_ElevationVariableID = $this->ReadPropertyInteger('ElevationVariableID');
        if ($ref_ElevationVariableID > 1 && @IPS_ObjectExists($ref_ElevationVariableID)) {
            $this->RegisterReference($ref_ElevationVariableID);
        }
        $ref_BrightnessVariableID = $this->ReadPropertyInteger('BrightnessVariableID');
        if ($ref_BrightnessVariableID > 1 && @IPS_ObjectExists($ref_BrightnessVariableID)) {
            $this->RegisterReference($ref_BrightnessVariableID);
        }
        $ref_OutdoorTempVariableID = $this->ReadPropertyInteger('OutdoorTempVariableID');
        if ($ref_OutdoorTempVariableID > 1 && @IPS_ObjectExists($ref_OutdoorTempVariableID)) {
            $this->RegisterReference($ref_OutdoorTempVariableID);
        }
        $ref_WindVariableID = $this->ReadPropertyInteger('WindVariableID');
        if ($ref_WindVariableID > 1 && @IPS_ObjectExists($ref_WindVariableID)) {
            $this->RegisterReference($ref_WindVariableID);
        }
        $ref_SunriseVariableID = $this->ReadPropertyInteger('SunriseVariableID');
        if ($ref_SunriseVariableID > 1 && @IPS_ObjectExists($ref_SunriseVariableID)) {
            $this->RegisterReference($ref_SunriseVariableID);
        }
        $ref_SunsetVariableID = $this->ReadPropertyInteger('SunsetVariableID');
        if ($ref_SunsetVariableID > 1 && @IPS_ObjectExists($ref_SunsetVariableID)) {
            $this->RegisterReference($ref_SunsetVariableID);
        }
        $registryID = $this->ReadPropertyInteger('RegistryID');
        if ($registryID > 1 && @IPS_ObjectExists($registryID)) {
            $this->RegisterReference($registryID);
        }
        $list_BlindVariables = json_decode($this->ReadPropertyString('BlindVariables'), true);
        $activeModeIdents = [];
        if (is_array($list_BlindVariables)) {
            foreach ($list_BlindVariables as $item) {
                list($vid, $contactID, $name) = $this->ResolveBlindVariables($item);
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                    
                    $ident = 'Mode_' . $vid;
                    $activeModeIdents[] = $ident;
                    $modeName = 'Modus: ' . $name;
                    $this->RegisterVariableInteger($ident, $modeName, [
                        'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                        'OPTIONS' => json_encode([
                            ['Value' => 0, 'Caption' => 'Automatik', 'IconActive' => true, 'IconValue' => 'robot', 'Color' => 0x00CC00],
                            ['Value' => 1, 'Caption' => 'Manuell: Auf', 'IconActive' => true, 'IconValue' => 'ArrowUp', 'Color' => 0x3366FF],
                            ['Value' => 2, 'Caption' => 'Manuell: Zu', 'IconActive' => true, 'IconValue' => 'ArrowDown', 'Color' => 0x3366FF],
                            ['Value' => 3, 'Caption' => 'Manuell: Schatten', 'IconActive' => true, 'IconValue' => 'sun', 'Color' => 0x3366FF],
                            ['Value' => 4, 'Caption' => 'Manuell: Position', 'IconActive' => true, 'IconValue' => 'bars', 'Color' => 0x3366FF]
                        ])
                    ], 50);
                    $this->EnableAction($ident);

                    $posIdent = 'Pos_' . $vid;
                    $activeModeIdents[] = $posIdent;
                    $targetVar = IPS_GetVariable($vid);
                    $profileName = $targetVar['VariableCustomProfile'];
                    if ($profileName == '') {
                        $profileName = $targetVar['VariableProfile'];
                    }
                    
                    $presentation = defined('VARIABLE_PRESENTATION_SHUTTER') ? [
                        'PRESENTATION' => VARIABLE_PRESENTATION_SHUTTER,
                        'MIN' => 0,
                        'MAX' => 100
                    ] : $profileName;

                    if ($targetVar['VariableType'] == 1) { // Integer
                        $this->RegisterVariableInteger($posIdent, 'Pos: ' . $name, $presentation, 55);
                        $this->EnableAction($posIdent);
                    } elseif ($targetVar['VariableType'] == 2) { // Float
                        $this->RegisterVariableFloat($posIdent, 'Pos: ' . $name, $presentation, 55);
                        $this->EnableAction($posIdent);
                    }
                }
                if ($contactID > 1 && @IPS_ObjectExists($contactID)) {
                    $this->RegisterReference($contactID);
                }
            }
        }

        $children = IPS_GetChildrenIDs($this->InstanceID);
        foreach ($children as $childID) {
            $obj = IPS_GetObject($childID);
            if (strpos($obj['ObjectIdent'], 'Mode_') === 0 || strpos($obj['ObjectIdent'], 'Pos_') === 0) {
                if (!in_array($obj['ObjectIdent'], $activeModeIdents)) {
                    $this->UnregisterVariable($obj['ObjectIdent']);
                }
            }
        }
        // ---------------------------------

        
        // Timer aktivieren
        $this->SetTimerInterval('ShadingEvaluator', 3 * 60 * 1000); // 3 Minuten


        // Nachrichten für Rollläden und Fensterkontakte registrieren
        $this->UpdateMessageRegistrations();
        
        // Variable Profile für Status

        $this->DA_SetAvailable(true);
    }
    
    private function UpdateMessageRegistrations(): void
    {
        // Alle alten Registrierungen löschen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }
        
        $blindsJson = $this->ReadPropertyString('BlindVariables');
        $blinds = json_decode($blindsJson, true);
        if (!is_array($blinds)) return;
        
        foreach ($blinds as $blind) {
            list($varID, $contactID) = $this->ResolveBlindVariables($blind);
            if ($varID > 0 && IPS_VariableExists($varID)) {
                $this->RegisterMessage($varID, VM_UPDATE);
            }
            
            // contactID resolved above
            if ($contactID > 0 && IPS_VariableExists($contactID)) {
                $this->RegisterMessage($contactID, VM_UPDATE);
            }
        }
        
        $windVar = $this->ReadPropertyInteger('WindVariableID');
        if ($windVar > 0 && IPS_VariableExists($windVar)) {
            $this->RegisterMessage($windVar, VM_UPDATE);
        }
    }
    
    protected function OnCentralStateChanged(string $stateName, mixed $newValue): void
    {
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($this->HandleCentralStateMessage($SenderID, $Message, $Data)) return;
        if ($Message == VM_UPDATE) {
            $blindsJson = $this->ReadPropertyString('BlindVariables');
            $blinds = json_decode($blindsJson, true);
            if (!is_array($blinds)) return;
            
            foreach ($blinds as $blind) {
                // contactID resolved above
                list($varID, $contactID) = $this->ResolveBlindVariables($blind);
                
                if ($SenderID == $contactID) {
                    // Fensterkontakt hat sich geändert -> Sofort evaluieren
                    $this->EvaluateConditions();
                }
                
                if ($SenderID == $varID) {
                    $posIdent = 'Pos_' . $varID;
                    if (@IPS_GetObjectIDByIdent($posIdent, $this->InstanceID) !== false) {
                        $valTop = (float)($blind['ValueTop'] ?? $blind['ValueOpen'] ?? 0);
                        $valClose = (float)($blind['ValueClose'] ?? 1);
                        $hwVal = (float)$Data[0];
                        
                        $pos = 0;
                        if ($valClose != $valTop) {
                            $pos = ($hwVal - $valTop) / ($valClose - $valTop) * 100;
                        }
                        if ($pos < 0) $pos = 0;
                        if ($pos > 100) $pos = 100;
                        
                        $this->SetValue($posIdent, $pos);
                    }
                }
            }
            
            $windVar = $this->ReadPropertyInteger('WindVariableID');
            if ($windVar > 0 && $SenderID == $windVar) {
                $this->CheckWind();
            }
        }
    }
    
    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'Active') {
            $this->SetValue($Ident, $Value);
            if ($Value) {
                $this->EvaluateConditions();
            }
        } elseif ($Ident === 'AlarmWindWarning') {
            $this->SetValue($Ident, false);
        } elseif (strpos($Ident, 'Mode_') === 0) {
            $this->SetValue($Ident, $Value);
            $varId = (int)substr($Ident, 5);
            
            if ($Value != 0) {
                $blinds = json_decode($this->ReadPropertyString('BlindVariables'), true);
                $matchedBlind = null;
                if (is_array($blinds)) {
                    foreach ($blinds as $blind) {
                        list($vid, $cid) = $this->ResolveBlindVariables($blind);
                        if ($vid == $varId) {
                            $matchedBlind = $blind;
                            break;
                        }
                    }
                }
                if ($matchedBlind) {
                    $targetValueStr = "";
                    if ($Value == 1) $targetValueStr = $matchedBlind['ValueOpen'] ?? "0";
                    if ($Value == 2) $targetValueStr = $matchedBlind['ValueClose'] ?? "1";
                    if ($Value == 3) $targetValueStr = $matchedBlind['ValueShade'] ?? "0.1";
                    
                    if ($Value != 4) { // Bei Modus 4 (Position) wird die Position separat über Pos_ gesetzt
                        $this->ExecuteAction($varId, $targetValueStr);
                    }
                    
                    $states = json_decode($this->ReadAttributeString('CurrentState'), true);
                    $states[$varId] = 'MANUAL';
                    $this->WriteAttributeString('CurrentState', json_encode($states));
                }
            } else {
                $this->EvaluateConditions();
            }
        } elseif (strpos($Ident, 'Pos_') === 0) {
            $this->SetValue($Ident, $Value);
            $varId = (int)substr($Ident, 4);
            
            $blinds = json_decode($this->ReadPropertyString('BlindVariables'), true);
            $matchedBlind = null;
            if (is_array($blinds)) {
                foreach ($blinds as $blind) {
                    list($vid, $cid) = $this->ResolveBlindVariables($blind);
                    if ($vid == $varId) {
                        $matchedBlind = $blind;
                        break;
                    }
                }
            }
            
            $targetValueStr = (string)$Value;
            if ($matchedBlind) {
                $valTop = (float)($matchedBlind['ValueTop'] ?? $matchedBlind['ValueOpen'] ?? 0);
                $valClose = (float)($matchedBlind['ValueClose'] ?? 1);
                
                $hwVal = $valTop + ((float)$Value / 100) * ($valClose - $valTop);
                
                $targetVar = IPS_GetVariable($varId);
                if ($targetVar['VariableType'] == 1) { // Integer
                    $hwVal = round($hwVal);
                }
                $targetValueStr = (string)$hwVal;
            }
            
            $this->ExecuteAction($varId, $targetValueStr);
            
            $modeIdent = 'Mode_' . $varId;
            if (@IPS_GetObjectIDByIdent($modeIdent, $this->InstanceID) !== false) {
                $this->SetValue($modeIdent, 4); // Setze Modus auf 'Manuell: Position'
            }
            
            $states = json_decode($this->ReadAttributeString('CurrentState'), true);
            $states[$varId] = 'MANUAL';
            $this->WriteAttributeString('CurrentState', json_encode($states));
        }
    }
    
    private function CheckWind(): void
    {
        $windVar = $this->ReadPropertyInteger('WindVariableID');
        if ($windVar <= 0 || !IPS_VariableExists($windVar)) return;

        $windSpeed = GetValue($windVar);
        $threshold = $this->ReadPropertyFloat('WindThreshold');
        if ($windSpeed >= $threshold) {
            $this->SetValue('AlarmWindWarning', true);
            $this->SLogWarning( 'Sturmwarnung aktiv — Rollläden werden eingefahren.', "Windgeschwindigkeit: {$windSpeed} | Schwelle: {$threshold}");
        }
    }

    private function CalculateBlindState(array $blind, bool $isNight, bool $isHotAndBright, float $azimuth, int $contactID = 0): ?float
    {
        // Fensterkontakt prüfen
        $isOpen = false;
        if ($contactID > 0 && IPS_VariableExists($contactID)) {
            $contactVal = GetValue($contactID);
            if (is_string($contactVal)) {
                $isOpen = (strtoupper($contactVal) === 'OPEN'|| strtoupper($contactVal) === 'TILTED');
            } elseif (is_bool($contactVal)) {
                $isOpen = $contactVal;
            } else {
                $isOpen = ($contactVal > 0);
            }
        }
        
        // Sonnen-Sektor
        $aziFrom = (float)($blind['AzimuthFrom'] ?? 90);
        $aziTo = (float)($blind['AzimuthTo'] ?? 270);
        
        // Ist die Sonne im Sektor?
        $sunInSector = false;
        if ($aziFrom < $aziTo) {
            $sunInSector = ($azimuth >= $aziFrom && $azimuth <= $aziTo);
        } else {
            $sunInSector = ($azimuth >= $aziFrom || $azimuth <= $aziTo);
        }
        
        $targetState = 'OPEN';
        $targetValueStr = "1";
        
        if ($isNight) {
            $targetState = 'NIGHT';
            $targetValueStr = $blind['ValueClose'] ?? "1";
        } elseif ($sunInSector && $isHotAndBright) {
            $targetState = 'SHADING';
            $targetValueStr = $blind['ValueShade'] ?? "0.1";
        } else {
            $targetState = 'OPEN';
            $targetValueStr = $blind['ValueOpen'] ?? "0";
        }
        
        if ($isOpen && $targetState !== 'OPEN') {
            $targetState = 'VENTILATE';
            $targetValueStr = $blind['ValueVentilate'] ?? "0.3";
        }

        return (float) $targetValueStr;
    }

    private function GetTargetValueString(array $blind, string $state): string 
    {
        if ($state === 'NIGHT') {
            return $blind['ValueClose'] ?? "1";
        } elseif ($state === 'SHADING') {
            return $blind['ValueShade'] ?? "0.1";
        } elseif ($state === 'VENTILATE') {
            return $blind['ValueVentilate'] ?? "0.3";
        } else {
            return $blind['ValueOpen'] ?? "0";
        }
    }

        private function ResolveBlindVariables(array $blind): array
    {
        $registryID = $this->ReadPropertyInteger('RegistryID');
        $vid = 0;
        $contactID = 0;
        $name = '';
        
        if ($registryID > 1 && @IPS_ObjectExists($registryID) && function_exists('SDR_GetDevicesByType')) {
            if (isset($blind['DeviceID']) && $blind['DeviceID'] !== '' && $blind['DeviceID'] !== '0') {
                $blindsMap = @SDR_GetDevicesByType($registryID, 'DevicesBlind');
                foreach ($blindsMap as $b) {
                    if ($b['id'] === $blind['DeviceID']) {
                        $vid = (int)($b['OpenClose_VarID'] ?? 0);
                        $name = (string)($b['name'] ?? '');
                        break;
                    }
                }
            }
            if (isset($blind['ContactID']) && !is_numeric($blind['ContactID']) && $blind['ContactID'] !== '') {
                $contactsMap = @SDR_GetDevicesByType($registryID, 'DevicesContactSensor');
                foreach ($contactsMap as $c) {
                    if ($c['id'] === $blind['ContactID']) {
                        $contactID = (int)($c['Status_VarID'] ?? 0);
                        break;
                    }
                }
            }
        }
        
        // Fallbacks
        if ($vid === 0 && isset($blind['VariableID'])) $vid = (int)$blind['VariableID'];
        if ($contactID === 0 && isset($blind['ContactID']) && is_numeric($blind['ContactID'])) $contactID = (int)$blind['ContactID'];
        
        if ($name === '' && $vid > 0 && @IPS_ObjectExists($vid)) {
            $name = IPS_GetName($vid);
        }
        
        return [$vid, $contactID, $name];
    }

    public function EvaluateConditions(): void
    {
        if (!$this->GetValue('Active')) {
            return;
        }
        
        $blindsJson = $this->ReadPropertyString('BlindVariables');
        $blinds = json_decode($blindsJson, true);
        if (!is_array($blinds) || count($blinds) === 0) return;
        
        if ($this->GetValue('AlarmWindWarning')) {
            return;
        }
        
        // Werte lesen
        $azimuth = $this->GetFloatVal('AzimuthVariableID');
        $brightness = $this->GetFloatVal('BrightnessVariableID');
        $brightnessThreshold = $this->ReadPropertyInteger('BrightnessThreshold');
        $temp = $this->GetFloatVal('OutdoorTempVariableID');
        $tempThreshold = $this->ReadPropertyFloat('TempThreshold');
        
        $states = json_decode($this->ReadAttributeString('CurrentState'), true);
        
        $isHotAndBright = ($temp >= $tempThreshold && $brightness >= $brightnessThreshold);
        $this->SetValue('StatusIsHotAndBright', $isHotAndBright);
        
        $sunriseTime = $this->GetFloatVal('SunriseVariableID');
        $sunsetTime = $this->GetFloatVal('SunsetVariableID');
        $now = time();
        $isNight = false;
        
        if ($sunriseTime > 0 && $sunsetTime > 0) {
            if ($sunriseTime > $sunsetTime) {
                $isNight = false;
            } else {
                if ($now >= $sunsetTime || $now < $sunriseTime) {
                    $isNight = true;
                }
            }
        }
        $this->SetValue('StatusIsNight', $isNight);
        $this->SetValue('StatusLastEvaluation', time());
        
        $sunCount = 0;
        $shadingCount = 0;
        
        foreach ($blinds as $blind) {
            list($id, $cid) = $this->ResolveBlindVariables($blind);
            if ($id <= 0) {
                continue;
            }
            
            $ident = 'Mode_' . $id;
            if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) !== false) {
                $mode = $this->GetValue($ident);
                if ($mode != 0) {
                    continue;
                }
            }

            // Für StatusSunInSectorCount
            $aziFrom = (float)($blind['AzimuthFrom'] ?? 90);
            $aziTo = (float)($blind['AzimuthTo'] ?? 270);
            $sunInSector = false;
            if ($aziFrom < $aziTo) {
                $sunInSector = ($azimuth >= $aziFrom && $azimuth <= $aziTo);
            } else {
                $sunInSector = ($azimuth >= $aziFrom || $azimuth <= $aziTo);
            }
            if ($sunInSector) {
                $sunCount++;
            }
            
            $targetValueFloat = $this->CalculateBlindState($blind, $isNight, $isHotAndBright, $azimuth, $cid);
            
            // Re-resolve state for logging and target value (since int loses exact floats)
            // But actually we have to return int for the method signature
            // Let's deduce targetState from $targetValueInt!
            
            // We can just use the int to find the state or recalculate internally
            $targetValueStr = (string)$targetValueFloat;
            $targetState = 'OPEN';
            
            // Or better, CalculateBlindState returns the TARGET value! Let's deduce state.
            if ($targetValueFloat == (float)($blind['ValueClose'] ?? "1")) {
                $targetState = 'NIGHT';
                $targetValueStr = $blind['ValueClose'] ?? "1";
            } elseif ($targetValueFloat == (float)($blind['ValueShade'] ?? "0.1")) {
                $targetState = 'SHADING';
                $targetValueStr = $blind['ValueShade'] ?? "0.1";
            } elseif ($targetValueFloat == (float)($blind['ValueVentilate'] ?? "0.3")) {
                $targetState = 'VENTILATE';
                $targetValueStr = $blind['ValueVentilate'] ?? "0.3";
            } else {
                $targetState = 'OPEN';
                $targetValueStr = $blind['ValueOpen'] ?? "0";
            }
            
            $currentState = $states[$id] ?? 'UNKNOWN';
            
            if ($currentState !== $targetState) {
                $this->ExecuteAction($id, $targetValueStr);
                $states[$id] = $targetState;
                $this->SLogInfo( "Rollladen $id fährt auf Zustand: $targetState");
            }
            
            if ($targetState === 'SHADING') {
                $shadingCount++;
            }
        }
        
        $this->SetValue('StatusSunInSectorCount', $sunCount);
        $this->SetValue('ActiveShadingCount', $shadingCount);
        $this->WriteAttributeString('CurrentState', json_encode($states));
    }
    
    private function GetFloatVal(string $propName): float
    {
        $varId = $this->ReadPropertyInteger($propName);
        if ($varId > 0 && IPS_VariableExists($varId)) {
            return (float)GetValue($varId);
        }
        return 0.0;
    }

    private function ExecuteAction(int $targetID, string $valStr): void
    {
        if (!IPS_VariableExists($targetID)) return;

        $var = IPS_GetVariable($targetID);
        $val = $valStr;
        
        if ($var['VariableType'] == 0) { // Boolean
            $val = (strtolower($valStr) === 'true'|| $valStr === '1');
        } elseif ($var['VariableType'] == 1) { // Integer
            $val = (int)$valStr;
        } elseif ($var['VariableType'] == 2) { // Float
            $valStr = str_replace(',', '.', $valStr);
            $val = (float)$valStr;
        }
        
        $result = $this->safeRequestAction($targetID, $val);
        if (!$result) {
            $this->SLogError( "RequestAction für ID $targetID fehlgeschlagen!");
        }
    }

        public function GetConfigurationForm(): string
    {
        $form = [
            "elements" => [
                [
                    "type" => "CheckBox",
                    "name" => "SimulationMode",
                    "caption" => "Simulationsmodus (Testbetrieb)"
                ],
                [
                    "type" => "SelectModule",
                    "name" => "RegistryID",
                    "caption" => "Device Registry (Geräteverwaltung)",
                    "moduleID" => "{F3B4A7D9-C59E-401A-B826-17D3B5C2849E}"
                ],
                [
                    "type" => "Label",
                    "caption" => " "
                ],
                [
                    "type" => "ExpansionPanel",
                    "caption" => "⚙ SmartHome Shading - Intelligente Sonnenstands- & Hitzebeschattung",
                    "items" => [
                        ["type" => "Label", "caption" => "Willkommen bei SmartHome Shading! Lass uns deine Rollläden intelligent machen."],
                        ["type" => "Label", "caption" => "1. Globale Sensorik"],
                        ["type" => "Label", "caption" => "Hier wählst du die Sensoren für den Sonnenstand aus. Diese benötigt das Modul, um zu wissen, wo die Sonne gerade steht:"],
                        [
                            "type" => "RowLayout",
                            "items" => [
                                ["type" => "SelectVariable", "name" => "AzimuthVariableID", "caption" => "Sonnen-Azimut (Location-Modul)"],
                                ["type" => "SelectVariable", "name" => "ElevationVariableID", "caption" => "Sonnen-Höhe (Elevation)"]
                            ]
                        ],
                        ["type" => "Label", "caption" => "Ab wie viel Lux soll beschattet werden? Wähle deinen Helligkeitssensor und den Schwellwert:"],
                        [
                            "type" => "RowLayout",
                            "items" => [
                                ["type" => "SelectVariable", "name" => "BrightnessVariableID", "caption" => "Helligkeits-Sensor (Lux)"],
                                ["type" => "NumberSpinner", "name" => "BrightnessThreshold", "caption" => "Schwellwert Lux (z.B. 40000)", "minimum" => 0, "maximum" => 200000]
                            ]
                        ],
                        ["type" => "Label", "caption" => "Ab welcher Temperatur wird es dir zu warm im Haus? Beschattung startet nur, wenn es draußen heißer ist als dieser Wert:"],
                        [
                            "type" => "RowLayout",
                            "items" => [
                                ["type" => "SelectVariable", "name" => "OutdoorTempVariableID", "caption" => "Außentemperatur-Sensor (°C)"],
                                ["type" => "NumberSpinner", "name" => "TempThreshold", "caption" => "Hitze-Schwellwert (°C, z.B. 24)", "minimum" => -20, "maximum" => 50, "digits" => 1]
                            ]
                        ],
                        ["type" => "Label", "caption" => "Sturmschutz: Ab welcher Windgeschwindigkeit sollen die Rollläden zum Schutz hochfahren?"],
                        [
                            "type" => "RowLayout",
                            "items" => [
                                ["type" => "SelectVariable", "name" => "WindVariableID", "caption" => "Wind-Sensor (km/h)"],
                                ["type" => "NumberSpinner", "name" => "WindThreshold", "caption" => "Sturm-Schutz ab (km/h)", "minimum" => 0, "maximum" => 150, "digits" => 1]
                            ]
                        ],
                        ["type" => "Label", "caption" => "Damit die Rollläden abends automatisch schließen und morgens öffnen, wähle hier die Astro-Variablen:"],
                        [
                            "type" => "RowLayout",
                            "items" => [
                                ["type" => "SelectVariable", "name" => "SunriseVariableID", "caption" => "Sonnenaufgang Variable (Astro)"],
                                ["type" => "SelectVariable", "name" => "SunsetVariableID", "caption" => "Sonnenuntergang Variable (Astro)"]
                            ]
                        ]
                    ]
                ],
                [
                    "type" => "Label",
                    "caption" => "2. Rollläden & Fenster (0=Auf, 1=Zu)"
                ],
                [
                    "type" => "Label",
                    "caption" => "Hier legst du deine Rollläden an. Wähle das Gerät aus der Registry. Gib an, bei welchem Sonnenstand (Azimut Von/Bis) das Fenster Sonne abbekommt. Trage außerdem die Positionen für 'Auf', 'Zu', 'Beschatten' und 'Lüften' (wenn die Tür offen ist) ein."
                ]
            ]
        ];

        // Baue die Optionen für Registry-Geräte
        $blindOptions = [["caption" => "-", "value" => 0]];
        $contactOptions = [["caption" => "-", "value" => 0]];
        
        $registryID = $this->ReadPropertyInteger('RegistryID');
        if ($registryID > 1 && @IPS_ObjectExists($registryID) && function_exists('SDR_GetDevicesByType')) {
            $blinds = @SDR_GetDevicesByType($registryID, 'DevicesBlind');
            if (is_array($blinds)) {
                foreach ($blinds as $b) {
                    $blindOptions[] = ["caption" => ($b['room'] ?? '') . " - " . ($b['name'] ?? ''), "value" => $b['id']];
                }
            }
            
            $contacts = @SDR_GetDevicesByType($registryID, 'DevicesContactSensor');
            if (is_array($contacts)) {
                foreach ($contacts as $c) {
                    $contactOptions[] = ["caption" => ($c['room'] ?? '') . " - " . ($c['name'] ?? ''), "value" => $c['id']];
                }
            }
        }

        $form["elements"][] = [
            "type" => "List",
            "name" => "BlindVariables",
            "caption" => "Rollläden",
            "rowCount" => 15,
            "add" => true,
            "delete" => true,
            "changeOrder" => true,
            "columns" => [
                [
                    "caption" => "Gerät (Registry)",
                    "name" => "DeviceID",
                    "width" => "250px",
                    "add" => 0,
                    "edit" => [
                        "type" => "Select",
                        "options" => $blindOptions
                    ]
                ],
                [
                    "caption" => "Fensterkontakt",
                    "name" => "ContactID",
                    "width" => "200px",
                    "add" => 0,
                    "edit" => [
                        "type" => "Select",
                        "options" => $contactOptions
                    ]
                ],
                [
                    "caption" => "Azimut Von (°)",
                    "name" => "AzimuthFrom",
                    "width" => "120px",
                    "add" => 90,
                    "edit" => ["type" => "NumberSpinner", "minimum" => 0, "maximum" => 360]
                ],
                [
                    "caption" => "Azimut Bis (°)",
                    "name" => "AzimuthTo",
                    "width" => "120px",
                    "add" => 270,
                    "edit" => ["type" => "NumberSpinner", "minimum" => 0, "maximum" => 360]
                ],
                [
                    "caption" => "Obere Pos (0%)",
                    "name" => "ValueTop",
                    "width" => "100px",
                    "add" => "0",
                    "edit" => ["type" => "ValidationTextBox"]
                ],
                [
                    "caption" => "Auf-Pos",
                    "name" => "ValueOpen",
                    "width" => "80px",
                    "add" => "0",
                    "edit" => ["type" => "ValidationTextBox"]
                ],
                [
                    "caption" => "Zu-Pos",
                    "name" => "ValueClose",
                    "width" => "80px",
                    "add" => "1",
                    "edit" => ["type" => "ValidationTextBox"]
                ],
                [
                    "caption" => "Schatten-Pos",
                    "name" => "ValueShade",
                    "width" => "120px",
                    "add" => "0.1",
                    "edit" => ["type" => "ValidationTextBox"]
                ],
                [
                    "caption" => "Tür Offen-Pos",
                    "name" => "ValueVentilate",
                    "width" => "120px",
                    "add" => "0.3",
                    "edit" => ["type" => "ValidationTextBox"]
                ]
            ]
        ];

        return json_encode($form);
    }
}


