<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class SmartMonitorDevice extends IPSModuleStrict
{
    use SmartLog_Trait;
    use DeviceAvailability_Trait;

    public function Create(): void
    {
        parent::Create();
        $this->DA_RegisterAvailability(900);

        // Properties
        $this->RegisterPropertyInteger('RegistryID', 0);
        $this->RegisterPropertyInteger('TargetNotifier', 0);
        $this->RegisterPropertyInteger('LowBatteryThreshold', 15);
        $this->RegisterPropertyInteger('BackupVariableID', 0);

        // Legacy properties (kept to prevent errors on update, no longer used)
        $this->RegisterPropertyString('BatteryList', '[]');
        $this->RegisterPropertyString('OfflineList', '[]');
        $this->RegisterPropertyString('CustomVariables', '[]');
        $this->RegisterPropertyString('CustomBatteryVariables', '[]');
        $this->RegisterPropertyString('CustomOfflineVariables', '[]');

        // Status variables
        $this->RegisterVariableInteger('LowBatteryCount', 'Schwache Batterien', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Battery'
        ], 1);
        $this->RegisterVariableInteger('OfflineDeviceCount', 'Offline Geraete', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Warning'
        ], 2);
        $this->RegisterVariableInteger('OrphanedVarCount', 'Verwaiste Variablen', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Alert'
        ], 3);
        $this->RegisterVariableString('SummaryText', 'Status Zusammenfassung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Information'
        ], 4);
        $this->RegisterVariableString('MonitoredListHTML', 'Ueberwachte Geraete (Uebersicht)', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Database'
        ], 10);

        // Health-check timer (every 30 minutes)
        $this->RegisterTimer('HealthCheckTimer', 0, 'SMD_CheckHealth($_IPS[\'TARGET\'], false);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        $this->SetVisualizationType(1);
        $this->SetStatus(102);

        // Clear old message subscriptions
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        // Clear old references
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }

        $registryID = $this->ReadPropertyInteger('RegistryID');
        if ($registryID > 1 && @IPS_ObjectExists($registryID)) {
            $this->RegisterReference($registryID);
        }

        // Subscribe to all Reachable_VarID and Battery_VarID from registry
        $this->RegisterRegistryMessages($registryID);

        $backupVid = $this->ReadPropertyInteger('BackupVariableID');
        if ($backupVid > 1 && @IPS_VariableExists($backupVid)) {
            $this->RegisterMessage($backupVid, VM_UPDATE);
            $this->RegisterReference($backupVid);
        }

        // Enable timer
        $this->SetTimerInterval('HealthCheckTimer', 30 * 60 * 1000);

        $this->CheckHealth(false);
        $this->DA_SetAvailable(true);
    }

    private function RegisterRegistryMessages(int $registryID): void
    {
        if ($registryID <= 1 || !@IPS_ObjectExists($registryID) || !function_exists('SDR_GetDevices')) {
            return;
        }

        $allDevices = @SDR_GetDevices($registryID);
        if (!is_array($allDevices)) return;

        foreach ($allDevices as $dev) {
            $vid = (int)($dev['Reachable_VarID'] ?? 0);
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $this->RegisterMessage($vid, VM_UPDATE);
                $this->RegisterReference($vid);
            }
            $vid = (int)($dev['Battery_VarID'] ?? 0);
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $this->RegisterMessage($vid, VM_UPDATE);
                $this->RegisterReference($vid);
            }
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE) {
            // Only react if value actually changed
            if (isset($Data[1]) && $Data[1] === false) {
                return;
            }
            $this->CheckHealth(true);
        }
    }

    public function CheckHealth(bool $triggerNotification = false): void
    {
        $threshold = $this->ReadPropertyInteger('LowBatteryThreshold');
        $registryID = $this->ReadPropertyInteger('RegistryID');
        $backupVid = $this->ReadPropertyInteger('BackupVariableID');

        $lowBatteries = [];
        $systemWarnings = [];
        $offlineDevices = [];
        $orphanedVars = [];
        $htmlRowsBattery = [];
        $htmlRowsOffline = [];
        $htmlRowsOrphaned = [];

        if ($registryID > 1 && @IPS_ObjectExists($registryID) && function_exists('SDR_GetDevices')) {
            $allDevices = @SDR_GetDevices($registryID);
            if (is_array($allDevices)) {
                foreach ($allDevices as $dev) {
                    $devName = ($dev['room'] ?? '') . ' / ' . ($dev['name'] ?? '?');
                    $devType = $dev['Type'] ?? '?';

                    // Check for orphaned primary variables
                    $primaryVarFields = [
                        'OnOff_VarID', 'OpenClose_VarID', 'Status_VarID', 'TempSet_VarID',
                        'ActualTemp_VarID', 'Value_VarID', 'Brightness_VarID'
                    ];
                    foreach ($primaryVarFields as $field) {
                        if (!isset($dev[$field])) continue;
                        $vid = (int)$dev[$field];
                        if ($vid > 0 && !IPS_VariableExists($vid)) {
                            $orphanedVars[] = "$devName ($field #$vid fehlt)";
                            $htmlRowsOrphaned[] = "<tr><td style='width:50%;'><b>$devName</b></td><td style='width:30%;'>$devType</td><td style='width:20%; color:#FF4040;'><b>VARIABLE FEHLT (#$vid)</b></td></tr>";
                        }
                    }

                    // Battery check
                    $batVid = (int)($dev['Battery_VarID'] ?? 0);
                    if ($batVid > 0) {
                        if (!IPS_VariableExists($batVid)) {
                            $orphanedVars[] = "$devName (Battery_VarID #$batVid fehlt)";
                        } else {
                            $val = GetValue($batVid);
                            $statusText = 'OK';
                            $statusColor = '#00FF00';
                            $isLow = false;

                            if (is_bool($val) && $val === true) {
                                $isLow = true;
                                $statusText = 'SCHWACH';
                                $statusColor = '#FF0000';
                            } elseif (is_numeric($val) && (float)$val < $threshold) {
                                $isLow = true;
                                $statusText = round((float)$val) . '% (NIEDRIG)';
                                $statusColor = '#FF0000';
                            } elseif (is_numeric($val)) {
                                $statusText = round((float)$val) . '%';
                            }

                            if ($isLow) {
                                $lowBatteries[] = $devName;
                            }
                            $htmlRowsBattery[] = "<tr><td style='width:50%;'><b>$devName</b></td><td style='width:30%;'>$devType</td><td style='width:20%; color:$statusColor;'><b>$statusText</b></td></tr>";
                        }
                    }

                    // Reachability check
                    $reachVid = (int)($dev['Reachable_VarID'] ?? 0);
                    if ($reachVid > 0) {
                        if (!IPS_VariableExists($reachVid)) {
                            $orphanedVars[] = "$devName (Reachable_VarID #$reachVid fehlt)";
                        } else {
                            $val = GetValue($reachVid);
                            $ident = strtoupper(IPS_GetObject($reachVid)['ObjectIdent']);
                            $isOffline = false;

                            if ($ident === 'DEVICEAVAILABLE' && $val === false) {
                                $isOffline = true;
                            } elseif ($ident !== 'DEVICEAVAILABLE' && $val === true) {
                                $isOffline = true;
                            } elseif (is_string($val) && strtolower($val) === 'offline') {
                                $isOffline = true;
                            }

                            $statusText = $isOffline ? 'OFFLINE' : 'ONLINE';
                            $statusColor = $isOffline ? '#FF9900' : '#00FF00';
                            if ($isOffline) $offlineDevices[] = $devName;
                            $htmlRowsOffline[] = "<tr><td style='width:50%;'><b>$devName</b></td><td style='width:30%;'>$devType</td><td style='width:20%; color:$statusColor;'><b>$statusText</b></td></tr>";
                        }
                    }
                }
            }
        }

        // Backup Check
        if ($backupVid > 1 && @IPS_VariableExists($backupVid)) {
            $lastBackup = GetValue($backupVid);
            if (is_int($lastBackup) && $lastBackup > 0) {
                if (time() - $lastBackup > 48 * 3600) {
                    $systemWarnings[] = 'Backup ist älter als 48h';
                }
            }
        }

        $batCount   = count($lowBatteries);
        $offCount   = count($offlineDevices);
        $orphaCount = count($orphanedVars);
        $warnCount  = count($systemWarnings);

        $this->SetValue('LowBatteryCount', $batCount);
        $this->SetValue('OfflineDeviceCount', $offCount);
        $this->SetValue('OrphanedVarCount', $orphaCount);

        $summary = [];
        if ($warnCount > 0)  $summary[] = "System Warnungen: " . implode(', ', $systemWarnings);
        if ($batCount > 0)   $summary[] = "Batterien niedrig ($batCount): " . implode(', ', $lowBatteries);
        if ($offCount > 0)   $summary[] = "Offline ($offCount): " . implode(', ', $offlineDevices);
        if ($orphaCount > 0) $summary[] = "Fehlende Variablen ($orphaCount)";

        $text = count($summary) > 0 ? implode(' | ', $summary) : 'Alle Geraete betriebsbereit.';
        $oldText = $this->GetValue('SummaryText');
        $this->SetValue('SummaryText', $text);
        $hasChanged = ($text !== $oldText);

        $buildTable = function(string $title, array $rows): string {
            $t  = "<div style='margin-top:10px;margin-bottom:5px;padding-bottom:2px;border-bottom:1px solid #555;color:#ddd;font-weight:bold;text-transform:uppercase;'>$title</div>";
            $t .= "<table style='width:100%;border-collapse:collapse;margin-bottom:15px;'>";
            $t .= count($rows) > 0 ? implode('', $rows) : "<tr><td colspan='3' style='color:#00FF00;'>Alles in Ordnung.</td></tr>";
            $t .= "</table>";
            return $t;
        };

        $html  = $buildTable('Erreichbarkeit (Online/Offline)', $htmlRowsOffline);
        $html .= $buildTable('Batteriestatus', $htmlRowsBattery);
        $html .= $buildTable('Fehlende / Verwaiste Variablen', $htmlRowsOrphaned);
        $this->SetValue('MonitoredListHTML', $html);

        if ($triggerNotification && $hasChanged && ($batCount > 0 || $offCount > 0 || $orphaCount > 0)) {
            $notifierId = $this->ReadPropertyInteger('TargetNotifier');
            if ($notifierId > 0 && @IPS_InstanceExists($notifierId)) {
                $payload = json_encode([
                    'Title'    => 'Geraeteueberwachung',
                    'Message'  => $text,
                    'Priority' => ($orphaCount > 0 || $offCount > 0) ? 2 : 1
                ]);
                @NOTIFY_SendEvent($notifierId, $payload);
            }
        }
    }

    public function GetVisualizationTile(): string
    {
        $batCount   = $this->GetValue('LowBatteryCount');
        $offCount   = $this->GetValue('OfflineDeviceCount');
        $orphaCount = $this->GetValue('OrphanedVarCount');
        $htmlList   = $this->GetValue('MonitoredListHTML');
        $summary    = $this->GetValue('SummaryText');
        $hasBackupWarning = strpos($summary, 'System Warnungen') !== false;

        $hasIssue    = ($batCount > 0 || $offCount > 0 || $orphaCount > 0 || $hasBackupWarning);
        $statusStyle = $hasIssue ? 'color: #ff3333; font-weight: bold;' : 'color: #33cc33; font-weight: bold;';
        $statusText  = $hasIssue ? 'Warnungen/Fehler gefunden!' : 'Alles in bester Ordnung.';
        
        $warningRow = $hasBackupWarning ? "<br><span style='color:#FF9900'>$summary</span>" : "";

        return <<<HTML
<div style="font-family: sans-serif; padding: 10px;">
    <h2>Smart Device Monitor</h2>
    <div style="background-color: rgba(255,255,255,0.05); padding: 15px; border-radius: 8px; margin-bottom: 20px;">
        <span style="{$statusStyle}">{$statusText}</span><br>
        Schwache Batterien: <b>{$batCount}</b> | Offline: <b>{$offCount}</b> | Verwaiste Variablen: <b>{$orphaCount}</b>{$warningRow}
    </div>
    <h3>Detail-Uebersicht</h3>
    <div style="background-color: rgba(0,0,0,0.2); padding: 10px; border-radius: 8px; overflow-x: auto; max-height: 400px; overflow-y: auto;">
        {$htmlList}
    </div>
</div>
HTML;
    }

    public function GetConfigurationForm(): string
    {
        $elements = [];
        
        $elements[] = [
            "type"     => "SelectModule",
            "name"     => "RegistryID",
            "caption"  => "Device Registry (Geraeteverwaltung)",
            "moduleID" => "{F3B4A7D9-C59E-401A-B826-17D3B5C2849E}"
        ];
        
        // Dynamic Read-Only List of Monitored Devices
        $monitoredList = [];
        $registryId = $this->ReadPropertyInteger('RegistryID');
        if ($registryId > 1 && @IPS_ObjectExists($registryId) && function_exists('SDR_GetDevices')) {
            $allDevices = @SDR_GetDevices($registryId);
            if (is_array($allDevices)) {
                foreach ($allDevices as $dev) {
                    $hasBattery = (isset($dev['Battery_VarID']) && (int)$dev['Battery_VarID'] > 0);
                    $hasReachable = (isset($dev['Reachable_VarID']) && (int)$dev['Reachable_VarID'] > 0);
                    
                    if ($hasBattery || $hasReachable) {
                        $monitors = [];
                        if ($hasBattery) $monitors[] = "Batterie";
                        if ($hasReachable) $monitors[] = "Erreichbarkeit";
                        
                        $monitoredList[] = [
                            "id" => $dev['id'] ?? '',
                            "name" => $dev['name'] ?? 'Unbekannt',
                            "room" => $dev['room'] ?? '',
                            "monitors" => implode(", ", $monitors)
                        ];
                    }
                }
            }
        }
        
        $elements[] = [
            "type" => "ExpansionPanel",
            "caption" => "🔋 Automatisch überwachte Geräte (Aus der Registry)",
            "items" => [
                [
                    "type" => "Label",
                    "caption" => "Die folgenden Geräte werden vollautomatisch auf leere Batterien oder Verbindungsabbrüche überwacht:"
                ],
                [
                    "type" => "List",
                    "name" => "_dummyList",
                    "caption" => "Geräte",
                    "add" => false,
                    "delete" => false,
                    "edit" => false,
                    "columns" => [
                        ["name" => "name", "caption" => "Name", "width" => "250px"],
                        ["name" => "room", "caption" => "Raum", "width" => "150px"],
                        ["name" => "monitors", "caption" => "Überwachung", "width" => "auto"]
                    ],
                    "values" => $monitoredList
                ]
            ]
        ];

        $elements[] = [
            "type" => "Label",
            "caption" => " "
        ];
        $elements[] = [
            "type"     => "SelectModule",
            "name"     => "TargetNotifier",
            "caption"  => "SmartNotifier Instanz (fuer Push-Benachrichtigungen)",
            "moduleID" => "{B8A7F31D-E1D8-49A4-B9A9-5E9D5B4A1C8F}"
        ];
        $elements[] = [
            "type"    => "NumberSpinner",
            "name"    => "LowBatteryThreshold",
            "caption" => "Batterie Warnschwelle (%)",
            "minimum" => 1,
            "maximum" => 50
        ];
        $elements[] = [
            "type" => "Label",
            "caption" => " "
        ];
        $elements[] = [
            "type"    => "SelectVariable",
            "name"    => "BackupVariableID",
            "caption" => "Zuletzt abgeschlossenes Backup (UnixTimestamp Variable)"
        ];

        return json_encode([
            "elements" => $elements,
            "actions" => [
                [
                    "type"    => "Button",
                    "caption" => "Jetzt Geraete pruefen",
                    "onClick" => 'SMD_CheckHealth($id, false); echo "Pruefung abgeschlossen!";'
                ]
            ]
        ]);
    }
}
