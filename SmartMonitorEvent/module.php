<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_RegistryAware.php';

/**
 * SmartMonitorEvent
 * Verwaltet Haus-Ereignisse (Waschmaschine, Briefkasten etc.) mit Routing.
 *
 * @author Florian Graßinger
 * @url https://github.com/pinkerunicorn/SymconSmartVillaKunterbunt/tree/main/SmartMonitorEvent
 */
class SmartMonitorEvent extends IPSModuleStrict
{
    use SmartLog_Trait;
    use RegistryAware_Trait;

    public function Create(): void
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyBoolean("SimulationMode", false);
        $this->RegisterPropertyString("MonitoredEvents", "[]");
        $this->RegisterPropertyInteger("TargetNotifier", 0);
        $this->RegisterPropertyInteger("RegistryID", 0);

        // Variables
        $this->RegisterVariableInteger("ActiveEventsCount", "Aktive Ereignisse", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'bell'
        ], 1);
        $this->RegisterVariableString("LastEvent", "Letztes Ereignis", [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'envelope'
        ], 2);
    }

        public function GetConfigurationForm(): string
    {
        $form = [
            'elements' => [
                [
                    'type' => 'CheckBox',
                    'name' => 'SimulationMode',
                    'caption' => 'Simulationsmodus (Testbetrieb)'
                ],
                [
                    'type' => 'SelectModule',
                    'name' => 'RegistryID',
                    'caption' => 'Device Registry',
                    'moduleID' => '{F3B4A7D9-C59E-401A-B826-17D3B5C2849E}'
                ]
            ]
        ];
        
        $values = [];
        $regId = $this->ReadPropertyInteger('RegistryID');
        if ($regId > 1 && @IPS_InstanceExists($regId)) {
            if (function_exists('SDR_GetDevicesByType')) {
                $devices = @SDR_GetDevicesByType($regId, 'DevicesEvent');
                if (is_array($devices)) {
                    foreach ($devices as $dev) {
                        $vid = (int)($dev['Status_VarID'] ?? 0);
                        if ($vid > 0) {
                            $name = $dev['name'] ?? 'Unbekannt';
                            $room = $dev['room'] ?? '';
                            $level = (int)($dev['AlarmLevel'] ?? 0) === 1 ? '1 (Hinweis)' : '0 (Info)';
                            $msg = $dev['Message'] ?? '';
                            if ($msg === '') $msg = $name;
                            
                            $values[] = [
                                'name' => $name,
                                'room' => $room,
                                'level' => $level,
                                'msg' => $msg
                            ];
                        }
                    }
                }
            }
        }

        $form['elements'][] = [
            'type' => 'ExpansionPanel',
            'caption' => 'Haus-Ereignisse (Auto-Sync)',
            'items' => [
                [
                    'type' => 'Label',
                    'caption' => 'Alle Ereignisse werden automatisch aus der Device Registry ausgelesen. Benachrichtigungen werden zentral über den SmartNotifier geroutet.'
                ],
                [
                    'type' => 'List',
                    'name' => 'ReadOnlyEvents',
                    'caption' => 'Überwachte Ereignisse',
                    'add' => false,
                    'delete' => false,
                    'columns' => [
                        ['name' => 'name', 'caption' => 'Name', 'width' => '200px'],
                        ['name' => 'room', 'caption' => 'Raum', 'width' => '150px'],
                        ['name' => 'level', 'caption' => 'Stufe', 'width' => '100px'],
                        ['name' => 'msg', 'caption' => 'Meldung', 'width' => 'auto']
                    ],
                    'values' => $values
                ]
            ]
        ];

        return json_encode($form);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Unregister all old messages
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }

        // TargetNotifier Reference
        $notifierId = $this->DR_GetNotifierID();
        if ($notifierId > 1 && IPS_InstanceExists($notifierId)) {
            $this->RegisterReference($notifierId);
        }

        // Registry Reference
        $registryId = $this->ReadPropertyInteger('RegistryID');
        if ($registryId > 1 && IPS_InstanceExists($registryId)) {
            $this->RegisterReference($registryId);
            
            // Subscribe to ALL events from Registry
            if (function_exists('SDR_GetDevicesByType')) {
                $devices = @SDR_GetDevicesByType($registryId, 'DevicesEvent');
                if (is_array($devices)) {
                    foreach ($devices as $dev) {
                        $vid = (int)($dev['Status_VarID'] ?? 0);
                        if ($vid > 0 && IPS_VariableExists($vid)) {
                            $this->RegisterMessage($vid, VM_UPDATE);
                            $this->RegisterReference($vid);
                        }
                    }
                }
            }
        }

        $this->CalculateActiveEvents();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message !== VM_UPDATE) return;
        $val = $Data[0];
        $changed = $Data[1];
        
        if (!$changed) return; // Only process on change

        $registryId = $this->ReadPropertyInteger('RegistryID');
        if ($registryId > 1 && @IPS_InstanceExists($registryId) && function_exists('SDR_GetDevicesByType')) {
            $devices = @SDR_GetDevicesByType($registryId, 'DevicesEvent');
            if (is_array($devices)) {
                foreach ($devices as $dev) {
                    $vid = (int)($dev['Status_VarID'] ?? 0);
                    if ($vid === $SenderID) {
                        $this->HandleEventTrigger($dev, $val);
                        return; // Done
                    }
                }
            }
        }
    }

    private function HandleEventTrigger(array $eventDev, $currentValue): void
    {
        $vid = (int)($eventDev['Status_VarID'] ?? 0);
        if ($vid === 0) return;

        // Fallback NormalState
        $triggerValueStr = 'true'; // If normal is false, trigger is true

        // Read NormalState from Registry Device
        $normalState = strtolower(trim((string)($eventDev['NormalState'] ?? '')));
        if ($normalState === '') $normalState = 'false';
        
        // Invert normal state to get trigger value
        if ($normalState === 'true' || $normalState === '1') {
            $triggerValueStr = 'false';
        } elseif ($normalState === 'false' || $normalState === '0') {
            $triggerValueStr = 'true';
        } else {
            $triggerValueStr = $normalState; 
        }

        $currentValueStr = strtolower(trim((string)$currentValue));
        $isTriggered = false;
        
        // Boolean check
        if ($triggerValueStr === 'true' || $triggerValueStr === '1') {
            $isTriggered = (bool)$currentValue === true;
        } elseif ($triggerValueStr === 'false' || $triggerValueStr === '0') {
            $isTriggered = (bool)$currentValue === false;
        } else {
            // String/Int check
            $isTriggered = ($currentValueStr !== $triggerValueStr);
        }

        $messageText = $eventDev['Message'] ?? '';
        if ($messageText === '') $messageText = $eventDev['name'] ?? 'Unbekanntes Ereignis';
        
        $alarmLevel = (int)($eventDev['AlarmLevel'] ?? 0);
        $autoReset = (bool)($eventDev['AutoReset'] ?? true); // Default to true if not set

        // Acknowledge Variable (Event_XXX)
        $ident = 'Event_' . $vid;
        $eventVarID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);

        if ($isTriggered) {
            $this->LogMessage("Ereignis ausgeloest: {$messageText}", KL_NOTIFY);
            $this->SetValue('LastEvent', $messageText);

            if ($eventVarID === false) {
                // Ensure ~Switch is NOT used
                $eventVarID = $this->RegisterVariableBoolean($ident, $messageText, "", 10);
                $this->EnableAction($ident);
            }
            $this->SetValue($ident, true);
            IPS_SetHidden($eventVarID, false);

            // Route to Notifier (Phase B: No target checkboxes, all routed through Notifier)
            $this->SendToNotifier($messageText, $alarmLevel);
        } else {
            // Reset condition met
            if ($autoReset) {
                if ($eventVarID !== false) {
                    $this->SetValue($ident, false);
                    IPS_SetHidden($eventVarID, true);
                }
            }
        }
        
        $this->CalculateActiveEvents();
    }

    public function RequestAction($Ident, $Value): void
    {
        if (str_starts_with($Ident, 'Event_')) {
            $this->SetValue($Ident, $Value);
            if (!$Value) {
                $vid = @IPS_GetObjectIDByIdent($Ident, $this->InstanceID);
                if ($vid !== false) {
                    IPS_SetHidden($vid, true);
                }
            }
            $this->CalculateActiveEvents();
        }
    }

    private function CalculateActiveEvents(): void
    {
        $count = 0;
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $ident = IPS_GetObject($childID)['ObjectIdent'];
            if (str_starts_with($ident, 'Event_')) {
                if (GetValue($childID) === true) {
                    $count++;
                }
            }
        }
        $this->SetValue('ActiveEventsCount', $count);
    }

    private function SendToNotifier(string $message, int $level): void
    {
        $notifierId = $this->DR_GetNotifierID();
        if ($notifierId <= 1 || !IPS_InstanceExists($notifierId)) return;

        if ($this->ReadPropertyBoolean('SimulationMode')) {
            $this->LogMessage("SIMULATION: Sende '{$message}' an Notifier ({$notifierId}). Level: {$level}", KL_NOTIFY);
            return;
        }

        // Map Level
        $priority = 0; // Normal Info
        if ($level > 0) $priority = 1; // Warning

        if (function_exists('NOTIFY_SendEvent')) {
            @NOTIFY_SendEvent($notifierId, $message, $priority);
        }
    }
}
