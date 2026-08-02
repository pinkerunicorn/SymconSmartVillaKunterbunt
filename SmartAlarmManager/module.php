<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_CentralStateAware.php';
require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class SmartAlarmManager extends IPSModuleStrict
{
    use SmartLog_Trait;
    use CentralStateAware_Trait;
    use DeviceAvailability_Trait;
    public function Create(): void{
        parent::Create();
        
        $this->DA_RegisterAvailability(900);

        $this->RegisterPropertyString("MonitoredVariables", "[]");
        $this->RegisterPropertyInteger("EscalationTimeLvl2", 300);
        $this->RegisterPropertyInteger("EscalationTimeLvl3", 900);
        $this->RegisterPropertyInteger("TargetNotifier", 0);
        
        $this->RegisterTimer("EscalationTimer", 0, 'SAM_CheckEscalation($_IPS[\'TARGET\']);');
        $this->RegisterTimer("StatusResetTimer", 0, 'SAM_UpdateStatusVariables($_IPS[\'TARGET\']); IPS_SetScriptTimer($_IPS[\'TARGET\'], "StatusResetTimer", 0);');
        
        $this->SetBuffer("ActiveAlarms", "{}");

        // Summary Variables for Tile UI
        $this->RegisterVariableInteger("SystemStatus", "System Status", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Information'
        ], 1);
        $this->RegisterVariableInteger("ActiveAlarmsCount", "Aktive Alarme", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Warning'
        ], 2);
        IPS_SetVariableCustomProfile($this->GetIDForIdent('ActiveAlarmsCount'), '');
        
        $this->RegisterVariableString("LastEvent", "Letztes Ereignis", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Flag'
        ], 3);
        IPS_SetVariableCustomProfile($this->GetIDForIdent('LastEvent'), '');
        $this->RegisterVariableBoolean("AcknowledgeAll", "Alle Alarme quittieren", [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON'         => 'Ok'
        ], 4);
        $this->EnableAction("AcknowledgeAll");
    }

    public function ApplyChanges(): void{
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();
        // --- Auto-generated References ---
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $notifier = $this->ReadPropertyInteger('TargetNotifier');
        if ($notifier > 1 && @IPS_ObjectExists($notifier)) {
            $this->RegisterReference($notifier);
        }
        $list_MonitoredVariables = json_decode($this->ReadPropertyString('MonitoredVariables'), true);
        if (is_array($list_MonitoredVariables)) {
            foreach ($list_MonitoredVariables as $item) {
                $vid = $item['VariableID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        // ---------------------------------
        
        $options = json_encode([
            ['Value' => 0, 'Caption' => 'Alles OK', 'IconValue' => 'Ok', 'ColorDisplay' => 0x00FF00, 'ColorValue' => 0x00FF00, 'IconActive' => false, 'ColorActive' => false, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1],
            ['Value' => 1, 'Caption' => 'Info / Hinweis', 'IconValue' => 'Information', 'ColorDisplay' => 0xFFFF00, 'ColorValue' => 0xFFFF00, 'IconActive' => false, 'ColorActive' => false, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1],
            ['Value' => 2, 'Caption' => 'ALARM!', 'IconValue' => 'Warning', 'ColorDisplay' => 0xFF0000, 'ColorValue' => 0xFF0000, 'IconActive' => false, 'ColorActive' => false, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1],
            ['Value' => 3, 'Caption' => 'ESKALATION', 'IconValue' => 'Warning', 'ColorDisplay' => 0xFF0000, 'ColorValue' => 0xFF0000, 'IconActive' => false, 'ColorActive' => false, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1],
            ['Value' => 4, 'Caption' => 'VOLLALARM', 'IconValue' => 'Alert', 'ColorDisplay' => 0xFF0000, 'ColorValue' => 0xFF0000, 'IconActive' => false, 'ColorActive' => false, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1]
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('SystemStatus'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'DISPLAY_TYPE' => 0,
            'OPTIONS' => $options
        ]);
        IPS_SetVariableCustomProfile($this->GetIDForIdent('SystemStatus'), '');


        $this->SubscribeToCentralStates(['PresenceMode', 'ActivityMode']);

        // Unregister all old messages
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        $monitored = json_decode($this->ReadPropertyString("MonitoredVariables"), true);
        if (!is_array($monitored)) $monitored = [];

        $activeIdents = [];

        foreach ($monitored as $item) {
            $vid = $item['VariableID'] ?? 0;
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $this->RegisterMessage($vid, VM_UPDATE);
                
                if (($item['AlarmLevel'] ?? 1) > 0) {
                    $ident = "Alarm_". $vid;
                    $activeIdents[] = $ident;
                    $this->MaintainVariable($ident, "Status: ". ($item['Message'] ?? 'Alarm'), 0, "", 0, true);
                    $varID = $this->GetIDForIdent($ident);
                    IPS_SetVariableCustomPresentation($varID, [
                        'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
                        'ICON'         => 'Alert'
                    ]);
                    $this->EnableAction($ident);

                    // Nur Alarme sichtbar machen, die zu quittieren sind
                    $isAlarmActive = (bool)$this->GetValue($ident);
                    IPS_SetHidden($varID, !$isAlarmActive);
                }
            }
        }
        
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $ident = IPS_GetObject($childID)['ObjectIdent'];
            if (strpos($ident, "Alarm_") === 0) {
                if (!in_array($ident, $activeIdents)) {
                    $this->MaintainVariable($ident, "", 0, "", 0, false);
                }
            }
        }

        $this->UpdateStatusVariables();
        $this->DA_SetAvailable(true);
    }


    protected function OnCentralStateChanged(string $stateName, mixed $newValue): void {}

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void{
        if ($this->HandleCentralStateMessage($SenderID, $Message, $Data)) return;

        $monitored = json_decode($this->ReadPropertyString("MonitoredVariables"), true);
        if (!is_array($monitored)) return;

        $currentVal = $Data[0]; 
        
        foreach ($monitored as $item) {
            $vid = $item['VariableID'] ?? 0;
            if ($vid == $SenderID) {
                $triggerVal = $item['TriggerValue'] ?? 'true';
                if ($this->IsTriggered($currentVal, $triggerVal)) {
                    $this->HandleTrigger($item);
                } else {
                    if (($item['AutoReset'] ?? false)) {
                        $alarms = json_decode($this->GetBuffer("ActiveAlarms"), true) ?: [];
                        if (isset($alarms[$vid])) {
                            $this->SLogInfo("Auto-Reset für Sensor/Variable $vid");
                            $this->RequestAction("Alarm_".$vid, false);
                        }
                    }
                }
            }
        }
    }

    private function HandleTrigger($item)
    {
        $level = (int)($item['AlarmLevel'] ?? 1);
        $msg = $item['Message'] ?? "Alarm ausgelöst";
        $vid = $item['VariableID'];
        
        $notifier = $this->ReadPropertyInteger('TargetNotifier');

        if ($level == 0) {
            $this->SLogInfo("Info/Event ausgelöst: ". $msg);
            $this->SendDebug("Trigger", "Info/Event: ". $msg, 0);
            
            if ($notifier > 0 && @IPS_InstanceExists($notifier)) {
                $payload = json_encode(['Title' => 'Info', 'Message' => $msg, 'Priority' => 0]);
                @NOTIFY_SendEvent($notifier, $payload);
            }
            
            $this->SetValue("LastEvent", date("d.m.Y H:i:s") . "- ". $msg);
            if ($this->GetValue("SystemStatus") == 0) {
                $this->SetValue("SystemStatus", 1);
                $this->SetTimerInterval("StatusResetTimer", 3000);
            }
        } else {
            $alarms = json_decode($this->GetBuffer("ActiveAlarms"), true) ?: [];
            
            if (!isset($alarms[$vid])) {
                $alarms[$vid] = [
                    "timestamp"=> time(),
                    "level"=> $level,
                    "item"=> $item
                ];
                $this->SetBuffer("ActiveAlarms", json_encode($alarms));
                
                $this->SLogWarning("ALARM ausgelöst (Stufe $level): ". $msg);
                $this->SendDebug("Trigger", "Alarm Stufe $level: ". $msg, 0);
                
                if ($notifier > 0 && @IPS_InstanceExists($notifier)) {
                    $payload = json_encode(['Title' => 'Alarm', 'Message' => $msg, 'Priority' => $level]);
                    @NOTIFY_SendEvent($notifier, $payload);
                }
                
                $ident = "Alarm_". $vid;
                if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
                    $varID = $this->GetIDForIdent($ident);
                    $this->SetValue($ident, true);
                    IPS_SetHidden($varID, false);
                }
                
                $this->SetValue("LastEvent", date("d.m.Y H:i:s") . "- ALARM: ". $msg);
                $this->SetTimerInterval("EscalationTimer", 10000);
                $this->UpdateStatusVariables();
            }
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void{
        if (strpos($Ident, "Alarm_") === 0) {
            if ($Value == false) {
                $this->SetValue($Ident, false);
                $varID = $this->GetIDForIdent($Ident);
                IPS_SetHidden($varID, true);

                $vid = substr($Ident, 6);
                $alarms = json_decode($this->GetBuffer("ActiveAlarms"), true) ?: [];

                // Lesbaren Namen fÃ¼r das Log ermitteln
                $alarmName = $alarms[$vid]['item']['Message'] ?? ($alarms[$vid]['message'] ?? null);
                if (!$alarmName && IPS_VariableExists((int)$vid)) {
                    $alarmName = IPS_GetName((int)$vid);
                }
                $alarmName = $alarmName ?: ('Variable #' . $vid);

                $this->SLogInfo( 'Alarm quittiert', $alarmName);
                $this->SendDebug("Acknowledge", "Quittiert: ". $Ident, 0);

                if (isset($alarms[$vid])) {
                    unset($alarms[$vid]);
                    $this->SetBuffer("ActiveAlarms", json_encode($alarms));
                }
                
                if (empty($alarms)) {
                    $this->SetTimerInterval("EscalationTimer", 0);
                }
                $this->UpdateStatusVariables();
            }
        } elseif ($Ident === "AcknowledgeAll") {
            if ($Value == true) {
                $alarms = json_decode($this->GetBuffer("ActiveAlarms"), true) ?: [];
                foreach ($alarms as $vid => $alarm) {
                    $ident = "Alarm_". $vid;
                    if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
                        $this->SetValue($ident, false);
                        $varID = $this->GetIDForIdent($ident);
                        IPS_SetHidden($varID, true);
                    }
                }

                foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
                    $ident = IPS_GetObject($childID)['ObjectIdent'];
                    if (strpos($ident, "Alarm_") === 0) {
                        $this->SetValue($ident, false);
                        IPS_SetHidden($childID, true);
                    }
                }
                

                
                $this->SetBuffer("ActiveAlarms", "{}");
                $this->SetTimerInterval("EscalationTimer", 0);
                $this->UpdateStatusVariables();
                $this->SLogInfo("Alle Alarme quittiert.");
                $this->SetValue("LastEvent", date("d.m.Y H:i:s") . "- Alle Alarme quittiert");
                
                $this->SetValue("AcknowledgeAll", false);
            }
        } else {
            throw new Exception("Invalid Ident");
        }
    }

    public function CheckEscalation(): void
    {
        $alarms = json_decode($this->GetBuffer("ActiveAlarms"), true) ?: [];
        if (empty($alarms)) {
            $this->SetTimerInterval("EscalationTimer", 0);
            return;
        }

        $now = time();
        $changed = false;
        $lvl2_time = $this->ReadPropertyInteger("EscalationTimeLvl2");
        $lvl3_time = $this->ReadPropertyInteger("EscalationTimeLvl3");

        foreach ($alarms as $vid => &$alarm) {
            $elapsed = $now - $alarm['timestamp'];
            $msg = $alarm['item']['Message'] ?? "Alarm";
            
            $notifier = $this->ReadPropertyInteger('TargetNotifier');

            if ($alarm['level'] == 1 && $elapsed >= $lvl2_time) {
                $alarm['level'] = 2;
                $changed = true;
                $this->SLogWarning("Alarm ESKALATION (Stufe 2): ". $msg);
                $this->SendDebug("Escalation", "Stufe 2: ". $msg, 0);
                if ($notifier > 0 && @IPS_InstanceExists($notifier)) {
                    $payload = json_encode(['Title' => 'Alarm (Stufe 2)', 'Message' => $msg, 'Priority' => 2]);
                    @NOTIFY_SendEvent($notifier, $payload);
                }
            }

            if ($alarm['level'] == 2 && $elapsed >= $lvl3_time) {
                $alarm['level'] = 3;
                $changed = true;
                $this->SLogError("VOLLALARM (Stufe 3): ". $msg);
                $this->SendDebug("Escalation", "Stufe 3: ". $msg, 0);
                if ($notifier > 0 && @IPS_InstanceExists($notifier)) {
                    $payload = json_encode(['Title' => 'VOLLALARM', 'Message' => $msg, 'Priority' => 2]);
                    @NOTIFY_SendEvent($notifier, $payload);
                }
            }
        }

        if ($changed) {
            $this->SetBuffer("ActiveAlarms", json_encode($alarms));
            $this->UpdateStatusVariables();
        }
    }

    public function UpdateStatusVariables(): void
    {
        $alarms = json_decode($this->GetBuffer("ActiveAlarms"), true) ?: [];

        // Synchronisiere den Puffer mit den tatsÃ¤chlich aktiven Alarm-Variablen
        $monitored = json_decode($this->ReadPropertyString("MonitoredVariables"), true);
        if (is_array($monitored)) {
            $monitoredMap = [];
            foreach ($monitored as $item) {
                $vid = $item['VariableID'] ?? 0;
                if ($vid > 0) {
                    $monitoredMap[$vid] = $item;
                }
            }

            // Entferne Alarme aus dem Puffer, falls die Variable quittiert oder nicht mehr Ã¼berwacht ist
            foreach ($alarms as $vid => $alarmData) {
                $ident = "Alarm_" . $vid;
                if (!isset($monitoredMap[$vid]) || !@IPS_GetObjectIDByIdent($ident, $this->InstanceID) || !$this->GetValue($ident)) {
                    unset($alarms[$vid]);
                }
            }

            // ErgÃ¤nze Alarme im Puffer, falls eine Alarm-Variable aktiv (true) ist, aber im Puffer fehlt
            foreach ($monitoredMap as $vid => $item) {
                if (($item['AlarmLevel'] ?? 1) > 0) {
                    $ident = "Alarm_" . $vid;
                    if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) && $this->GetValue($ident)) {
                        if (!isset($alarms[$vid])) {
                            $alarms[$vid] = [
                                "timestamp" => time(),
                                "level"     => (int)($item['AlarmLevel'] ?? 1),
                                "item"      => $item
                            ];
                        }
                    }
                }
            }
            $this->SetBuffer("ActiveAlarms", json_encode($alarms));
        }

        $count = count($alarms);
        $this->SetValue("ActiveAlarmsCount", $count);

        if (@IPS_GetObjectIDByIdent('AcknowledgeAll', $this->InstanceID) !== false) {
            IPS_SetHidden($this->GetIDForIdent('AcknowledgeAll'), $count === 0);
        }
        
        if ($count == 0) {
            if ($this->GetValue("SystemStatus") > 0) {
                $this->SetValue("SystemStatus", 0);
            }
            $this->SetTimerInterval("EscalationTimer", 0);
        } else {
            $maxLevel = 1;
            foreach ($alarms as $alarm) {
                $lvl = $alarm['level'] ?? 1;
                if ($lvl > $maxLevel) {
                    $maxLevel = $lvl;
                }
            }
            $this->SetValue("SystemStatus", $maxLevel + 1);
        }
        
        // Notify SmartHomeControl of alarm level change
        $systemStatus = $this->GetValue("SystemStatus");
        $shcLevel = 0;
        if ($systemStatus == 1) $shcLevel = 1;
        elseif ($systemStatus > 1) $shcLevel = 2;
        
        $instances = IPS_GetInstanceListByModuleID('{460D7C60-0766-4534-BFD8-5920737B1845}');
        if (count($instances) > 0 && function_exists('SHC_SetAlarmLevel')) {
            @SHC_SetAlarmLevel($instances[0], $shcLevel);
        }
    }



    private function IsTriggered($currentVal, $triggerValStr)
    {
        if (is_bool($currentVal)) {
            $t = strtolower(trim((string)$triggerValStr));
            $target = ($t === 'true'|| $t === '1'|| $t === 'wahr');
            return $currentVal === $target;
        }
        return (string)$currentVal === (string)$triggerValStr;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "ExpansionPanel",
            "caption": "⚙ Globale Einstellungen & Eskalation",
            "items": [
                {
                    "type": "Label",
                    "caption": "Hier stellst du ein, nach wie vielen Sekunden die nächste Eskalationsstufe auslöst."
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "NumberSpinner",
                            "name": "EscalationTimeLvl2",
                            "caption": "Stufe 2 (Sekunden)",
                            "suffix": "s"
                        },
                        {
                            "type": "NumberSpinner",
                            "name": "EscalationTimeLvl3",
                            "caption": "Stufe 3 (Sekunden)",
                            "suffix": "s"
                        }
                    ]
                }
            ]
        },
        {
            "type": "ExpansionPanel",
            "caption": "📢 Nachrichten & Signale",
            "items": [
                {
                    "type": "Label",
                    "caption": "Wähle hier die SmartNotifier Instanz aus, um Alarme zentral zu melden."
                },
                {
                    "type": "SelectInstance",
                    "name": "TargetNotifier",
                    "caption": "SmartNotifier Instanz"
                }
            ]
        },
        {
            "type": "Label",
            "caption": "Überwachte Variablen (Sensoren / Auslöser)"
        },
        {
            "type": "Label",
            "caption": "Hier stellst du ein, welche Sensoren überwacht werden und wann ein Alarm ausgelöst wird."
        },
        {
            "type": "List",
            "name": "MonitoredVariables",
            "caption": "Variablen",
            "add": true,
            "delete": true,
            "changeOrder": true,
            "rowCount": 15,
            "columns": [
                {
                    "caption": "Sensor/Auslöser",
                    "name": "VariableID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Nachricht/Titel",
                    "name": "Message",
                    "width": "250px",
                    "add": "Neuer Alarm",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Auslöse-Wert",
                    "name": "TriggerValue",
                    "width": "150px",
                    "add": "true",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Stufe (Level)",
                    "name": "AlarmLevel",
                    "width": "150px",
                    "add": 1,
                    "edit": {
                        "type": "Select",
                        "options": [
                            {
                                "caption": "Normal (Nur Info)",
                                "value": 0
                            },
                            {
                                "caption": "Warnung",
                                "value": 1
                            },
                            {
                                "caption": "Fehler / Alarm",
                                "value": 2
                            }
                        ]
                    }
                },
                {
                    "caption": "Auto-Reset",
                    "name": "AutoReset",
                    "width": "100px",
                    "add": false,
                    "edit": {
                        "type": "CheckBox"
                    }
                }
            ]
        }
    ]
}
EOT;
    }
}