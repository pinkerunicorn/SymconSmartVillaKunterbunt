<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_CentralStateAware.php';
require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class SmartSecurityManager extends IPSModuleStrict
{
    use SmartLog_Trait;
    use CentralStateAware_Trait;
    use DeviceAvailability_Trait;
    
    public function Create(): void{
        parent::Create();
        
        $this->DA_RegisterAvailability(900);

        // Security Properties
        $this->RegisterPropertyInteger('RegistryID', 0);

        // Alarm Properties (From SmartAlarmManager)
        $this->RegisterPropertyString("MonitoredVariables", "[]");
        $this->RegisterPropertyInteger("EscalationTimeLvl2", 300);
        $this->RegisterPropertyInteger("EscalationTimeLvl3", 900);
        $this->RegisterPropertyInteger("TargetNotifier", 0);
        
        $this->RegisterTimer("EscalationTimer", 0, 'SAM_CheckEscalation($_IPS[\'TARGET\']);');
        $this->RegisterTimer("StatusResetTimer", 0, 'SAM_UpdateStatusVariables($_IPS[\'TARGET\']); SAM_SetTimerInterval($_IPS[\'TARGET\'], "StatusResetTimer", 0);');
        
        $this->SetBuffer("ActiveAlarms", "{}");

        // UI Variables
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

        // Windows/Doors count variables
        $this->RegisterVariableInteger('OpenWindowsCount', 'Offene Fenster / Türen (Zähler)', [
            'PRESENTATION'   => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'           => 'Window',
            'SUFFIX'         => ' offen'
        ], 5);
        $this->RegisterVariableString('OpenWindowsList', 'Offene Fenster / Türen (Namen)', [
            'PRESENTATION'   => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'           => 'Information'
        ], 6);
    }

    public function ApplyChanges(): void{
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        // SystemStatus (Ampel)
        $intervals = json_encode([
            ['IntervalMinValue' => 0, 'IntervalMaxValue' => 1, 'ConstantActive' => true, 'ConstantValue' => 'Alles OK', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Ok', 'ColorActive' => true, 'ColorValue' => 0x00CC00, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF],
            ['IntervalMinValue' => 1, 'IntervalMaxValue' => 2, 'ConstantActive' => true, 'ConstantValue' => 'Info / Hinweis', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Warning', 'ColorActive' => true, 'ColorValue' => 0xFFFF00, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF],
            ['IntervalMinValue' => 2, 'IntervalMaxValue' => 3, 'ConstantActive' => true, 'ConstantValue' => 'ALARM!', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Alert', 'ColorActive' => true, 'ColorValue' => 0xFF0000, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF],
            ['IntervalMinValue' => 3, 'IntervalMaxValue' => 4, 'ConstantActive' => true, 'ConstantValue' => 'ESKALATION', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Alert', 'ColorActive' => true, 'ColorValue' => 0xFF0000, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF],
            ['IntervalMinValue' => 4, 'IntervalMaxValue' => 5, 'ConstantActive' => true, 'ConstantValue' => 'VOLLALARM', 'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => true, 'IconValue' => 'Alert', 'ColorActive' => true, 'ColorValue' => 0xFF0000, 'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF]
        ]);
        $this->RegisterVariableInteger("SystemStatus", "System Status", ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Information', 'INTERVALS_ACTIVE' => true, 'INTERVALS' => $intervals], 1);

        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $notifier = $this->ReadPropertyInteger('TargetNotifier');
        if ($notifier > 1 && @IPS_ObjectExists($notifier)) {
            $this->RegisterReference($notifier);
        }
        $list_MonitoredVariables = $this->safeJsonDecode($this->ReadPropertyString('MonitoredVariables'), true);
        if (is_array($list_MonitoredVariables)) {
            foreach ($list_MonitoredVariables as $item) {
                $vid = $item['VariableID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) $this->RegisterReference($vid);
            }
        }
        
        // Door & Window References from Registry
        $registryId = $this->ReadPropertyInteger('RegistryID');
        if ($registryId > 1 && @IPS_ObjectExists($registryId)) {
            $this->RegisterReference($registryId);
            $contactSensors = $this->safeJsonDecode(@IPS_GetProperty($registryId, 'DevicesContactSensor') ?: '[]', true);
            foreach ($contactSensors as $sensor) {
                $vid = $sensor['Status_VarID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) $this->RegisterReference($vid);
            }
        } else {
            $contactSensors = [];
        }

        $this->SubscribeToCentralStates(['PresenceMode', 'ActivityMode']);

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        $monitored = $this->safeJsonDecode($this->ReadPropertyString("MonitoredVariables"), true) ?: [];
        $activeIdents = [];

        foreach ($monitored as $item) {
            $vid = $item['VariableID'] ?? 0;
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $this->RegisterMessage($vid, VM_UPDATE);
                
                if (($item['AlarmLevel'] ?? 1) > 0) {
                    $ident = "Alarm_". $vid;
                    $activeIdents[] = $ident;
                    $this->RegisterVariableBoolean($ident, "Status: ". ($item['Message'] ?? 'Alarm'), ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH, 'ICON' => 'Alert'], 0);
                    $this->EnableAction($ident);
                    $varID = $this->GetIDForIdent($ident);
                    $isAlarmActive = (bool)$this->GetValue($ident);
                    IPS_SetHidden($varID, !$isAlarmActive);
                }
            }
        }
        
        // Always add the absence alarm to activeIdents so it isn't deleted
        $absenceIdent = "Alarm_". $this->GetIDForIdent('OpenWindowsCount');
        $activeIdents[] = $absenceIdent;
        $this->RegisterVariableBoolean($absenceIdent, "Status: Fenster/Tür offen bei Abwesenheit", ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH, 'ICON' => 'Alert'], 0);
        $this->EnableAction($absenceIdent);
        $varID = $this->GetIDForIdent($absenceIdent);
        IPS_SetHidden($varID, !(bool)$this->GetValue($absenceIdent));
        
        // Window & Door Messages
        foreach ($contactSensors as $sensor) {
            $id = $sensor['Status_VarID'] ?? 0;
            if ($id > 0 && IPS_VariableExists($id)) $this->RegisterMessage($id, VM_UPDATE);
        }

        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $ident = IPS_GetObject($childID)['ObjectIdent'];
            if (strpos($ident, "Alarm_") === 0) {
                if (!in_array($ident, $activeIdents)) {
                    $this->UnregisterVariable($ident);
                }
            }
        }

        $this->CalculateOpenWindows();
        $this->UpdateStatusVariables();
        $this->DA_SetAvailable(true);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void{
        if ($this->HandleCentralStateMessage($SenderID, $Message, $Data)) return;

        // Check if sender is a door or window
        $isWindowOrDoor = false;
        $registryId = $this->ReadPropertyInteger('RegistryID');
        if ($registryId > 1 && @IPS_ObjectExists($registryId)) {
            $contactSensors = $this->safeJsonDecode(@IPS_GetProperty($registryId, 'DevicesContactSensor') ?: '[]', true);
            foreach ($contactSensors as $sensor) {
                if (isset($sensor['Status_VarID']) && $sensor['Status_VarID'] == $SenderID) {
                    $isWindowOrDoor = true;
                    break;
                }
            }
        }
        if ($isWindowOrDoor) {
            $this->CalculateOpenWindows();
        }

        // Generic Alarm Check
        $monitored = $this->safeJsonDecode($this->ReadPropertyString("MonitoredVariables"), true);
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
                        $alarms = $this->safeJsonDecode($this->GetBuffer("ActiveAlarms"), true) ?: [];
                        if (isset($alarms[$vid])) {
                            $this->SLogInfo("Auto-Reset für Sensor/Variable $vid");
                            $this->RequestAction("Alarm_".$vid, false);
                        }
                    }
                }
            }
        }
    }

    public function DiscoverVariables(bool $forceRefresh = false): void
    {
        $existing = $this->safeJsonDecode($this->ReadPropertyString("MonitoredVariables"), true) ?: [];
        $existingIds = array_column($existing, 'VariableID');
        
        $cached = $this->GetBuffer('AllVariablesCache');
        if ($forceRefresh || $cached === '') {
            $allVars = IPS_GetVariableList();
            $this->SetBuffer('AllVariablesCache', json_encode($allVars));
        } else {
            $allVars = $this->safeJsonDecode($cached, true);
        }

        $addedCount = 0;
        foreach($allVars as $vid) {
            if (in_array($vid, $existingIds)) continue;
            
            $obj = IPS_GetObject($vid);
            $ident = $obj['ObjectIdent'];
            $name = $obj['ObjectName'];
            
            $isAlarm = (
                strpos(strtolower($ident), 'alarm') !== false ||
                strpos(strtolower($name), 'alarm') !== false ||
                strpos(strtolower($ident), 'error') !== false ||
                strpos(strtolower($name), 'fehler') !== false ||
                strpos(strtolower($ident), 'defect') !== false ||
                strpos(strtolower($name), 'defekt') !== false ||
                strpos(strtolower($ident), 'warning') !== false ||
                strpos(strtolower($name), 'warnung') !== false ||
                strpos(strtolower($ident), 'leak') !== false ||
                strpos(strtolower($name), 'leck') !== false
            );
            
            // Skip UI variables of this module itself
            if ($obj['ParentID'] == $this->InstanceID) continue;
            
            if ($isAlarm) {
                $type = IPS_GetVariable($vid)['VariableType'];
                $trigger = 'true';
                if ($type === 1) $trigger = '1';
                
                $parentName = IPS_GetName($obj['ParentID']);
                
                $existing[] = [
                    'VariableID' => $vid,
                    'Message' => $parentName . ': ' . $name,
                    'TriggerValue' => $trigger,
                    'AlarmLevel' => 2,
                    'AutoReset' => true
                ];
                $addedCount++;
            }
        }
        
        if ($addedCount > 0) {
            IPS_SetProperty($this->InstanceID, "MonitoredVariables", json_encode($existing));
            IPS_ApplyChanges($this->InstanceID);
            $this->SendDebug('Discover', "Es wurden $addedCount neue Alarm-Variablen gefunden und hinzugefügt!", 0);
        } else {
            $this->SendDebug('Discover', "Keine neuen Alarm-Variablen gefunden.", 0);
        }
    }

    public function ImportAlarmsFromRegistry(): void
    {
        $registryId = $this->ReadPropertyInteger('RegistryID');
        if ($registryId <= 1 || !@IPS_ObjectExists($registryId)) {
            echo "Fehler: Keine gültige Device Registry ausgewählt!\n";
            return;
        }

        $registryAlarms = $this->safeJsonDecode(@IPS_GetProperty($registryId, 'DevicesAlarmSensor') ?: '[]', true);
        $existing = $this->safeJsonDecode($this->ReadPropertyString("MonitoredVariables"), true) ?: [];
        $existingIds = array_column($existing, 'VariableID');

        $addedCount = 0;
        foreach ($registryAlarms as $alarm) {
            $vid = $alarm['Status_VarID'] ?? 0;
            if ($vid > 0 && !in_array($vid, $existingIds)) {
                $existing[] = [
                    'VariableID' => $vid,
                    'Message' => !empty($alarm['name']) ? $alarm['name'] : IPS_GetName($vid),
                    'TriggerValue' => 'true',
                    'AlarmLevel' => 2,
                    'AutoReset' => true
                ];
                $addedCount++;
            }
        }

        if ($addedCount > 0) {
            IPS_SetProperty($this->InstanceID, "MonitoredVariables", json_encode($existing));
            IPS_ApplyChanges($this->InstanceID);
            echo "$addedCount neue Alarmmelder aus der Registry importiert!\n";
        } else {
            echo "Keine neuen Alarmmelder zum Importieren gefunden.\n";
        }
    }

    private function CalculateOpenWindows(): void
    {
        $registryId = $this->ReadPropertyInteger('RegistryID');
        $contactSensors = [];
        if ($registryId > 1 && @IPS_ObjectExists($registryId)) {
            $contactSensors = $this->safeJsonDecode(@IPS_GetProperty($registryId, 'DevicesContactSensor') ?: '[]', true);
        }

        $count = 0;
        $openNames = [];
        
        foreach ($contactSensors as $sensor) {
            $id = $sensor['Status_VarID'] ?? 0;
            if ($id > 0 && IPS_VariableExists($id)) {
                $currentVal = GetValue($id);
                $checkVal = $sensor['ClosedValue'] ?? 'false';
                if (!$this->IsTriggered($currentVal, $checkVal)) { // It is NOT closed
                    $count++;
                    $name = !empty($sensor['name']) ? $sensor['name'] : IPS_GetName($id);
                    $openNames[] = $name;
                }
            }
        }

        if (GetValue($this->GetIDForIdent('OpenWindowsCount')) !== $count) {
            $this->SetValue('OpenWindowsCount', $count);
        }
        
        if ($count == 0) {
            if (GetValue($this->GetIDForIdent('OpenWindowsList')) !== 'Alle geschlossen') $this->SetValue('OpenWindowsList', 'Alle geschlossen');
        } else {
            $namesStr = implode(", ", $openNames);
            if (GetValue($this->GetIDForIdent('OpenWindowsList')) !== $namesStr) $this->SetValue('OpenWindowsList', $namesStr);
        }
    }

    protected function OnCentralStateChanged(string $stateName, mixed $newValue): void
    {
        if ($stateName === 'PresenceMode') {
            $isAbsence = $this->IsAway() || $this->IsVacation();
            if ($isAbsence) {
                $this->CalculateOpenWindows();
                if ($this->GetValue('OpenWindowsCount') > 0) {
                    $mockItem = [
                        'AlarmLevel' => 2,
                        'Message' => "Fenster/Türen offen während Abwesenheit: " . $this->GetValue('OpenWindowsList'),
                        'VariableID' => $this->GetIDForIdent('OpenWindowsCount')
                    ];
                    $this->HandleTrigger($mockItem);
                }
            }
        }
    }

    
    private function dispatchNotification(string $title, string $msg, int $priority): void
    {
        $notifier = $this->ReadPropertyInteger('TargetNotifier');
        if ($notifier > 0 && @IPS_InstanceExists($notifier)) {
            $payload = json_encode(['Title' => $title, 'Message' => $msg, 'Priority' => $priority]);
            $this->safeRequestAction($notifier, $payload); // Note: Assuming NOTIFY_SendEvent maps to a command or RequestAction? Wait! The code calls @NOTIFY_SendEvent($notifier, $payload);
            // Let me use the original function call
            @NOTIFY_SendEvent($notifier, $payload);
        }
    }
private function HandleTrigger(array $item): void
    {
        $level = (int)($item['AlarmLevel'] ?? 1);
        $msg = $item['Message'] ?? "Alarm ausgelöst";
        $vid = $item['VariableID'];
        
        if ($level == 0) {
            $this->SLogInfo("Info/Event ausgelöst: ". $msg);
            $this->SendDebug("Trigger", "Info/Event: ". $msg, 0);
            $this->dispatchNotification('Info', $msg, 0);
            
            $this->SetValue("LastEvent", date("d.m.Y H:i:s") . "- ". $msg);
            if ($this->GetValue("SystemStatus") == 0) {
                $this->SetValue("SystemStatus", 1);
                $this->SetTimerInterval("StatusResetTimer", 3000);
            }
        } else {
            $alarms = $this->safeJsonDecode($this->GetBuffer("ActiveAlarms"), true) ?: [];
            
            if (!isset($alarms[$vid])) {
                $alarms[$vid] = [
                    "timestamp"=> time(),
                    "level"=> $level,
                    "item"=> $item
                ];
                $this->SetBuffer("ActiveAlarms", json_encode($alarms));
                
                $this->SLogWarning("ALARM ausgelöst (Stufe $level): ". $msg);
                $this->SendDebug("Trigger", "Alarm Stufe $level: ". $msg, 0);
                
                $this->dispatchNotification('Alarm', $msg, $level);
                
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
                $alarms = $this->safeJsonDecode($this->GetBuffer("ActiveAlarms"), true) ?: [];

                $alarmName = $alarms[$vid]['item']['Message'] ?? ($alarms[$vid]['message'] ?? null);
                if (!$alarmName && IPS_VariableExists((int)$vid)) {
                    $alarmName = IPS_GetName((int)$vid);
                }
                $alarmName = $alarmName ?: ('Variable #' . $vid);

                $this->SLogInfo('Alarm quittiert', $alarmName);
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
                $alarms = $this->safeJsonDecode($this->GetBuffer("ActiveAlarms"), true) ?: [];
                foreach ($alarms as $vid => $alarm) {
                    $ident = "Alarm_". $vid;
                    if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
                        $this->SetValue($ident, false);
                        IPS_SetHidden($this->GetIDForIdent($ident), true);
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
        $alarms = $this->safeJsonDecode($this->GetBuffer("ActiveAlarms"), true) ?: [];
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
            
            if ($alarm['level'] == 1 && $elapsed >= $lvl2_time) {
                $alarm['level'] = 2;
                $changed = true;
                $this->SLogWarning("Alarm ESKALATION (Stufe 2): ". $msg);
                $this->SendDebug("Escalation", "Stufe 2: ". $msg, 0);
                $this->dispatchNotification('Alarm (Stufe 2)', $msg, 2);
            }

            if ($alarm['level'] == 2 && $elapsed >= $lvl3_time) {
                $alarm['level'] = 3;
                $changed = true;
                $this->SLogError("VOLLALARM (Stufe 3): ". $msg);
                $this->SendDebug("Escalation", "Stufe 3: ". $msg, 0);
                $this->dispatchNotification('VOLLALARM', $msg, 2);
            }
        }

        if ($changed) {
            $this->SetBuffer("ActiveAlarms", json_encode($alarms));
            $this->UpdateStatusVariables();
        }
    }

    public function UpdateStatusVariables(): void
    {
        $alarms = $this->safeJsonDecode($this->GetBuffer("ActiveAlarms"), true) ?: [];

        $monitored = $this->safeJsonDecode($this->ReadPropertyString("MonitoredVariables"), true) ?: [];
        $monitoredMap = [];
        foreach ($monitored as $item) {
            $vid = $item['VariableID'] ?? 0;
            if ($vid > 0) $monitoredMap[$vid] = $item;
        }
        
        $absenceID = $this->GetIDForIdent('OpenWindowsCount');
        $monitoredMap[$absenceID] = ['AlarmLevel' => 2, 'Message' => 'Fenster/Tür offen bei Abwesenheit', 'VariableID' => $absenceID];

        foreach ($alarms as $vid => $alarmData) {
            $ident = "Alarm_" . $vid;
            if (!isset($monitoredMap[$vid]) || !@IPS_GetObjectIDByIdent($ident, $this->InstanceID) || !$this->GetValue($ident)) {
                unset($alarms[$vid]);
            }
        }

        foreach ($monitoredMap as $vid => $item) {
            if (($item['AlarmLevel'] ?? 1) > 0) {
                $ident = "Alarm_" . $vid;
                if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) && $this->GetValue($ident)) {
                    if (!isset($alarms[$vid])) {
                        $alarms[$vid] = ["timestamp" => time(), "level" => (int)($item['AlarmLevel'] ?? 1), "item" => $item];
                    }
                }
            }
        }
        
        $this->SetBuffer("ActiveAlarms", json_encode($alarms));
        $count = count($alarms);
        $this->SetValue("ActiveAlarmsCount", $count);

        if (@IPS_GetObjectIDByIdent('AcknowledgeAll', $this->InstanceID) !== false) {
            IPS_SetHidden($this->GetIDForIdent('AcknowledgeAll'), $count === 0);
        }
        
        if ($count == 0) {
            if ($this->GetValue("SystemStatus") > 0) $this->SetValue("SystemStatus", 0);
            $this->SetTimerInterval("EscalationTimer", 0);
        } else {
            $maxLevel = 1;
            foreach ($alarms as $alarm) {
                $lvl = $alarm['level'] ?? 1;
                if ($lvl > $maxLevel) $maxLevel = $lvl;
            }
            $this->SetValue("SystemStatus", $maxLevel + 1);
        }
        
        $systemStatus = $this->GetValue("SystemStatus");
        $shcLevel = 0;
        if ($systemStatus == 1) $shcLevel = 1;
        elseif ($systemStatus > 1) $shcLevel = 2;
        
        $instances = IPS_GetInstanceListByModuleID('{460D7C60-0766-4534-BFD8-5920737B1845}');
        if (count($instances) > 0 && function_exists('SHC_SetAlarmLevel')) {
            @SHC_SetAlarmLevel($instances[0], $shcLevel);
        }
    }

    private function IsTriggered(mixed $currentVal, string $triggerValStr): bool
    {
        if (is_bool($currentVal)) {
            $t = strtolower(trim((string)$triggerValStr));
            $target = ($t === 'true'|| $t === '1'|| $t === 'wahr');
            return $currentVal === $target;
        }
        if (is_int($currentVal)) return $currentVal === (int)$triggerValStr;
        if (is_float($currentVal)) return $currentVal === (float)$triggerValStr;
        if (is_string($currentVal)) return strtolower(trim($currentVal)) === strtolower(trim($triggerValStr));
        return (string)$currentVal === (string)$triggerValStr;
    }

    private function safeRequestAction(int $id, mixed $value): bool {
        try {
            if (!IPS_VariableExists($id)) {
                $this->SLogWarning("RequestAction failed", "Variable #$id does not exist.");
                return false;
            }
            $res = @RequestAction($id, $value);
            if ($res === false) {
                $this->SLogWarning("RequestAction returned false", "ID: $id, Value: " . var_export($value, true));
            }
            return $res;
        } catch (\Throwable $e) {
            $this->SLogWarning("RequestAction Exception", $e->getMessage());
            return false;
        }
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