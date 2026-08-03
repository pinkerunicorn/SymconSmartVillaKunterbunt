<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class SmartDeviceMonitor extends IPSModuleStrict
{
    use SmartLog_Trait;
    use DeviceAvailability_Trait;

    public function Create(): void
    {
        parent::Create();
        $this->DA_RegisterAvailability(900);

        // Properties
        $this->RegisterPropertyInteger('TargetNotifier', 0);
        $this->RegisterPropertyInteger('LowBatteryThreshold', 15);
        $this->RegisterPropertyString('BatteryList', '[]');
        $this->RegisterPropertyString('OfflineList', '[]');
        
        // Legacy properties to prevent errors on update
        $this->RegisterPropertyString('CustomVariables', '[]');
        $this->RegisterPropertyString('CustomBatteryVariables', '[]');
        $this->RegisterPropertyString('CustomOfflineVariables', '[]');

        // Variablen (Status)
        $this->RegisterVariableInteger('LowBatteryCount', 'Schwache Batterien', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Battery'], 1);
        $this->RegisterVariableInteger('OfflineDeviceCount', 'Offline Geräte', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Warning'], 2);
        
        $this->RegisterVariableString('SummaryText', 'Status Zusammenfassung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Information'
        ], 3);

        $this->RegisterVariableString('MonitoredListHTML', 'Überwachte Geräte (Übersicht)', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Database'
        ], 10);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();
        
        $this->SetVisualizationType(1);
        $this->SetStatus(102);

        // Alte Registrierungen löschen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        $monitoredVars = [];

        $batteryList = json_decode($this->ReadPropertyString('BatteryList'), true) ?: [];
        foreach ($batteryList as $item) {
            if (!empty($item['Active']) && !empty($item['VariableID']) && IPS_VariableExists((int)$item['VariableID'])) {
                $monitoredVars[] = (int)$item['VariableID'];
            }
        }

        $offlineList = json_decode($this->ReadPropertyString('OfflineList'), true) ?: [];
        foreach ($offlineList as $item) {
            if (!empty($item['Active']) && !empty($item['VariableID']) && IPS_VariableExists((int)$item['VariableID'])) {
                $monitoredVars[] = (int)$item['VariableID'];
            }
        }

        $monitoredVars = array_unique($monitoredVars);

        foreach ($monitoredVars as $vid) {
            $this->RegisterMessage($vid, VM_UPDATE);
        }

        $this->CheckHealth(false);
    }

    public function ScanDevices(): void
    {
        $existingBatteries = json_decode($this->ReadPropertyString('BatteryList'), true) ?: [];
        $existingOffline = json_decode($this->ReadPropertyString('OfflineList'), true) ?: [];
        
        $batteryMap = [];
        foreach ($existingBatteries as $b) {
            if (!empty($b['VariableID'])) {
                $batteryMap[$b['VariableID']] = $b;
            }
        }
        
        $offlineMap = [];
        foreach ($existingOffline as $o) {
            if (!empty($o['VariableID'])) {
                $offlineMap[$o['VariableID']] = $o;
            }
        }

        // Migrate legacy custom lists just in case
        $legacyBattery = json_decode($this->ReadPropertyString('CustomBatteryVariables'), true) ?: [];
        foreach ($legacyBattery as $lb) {
            $vid = (int)($lb['VariableID'] ?? 0);
            if ($vid > 0 && !isset($batteryMap[$vid])) {
                $batteryMap[$vid] = ['Active' => true, 'Name' => $this->GetReadableDeviceName($vid), 'VariableID' => $vid];
            }
        }
        $legacyOffline = json_decode($this->ReadPropertyString('CustomOfflineVariables'), true) ?: [];
        foreach ($legacyOffline as $lo) {
            $vid = (int)($lo['VariableID'] ?? 0);
            if ($vid > 0 && !isset($offlineMap[$vid])) {
                $offlineMap[$vid] = ['Active' => true, 'Name' => $this->GetReadableDeviceName($vid), 'VariableID' => $vid];
            }
        }

        $varIDs = IPS_GetVariableList();
        foreach ($varIDs as $vid) {
            $obj = IPS_GetObject($vid);
            if ($obj['ParentID'] == $this->InstanceID) {
                unset($batteryMap[$vid]);
                unset($offlineMap[$vid]);
                continue;
            }
            
            $ident = strtoupper($obj['ObjectIdent']);
            $varName = $obj['ObjectName'];
            $var = IPS_GetVariable($vid);
            $profile = $var['VariableCustomProfile'] !== '' ? $var['VariableCustomProfile'] : $var['VariableProfile'];

            $isBattery = false;
            if (in_array($ident, ['LOWBAT', 'LOW_BAT', 'BATTERY', 'BATTERY_STATE', 'BATTERY_LEVEL', 'BATTERIE', 'OPERATINGVOLTAGE'], true)) {
                $isBattery = true;
            } elseif ($profile !== '' && (stripos($profile, 'battery') !== false || stripos($profile, 'batterie') !== false)) {
                $isBattery = true;
            } elseif (stripos($varName, 'batterie') !== false || stripos($varName, 'battery') !== false || stripos($varName, 'lowbat') !== false) {
                $isBattery = true;
            }

            if ($isBattery && !isset($batteryMap[$vid])) {
                $batteryMap[$vid] = [
                    'Active' => true,
                    'Name' => $this->GetReadableDeviceName($vid),
                    'VariableID' => $vid
                ];
            }

            $isOffline = false;
            if (in_array($ident, ['UNREACH', 'STICKY_UNREACH', 'DEVICEAVAILABLE', 'OFFLINE'], true)) {
                $isOffline = true;
            }

            if ($isOffline && !isset($offlineMap[$vid])) {
                $offlineMap[$vid] = [
                    'Active' => true,
                    'Name' => $this->GetReadableDeviceName($vid),
                    'VariableID' => $vid
                ];
            }
        }

        $newBatteryList = array_values($batteryMap);
        $newOfflineList = array_values($offlineMap);

        // Sort by name alphabetically
        usort($newBatteryList, function($a, $b) { return strcasecmp($a['Name'], $b['Name']); });
        usort($newOfflineList, function($a, $b) { return strcasecmp($a['Name'], $b['Name']); });

        IPS_SetProperty($this->InstanceID, 'BatteryList', json_encode($newBatteryList));
        IPS_SetProperty($this->InstanceID, 'OfflineList', json_encode($newOfflineList));
        
        // Clear legacy
        IPS_SetProperty($this->InstanceID, 'CustomBatteryVariables', '[]');
        IPS_SetProperty($this->InstanceID, 'CustomOfflineVariables', '[]');
        
        if (IPS_HasChanges($this->InstanceID)) {
            IPS_ApplyChanges($this->InstanceID);
            echo "Scan abgeschlossen. Neue Geräte wurden hinzugefügt!\n";
        } else {
            // Selbst wenn keine neuen Geräte gefunden wurden, aktualisieren wir die GUI-Variablen (HTML-Tabelle etc.)
            $this->CheckHealth(false);
            echo "Scan abgeschlossen. Keine neuen Geräte gefunden.\n";
        }
    }

    private function GetReadableDeviceName(int $vid): string {
        $fullLoc = IPS_GetLocation($vid);
        $pathParts = explode('\\', $fullLoc);
        array_pop($pathParts);
        if (count($pathParts) > 3) {
            $pathParts = array_slice($pathParts, -3);
        }
        return trim(implode(' / ', $pathParts));
    }

    public function GetVisualizationTile(): string
    {
        $batCount = $this->GetValue('LowBatteryCount');
        $offCount = $this->GetValue('OfflineDeviceCount');
        $htmlList = $this->GetValue('MonitoredListHTML');
        
        $statusStyle = ($batCount > 0 || $offCount > 0) ? 'color: #ff3333; font-weight: bold;' : 'color: #33cc33; font-weight: bold;';
        $statusText = ($batCount > 0 || $offCount > 0) ? 'Fehlerhafte Geräte gefunden!' : 'Alles in bester Ordnung.';

        return <<<HTML
<div style="font-family: sans-serif; padding: 10px;">
    <h2>Smart Device Monitor</h2>
    <p>Übersicht über leere Batterien & Offline-Geräte.</p>
    
    <div style="background-color: rgba(255,255,255,0.05); padding: 15px; border-radius: 8px; margin-bottom: 20px;">
        <span style="{$statusStyle}">{$statusText}</span><br>
        Schwache Batterien: <b>{$batCount}</b> | Offline Geräte: <b>{$offCount}</b>
    </div>

    <h3>Detail-Übersicht</h3>
    <div style="background-color: rgba(0,0,0,0.2); padding: 10px; border-radius: 8px; overflow-x: auto; max-height: 400px; overflow-y: auto;">
        {$htmlList}
    </div>
</div>
HTML;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "SelectInstance",
            "name": "TargetNotifier",
            "caption": "SmartNotifier Instanz"
        },
        {
            "type": "NumberSpinner",
            "name": "LowBatteryThreshold",
            "caption": "Batterie Warnschwelle (%)",
            "minimum": 1,
            "maximum": 50
        },
        {
            "type": "List",
            "name": "BatteryList",
            "caption": "Überwachte Batterien",
            "rowCount": 20,
            "add": true,
            "delete": true,
            "columns": [
                {
                    "name": "Active",
                    "caption": "Aktiv",
                    "type": "CheckBox",
                    "width": "80px",
                    "add": true,
                    "edit": { "type": "CheckBox" }
                },
                {
                    "name": "Name",
                    "caption": "Geräte-Pfad",
                    "type": "ValidationTextBox",
                    "width": "auto",
                    "add": "",
                    "edit": { "type": "ValidationTextBox" }
                },
                {
                    "name": "VariableID",
                    "caption": "Variable",
                    "type": "SelectVariable",
                    "width": "250px",
                    "add": 0,
                    "edit": { "type": "SelectVariable" }
                }
            ]
        },
        {
            "type": "List",
            "name": "OfflineList",
            "caption": "Überwachte Geräte (On/Offline)",
            "rowCount": 20,
            "add": true,
            "delete": true,
            "columns": [
                {
                    "name": "Active",
                    "caption": "Aktiv",
                    "type": "CheckBox",
                    "width": "80px",
                    "add": true,
                    "edit": { "type": "CheckBox" }
                },
                {
                    "name": "Name",
                    "caption": "Geräte-Pfad",
                    "type": "ValidationTextBox",
                    "width": "auto",
                    "add": "",
                    "edit": { "type": "ValidationTextBox" }
                },
                {
                    "name": "VariableID",
                    "caption": "Variable",
                    "type": "SelectVariable",
                    "width": "250px",
                    "add": 0,
                    "edit": { "type": "SelectVariable" }
                }
            ]
        }
    ],
    "actions": [
        {
            "type": "Button",
            "caption": "Geräte suchen & Listen aktualisieren",
            "onClick": "echo SDM_ScanDevices($id);"
        }
    ]
}
EOT;
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE) {
            // Nur reagieren, wenn sich der Wert auch WIRKLICH geändert hat
            if (isset($Data[1]) && $Data[1] === false) {
                return;
            }
            $this->CheckHealth(true);
        }
    }

    public function CheckHealth(bool $triggerNotification = false): void
    {
        $threshold = $this->ReadPropertyInteger('LowBatteryThreshold');
        $lowBatteries = [];
        $offlineDevices = [];
        $htmlRowsBattery = [];
        $htmlRowsOffline = [];

        $batteryList = json_decode($this->ReadPropertyString('BatteryList'), true) ?: [];
        foreach ($batteryList as $item) {
            if (empty($item['Active']) || empty($item['VariableID'])) continue;
            $vid = (int)$item['VariableID'];
            if (!IPS_VariableExists($vid)) continue;
            
            $deviceName = $item['Name'];
            $varName = IPS_GetName($vid);
            $val = GetValue($vid);
            $statusText = 'BATTERIE OK';
            $statusColor = '#00FF00';

            if (is_numeric($val) && $val < $threshold) {
                $lowBatteries[] = "$deviceName ($val %)";
                $statusText = "BATTERIE NIEDRIG ($val %)";
                $statusColor = '#FF0000';
            } elseif (is_bool($val) && $val === true) {
                $lowBatteries[] = "$deviceName ($varName)";
                $statusText = 'BATTERIE SCHWACH';
                $statusColor = '#FF0000';
            } elseif (is_bool($val) && $val === false) {
                $statusText = 'BATTERIE OK';
            } elseif (!is_bool($val) && !is_numeric($val)) {
                // z.B. String "NORMAL"
                $statusText = "BATTERIE OK ($val)";
            }
            
            $htmlRowsBattery[] = "<tr><td style='width:50%;'><b>$deviceName</b></td><td style='width:30%;'>$varName</td><td style='width:20%; color:$statusColor;'><b>$statusText</b></td></tr>";
        }

        $offlineList = json_decode($this->ReadPropertyString('OfflineList'), true) ?: [];
        foreach ($offlineList as $item) {
            if (empty($item['Active']) || empty($item['VariableID'])) continue;
            $vid = (int)$item['VariableID'];
            if (!IPS_VariableExists($vid)) continue;
            
            $deviceName = $item['Name'];
            $varName = IPS_GetName($vid);
            $ident = strtoupper(IPS_GetObject($vid)['ObjectIdent']);
            $val = GetValue($vid);
            $statusText = 'ONLINE';
            $statusColor = '#00FF00';

            // DeviceAvailable = false is offline. Unreach = true is offline.
            $isOffline = false;
            if ($ident === 'DEVICEAVAILABLE' && $val === false) {
                $isOffline = true;
            } elseif ($ident !== 'DEVICEAVAILABLE' && $val === true) {
                $isOffline = true;
            }

            if ($isOffline) {
                $offlineDevices[] = "$deviceName ($varName)";
                $statusText = 'OFFLINE';
                $statusColor = '#FF9900';
            }
            
            $htmlRowsOffline[] = "<tr><td style='width:50%;'><b>$deviceName</b></td><td style='width:30%;'>$varName</td><td style='width:20%; color:$statusColor;'><b>$statusText</b></td></tr>";
        }

        $batCount = count($lowBatteries);
        $offCount = count($offlineDevices);

        $this->SetValue('LowBatteryCount', $batCount);
        $this->SetValue('OfflineDeviceCount', $offCount);

        $summary = [];
        if ($batCount > 0) {
            $summary[] = "Batterien leer ($batCount): " . implode(', ', $lowBatteries);
        }
        if ($offCount > 0) {
            $summary[] = "Offline Geräte ($offCount): " . implode(', ', $offlineDevices);
        }

        $text = count($summary) > 0 ? implode(' | ', $summary) : 'Alle Geräte betriebsbereit.';
        $oldText = $this->GetValue('SummaryText');
        $this->SetValue('SummaryText', $text);
        
        $hasChanged = ($text !== $oldText);

        $buildTable = function($title, $rows) {
            $t = "<div style='margin-top: 10px; margin-bottom: 5px; padding-bottom: 2px; border-bottom: 1px solid #555; color: #ddd; font-weight: bold; text-transform: uppercase;'>$title</div>";
            $t .= "<table style='width:100%; border-collapse:collapse; margin-bottom: 15px;'>";
            if (count($rows) > 0) {
                $t .= implode('', $rows);
            } else {
                $t .= "<tr><td colspan='3' style='color:#00FF00;'>Alle in Ordnung bzw. keine Geräte zur Überwachung aktiviert.</td></tr>";
            }
            $t .= "</table>";
            return $t;
        };

        $html = $buildTable('Erreichbarkeit (On/Offline)', $htmlRowsOffline);
        $html .= $buildTable('Batteriestatus', $htmlRowsBattery);
        $this->SetValue('MonitoredListHTML', $html);

        if ($triggerNotification && $hasChanged && (count($lowBatteries) > 0 || count($offlineDevices) > 0)) {
            $notifierId = $this->ReadPropertyInteger('TargetNotifier');
            if ($notifierId > 0 && @IPS_InstanceExists($notifierId)) {
                $payload = json_encode([
                    'Title' => 'Geräteüberwachung',
                    'Message' => $text,
                    'Priority' => 1
                ]);
                @IPS_RunScriptText("NOTIFY_SendEvent($notifierId, " . var_export($payload, true) . ");");
            }
        }
    }
}