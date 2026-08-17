<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';

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
                ],
                [
                    'type' => 'SelectModule',
                    'name' => 'TargetNotifier',
                    'caption' => 'SmartNotifier Instanz',
                    'moduleID' => '{B8A7F31D-E1D8-49A4-B9A9-5E9D5B4A1C8F}'
                ]
            ]
        ];
        
        $options = [];
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
                            $caption = $name . ($room !== '' ? " ($room)" : "");
                            $options[] = ['caption' => $caption, 'value' => (string)$vid];
                        }
                    }
                }
            }
        }

        if (empty($options)) {
            $options[] = ['caption' => 'Keine Ereignisse in Registry', 'value' => '0'];
        }

        $form['elements'][] = [
            'type' => 'ExpansionPanel',
            'caption' => '📅 Haus-Ereignisse & Komfort',
            'items' => [
                [
                    'type' => 'Label',
                    'caption' => 'Verknüpfe hier deine Ereignisse aus der Device Registry.'
                ],
                [
                    'type' => 'List',
                    'name' => 'MonitoredEvents',
                    'caption' => 'Ereignisse',
                    'add' => true,
                    'delete' => true,
                    'columns' => [
                        [
                            'name' => 'VariableID',
                            'caption' => 'Ereignis (aus Registry)',
                            'width' => '250px',
                            'add' => '0',
                            'edit' => [
                                'type' => 'Select',
                                'options' => $options
                            ]
                        ],
                        [
                            'name' => 'AlarmLevel',
                            'caption' => 'Stufe',
                            'width' => '100px',
                            'add' => 0,
                            'edit' => [
                                'type' => 'Select',
                                'options' => [
                                    [ "caption" => "0 (Info)", "value" => 0 ],
                                    [ "caption" => "1 (Hinweis)", "value" => 1 ]
                                ]
                            ]
                        ],
                        [
                            'name' => 'Message',
                            'caption' => 'Meldung (TTS/Push)',
                            'width' => '200px',
                            'add' => '',
                            'edit' => [
                                'type' => 'ValidationTextBox'
                            ]
                        ],
                        [
                            'name' => 'AutoReset',
                            'caption' => 'Auto-Reset',
                            'width' => '100px',
                            'add' => false,
                            'edit' => [
                                'type' => 'CheckBox'
                            ]
                        ],
                        [
                            'name' => 'TargetPush',
                            'caption' => 'Push',
                            'width' => '60px',
                            'add' => true,
                            'edit' => [
                                'type' => 'CheckBox'
                            ]
                        ],
                        [
                            'name' => 'TargetSonos',
                            'caption' => 'Sonos',
                            'width' => '60px',
                            'add' => false,
                            'edit' => [
                                'type' => 'CheckBox'
                            ]
                        ],
                        [
                            'name' => 'TargetVestaboard',
                            'caption' => 'Vesta',
                            'width' => '60px',
                            'add' => false,
                            'edit' => [
                                'type' => 'CheckBox'
                            ]
                        ],
                        [
                            'name' => 'TargetMP3',
                            'caption' => 'MP3',
                            'width' => '60px',
                            'add' => false,
                            'edit' => [
                                'type' => 'CheckBox'
                            ]
                        ]
                    ]
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
        $notifierId = $this->ReadPropertyInteger('TargetNotifier');
        if ($notifierId > 1 && IPS_InstanceExists($notifierId)) {
            $this->RegisterReference($notifierId);
        }

        // Registry Reference
        $registryId = $this->ReadPropertyInteger('RegistryID');
        if ($registryId > 1 && IPS_InstanceExists($registryId)) {
            $this->RegisterReference($registryId);
        }

        // Subscribe to events
        $events = json_decode($this->ReadPropertyString("MonitoredEvents"), true) ?: [];
        foreach ($events as $event) {
            $vid = (int)($event['VariableID'] ?? 0);
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $this->RegisterMessage($vid, VM_UPDATE);
                $this->RegisterReference($vid);
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

        $events = json_decode($this->ReadPropertyString("MonitoredEvents"), true) ?: [];
        foreach ($events as $event) {
            $vid = (int)($event['VariableID'] ?? 0);
            if ($vid === $SenderID) {
                $this->HandleEventTrigger($event, $val);
            }
        }
    }

    private function HandleEventTrigger(array $event, $currentValue): void
    {
        $vid = (int)($event['VariableID'] ?? 0);
        if ($vid === 0) return;

        // Fallback NormalState
        $triggerValueStr = 'true'; // If normal is false, trigger is true

        // Read NormalState from Registry
        $registryId = $this->ReadPropertyInteger('RegistryID');
        if ($registryId > 1 && @IPS_InstanceExists($registryId) && function_exists('SDR_GetDevicesByType')) {
            $devices = @SDR_GetDevicesByType($registryId, 'DevicesEvent');
            if (is_array($devices)) {
                foreach ($devices as $dev) {
                    if ((int)($dev['Status_VarID'] ?? 0) === $vid) {
                        $normalState = strtolower(trim((string)($dev['NormalState'] ?? '')));
                        if ($normalState === '') $normalState = 'false';
                        
                        // Invert normal state to get trigger value
                        if ($normalState === 'true' || $normalState === '1') {
                            $triggerValueStr = 'false';
                        } elseif ($normalState === 'false' || $normalState === '0') {
                            $triggerValueStr = 'true';
                        } else {
                            $triggerValueStr = $normalState; // fallback if it's a string state, though event variables are usually bool
                        }
                        break;
                    }
                }
            }
        }

        $currentValueStr = strtolower(trim((string)$currentValue));
        $isTriggered = false;
        
        // Boolean check
        if ($triggerValueStr === 'true' || $triggerValueStr === '1') {
            $isTriggered = (bool)$currentValue === true;
        } elseif ($triggerValueStr === 'false' || $triggerValueStr === '0') {
            $isTriggered = (bool)$currentValue === false;
        } else {
            // String/Int check (if NormalState was string, trigger means they don't match)
            $isTriggered = ($currentValueStr !== $triggerValueStr);
        }

        $messageText = $event['Message'] ?? 'Unbekanntes Ereignis';
        $alarmLevel = (int)($event['AlarmLevel'] ?? 0);
        $autoReset = (bool)($event['AutoReset'] ?? false);
        $vid = (int)($event['VariableID'] ?? 0);

        // Acknowledge Variable (Alarm_XXX)
        $ident = 'Event_' . $vid;
        $eventVarID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);

        if ($isTriggered) {
            $this->LogMessage("Ereignis ausgeloest: {$messageText}", KL_NOTIFY);
            $this->SetValue('LastEvent', $messageText);

            if (!$autoReset) {
                if ($eventVarID === false) {
                    $eventVarID = $this->RegisterVariableBoolean($ident, "🔔 " . $messageText, "~Switch", 10);
                    $this->EnableAction($ident);
                }
                $this->SetValue($ident, true);
                IPS_SetHidden($eventVarID, false);
            }

            // Route to Notifier
            $this->SendToNotifier($event, $messageText, $alarmLevel);
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

    private function SendToNotifier(array $event, string $message, int $level): void
    {
        $notifierId = $this->ReadPropertyInteger('TargetNotifier');
        if ($notifierId <= 1 || !IPS_InstanceExists($notifierId)) return;

        $targetPush = (bool)($event['TargetPush'] ?? false);
        $targetSonos = (bool)($event['TargetSonos'] ?? false);
        $targetVesta = (bool)($event['TargetVestaboard'] ?? false);
        $targetMP3 = (bool)($event['TargetMP3'] ?? false);

        if ($this->ReadPropertyBoolean('SimulationMode')) {
            $this->LogMessage("SIMULATION: Sende '{$message}' an Notifier ({$notifierId}). Ziele: Push:".(int)$targetPush." Sonos:".(int)$targetSonos." Vesta:".(int)$targetVesta." MP3:".(int)$targetMP3, KL_NOTIFY);
            return;
        }

        // Map Level
        $priority = 0; // Normal Info
        if ($level > 0) $priority = 1; // Warning

        // Instead of overriding the Notifier's global Enable flags (which we can't easily do via a simple interface),
        // we can call a custom function or use standard NOTIFY_SendEvent if we extend it.
        // For now, we call the standard Notification endpoint and prepend target info or use generic push.
        // Wait, SmartNotifier currently accepts NOTIFY_SendEvent(ID, Message, Priority).
        // Let's pass a JSON structure if we want routing, or just call it directly.
        // Let's implement NOTIFY_RouteEvent in SmartNotifier later, or just do simple SendEvent for now.
        
        if (function_exists('NOTIFY_SendEvent')) {
            // As a simple fallback before extending Notifier
            @NOTIFY_SendEvent($notifierId, $message, $priority);
        }
    }
}
