<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_HardwareControl.php';
require_once __DIR__ . '/../libs/Trait_CentralStateAware.php';

class SmartPresenceSimulation extends IPSModuleStrict
{
    use SmartLog_Trait;
    use HardwareControl_Trait;
    use CentralStateAware_Trait;
    public function Create(): void
    {
        parent::Create();

        // Gemini API-Key und Modell werden zentral über SmartGeminiIO konfiguriert.
        $this->RegisterPropertyInteger('SunsetVariableID', 0);
        $this->RegisterPropertyInteger('ArchiveControlID', 0);
        $this->RegisterPropertyInteger('RegistryID', 0);

        $this->RegisterAttributeString('LightSchedule', '[]');

        $this->RegisterVariableString('LightScheduleStatus', 'Aktueller KI-Schaltplan', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'        => 'Clock'
        ], 1);
        $this->RegisterVariableBoolean('GeminiError', 'Fehler aufgetreten', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Alert',
            'COLOR' => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => json_encode([
                ['Value' => false, 'Caption' => 'OK', 'IconValue' => 'Alert', 'IconActive' => false, 'ColorActive' => false, 'ColorDisplay' => 0x00CC00, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00CC00],
                ['Value' => true, 'Caption' => 'Fehler!', 'IconValue' => 'Alert', 'IconActive' => false, 'ColorActive' => false, 'ColorDisplay' => 0xFF0000, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF0000]
            ])
        ], 2);
        
        $this->RegisterVariableInteger('ActiveLightsCount', 'Aktive Lampen (Zähler)', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'        => 'Bulb',
            'SUFFIX'      => ' an'
        ], 3);
        $this->RegisterVariableString('ActiveLightsList', 'Aktive Lampen (Namen)', [
            'PRESENTATION'=> VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'        => 'Bulb'
        ], 4);
        $this->RegisterVariableBoolean('AlarmLightsOnDuringAbsence', 'Alarm: Licht brennt bei Abwesenheit', [
            'PRESENTATION'  => VARIABLE_PRESENTATION_SWITCH,
            'ICON'          => 'Warning'
        ], 5);
        $this->EnableAction('AlarmLightsOnDuringAbsence');

        $this->RegisterTimer('LightExecutionTimer', 0, 'SPS_CheckAndExecuteLightSchedule($_IPS[\'TARGET\']);');
        $this->RegisterTimer('GeminiRetryTimer', 0, 'SPS_GenerateAiSchedule($_IPS[\'TARGET\'], true);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SubscribeToCentralStates(['PresenceMode', 'ActivityMode']);
        // --- Auto-generated References ---
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $ref_SunsetVariableID = $this->ReadPropertyInteger('SunsetVariableID');
        if ($ref_SunsetVariableID > 1 && @IPS_ObjectExists($ref_SunsetVariableID)) {
            $this->RegisterReference($ref_SunsetVariableID);
        }
        $ref_ArchiveControlID = $this->ReadPropertyInteger('ArchiveControlID');
        if ($ref_ArchiveControlID > 1 && @IPS_ObjectExists($ref_ArchiveControlID)) {
            $this->RegisterReference($ref_ArchiveControlID);
        }
        $ref_RegistryID = $this->ReadPropertyInteger('RegistryID');
        if ($ref_RegistryID > 1 && @IPS_ObjectExists($ref_RegistryID)) {
            $this->RegisterReference($ref_RegistryID);
        }
        // ---------------------------------
        

        $geminiInstances = IPS_GetInstanceListByModuleID('{4C8B2A6D-9E3F-4A7B-8C5D-1F6E2A3B7C4D}');
        if (empty($geminiInstances)) {
            $this->SetStatus(201); // Inactive — SmartGeminiIO fehlt
            return;
        }

        $regId = $this->ReadPropertyInteger('RegistryID');
        if ($regId > 1 && @IPS_ObjectExists($regId) && function_exists('SDR_GetDevices')) {
            $allDevices = @SDR_GetDevices($regId);
            if (is_array($allDevices)) {
                foreach ($allDevices as $dev) {
                    // Only subscribe to Lights, Dimmers, Colors, and Switches
                    if (in_array($dev['Type'] ?? '', ['DevicesLight', 'DevicesLightDimmer', 'DevicesLightColor', 'DevicesSwitch'])) {
                        // Subscribe to OnOff or Brightness or Status
                        foreach (['OnOff_VarID', 'Status_VarID', 'Brightness_VarID'] as $field) {
                            $id = (int)($dev[$field] ?? 0);
                            if ($id > 0 && IPS_VariableExists($id)) {
                                $this->RegisterMessage($id, VM_UPDATE);
                                $this->RegisterReference($id);
                            }
                        }
                    }
                }
            }
        }
        $this->CalculateActiveLights();
        $this->MaintainDailyEvent();
        $this->SetStatus(102);

        // Bei laufender Abwesenheit: Simulation wiederherstellen (z.B. nach Reboot)
        $this->EnsureSimulationIfAbsent();

        $this->EnsureArchiving();
        $this->UpdateLinkFolders();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($this->HandleCentralStateMessage($SenderID, $Message, $Data)) return;
        if ($Message == VM_UPDATE) {
            $this->CalculateActiveLights();
        }
    }

    private function CalculateActiveLights(): void
    {
        $regId = $this->ReadPropertyInteger('RegistryID');
        $count = 0;
        $activeNames = [];
        
        if ($regId > 1 && @IPS_ObjectExists($regId) && function_exists('SDR_GetDevices')) {
            $allDevices = @SDR_GetDevices($regId);
            if (is_array($allDevices)) {
                foreach ($allDevices as $dev) {
                    if (in_array($dev['Type'] ?? '', ['DevicesLight', 'DevicesLightDimmer', 'DevicesLightColor', 'DevicesSwitch'])) {
                        $isActive = false;
                        
                        // Check state
                        $vid = (int)($dev['OnOff_VarID'] ?? 0);
                        if ($vid == 0) $vid = (int)($dev['Status_VarID'] ?? 0);
                        if ($vid > 0 && IPS_VariableExists($vid)) {
                            $isActive = (bool)GetValue($vid);
                        } else {
                            // Dimmer fallback
                            $vid = (int)($dev['Brightness_VarID'] ?? 0);
                            if ($vid > 0 && IPS_VariableExists($vid)) {
                                $isActive = (GetValue($vid) > 0);
                            }
                        }
                        
                        if ($isActive) {
                            $count++;
                            $name = ($dev['room'] ?? '') . ' ' . ($dev['name'] ?? '');
                            if (trim($name) === '') $name = IPS_GetName($vid);
                            $activeNames[] = trim($name);
                        }
                    }
                }
            }
        }

        $this->SetValueIfChanged('ActiveLightsCount', $count);
        
        if ($count == 0) {
            $this->SetValueIfChanged('ActiveLightsList', 'Alle aus');
        } else {
            $namesStr = implode(", ", $activeNames);
            $this->SetValueIfChanged('ActiveLightsList', $namesStr);
        }
        
        $this->SortLinkFolders();
    }

    public function GetActiveLights(): array
    {
        $this->CalculateActiveLights();
        $count = GetValue($this->GetIDForIdent('ActiveLightsCount'));
        if ($count > 0) {
            $list = GetValue($this->GetIDForIdent('ActiveLightsList'));
            return explode(", ", $list);
        }
        return [];
    }

    protected function OnCentralStateChanged(string $stateName, mixed $newValue): void
    {
        $this->updateLightingMode();
    }

    private function updateLightingMode(): void
    {
        $isAbsence = ($this->IsAway() || $this->IsVacation());
        $isSleep = $this->IsSleeping();
        
        $eid = $this->MaintainDailyEvent();
        
        if ($isAbsence) {
            $this->GenerateAiSchedule();
            IPS_SetEventActive($eid, true);
            $this->SetTimerInterval('LightExecutionTimer', 60000);
            $this->SLogInfo( 'Präsenzsimulation gestartet.', "Hausmodus: Abwesend");
            $this->TurnOffAllSimulatedLights(); // Zuerst alles aus
            
            // Check if any lights are STILL on (meaning they were manually turned on and forgotten)
            $this->CalculateActiveLights();
            if ($this->GetValue('ActiveLightsCount') > 0) {
                $this->SetValueIfChanged('AlarmLightsOnDuringAbsence', true);
                $this->SLogWarning( 'Alarm: Bei Abwesenheit ist noch Licht an!', "Aktive Lampen: " . $this->GetValue('ActiveLightsList'));
            }
        } else {
            // Wenn Präsenzsimulation lief, schalten wir sie ab
            $wasActive = IPS_GetEvent($eid)['EventActive'];
            
            IPS_SetEventActive($eid, false);
            $this->SetTimerInterval('LightExecutionTimer', 0);
            $this->SetTimerInterval('GeminiRetryTimer', 0);
            $this->WriteAttributeString('LightSchedule', '[]');
            $this->SetValueIfChanged('LightScheduleStatus', 'Abwesenheit inaktiv - Kein Plan generiert');
            $this->SetValueIfChanged('GeminiError', false);
            $this->SetValueIfChanged('AlarmLightsOnDuringAbsence', false);
            
            if ($isSleep) { // Schlafen
                $this->TurnOffAllSimulatedLights();
                $this->SLogInfo( 'Alle Lichter aus.', 'Grund: Schlafen aktiv');
            } else {
                // Bei Rückkehr (0, 3, 4) machen wir die simulierten Lichter aus, 
                // aber nur wenn die Simulation davor lief.
                if ($wasActive) {
                    $this->TurnOffAllSimulatedLights(true);
                    $this->SLogInfo( 'Präsenzsimulation gestoppt und Lichter aus.', "Hausmodus: Anwesend");
                }
            }
        }
    }

    public function GenerateAiSchedule(bool $isRetry = false): void
    {
        if (!$isRetry) {
            $this->SetBuffer('GeminiRetryCount', '0');
            $this->SetTimerInterval('GeminiRetryTimer', 0);
        }

        $geminiInstances = IPS_GetInstanceListByModuleID('{4C8B2A6D-9E3F-4A7B-8C5D-1F6E2A3B7C4D}');
        if (empty($geminiInstances)) {
            $this->SetValue('GeminiError', true);
            return;
        }
        $geminiId = $geminiInstances[0];
        $sunsetVarId = $this->ReadPropertyInteger('SunsetVariableID');
        $archiveId = $this->ReadPropertyInteger('ArchiveControlID');

        $this->SetValue('GeminiError', false);
        $this->SetValue('LightScheduleStatus', 'Starte KI-Generierung... Bitte warten.');

        // Sicherstellen, dass Timer und Event aktiv sind (z.B. nach Reboot oder täglichem Event)
        if (!$this->IsHome()) {
            $this->SetTimerInterval('LightExecutionTimer', 60000);
            $eid = @IPS_GetObjectIDByIdent('DailyScheduleEvent', $this->InstanceID);
            if ($eid !== false) {
                IPS_SetEventActive($eid, true);
            }
        }

        if ($sunsetVarId == 0 || $archiveId == 0) {
            $this->SetValue('GeminiError', true);
            return;
        }

        $sunsetTimeStr = "18:00";
        if (IPS_VariableExists($sunsetVarId)) {
            $val = GetValue($sunsetVarId);
            if (is_int($val)) {
                $sunsetTimeStr = date('H:i', $val);
            } else {
                $sunsetTimeStr = (string)$val;
            }
        }

        $regId = $this->ReadPropertyInteger('RegistryID');
        if ($regId <= 1 || !@IPS_ObjectExists($regId) || !function_exists('SDR_GetDevices')) return;
        $allDevices = @SDR_GetDevices($regId);
        if (!is_array($allDevices) || count($allDevices) == 0) return;

        $startTime = time() - (14 * 24 * 60 * 60);
        $endTime = time();
        $historyDataSwitches = [];
        $historyDataDimmers = [];

        foreach ($allDevices as $dev) {
            if (!in_array($dev['Type'] ?? '', ['DevicesLight', 'DevicesLightDimmer', 'DevicesLightColor', 'DevicesSwitch'])) {
                continue;
            }
            $name = ($dev['room'] ?? '') . ' / ' . ($dev['name'] ?? '');
            
            $isDimmer = ($dev['Type'] === 'DevicesLightDimmer');
            $vid = $isDimmer ? (int)($dev['Brightness_VarID'] ?? 0) : (int)($dev['OnOff_VarID'] ?? $dev['Status_VarID'] ?? 0);
            
            if ($vid > 0 && IPS_VariableExists($vid) && AC_GetLoggingStatus($archiveId, $vid)) {
                $values = AC_GetLoggedValues($archiveId, $vid, $startTime, $endTime, 50);
                $compactLog = [];
                foreach ($values as $v) {
                    $compactLog[] = ["time"=> date('Y-m-d H:i', $v['TimeStamp']), "val"=> $v['Value']];
                }
                
                if ($isDimmer) {
                    $historyDataDimmers[$vid] = ["name"=> $name, "log"=> $compactLog];
                } else {
                    $historyDataSwitches[$vid] = ["name"=> $name, "log"=> $compactLog];
                }
            }
        }

        $prompt = "Du bist eine Smart Home KI. Heute ist der ". date('Y-m-d') . ". Der Sonnenuntergang ist um ". $sunsetTimeStr . "Uhr.\n";
        $prompt .= "Hier sind die historischen Schaltdaten der Lichter der letzten 14 Tage inkl. Name/Raum als JSON:\n";
        if (count($historyDataSwitches) > 0) {
            $prompt .= "Geräte vom Typ SCHALTER (Werte: true/false):\n". json_encode($historyDataSwitches) . "\n";
        }
        if (count($historyDataDimmers) > 0) {
            $prompt .= "Geräte vom Typ DIMMER (Werte: 0-100):\n". json_encode($historyDataDimmers) . "\n";
        }
        $prompt .= "Generiere einen realistischen Schaltplan für den heutigen Abend, der echte Anwesenheit simuliert und sich an den historischen Daten orientiert. Nutze die Raumnamen, um einen logischen Ablauf (z.B. Wohnzimmer vor Schlafzimmer) zu erstellen. ";
        $prompt .= "Ignoriere unwichtige Räume (wie Keller oder Abstellkammer) selbstständig. ";
        $prompt .= "Entscheide für jedes eingeschaltete Gerät zusätzlich, ob es bei vorzeitiger Heimkehr der Bewohner absichtlich eingeschaltet bleiben soll (z.B. Außenlicht, Flur, Haustür) oder abgeschaltet werden soll (Schlafzimmer).\n";
        $prompt .= "Antworte AUSSCHLIESSLICH im folgenden JSON Format (ohne Markdown, ohne Erklärungen), verwende für 'device' zwingend die übermittelte numerische ID:\n";
        $prompt .= "[ {\"time\":\"HH:MM\", \"device\": 12345, \"state\": true/false/dimvalue, \"keep_on_return\": true/false} ]";

        // Async via SmartGeminiIO — 'application/json' = JSON-Modus ohne formales Schema
        $instanceId = $this->InstanceID;
        $script = '<?php
            $result = GIO_Query(' . $geminiId . ',
                ' . var_export($prompt, true) . ',
                \'Du bist eine Smart Home KI. Antworte AUSSCHLIESSLICH mit einem JSON-Array ohne Markdown.\',
                \'application/json\',
                0.2
            );
            SPS_ProcessGeminiResult(' . $instanceId . ', $result);
        ';
        IPS_RunScriptText($script);
    }

    public function ProcessGeminiResult(string $scheduleJson): void
    {
        if (empty($scheduleJson)) {
            $this->HandleGeminiError('SmartGeminiIO lieferte keine Antwort.');
            return;
        }

        $scheduleArray = $this->safeJsonDecode($scheduleJson, true);
        if (is_array($scheduleArray)) {
            $this->WriteAttributeString('LightSchedule', json_encode($scheduleArray));
            $this->SetBuffer('GeminiRetryCount', '0');
            $this->SetTimerInterval('GeminiRetryTimer', 0);
            $this->SetValue('GeminiError', false);

            $lightNames = [];
            $regId = $this->ReadPropertyInteger('RegistryID');
            if ($regId > 1 && @IPS_ObjectExists($regId) && function_exists('SDR_GetDevices')) {
                $allDevices = @SDR_GetDevices($regId);
                if (is_array($allDevices)) {
                    foreach ($allDevices as $dev) {
                        $name = ($dev['room'] ?? '') . ' / ' . ($dev['name'] ?? '');
                        $vid = (int)($dev['OnOff_VarID'] ?? $dev['Status_VarID'] ?? $dev['Brightness_VarID'] ?? 0);
                        if ($vid > 0) $lightNames[$vid] = $name;
                    }
                }
            }

            $formattedSchedule = "Geplante Schaltvorgänge für heute:\n";
            foreach ($scheduleArray as $action) {
                $state = $action['state'] ? "AN" : "AUS";
                if (is_numeric($action['state']) && $action['state'] > 1) {
                    $state = "Wert: " . $action['state'];
                }
                $devName = isset($lightNames[$action['device']]) ? $lightNames[$action['device']] : "Gerät " . $action['device'];
                $formattedSchedule .= "- " . $action['time'] . "Uhr: " . $devName . "-> " . $state . "\n";
            }
            $this->SetValue('LightScheduleStatus', $formattedSchedule);
        } else {
            $this->HandleGeminiError("Ungültiges JSON empfangen.");
        }
    }

    private function HandleGeminiError(string $errorMsg): void
    {
        $retryCount = (int)$this->GetBuffer('GeminiRetryCount');
        if ($retryCount < 5) {
            $retryCount++;
            $this->SetBuffer('GeminiRetryCount', (string)$retryCount);
            $this->SetTimerInterval('GeminiRetryTimer', 5 * 60 * 1000);
            $this->SetValue('LightScheduleStatus', "Fehler aufgetreten. Starte Versuch $retryCount/5 in 5 Minuten...");
        } else {
            $this->SetTimerInterval('GeminiRetryTimer', 0);
            $this->SetValue('GeminiError', true);
            $this->SetValue('LightScheduleStatus', 'Fehler: API nicht erreichbar (Max Retries erreicht).');
        }
    }

    public function CheckAndExecuteLightSchedule(): void
    {
        $scheduleStr = $this->ReadAttributeString('LightSchedule');
        $schedule = $this->safeJsonDecode($scheduleStr, true);
        if (!is_array($schedule) || count($schedule) == 0) return;

        // Gerätenamen-Lookup für lesbares Logging aufbauen
        $lightNames = [];
        $regId = $this->ReadPropertyInteger('RegistryID');
        if ($regId > 1 && @IPS_ObjectExists($regId) && function_exists('SDR_GetDevices')) {
            $allDevices = @SDR_GetDevices($regId);
            if (is_array($allDevices)) {
                foreach ($allDevices as $dev) {
                    $name = ($dev['room'] ?? '') . ' / ' . ($dev['name'] ?? '');
                    $vid = (int)($dev['OnOff_VarID'] ?? $dev['Status_VarID'] ?? $dev['Brightness_VarID'] ?? 0);
                    if ($vid > 0) $lightNames[$vid] = $name;
                }
            }
        }

        $currentTime = date('H:i');
        $remainingSchedule = [];
        $executedSomething = false;

        foreach ($schedule as $action) {
            if ($action['time'] == $currentTime) {
                $devId    = $action['device'];
                $devState = $action['state'];
                $devName  = $lightNames[$devId] ?? ('Gerät ' . $devId);

                // Lesbarer Zustand für das Log
                if (is_bool($devState)) {
                    $stateLabel = $devState ? 'AN' : 'AUS';
                } elseif (is_numeric($devState)) {
                    $stateLabel = ($devState > 1) ? ('Dimmer ' . $devState . '%') : ($devState == 0 ? 'AUS' : 'AN');
                } else {
                    $stateLabel = var_export($devState, true);
                }

                if (IPS_VariableExists($devId)) {
                    if (!$this->safeRequestAction($devId, $devState)) {
                        $this->SLogWarning( "Aktor-Befehl fehlgeschlagen: $devName", "ID: $devId | Ziel: $stateLabel | Zeit: {$action['time']}");
                    } else {
                        $this->SLogInfo( "Licht (KI Plan) geschaltet: $devName → $stateLabel", "ID: $devId | Zeit: {$action['time']}");
                    }
                } else {
                    $this->SLogWarning( "Gerät nicht gefunden (KI Plan)", "ID: $devId | Name aus Plan: $devName | Zeit: {$action['time']}");
                }
                $executedSomething = true;
            } else {
                if ($action['time'] > $currentTime) {
                    $remainingSchedule[] = $action;
                }
            }
        }

        if ($executedSomething) {
            $this->WriteAttributeString('LightSchedule', json_encode($remainingSchedule));
            
            $lightNames = [];
            $regId = $this->ReadPropertyInteger('RegistryID');
            if ($regId > 1 && @IPS_ObjectExists($regId) && function_exists('SDR_GetDevices')) {
                $allDevices = @SDR_GetDevices($regId);
                if (is_array($allDevices)) {
                    foreach ($allDevices as $dev) {
                        $name = ($dev['room'] ?? '') . ' / ' . ($dev['name'] ?? '');
                        $vid = (int)($dev['OnOff_VarID'] ?? $dev['Status_VarID'] ?? $dev['Brightness_VarID'] ?? 0);
                        if ($vid > 0) $lightNames[$vid] = $name;
                    }
                }
            }

            $formattedSchedule = "Verbleibende Schaltvorgänge für heute:\n";
            if (count($remainingSchedule) == 0) {
                $formattedSchedule = "Keine weiteren Schaltvorgänge für heute geplant.";
            } else {
                foreach ($remainingSchedule as $action) {
                    $state = $action['state'] ? "AN": "AUS";
                    if (is_numeric($action['state']) && $action['state'] > 1) {
                        $state = "Wert: ". $action['state'];
                    }
                    $devName = isset($lightNames[$action['device']]) ? $lightNames[$action['device']] : "Gerät ". $action['device'];
                    $formattedSchedule .= "- ". $action['time'] . "Uhr: ". $devName . "-> ". $state . "\n";
                }
            }
            $this->SetValue('LightScheduleStatus', $formattedSchedule);
        }
    }

    private function TurnOffAllSimulatedLights(bool $respectKeepOnReturn = false): void
    {
        $keepIds = [];
        if ($respectKeepOnReturn) {
            $scheduleStr = $this->ReadAttributeString('LightSchedule');
            $schedule = $this->safeJsonDecode($scheduleStr, true);
            if (is_array($schedule)) {
                foreach ($schedule as $action) {
                    if (isset($action['keep_on_return']) && $action['keep_on_return'] === true) {
                        $keepIds[] = $action['device'];
                    }
                }
            }
        }

        $regId = $this->ReadPropertyInteger('RegistryID');
        if ($regId > 1 && @IPS_ObjectExists($regId) && function_exists('SDR_GetDevices')) {
            $allDevices = @SDR_GetDevices($regId);
            if (is_array($allDevices)) {
                foreach ($allDevices as $dev) {
                    if (!in_array($dev['Type'] ?? '', ['DevicesLight', 'DevicesLightDimmer', 'DevicesLightColor', 'DevicesSwitch'])) {
                        continue;
                    }
                    
                    $isDimmer = ($dev['Type'] === 'DevicesLightDimmer');
                    $vid = $isDimmer ? (int)($dev['Brightness_VarID'] ?? 0) : (int)($dev['OnOff_VarID'] ?? $dev['Status_VarID'] ?? 0);
                    $devName = ($dev['room'] ?? '') . ' / ' . ($dev['name'] ?? '');

                    if ($vid > 0 && IPS_VariableExists($vid)) {
                        if ($respectKeepOnReturn && in_array($vid, $keepIds)) {
                            $this->SLogInfo("Licht bleibt an (KeepOnReturn durch KI): $devName", "ID: $vid");
                            continue;
                        }

                        if ($isDimmer) {
                            if (GetValue($vid) > 0) {
                                if (!$this->safeRequestAction($vid, 0)) {
                                    $this->SLogWarning("Aktor-Befehl fehlgeschlagen: $devName (Dimmer)", "ID: $vid | Ziel: 0");
                                } else {
                                    $this->SLogInfo("Licht (Dimmer) ausgeschaltet: $devName", "ID: $vid");
                                }
                                usleep(100000);
                            }
                        } else {
                            if (GetValue($vid) === true) {
                                if (!$this->safeRequestAction($vid, false)) {
                                    $this->SLogWarning("Aktor-Befehl fehlgeschlagen: $devName", "ID: $vid | Ziel: AUS");
                                } else {
                                    $this->SLogInfo("Licht ausgeschaltet: $devName", "ID: $vid");
                                }
                                usleep(100000);
                            }
                        }
                    }
                }
            }
        }
    }



    private function MaintainDailyEvent(): int
    {
        $eid = @IPS_GetObjectIDByIdent('DailyScheduleEvent', $this->InstanceID);
        if ($eid === false) {
            $eid = IPS_CreateEvent(1);
            IPS_SetParent($eid, $this->InstanceID);
            IPS_SetIdent($eid, 'DailyScheduleEvent');
            IPS_SetName($eid, 'Täglicher KI Plan (12:00 Uhr)');
            IPS_SetEventCyclic($eid, 0, 0, 0, 0, 0, 0); 
            IPS_SetEventCyclicTimeFrom($eid, 12, 0, 0);
            IPS_SetEventActive($eid, false);
        }
        IPS_SetEventScript($eid, "SPS_GenerateAiSchedule(\$_IPS['TARGET']);");
        return $eid;
    }

    private function EnsureSimulationIfAbsent(): void
    {
        $isAbsence = ($this->IsAway() || $this->IsVacation());

        if (!$isAbsence) {
            return;
        }

        // Daily Event aktivieren
        $eid = @IPS_GetObjectIDByIdent('DailyScheduleEvent', $this->InstanceID);
        if ($eid !== false) {
            IPS_SetEventActive($eid, true);
        }

        // Execution Timer starten (jede Minute prüfen)
        $this->SetTimerInterval('LightExecutionTimer', 60000);

        // Wenn kein Schedule existiert, neuen generieren
        $scheduleStr = $this->ReadAttributeString('LightSchedule');
        $schedule = $this->safeJsonDecode($scheduleStr, true);
        if (!is_array($schedule) || count($schedule) == 0) {
            $this->SLogInfo( 'Präsenzsimulation nach Neustart wiederhergestellt.', 'Generiere neuen KI-Plan.');
            $this->GenerateAiSchedule();
        } else {
            $this->SLogInfo( 'Präsenzsimulation nach Neustart wiederhergestellt.', 'Bestehender Plan mit ' . count($schedule) . ' Einträgen aktiv.');
        }
    }
    
    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'AlarmLightsOnDuringAbsence') {
            $this->SetValue($Ident, false);
        }
    }

    private function SetValueIfChanged(string $Ident, $Value): void
    {
        $id = $this->GetIDForIdent($Ident);
        if (GetValue($id) !== $Value) {
            $this->SetValue($Ident, $Value);
        }
    }

    public function GetConfigurationForm(): string
    {
        return file_get_contents(__DIR__ . '/form.json');
    }

    private function EnsureArchiving(): void
    {
        $archiveID = $this->ReadPropertyInteger('ArchiveControlID');
        if ($archiveID <= 1 || !@IPS_InstanceExists($archiveID)) {
            return;
        }

        $regId = $this->ReadPropertyInteger('RegistryID');
        if ($regId <= 1 || !@IPS_ObjectExists($regId) || !function_exists('SDR_GetDevices')) return;
        $allDevices = @SDR_GetDevices($regId);
        if (!is_array($allDevices)) return;

        $changed = false;
        foreach ($allDevices as $dev) {
            if (!in_array($dev['Type'] ?? '', ['DevicesLight', 'DevicesLightDimmer', 'DevicesLightColor', 'DevicesSwitch'])) continue;
            
            $isDimmer = ($dev['Type'] === 'DevicesLightDimmer');
            $vid = $isDimmer ? (int)($dev['Brightness_VarID'] ?? 0) : (int)($dev['OnOff_VarID'] ?? $dev['Status_VarID'] ?? 0);
            
            if ($vid > 0 && IPS_VariableExists($vid)) {
                if (!AC_GetLoggingStatus($archiveID, $vid)) {
                    AC_SetLoggingStatus($archiveID, $vid, true);
                    $changed = true;
                }
            }
        }
        
        if ($changed) {
            IPS_ApplyChanges($archiveID);
        }
    }

    private function UpdateLinkFolders(): void
    {
        $this->SyncFolderLinks('FolderLampen', 'Lampen', 'LightVariables');
        $this->SyncFolderLinks('FolderDimmer', 'Dimmer', 'DimmerVariables');
    }

    private function SyncFolderLinks(string $ident, string $name, string $propertyName): void
    {
        $catID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        
        // Wenn es noch eine alte Kategorie (Ordner) ist, löschen wir diese kurz, um sie als Dummy-Modul neu anzulegen
        if ($catID !== false) {
            $obj = IPS_GetObject($catID);
            if ($obj['ObjectType'] === 0) { // 0 = Kategorie
                foreach (IPS_GetChildrenIDs($catID) as $childID) {
                    IPS_DeleteLink($childID);
                }
                IPS_DeleteCategory($catID);
                $catID = false;
            }
        }

        if ($catID === false) {
            // 485D0419-BE97-4548-AA9C-C083EB82E61E ist die GUID für das Dummy Modul
            $catID = IPS_CreateInstance('{485D0419-BE97-4548-AA9C-C083EB82E61E}');
            IPS_SetParent($catID, $this->InstanceID);
            IPS_SetIdent($catID, $ident);
            IPS_SetName($catID, $name);
        }

        $vars = $this->safeJsonDecode($this->ReadPropertyString($propertyName), true);
        if (!is_array($vars)) {
            $vars = [];
        }

        $validItems = [];
        foreach ($vars as $item) {
            $id = (int)($item['VariableID'] ?? 0);
            if ($id > 0 && IPS_VariableExists($id)) {
                $customName = trim($item['Name'] ?? '');
                if ($customName === '') {
                    $customName = IPS_GetName($id);
                }
                $validItems[$id] = $customName;
            }
        }

        // Veraltete Links löschen
        $children = IPS_GetChildrenIDs($catID);
        foreach ($children as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectType'] === 6) { // Link
                $link = IPS_GetLink($childID);
                if (!isset($validItems[$link['TargetID']])) {
                    IPS_DeleteLink($childID);
                }
            }
        }

        // Neue Links erstellen / Bestehende aktualisieren
        foreach ($validItems as $targetID => $desiredName) {
            $linkExists = false;
            foreach (IPS_GetChildrenIDs($catID) as $childID) {
                $obj = IPS_GetObject($childID);
                if ($obj['ObjectType'] === 6) {
                    $link = IPS_GetLink($childID);
                    if ($link['TargetID'] === $targetID) {
                        $linkExists = true;
                        // Name synchron halten
                        if (IPS_GetName($childID) !== $desiredName) {
                            IPS_SetName($childID, $desiredName);
                        }
                        break;
                    }
                }
            }
            if (!$linkExists) {
                $linkID = IPS_CreateLink();
                IPS_SetParent($linkID, $catID);
                IPS_SetLinkTargetID($linkID, $targetID);
                IPS_SetName($linkID, $desiredName);
                IPS_SetIcon($linkID, 'Bulb');
            }
        }
    }

    private function SortLinkFolders(): void
    {
        $this->SortFolderLinks('FolderLampen');
        $this->SortFolderLinks('FolderDimmer');
    }

    private function SortFolderLinks(string $ident): void
    {
        $catID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($catID === false) return;

        $children = IPS_GetChildrenIDs($catID);
        $activeLinks = [];
        $inactiveLinks = [];

        foreach ($children as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectType'] === 6) { // Link
                $link = IPS_GetLink($childID);
                $targetID = $link['TargetID'];
                $isActive = false;
                
                if (IPS_VariableExists($targetID)) {
                    $currentVal = GetValue($targetID);
                    if (is_bool($currentVal)) {
                        $isActive = $currentVal;
                    } else if (is_int($currentVal) || is_float($currentVal)) {
                        $isActive = ($currentVal > 0);
                    } else if (is_string($currentVal)) {
                        $isActive = (strtolower(trim($currentVal)) === 'true' || trim($currentVal) === '1');
                    }
                }

                if ($isActive) {
                    $activeLinks[] = ['id' => $childID, 'name' => $obj['ObjectName']];
                } else {
                    $inactiveLinks[] = ['id' => $childID, 'name' => $obj['ObjectName']];
                }
            }
        }

        // Alphabetisch sortieren
        usort($activeLinks, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        usort($inactiveLinks, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        // Aktive ganz nach oben, Inaktive nach unten
        $pos = 10;
        foreach ($activeLinks as $item) {
            IPS_SetPosition($item['id'], $pos++);
        }
        $pos = 1000;
        foreach ($inactiveLinks as $item) {
            IPS_SetPosition($item['id'], $pos++);
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