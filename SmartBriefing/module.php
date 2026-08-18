<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_DeviceRegistration.php';
require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_CentralStateAware.php';
require_once __DIR__ . '/../libs/Trait_RegistryAware.php';

/**
 * SmartBriefing
 * Generiert ein Briefing mithilfe der KI aus beliebigen Variablen.
 *
 * @author Florian Graßinger
 * @url https://github.com/pinkerunicorn/SymconSmartVillaKunterbunt/tree/main/SmartBriefing
 */
class SmartBriefing extends IPSModuleStrict
{
    use DeviceRegistration_Trait;
    use SmartLog_Trait;
    use CentralStateAware_Trait;
    use RegistryAware_Trait;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyInteger('RegistryID', 0);

        // Konfiguration
        $this->RegisterPropertyString('VariablesList', '[]');
        $this->RegisterPropertyInteger('TargetNotifier', 0);
        $this->RegisterPropertyBoolean('AutoTrigger', true);
        $this->RegisterPropertyString('CustomPrompt', 'Du bist ein charmanter Smart Home Assistent. Fasse die folgenden Daten zu einem kurzen, freundlichen Briefing zusammen. Erwähne Dinge nur, wenn sie relevant sind (z.B. Müll heute, schwache Batterien, Fenster offen). Wenn alles ok ist, wünsche einfach einen guten Tag.');

        // Variablen (UI)
        $this->RegisterVariableBoolean('GenerateBriefing', 'Briefing jetzt generieren', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'envelope-open-text'
        ], 1);
        $this->EnableAction('GenerateBriefing');

        $this->RegisterVariableString('BriefingText', 'Aktuelles Briefing', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'sparkles'
        ], 2);

        $this->RegisterVariableString('LastPrompt', 'Zuletzt gesendeter Prompt', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'envelope-open-text'
        ], 3);

        $this->RegisterVariableBoolean('GeminiError', 'Fehler aufgetreten', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'triangle-exclamation',
            'COLOR' => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => json_encode([
                ['Value' => false, 'Caption' => 'OK', 'IconValue' => 'bell', 'IconActive' => false, 'ColorActive' => false, 'ColorDisplay' => 0x00CC00, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00CC00],
                ['Value' => true, 'Caption' => 'Fehler!', 'IconValue' => 'triangle-exclamation', 'IconActive' => false, 'ColorActive' => false, 'ColorDisplay' => 0xFF0000, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF0000]
            ])
        ], 3);

        $this->DR_Register('DevicesGenericSensor');
    }

    public function Destroy(): void
    {
        $this->DR_Unregister();
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SubscribeToCentralStates(['ActivityMode']);

        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }

        $notifierId = $this->DR_GetNotifierID();
        if ($notifierId > 1 && @IPS_InstanceExists($notifierId)) {
            $this->RegisterReference($notifierId);
        }

        $list = json_decode($this->ReadPropertyString('VariablesList'), true);
        if (is_array($list)) {
            foreach ($list as $item) {
                $vid = (int)($item['VariableID'] ?? 0);
                if ($vid > 1 && @IPS_VariableExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        
        $geminiInstances = IPS_GetInstanceListByModuleID('{4C8B2A6D-9E3F-4A7B-8C5D-1F6E2A3B7C4D}');
        if (empty($geminiInstances)) {
            $this->SetStatus(201); // Inactive
        } else {
            $this->SetStatus(102); // Active
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'GenerateBriefing') {
            $this->SetValue($Ident, true);
            $this->GenerateBriefing();
            $this->SetValue($Ident, false);
        }
    }

    protected function OnCentralStateChanged(string $stateName, mixed $newValue): void
    {
        if ($stateName === 'ActivityMode') {
            $autoTrigger = $this->ReadPropertyBoolean('AutoTrigger');
            // 0 = Normal/Awake
            if ($autoTrigger && (int)$newValue === 0) {
                $this->SLogInfo('Auto-Trigger', 'ActivityMode gewechselt auf Normal. Generiere Briefing.');
                $this->GenerateBriefing();
            }
        }
    }

    public function GenerateBriefing(): void
    {
        $geminiInstances = IPS_GetInstanceListByModuleID('{4C8B2A6D-9E3F-4A7B-8C5D-1F6E2A3B7C4D}');
        if (empty($geminiInstances)) {
            $this->SetValue('GeminiError', true);
            $this->SLogError('Fehler', 'Keine SmartGeminiIO Instanz gefunden!');
            return;
        }
        
        $geminiId = $geminiInstances[0];
        $this->SetValue('GeminiError', false);
        $this->SetValue('BriefingText', 'Generiere Briefing...');

        $list = json_decode($this->ReadPropertyString('VariablesList'), true);
        $collectedData = [];
        
        if (is_array($list)) {
            foreach ($list as $item) {
                if (!isset($item['Active']) || !$item['Active']) continue;
                
                $vid = (int)($item['VariableID'] ?? 0);
                $name = trim((string)($item['Name'] ?? ''));
                if ($name === '') {
                    $name = "Variable " . $vid;
                    if ($vid > 0 && @IPS_ObjectExists($vid)) {
                        $name = IPS_GetName($vid);
                    }
                }

                if ($vid > 0 && IPS_VariableExists($vid)) {
                    $valStr = (string)GetValueFormatted($vid);
                    $collectedData[$name] = $valStr;
                }
            }
        }

        $basePrompt = trim($this->ReadPropertyString('CustomPrompt'));
        $dataStr = "Hier sind die aktuellen Haus-Daten:\n" . json_encode($collectedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        $prompt = $basePrompt . "\n\n" . $dataStr;

        $this->SetValue('LastPrompt', $prompt);
        $this->SLogInfo('Briefing Prompt gesendet', "Folgender Prompt wurde an die KI übermittelt:\n" . $prompt);

        // Async via SmartGeminiIO
        $instanceId = $this->InstanceID;
        $script = '<?php
            $result = GIO_Query(' . $geminiId . ',
                ' . var_export($prompt, true) . ',
                \'Antworte AUSSCHLIESSLICH mit dem finalen Text des Briefings, den der Sprachassistent vorlesen soll. Keine Formatierung, keine Markdown-Tags, kein JSON.\',
                \'text/plain\',
                0.5
            );
            SBR_ProcessGeminiResult(' . $instanceId . ', $result);
        ';
        IPS_RunScriptText($script);
    }

    public function ProcessGeminiResult(string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            $this->SetValue('GeminiError', true);
            $this->SetValue('BriefingText', 'Fehler: SmartGeminiIO lieferte keine Antwort.');
            return;
        }

        $this->SetValue('BriefingText', $text);
        $this->SetValue('GeminiError', false);
        
        $this->SLogInfo('Briefing generiert', 'Text wurde erfolgreich generiert und an Notifier gesendet.');

        $notifierId = $this->DR_GetNotifierID();
        if ($notifierId > 1 && IPS_InstanceExists($notifierId)) {
            $payload = [
                'Title' => 'Briefing',
                'Message' => $text,
                'Priority' => 0
            ];
            @NOTIFY_SendEvent($notifierId, json_encode($payload));
        }
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "ExpansionPanel",
            "caption": "⚙️ Grundeinstellungen",
            "items": [
                {
                    "type": "CheckBox",
                    "name": "AutoTrigger",
                    "caption": "Briefing automatisch abspielen, wenn das Haus erwacht (ActivityMode: Normal)"
                },
                {
                    "type": "ValidationTextBox",
                    "name": "CustomPrompt",
                    "caption": "System Prompt (Anweisung an die KI)"
                }
            ]
        },
        {
            "type": "Label",
            "caption": " "
        },
        {
            "type": "List",
            "name": "VariablesList",
            "caption": "Datenquellen für das Briefing",
            "add": true,
            "delete": true,
            "changeOrder": true,
            "columns": [
                {
                    "name": "Active",
                    "caption": "Aktiv",
                    "width": "80px",
                    "add": true,
                    "edit": {
                        "type": "CheckBox"
                    }
                },
                {
                    "name": "Name",
                    "caption": "Bezeichnung (z.B. Wetter, Müll)",
                    "width": "250px",
                    "add": "",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "name": "VariableID",
                    "caption": "Symcon Variable",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                }
            ]
        }
    ]
}
EOT;
    }
}
