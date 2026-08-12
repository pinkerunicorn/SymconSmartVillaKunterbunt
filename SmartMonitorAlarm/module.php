<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_CentralStateAware.php';
require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_HardwareControl.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class SmartMonitorAlarm extends IPSModuleStrict
{
    use SmartLog_Trait;
    use HardwareControl_Trait;
    use CentralStateAware_Trait;
    use DeviceAvailability_Trait;

    // Auto-alarm sensor types from registry and their default alarm level
    private const REGISTRY_ALARM_TYPES = [
        'DevicesSmokeSensor'  => ['level' => 2, 'label' => 'Rauchmelder'],
        'DevicesWaterSensor'  => ['level' => 2, 'label' => 'Wassermelder'],
        'DevicesAlarmSensor'  => ['level' => 2, 'label' => 'Alarmmelder'],
    ];

    public function Create(): void {
        parent::Create();

        $this->DA_RegisterAvailability(900);

        // Security Properties
        $this->RegisterPropertyInteger('RegistryID', 0);

        // Manual alarm variables (custom fallback)
        $this->RegisterPropertyInteger("EscalationTimeLvl2", 300);
        $this->RegisterPropertyInteger("EscalationTimeLvl3", 900);
        $this->RegisterPropertyInteger("TargetNotifier", 0);

        $this->RegisterTimer("EscalationTimer", 0, 'SAM_CheckEscalation($_IPS[\'TARGET\']);');
        $this->RegisterTimer("StatusResetTimer", 0, 'SAM_UpdateStatusVariables($_IPS[\'TARGET\']);');

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
        $this->RegisterVariableInteger('OpenWindowsCount', 'Offene Fenster / Tueren (Zaehler)', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Window',
            'SUFFIX'       => ' offen'
        ], 5);
        $this->RegisterVariableString('OpenWindowsList', 'Offene Fenster / Tueren (Namen)', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Information'
        ], 6);
    }

    public function ApplyChanges(): void {
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

        // Manual monitored variables
        $list_MonitoredVariables = $this->safeJsonDecode($this->ReadPropertyString('MonitoredVariables'), true);
        if (is_array($list_MonitoredVariables)) {
            foreach ($list_MonitoredVariables as $item) {
                $vid = $item['VariableID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) $this->RegisterReference($vid);
            }
        }

        // Registry references
        $registryId = $this->ReadPropertyInteger('RegistryID');
        if ($registryId > 1 && @IPS_ObjectExists($registryId)) {
            $this->RegisterReference($registryId);
        }

        $this->SubscribeToCentralStates(['PresenceMode', 'ActivityMode']);

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        // Register manual alarm variable messages + dynamic Alarm_ variables
$activeIdents = [];

        foreach ($monitored as $item) {
            $vid = $item['VariableID'] ?? 0;
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $this->RegisterMessage($vid, VM_UPDATE);

                if (($item['AlarmLevel'] ?? 1) > 0) {
                    $ident = "Alarm_" . $vid;
                    $activeIdents[] = $ident;
                    $this->RegisterVariableBoolean($ident, "Status: " . ($item['Message'] ?? 'Alarm'), ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH, 'ICON' => 'Alert'], 0);
                    $this->EnableAction($ident);
                    $varID = $this->GetIDForIdent($ident);
                    $isAlarmActive = (bool)$this->GetValue($ident);
                    IPS_SetHidden($varID, !$isAlarmActive);
                }
            }
        }

        // Absence alarm ident
        $absenceIdent = "Alarm_" . $this->GetIDForIdent('OpenWindowsCount');
        $activeIdents[] = $absenceIdent;
        $this->RegisterVariableBoolean($absenceIdent, "Status: Fenster/Tuer offen bei Abwesenheit", ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH, 'ICON' => 'Alert'], 0);
        $this->EnableAction($absenceIdent);
        $varID = $this->GetIDForIdent($absenceIdent);
        IPS_SetHidden($varID, !(bool)$this->GetValue($absenceIdent));

        // Auto-register Registry alarm sensor messages + Alarm_ variables
        $registrySensors = $this->GetRegistrySensors($registryId);
        foreach ($registrySensors as $sensor) {
            $vid = (int)($sensor['Status_VarID'] ?? 0);
            if ($vid <= 0 || !IPS_VariableExists($vid)) continue;
            $this->RegisterReference($vid);
            $this->RegisterMessage($vid, VM_UPDATE);

            $label = ($sensor['_label'] ?? 'Alarmmelder');
            $name  = ($sensor['room'] ?? '') . ': ' . ($sensor['name'] ?? '');
            $ident = "Alarm_" . $vid;
            $activeIdents[] = $ident;
            $this->RegisterVariableBoolean($ident, "Status: $name", ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH, 'ICON' => 'Alert'], 0);
            $this->EnableAction($ident);
            $varID = $this->GetIDForIdent($ident);
            IPS_SetHidden($varID, !(bool)$this->GetValue($ident));
        }

        // Window & Door contact messages
        $contactSensors = $this->GetRegistryContactSensors($registryId);
        foreach ($contactSensors as $sensor) {
            $id = $sensor['Status_VarID'] ?? 0;
            if ($id > 0 && IPS_VariableExists($id)) {
                $this->RegisterReference($id);
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }

        // Clean up orphaned Alarm_ variables
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

    /**
     * Returns all sensors from registry that should trigger automatic alarms
     * (SmokeSensor, WaterSensor, AlarmSensor)
     */
    private function GetRegistrySensors(int $registryId): array
    {
        if ($registryId <= 1 || !@IPS_ObjectExists($registryId)) return [];
        $result = [];
        foreach (self::REGISTRY_ALARM_TYPES as $type => $cfg) {
            if (!function_exists('SDR_GetDevicesByType')) break;
            $sensors = @SDR_GetDevicesByType($registryId, $type);
            if (!is_array($sensors)) continue;
            foreach ($sensors as $s) {
                $s['_alarmLevel'] = $cfg['level'];
                $s['_label']      = $cfg['label'];
                $result[] = $s;
            }
        }
        return $result;
    }

    private function GetRegistryContactSensors(int $registryId): array
    {
        if ($registryId <= 1 || !@IPS_ObjectExists($registryId) || !function_exists('SDR_GetDevicesByType')) return [];
        $sensors = @SDR_GetDevicesByType($registryId, 'DevicesContactSensor');
        return is_array($sensors) ? $sensors : [];
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void {
        if ($this->HandleCentralStateMessage($SenderID, $Message, $Data)) return;

        $registryId = $this->ReadPropertyInteger('RegistryID');

        // Check if sender is a contact sensor (window/door)
        $contactSensors = $this->GetRegistryContactSensors($registryId);
        foreach ($contactSensors as $sensor) {
            if (isset($sensor['Status_VarID']) && $sensor['Status_VarID'] == $SenderID) {
                $this->CalculateOpenWindows();
                break;
            }
        }

        // Check if sender is an auto-alarm sensor from registry
        $registrySensors = $this->GetRegistrySensors($registryId);
        foreach ($registrySensors as $sensor) {
            $vid = (int)($sensor['Status_VarID'] ?? 0);
            if ($vid !== $SenderID) continue;
            $currentVal = $Data[0];
            // Smoke/water/alarm sensors: true = alarm triggered
            if ($this->IsTriggered($currentVal, 'true')) {
                $level  = $sensor['_alarmLevel'];
                $name   = ($sensor['room'] ?? '') . ': ' . ($sensor['name'] ?? 'Sensor');
                $label  = $sensor['_label'];
                $this->HandleTrigger([
                    'VariableID' => $vid,
                    'AlarmLevel' => $level,
                    'Message'    => "$label ausgeloest: $name",
                    'AutoReset'  => true
                ]);
            } else {
                // Auto-reset
                $alarms = $this->safeJsonDecode($this->GetBuffer("ActiveAlarms"), true) ?: [];
                if (isset($alarms[$vid])) {
                    $this->RequestAction("Alarm_" . $vid, false);
                }
            }
            return;
        }

        // Generic manual alarm check
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
                            $this->RequestAction("Alarm_" . $vid, false);
                        }
                    }
                }
            }
        }
    }

    private function CalculateOpenWindows(): void
    {
        $registryId = $this->ReadPropertyInteger('RegistryID');
        $contactSensors = $this->GetRegistryContactSensors($registryId);

        $count = 0;
        $openNames = [];

        foreach ($contactSensors as $sensor) {
            $id = $sensor['Status_VarID'] ?? 0;
            if ($id > 0 && IPS_VariableExists($id)) {
                $currentVal = GetValue($id);
                $checkVal   = $sensor['ClosedValue'] ?? 'false';
                if (!$this->IsTriggered($currentVal, $checkVal)) {
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
                        'Message'    => "Fenster/Tueren offen waehrend Abwesenheit: " . $this->GetValue('OpenWindowsList'),
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
            @NOTIFY_SendEvent($notifier, $payload);
        }
    }

    private function HandleTrigger(array $item): void
    {
        $level = (int)($item['AlarmLevel'] ?? 1);
        $msg   = $item['Message'] ?? "Alarm ausgeloest";
        $vid   = $item['VariableID'];

        if ($level == 0) {
            $this->SLogInfo("Info/Event ausgeloest: " . $msg);
            $this->dispatchNotification('Info', $msg, 0);
            $this->SetValue("LastEvent", date("d.m.Y H:i:s") . "- " . $msg);
            if ($this->GetValue("SystemStatus") == 0) {
                $this->SetValue("SystemStatus", 1);
                $this->SetTimerInterval("StatusResetTimer", 3000);
            }
        } else {
            $alarms = $this->safeJsonDecode($this->GetBuffer("ActiveAlarms"), true) ?: [];
            if (!isset($alarms[$vid])) {
                $alarms[$vid] = ["timestamp" => time(), "level" => $level, "item" => $item];
                $this->SetBuffer("ActiveAlarms", json_encode($alarms));
                $this->SLogWarning("ALARM ausgeloest (Stufe $level): " . $msg);
                $this->dispatchNotification('Alarm', $msg, $level);

                $ident = "Alarm_" . $vid;
                if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
                    $varID = $this->GetIDForIdent($ident);
                    $this->SetValue($ident, true);
                    IPS_SetHidden($varID, false);
                }
                $this->SetValue("LastEvent", date("d.m.Y H:i:s") . "- ALARM: " . $msg);
                $this->SetTimerInterval("EscalationTimer", 10000);
                $this->UpdateStatusVariables();
            }
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void {
        if (strpos($Ident, "Alarm_") === 0) {
            if ($Value == false) {
                $this->SetValue($Ident, false);
                $varID = $this->GetIDForIdent($Ident);
                IPS_SetHidden($varID, true);

                $vid    = substr($Ident, 6);
                $alarms = $this->safeJsonDecode($this->GetBuffer("ActiveAlarms"), true) ?: [];
                $alarmName = $alarms[$vid]['item']['Message'] ?? null;
                if (!$alarmName && IPS_VariableExists((int)$vid)) {
                    $alarmName = IPS_GetName((int)$vid);
                }
                $this->SLogInfo('Alarm quittiert', $alarmName ?: ('Variable #' . $vid));

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
                    $ident = "Alarm_" . $vid;
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

        $now       = time();
        $changed   = false;
        $lvl2_time = $this->ReadPropertyInteger("EscalationTimeLvl2");
        $lvl3_time = $this->ReadPropertyInteger("EscalationTimeLvl3");

        foreach ($alarms as $vid => &$alarm) {
            $elapsed = $now - $alarm['timestamp'];
            $msg     = $alarm['item']['Message'] ?? "Alarm";
            if ($alarm['level'] == 1 && $elapsed >= $lvl2_time) {
                $alarm['level'] = 2;
                $changed = true;
                $this->dispatchNotification('Alarm (Stufe 2)', $msg, 2);
            }
            if ($alarm['level'] == 2 && $elapsed >= $lvl3_time) {
                $alarm['level'] = 3;
                $changed = true;
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
        $this->SetTimerInterval('StatusResetTimer', 0);
        $alarms = $this->safeJsonDecode($this->GetBuffer("ActiveAlarms"), true) ?: [];
$monitoredMap = [];
        $registryId = $this->ReadPropertyInteger('RegistryID');
        foreach ($this->GetRegistrySensors($registryId) as $sensor) {
            $vid = (int)($sensor['Status_VarID'] ?? 0);
            if ($vid > 0) {
                $monitoredMap[$vid] = [
                    'AlarmLevel' => $sensor['_alarmLevel'],
                    'Message'    => ($sensor['_label'] ?? '') . ': ' . ($sensor['name'] ?? ''),
                    'VariableID' => $vid
                ];
            }
        }

        $absenceID = $this->GetIDForIdent('OpenWindowsCount');
        $monitoredMap[$absenceID] = ['AlarmLevel' => 2, 'Message' => 'Fenster/Tuer offen bei Abwesenheit', 'VariableID' => $absenceID];

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
            $t      = strtolower(trim((string)$triggerValStr));
            $target = ($t === 'true' || $t === '1' || $t === 'wahr');
            return $currentVal === $target;
        }
        if (is_int($currentVal))   return $currentVal === (int)$triggerValStr;
        if (is_float($currentVal)) return $currentVal === (float)$triggerValStr;
        if (is_string($currentVal)) return strtolower(trim($currentVal)) === strtolower(trim($triggerValStr));
        return (string)$currentVal === (string)$triggerValStr;
    }

    private function safeJsonDecode(string $json, bool $assoc = true): mixed
    {
        try {
            if (trim($json) === '') return $assoc ? [] : null;
            return json_decode($json, $assoc, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->SLogWarning("JSON Decode Exception", $e->getMessage());
            return $assoc ? [] : null;
        }
    }

    public function GetConfigurationForm(): string
    {
        $elements = [];
        
        $elements[] = [
            "type" => "CheckBox",
            "name" => "SimulationMode",
            "caption" => "Simulationsmodus (Testbetrieb)"
        ];
        
        $elements[] = [
            "type" => "Label",
            "caption" => " "
        ];
        
        $elements[] = [
            "type" => "SelectModule",
            "name" => "RegistryID",
            "caption" => "Device Registry",
            "moduleID" => "{F3B4A7D9-C59E-401A-B826-17D3B5C2849E}"
        ];
        
        $elements[] = [
            "type" => "Label",
            "caption" => " "
        ];
        
        // Dynamic Read-Only List of Monitored Sensors
        $sensorsList = [];
        $registryId = $this->ReadPropertyInteger('RegistryID');
        if ($registryId > 1 && @IPS_ObjectExists($registryId)) {
            foreach ($this->GetRegistrySensors($registryId) as $sensor) {
                $sensorsList[] = [
                    "id" => $sensor['id'] ?? '',
                    "name" => $sensor['name'] ?? 'Unbekannt',
                    "room" => $sensor['room'] ?? '',
                    "type" => $sensor['type'] ?? '',
                    "level" => "Vollalarm (2)"
                ];
            }
        }
        
        $elements[] = [
            "type" => "ExpansionPanel",
            "caption" => "🛡️ Automatisch überwachte Sensoren (Aus der Registry)",
            "items" => [
                [
                    "type" => "Label",
                    "caption" => "Die folgenden Rauch-, Wasser- und Einbruchmelder werden vollautomatisch überwacht:"
                ],
                [
                    "type" => "List",
                    "name" => "_dummyList",
                    "caption" => "Sensoren",
                    "add" => false,
                    "delete" => false,
                    "edit" => false,
                    "columns" => [
                        ["name" => "name", "caption" => "Name", "width" => "250px"],
                        ["name" => "room", "caption" => "Raum", "width" => "150px"],
                        ["name" => "type", "caption" => "Typ", "width" => "150px"],
                        ["name" => "level", "caption" => "Alarmstufe", "width" => "auto"]
                    ],
                    "values" => $sensorsList
                ]
            ]
        ];
        
        $elements[] = [
            "type" => "ExpansionPanel",
            "caption" => "📢 Notifier & Eskalation",
            "items" => [
                [
                    "type" => "Label",
                    "caption" => "Wähle hier die Ziele für die Alarmierung aus."
                ],
                [ "type" => "SelectInstance", "name" => "TargetWebFront", "caption" => "WebFront Instanz" ],
                [ "type" => "SelectInstance", "name" => "TargetSMTP", "caption" => "SMTP Instanz (E-Mail)" ],
                [ "type" => "SelectInstance", "name" => "TargetVestaboard", "caption" => "Vestaboard" ],
                [ "type" => "SelectInstance", "name" => "TargetSonos", "caption" => "Sonos" ],
                [ "type" => "ValidationTextBox", "name" => "EmailAddress", "caption" => "E-Mail Empfänger" ],
                [
                    "type" => "Label",
                    "caption" => " "
                ],
                [ "type" => "NumberSpinner", "name" => "EscalationTimeLvl2", "caption" => "Eskalationszeit zu Stufe 2 (Sekunden)", "minimum" => 0 ],
                [ "type" => "NumberSpinner", "name" => "EscalationTimeLvl3", "caption" => "Eskalationszeit zu Stufe 3 (Sekunden)", "minimum" => 0 ]
            ]
        ];
        
        return json_encode(["elements" => $elements]);
    }
}