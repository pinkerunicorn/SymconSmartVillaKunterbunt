<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_HardwareControl.php';
require_once __DIR__ . '/../libs/Trait_CentralStateAware.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';
require_once __DIR__ . '/../libs/Trait_InventoryAware.php';

/**
 * SmartHomeEntrance
 * Verwaltet Briefkasten, Klingelknöpfe, Abwesenheitstaster und das smarte Türschloss (Tedee).
 *
 * @author Florian Graßinger
 * @url https://github.com/pinkerunicorn/
 */
class SmartEntrance extends IPSModuleStrict
{
    use SmartLog_Trait;
    use HardwareControl_Trait;
    use CentralStateAware_Trait;
    use DeviceAvailability_Trait;
    use InventoryAware_Trait;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyInteger('SmartInventoryID', 0);
        
        $this->DA_RegisterAvailability(900);

        // --- Properties: Briefkasten ---
        $this->RegisterPropertyInteger('SourceMailboxFlap', 0);
        $this->RegisterPropertyString('FlapTriggerValue', 'true');
        $this->RegisterPropertyInteger('SourceMailboxDoor', 0);
        $this->RegisterPropertyString('DoorTriggerValue', 'true');

        // --- Properties: Türklingeln ---
        $this->RegisterPropertyInteger('SourceDoorbell1', 0);
        $this->RegisterPropertyString('SourceDoorbell1Name', 'Haustür');
        $this->RegisterPropertyString('Doorbell1TriggerValue', 'true');
        $this->RegisterPropertyInteger('SourceDoorbell2', 0);
        $this->RegisterPropertyString('SourceDoorbell2Name', 'Nebentür');
        $this->RegisterPropertyString('Doorbell2TriggerValue', 'true');

        // --- Properties: Abwesenheitstaster ---
        $this->RegisterPropertyInteger('SourceAbsenceButton', 0);
        $this->RegisterPropertyString('AbsenceButtonTriggerValue', 'true');

        // --- Properties: Notifier ---
        $this->RegisterPropertyInteger('TargetNotifier', 0);

        // --- Properties: MP3P Gong & Signalisierung ---
        $this->RegisterPropertyInteger('TargetMP3P', 0);

        // Klingel 1 (z.B. Haustür)
        $this->RegisterPropertyString('Doorbell1MP3P_Track', '1');
        $this->RegisterPropertyInteger('Doorbell1MP3P_Volume', 80);
        $this->RegisterPropertyInteger('Doorbell1MP3P_TrackDuration', 0); // 0 = 1x abspielen
        $this->RegisterPropertyInteger('Doorbell1MP3P_LEDColor', 1); // 1 = Blau
        $this->RegisterPropertyInteger('Doorbell1MP3P_LEDDuration', 5);

        // Klingel 2 (z.B. Nebentür / Einlieger)
        $this->RegisterPropertyString('Doorbell2MP3P_Track', '3');
        $this->RegisterPropertyInteger('Doorbell2MP3P_Volume', 80);
        $this->RegisterPropertyInteger('Doorbell2MP3P_TrackDuration', 0); // 0 = 1x abspielen
        $this->RegisterPropertyInteger('Doorbell2MP3P_LEDColor', 3); // 3 = Türkis
        $this->RegisterPropertyInteger('Doorbell2MP3P_LEDDuration', 5);

        // Briefkasten
        $this->RegisterPropertyString('MailboxMP3P_Track', '2');
        $this->RegisterPropertyInteger('MailboxMP3P_Volume', 50);
        $this->RegisterPropertyInteger('MailboxMP3P_TrackDuration', 0); // 0 = 1x abspielen
        $this->RegisterPropertyInteger('MailboxMP3P_LEDColor', 6); // 6 = Gelb / Orange
        $this->RegisterPropertyInteger('MailboxMP3P_LEDDuration', 5);

        // --- Properties: Smart Locks (Tedee) ---
        $this->RegisterPropertyString('LockVariables', '[]');

        // --- Properties: Garage ---
        $this->RegisterPropertyFloat('GarageThresholdGreen', 1.5);
        $this->RegisterPropertyFloat('GarageThresholdYellow', 0.8);
        $this->RegisterPropertyFloat('GarageThresholdRed', 0.4);
        $this->RegisterPropertyFloat('GarageThresholdBlink', 0.2);
        $this->RegisterPropertyInteger('SourceGarageParked', 0);
        $this->RegisterPropertyInteger('SourceGarageStatus', 0);

        // --- Timers ---
        $this->RegisterTimer('TimerLock1Lock', 0, 'SHE_TimerLockAction($_IPS[\'TARGET\'], 0, true);');
        $this->RegisterTimer('TimerLock1Unlock', 0, 'SHE_TimerLockAction($_IPS[\'TARGET\'], 0, false);');
        $this->RegisterTimer('TimerLock2Lock', 0, 'SHE_TimerLockAction($_IPS[\'TARGET\'], 1, true);');
        $this->RegisterTimer('TimerLock2Unlock', 0, 'SHE_TimerLockAction($_IPS[\'TARGET\'], 1, false);');
        $this->RegisterTimer('ResetDoorbell1', 0, 'SHE_ResetDoorbell($_IPS[\'TARGET\'], 1);');
        $this->RegisterTimer('ResetDoorbell2', 0, 'SHE_ResetDoorbell($_IPS[\'TARGET\'], 2);');

        // --- Legacy Cleanup ---
        $tid1 = @IPS_GetObjectIDByIdent('TimerAutoLock', $this->InstanceID);
        if ($tid1 !== false) { @IPS_DeleteEvent($tid1); }
        
        $tid2 = @IPS_GetObjectIDByIdent('TimerAutoUnlock', $this->InstanceID);
        if ($tid2 !== false) { @IPS_DeleteEvent($tid2); }

        // --- Variables ---
        $vid = @IPS_GetObjectIDByIdent('MailboxState', $this->InstanceID);
        if ($vid !== false && IPS_VariableExists($vid) && IPS_GetVariable($vid)['VariableType'] !== 0) {
            IPS_DeleteVariable($vid);
        }
        $this->RegisterVariableBoolean('MailboxState', 'Briefkasten', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'mailbox',
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => json_encode([
                ['Value' => false, 'Caption' => 'Leer', 'IconValue' => 'mailbox', 'IconActive' => true,
                 'ColorActive' => true, 'ColorDisplay' => 0x888888, 'ContentColorActive' => false,
                 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x888888],
                ['Value' => true, 'Caption' => 'Voll', 'IconValue' => 'mailbox-flag-up', 'IconActive' => true,
                 'ColorActive' => true, 'ColorDisplay' => 0xFFD700, 'ContentColorActive' => false,
                 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFFD700]
            ])
        ], 1);
        
        $this->RegisterVariableBoolean('Doorbell1', 'Klingel 1', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'bell',
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => json_encode([
                ['Value' => false, 'Caption' => 'Ruhe', 'IconValue' => 'bell', 'IconActive' => true,
                 'ColorActive' => true, 'ColorDisplay' => -1, 'ContentColorActive' => false,
                 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => -1],
                ['Value' => true, 'Caption' => 'Klingelt', 'IconValue' => 'bell', 'IconActive' => true,
                 'ColorActive' => true, 'ColorDisplay' => 0xFF4400, 'ContentColorActive' => false,
                 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF4400]
            ])
        ], 2);
        
        $this->RegisterVariableBoolean('Doorbell2', 'Klingel 2', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'bell',
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => json_encode([
                ['Value' => false, 'Caption' => 'Ruhe', 'IconValue' => 'bell', 'IconActive' => true,
                 'ColorActive' => true, 'ColorDisplay' => -1, 'ContentColorActive' => false,
                 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => -1],
                ['Value' => true, 'Caption' => 'Klingelt', 'IconValue' => 'bell', 'IconActive' => true,
                 'ColorActive' => true, 'ColorDisplay' => 0xFF4400, 'ContentColorActive' => false,
                 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF4400]
            ])
        ], 3);
        
        // Garage Variables
        $this->RegisterVariableBoolean('GarageParked', 'Auto geparkt', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Car',
            'OPTIONS' => json_encode([
                ['Value' => false, 'Caption' => 'Unterwegs', 'IconValue' => 'Car', 'IconActive' => true,
                 'ColorActive' => true, 'ColorDisplay' => 0x888888, 'ColorValue' => 0x888888,
                 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1],
                ['Value' => true, 'Caption' => 'Geparkt', 'IconValue' => 'House', 'IconActive' => true,
                 'ColorActive' => true, 'ColorDisplay' => 0x00CC00, 'ColorValue' => 0x00CC00,
                 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1]
            ])
        ], 10);
        
        $this->RegisterVariableString('GarageStatus', 'Ampel Status', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Information'
        ], 11);

        // No EnableAction on Doorbells - Read Only for Visu/History
    }

    public function Destroy(): void
    {
        parent::Destroy();
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
        $properties = [
            'SourceMailboxFlap', 'SourceMailboxDoor', 
            'SourceDoorbell1', 'SourceDoorbell2', 
            'SourceAbsenceButton', 'TargetMP3P',
            'SourceGarageParked', 'SourceGarageStatus'
        ];
        foreach ($properties as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 1 && @IPS_ObjectExists($id)) {
                $this->RegisterReference($id);
                if ($prop !== 'TargetMP3P') {
                    $this->RegisterMessage($id, VM_UPDATE);
                }
            }
        }

        $notifierId = $this->SINV_GetNotifierID();
        if ($notifierId > 1 && @IPS_ObjectExists($notifierId)) {
            $this->RegisterReference($notifierId);
        }
        
        $lockVars = $this->safeJsonDecode($this->ReadPropertyString('LockVariables'), true);
        if (is_array($lockVars)) {
            foreach ($lockVars as $lock) {
                if (isset($lock['SensorVariableID']) && $lock['SensorVariableID'] > 1 && @IPS_ObjectExists($lock['SensorVariableID'])) {
                    $this->RegisterReference($lock['SensorVariableID']);
                }
                if (isset($lock['LockVariableID']) && $lock['LockVariableID'] > 1 && @IPS_ObjectExists($lock['LockVariableID'])) {
                    $this->RegisterReference($lock['LockVariableID']);
                }
            }
        }

        $this->UpdateTimers();
        
        // Publish Garage Thresholds via MQTT
        $this->PublishMQTT('garagen-sensor/number/schwelle_gruen/command', (string)$this->ReadPropertyFloat('GarageThresholdGreen'));
        $this->PublishMQTT('garagen-sensor/number/schwelle_gelb/command', (string)$this->ReadPropertyFloat('GarageThresholdYellow'));
        $this->PublishMQTT('garagen-sensor/number/schwelle_rot/command', (string)$this->ReadPropertyFloat('GarageThresholdRed'));
        $this->PublishMQTT('garagen-sensor/number/schwelle_blinken/command', (string)$this->ReadPropertyFloat('GarageThresholdBlink'));
        $this->DA_SetAvailable(true);
        $this->SetStatus(102);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        $this->SLogInfo('MessageSink_RAW', "Sender=$SenderID, Message=$Message");
        
        if ($this->HandleCentralStateMessage($SenderID, $Message, $Data)) return;

        if ($Message === VM_UPDATE) {
            $value = $Data[0];
            $this->SLogInfo('MessageSink', "VM_UPDATE empfangen von ID: $SenderID mit Wert: [" . (string)$value . "]");
            
            // Mailbox Flap
            if ($SenderID === $this->ReadPropertyInteger('SourceMailboxFlap')) {
                $this->SLogInfo('MessageSink', "Prüfe Flap...");
                if ($this->ValuesMatch($value, $this->ReadPropertyString('FlapTriggerValue'), $SenderID)) {
                    $this->TriggerMailbox(true);
                }
            }
            // Mailbox Door
            if ($SenderID === $this->ReadPropertyInteger('SourceMailboxDoor')) {
                $this->SLogInfo('MessageSink', "Prüfe Door...");
                if ($this->ValuesMatch($value, $this->ReadPropertyString('DoorTriggerValue'), $SenderID)) {
                    $this->TriggerMailbox(false);
                }
            }
            // Doorbell 1
            if ($SenderID === $this->ReadPropertyInteger('SourceDoorbell1')) {
                $this->SLogInfo('MessageSink', "Prüfe Doorbell 1...");
                if ($this->ValuesMatch($value, $this->ReadPropertyString('Doorbell1TriggerValue'), $SenderID)) {
                    $this->TriggerDoorbell(1);
                }
            }
            // Doorbell 2
            if ($SenderID === $this->ReadPropertyInteger('SourceDoorbell2')) {
                $this->SLogInfo('MessageSink', "Prüfe Doorbell 2...");
                if ($this->ValuesMatch($value, $this->ReadPropertyString('Doorbell2TriggerValue'), $SenderID)) {
                    $this->TriggerDoorbell(2);
                }
            }
            // Garage Status
            if ($SenderID === $this->ReadPropertyInteger('SourceGarageStatus')) {
                if ($this->GetValue('GarageStatus') !== (string)$value) {
                    $this->SetValue('GarageStatus', (string)$value);
                }
            }
            // Garage Parked
            if ($SenderID === $this->ReadPropertyInteger('SourceGarageParked')) {
                if ($this->GetValue('GarageParked') !== (bool)$value) {
                    $this->SetValue('GarageParked', (bool)$value);
                }
            }
            // Absence Button
            if ($SenderID === $this->ReadPropertyInteger('SourceAbsenceButton')) {
                $this->SLogInfo('MessageSink', "Prüfe Absence Button...");
                if ($this->ValuesMatch($value, $this->ReadPropertyString('AbsenceButtonTriggerValue'), $SenderID)) {
                    $this->TriggerAbsence();
                }
            }
        }
    }

    // =========================================================================
    // Entrance Logic
    // =========================================================================

    private function TriggerMailbox(bool $receivedMail): void
    {
        if ($receivedMail) {
            if ($this->GetValue('MailboxState') !== true) {
                $this->SetValue('MailboxState', true);
                $this->SLogInfo('Briefkasten', 'Neue Post eingeworfen.');
                $this->SendToNotifier('Briefkasten', 'Es wurde Post eingeworfen.', 0);

                // MP3-Gong Signalisierung für Briefkasten
                $this->TriggerMP3P(
                    $this->ReadPropertyString('MailboxMP3P_Track'),
                    $this->ReadPropertyInteger('MailboxMP3P_Volume'),
                    $this->ReadPropertyInteger('MailboxMP3P_TrackDuration'),
                    $this->ReadPropertyInteger('MailboxMP3P_LEDColor'),
                    $this->ReadPropertyInteger('MailboxMP3P_LEDDuration')
                );
            }
        } else {
            if ($this->GetValue('MailboxState') !== false) {
                $this->SetValue('MailboxState', false);
                $this->SLogInfo('Briefkasten', 'Wurde geleert.');
                
                // MP3-Gong LED wieder ausschalten
                $mp3Id = $this->ReadPropertyInteger('TargetMP3P');
                if ($mp3Id > 0 && @IPS_InstanceExists($mp3Id)) {
                    try {
                        if (function_exists('MP3P_SetLight')) {
                            @MP3P_SetLight($mp3Id, 0, 0, 0); // Aus
                        } else {
                            @HM_WriteValueString($mp3Id, 'COMBINED_PARAMETER', 'L=0,DV=0,DU=0,RTV=0,RTU=1,C=0');
                        }
                    } catch (Exception $e) {
                        $this->SLogError('Briefkasten', 'Fehler beim Ausschalten der MP3P LED: ' . $e->getMessage());
                    }
                }
            }
        }
    }

    private function TriggerDoorbell(int $bellNumber): void
    {
        $ident = "Doorbell$bellNumber";
        $nameProp = "SourceDoorbell{$bellNumber}Name";
        $bellName = $this->ReadPropertyString($nameProp);
        
        $this->SetValue($ident, true);
        $this->SetTimerInterval("ResetDoorbell$bellNumber", 5000); // 5s pulse
        
        $this->SLogInfo('Klingel', "Klingel ausgelöst: $bellName");
        $this->SendToNotifier('Klingel', "Es hat an der Türklingel ($bellName) geklingelt.", 1);

        // Individuelle MP3-Gong Signalisierung je nach Klingel (Klingel 1 oder Klingel 2)
        $track         = $this->ReadPropertyString("Doorbell{$bellNumber}MP3P_Track");
        $volume        = $this->ReadPropertyInteger("Doorbell{$bellNumber}MP3P_Volume");
        $trackDuration = $this->ReadPropertyInteger("Doorbell{$bellNumber}MP3P_TrackDuration");
        $color         = $this->ReadPropertyInteger("Doorbell{$bellNumber}MP3P_LEDColor");
        $ledDuration   = $this->ReadPropertyInteger("Doorbell{$bellNumber}MP3P_LEDDuration");

        $this->TriggerMP3P($track, $volume, $trackDuration, $color, $ledDuration);
    }

    public function ResetDoorbell(int $bellNumber): void
    {
        $this->SetTimerInterval("ResetDoorbell$bellNumber", 0);
        $this->SetValue("Doorbell$bellNumber", false);
    }

    private function TriggerAbsence(): void
    {
        $this->SLogInfo('Abwesenheitstaster', 'Gedrückt. Schalte Hausmodus auf "Kurz weg".');
        
        // Finde SmartHomeControl Instanz um SetPresenceMode aufzurufen
        $shcInstances = IPS_GetInstanceListByModuleID('{460D7C60-0766-4534-BFD8-5920737B1845}');
        if (!empty($shcInstances)) {
            $shcID = $shcInstances[0];
            if (function_exists('SHC_SetPresenceMode')) {
                SHC_SetPresenceMode($shcID, 1); // 1 = PRESENCE_AWAY
            } else {
                IPS_RunScriptText("SHC_SetPresenceMode($shcID, 1);");
            }
        } else {
            $this->SLogError('SmartHomeControl', 'Instanz nicht gefunden! Hausmodus konnte nicht gesetzt werden.');
        }
    }

    private function TriggerMP3P(string $soundTrack, int $volume, int $trackDuration = 0, int $color = 0, int $duration = 5): void
    {
        $mp3Id = $this->ReadPropertyInteger('TargetMP3P');
        if ($mp3Id > 0 && @IPS_InstanceExists($mp3Id)) {
            try {
                if ($soundTrack !== '' && $volume > 0) {
                    if (function_exists('MP3P_PlaySound')) {
                        @MP3P_PlaySound($mp3Id, $soundTrack, $volume, $trackDuration);
                    } else {
                        $param = "L={$volume},DU=0,DV={$trackDuration},RTU=0,RTV=0,R=0,SL={$soundTrack}";
                        @HM_WriteValueString($mp3Id, 'COMBINED_PARAMETER', $param);
                    }
                }

                if ($color > 0) {
                    if (function_exists('MP3P_SetLight')) {
                        @MP3P_SetLight($mp3Id, $color, 100, $duration);
                    } else {
                        $rtu = ($duration === 0) ? 1 : 0;
                        $ledParam = "L=100,DV={$duration},DU=0,RTV=0,RTU={$rtu},C={$color}";
                        @HM_WriteValueString($mp3Id, 'COMBINED_PARAMETER', $ledParam);
                    }
                }
            } catch (Exception $e) {
                $this->SLogError('SmartEntrance MP3P', 'Fehler beim Ansteuern: ' . $e->getMessage());
            }
        }
    }

    private function SendToNotifier(string $title, string $message, int $priority): void
    {
        $notifierId = $this->SINV_GetNotifierID();
        if ($notifierId > 0 && @IPS_InstanceExists($notifierId)) {
            $payload = json_encode([
                'Title' => $title,
                'Message' => $message,
                'Priority' => $priority
            ]);
            try {
                if (function_exists('NOTIFY_SendEvent')) {
                    @NOTIFY_SendEvent($notifierId, $payload);
                } else {
                    IPS_RunScriptText("NOTIFY_SendEvent($notifierId, " . var_export($payload, true) . ");");
                }
            } catch (Exception $e) {
                $this->SLogError('Notifier', 'Fehler beim Senden: ' . $e->getMessage());
            }
        }
    }

    // =========================================================================
    // Smart Lock (Tedee) Logic
    // =========================================================================

    protected function OnCentralStateChanged(string $stateName, mixed $newValue): void
    {
        // Only react to PresenceMode changes for auto-lock/unlock
        if ($stateName !== 'PresenceMode') return;

        $isAbsence = $this->IsAway() || $this->IsVacation();

        // Lock if Absence
        if ($isAbsence) {
            $this->LockDoor();
            $this->SLogInfo('Türschloss', 'Zentrale Automatik: Verriegelung ausgelöst (Unterwegs/Urlaub).');
        } else if ($this->IsHome()) {
            // Unlock specific locks that are configured to unlock on presence
            $lockVars = $this->safeJsonDecode($this->ReadPropertyString('LockVariables'), true);
            if (!is_array($lockVars)) return;

            foreach ($lockVars as $index => $lock) {
                if (!empty($lock['UnlockOnPresence'])) {
                    $this->UnlockSingleDoor($index);
                }
            }
            $this->SLogInfo('Türschloss', 'Zentrale Automatik: Entriegelung ausgelöst (Zuhause) für konfigurierte Schlösser.');
        }
    }

    private function LockDoor(): void
    {
        $lockVars = $this->safeJsonDecode($this->ReadPropertyString('LockVariables'), true);
        if (!is_array($lockVars)) return;

        foreach ($lockVars as $lock) {
            $this->LockSpecificDoor($lock);
        }
    }

    private function UnlockDoor(): void
    {
        $lockVars = $this->safeJsonDecode($this->ReadPropertyString('LockVariables'), true);
        if (!is_array($lockVars)) return;

        foreach ($lockVars as $lock) {
            $this->UnlockSpecificDoor($lock);
        }
    }

    private function LockSingleDoor(int $index): void
    {
        $lockVars = $this->safeJsonDecode($this->ReadPropertyString('LockVariables'), true);
        if (!is_array($lockVars) || !isset($lockVars[$index])) return;
        $this->LockSpecificDoor($lockVars[$index]);
    }

    private function UnlockSingleDoor(int $index): void
    {
        $lockVars = $this->safeJsonDecode($this->ReadPropertyString('LockVariables'), true);
        if (!is_array($lockVars) || !isset($lockVars[$index])) return;
        $this->UnlockSpecificDoor($lockVars[$index]);
    }

    private function LockSpecificDoor(array $lock): void
    {
        $lockId = $lock['LockVariableID'] ?? 0;
        if ($lockId <= 0 || !IPS_VariableExists($lockId)) return;
        
        $name = isset($lock['Name']) && $lock['Name'] != '' ? $lock['Name'] : IPS_GetName($lockId);

        if (!$this->IsDoorClosed($lock)) {
            $this->SLogWarning('Türschloss', "Verriegelung übersprungen: Die Tür '$name' ist noch offen!");
            return;
        }

        $lockValue = $this->ParseTypedValue($lock['LockValue'] ?? '1');
        if (!$this->safeRequestAction($lockId, $lockValue)) {
            $this->SLogWarning('Türschloss', "Aktor-Befehl fehlgeschlagen für: $name");
        } else {
            $this->SLogInfo('Türschloss', "Erfolgreich verriegelt: $name");
        }
    }

    private function UnlockSpecificDoor(array $lock): void
    {
        $lockId = $lock['LockVariableID'] ?? 0;
        if ($lockId <= 0 || !IPS_VariableExists($lockId)) return;

        $name = isset($lock['Name']) && $lock['Name'] != '' ? $lock['Name'] : IPS_GetName($lockId);
        $unlockValue = $this->ParseTypedValue($lock['UnlockValue'] ?? '0');
        
        if (!$this->safeRequestAction($lockId, $unlockValue)) {
            $this->SLogWarning('Türschloss', "Aktor-Befehl fehlgeschlagen (Aufsperren) für: $name");
        } else {
            $this->SLogInfo('Türschloss', "Erfolgreich entriegelt: $name");
        }
    }

    public function TimerLockAction(int $index, bool $lock): void
    {
        if ($lock) {
            $this->LockSingleDoor($index);
            $this->SLogInfo('Türschloss', "Automatisches (zeitbasiertes) Verriegeln für Schloss " . ($index + 1) . " ausgeführt.");
        } else {
            $this->UnlockSingleDoor($index);
            $this->SLogInfo('Türschloss', "Automatisches (zeitbasiertes) Entriegeln für Schloss " . ($index + 1) . " ausgeführt.");
        }
        
        // Update ONLY the timer that just fired to prevent a race condition 
        // where updating all timers resets others that are scheduled for the exact same second.
        $lockVars = $this->safeJsonDecode($this->ReadPropertyString('LockVariables'), true);
        if (is_array($lockVars) && isset($lockVars[$index])) {
            $timeField = $lock ? 'AutoLockTime' : 'AutoUnlockTime';
            if (!empty($lockVars[$index][$timeField])) {
                $timeStr = is_string($lockVars[$index][$timeField]) ? $lockVars[$index][$timeField] : json_encode($lockVars[$index][$timeField]);
                $timerName = "TimerLock" . ($index + 1) . ($lock ? "Lock" : "Unlock");
                $this->SetTimerInterval($timerName, $this->GetMillisecondsToTime($timeStr));
            }
        }
    }

    private function IsDoorClosed(array $lock): bool
    {
        $sensorId = $lock['SensorVariableID'] ?? 0;
        if ($sensorId <= 0 || !IPS_VariableExists($sensorId)) {
            return true; // If no sensor configured, assume closed
        }
        
        $currentVal = GetValue($sensorId);
        $closedVal = $lock['ClosedValue'] ?? 'false';
        return $this->ValuesMatch($currentVal, $closedVal);
    }

    private function PublishMQTT(string $topic, string $payload): void
    {
        $mqttInstances = IPS_GetInstanceListByModuleID('{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}'); // MQTT Server
        if (!empty($mqttInstances)) {
            $mqttID = $mqttInstances[0];
            if (function_exists('MQTT_Publish')) {
                @MQTT_Publish($mqttID, $topic, $payload, 0, 0);
            }
        }
    }

    // =========================================================================
    // Helpers & UI
    // =========================================================================

    private function UpdateTimers(): void
    {
        $lockVars = $this->safeJsonDecode($this->ReadPropertyString('LockVariables'), true);
        if (!is_array($lockVars)) $lockVars = [];

        $this->SetTimerInterval('TimerLock1Lock', 0);
        $this->SetTimerInterval('TimerLock1Unlock', 0);
        $this->SetTimerInterval('TimerLock2Lock', 0);
        $this->SetTimerInterval('TimerLock2Unlock', 0);

        foreach ($lockVars as $index => $lock) {
            if ($index > 1) break; // We only support up to 2 locks with these fixed timers

            $timerLockName = "TimerLock" . ($index + 1) . "Lock";
            $timerUnlockName = "TimerLock" . ($index + 1) . "Unlock";

            if (!empty($lock['AutoLockTime'])) {
                $timeStr = is_string($lock['AutoLockTime']) ? $lock['AutoLockTime'] : json_encode($lock['AutoLockTime']);
                $this->SetTimerInterval($timerLockName, $this->GetMillisecondsToTime($timeStr));
            }

            if (!empty($lock['AutoUnlockTime'])) {
                $timeStr = is_string($lock['AutoUnlockTime']) ? $lock['AutoUnlockTime'] : json_encode($lock['AutoUnlockTime']);
                $this->SetTimerInterval($timerUnlockName, $this->GetMillisecondsToTime($timeStr));
            }
        }
    }

    private function GetMillisecondsToTime(mixed $time): int
    {
        if (is_string($time)) {
            $time = $this->safeJsonDecode($time, true);
        }
        if (!is_array($time)) return 0;
        
        $now = time();
        $target = mktime(
            (int)($time['hour'] ?? 0),
            (int)($time['minute'] ?? 0),
            (int)($time['second'] ?? 0),
            (int)date('n', $now),
            (int)date('j', $now),
            (int)date('Y', $now)
        );

        if ($target <= $now) {
            $target = mktime(
                (int)($time['hour'] ?? 0),
                (int)($time['minute'] ?? 0),
                (int)($time['second'] ?? 0),
                (int)date('n', $now),
                (int)date('j', $now) + 1,
                (int)date('Y', $now)
            );
        }

        return ($target - $now) * 1000;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "ExpansionPanel",
            "caption": "📬 Briefkasten",
            "items": [
                {
                    "type": "RowLayout",
                    "items": [
                        { "type": "SelectVariable", "name": "SourceMailboxFlap", "caption": "Klappe (Einwurf)" },
                        { "type": "ValidationTextBox", "name": "FlapTriggerValue", "caption": "Trigger-Wert (z.B. true)" }
                    ]
                },
                {
                    "type": "RowLayout",
                    "items": [
                        { "type": "SelectVariable", "name": "SourceMailboxDoor", "caption": "Tür (Entnahme)" },
                        { "type": "ValidationTextBox", "name": "DoorTriggerValue", "caption": "Trigger-Wert (z.B. true)" }
                    ]
                }
            ]
        },
        {
            "type": "ExpansionPanel",
            "caption": "🔔 Türklingeln",
            "items": [
                {
                    "type": "RowLayout",
                    "items": [
                        { "type": "SelectVariable", "name": "SourceDoorbell1", "caption": "Klingel 1 (Sensor)" },
                        { "type": "ValidationTextBox", "name": "Doorbell1TriggerValue", "caption": "Trigger-Wert" },
                        { "type": "ValidationTextBox", "name": "SourceDoorbell1Name", "caption": "Name (für Ansage)" }
                    ]
                },
                {
                    "type": "RowLayout",
                    "items": [
                        { "type": "SelectVariable", "name": "SourceDoorbell2", "caption": "Klingel 2 (Sensor)" },
                        { "type": "ValidationTextBox", "name": "Doorbell2TriggerValue", "caption": "Trigger-Wert" },
                        { "type": "ValidationTextBox", "name": "SourceDoorbell2Name", "caption": "Name (für Ansage)" }
                    ]
                }
            ]
        },
        {
            "type": "ExpansionPanel",
            "caption": "🔊 MP3-Gong Signalisierung (Klingeln & Briefkasten)",
            "items": [
                {
                    "type": "SelectInstance",
                    "name": "TargetMP3P",
                    "caption": "HmIP MP3P Instanz"
                },
                {
                    "type": "Label",
                    "bold": true,
                    "caption": "🔔 Klingel 1 Signalisierung (z.B. Haustür):"
                },
                {
                    "type": "RowLayout",
                    "items": [
                        { "type": "ValidationTextBox", "name": "Doorbell1MP3P_Track", "caption": "Track (z.B. 1)" },
                        { "type": "NumberSpinner", "name": "Doorbell1MP3P_Volume", "caption": "Lautstärke (%)", "minimum": 0, "maximum": 100, "suffix": "%" },
                        { "type": "NumberSpinner", "name": "Doorbell1MP3P_TrackDuration", "caption": "Track Dauer (s, 0=1x abspielen)", "minimum": 0, "suffix": "s" },
                        {
                            "type": "Select",
                            "name": "Doorbell1MP3P_LEDColor",
                            "caption": "LED Farbe",
                            "options": [
                                { "caption": "Aus", "value": 0 },
                                { "caption": "Blau", "value": 1 },
                                { "caption": "Grün", "value": 2 },
                                { "caption": "Türkis", "value": 3 },
                                { "caption": "Rot", "value": 4 },
                                { "caption": "Violett", "value": 5 },
                                { "caption": "Gelb / Orange", "value": 6 },
                                { "caption": "Weiß", "value": 7 }
                            ]
                        },
                        { "type": "NumberSpinner", "name": "Doorbell1MP3P_LEDDuration", "caption": "LED Dauer (s, 0=unendlich)", "minimum": 0, "suffix": "s" }
                    ]
                },
                {
                    "type": "Label",
                    "bold": true,
                    "caption": "🔔 Klingel 2 Signalisierung (z.B. Nebentür / Einlieger):"
                },
                {
                    "type": "RowLayout",
                    "items": [
                        { "type": "ValidationTextBox", "name": "Doorbell2MP3P_Track", "caption": "Track (z.B. 3)" },
                        { "type": "NumberSpinner", "name": "Doorbell2MP3P_Volume", "caption": "Lautstärke (%)", "minimum": 0, "maximum": 100, "suffix": "%" },
                        { "type": "NumberSpinner", "name": "Doorbell2MP3P_TrackDuration", "caption": "Track Dauer (s, 0=1x abspielen)", "minimum": 0, "suffix": "s" },
                        {
                            "type": "Select",
                            "name": "Doorbell2MP3P_LEDColor",
                            "caption": "LED Farbe",
                            "options": [
                                { "caption": "Aus", "value": 0 },
                                { "caption": "Blau", "value": 1 },
                                { "caption": "Grün", "value": 2 },
                                { "caption": "Türkis", "value": 3 },
                                { "caption": "Rot", "value": 4 },
                                { "caption": "Violett", "value": 5 },
                                { "caption": "Gelb / Orange", "value": 6 },
                                { "caption": "Weiß", "value": 7 }
                            ]
                        },
                        { "type": "NumberSpinner", "name": "Doorbell2MP3P_LEDDuration", "caption": "LED Dauer (s, 0=unendlich)", "minimum": 0, "suffix": "s" }
                    ]
                },
                {
                    "type": "Label",
                    "bold": true,
                    "caption": "📬 Briefkasten Signalisierung:"
                },
                {
                    "type": "RowLayout",
                    "items": [
                        { "type": "ValidationTextBox", "name": "MailboxMP3P_Track", "caption": "Track (z.B. 2)" },
                        { "type": "NumberSpinner", "name": "MailboxMP3P_Volume", "caption": "Lautstärke (%)", "minimum": 0, "maximum": 100, "suffix": "%" },
                        { "type": "NumberSpinner", "name": "MailboxMP3P_TrackDuration", "caption": "Track Dauer (s, 0=1x abspielen)", "minimum": 0, "suffix": "s" },
                        {
                            "type": "Select",
                            "name": "MailboxMP3P_LEDColor",
                            "caption": "LED Farbe",
                            "options": [
                                { "caption": "Aus", "value": 0 },
                                { "caption": "Blau", "value": 1 },
                                { "caption": "Grün", "value": 2 },
                                { "caption": "Türkis", "value": 3 },
                                { "caption": "Rot", "value": 4 },
                                { "caption": "Violett", "value": 5 },
                                { "caption": "Gelb / Orange", "value": 6 },
                                { "caption": "Weiß", "value": 7 }
                            ]
                        },
                        { "type": "NumberSpinner", "name": "MailboxMP3P_LEDDuration", "caption": "LED Dauer (s, 0=unendlich)", "minimum": 0, "suffix": "s" }
                    ]
                }
            ]
        },
        {
            "type": "ExpansionPanel",
            "caption": "🚗 Garagen-Einparkhilfe",
            "items": [
                {
                    "type": "RowLayout",
                    "items": [
                        { "type": "NumberSpinner", "name": "GarageThresholdGreen", "caption": "Schwelle Grün (m)", "digits": 2, "minimum": 0, "suffix": "m" },
                        { "type": "NumberSpinner", "name": "GarageThresholdYellow", "caption": "Schwelle Gelb (m)", "digits": 2, "minimum": 0, "suffix": "m" },
                        { "type": "NumberSpinner", "name": "GarageThresholdRed", "caption": "Schwelle Rot (m)", "digits": 2, "minimum": 0, "suffix": "m" },
                        { "type": "NumberSpinner", "name": "GarageThresholdBlink", "caption": "Schwelle Blinken (m)", "digits": 2, "minimum": 0, "suffix": "m" }
                    ]
                },
                {
                    "type": "RowLayout",
                    "items": [
                        { "type": "SelectVariable", "name": "SourceGarageStatus", "caption": "MQTT-Quelle: Ampel Status" },
                        { "type": "SelectVariable", "name": "SourceGarageParked", "caption": "MQTT-Quelle: Auto geparkt" }
                    ]
                }
            ]
        },
        {
            "type": "ExpansionPanel",
            "caption": "🚶‍♂️ Abwesenheitstaster",
            "items": [
                {
                    "type": "RowLayout",
                    "items": [
                        { "type": "SelectVariable", "name": "SourceAbsenceButton", "caption": "Taster (Sensor)" },
                        { "type": "ValidationTextBox", "name": "AbsenceButtonTriggerValue", "caption": "Trigger-Wert" }
                    ]
                },
                {
                    "type": "Label",
                    "caption": "Beim Drücken wird der Hausmodus auf 'Kurz weg' geschaltet (Garage schließt, Tür verriegelt)."
                }
            ]
        },
        {
            "type": "ExpansionPanel",
            "caption": "🔒 Smart Locks (Türen & Keller)",
            "items": [
                {
                    "type": "List",
                    "name": "LockVariables",
                    "caption": "Schlösser (z.B. Tedee)",
                    "rowCount": 5,
                    "add": true,
                    "delete": true,
                    "changeOrder": true,
                    "columns": [
                        {
                            "caption": "Name",
                            "name": "Name",
                            "width": "120px",
                            "add": "",
                            "edit": { "type": "ValidationTextBox" }
                        },
                        {
                            "caption": "Tür-Kontakt (Sensor)",
                            "name": "SensorVariableID",
                            "width": "200px",
                            "add": 0,
                            "edit": { "type": "SelectVariable" }
                        },
                        {
                            "caption": "Kontakt = Zu",
                            "name": "ClosedValue",
                            "width": "100px",
                            "add": "false",
                            "edit": { "type": "ValidationTextBox" }
                        },
                        {
                            "caption": "Schloss (Aktor)",
                            "name": "LockVariableID",
                            "width": "auto",
                            "add": 0,
                            "edit": { "type": "SelectVariable" }
                        },
                        {
                            "caption": "Wert f. Zu",
                            "name": "LockValue",
                            "width": "80px",
                            "add": "1",
                            "edit": { "type": "ValidationTextBox" }
                        },
                        {
                            "caption": "Wert f. Auf",
                            "name": "UnlockValue",
                            "width": "80px",
                            "add": "0",
                            "edit": { "type": "ValidationTextBox" }
                        },
                        {
                            "caption": "Auto-Sperren",
                            "name": "AutoLockTime",
                            "width": "120px",
                            "add": "",
                            "edit": { "type": "SelectTime" }
                        },
                        {
                            "caption": "Auto-Aufsperren",
                            "name": "AutoUnlockTime",
                            "width": "120px",
                            "add": "",
                            "edit": { "type": "SelectTime" }
                        },
                        {
                            "caption": "Aufsp. b. Anwesenh.",
                            "name": "UnlockOnPresence",
                            "width": "120px",
                            "add": false,
                            "edit": { "type": "CheckBox" }
                        }
                    ]
                }
            ]
        }
    ]
}
EOT;
    }

    private function ValuesMatch(mixed $currentVal, string $targetValStr, int $variableID = 0): bool
    {
        $origTarget = $targetValStr;
        $targetValStr = trim(strtolower($targetValStr));
        if ($targetValStr === 'true' || $targetValStr === '1') {
            $res = ((bool)$currentVal === true || (string)$currentVal === '1');
            if ($res) $this->SLogInfo('ValuesMatch', "Match durch true/1: VariableID=$variableID");
            return $res;
        }
        if ($targetValStr === 'false' || $targetValStr === '0') {
            $res = ((bool)$currentVal === false || (string)$currentVal === '0');
            if ($res) $this->SLogInfo('ValuesMatch', "Match durch false/0: VariableID=$variableID");
            return $res;
        }
        
        // Versuche das Variablenprofil aufzulösen (z.B. wenn der User "OPEN" eingetragen hat, die Variable aber 1 ist)
        if ($variableID > 0 && IPS_VariableExists($variableID)) {
            $var = IPS_GetVariable($variableID);
            $profileName = $var['VariableCustomProfile'] !== "" ? $var['VariableCustomProfile'] : $var['VariableProfile'];
            if ($profileName !== "") {
                $profile = IPS_GetVariableProfile($profileName);
                if (isset($profile['Associations'])) {
                    foreach ($profile['Associations'] as $assoc) {
                        if (strtolower(trim($assoc['Name'])) === $targetValStr) {
                            if ($currentVal == $assoc['Value']) {
                                $this->SLogInfo('ValuesMatch', "Match durch Profil gefunden: VariableID=$variableID, Association=" . $assoc['Name']);
                                return true;
                            }
                        }
                    }
                }
            }
        }
        
        $result = strtolower(trim((string)$currentVal)) === $targetValStr;
        if (!$result) {
            $this->SLogInfo('ValuesMatch', "Kein Match: VariableID=$variableID, Typ=" . gettype($currentVal) . ", currentVal=[" . (string)$currentVal . "], targetValStr=[$targetValStr]");
        }
        return $result;
    }

    private function ParseTypedValue(string $valStr): mixed
    {
        $str = trim(strtolower($valStr));
        if ($str === 'true') return true;
        if ($str === 'false') return false;
        if (is_numeric($str)) {
            return str_contains($str, '.') ? (float)$str : (int)$str;
        }
        return $valStr;
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
