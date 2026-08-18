<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_CentralStateAware.php';
require_once __DIR__ . '/../libs/Trait_SmartLog.php';

/**
 * SmartNotifier
 * Zentraler Nachrichten-Hub für das Smart Home.
 *
 * @author Florian Graßinger
 * @url https://github.com/pinkerunicorn/
 */
class SmartNotifier extends IPSModuleStrict
{
    use CentralStateAware_Trait;
    use SmartLog_Trait;

    public function Create(): void
    {
        parent::Create();

        // Register Properties
        $this->RegisterPropertyInteger('TargetVisu', 0);
        $this->RegisterPropertyInteger('TargetSonosTTS', 0);
        $this->RegisterPropertyInteger('TargetMP3P', 0);
        $this->RegisterPropertyInteger('TargetVestaboard', 0);
        $this->RegisterPropertyInteger('TargetSMTP', 0);
        $this->RegisterPropertyString('EmailAddress', '');
        
        $this->RegisterPropertyBoolean('EnablePush', true);
        $this->RegisterPropertyBoolean('EnableTTS', true);
        $this->RegisterPropertyBoolean('EnableMP3P', true);
        $this->RegisterPropertyBoolean('EnableVestaboard', true);
        $this->RegisterPropertyBoolean('EnableSMTP', true);

        // MP3P Gong Customizations (Track, Volume, Track Duration, LED Color & LED Duration for High / Low)
        $this->RegisterPropertyString('MP3P_Track_High', '1');
        $this->RegisterPropertyInteger('MP3P_Volume_High', 80);
        $this->RegisterPropertyInteger('MP3P_Track_Duration_High', 0); // 0 = 1x abspielen
        $this->RegisterPropertyInteger('MP3P_LED_Color_High', 4); // 4 = Rot
        $this->RegisterPropertyInteger('MP3P_LED_Duration_High', 5);

        $this->RegisterPropertyString('MP3P_Track_Low', '2');
        $this->RegisterPropertyInteger('MP3P_Volume_Low', 50);
        $this->RegisterPropertyInteger('MP3P_Track_Duration_Low', 0); // 0 = 1x abspielen
        $this->RegisterPropertyInteger('MP3P_LED_Color_Low', 6); // 6 = Gelb / Orange
        $this->RegisterPropertyInteger('MP3P_LED_Duration_Low', 5);

        // Buffers for Queuing
        $this->SetBuffer('MessageQueue', json_encode([]));

        // Routing Rules Matrix
        $defaultRouting = [
            ['Level' => 0, 'Push' => true,  'TTS' => true,  'MP3' => true,  'Vesta' => false, 'Mail' => false],
            ['Level' => 1, 'Push' => true,  'TTS' => true,  'MP3' => true,  'Vesta' => true,  'Mail' => false],
            ['Level' => 2, 'Push' => true,  'TTS' => true,  'MP3' => true,  'Vesta' => true,  'Mail' => true]
        ];
        $this->RegisterPropertyString('RoutingRules', json_encode($defaultRouting));
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SubscribeToCentralStates(['PresenceMode', 'ActivityMode']);
        
        // Auto-Generate References
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        
        $visu = $this->ReadPropertyInteger('TargetVisu');
        if ($visu > 0 && @IPS_InstanceExists($visu)) {
            $this->RegisterReference($visu);
        }
        
        $sonos = $this->ReadPropertyInteger('TargetSonosTTS');
        if ($sonos > 0 && @IPS_InstanceExists($sonos)) {
            $this->RegisterReference($sonos);
        }
        
        $mp3p = $this->ReadPropertyInteger('TargetMP3P');
        if ($mp3p > 0 && @IPS_InstanceExists($mp3p)) {
            $this->RegisterReference($mp3p);
        }

        $vesta = $this->ReadPropertyInteger('TargetVestaboard');
        if ($vesta > 0 && @IPS_InstanceExists($vesta)) {
            $this->RegisterReference($vesta);
        }

        $smtp = $this->ReadPropertyInteger('TargetSMTP');
        if ($smtp > 0 && @IPS_InstanceExists($smtp)) {
            $this->RegisterReference($smtp);
        }

        $this->SetStatus(102);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($this->HandleCentralStateMessage($SenderID, $Message, $Data)) {
            return;
        }
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "SelectInstance",
            "name": "TargetVisu",
            "caption": "Kachel-Visualisierung (fuer Push)"
        },
        {
            "type": "CheckBox",
            "name": "EnablePush",
            "caption": "Push-Nachrichten aktivieren"
        },
        {
            "type": "SelectInstance",
            "name": "TargetSonosTTS",
            "caption": "Sonos TTS Instanz"
        },
        {
            "type": "CheckBox",
            "name": "EnableTTS",
            "caption": "Sprachausgabe aktivieren"
        },
        {
            "type": "ExpansionPanel",
            "caption": "🔔 HmIP MP3-Gong Einstellungen",
            "items": [
                {
                    "type": "SelectInstance",
                    "name": "TargetMP3P",
                    "caption": "HmIP MP3P Instanz"
                },
                {
                    "type": "CheckBox",
                    "name": "EnableMP3P",
                    "caption": "MP3-Gong aktivieren"
                },
                {
                    "type": "Label",
                    "bold": true,
                    "caption": "High Priority (Alarm):"
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "ValidationTextBox",
                            "name": "MP3P_Track_High",
                            "caption": "Track (z.B. 1)"
                        },
                        {
                            "type": "NumberSpinner",
                            "name": "MP3P_Volume_High",
                            "caption": "Lautstärke (%)",
                            "minimum": 0,
                            "maximum": 100,
                            "suffix": "%"
                        },
                        {
                            "type": "NumberSpinner",
                            "name": "MP3P_Track_Duration_High",
                            "caption": "Track Dauer (s, 0=1x abspielen)",
                            "minimum": 0,
                            "suffix": "s"
                        },
                        {
                            "type": "Select",
                            "name": "MP3P_LED_Color_High",
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
                        {
                            "type": "NumberSpinner",
                            "name": "MP3P_LED_Duration_High",
                            "caption": "LED Dauer (s, 0=unendlich)",
                            "minimum": 0,
                            "suffix": "s"
                        }
                    ]
                },
                {
                    "type": "Label",
                    "bold": true,
                    "caption": "Low / Medium Priority (Hinweis):"
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "ValidationTextBox",
                            "name": "MP3P_Track_Low",
                            "caption": "Track (z.B. 2)"
                        },
                        {
                            "type": "NumberSpinner",
                            "name": "MP3P_Volume_Low",
                            "caption": "Lautstärke (%)",
                            "minimum": 0,
                            "maximum": 100,
                            "suffix": "%"
                        },
                        {
                            "type": "NumberSpinner",
                            "name": "MP3P_Track_Duration_Low",
                            "caption": "Track Dauer (s, 0=1x abspielen)",
                            "minimum": 0,
                            "suffix": "s"
                        },
                        {
                            "type": "Select",
                            "name": "MP3P_LED_Color_Low",
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
                        {
                            "type": "NumberSpinner",
                            "name": "MP3P_LED_Duration_Low",
                            "caption": "LED Dauer (s, 0=unendlich)",
                            "minimum": 0,
                            "suffix": "s"
                        }
                    ]
                }
            ]
        },
        {
            "type": "SelectInstance",
            "name": "TargetVestaboard",
            "caption": "VestaboardGenerator Instanz"
        },
        {
            "type": "CheckBox",
            "name": "EnableVestaboard",
            "caption": "Vestaboard Alarm-Push aktivieren"
        },
        {
            "type": "SelectInstance",
            "name": "TargetSMTP",
            "caption": "SMTP Instanz (fuer E-Mails)"
        },
        {
            "type": "ValidationTextBox",
            "name": "EmailAddress",
            "caption": "Empfaenger E-Mail Adresse"
        },
        {
            "type": "CheckBox",
            "name": "EnableSMTP",
            "caption": "E-Mail Benachrichtigungen aktivieren"
        },
        {
            "type": "ExpansionPanel",
            "caption": "Routing-Regeln (Nach Priorität)",
            "items": [
                {
                    "type": "Label",
                    "caption": "Definiert, welche Nachrichten-Priorität über welche Kanäle gesendet wird. (Voraussetzung: Kanal oben aktiviert)."
                },
                {
                    "type": "List",
                    "name": "RoutingRules",
                    "caption": "Aktions-Matrix",
                    "add": false,
                    "delete": false,
                    "columns": [
                        {
                            "caption": "Priorität",
                            "name": "Level",
                            "width": "150px",
                            "edit": {
                                "type": "Select",
                                "options": [
                                    { "caption": "0 (Info)", "value": 0 },
                                    { "caption": "1 (Hinweis)", "value": 1 },
                                    { "caption": "2 (Alarm)", "value": 2 }
                                ]
                            }
                        },
                        {
                            "caption": "Push", "name": "Push", "width": "80px",
                            "edit": { "type": "CheckBox" }
                        },
                        {
                            "caption": "Sprache", "name": "TTS", "width": "80px",
                            "edit": { "type": "CheckBox" }
                        },
                        {
                            "caption": "MP3", "name": "MP3", "width": "80px",
                            "edit": { "type": "CheckBox" }
                        },
                        {
                            "caption": "Vestaboard", "name": "Vesta", "width": "80px",
                            "edit": { "type": "CheckBox" }
                        },
                        {
                            "caption": "E-Mail", "name": "Mail", "width": "80px",
                            "edit": { "type": "CheckBox" }
                        }
                    ]
                }
            ]
        }
    ],
    "actions": [
        {
            "type": "Button",
            "caption": "Test: Low Priority",
            "onClick": "NOTIFY_SendMessage($id, 'Test', 'Dies ist eine Nachricht mit niedriger Prioritaet.', 0);"
        },
        {
            "type": "Button",
            "caption": "Test: High Priority",
            "onClick": "NOTIFY_SendMessage($id, 'Alarm', 'Dies ist ein kritischer Test!', 2);"
        }
    ]
}
EOT;
    }

    /**
     * @param string $stateName
     * @param mixed $newValue
     */
    protected function OnCentralStateChanged(string $stateName, mixed $newValue): void
    {
        // When waking up (ActivityMode changes to Normal) -> Process morning queue
        if ($stateName === 'ActivityMode' && (int)$newValue === 0) {
            $this->ProcessMorningQueue();
        }
    }

    /**
     * Senden einer Nachricht über den Notifier.
     *
     * @param string $title
     * @param string $message
     * @param int $priority 0 = Low, 1 = Medium, 2 = High
     */
    public function SendEvent(string $PayloadJSON): void
    {
        $payload = json_decode($PayloadJSON, true);
        if (!is_array($payload)) {
            $this->SLogError('SmartNotifier: Invalid JSON payload');
            return;
        }
        
        $title = $payload['Title'] ?? 'Info';
        $message = $payload['Message'] ?? '';
        $priority = (int)($payload['Priority'] ?? 0);
        $actions = $payload['Actions'] ?? [];

        $this->ProcessEvent($title, $message, $priority, $actions);
    }

    /**
     * @deprecated Use SendEvent() instead.
     */
    public function SendMessage(string $title, string $message, int $priority): void
    {
        $this->ProcessEvent($title, $message, $priority, []);
    }

    private function ProcessEvent(string $title, string $message, int $priority, array $actions): void
    {
        $this->SLogInfo("Message received: [$title] $message (Prio: $priority)");

        $isHome = $this->IsHome();
        $isSleeping = $this->IsSleeping();
        $isCinema = $this->IsCinema();

        // --------------------------------------------------------
        // Absoluter Schlaf-Modus (DND): Keine Ausgabe, alles in die Queue (nur für Prio 0 und 1)
        // --------------------------------------------------------
        if ($isSleeping && $priority < 2) {
            $this->QueueMessage($title, $message);
            $this->SLogInfo("Schlafmodus aktiv: Nachricht in die Morgen-Warteschlange verschoben.");
            return;
        }

        // Get routing rule for this priority
        $rules = json_decode($this->ReadPropertyString('RoutingRules'), true) ?: [];
        $rule = ['Push' => false, 'TTS' => false, 'MP3' => false, 'Vesta' => false, 'Mail' => false];
        foreach ($rules as $r) {
            if ((int)($r['Level'] ?? 0) === $priority) {
                $rule = $r;
                break;
            }
        }

        // Execute Routing
        if (!empty($rule['Push'])) {
            $sound = ($priority === 2) ? 'alarm' : (($priority === 1) ? 'warning' : '');
            $this->TriggerPush($title, $message, $sound, $actions);
        }

        if (!empty($rule['Mail'])) {
            $this->TriggerEmail($title, $message);
        }

        if (!empty($rule['Vesta'])) {
            $this->TriggerVestaboard("$title: $message");
        }

        if ($isHome) {
            // Audio rules
            $canSpeak = true;
            if ($isCinema && $priority < 2) $canSpeak = false; // Kino-Modus stumm für Info/Hinweis
            if ($isSleeping && $priority === 2) $canSpeak = true; // Alarm weckt auf

            if ($canSpeak) {
                if (!empty($rule['TTS'])) {
                    $prefix = ($priority === 2) ? "Achtung! " : "";
                    $this->TriggerTTS($prefix . $title . ": " . $message);
                }

                if (!empty($rule['MP3'])) {
                    $high = ($priority === 2);
                    $track = $this->ReadPropertyString($high ? 'MP3P_Track_High' : 'MP3P_Track_Low');
                    $vol = $this->ReadPropertyInteger($high ? 'MP3P_Volume_High' : 'MP3P_Volume_Low');
                    $trackDuration = $this->ReadPropertyInteger($high ? 'MP3P_Track_Duration_High' : 'MP3P_Track_Duration_Low');
                    $color = $this->ReadPropertyInteger($high ? 'MP3P_LED_Color_High' : 'MP3P_LED_Color_Low');
                    $ledDuration = $this->ReadPropertyInteger($high ? 'MP3P_LED_Duration_High' : 'MP3P_LED_Duration_Low');
                    if ($track !== '') {
                        $this->TriggerMP3P($track, $vol, $trackDuration, $color, $ledDuration);
                    }
                }
            }
        }
    }

    private function QueueMessage(string $title, string $message): void
    {
        $queueStr = $this->GetBuffer('MessageQueue');
        $queue = $queueStr !== '' ? json_decode($queueStr, true) : [];
        if (!is_array($queue)) {
            $queue = [];
        }

        $queue[] = [
            'time' => time(),
            'title' => $title,
            'message' => $message
        ];

        $this->SetBuffer('MessageQueue', json_encode($queue));
        $this->SLogInfo("Nachricht in Morning-Queue gespeichert: $message");
    }

    private function ProcessMorningQueue(): void
    {
        $queueStr = $this->GetBuffer('MessageQueue');
        if ($queueStr === '') {
            return;
        }
        
        $queue = json_decode($queueStr, true);
        if (!is_array($queue) || count($queue) === 0) {
            return;
        }

        $count = count($queue);
        $this->SLogInfo("Guten Morgen. Verarbeite $count gesammelte Nachrichten.");

        $ttsMsg = "Guten Morgen. Während du geschlafen hast, gab es $count Meldungen. ";
        foreach ($queue as $item) {
            $ttsMsg .= $item['title'] . ": " . $item['message'] . ". ";
        }

        $this->TriggerTTS($ttsMsg);
        
        // Empty queue
        $this->SetBuffer('MessageQueue', json_encode([]));
    }

    // =========================================================================
    // Hardware Triggers
    // =========================================================================

    private function TriggerPush(string $title, string $message, string $sound, array $actions = []): void
    {
        if (!$this->ReadPropertyBoolean('EnablePush')) return;
        
        $visuId = $this->ReadPropertyInteger('TargetVisu');
        if ($visuId > 0 && @IPS_InstanceExists($visuId)) {
            $targetId = 0;
            if (!empty($actions) && isset($actions[0]) && is_numeric($actions[0])) {
                $targetId = (int)$actions[0];
            }
            
            $icon = 'Information';
            if ($sound === 'alarm') {
                $icon = 'Alert';
            } elseif ($sound === 'warning') {
                $icon = 'Warning';
            }
            
            @VISU_PostNotificationEx($visuId, $title, $message, $icon, $sound, $targetId);
        }
    }

    private function TriggerTTS(string $message): void
    {
        if (!$this->ReadPropertyBoolean('EnableTTS')) return;

        $sonosId = $this->ReadPropertyInteger('TargetSonosTTS');
        if ($sonosId > 0 && @IPS_InstanceExists($sonosId)) {
            try {
                if (function_exists('GSTTS_PlayMessage')) {
                    @GSTTS_PlayMessage($sonosId, $message, true);
                } elseif (function_exists('SNS_PlayText')) {
                    @SNS_PlayText($sonosId, $message);
                }
            } catch (Exception $e) {
                $this->SLogError("Fehler bei Sonos TTS: " . $e->getMessage());
            }
        }
    }

    private function TriggerMP3P(string $soundTrack, int $volume = 80, int $trackDuration = 0, int $color = 0, int $duration = 5): void
    {
        if (!$this->ReadPropertyBoolean('EnableMP3P')) return;

        $mp3Id = $this->ReadPropertyInteger('TargetMP3P');
        if ($mp3Id > 0 && @IPS_InstanceExists($mp3Id)) {
            try {
                if ($soundTrack !== '' && $volume > 0) {
                    if (function_exists('MP3P_PlaySound')) {
                        @MP3P_PlaySound($mp3Id, $soundTrack, $volume, $trackDuration);
                    } else {
                        // Fallback HM-Aufruf
                        $param = "L={$volume},DU=0,DV={$trackDuration},RTU=0,RTV=0,R=0,SL={$soundTrack}";
                        @HM_WriteValueString($mp3Id, 'COMBINED_PARAMETER', $param);
                    }
                }

                if ($color > 0) {
                    if (function_exists('MP3P_SetLight')) {
                        @MP3P_SetLight($mp3Id, $color, 100, $duration);
                    } else {
                        // Fallback LED HM-Aufruf
                        $rtu = ($duration === 0) ? 1 : 0;
                        $ledParam = "L=100,DV={$duration},DU=0,RTV=0,RTU={$rtu},C={$color}";
                        @HM_WriteValueString($mp3Id, 'COMBINED_PARAMETER', $ledParam);
                    }
                }
            } catch (Exception $e) {
                $this->SLogError('Fehler MP3P: ' . $e->getMessage());
            }
        }
    }

    private function TriggerVestaboard(string $message): void
    {
        if (!$this->ReadPropertyBoolean('EnableVestaboard')) return;

        $vestaId = $this->ReadPropertyInteger('TargetVestaboard');
        if ($vestaId > 0 && @IPS_InstanceExists($vestaId)) {
            try {
                // Aufruf der PushAlert-Methode im VestaboardGenerator (resume = true)
                @IPS_RunScriptText("VESTAG_PushAlert($vestaId, " . var_export(substr($message, 0, 132), true) . ", true);");
            } catch (Exception $e) {
                $this->SLogError('Fehler beim Senden an Vestaboard: ' . $e->getMessage());
            }
        }
    }

    private function TriggerEmail(string $title, string $message): void
    {
        if (!$this->ReadPropertyBoolean('EnableSMTP')) return;

        $smtp = $this->ReadPropertyInteger('TargetSMTP');
        $email = trim($this->ReadPropertyString('EmailAddress'));
        if ($smtp > 0 && @IPS_InstanceExists($smtp) && $email !== '') {
            $this->SLogInfo("E-Mail: Sende Mail an $email ($title)");
            try {
                if (function_exists('SMTP_SendMailEx')) {
                    @SMTP_SendMailEx($smtp, $email, $title, $message);
                } else {
                    @SMTP_SendMail($smtp, $title, $message);
                }
            } catch (Exception $e) {
                $this->SLogError("Fehler beim Senden der E-Mail: " . $e->getMessage());
            }
        }
    }
}
