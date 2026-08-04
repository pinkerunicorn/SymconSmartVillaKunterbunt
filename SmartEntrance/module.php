<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_CentralStateAware.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

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
    use CentralStateAware_Trait;
    use DeviceAvailability_Trait;

    public function Create(): void
    {
        parent::Create();
        
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

        // --- Properties: Smart Locks (Tedee) ---
        $this->RegisterPropertyString('LockVariables', '[]');

        // --- Properties: Auto-Lock Timers ---
        $this->RegisterPropertyBoolean('AutoLockActive', false);
        $this->RegisterPropertyString('AutoLockTime', '{"hour":22,"minute":0,"second":0}');
        $this->RegisterPropertyBoolean('AutoUnlockActive', false);
        $this->RegisterPropertyString('AutoUnlockTime', '{"hour":7,"minute":0,"second":0}');
        $this->RegisterPropertyBoolean('AutoUnlockOnlyWhenPresent', true);

        // --- Timers ---
        $this->RegisterTimer('TimerAutoLock', 0, 'SHE_TimerAutoLock($_IPS[\'TARGET\']);');
        $this->RegisterTimer('TimerAutoUnlock', 0, 'SHE_TimerAutoUnlock($_IPS[\'TARGET\']);');
        $this->RegisterTimer('ResetDoorbell1', 0, 'SHE_ResetDoorbell($_IPS[\'TARGET\'], 1);');
        $this->RegisterTimer('ResetDoorbell2', 0, 'SHE_ResetDoorbell($_IPS[\'TARGET\'], 2);');

        // --- Variables ---
        $vid = @IPS_GetObjectIDByIdent('MailboxState', $this->InstanceID);
        if ($vid !== false && IPS_VariableExists($vid) && IPS_GetVariable($vid)['VariableType'] !== 0) {
            IPS_DeleteVariable($vid);
        }
        $this->RegisterVariableBoolean('MailboxState', 'Briefkasten', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Mailbox',
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
            'ICON' => 'Bell',
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => json_encode([
                ['Value' => false, 'Caption' => 'Ruhe', 'IconValue' => 'Bell', 'IconActive' => true,
                 'ColorActive' => true, 'ColorDisplay' => -1, 'ContentColorActive' => false,
                 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => -1],
                ['Value' => true, 'Caption' => 'Klingelt', 'IconValue' => 'Bell', 'IconActive' => true,
                 'ColorActive' => true, 'ColorDisplay' => 0xFF4400, 'ContentColorActive' => false,
                 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF4400]
            ])
        ], 2);
        
        $this->RegisterVariableBoolean('Doorbell2', 'Klingel 2', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Bell',
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => json_encode([
                ['Value' => false, 'Caption' => 'Ruhe', 'IconValue' => 'Bell', 'IconActive' => true,
                 'ColorActive' => true, 'ColorDisplay' => -1, 'ContentColorActive' => false,
                 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => -1],
                ['Value' => true, 'Caption' => 'Klingelt', 'IconValue' => 'Bell', 'IconActive' => true,
                 'ColorActive' => true, 'ColorDisplay' => 0xFF4400, 'ContentColorActive' => false,
                 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF4400]
            ])
        ], 3);
        
        // No EnableAction on Doorbells - Read Only for Visu/History
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
            'SourceAbsenceButton', 'TargetNotifier'
        ];
        foreach ($properties as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 1 && @IPS_ObjectExists($id)) {
                $this->RegisterReference($id);
                if ($prop !== 'TargetNotifier') {
                    $this->RegisterMessage($id, VM_UPDATE);
                }
            }
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
        $this->DA_SetAvailable(true);
        $this->SetStatus(102);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($this->HandleCentralStateMessage($SenderID, $Message, $Data)) return;

        if ($Message === VM_UPDATE) {
            $value = $Data[0];
            
            // Mailbox Flap
            if ($SenderID === $this->ReadPropertyInteger('SourceMailboxFlap') && $this->ValuesMatch($value, $this->ReadPropertyString('FlapTriggerValue'))) {
                $this->TriggerMailbox(true);
            }
            // Mailbox Door
            if ($SenderID === $this->ReadPropertyInteger('SourceMailboxDoor') && $this->ValuesMatch($value, $this->ReadPropertyString('DoorTriggerValue'))) {
                $this->TriggerMailbox(false);
            }
            // Doorbell 1
            if ($SenderID === $this->ReadPropertyInteger('SourceDoorbell1') && $this->ValuesMatch($value, $this->ReadPropertyString('Doorbell1TriggerValue'))) {
                $this->TriggerDoorbell(1);
            }
            // Doorbell 2
            if ($SenderID === $this->ReadPropertyInteger('SourceDoorbell2') && $this->ValuesMatch($value, $this->ReadPropertyString('Doorbell2TriggerValue'))) {
                $this->TriggerDoorbell(2);
            }
            // Absence Button
            if ($SenderID === $this->ReadPropertyInteger('SourceAbsenceButton') && $this->ValuesMatch($value, $this->ReadPropertyString('AbsenceButtonTriggerValue'))) {
                $this->TriggerAbsence();
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
            }
        } else {
            if ($this->GetValue('MailboxState') !== false) {
                $this->SetValue('MailboxState', false);
                $this->SLogInfo('Briefkasten', 'Wurde geleert.');
                // Lautlos leeren (keine Notifier Meldung wie gewünscht)
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

    private function SendToNotifier(string $title, string $message, int $priority): void
    {
        $notifierId = $this->ReadPropertyInteger('TargetNotifier');
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
        // React to Activity/Presence to auto-lock/unlock
        $isAbsence = $this->IsAway() || $this->IsVacation();
        $isSleep = $this->IsSleeping();
        $isCinema = $this->IsCinema();

        // Lock if Absence, Sleep, or Cinema
        if ($isAbsence || $isSleep || $isCinema) {
            $this->LockDoor();
            $this->SLogInfo('Türschloss', 'Zentrale Automatik: Verriegelung ausgelöst (Abwesenheit/Schlafen/Kino).');
        } else {
            $this->UnlockDoor();
            $this->SLogInfo('Türschloss', 'Zentrale Automatik: Entriegelung ausgelöst (Zuhause & Normal).');
        }
    }

    private function LockDoor(): void
    {
        $lockVars = $this->safeJsonDecode($this->ReadPropertyString('LockVariables'), true);
        if (!is_array($lockVars)) return;

        foreach ($lockVars as $lock) {
            $lockId = $lock['LockVariableID'] ?? 0;
            if ($lockId <= 0 || !IPS_VariableExists($lockId)) continue;
            
            $name = isset($lock['Name']) && $lock['Name'] != '' ? $lock['Name'] : IPS_GetName($lockId);

            if (!$this->IsDoorClosed($lock)) {
                $this->SLogWarning('Türschloss', "Verriegelung übersprungen: Die Tür '$name' ist noch offen!");
                continue;
            }

            $lockValue = $this->ParseTypedValue($lock['LockValue'] ?? '1');
            if (!$this->safeRequestAction($lockId, $lockValue)) {
                $this->SLogWarning('Türschloss', "Aktor-Befehl fehlgeschlagen für: $name");
            } else {
                $this->SLogInfo('Türschloss', "Erfolgreich verriegelt: $name");
            }
        }
    }

    private function UnlockDoor(): void
    {
        $lockVars = $this->safeJsonDecode($this->ReadPropertyString('LockVariables'), true);
        if (!is_array($lockVars)) return;

        foreach ($lockVars as $lock) {
            $lockId = $lock['LockVariableID'] ?? 0;
            if ($lockId <= 0 || !IPS_VariableExists($lockId)) continue;

            $name = isset($lock['Name']) && $lock['Name'] != '' ? $lock['Name'] : IPS_GetName($lockId);
            $unlockValue = $this->ParseTypedValue($lock['UnlockValue'] ?? '0');
            
            if (!$this->safeRequestAction($lockId, $unlockValue)) {
                $this->SLogWarning('Türschloss', "Aktor-Befehl fehlgeschlagen (Aufsperren) für: $name");
            } else {
                $this->SLogInfo('Türschloss', "Erfolgreich entriegelt: $name");
            }
        }
    }

    public function TimerAutoLock(): void
    {
        $this->LockDoor();
        $this->SLogInfo('Türschloss', 'Automatisches (zeitbasiertes) Verriegeln ausgeführt.');
        $this->UpdateTimers();
    }

    public function TimerAutoUnlock(): void
    {
        $this->UpdateTimers();

        if ($this->ReadPropertyBoolean('AutoUnlockOnlyWhenPresent')) {
            if ($this->IsAway() || $this->IsVacation()) {
                $this->SLogInfo('Türschloss', 'Zeitbasiertes Aufsperren übersprungen, da Abwesenheit aktiv ist.');
                return;
            }
        }

        $this->UnlockDoor();
        $this->SLogInfo('Türschloss', 'Automatisches (zeitbasiertes) Entriegeln ausgeführt.');
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

    // =========================================================================
    // Helpers & UI
    // =========================================================================

    private function UpdateTimers(): void
    {
        if ($this->ReadPropertyBoolean('AutoLockActive')) {
            $this->SetTimerInterval('TimerAutoLock', $this->GetMillisecondsToTime($this->ReadPropertyString('AutoLockTime')));
        } else {
            $this->SetTimerInterval('TimerAutoLock', 0);
        }

        if ($this->ReadPropertyBoolean('AutoUnlockActive')) {
            $this->SetTimerInterval('TimerAutoUnlock', $this->GetMillisecondsToTime($this->ReadPropertyString('AutoUnlockTime')));
        } else {
            $this->SetTimerInterval('TimerAutoUnlock', 0);
        }
    }

    private function GetMillisecondsToTime(string $timeStr): int
    {
        $time = $this->safeJsonDecode($timeStr, true);
        if (!is_array($time)) return 0;
        
        $now = time();
        $targetTime = mktime($time['hour'], $time['minute'], $time['second'], (int)date('m'), (int)date('d'), (int)date('Y'));
        
        if ($targetTime <= $now) {
            $targetTime += 86400; // Nächster Tag
        }
        
        return ($targetTime - $now) * 1000;
    }

    private function ValuesMatch($actual, $expected): bool
    {
        if ((string)$expected === '') return true;
        if (is_bool($actual)) {
            $targetBool = ($expected === 'true' || $expected === '1' || strtolower((string)$expected) === 'wahr');
            return ($actual === $targetBool);
        } elseif (is_int($actual)) {
            return ($actual === (int)$expected);
        } elseif (is_float($actual)) {
            return ($actual === (float)$expected);
        } elseif (is_string($actual)) {
            return (strtolower(trim($actual)) === strtolower(trim((string)$expected)));
        }
        return ($actual == $expected);
    }

    private function ParseTypedValue(string $val)
    {
        if ($val === 'true' || $val === 'True') return true;
        if ($val === 'false' || $val === 'False') return false;
        if (is_numeric($val)) {
            if (strpos((string)$val, '.') !== false) return (float)$val;
            return (int)$val;
        }
        return $val;
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
                        }
                    ]
                },
                {
                    "type": "Label",
                    "label": "Zeitsteuerung"
                },
                { "type": "CheckBox", "name": "AutoLockActive", "caption": "Automatisch Verschließen" },
                { "type": "SelectTime", "name": "AutoLockTime", "caption": "Uhrzeit zum Verschließen" },
                { "type": "CheckBox", "name": "AutoUnlockActive", "caption": "Automatisch Aufsperren" },
                { "type": "SelectTime", "name": "AutoUnlockTime", "caption": "Uhrzeit zum Aufsperren" },
                { "type": "CheckBox", "name": "AutoUnlockOnlyWhenPresent", "caption": "Aufsperren nur bei Anwesenheit" }
            ]
        },
        {
            "type": "ExpansionPanel",
            "caption": "📢 Notifier (Push & Sprachausgabe)",
            "items": [
                {
                    "type": "SelectInstance",
                    "name": "TargetNotifier",
                    "caption": "SmartNotifier Instanz"
                }
            ]
        }
    ]
}
EOT;
    }

    private function safeRequestAction(int $id, mixed $value): bool {
        try {
            $res = RequestAction($id, $value);
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
            return $this->safeJsonDecode($json, $assoc, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->SLogWarning("JSON Decode Exception", $e->getMessage());
            return $assoc ? [] : null;
        }
    }

}