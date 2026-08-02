<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_CentralStateAware.php';
require_once __DIR__ . '/../libs/Trait_SmartLog.php';

/**
 * SmartNotifier
 * Zentraler Nachrichten-Hub fÃ¼r das Smart Home.
 *
 * @author Florian GraÃŸinger
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
        $this->RegisterPropertyInteger('TargetWebFront', 0);
        $this->RegisterPropertyInteger('TargetSonosTTS', 0);
        $this->RegisterPropertyInteger('TargetMP3P', 0);
        $this->RegisterPropertyInteger('TargetVestaboard', 0);
        
        $this->RegisterPropertyBoolean('EnablePush', true);
        $this->RegisterPropertyBoolean('EnableTTS', true);
        $this->RegisterPropertyBoolean('EnableMP3P', true);
        $this->RegisterPropertyBoolean('EnableVestaboard', true);

        // Buffers for Queuing
        $this->SetBuffer('MessageQueue', json_encode([]));
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SubscribeToCentralStates(['PresenceMode', 'ActivityMode']);
        
        // Auto-Generate References
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        
        $wf = $this->ReadPropertyInteger('TargetWebFront');
        if ($wf > 0 && @IPS_InstanceExists($wf)) {
            $this->RegisterReference($wf);
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
            "name": "TargetWebFront",
            "caption": "WebFront Instanz (fÃ¼r Push)"
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
            "type": "SelectInstance",
            "name": "TargetVestaboard",
            "caption": "VestaboardGenerator Instanz"
        },
        {
            "type": "CheckBox",
            "name": "EnableVestaboard",
            "caption": "Vestaboard Alarm-Push aktivieren"
        }
    ],
    "actions": [
        {
            "type": "Button",
            "caption": "Test: Low Priority",
            "onClick": "NOTIFY_SendMessage($id, 'Test', 'Dies ist eine Nachricht mit niedriger PrioritÃ¤t.', 0);"
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
     * Senden einer Nachricht Ã¼ber den Notifier.
     *
     * @param string $title
     * @param string $message
     * @param int $priority 0 = Low, 1 = Medium, 2 = High
     */
    public function SendEvent(string $PayloadJSON): void
    {
        $payload = json_decode($PayloadJSON, true);
        if (!is_array($payload)) {
            $this->SLogError( 'SmartNotifier: Invalid JSON payload');
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
        $this->SLogInfo( "Message received: [$title] $message (Prio: $priority)");

        $isHome = $this->IsHome();
        $isSleeping = $this->IsSleeping();
        $isCinema = $this->IsCinema();

        // --------------------------------------------------------
        // High Priority (2) -> Immer volle Eskalation
        // --------------------------------------------------------
        if ($priority >= 2) {
            $this->TriggerPush($title, $message, 'alarm', $actions);
            $this->TriggerVestaboard("$title: $message");
            if ($isHome) {
                $this->TriggerTTS("Achtung! $title: $message");
                $this->TriggerMP3P('1'); // z.B. Track 1 = Alarm
            }
            return;
        }

        // --------------------------------------------------------
        // Medium Priority (1) -> Push + lokales Feedback
        // --------------------------------------------------------
        if ($priority === 1) {
            $this->TriggerPush($title, $message, 'warning', $actions);
            $this->TriggerVestaboard("$title: $message");
            
            if ($isHome) {
                if ($isSleeping || $isCinema) {
                    // Stumm, wenn man schlÃ¤ft oder Film schaut, aber sofort Push
                } else {
                    $this->TriggerTTS("$title: $message");
                    $this->TriggerMP3P('2'); // z.B. Track 2 = Hinweis
                }
            }
            return;
        }

        // --------------------------------------------------------
        // Low Priority (0) -> Queueing nachts, sonst normales Feedback
        // --------------------------------------------------------
        if ($priority === 0) {
            if ($isHome && $isSleeping) {
                // Queue for morning!
                $this->QueueMessage($title, $message);
                // Stiller Push zur Info
                $this->TriggerPush($title, $message, '', $actions);
                return;
            }
            
            $this->TriggerPush($title, $message, '', $actions);
            if ($isHome && !$isCinema) {
                $this->TriggerTTS($message); // Ohne Titel, nur die kurze Nachricht
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
        $this->SLogInfo( "Nachricht in Morning-Queue gespeichert: $message");
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
        $this->SLogInfo( "Guten Morgen. Verarbeite $count gesammelte Nachrichten.");

        $ttsMsg = "Guten Morgen. WÃ¤hrend du geschlafen hast, gab es $count Meldungen. ";
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
        
        $wfId = $this->ReadPropertyInteger('TargetWebFront');
        if ($wfId > 0 && @IPS_InstanceExists($wfId)) {
            // WFC_PushNotification(int $InstanceID, string $Title, string $Text, string $Sound, int $TargetID)
            @WFC_PushNotification($wfId, $title, $message, $sound, 0);
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
                $this->SLogError( "Fehler bei Sonos TTS: " . $e->getMessage());
            }
        }
    }

    private function TriggerMP3P(string $soundTrack): void
    {
        if (!$this->ReadPropertyBoolean('EnableMP3P')) return;

        $mp3Id = $this->ReadPropertyInteger('TargetMP3P');
        if ($mp3Id > 0 && @IPS_InstanceExists($mp3Id)) {
            try {
                if (function_exists('MP3P_PlaySound')) {
                    @MP3P_PlaySound($mp3Id, $soundTrack, 100, 0);
                } else {
                    // Fallback HM-Aufruf
                    $param = "L=100,DU=0,DV=0,RTU=0,RTV=0,R=0,SL={$soundTrack}";
                    @HM_WriteValueString($mp3Id, 'COMBINED_PARAMETER', $param);
                }
            } catch (Exception $e) {
                $this->SLogError( 'Fehler MP3P Fallback: ' . $e->getMessage());
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
                $this->SLogError( 'Fehler beim Senden an Vestaboard: ' . $e->getMessage());
            }
        }
    }
}
