<?php

declare(strict_types=1);

class SymconDeviceRegistry extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        
        $this->RegisterPropertyString('Floors', '[]');
        $this->RegisterPropertyString('Rooms', '[]');

        $this->RegisterPropertyFloat('PriceElectricity', 0.32);
        $this->RegisterPropertyFloat('BasePriceElectricity', 0.0);
        $this->RegisterPropertyFloat('PriceWater', 4.80);
        $this->RegisterPropertyFloat('BasePriceWater', 0.0);
        $this->RegisterPropertyFloat('PriceGas', 0.12);
        $this->RegisterPropertyFloat('BasePriceGas', 0.0);

        // Astro
        $this->RegisterPropertyInteger('SunsetVariableID', 0);
        $this->RegisterPropertyInteger('SunriseVariableID', 0);

        // Haus-Konfiguration (Multi-House Service-Locator)
        $this->RegisterPropertyInteger('ControllerID', 0);

        // Aktorik
        $this->RegisterPropertyString('DevicesSwitch', '[]');
        
        // Sensorik
        $this->RegisterPropertyString('DevicesWallSwitch', '[]');
        $this->RegisterPropertyString('DevicesSocket', '[]');
        $this->RegisterPropertyString('DevicesLight', '[]');
        $this->RegisterPropertyString('DevicesLightDimmer', '[]');
        $this->RegisterPropertyString('DevicesLightColor', '[]');
        $this->RegisterPropertyString('DevicesBlind', '[]');
        $this->RegisterPropertyString('DevicesThermostat', '[]');
        
        // Sensorik
        $this->RegisterPropertyString('DevicesMotionSensor', '[]');
        $this->RegisterPropertyString('DevicesContactSensor', '[]');
        $this->RegisterPropertyString('DevicesSmokeSensor', '[]');
        $this->RegisterPropertyString('DevicesAlarmSensor', '[]');
        $this->RegisterPropertyString('DevicesGenericSensor', '[]');
        
        // Diagnose
        $this->RegisterPropertyString('DevicesHealth', '[]');
        $this->RegisterPropertyString('DevicesEvent', '[]');
        
        // Auto-Registration
        $this->RegisterAttributeString('AutoRegisteredDevices', '[]');

        // Standort-Mapping (welche Pfad-Ebene = Raum / Etage)
        $this->RegisterPropertyInteger('LocationFloorLevel', 2);
        $this->RegisterPropertyInteger('LocationRoomLevel', 3);

        $this->RegisterVariableInteger('RegisteredDevices', 'Gesamtanzahl Geraete', '', 1);

        $this->RegisterVariableFloat('VarPriceElectricity', 'Strompreis', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' Cent/kWh',
            'ICON' => 'bolt',
            'DIGITS' => 4
        ], 200);
        $this->RegisterVariableFloat('VarBasePriceElectricity', 'Strom Grundpreis', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' €/Jahr',
            'ICON' => 'bolt',
            'DIGITS' => 2
        ], 201);
        
        $this->RegisterVariableFloat('VarPriceWater', 'Wasserpreis', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' Cent/m³',
            'ICON' => 'faucet',
            'DIGITS' => 4
        ], 202);
        $this->RegisterVariableFloat('VarBasePriceWater', 'Wasser Grundpreis', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' €/Jahr',
            'ICON' => 'faucet',
            'DIGITS' => 2
        ], 203);
        
        $this->RegisterVariableFloat('VarPriceGas', 'Gaspreis', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' Cent/kWh',
            'ICON' => 'fire',
            'DIGITS' => 4
        ], 204);
        $this->RegisterVariableFloat('VarBasePriceGas', 'Gas Grundpreis', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' €/Jahr',
            'ICON' => 'fire',
            'DIGITS' => 2
        ], 205);
        

        $captions = [
            'DevicesSwitch' => 'Schalter',
            'DevicesSocket' => 'Steckdosen',
            'DevicesLight' => 'Licht (Schalter)',
            'DevicesLightDimmer' => 'Licht (Dimmer)',
            'DevicesLightColor' => 'Licht (Farbe)',
            'DevicesBlind' => 'Jalousien',
            'DevicesThermostat' => 'Thermostate',

            'DevicesWallSwitch' => 'Wandschalter / Taster',
            'DevicesMotionSensor' => 'Bewegungsmelder',
            'DevicesContactSensor' => 'Fenster-/Türkontakte',
            'DevicesSmokeSensor' => 'Rauchmelder',
            'DevicesAlarmSensor' => 'Alarmmelder',
            'DevicesGenericSensor' => 'Allgemeine Sensoren / Regler',
            'DevicesHealth' => 'Offline-Module',
            'DevicesEvent' => 'Haus-Ereignisse'
        ];
        
        $pos = 10;
        foreach ($captions as $prop => $caption) {
            $this->RegisterVariableInteger("Count_" . $prop, "Anzahl " . $caption, '', $pos++);
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetValue('VarPriceElectricity', $this->ReadPropertyFloat('PriceElectricity') * 100);
        $this->SetValue('VarBasePriceElectricity', $this->ReadPropertyFloat('BasePriceElectricity'));
        $this->SetValue('VarPriceWater', $this->ReadPropertyFloat('PriceWater') * 100);
        $this->SetValue('VarBasePriceWater', $this->ReadPropertyFloat('BasePriceWater'));
        $this->SetValue('VarPriceGas', $this->ReadPropertyFloat('PriceGas') * 100);
        $this->SetValue('VarBasePriceGas', $this->ReadPropertyFloat('BasePriceGas'));

        $mappings = [
            'Floors', 'Rooms', 'DevicesSwitch', 'DevicesSocket', 'DevicesLight', 'DevicesLightDimmer',
            'DevicesLightColor', 'DevicesBlind', 'DevicesThermostat',
            'DevicesWallSwitch', 'DevicesMotionSensor', 'DevicesContactSensor', 'DevicesSmokeSensor',
            'DevicesAlarmSensor', 'DevicesGenericSensor', 'DevicesHealth', 'DevicesEvent'
        ];
        
        $changed = false;
        $totalDevices = 0;
        foreach ($mappings as $propName) {
            $json = @$this->ReadPropertyString($propName);
            if ($json === false || $json === '') $json = '[]';
            $devices = json_decode((string)$json, true);
            if (!is_array($devices)) continue;
            
            $propChanged = false;
            foreach ($devices as &$device) {
                if (empty($device['id'])) {
                    // Generate a stable unique ID
                    $device['id'] = md5(uniqid((string)mt_rand(), true));
                    $propChanged = true;
                    $changed = true;
                }
            }
            unset($device);
            if ($propChanged) {
                IPS_SetProperty($this->InstanceID, $propName, json_encode(array_values($devices)));
            }
            
            if (str_starts_with($propName, 'Devices')) {
                $count = count($devices);
                @$this->SetValue("Count_" . $propName, $count);
                $totalDevices += $count;
            }
        }

        if ($changed) {
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        // Include auto-registered devices in total count
        $autoJson = @$this->ReadAttributeString('AutoRegisteredDevices');
        if ($autoJson === false || $autoJson === '') $autoJson = '[]';
        $autoDevices = json_decode((string)$autoJson, true);
        $autoCount = is_array($autoDevices) ? count($autoDevices) : 0;
        $this->SetValue('RegisteredDevices', $totalDevices + $autoCount);
        
        $this->notifyDependentModules();
    }

    private function notifyDependentModules(): void
    {
        $allInstances = IPS_GetInstanceList();
        $myId = $this->InstanceID;
        $count = 0;
        
        foreach ($allInstances as $instId) {
            if ($instId === $myId) {
                continue;
            }
            $regId = @IPS_GetProperty($instId, 'RegistryID');
            if ($regId === $myId) {
                $count++;
                @IPS_ApplyChanges($instId);
            }
        }
        
        if ($count > 0) {
            $this->SendDebug('RegistryUpdate', "Triggered IPS_ApplyChanges on $count dependent instances.", 0);
        }
    }

    public function GetConfigurationForm(): string
    {
        $jsonForm = file_get_contents(__DIR__ . '/form.json');
        $form     = json_decode($jsonForm, true);
        
        $floorsJson = @$this->ReadPropertyString('Floors');
        if ($floorsJson === false || $floorsJson === '') $floorsJson = '[]';
        $floorsList = json_decode((string)$floorsJson, true);
        $floorOptions = [['caption' => '(Nicht zugewiesen)', 'value' => '']];
        if (is_array($floorsList)) {
            foreach ($floorsList as $f) {
                if (!empty($f['name'])) {
                    $floorOptions[] = ['caption' => $f['name'], 'value' => $f['name']];
                }
            }
        }
        
        $roomsJson = @$this->ReadPropertyString('Rooms');
        if ($roomsJson === false || $roomsJson === '') $roomsJson = '[]';
        $roomsList = json_decode((string)$roomsJson, true);
        $roomOptions = [['caption' => '(Nicht zugewiesen)', 'value' => '']];
        if (is_array($roomsList)) {
            foreach ($roomsList as $r) {
                if (!empty($r['name'])) {
                    $roomOptions[] = ['caption' => $r['name'], 'value' => $r['name']];
                }
            }
        }

        if (is_array($form) && isset($form['elements'])) {
            foreach ($form['elements'] as &$element) {
                if (($element['type'] ?? '') === 'ExpansionPanel' && isset($element['items'])) {
                    foreach ($element['items'] as &$item) {
                        if (($item['type'] ?? '') === 'List' && isset($item['name'])) {
                            if ($item['name'] === 'Rooms') {
                                // Inject Floor Options
                                if (isset($item['columns'])) {
                                    foreach ($item['columns'] as &$col) {
                                        if ($col['name'] === 'floor' && isset($col['edit']['type']) && $col['edit']['type'] === 'Select') {
                                            $col['edit']['options'] = $floorOptions;
                                        }
                                    }
                                    unset($col);
                                }
                            } elseif ($item['name'] === 'AutoRegisteredList') {
                                $autoJson = @$this->ReadAttributeString('AutoRegisteredDevices');
                                if ($autoJson === false || $autoJson === '') $autoJson = '[]';
                                $autoDevices = json_decode((string)$autoJson, true);
                                if (is_array($autoDevices)) {
                                    $values = [];
                                    foreach ($autoDevices as $dev) {
                                        $varStrs = [];
                                        if (isset($dev['variables']) && is_array($dev['variables'])) {
                                            foreach ($dev['variables'] as $k => $v) {
                                                if ($v > 0) $varStrs[] = str_replace('_VarID', '', $k) . ': ' . $v;
                                            }
                                        }
                                        $dev['variablesSummary'] = implode(', ', $varStrs);
                                        $values[] = $dev;
                                    }
                                    $item['values'] = $values;
                                }
                            } elseif (str_starts_with($item['name'], 'Devices')) {
                                // Inject Room Options
                                if (isset($item['columns'])) {
                                    foreach ($item['columns'] as &$col) {
                                        if ($col['name'] === 'room' && isset($col['edit']['type']) && $col['edit']['type'] === 'Select') {
                                            $col['edit']['options'] = $roomOptions;
                                        }
                                    }
                                    unset($col);
                                }
                                
                                $propName    = $item['name'];
                            $devicesJson = @$this->ReadPropertyString($propName);
                            if ($devicesJson === false || $devicesJson === '') $devicesJson = '[]';
                            $devices     = json_decode((string)$devicesJson, true);
                            if (is_array($devices)) {
                                foreach ($devices as &$dev) {
                                    $status   = 'OK';
                                    $rowColor = ''; 
                                    $hasError = false;

                                        $varFields = ['OnOff_VarID', 'Brightness_VarID', 'ColorRGB_VarID', 'ColorTemp_VarID', 'OpenClose_VarID', 'TempSet_VarID', 'Status_VarID', 'Lux_VarID', 'Value_VarID', 'ActualTemp_VarID', 'BoostMode_VarID', 'ControlMode_VarID', 'Humidity_VarID', 'Power_VarID', 'Energy_VarID', 'Battery_VarID', 'Reachable_VarID'];
                                        $primaryFieldFound = false;
                                        
                                        $isDimmer = in_array($propName, ['DevicesLightDimmer', 'DevicesLightColor']);
                                        $isGeneric = ($propName === 'DevicesGenericSensor');
                                        $hasBrightness = (isset($dev['Brightness_VarID']) && (int)$dev['Brightness_VarID'] > 0 && IPS_VariableExists((int)$dev['Brightness_VarID']));

                                        foreach ($varFields as $varField) {
                                            if (isset($dev[$varField])) {
                                                $val = (int)$dev[$varField];
                                                if ($val > 0) {
                                                    $primaryFieldFound = true;
                                                    if (!IPS_VariableExists($val)) {
                                                        $status   = 'Var fehlt: ' . str_replace('_VarID', '', $varField);
                                                        $rowColor = '#FF4040'; 
                                                        $hasError = true;
                                                        break;
                                                    }
                                                } else {
                                                    if ($varField === 'OnOff_VarID' && $isDimmer && $hasBrightness) {
                                                        // OK: Dimmer darf nur Brightness haben
                                                    } elseif ($isGeneric && in_array($varField, ['Value_VarID', 'Status_VarID'])) {
                                                        // OK: Generic Sensor needs no specific status/value variable
                                                    } elseif (in_array($varField, ['OnOff_VarID', 'OpenClose_VarID', 'Status_VarID', 'Value_VarID', 'TempSet_VarID', 'ActualTemp_VarID'])) {
                                                        $status   = 'Unvollstaendig';
                                                        $rowColor = '#FF8000';
                                                        $hasError = true;
                                                    }
                                                }
                                            }
                                        }
                                        if (!$primaryFieldFound && !$hasError) {
                                             $status   = 'Unvollstaendig';
                                             $rowColor = '#FF8000'; 
                                        }

                                    $dev['Status']   = $status;
                                    if ($rowColor !== '') {
                                        $dev['rowColor'] = $rowColor;
                                    } else {
                                        unset($dev['rowColor']);
                                    }
                                }
                                unset($dev);
                                $item['values'] = $devices;
                            }
                        }
                        }
                    }
                    unset($item);
                }
            }
            unset($element);
        }

        if (!isset($form['actions'])) {
            $form['actions'] = [];
        }
        $form['actions'][] = [
            "type"    => "Button",
            "caption" => "Tote Variablen-Verknüpfungen bereinigen (Leichen entfernen)",
            "onClick" => 'SDR_CleanUpDeadLinks($id);'
        ];

        return json_encode($form);
    }
    
    // API Methoden
    
    public function GetFloors(): array
    {
        $json = @$this->ReadPropertyString('Floors');
        if ($json === false || $json === '') $json = '[]';
        $list = json_decode((string)$json, true);
        return is_array($list) ? $list : [];
    }
    
    public function GetRooms(): array
    {
        $json = @$this->ReadPropertyString('Rooms');
        if ($json === false || $json === '') $json = '[]';
        $list = json_decode((string)$json, true);
        return is_array($list) ? $list : [];
    }
    
    public function GetDevices(): array
    {
        $allDevices = [];
        $mappings = [
            'DevicesSwitch', 'DevicesSocket', 'DevicesLight', 'DevicesLightDimmer',
            'DevicesLightColor', 'DevicesBlind', 'DevicesThermostat',
            'DevicesWallSwitch', 'DevicesMotionSensor', 'DevicesContactSensor', 'DevicesSmokeSensor', 'DevicesAlarmSensor', 'DevicesGenericSensor', 'DevicesEvent'
        ];

        foreach ($mappings as $propName) {
            $json = @$this->ReadPropertyString($propName);
            if ($json === false || $json === '') $json = '[]';
            $list = json_decode((string)$json, true);
            if (is_array($list)) {
                foreach ($list as $dev) {
                    $dev['Type'] = $propName;
                    $allDevices[] = $dev;
                }
            }
        }

        // Merge auto-registered devices
        $autoJson = @$this->ReadAttributeString('AutoRegisteredDevices');
        if ($autoJson === false || $autoJson === '') $autoJson = '[]';
        $autoDevices = json_decode((string)$autoJson, true);
        if (is_array($autoDevices)) {
            foreach ($autoDevices as $autoDev) {
                // Prüfe ob dieses Gerät schon manuell vorhanden ist (gleiche instanceID)
                $isDuplicate = false;
                $autoInstID = $autoDev['instanceID'] ?? 0;
                if ($autoInstID > 0) {
                    foreach ($allDevices as $manualDev) {
                        // Prüfe ob eine manuelle Variable-ID zur gleichen Instanz gehört
                        foreach ($manualDev as $key => $val) {
                            if (str_ends_with($key, '_VarID') && is_int($val) && $val > 0) {
                                $parentInst = @IPS_GetParent($val);
                                if ($parentInst === $autoInstID) {
                                    $isDuplicate = true;
                                    break 2;
                                }
                            }
                        }
                    }
                }
                if (!$isDuplicate) {
                    $autoDev['source'] = $autoDev['source'] ?? 'auto';
                    $allDevices[] = $autoDev;
                }
            }
        }

        return $allDevices;
    }

    public function GetDevicesByType(string $type): array
    {
        $list = [];
        $json = @$this->ReadPropertyString($type);
        if ($json === false || $json === '') {
            $json = '[]';
        }
        $primaryList = json_decode((string)$json, true);
        if (is_array($primaryList)) {
            foreach ($primaryList as $dev) {
                $dev['Type'] = $type;
                $list[] = $dev;
            }
        }

        // Feature: Dimmers and Color Lights can cascade down to simpler types
        $extraTypes = [];
        $requiredVar = '';
        
        if ($type === 'DevicesLight' || $type === 'DevicesSwitch') {
            $extraTypes = ['DevicesLightDimmer', 'DevicesLightColor'];
            $requiredVar = 'OnOff_VarID';
        } elseif ($type === 'DevicesLightDimmer') {
            $extraTypes = ['DevicesLightColor'];
            $requiredVar = 'Brightness_VarID';
        }

        if (!empty($extraTypes) && $requiredVar !== '') {
            foreach ($extraTypes as $eType) {
                $eJson = @$this->ReadPropertyString($eType);
                if ($eJson === false || $eJson === '') {
                    $eJson = '[]';
                }
                $eList = json_decode((string)$eJson, true);
                if (is_array($eList)) {
                    foreach ($eList as $dev) {
                        if (!empty($dev[$requiredVar]) && (int)$dev[$requiredVar] > 0) {
                            // Only add if not already in the list (prevent duplicates by ID)
                            $found = false;
                            foreach ($list as $existingDev) {
                                if (($existingDev['id'] ?? '') === ($dev['id'] ?? '')) {
                                    $found = true;
                                    break;
                                }
                            }
                            if (!$found) {
                                $dev['Type'] = $eType;
                                $list[] = $dev;
                            }
                        }
                    }
                }
            }
        }

        return $list;
    }

    public function GetDeviceVariables(string $deviceId): array
    {
        $devices = $this->GetDevices();
        foreach ($devices as $dev) {
            if (isset($dev['id']) && $dev['id'] === $deviceId) {
                $vars = [];
                $varFields = ['OnOff_VarID', 'Brightness_VarID', 'ColorRGB_VarID', 'ColorTemp_VarID', 'OpenClose_VarID', 'TempSet_VarID', 'Status_VarID', 'Lux_VarID', 'ActualTemp_VarID', 'BoostMode_VarID', 'ControlMode_VarID', 'Humidity_VarID', 'Power_VarID', 'Energy_VarID', 'Reachable_VarID', 'Battery_VarID'];
                foreach ($varFields as $field) {
                    if (isset($dev[$field]) && $dev[$field] > 0) {
                        $vars[$field] = (int)$dev[$field];
                    }
                }
                return $vars;
            }
        }
        return [];
    }

    public function CleanUpDeadLinks(): void
    {
        $varFields = ['OnOff_VarID', 'Brightness_VarID', 'ColorRGB_VarID', 'ColorTemp_VarID', 'OpenClose_VarID', 'TempSet_VarID', 'Status_VarID', 'Lux_VarID', 'Value_VarID', 'ActualTemp_VarID', 'BoostMode_VarID', 'ControlMode_VarID', 'Humidity_VarID', 'Power_VarID', 'Energy_VarID', 'Battery_VarID', 'Reachable_VarID'];
        
        $mappings = [
            'DevicesSwitch', 'DevicesSocket', 'DevicesLight', 'DevicesLightDimmer',
            'DevicesLightColor', 'DevicesBlind', 'DevicesThermostat',
            'DevicesWallSwitch', 'DevicesMotionSensor', 'DevicesContactSensor', 'DevicesSmokeSensor',
            'DevicesAlarmSensor', 'DevicesGenericSensor', 'DevicesHealth', 'DevicesEvent'
        ];

        $changes = 0;
        foreach ($mappings as $propName) {
            $json = @$this->ReadPropertyString($propName);
            if ($json === false || $json === '') $json = '[]';
            $list = json_decode((string)$json, true);
            if (is_array($list)) {
                $updated = false;
                foreach ($list as &$dev) {
                    foreach ($varFields as $varField) {
                        if (isset($dev[$varField])) {
                            $val = (int)$dev[$varField];
                            if ($val > 0 && !IPS_VariableExists($val)) {
                                $dev[$varField] = 0;
                                $updated = true;
                                $changes++;
                            }
                        }
                    }
                }
                unset($dev);
                if ($updated) {
                    IPS_SetProperty($this->InstanceID, $propName, json_encode(array_values($list)));
                }
            }
        }
        
        if ($changes > 0) {
            IPS_ApplyChanges($this->InstanceID);
            echo "Erfolg: Es wurden $changes tote Verknüpfungen (Leichen) bereinigt!";
        } else {
            echo "Alles sauber! Keine toten Verknüpfungen gefunden.";
        }
    }

    public function AutoRegister(string $deviceJSON): bool
    {
        $device = json_decode($deviceJSON, true);
        if (!is_array($device) || empty($device['instanceID'])) {
            return false;
        }
        
        $autoJson = @$this->ReadAttributeString('AutoRegisteredDevices');
        if ($autoJson === false || $autoJson === '') $autoJson = '[]';
        $autoDevices = json_decode((string)$autoJson, true);
        if (!is_array($autoDevices)) {
            $autoDevices = [];
        }
        
        $device['variables'] = $device['variables'] ?? [];
        $capabilities = $this->deriveCapabilities($device['variables']);
        $device = array_merge($device, $capabilities);
        
        $found = false;
        foreach ($autoDevices as &$existing) {
            if (($existing['instanceID'] ?? 0) === $device['instanceID']) {
                $existing = $device;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $autoDevices[] = $device;
        }
        
        $this->WriteAttributeString('AutoRegisteredDevices', json_encode(array_values($autoDevices)));
        return true;
    }

    public function AutoUnregister(int $instanceID): bool
    {
        $autoJson = @$this->ReadAttributeString('AutoRegisteredDevices');
        if ($autoJson === false || $autoJson === '') $autoJson = '[]';
        $autoDevices = json_decode((string)$autoJson, true);
        if (!is_array($autoDevices)) {
            return false;
        }
        
        $initialCount = count($autoDevices);
        $autoDevices = array_filter($autoDevices, function($device) use ($instanceID) {
            return ($device['instanceID'] ?? 0) !== $instanceID;
        });
        
        if (count($autoDevices) !== $initialCount) {
            $this->WriteAttributeString('AutoRegisteredDevices', json_encode(array_values($autoDevices)));
            return true;
        }
        return false;
    }

    public function ResolveLocation(string $path): string
    {
        $parts = explode('\\', $path);
        $floorLevel = $this->ReadPropertyInteger('LocationFloorLevel');
        $roomLevel = $this->ReadPropertyInteger('LocationRoomLevel');
        
        $floor = $parts[$floorLevel] ?? '';
        $room = $parts[$roomLevel] ?? '';
        
        return json_encode(['room' => $room, 'floor' => $floor]);
    }

    public function GetAutoRegistered(): string
    {
        return $this->ReadAttributeString('AutoRegisteredDevices');
    }

    private function deriveCapabilities(array $variables): array
    {
        return [
            'hasBattery'     => ($variables['Battery_VarID'] ?? 0) > 0,
            'hasReachable'   => ($variables['Reachable_VarID'] ?? 0) > 0,
            'hasOnOff'       => ($variables['OnOff_VarID'] ?? 0) > 0,
            'hasTemperature' => ($variables['Temperature_VarID'] ?? 0) > 0,
            'hasBrightness'  => ($variables['Brightness_VarID'] ?? 0) > 0,
            'hasPosition'    => ($variables['Position_VarID'] ?? 0) > 0,
            'hasHumidity'    => ($variables['Humidity_VarID'] ?? 0) > 0,
            'hasPower'       => ($variables['Power_VarID'] ?? 0) > 0,
            'hasStatus'      => ($variables['Status_VarID'] ?? 0) > 0,
        ];
    }

    /**
     * Gibt die SmartController-InstanceID dieses Hauses zurück.
     * Wird von RegistryAware_Trait über SDR_GetControllerID() aufgerufen.
     *
     * @return int ControllerID oder 0
     */
    public function GetControllerID(): int
    {
        $id = $this->ReadPropertyInteger('ControllerID');
        if ($id > 0 && @IPS_InstanceExists($id)) {
            return $id;
        }

        // Fallback: Wenn nur 1 Controller existiert, diesen verwenden
        $ids = @IPS_GetInstanceListByModuleID('{460D7C60-0766-4534-BFD8-5920737B1845}');
        if (is_array($ids) && count($ids) === 1) {
            return $ids[0];
        }

        return 0;
    }
}
