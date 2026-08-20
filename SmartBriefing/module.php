<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_CentralStateAware.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

/**
 * SmartBriefing
 * Generiert ein KI-Briefing aus beliebigen Variablen und sendet es via SmartNotifier.
 *
 * @author Florian Graßinger
 * @url https://github.com/pinkerunicorn/SymconSmartVillaKunterbunt/tree/main/SmartBriefing
 */
class SmartBriefing extends IPSModuleStrict
{
    use DeviceAvailability_Trait;
    use SmartLog_Trait;
    use CentralStateAware_Trait;

    public function Create(): void
    {
        parent::Create();
        $this->DA_RegisterAvailability(900);

        // Konfiguration
        $this->RegisterPropertyInteger('TargetNotifier', 0);
        $this->RegisterPropertyBoolean('AutoTrigger', true);
        $this->RegisterPropertyString('CustomPrompt', 'Du bist ein charmanter Smart Home Assistent. Fasse die folgenden Daten zu einem kurzen, freundlichen Briefing zusammen. Erwähne Dinge nur, wenn sie relevant sind (z.B. Müll heute, schwache Batterien, Fenster offen). Wenn alles ok ist, wünsche einfach einen guten Tag.');
        $this->RegisterPropertyString('VariablesList', '[]');

        // Legacy-Property (migration compat)
        $this->RegisterPropertyInteger('SmartInventoryID', 0);

        // Variablen
        $this->RegisterVariableBoolean('GenerateBriefing', 'Briefing jetzt generieren', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON'         => 'envelope-open-text',
        ], 1);
        $this->EnableAction('GenerateBriefing');

        $this->RegisterVariableString('BriefingText', 'Aktuelles Briefing', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'sparkles',
        ], 2);

        $this->RegisterVariableString('LastPrompt', 'Zuletzt gesendeter Prompt', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'envelope-open-text',
        ], 3);

        $this->RegisterVariableBoolean('GeminiError', 'Fehler aufgetreten', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'triangle-exclamation',
            'OPTIONS'      => json_encode([
                ['Value' => false, 'Caption' => 'OK',      'IconValue' => 'circle-check',        'IconActive' => true, 'ColorActive' => true, 'ColorDisplay' => 0x00CC00, 'ColorValue' => 0x00CC00, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1],
                ['Value' => true,  'Caption' => 'Fehler!', 'IconValue' => 'triangle-exclamation', 'IconActive' => true, 'ColorActive' => true, 'ColorDisplay' => 0xFF0000, 'ColorValue' => 0xFF0000, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1],
            ]),
        ], 4);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();
        $this->DA_SetAvailable(true);

        $this->SubscribeToCentralStates(['ActivityMode']);

        // References neu aufbauen
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }

        $notifierId = $this->ReadPropertyInteger('TargetNotifier');
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
        $this->SetStatus(empty($geminiInstances) ? 201 : 102);
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
        if ($stateName === 'ActivityMode' && $this->ReadPropertyBoolean('AutoTrigger') && (int)$newValue === 0) {
            $this->SLogInfo('Auto-Trigger: ActivityMode → Normal. Generiere Briefing.');
            $this->GenerateBriefing();
        }
    }

    public function GenerateBriefing(): void
    {
        $geminiInstances = IPS_GetInstanceListByModuleID('{4C8B2A6D-9E3F-4A7B-8C5D-1F6E2A3B7C4D}');
        if (empty($geminiInstances)) {
            $this->SetValue('GeminiError', true);
            $this->SLogError('Keine SmartGeminiIO Instanz gefunden!');
            return;
        }

        $geminiId = $geminiInstances[0];
        $this->SetValue('GeminiError', false);
        $this->SetValue('BriefingText', 'Generiere Briefing...');

        // Variablen sammeln
        $list = json_decode($this->ReadPropertyString('VariablesList'), true);
        $collectedData = [];

        if (is_array($list)) {
            foreach ($list as $item) {
                if (!isset($item['Active']) || !$item['Active']) {
                    continue;
                }
                $vid  = (int)($item['VariableID'] ?? 0);
                $name = trim((string)($item['Name'] ?? ''));
                if ($name === '' && $vid > 0 && @IPS_ObjectExists($vid)) {
                    $name = IPS_GetName($vid);
                }
                if ($vid > 0 && IPS_VariableExists($vid)) {
                    $collectedData[$name] = (string)GetValueFormatted($vid);
                }
            }
        }

        $basePrompt = trim($this->ReadPropertyString('CustomPrompt'));
        $dataStr    = "Hier sind die aktuellen Haus-Daten:\n" . json_encode($collectedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Automatisch SmartNotifier-Systemgesundheit anhaengen (wenn konfiguriert)
        $notifierId = $this->ReadPropertyInteger('TargetNotifier');
        if ($notifierId > 1 && @IPS_InstanceExists($notifierId)) {
            $healthMap = [
                'OfflineCount'     => 'Geraete offline',
                'LowBatteryCount'  => 'Batterien schwach',
                'ActiveAlarmCount' => 'Aktive Alarme',
                'OpenContactCount' => 'Kontakte/Fenster offen',
                'StaleCount'       => 'Sensoren ohne Update',
            ];
            $healthData = [];
            foreach ($healthMap as $ident => $label) {
                $vid = @IPS_GetObjectIDByIdent($ident, $notifierId);
                if ($vid !== false && @IPS_VariableExists($vid)) {
                    $val = GetValue($vid);
                    if ($val > 0) {
                        $healthData[$label] = $val;
                    }
                }
            }
            if (!empty($healthData)) {
                $dataStr .= "\n\nSystem-Gesundheit (SmartNotifier):\n" . json_encode($healthData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
        }

        $prompt = $basePrompt . "\n\n" . $dataStr;

        $this->SetValue('LastPrompt', $prompt);
        $this->SLogInfo('Briefing Prompt gesendet');

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
        $this->SLogInfo('Briefing erfolgreich generiert.');

        $notifierId = $this->ReadPropertyInteger('TargetNotifier');
        if ($notifierId > 1 && IPS_InstanceExists($notifierId)) {
            @NOTIFY_SendEvent($notifierId, json_encode([
                'Title'    => 'Briefing',
                'Message'  => $text,
                'Priority' => 0,
            ]));
        }
    }

    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Grundeinstellungen',
                    'expanded' => true,
                    'items'   => [
                        ['type' => 'SelectInstance', 'name' => 'TargetNotifier', 'caption' => 'SmartNotifier Instanz'],
                        ['type' => 'CheckBox', 'name' => 'AutoTrigger', 'caption' => 'Briefing automatisch abspielen wenn Haus erwacht (ActivityMode: Normal)'],
                        ['type' => 'ValidationTextBox', 'name' => 'CustomPrompt', 'caption' => 'System Prompt (Anweisung an die KI)', 'multiline' => true, 'height' => 80],
                    ],
                ],
                [
                    'type'         => 'List',
                    'name'         => 'VariablesList',
                    'caption'      => 'Datenquellen fuer das Briefing',
                    'add'          => true,
                    'delete'       => true,
                    'changeOrder'  => true,
                    'columns'      => [
                        ['name' => 'Active',     'caption' => 'Aktiv',                     'width' => '80px',   'add' => true,  'edit' => ['type' => 'CheckBox']],
                        ['name' => 'Name',       'caption' => 'Bezeichnung (z.B. Wetter)', 'width' => '250px',  'add' => '',    'edit' => ['type' => 'ValidationTextBox']],
                        ['name' => 'VariableID', 'caption' => 'Symcon Variable',           'width' => 'auto',   'add' => 0,     'edit' => ['type' => 'SelectVariable']],
                    ],
                ],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Briefing jetzt generieren', 'onClick' => 'SBR_GenerateBriefing($id); echo "Briefing wird generiert...";'],
            ],
        ]);
    }
}
