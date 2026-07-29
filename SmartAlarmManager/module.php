<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_HouseModeAware.php';
require_once __DIR__ . '/../libs/Trait_SmartLog.php';

class SmartAlarmManager extends IPSModuleStrict
{
    use SmartLog_Trait;
    use HouseModeAware_Trait;
    public function Create(): void{
        parent::Create();

        $this->RegisterPropertyString("MonitoredVariables", "[]");
        $this->RegisterPropertyString("ActionProfiles", "[]");
        $this->RegisterPropertyInteger("EscalationTimeLvl2", 300);
        $this->RegisterPropertyInteger("EscalationTimeLvl3", 900);
        $this->RegisterPropertyInteger("TargetWebFront", 0);
        $this->RegisterPropertyInteger("TargetSMTP", 0);
        $this->RegisterPropertyInteger("TargetVestaboard", 0);
        $this->RegisterPropertyInteger("TargetSonos", 0);
        $this->RegisterPropertyString("EmailAddress", "");
        
        $this->RegisterTimer("EscalationTimer", 0, 'SAM_CheckEscalation($_IPS[\'TARGET\']);');
        $this->RegisterTimer("DelayTimer", 0, 'SAM_HandleDelays($_IPS[\'TARGET\']);');
        
        $this->SetBuffer("ActiveAlarms", "{}");
        $this->SetBuffer("ActiveDelays", "{}");

        // Summary Variables for Tile UI
        $this->RegisterVariableInteger("SystemStatus", "System Status", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Information'
        ], 1);
        $this->RegisterVariableInteger("ActiveAlarmsCount", "Aktive Alarme", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Warning'
        ], 2);
        $this->RegisterVariableString("LastEvent", "Letztes Ereignis", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Flag'
        ], 3);
        $this->RegisterVariableBoolean("AcknowledgeAll", "Alle Alarme quittieren", [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON'         => 'Ok'
        ], 4);
        $this->EnableAction("AcknowledgeAll");
        
        $this->RegisterHouseModeAwareness();
    }

    public function ApplyChanges(): void{
        parent::ApplyChanges();
        // --- Auto-generated References ---
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $ref_TargetWebFront = $this->ReadPropertyInteger('TargetWebFront');
        if ($ref_TargetWebFront > 1 && @IPS_ObjectExists($ref_TargetWebFront)) {
            $this->RegisterReference($ref_TargetWebFront);
        }
        $ref_TargetSMTP = $this->ReadPropertyInteger('TargetSMTP');
        if ($ref_TargetSMTP > 1 && @IPS_ObjectExists($ref_TargetSMTP)) {
            $this->RegisterReference($ref_TargetSMTP);
        }
        $ref_TargetVestaboard = $this->ReadPropertyInteger('TargetVestaboard');
        if ($ref_TargetVestaboard > 1 && @IPS_ObjectExists($ref_TargetVestaboard)) {
            $this->RegisterReference($ref_TargetVestaboard);
        }
        $ref_TargetSonos = $this->ReadPropertyInteger('TargetSonos');
        if ($ref_TargetSonos > 1 && @IPS_ObjectExists($ref_TargetSonos)) {
            $this->RegisterReference($ref_TargetSonos);
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
        $list_ActionProfiles = json_decode($this->ReadPropertyString('ActionProfiles'), true);
        if (is_array($list_ActionProfiles)) {
            foreach ($list_ActionProfiles as $item) {
                $vid = $item['HmIP_MP3_Inst'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
                $vid = $item['HmIP_LED_Inst'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
                $vid = $item['HmIP_Siren_Inst'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
                $vid = $item['TargetVariableID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        // ---------------------------------
        
        $systemStatusOptions = json_encode([
            ['Value' => 0, 'Caption' => 'Alles OK', 'IconValue' => 'Ok', 'IconActive' => false, 'ColorActive' => false, 'ColorDisplay' => 0x00FF00, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00FF00],
            ['Value' => 1, 'Caption' => 'Info / Hinweis', 'IconValue' => 'Information', 'IconActive' => false, 'ColorActive' => false, 'ColorDisplay' => 0xFFFF00, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFFFF00],
            ['Value' => 2, 'Caption' => 'ALARM!', 'IconValue' => 'Warning', 'IconActive' => false, 'ColorActive' => false, 'ColorDisplay' => 0xFF0000, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF0000],
            ['Value' => 3, 'Caption' => 'ESKALATION', 'IconValue' => 'Warning', 'IconActive' => false, 'ColorActive' => false, 'ColorDisplay' => 0xFF0000, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF0000],
            ['Value' => 4, 'Caption' => 'VOLLALARM', 'IconValue' => 'Alert', 'IconActive' => false, 'ColorActive' => false, 'ColorDisplay' => 0xFF0000, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF0000]
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('SystemStatus'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON' => 'Information',
            'COLOR' => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => $systemStatusOptions
        ]);

        $this->ApplyHouseModeSubscription();

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
                
                if (($item['AlarmType'] ?? 0) == 0 || ($item['AlarmType'] ?? 0) == 2) {
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
    }

    private function GetActionProfiles($profileID)
    {
        $matches = [];
        $profiles = json_decode($this->ReadPropertyString("ActionProfiles"), true);
        if (is_array($profiles)) {
            foreach ($profiles as $p) {
                if (($p['ProfileID'] ?? '') === $profileID) {
                    $matches[] = $p;
                }
            }
        }
        return $matches;
    }

    private function OnHouseModeChanged(int $mode, bool $isAbsence, bool $isSleep): void {}

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void{
        if ($this->HandleHouseModeMessage($SenderID, $Message, $Data)) return;

        $monitored = json_decode($this->ReadPropertyString("MonitoredVariables"), true);
        if (!is_array($monitored)) return;

        $currentVal = $Data[0]; 
        
        foreach ($monitored as $item) {
            $vid = $item['VariableID'] ?? 0;
            if ($vid == $SenderID) {
                $triggerVal = $item['TriggerValue'] ?? 'true';
                if ($this->IsTriggered($currentVal, $triggerVal)) {
                    $delay = $item['DelaySeconds'] ?? 0;
                    if ($delay > 0) {
                        $delays = json_decode($this->GetBuffer("ActiveDelays"), true) ?: [];
                        if (!isset($delays[$vid])) {
                            $delays[$vid] = [
                                "triggerTime"=> time() + $delay,
                                "item"=> $item
                            ];
                            $this->SetBuffer("ActiveDelays", json_encode($delays));
                            $this->SetTimerInterval("DelayTimer", 1000);
                        }
                    } else {
                        $this->HandleTrigger($item);
                    }
                } else {
                    $delays = json_decode($this->GetBuffer("ActiveDelays"), true) ?: [];
                    if (isset($delays[$vid])) {
                        unset($delays[$vid]);
                        $this->SetBuffer("ActiveDelays", json_encode($delays));
                        if (empty($delays)) {
                            $this->SetTimerInterval("DelayTimer", 0);
                        }
                    }
                    
                    if (($item['AlarmType'] ?? 0) == 2) {
                        $alarms = json_decode($this->GetBuffer("ActiveAlarms"), true) ?: [];
                        if (isset($alarms[$vid])) {
                            $this->LogMessage("Auto-Reset für Sensor/Variable $vid", KL_NOTIFY);
                            $this->RequestAction("Alarm_".$vid, false);
                        }
                    }
                }
            }
        }
    }

    public function HandleDelays(): void
    {
        $delays = json_decode($this->GetBuffer("ActiveDelays"), true) ?: [];
        if (empty($delays)) {
            $this->SetTimerInterval("DelayTimer", 0);
            return;
        }

        $now = time();
        $changed = false;

        foreach ($delays as $vid => $delayObj) {
            if ($now >= $delayObj['triggerTime']) {
                $this->HandleTrigger($delayObj['item']);
                unset($delays[$vid]);
                $changed = true;
            }
        }

        if ($changed) {
            $this->SetBuffer("ActiveDelays", json_encode($delays));
            if (empty($delays)) {
                $this->SetTimerInterval("DelayTimer", 0);
            }
        }
    }

    private function HandleTrigger($item)
    {
        $type = $item['AlarmType'] ?? 0;
        $msg = $item['Message'] ?? "Alarm ausgelöst";
        $vid = $item['VariableID'];
        
        $profileIdStr = $item['ProfileID'] ?? '';
        $profileIds = array_map('trim', explode(',', $profileIdStr));
        $profiles = [];
        foreach ($profileIds as $pid) {
            if (empty($pid)) continue;
            $matchedProfiles = $this->GetActionProfiles($pid);
            foreach ($matchedProfiles as $p) {
                $profiles[] = $p;
            }
        }

        if ($type == 1) {
            $this->LogMessage("Info/Event ausgelöst: ". $msg, KL_NOTIFY);
            $this->SendDebug("Trigger", "Info/Event: ". $msg, 0);
            
            foreach ($profiles as $profile) {
                $this->TriggerInfo($profile, $msg);
            }
            
            $this->SetValue("LastEvent", date("d.m.Y H:i:s") . "- ". $msg);
            if ($this->GetValue("SystemStatus") == 0) {
                $this->SetValue("SystemStatus", 1);
                IPS_Sleep(3000); 
                $this->UpdateStatusVariables(); 
            }
        } else {
            $alarms = json_decode($this->GetBuffer("ActiveAlarms"), true) ?: [];
            
            if (!isset($alarms[$vid])) {
                $alarms[$vid] = [
                    "timestamp"=> time(),
                    "level"=> 1,
                    "item"=> $item,
                    "profiles"=> $profiles
                ];
                $this->SetBuffer("ActiveAlarms", json_encode($alarms));
                
                $this->LogMessage("ALARM ausgelöst (Stufe 1): ". $msg, KL_WARNING);
                $this->SendDebug("Trigger", "Alarm Stufe 1: ". $msg, 0);
                
                foreach ($profiles as $profile) {
                    $this->TriggerLevel1($profile, $msg);
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

                // Lesbaren Namen für das Log ermitteln
                $alarmName = $alarms[$vid]['item']['Message'] ?? ($alarms[$vid]['message'] ?? null);
                if (!$alarmName && IPS_VariableExists((int)$vid)) {
                    $alarmName = IPS_GetName((int)$vid);
                }
                $alarmName = $alarmName ?: ('Variable #' . $vid);

                $this->SLog('INFO', 'Alarm quittiert', $alarmName);
                $this->SendDebug("Acknowledge", "Quittiert: ". $Ident, 0);

                if (isset($alarms[$vid])) {
                    $profiles = $alarms[$vid]['profiles'] ?? [];
                    if (empty($profiles) && isset($alarms[$vid]['profile'])) {
                        $profiles = [$alarms[$vid]['profile']];
                    }
                    unset($alarms[$vid]);
                    $this->SetBuffer("ActiveAlarms", json_encode($alarms));

                    // Prüfen, ob verbleibende aktive Alarme eines der Aktionsprofile noch benötigen
                    $remainingProfiles = [];
                    foreach ($alarms as $remAlarm) {
                        $remProfs = $remAlarm['profiles'] ?? [];
                        if (empty($remProfs) && isset($remAlarm['profile'])) {
                            $remProfs = [$remAlarm['profile']];
                        }
                        foreach ($remProfs as $rp) {
                            $pid = $rp['ProfileID'] ?? '';
                            if ($pid !== '') {
                                $remainingProfiles[$pid] = true;
                            }
                        }
                    }

                    foreach ($profiles as $profile) {
                        $pid = $profile['ProfileID'] ?? '';
                        if ($pid === '' || !isset($remainingProfiles[$pid])) {
                            $this->TriggerHomematicLEDs($profile, true); 
                            $this->TriggerHomematicSirens($profile, true); 
                        }
                    }
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
                
                // Turn off ALL configured devices in ALL profiles to be safe (and to clear tests)
                $profiles = json_decode($this->ReadPropertyString("ActionProfiles"), true);
                if (is_array($profiles)) {
                    foreach ($profiles as $profile) {
                        $this->TriggerHomematicLEDs($profile, true); 
                        $this->TriggerHomematicSirens($profile, true); 
                    }
                }
                
                $this->SetBuffer("ActiveAlarms", "{}");
                $this->SetTimerInterval("EscalationTimer", 0);
                $this->UpdateStatusVariables();
                $this->LogMessage("Alle Alarme quittiert.", KL_NOTIFY);
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
            
            $profiles = $alarm['profiles'] ?? [];
            if (empty($profiles) && isset($alarm['profile'])) {
                $profiles = [$alarm['profile']];
            }

            if ($alarm['level'] == 1 && $elapsed >= $lvl2_time) {
                $alarm['level'] = 2;
                $changed = true;
                $this->LogMessage("Alarm ESKALATION (Stufe 2): ". $msg, KL_WARNING);
                $this->SendDebug("Escalation", "Stufe 2: ". $msg, 0);
                foreach ($profiles as $profile) {
                    $this->TriggerLevel2($profile, $msg);
                }
            }

            if ($alarm['level'] == 2 && $elapsed >= $lvl3_time) {
                $alarm['level'] = 3;
                $changed = true;
                $this->LogMessage("VOLLALARM (Stufe 3): ". $msg, KL_ERROR);
                $this->SendDebug("Escalation", "Stufe 3: ". $msg, 0);
                foreach ($profiles as $profile) {
                    $this->TriggerLevel3($profile, $msg);
                }
            }
        }

        if ($changed) {
            $this->SetBuffer("ActiveAlarms", json_encode($alarms));
            $this->UpdateStatusVariables();
        }
    }

    private function UpdateStatusVariables(): void
    {
        $alarms = json_decode($this->GetBuffer("ActiveAlarms"), true) ?: [];

        // Synchronisiere den Puffer mit den tatsächlich aktiven Alarm-Variablen
        $monitored = json_decode($this->ReadPropertyString("MonitoredVariables"), true);
        if (is_array($monitored)) {
            $monitoredMap = [];
            foreach ($monitored as $item) {
                $vid = $item['VariableID'] ?? 0;
                if ($vid > 0) {
                    $monitoredMap[$vid] = $item;
                }
            }

            // Entferne Alarme aus dem Puffer, falls die Variable quittiert oder nicht mehr überwacht ist
            foreach ($alarms as $vid => $alarmData) {
                $ident = "Alarm_" . $vid;
                if (!isset($monitoredMap[$vid]) || !@IPS_GetObjectIDByIdent($ident, $this->InstanceID) || !$this->GetValue($ident)) {
                    unset($alarms[$vid]);
                }
            }

            // Ergänze Alarme im Puffer, falls eine Alarm-Variable aktiv (true) ist, aber im Puffer fehlt
            foreach ($monitoredMap as $vid => $item) {
                if (($item['AlarmType'] ?? 0) == 0 || ($item['AlarmType'] ?? 0) == 2) {
                    $ident = "Alarm_" . $vid;
                    if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) && $this->GetValue($ident)) {
                        if (!isset($alarms[$vid])) {
                            $profileIdStr = $item['ProfileID'] ?? '';
                            $profileIds = array_map('trim', explode(',', $profileIdStr));
                            $profiles = [];
                            foreach ($profileIds as $pid) {
                                if (empty($pid)) continue;
                                foreach ($this->GetActionProfiles($pid) as $p) {
                                    $profiles[] = $p;
                                }
                            }
                            $alarms[$vid] = [
                                "timestamp" => time(),
                                "level"     => 1,
                                "item"      => $item,
                                "profiles"  => $profiles
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
    }

    private function TriggerLevel1($profile, $message)
    {
        $this->LogMessage("--- Starte Aktions-Profil Level 1 ---", KL_NOTIFY);
        
        if ($profile['UseWebFront'] ?? false) {
            $visu = $this->ReadPropertyInteger("TargetWebFront");
            if ($visu > 0 && IPS_InstanceExists($visu)) {
                $this->LogMessage("App/Visu: Sende Push & Notification", KL_NOTIFY);
                if (function_exists('VISU_PostNotification')) {
                    VISU_PostNotification($visu, "Alarm!", $message, "Warning", 0);
                }
            }
        }
        
        if ($profile['UseSonos'] ?? false) {
            if (!$this->IsAbsent()) {
                $this->LogMessage("Sonos: Spiele TTS", KL_NOTIFY);
                $this->TriggerSonos($message);
            }
        }
        
        $this->TriggerHomematicMP3($profile);
        $this->TriggerHomematicLEDs($profile);
        $this->TriggerHomematicSirens($profile);
        $this->TriggerTargetVariable($profile);
    }

    private function TriggerLevel2($profile, $message)
    {
        $this->LogMessage("--- Starte Aktions-Profil Level 2 (ESKALATION) ---", KL_NOTIFY);
        
        if ($profile['UseVestaboard'] ?? false) {
            $vesta = $this->ReadPropertyInteger("TargetVestaboard");
            if ($vesta > 0 && IPS_InstanceExists($vesta)) {
                if (function_exists('VESTAG_PushAlert')) {
                    $this->LogMessage("Vestaboard: Sende Alarm-Nachricht via Generator", KL_NOTIFY);
                    try {
                        VESTAG_PushAlert($vesta, "ALARM:\n". $message, true);
                    } catch (Exception $e) {
                        $this->LogMessage("Fehler beim Senden an Vestaboard: ". $e->getMessage(), KL_ERROR);
                    }
                }
            }
        }

        if ($profile['UseEmail'] ?? false) {
            $smtp = $this->ReadPropertyInteger("TargetSMTP");
            $email = trim($this->ReadPropertyString("EmailAddress"));
            if ($smtp > 0 && IPS_InstanceExists($smtp)) {
                if ($email != "") {
                    $this->LogMessage("E-Mail: Sende Mail an $email", KL_NOTIFY);
                    try {
                        SMTP_SendMailEx($smtp, $email, "SmartHome Alarm Stufe 2", "Folgender Alarm wurde ausgelöst und noch nicht quittiert:\n\n". $message);
                    } catch (Exception $e) {
                        $this->LogMessage("Fehler beim Senden der E-Mail: ". $e->getMessage(), KL_ERROR);
                    }
                }
            }
        }
        
        if ($profile['UseSonos'] ?? false) {
            $this->LogMessage("Sonos: Spiele TTS", KL_NOTIFY);
            $this->TriggerSonos("Achtung, Alarm: ". $message);
        }
        
        $this->TriggerHomematicMP3($profile);
        $this->TriggerHomematicLEDs($profile);
        $this->TriggerHomematicSirens($profile);
        $this->TriggerTargetVariable($profile);
    }

    private function TriggerLevel3($profile, $message)
    {
        $this->LogMessage("--- Starte Aktions-Profil Level 3 (VOLLALARM) ---", KL_NOTIFY);
        
        if ($profile['UseVestaboard'] ?? false) {
            $vesta = $this->ReadPropertyInteger("TargetVestaboard");
            if ($vesta > 0 && IPS_InstanceExists($vesta)) {
                if (function_exists('VESTAG_PushAlert')) {
                    $this->LogMessage("Vestaboard: Sende Vollalarm via Generator", KL_NOTIFY);
                    try {
                        VESTAG_PushAlert($vesta, "!!! VOLLALARM !!!\n" . $message, true);
                    } catch (Exception $e) {
                        $this->LogMessage("Fehler beim Senden an Vestaboard: " . $e->getMessage(), KL_ERROR);
                    }
                }
            }
        }
        
        if ($profile['UseWebFront'] ?? false) {
            $visu = $this->ReadPropertyInteger("TargetWebFront");
            if ($visu > 0 && IPS_InstanceExists($visu)) {
                $this->LogMessage("App/Visu: Sende Push & Notification", KL_NOTIFY);
                if (function_exists('VISU_PostNotification')) {
                    VISU_PostNotification($visu, "VOLLALARM", $message, "Alert", 0);
                }
            }
        }
        
        if ($profile['UseSonos'] ?? false) {
            $this->LogMessage("Sonos: Spiele TTS", KL_NOTIFY);
            $this->TriggerSonos("Vollalarm: ". $message);
        }
        
        $this->TriggerHomematicMP3($profile);
        $this->TriggerHomematicLEDs($profile);
        $this->TriggerHomematicSirens($profile);
        $this->TriggerTargetVariable($profile);
    }

    private function TriggerInfo($profile, $message)
    {
        $this->LogMessage("--- Starte Aktions-Profil Info/Event ---", KL_NOTIFY);
        
        if ($profile['UseWebFront'] ?? false) {
            $visu = $this->ReadPropertyInteger("TargetWebFront");
            if ($visu > 0 && IPS_InstanceExists($visu)) {
                $this->LogMessage("App/Visu: Sende Push & Notification", KL_NOTIFY);
                if (function_exists('VISU_PostNotification')) {
                    VISU_PostNotification($visu, "Info", $message, "Information", 0);
                }
            }
        }
        
        if ($profile['UseVestaboard'] ?? false) {
            $vesta = $this->ReadPropertyInteger("TargetVestaboard");
            if ($vesta > 0 && IPS_InstanceExists($vesta)) {
                if (function_exists('VESTAG_PushAlert')) {
                    $this->LogMessage("Vestaboard: Sende Info-Nachricht via Generator", KL_NOTIFY);
                    try {
                        VESTAG_PushAlert($vesta, $message, false);
                    } catch (Exception $e) {
                        $this->LogMessage("Fehler beim Senden an Vestaboard: " . $e->getMessage(), KL_ERROR);
                    }
                }
            }
        }
        
        if ($profile['UseEmail'] ?? false) {
            $smtp = $this->ReadPropertyInteger("TargetSMTP");
            $email = trim($this->ReadPropertyString("EmailAddress"));
            if ($smtp > 0 && IPS_InstanceExists($smtp)) {
                if ($email != "") {
                    $this->LogMessage("E-Mail: Sende Mail an $email", KL_NOTIFY);
                    try {
                        SMTP_SendMailEx($smtp, $email, "SmartHome Info / Event", $message);
                    } catch (Exception $e) {
                        $this->LogMessage("Fehler beim Senden der E-Mail: ". $e->getMessage(), KL_ERROR);
                    }
                }
            }
        }
        
        if ($profile['UseSonos'] ?? false) {
            if (!$this->IsAbsent()) {
                $this->LogMessage("Sonos: Spiele TTS", KL_NOTIFY);
                $this->TriggerSonos($message);
            }
        }
        
        $this->TriggerHomematicMP3($profile);
        $this->TriggerHomematicLEDs($profile);
        $this->TriggerHomematicSirens($profile);
        $this->TriggerTargetVariable($profile);
    }
    
    private function TriggerSonos($message)
    {
        $sonos = $this->ReadPropertyInteger("TargetSonos");
        if ($sonos > 0 && IPS_InstanceExists($sonos)) {
            try {
                if (function_exists('GSTTS_PlayMessage')) {
                    GSTTS_PlayMessage($sonos, $message, true);
                } elseif (function_exists('SNS_PlayText')) {
                    SNS_PlayText($sonos, $message);
                }
            } catch (Exception $e) {
                $this->LogMessage("Fehler bei Sonos TTS: ". $e->getMessage(), KL_ERROR);
            }
        }
    }

    private function TriggerHomematicMP3($profile)
    {
        $mp3InstID = $profile['HmIP_MP3_Inst'] ?? 0;
        if ($mp3InstID > 0 && IPS_InstanceExists($mp3InstID)) {
            $soundStr = $profile['MP3_Sounds'] ?? '1';
            $vol      = (int)($profile['MP3_Volume'] ?? 100);
            $duration = (int)($profile['MP3_Duration'] ?? 0);

            $this->LogMessage("HmIP_MP3P (Instanz $mp3InstID): Spiele Tracks '$soundStr' (Vol $vol%, Dauer {$duration}s)", KL_NOTIFY);

            if (function_exists('MP3P_PlaySound')) {
                MP3P_PlaySound($mp3InstID, $soundStr, $vol, $duration);
            } else {
                // Fallback: direkter HM-Aufruf falls Modul nicht geladen
                $param = "L={$vol},DU=0,DV={$duration},RTU=0,RTV=0,R=0,SL={$soundStr}";
                try {
                    HM_WriteValueString($mp3InstID, 'COMBINED_PARAMETER', $param);
                } catch (Exception $e) {
                    $this->LogMessage('Fehler MP3P Fallback: ' . $e->getMessage(), KL_ERROR);
                }
            }
        }
    }

    private function TriggerHomematicLEDs($profile, $turnOff = false)
    {
        $instId = $profile['HmIP_LED_Inst'] ?? 0;
        if ($instId > 0 && IPS_InstanceExists($instId)) {
            $color  = (int)($profile['LED_Color'] ?? 4);
            $mode   = (int)($profile['LED_Mode'] ?? 1);
            $bright = (int)($profile['LED_Brightness'] ?? 100);
            $isMP3P = (bool)($profile['HmIP_LED_IsMP3P'] ?? false);

            if ($isMP3P) {
                // MP3P-LED über HmIP_MP3P Modul
                if ($turnOff) {
                    $this->LogMessage("HmIP_MP3P LED (Instanz $instId): Licht aus", KL_NOTIFY);
                    if (function_exists('MP3P_SetLightOff')) {
                        MP3P_SetLightOff($instId);
                    } else {
                        HM_WriteValueString($instId, 'COMBINED_PARAMETER', 'L=100,DV=10,DU=0,RTV=0,RTU=1,C=0');
                    }
                } else {
                    $this->LogMessage("HmIP_MP3P LED (Instanz $instId): Farbe $color, Helligkeit $bright%", KL_NOTIFY);
                    if (function_exists('MP3P_SetLight')) {
                        MP3P_SetLight($instId, $color, $bright, 0);
                    } else {
                        HM_WriteValueString($instId, 'COMBINED_PARAMETER', "L={$bright},DV=31,DU=2,RTV=0,RTU=1,C={$color}");
                    }
                }
            } else {
                // WRC6-LED über HmIP_WRC6 Modul
                // Hinweis: $instId ist hier die WRC6-Modul-Instanz, nicht ein einzelner Kanal.
                // SetAllLEDs steuert alle konfigurierten Kanäle.
                if ($turnOff) {
                    $this->LogMessage("HmIP_WRC6 LED (Instanz $instId): Alle LEDs aus", KL_NOTIFY);
                    if (function_exists('WRC6_ClearAllLEDs')) {
                        WRC6_ClearAllLEDs($instId);
                    } else {
                        HM_WriteValueString($instId, 'COMBINED_PARAMETER', 'L=0,DV=31,DU=2,RTV=0,RTU=0,C=0,CB=0,RTTOV=0,RTTOU=3');
                    }
                } else {
                    $this->LogMessage("HmIP_WRC6 LED (Instanz $instId): Farbe $color, Modus $mode, Helligkeit $bright%", KL_NOTIFY);
                    if (function_exists('WRC6_SetAllLEDs')) {
                        WRC6_SetAllLEDs($instId, $color, $mode, $bright);
                    } else {
                        HM_WriteValueString($instId, 'COMBINED_PARAMETER', "L={$bright},DV=31,DU=2,RTV=0,RTU=0,C={$color},CB={$mode},RTTOV=0,RTTOU=3");
                    }
                }
            }
        }
    }

    private function TriggerHomematicSirens($profile, $turnOff = false)
    {
        $instId = $profile['HmIP_Siren_Inst'] ?? 0;
        if ($instId > 0 && IPS_InstanceExists($instId)) {
            $ac  = (int)($profile['Siren_Acoustic'] ?? 1);
            $opt = (int)($profile['Siren_Optical'] ?? 1);

            if ($turnOff) {
                $this->LogMessage("HmIP_ASIRO (Instanz $instId): Gestoppt", KL_NOTIFY);
                if (function_exists('ASIRO_Stop')) {
                    ASIRO_Stop($instId);
                } else {
                    HM_WriteValueString($instId, 'COMBINED_PARAMETER', 'O=0,A=0,DV=31,DU=2');
                }
            } else {
                $this->LogMessage("HmIP_ASIRO (Instanz $instId): Ausgelöst (Akustik $ac, Optik $opt)", KL_NOTIFY);
                if (function_exists('ASIRO_Trigger')) {
                    ASIRO_Trigger($instId, $ac, $opt, 0);
                } else {
                    HM_WriteValueString($instId, 'COMBINED_PARAMETER', "O={$opt},A={$ac},DV=31,DU=2");
                }
            }
        }
    }

    private function TriggerTargetVariable($profile)
    {
        $targetId = $profile['TargetVariableID'] ?? 0;
        if ($targetId > 0 && IPS_VariableExists($targetId)) {
            $targetValueStr = $profile['TargetVariableValue'] ?? "";
            $var = IPS_GetVariable($targetId);
            
            switch($var['VariableType']) {
                case 0: // Boolean
                    $targetValueStr = strtolower(trim((string)$targetValueStr));
                    $val = ($targetValueStr === 'true'|| $targetValueStr === '1'|| $targetValueStr === 'wahr');
                    break;
                case 1: // Integer
                    $val = (int)$targetValueStr;
                    break;
                case 2: // Float
                    $val = (float)str_replace(',', '.', $targetValueStr);
                    break;
                case 3: // String
                default:
                    $val = (string)$targetValueStr;
                    break;
            }
            
            $targetName = IPS_ObjectExists($targetId) ? IPS_GetName($targetId) : '';
            $targetDesc = $targetName !== '' ? "$targetId ('$targetName')" : (string)$targetId;

            $this->LogMessage("Setze Ziel-Variable $targetDesc auf Wert: ". var_export($val, true), KL_NOTIFY);
            try {
                if (HasAction($targetId)) {
                    if (!@RequestAction($targetId, $val)) {
                        $this->SLog('WARNING', 'Alarm-Aktor fehlgeschlagen', "ID: $targetId | Wert: " . var_export($val, true));
                    }
                } else {
                    SetValue($targetId, $val);
                }
            } catch (Exception $e) {
                $this->LogMessage("Fehler beim Setzen der Ziel-Variable $targetDesc: ". $e->getMessage(), KL_ERROR);
            }
        }
    }

    public function TestProfile(string $profileID, bool $turnOff): void
    {
        $profiles = $this->GetActionProfiles($profileID);
        if (empty($profiles)) {
            echo "Fehler: Profil(e) '$profileID'nicht gefunden!";
            return;
        }

        if ($turnOff) {
            $this->SendDebug("Test", "Stoppe Profile: ". $profileID, 0);
            foreach ($profiles as $profile) {
                $this->TriggerHomematicLEDs($profile, true);
                $this->TriggerHomematicSirens($profile, true);
            }
            echo "Profile '$profileID'gestoppt (LEDs & Sirenen Aus).";
        } else {
            $msg = "TEST-ALARM für Profil: ". $profileID;
            $this->SendDebug("Test", "Teste Profile: ". $profileID, 0);
            foreach ($profiles as $profile) {
                $this->TriggerInfo($profile, $msg);
            }
            echo "Profile '$profileID'getestet (Signale gesendet).";
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

    protected function LogMessage(string $Message, int $Type): bool
    {
        $this->SLog('INFO', $Message);
        IPS_LogMessage('SmartVillaKunterbunt', 'SmartAlarmManager: '. $Message);
        return true;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "SelectVariable",
            "name": "HouseModeVariableID",
            "caption": "Haus-Modus Variable"
        },
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
                            "caption": "Stufe 2 (Sekunden bis Email/Vestaboard)",
                            "suffix": "s"
                        },
                        {
                            "type": "NumberSpinner",
                            "name": "EscalationTimeLvl3",
                            "caption": "Stufe 3 (Sekunden bis Vollalarm)",
                            "suffix": "s"
                        }
                    ]
                }
            ]
        },
        {
            "type": "ExpansionPanel",
            "caption": "📢 Globale Schnittstellen / Ausgabegeräte",
            "items": [
                {
                    "type": "Label",
                    "caption": "Hier stellst du ein, welche Instanzen für globale Nachrichten und Signale genutzt werden."
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "SelectInstance",
                            "name": "TargetWebFront",
                            "caption": "WebFront / App (für Push Notifications)"
                        },
                        {
                            "type": "SelectInstance",
                            "name": "TargetSMTP",
                            "caption": "SMTP Instanz (für E-Mails)"
                        },
                        {
                            "type": "ValidationTextBox",
                            "name": "EmailAddress",
                            "caption": "Empfänger E-Mail Adresse"
                        }
                    ]
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "SelectInstance",
                            "name": "TargetVestaboard",
                            "caption": "Vestaboard Instanz"
                        },
                        {
                            "type": "SelectInstance",
                            "name": "TargetSonos",
                            "caption": "Sonos / GoogleTTS Instanz"
                        }
                    ]
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
                    "caption": "Verzögerung (s)",
                    "name": "DelaySeconds",
                    "width": "100px",
                    "add": 0,
                    "edit": {
                        "type": "NumberSpinner"
                    }
                },
                {
                    "caption": "Alarm/Eskalations-Typ",
                    "name": "AlarmType",
                    "width": "150px",
                    "add": 0,
                    "edit": {
                        "type": "Select",
                        "options": [
                            {
                                "caption": "Alarm (mit Eskalation)",
                                "value": 0
                            },
                            {
                                "caption": "Info / Türklingel (Einmalig)",
                                "value": 1
                            },
                            {
                                "caption": "Alarm (Eskalation, Auto-Reset)",
                                "value": 2
                            }
                        ]
                    }
                },
                {
                    "caption": "Aktions-Profil (Name)",
                    "name": "ProfileID",
                    "width": "200px",
                    "add": "Neues_Profil",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                }
            ]
        },
        {
            "type": "Label",
            "caption": "Aktions-Profile"
        },
        {
            "type": "Label",
            "caption": "Hier stellst du ein, was bei einem bestimmten Profil-Alarm passieren soll (z.B. Sirenen oder Lichter aktivieren)."
        },
        {
            "type": "List",
            "name": "ActionProfiles",
            "caption": "Profile",
            "add": true,
            "delete": true,
            "changeOrder": true,
            "rowCount": 15,
            "columns": [
                {
                    "caption": "Profil-ID (Name)",
                    "name": "ProfileID",
                    "width": "auto",
                    "add": "Neues_Profil",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "App",
                    "name": "UseWebFront",
                    "width": "50px",
                    "add": true,
                    "edit": {
                        "type": "CheckBox"
                    }
                },
                {
                    "caption": "E-Mail",
                    "name": "UseEmail",
                    "width": "50px",
                    "add": true,
                    "edit": {
                        "type": "CheckBox"
                    }
                },
                {
                    "caption": "Vestaboard",
                    "name": "UseVestaboard",
                    "width": "50px",
                    "add": true,
                    "edit": {
                        "type": "CheckBox"
                    }
                },
                {
                    "caption": "Sonos",
                    "name": "UseSonos",
                    "width": "50px",
                    "add": true,
                    "edit": {
                        "type": "CheckBox"
                    }
                },
                {
                    "caption": "MP3-Instanz",
                    "name": "HmIP_MP3_Inst",
                    "width": "120px",
                    "add": 0,
                    "edit": {
                        "type": "SelectInstance"
                    }
                },
                {
                    "caption": "MP3 Track(s)",
                    "name": "MP3_Sounds",
                    "width": "100px",
                    "add": "1",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Lautstärke",
                    "name": "MP3_Volume",
                    "width": "80px",
                    "add": 100,
                    "edit": {
                        "type": "NumberSpinner"
                    }
                },
                {
                    "caption": "Dauer (s)",
                    "name": "MP3_Duration",
                    "width": "60px",
                    "add": 0,
                    "edit": {
                        "type": "NumberSpinner"
                    }
                },
                {
                    "caption": "LED-Instanz",
                    "name": "HmIP_LED_Inst",
                    "width": "120px",
                    "add": 0,
                    "edit": {
                        "type": "SelectInstance"
                    }
                },
                {
                    "caption": "Ist MP3P-Licht?",
                    "name": "HmIP_LED_IsMP3P",
                    "width": "80px",
                    "add": false,
                    "edit": {
                        "type": "CheckBox"
                    }
                },
                {
                    "caption": "LED Helligkeit",
                    "name": "LED_Brightness",
                    "width": "80px",
                    "add": 100,
                    "edit": {
                        "type": "NumberSpinner"
                    }
                },
                {
                    "caption": "LED Farbe",
                    "name": "LED_Color",
                    "width": "100px",
                    "add": 4,
                    "edit": {
                        "type": "Select",
                        "options": [
                            {
                                "caption": "Aus",
                                "value": 0
                            },
                            {
                                "caption": "Blau",
                                "value": 1
                            },
                            {
                                "caption": "Grün",
                                "value": 2
                            },
                            {
                                "caption": "Türkis",
                                "value": 3
                            },
                            {
                                "caption": "Rot",
                                "value": 4
                            },
                            {
                                "caption": "Violett",
                                "value": 5
                            },
                            {
                                "caption": "Gelb",
                                "value": 6
                            },
                            {
                                "caption": "Weiß",
                                "value": 7
                            }
                        ]
                    }
                },
                {
                    "caption": "LED Modus",
                    "name": "LED_Mode",
                    "width": "100px",
                    "add": 1,
                    "edit": {
                        "type": "Select",
                        "options": [
                            {
                                "caption": "Dauerlicht",
                                "value": 1
                            },
                            {
                                "caption": "Blinken (L)",
                                "value": 2
                            },
                            {
                                "caption": "Blinken (M)",
                                "value": 3
                            },
                            {
                                "caption": "Blinken (S)",
                                "value": 4
                            },
                            {
                                "caption": "Blitzen (L)",
                                "value": 5
                            },
                            {
                                "caption": "Blitzen (M)",
                                "value": 6
                            },
                            {
                                "caption": "Blitzen (S)",
                                "value": 7
                            },
                            {
                                "caption": "Pulsieren (L)",
                                "value": 8
                            },
                            {
                                "caption": "Pulsieren (M)",
                                "value": 9
                            },
                            {
                                "caption": "Pulsieren (S)",
                                "value": 10
                            }
                        ]
                    }
                },
                {
                    "caption": "Sirenen-Instanz",
                    "name": "HmIP_Siren_Inst",
                    "width": "120px",
                    "add": 0,
                    "edit": {
                        "type": "SelectInstance"
                    }
                },
                {
                    "caption": "Sirene Ton",
                    "name": "Siren_Acoustic",
                    "width": "120px",
                    "add": 0,
                    "edit": {
                        "type": "Select",
                        "options": [
                            {
                                "caption": "Kein Ton",
                                "value": 0
                            },
                            {
                                "caption": "Frequenz steigend",
                                "value": 1
                            },
                            {
                                "caption": "Frequenz fallend",
                                "value": 2
                            },
                            {
                                "caption": "Frequenz steigen/fallend",
                                "value": 3
                            },
                            {
                                "caption": "Frequenz tief/hoch",
                                "value": 4
                            },
                            {
                                "caption": "Frequenz tief",
                                "value": 5
                            },
                            {
                                "caption": "Frequenz hoch",
                                "value": 6
                            }
                        ]
                    }
                },
                {
                    "caption": "Sirene Optik",
                    "name": "Siren_Optical",
                    "width": "120px",
                    "add": 0,
                    "edit": {
                        "type": "Select",
                        "options": [
                            {
                                "caption": "Kein Licht",
                                "value": 0
                            },
                            {
                                "caption": "Blinken",
                                "value": 1
                            },
                            {
                                "caption": "Blitzen",
                                "value": 2
                            }
                        ]
                    }
                },
                {
                    "caption": "Ziel-Variable",
                    "name": "TargetVariableID",
                    "width": "120px",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Ziel-Wert",
                    "name": "TargetVariableValue",
                    "width": "100px",
                    "add": "",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                }
            ]
        }
    ],
    "actions": [
        {
            "type": "Label",
            "caption": "Test: Aktions-Profil testen"
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "ValidationTextBox",
                    "name": "TestProfileID",
                    "caption": "Profil-ID (Name)",
                    "value": "Briefkasten"
                },
                {
                    "type": "Button",
                    "caption": "Profil testen (An)",
                    "onClick": "SAM_TestProfile($id, $TestProfileID, false);",
                    "icon": "Play"
                },
                {
                    "type": "Button",
                    "caption": "Profil stoppen (Aus)",
                    "onClick": "SAM_TestProfile($id, $TestProfileID, true);",
                    "icon": "Stop"
                }
            ]
        }
    ]
}
EOT;
    }
}


