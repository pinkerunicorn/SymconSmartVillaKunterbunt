<?php

declare(strict_types=1);

class UniversalDeviceScanner extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        
        $this->RegisterPropertyInteger('RegistryID', 0);
        $this->RegisterPropertyString('ExcludeConfigurators', '[]');
        $this->RegisterPropertyString('ExcludeInstances', '[]');
        
        $this->RegisterVariableInteger('ScannedDevices', 'Gescannte Geräte', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'magnifying-glass'
        ], 1);
        $this->RegisterVariableInteger('RegisteredDevices', 'Registrierte Geräte', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'check'
        ], 2);
        $this->RegisterVariableInteger('SkippedDevices', 'Übersprungen (Wrapper/Trait)', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'forward'
        ], 3);
        $this->RegisterVariableString('LastScan', 'Letzter Scan', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'clock'
        ], 5);
        $this->RegisterVariableString('ScanLog', 'Scan-Protokoll', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'file-lines'
        ], 10);
    }
    
    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        
        $registryID = $this->ReadPropertyInteger('RegistryID');
        if ($registryID > 0 && @IPS_InstanceExists($registryID)) {
            $this->SetStatus(102);
        } else {
            $this->SetStatus(104);
        }
    }
    
    public function Scan(): string
    {
        $results = [];
        $scanned = 0;
        $skipped = 0;
        $registered = 0;
        
        $registryID = $this->ReadPropertyInteger('RegistryID');
        if ($registryID === 0 || !@IPS_InstanceExists($registryID)) {
            echo 'Keine Device Registry konfiguriert!';
            return '[]';
        }
        
        // Exclude-Liste laden
        $excludeJson = $this->ReadPropertyString('ExcludeConfigurators');
        $excludeList = json_decode($excludeJson, true) ?: [];
        $excludeIDs = array_column($excludeList, 'instanceID');
        
        // Bereits per Trait registrierte Instanzen laden (für Dedup)
        $autoRegistered = [];
        if (function_exists('SDR_GetAutoRegistered')) {
            $autoRegistered = json_decode(@SDR_GetAutoRegistered($registryID), true) ?: [];
        }
        
        $traitInstanceIDs = [];
        foreach ($autoRegistered as $dev) {
            if (($dev['source'] ?? '') === 'trait') {
                $traitInstanceIDs[] = $dev['instanceID'] ?? 0;
            }
        }
        
        // Alle Konfiguratoren finden (ModuleType 4)
        $configurators = $this->findAllConfigurators();
        $log = [];
        
        foreach ($configurators as $conf) {
            if (in_array($conf['instanceID'], $excludeIDs)) {
                $log[] = 'SKIP Konfigurator: ' . $conf['name'] . ' (ausgeschlossen)';
                continue;
            }
            
            $log[] = 'Scanne: ' . $conf['name'] . ' (' . $conf['moduleName'] . ')';
            
            // Geraete vom Konfigurator holen
            $devices = $this->getDevicesFromConfigurator($conf['instanceID']);
            
            foreach ($devices as $device) {
                $instanceID = $device['instanceID'] ?? 0;
                if ($instanceID === 0 || !@IPS_InstanceExists($instanceID)) {
                    continue; // Gerät nicht in Symcon angelegt
                }
                
                $scanned++;
                
                // Dedup: Bereits per Trait registriert?
                if (in_array($instanceID, $traitInstanceIDs)) {
                    $skipped++;
                    $log[] = '  SKIP (Trait): ' . ($device['name'] ?? IPS_GetName($instanceID));
                    $results[] = [
                        'configurator' => $conf['name'],
                        'name'         => $device['name'] ?? IPS_GetName($instanceID),
                        'type'         => 'trait_managed',
                        'room'         => '',
                        'floor'        => '',
                        'hasBattery'   => '',
                        'hasReachable' => '',
                        'source'       => 'trait',
                        'status'       => 'Eigenes Modul'
                    ];
                    continue;
                }
                
                // Variablen der Instanz mappen
                $idents = $this->collectInstanceIdents($instanceID);
                
                // Für HM-IP: Maintenance-Kanal suchen (:0 hat UNREACH, LOW_BAT)
                $maintenanceID = $this->findMaintenanceChannel($instanceID);
                if ($maintenanceID !== null) {
                    $maintIdents = $this->collectInstanceIdents($maintenanceID);
                    // Maintenance-Idents übernehmen wenn nicht schon vorhanden
                    foreach ($maintIdents as $ident => $varID) {
                        if (!isset($idents[$ident])) {
                            $idents[$ident] = $varID;
                        }
                    }
                }
                
                // Typ erkennen
                $deviceName = $device['name'] ?? IPS_GetName($instanceID);
                $deviceType = $this->detectDeviceType($idents, $deviceName);
                $variables = $this->mapVariablesByType($idents, $deviceType);
                
                // Standort auflösen
                $location = IPS_GetLocation($instanceID);
                $resolved = [];
                if (function_exists('SDR_ResolveLocation')) {
                    $resolved = json_decode(@SDR_ResolveLocation($registryID, $location), true) ?: [];
                }
                $room = $resolved['room'] ?? '';
                $floor = $resolved['floor'] ?? '';
                
                $registered++;
                
                $results[] = [
                    'configurator' => $conf['name'],
                    'name'         => $deviceName,
                    'type'         => $deviceType,
                    'room'         => $room,
                    'floor'        => $floor,
                    'hasBattery'   => ($variables['Battery_VarID'] ?? 0) > 0 ? 'Ja' : '',
                    'hasReachable' => ($variables['Reachable_VarID'] ?? 0) > 0 ? 'Ja' : '',
                    'source'       => 'scan',
                    'status'       => 'Gefunden',
                    // Interne Daten für RegisterAll:
                    'instanceID'   => $instanceID,
                    'variables'    => $variables,
                    'location'     => $location,
                ];
                
                $log[] = '  OK: ' . $deviceName . ' -> ' . $deviceType . ' (' . $room . ')';
            }
        }
        
        $this->SetValue('ScannedDevices', $scanned);
        $this->SetValue('RegisteredDevices', $registered);
        $this->SetValue('SkippedDevices', $skipped);
        $this->SetValue('LastScan', date('d.m.Y H:i:s'));
        $this->SetValue('ScanLog', implode("\n", $log));
        
        $this->UpdateFormField('ScanResults', 'values', json_encode($results));
        
        echo 'Scan abgeschlossen: ' . $scanned . ' gescannt, ' . $registered . ' gefunden, ' . $skipped . ' übersprungen.';
        return json_encode($results);
    }
    
    public function RegisterAll(): void
    {
        $registryID = $this->ReadPropertyInteger('RegistryID');
        if ($registryID === 0) {
            echo 'Keine Device Registry konfiguriert!';
            return;
        }
        
        // Letztes Scan-Ergebnis erneut erzeugen
        $resultJson = $this->Scan();
        $results = json_decode($resultJson, true) ?: [];
        
        $count = 0;
        foreach ($results as $result) {
            if (($result['source'] ?? '') !== 'scan') continue;
            $instanceID = $result['instanceID'] ?? 0;
            if ($instanceID === 0) continue;
            
            $payload = json_encode([
                'instanceID'  => $instanceID,
                'moduleGUID'  => @IPS_GetInstance($instanceID)['ModuleInfo']['ModuleID'] ?? '',
                'type'        => $result['type'],
                'name'        => $result['name'],
                'location'    => $result['location'] ?? '',
                'room'        => $result['room'],
                'floor'       => $result['floor'],
                'variables'   => $result['variables'] ?? [],
                'source'      => 'scan',
            ]);
            
            if (function_exists('SDR_AutoRegister') && @SDR_AutoRegister($registryID, $payload)) {
                $count++;
            }
        }
        
        echo $count . ' Geräte erfolgreich in der Device Registry registriert.';
    }
    
    private function findAllConfigurators(): array
    {
        $configurators = [];
        $instanceList = @IPS_GetInstanceList();
        if (!is_array($instanceList)) return [];
        
        foreach ($instanceList as $instID) {
            $inst = @IPS_GetInstance($instID);
            if (!$inst) continue;
            
            $module = $inst['ModuleInfo'] ?? null;
            if ($module && isset($module['ModuleType']) && $module['ModuleType'] === 4) {
                $configurators[] = [
                    'instanceID' => $instID,
                    'name'       => IPS_GetName($instID),
                    'moduleGUID' => $module['ModuleID'] ?? '',
                    'moduleName' => $module['ModuleName'] ?? '',
                ];
            }
        }
        return $configurators;
    }
    
    private function getDevicesFromConfigurator(int $configuratorID): array
    {
        $formJson = @IPS_GetConfigurationForm($configuratorID);
        if ($formJson === false || $formJson === '') return [];
        $form = json_decode($formJson, true);
        if (!is_array($form)) return [];
        
        // Suche das Configurator-Element in elements UND actions
        $searchIn = array_merge($form['elements'] ?? [], $form['actions'] ?? []);
        foreach ($searchIn as $el) {
            if (($el['type'] ?? '') === 'Configurator') {
                return $el['values'] ?? [];
            }
        }
        return [];
    }
    
    private function collectInstanceIdents(int $instanceID): array
    {
        $idents = []; // ['IDENT' => childVarID, ...]
        $children = @IPS_GetChildrenIDs($instanceID);
        if (!is_array($children)) return $idents;
        
        foreach ($children as $childID) {
            $obj = @IPS_GetObject($childID);
            if (!is_array($obj) || ($obj['ObjectType'] ?? -1) !== 2) continue;
            $ident = $obj['ObjectIdent'] ?? '';
            if ($ident !== '') {
                $idents[$ident] = $childID;
            }
        }
        return $idents;
    }

    private function detectDeviceType(array $idents, string $name): string
    {
        // 1. HM-IP Präfix-Matching (höchste Priorität)
        $typeMap = [
            'HmIP-SWDO'  => 'DevicesContactSensor', 'HmIP-SWDM'  => 'DevicesContactSensor',
            'HmIP-SRH'   => 'DevicesContactSensor',
            'HmIP-SMI'   => 'DevicesMotionSensor',   'HmIP-SMO'   => 'DevicesMotionSensor',
            'HmIP-SPI'   => 'DevicesMotionSensor',
            'HmIP-eTRV'  => 'DevicesThermostat',     'HmIP-STHD'  => 'DevicesThermostat',
            'HmIP-STH'   => 'DevicesThermostat',     'HmIP-WTH'   => 'DevicesThermostat',
            'HmIP-FALMOT'=> 'DevicesThermostat',
            'HmIP-BSM'   => 'DevicesSocket',         'HmIP-FSM'   => 'DevicesSocket',
            'HmIP-PS'    => 'DevicesSwitch',          'HmIP-PSM'   => 'DevicesSocket',
            'HmIP-BSL'   => 'DevicesSwitch',
            'HmIP-BDT'   => 'DevicesLightDimmer',    'HmIP-FDT'   => 'DevicesLightDimmer',
            'HmIP-PDT'   => 'DevicesLightDimmer',
            'HmIP-BROLL' => 'DevicesBlind',           'HmIP-FROLL' => 'DevicesBlind',
            'HmIP-BBL'   => 'DevicesBlind',           'HmIP-FBL'   => 'DevicesBlind',
            'HmIP-HDM'   => 'DevicesBlind',
            'HmIP-SWSD'  => 'DevicesSmokeSensor',
            'HmIP-SWD'   => 'DevicesAlarmSensor',
            'HmIP-SWO'   => 'DevicesGenericSensor',
            'HmIP-STHO'  => 'DevicesGenericSensor',
            'HmIP-DLD'   => 'DevicesSwitch',
            'HmIP-WRC2'  => 'DevicesWallSwitch',      'HmIP-WRC6'  => 'DevicesWallSwitch',
            'HmIP-WRCD'  => 'DevicesWallSwitch',      'HmIP-WRCR'  => 'DevicesWallSwitch',
            'HmIP-KRCA'  => 'DevicesWallSwitch',      'HmIP-KRC4'  => 'DevicesWallSwitch',
            'HmIP-ASIR'  => 'DevicesAlarmSensor',     'HmIP-MP3P'  => 'DevicesGenericSensor',
        ];
        
        foreach ($typeMap as $prefix => $type) {
            if (str_contains($name, $prefix)) return $type;
        }
        
        // 2. Ident-basierter Fallback (eindeutige Idents zuerst)
        if (isset($idents['SET_POINT_TEMPERATURE']) || isset($idents['ACTUAL_TEMPERATURE']) && isset($idents['HUMIDITY'])) return 'DevicesThermostat';
        if (isset($idents['SMOKE_DETECTOR_ALARM_STATUS'])) return 'DevicesSmokeSensor';
        if (isset($idents['MOISTURE_DETECTED'])) return 'DevicesAlarmSensor';
        if (isset($idents['MOTION']) || isset($idents['MOTION_DETECTION']) || isset($idents['PRESENCE_DETECTION'])) return 'DevicesMotionSensor';
        if (isset($idents['LEVEL']) && !isset($idents['STATE'])) return 'DevicesBlind'; // Nur LEVEL ohne STATE = Jalousie
        if (isset($idents['LEVEL']) && isset($idents['STATE'])) return 'DevicesLightDimmer'; // LEVEL + STATE = Dimmer
        if (isset($idents['STATE']) && !isset($idents['LEVEL'])) {
            // STATE ohne LEVEL: Kontakt oder Schalter?
            // Wenn der Name auf Kontakt/Fenster/Tür hindeutet -> Kontakt
            if (preg_match('/(?:Fenster|T\x{00fc}r|Door|Window|Kontakt|Contact|SRH|SWDO|SWDM)/iu', $name)) {
                return 'DevicesContactSensor';
            }
            return 'DevicesSwitch'; // Default für STATE-only
        }
        if (isset($idents['WIND_SPEED']) || isset($idents['RAINING'])) return 'DevicesGenericSensor';
        
        return 'DevicesGenericSensor';
    }

    private function mapVariablesByType(array $idents, string $deviceType): array
    {
        $vars = [];
        
        // Typ-spezifische Mappings
        $typeMappings = [
            'DevicesSwitch' => [
                'OnOff_VarID' => ['STATE', 'SWITCH', 'Power', 'Status'],
            ],
            'DevicesSocket' => [
                'OnOff_VarID' => ['STATE', 'SWITCH'],
                'Power_VarID' => ['POWER', 'CURRENT_POWER'],
                'Energy_VarID' => ['ENERGY_COUNTER'],
            ],
            'DevicesContactSensor' => [
                'OpenClose_VarID' => ['STATE'],
            ],
            'DevicesMotionSensor' => [
                'Motion_VarID' => ['MOTION', 'MOTION_DETECTION', 'PRESENCE_DETECTION'],
                'Illumination_VarID' => ['ILLUMINATION', 'CURRENT_ILLUMINATION'],
            ],
            'DevicesLightDimmer' => [
                'OnOff_VarID' => ['STATE'],
                'Brightness_VarID' => ['LEVEL', 'Brightness'],
            ],
            'DevicesLightColor' => [
                'OnOff_VarID' => ['STATE', 'SWITCH'],
                'Brightness_VarID' => ['LEVEL', 'Brightness'],
            ],
            'DevicesBlind' => [
                'Position_VarID' => ['LEVEL', 'Position', 'Shutter'],
            ],
            'DevicesThermostat' => [
                'Temperature_VarID' => ['ACTUAL_TEMPERATURE', 'TEMPERATURE'],
                'SetPoint_VarID' => ['SET_POINT_TEMPERATURE', 'SET_TEMPERATURE'],
                'Humidity_VarID' => ['HUMIDITY'],
            ],
            'DevicesSmokeSensor' => [
                'Smoke_VarID' => ['SMOKE_DETECTOR_ALARM_STATUS'],
            ],
            'DevicesAlarmSensor' => [
                'Status_VarID' => ['STATE', 'ALARMSTATE', 'MOISTURE_DETECTED'],
            ],
            'DevicesWallSwitch' => [
                'Status_VarID' => ['PRESS_SHORT', 'PRESS_LONG'],
            ],
            'DevicesGenericSensor' => [
                'Temperature_VarID' => ['ACTUAL_TEMPERATURE', 'TEMPERATURE'],
                'Humidity_VarID' => ['HUMIDITY'],
                'Status_VarID' => ['STATE', 'Status'],
            ],
            'DevicesHealth' => [
                'Status_VarID' => ['STATE', 'Status'],
            ],
        ];
        
        $mapping = $typeMappings[$deviceType] ?? $typeMappings['DevicesGenericSensor'];
        
        foreach ($mapping as $varKey => $identCandidates) {
            foreach ($identCandidates as $ident) {
                if (isset($idents[$ident])) {
                    $vars[$varKey] = $idents[$ident];
                    break;
                }
            }
        }
        
        // Generische Mappings für alle Typen
        $genericMappings = [
            'Battery_VarID'   => ['LOW_BAT', 'OPERATING_VOLTAGE', 'BatteryLevel', 'Battery'],
            'Reachable_VarID' => ['UNREACH'],
        ];
        
        foreach ($genericMappings as $varKey => $identCandidates) {
            foreach ($identCandidates as $ident) {
                if (isset($idents[$ident])) {
                    $vars[$varKey] = $idents[$ident];
                    break;
                }
            }
        }
        
        if (isset($vars['Reachable_VarID'])) {
            $vars['reachableInverted'] = true;
        }
        
        return $vars;
    }
    
    private function findMaintenanceChannel(int $instanceID): ?int
    {
        $hmDeviceGUID = '{EE4A81C6-5C90-4DB7-AD2F-F6BBD521412E}';
        $modInfo = @IPS_GetInstance($instanceID)['ModuleInfo']['ModuleID'] ?? '';
        if ($modInfo !== $hmDeviceGUID) return null;

        $address = @IPS_GetProperty($instanceID, 'Address');
        if (!is_string($address) || !str_contains($address, ':')) return null;
        
        $baseAddress = explode(':', $address)[0];
        $maintenanceAddress = $baseAddress . ':0';
        
        $instances = @IPS_GetInstanceListByModuleID($hmDeviceGUID);
        if (!is_array($instances)) return null;
        
        foreach ($instances as $instID) {
            if (@IPS_GetProperty($instID, 'Address') === $maintenanceAddress) {
                return $instID;
            }
        }
        return null;
    }
    
    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        return json_encode($form);
    }
}
