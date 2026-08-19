<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_CentralStateAware.php';
require_once __DIR__ . '/../libs/Trait_SmartLog.php';

/**
 * SmartNotifier
 * Zentraler Nachrichten-Hub und Device-Monitor für das Smart Home.
 *
 * Sendet Nachrichten über Push, TTS, MP3-Gong, Vestaboard und E-Mail.
 * Überwacht alle per SI:-Tag erfassten Geräte (Batterie, Erreichbarkeit,
 * Alarme, Kontakte, Stale-Sensoren) und meldet Probleme sofort.
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

        // ── Ausgabe-Kanäle ──────────────────────────────────────────
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

        // ── Monitoring ──────────────────────────────────────────────
        $this->RegisterPropertyInteger('InventoryID', 0);           // SmartInventory Instanz
        $this->RegisterPropertyInteger('MonitorInterval', 5);       // Minuten (0 = deaktiviert)
        $this->RegisterPropertyInteger('BatteryThreshold', 15);     // Prozent-Schwellwert
        $this->RegisterPropertyInteger('StaleThreshold', 120);      // Minuten ohne Update = stale

        // ── MP3P Gong ───────────────────────────────────────────────
        $this->RegisterPropertyString('MP3P_Track_High', '1');
        $this->RegisterPropertyInteger('MP3P_Volume_High', 80);
        $this->RegisterPropertyInteger('MP3P_Track_Duration_High', 0);
        $this->RegisterPropertyInteger('MP3P_LED_Color_High', 4);
        $this->RegisterPropertyInteger('MP3P_LED_Duration_High', 5);

        $this->RegisterPropertyString('MP3P_Track_Low', '2');
        $this->RegisterPropertyInteger('MP3P_Volume_Low', 50);
        $this->RegisterPropertyInteger('MP3P_Track_Duration_Low', 0);
        $this->RegisterPropertyInteger('MP3P_LED_Color_Low', 6);
        $this->RegisterPropertyInteger('MP3P_LED_Duration_Low', 5);

        // ── Routing ─────────────────────────────────────────────────
        $defaultRouting = [
            ['Level' => 0, 'Push' => true,  'TTS' => true,  'MP3' => true,  'Vesta' => false, 'Mail' => false],
            ['Level' => 1, 'Push' => true,  'TTS' => true,  'MP3' => true,  'Vesta' => true,  'Mail' => false],
            ['Level' => 2, 'Push' => true,  'TTS' => true,  'MP3' => true,  'Vesta' => true,  'Mail' => true],
        ];
        $this->RegisterPropertyString('RoutingRules', json_encode($defaultRouting));

        // ── Status-Variablen ────────────────────────────────────────
        $this->RegisterVariableInteger('OfflineCount', 'Geraete offline', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'wifi-slash',
        ], 1);
        $this->RegisterVariableInteger('LowBatteryCount', 'Batterien kritisch', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'battery-quarter',
        ], 2);
        $this->RegisterVariableInteger('ActiveAlarmCount', 'Alarme aktiv', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'bell',
        ], 3);
        $this->RegisterVariableInteger('OpenContactCount', 'Kontakte offen', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'door-open',
        ], 4);
        $this->RegisterVariableInteger('StaleCount', 'Sensoren veraltet', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'clock-rotate-left',
        ], 5);
        $this->RegisterVariableString('LastCheck', 'Letzter Check', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'clock',
        ], 900);

        // ── Buffer / Timer ──────────────────────────────────────────
        $this->SetBuffer('MessageQueue', json_encode([]));
        $this->RegisterTimer('MonitorTimer', 0, 'NOTIFY_RunMonitor(' . $this->InstanceID . ');');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SubscribeToCentralStates(['PresenceMode', 'ActivityMode']);

        // References neu aufbauen
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        foreach (['TargetVisu', 'TargetSonosTTS', 'TargetMP3P', 'TargetVestaboard', 'TargetSMTP', 'InventoryID'] as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0 && @IPS_InstanceExists($id)) {
                $this->RegisterReference($id);
            }
        }

        // Monitor-Timer setzen
        $interval = $this->ReadPropertyInteger('MonitorInterval');
        $this->SetTimerInterval('MonitorTimer', $interval > 0 ? $interval * 60 * 1000 : 0);

        // MessageSink fuer getaggte Variablen einrichten
        $this->RefreshSubscriptions();

        $this->SetStatus(102);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($this->HandleCentralStateMessage($SenderID, $Message, $Data)) {
            return;
        }

        // Variablen-Updates von überwachten Variablen
        if ($Message === IPS_VARIABLEMESSAGE && $Data[0] === VM_UPDATE) {
            $newValue = $Data[1];
            $oldValue = $Data[2];
            if ($newValue === $oldValue) {
                return;
            }
            $this->HandleVariableUpdate($SenderID, $newValue);
        }
    }

    // =========================================================================
    // Monitoring – öffentlich (wird vom Timer aufgerufen)
    // =========================================================================

    /**
     * Führt den kompletten Monitor-Durchlauf durch.
     * Wird vom Timer aufgerufen: NOTIFY_RunMonitor($id)
     */
    public function RunMonitor(): void
    {
        $inventory = $this->LoadInventory();
        if (empty($inventory)) {
            $this->SendDebug('Monitor', 'Kein Inventar verfuegbar – Scan zuerst ausfuehren', 0);
            return;
        }

        // Quiet-Start: beim allerersten Durchlauf (leerer NotifiedProblems-Buffer)
        // nur Counter setzen, keine Notifications. Verhindert Push-Flut nach Neustart.
        $notifiedRaw = $this->GetBuffer('NotifiedProblems');
        $isFirstRun  = ($notifiedRaw === '' || $notifiedRaw === false || $notifiedRaw === '{}');
        if ($isFirstRun) {
            // Alle aktuellen Probleme als "bereits bekannt" markieren ohne zu melden
            $this->SendDebug('Monitor', 'Erster Durchlauf (Quiet-Start): Baseline wird gesetzt', 0);
        }

        $threshold  = $this->ReadPropertyInteger('BatteryThreshold');

        $staleMin   = $this->ReadPropertyInteger('StaleThreshold');
        $now        = time();

        $offlineCount  = 0;
        $lowBatCount   = 0;
        $alarmCount    = 0;
        $contactCount  = 0;
        $staleCount    = 0;

        foreach ($inventory as $device) {
            foreach ($device['variables'] as $v) {
                if ($v['disabled']) {
                    continue;
                }

                // Live-Wert holen
                if (!@IPS_VariableExists($v['varID'])) {
                    continue;
                }
                $varInfo    = IPS_GetVariable($v['varID']);
                $liveValue  = @GetValue($v['varID']);
                $lastUpdate = $varInfo['VariableUpdated'];

                $cat = $v['category'];
                $name = $device['instanceName'] . ($device['room'] !== '' ? ' (' . $device['room'] . ')' : '');

                // ── Stale-Check ─────────────────────────────────────
                // Nur Sensoren die regelmässig senden sollten
                if (in_array($cat, ['battery', 'reachability', 'sensor']) && $staleMin > 0) {
                    $ageMin = ($now - $lastUpdate) / 60;
                    if ($ageMin > $staleMin && $lastUpdate > 0) {
                        $staleCount++;
                        $ageH = round($ageMin / 60, 1);
                        $this->NotifyIfNew('stale_' . $v['varID'], "Sensor veraltet", "$name: Kein Update seit {$ageH}h", 1, $isFirstRun);
                    }
                }

                // ── Kategorie-Checks ────────────────────────────────
                switch ($cat) {
                    case 'reachability':
                        $isOffline = $this->IsTriggered($liveValue, $v['normalState']);
                        if ($isOffline) {
                            $offlineCount++;
                            $this->NotifyIfNew('offline_' . $v['varID'], 'Geraet offline', "$name: Nicht erreichbar", 1, $isFirstRun);
                        } else {
                            $this->ClearNotified('offline_' . $v['varID']);
                        }
                        break;

                    case 'battery':
                        $isLow = $this->IsBatteryLow($liveValue, $varInfo['VariableType'], $threshold);
                        if ($isLow) {
                            $lowBatCount++;
                            $valStr = is_bool($liveValue) ? ($liveValue ? 'leer' : 'OK') : $liveValue . '%';
                            $this->NotifyIfNew('battery_' . $v['varID'], 'Batterie schwach', "$name: $valStr", 1, $isFirstRun);
                        } else {
                            $this->ClearNotified('battery_' . $v['varID']);
                        }
                        break;

                    case 'alarm':
                    case 'warning':
                        $isActive = $this->IsTriggered($liveValue, $v['normalState']);
                        if ($isActive) {
                            $alarmCount++;
                            $sub = $v['subcategory'] !== '' ? ' (' . $v['subcategory'] . ')' : '';
                            $prio = ($cat === 'alarm') ? 2 : 1;
                            $this->NotifyIfNew('alarm_' . $v['varID'], 'Alarm' . $sub, "$name: " . IPS_GetName($v['varID']), $prio, $isFirstRun);
                        } else {
                            $this->ClearNotified('alarm_' . $v['varID']);
                        }
                        break;

                    case 'contact':
                        $isOpen = $this->IsTriggered($liveValue, $v['normalState']);
                        if ($isOpen) {
                            $contactCount++;
                            // Kontakte: nur beim Oeffnen melden, kein dauerhafter Alarm
                        } else {
                            $this->ClearNotified('contact_' . $v['varID']);
                        }
                        break;
                }
            }
        }

        // Counter-Variablen aktualisieren
        $this->UpdateCounterVar('OfflineCount', $offlineCount);
        $this->UpdateCounterVar('LowBatteryCount', $lowBatCount);
        $this->UpdateCounterVar('ActiveAlarmCount', $alarmCount);
        $this->UpdateCounterVar('OpenContactCount', $contactCount);
        $this->UpdateCounterVar('StaleCount', $staleCount);
        $this->SetValue('LastCheck', date('d.m.Y H:i:s'));

        $this->SendDebug('Monitor', "Offline: $offlineCount, Batterie: $lowBatCount, Alarme: $alarmCount, Kontakte: $contactCount, Stale: $staleCount", 0);
    }

    /**
     * Aktualisiert die MessageSink-Subscriptions für alle getaggten Variablen.
     * Wird nach Scan() aufgerufen: NOTIFY_RefreshSubscriptions($id)
     */
    public function RefreshSubscriptions(): void
    {
        // Erst alle alten Subscriptions entfernen (ausser CentralState-Vars)
        foreach ($this->GetMessageList() as $senderID => $msgs) {
            if (!in_array(IPS_VARIABLEMESSAGE, $msgs)) {
                continue;
            }
            // CentralState-Variablen behalten
            $ident = @IPS_GetObject($senderID)['ObjectIdent'] ?? '';
            if (in_array($ident, ['ActivityMode', 'PresenceMode'])) {
                continue;
            }
            $this->UnregisterMessage($senderID, IPS_VARIABLEMESSAGE);
        }

        $inventory = $this->LoadInventory();
        $count = 0;

        // Echtzeit-Kategorien abonnieren (Alarm, Warning, Kontakt, Reachability)
        $realtimeCategories = ['alarm', 'warning', 'contact', 'reachability'];
        foreach ($inventory as $device) {
            foreach ($device['variables'] as $v) {
                if ($v['disabled']) {
                    continue;
                }
                if (in_array($v['category'], $realtimeCategories)) {
                    if (@IPS_VariableExists($v['varID'])) {
                        $this->RegisterMessage($v['varID'], IPS_VARIABLEMESSAGE);
                        $count++;
                    }
                }
            }
        }

        $this->SendDebug('Subscriptions', "$count Variablen abonniert", 0);
    }

    // =========================================================================
    // Monitoring – intern
    // =========================================================================

    /**
     * Echtzeit-Handler: wird von MessageSink bei Variablenänderung aufgerufen.
     */
    private function HandleVariableUpdate(int $varID, mixed $newValue): void
    {
        $obj = @IPS_GetObject($varID);
        if ($obj === false) {
            return;
        }
        $tag = $obj['ObjectInfo'];
        if (!str_starts_with($tag, 'SI:')) {
            return;
        }

        $parsed      = $this->ParseTag($tag);
        $cat         = $parsed['category'];
        $instanceID  = IPS_GetParent($varID);
        $instName    = @IPS_GetName($instanceID);
        $room        = '';
        $roomVarID   = @IPS_GetObjectIDByIdent('_SI_Room', $instanceID);
        if ($roomVarID !== false) {
            $room = GetValue($roomVarID);
        }
        $name = $instName . ($room !== '' ? ' (' . $room . ')' : '');

        switch ($cat) {
            case 'alarm':
                $isActive = $this->IsTriggered($newValue, $parsed['normalState']);
                if ($isActive) {
                    $sub = $parsed['subcategory'] !== '' ? ' (' . $parsed['subcategory'] . ')' : '';
                    $this->NotifyIfNew('alarm_' . $varID, 'Alarm' . $sub, "$name: " . IPS_GetName($varID), 2);
                } else {
                    $this->ClearNotified('alarm_' . $varID);
                }
                break;

            case 'warning':
                $isActive = $this->IsTriggered($newValue, $parsed['normalState']);
                if ($isActive) {
                    $this->NotifyIfNew('alarm_' . $varID, 'Warnung', "$name: " . IPS_GetName($varID), 1);
                } else {
                    $this->ClearNotified('alarm_' . $varID);
                }
                break;

            case 'contact':
                $isOpen = $this->IsTriggered($newValue, $parsed['normalState']);
                if ($isOpen) {
                    $sub = $parsed['subcategory'] !== '' ? ' (' . $parsed['subcategory'] . ')' : '';
                    $this->NotifyIfNew('contact_' . $varID, 'Kontakt offen' . $sub, "$name: " . IPS_GetName($varID), 0);
                } else {
                    $this->ClearNotified('contact_' . $varID);
                }
                break;

            case 'reachability':
                $isOffline = $this->IsTriggered($newValue, $parsed['normalState']);
                if ($isOffline) {
                    $this->NotifyIfNew('offline_' . $varID, 'Geraet offline', "$name: Nicht erreichbar", 1);
                } else {
                    $this->ClearNotified('offline_' . $varID);
                }
                break;
        }

        // Counter nach Echtzeit-Update aktualisieren
        $this->RunMonitor();
    }

    /**
     * Sendet eine Benachrichtigung nur wenn das Problem noch nicht bekannt ist.
     * Verhindert Notification-Spam für dauerhaft aktive Probleme.
     */
    private function NotifyIfNew(string $key, string $title, string $message, int $priority, bool $quietMode = false): void
    {
        $notified = json_decode($this->GetBuffer('NotifiedProblems') ?: '{}', true);
        if (!is_array($notified)) {
            $notified = [];
        }

        if (isset($notified[$key])) {
            // Bereits gemeldet – nicht nochmal senden
            return;
        }

        $notified[$key] = time();
        $this->SetBuffer('NotifiedProblems', json_encode($notified));

        if (!$quietMode) {
            $this->ProcessEvent($title, $message, $priority, []);
        }
    }

    /**
     * Löscht einen Problem-Key aus dem Notified-Tracker (Problem behoben).
     */
    private function ClearNotified(string $key): void
    {
        $notified = json_decode($this->GetBuffer('NotifiedProblems') ?: '{}', true);
        if (!is_array($notified)) {
            return;
        }
        if (isset($notified[$key])) {
            unset($notified[$key]);
            $this->SetBuffer('NotifiedProblems', json_encode($notified));
        }
    }

    /**
     * Liest das Inventar aus SmartInventory und normalisiert die Kurzschluessel.
     */
    private function LoadInventory(): array
    {
        $inventoryID = $this->ReadPropertyInteger('InventoryID');
        if ($inventoryID === 0 || !@IPS_InstanceExists($inventoryID)) {
            $this->SendDebug('LoadInventory', 'Keine InventoryID konfiguriert', 0);
            return [];
        }

        if (!function_exists('SINV_GetInventory')) {
            $this->SendDebug('LoadInventory', 'SINV_GetInventory nicht verfuegbar', 0);
            return [];
        }

        $json = @SINV_GetInventory($inventoryID);
        if (empty($json) || $json === '[]') {
            $this->SendDebug('LoadInventory', 'Leeres Inventar von SINV_GetInventory', 0);
            return [];
        }

        $raw = json_decode($json, true);
        if (!is_array($raw)) {
            $this->SendDebug('LoadInventory', 'JSON-Fehler: ' . substr($json, 0, 100), 0);
            return [];
        }

        // Kurzschluessel (i/n/r/v/c/s/t/u) auf lesbare Namen normalisieren
        $normalized = [];
        foreach ($raw as $device) {
            $vars = [];
            foreach (($device['v'] ?? []) as $v) {
                $vars[] = [
                    'varID'       => $v['v'],
                    'category'    => $v['c'],
                    'subcategory' => $v['s'] ?? '',
                    'normalState' => $v['n'] ?? null,
                    'type'        => $v['t'] ?? 0,
                    'lastUpdatedTS' => $v['u'] ?? 0,
                    'disabled'    => false, // disabled wurden beim Cachen bereits herausgefiltert
                ];
            }
            $normalized[] = [
                'instanceID'   => $device['i'],
                'instanceName' => $device['n'],
                'room'         => $device['r'] ?? '',
                'variables'    => $vars,
            ];
        }

        $this->SendDebug('LoadInventory', count($normalized) . ' Geraete geladen', 0);
        return $normalized;
    }

    /**
     * Parst einen SI:-Tag-String.
     */
    private function ParseTag(string $tag): array
    {
        $result = ['category' => '', 'subcategory' => '', 'normalState' => null, 'disabled' => false];

        if (!str_starts_with($tag, 'SI:')) {
            return $result;
        }

        $parts = explode(':', substr($tag, 3));
        $result['category'] = $parts[0] ?? '';

        for ($i = 1; $i < count($parts); $i++) {
            $p = $parts[$i];
            if ($p === 'disabled') {
                $result['disabled'] = true;
            } elseif (str_contains($p, '=')) {
                [$key, $val] = explode('=', $p, 2);
                $result['normalState'] = ['key' => $key, 'value' => $val];
            } else {
                $result['subcategory'] = $p;
            }
        }
        return $result;
    }

    /**
     * Prüft ob ein Wert als "ausgelöst/problematisch" gilt.
     */
    private function IsTriggered(mixed $value, ?array $normalState): bool
    {
        if ($normalState !== null) {
            $normalVal = $normalState['value'];
            if (is_bool($value)) {
                $normalBool = filter_var($normalVal, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($normalBool !== null) {
                    return $value !== $normalBool;
                }
            }
            if (is_numeric($value) && is_numeric($normalVal)) {
                return (float) $value !== (float) $normalVal;
            }
            return strcasecmp((string) $value, $normalVal) !== 0;
        }

        if (is_bool($value)) {
            return $value === true;
        }
        if (is_numeric($value)) {
            return (float) $value > 0;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['offline', 'nicht erreichbar', 'offen', 'open', 'alarm', 'fehler', 'error', 'true', 'ja']);
        }
        return false;
    }

    /**
     * Prüft ob eine Batterie-Variable unter dem Schwellwert ist.
     */
    private function IsBatteryLow(mixed $value, int $varType, int $threshold): bool
    {
        if ($varType === 0) { // Boolean
            return (bool) $value;
        }
        if (is_numeric($value)) {
            $f = (float) $value;
            return $f > 0 && $f < $threshold;
        }
        return false;
    }

    /**
     * Aktualisiert eine Counter-Variable nur wenn sich der Wert geändert hat.
     */
    private function UpdateCounterVar(string $ident, int $value): void
    {
        if ($this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    // =========================================================================
    // Öffentliche API
    // =========================================================================

    /**
     * Sendet ein strukturiertes Event.
     */
    public function SendEvent(string $PayloadJSON): void
    {
        $payload = json_decode($PayloadJSON, true);
        if (!is_array($payload)) {
            $this->SLogError('SmartNotifier: Invalid JSON payload');
            return;
        }

        $title    = $payload['Title'] ?? 'Info';
        $message  = $payload['Message'] ?? '';
        $priority = (int) ($payload['Priority'] ?? 0);
        $actions  = $payload['Actions'] ?? [];

        $this->ProcessEvent($title, $message, $priority, $actions);
    }

    /**
     * @deprecated Use SendEvent() instead.
     */
    public function SendMessage(string $title, string $message, int $priority): void
    {
        $this->ProcessEvent($title, $message, $priority, []);
    }

    // =========================================================================
    // Formular
    // =========================================================================

    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Monitoring',
                    'expanded' => true,
                    'items' => [
                        ['type' => 'SelectInstance', 'name' => 'InventoryID', 'caption' => 'SmartInventory Instanz'],
                        ['type' => 'NumberSpinner', 'name' => 'MonitorInterval', 'caption' => 'Monitor-Intervall', 'suffix' => 'Minuten (0 = deaktiviert)', 'minimum' => 0, 'maximum' => 60],
                        ['type' => 'NumberSpinner', 'name' => 'BatteryThreshold', 'caption' => 'Batterie-Schwellwert', 'suffix' => '%', 'minimum' => 5, 'maximum' => 50],
                        ['type' => 'NumberSpinner', 'name' => 'StaleThreshold', 'caption' => 'Stale-Sensor-Schwellwert', 'suffix' => 'Minuten ohne Update', 'minimum' => 0, 'maximum' => 1440],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Ausgabe-Kanaele',
                    'items' => [
                        ['type' => 'SelectInstance', 'name' => 'TargetVisu', 'caption' => 'Kachel-Visualisierung (fuer Push)'],
                        ['type' => 'CheckBox', 'name' => 'EnablePush', 'caption' => 'Push-Nachrichten aktivieren'],
                        ['type' => 'SelectInstance', 'name' => 'TargetSonosTTS', 'caption' => 'Sonos TTS Instanz'],
                        ['type' => 'CheckBox', 'name' => 'EnableTTS', 'caption' => 'Sprachausgabe aktivieren'],
                        ['type' => 'SelectInstance', 'name' => 'TargetVestaboard', 'caption' => 'VestaboardGenerator Instanz'],
                        ['type' => 'CheckBox', 'name' => 'EnableVestaboard', 'caption' => 'Vestaboard Alarm-Push aktivieren'],
                        ['type' => 'SelectInstance', 'name' => 'TargetSMTP', 'caption' => 'SMTP Instanz (fuer E-Mails)'],
                        ['type' => 'ValidationTextBox', 'name' => 'EmailAddress', 'caption' => 'Empfaenger E-Mail Adresse'],
                        ['type' => 'CheckBox', 'name' => 'EnableSMTP', 'caption' => 'E-Mail Benachrichtigungen aktivieren'],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'HmIP MP3-Gong Einstellungen',
                    'items' => [
                        ['type' => 'SelectInstance', 'name' => 'TargetMP3P', 'caption' => 'HmIP MP3P Instanz'],
                        ['type' => 'CheckBox', 'name' => 'EnableMP3P', 'caption' => 'MP3-Gong aktivieren'],
                        ['type' => 'Label', 'bold' => true, 'caption' => 'High Priority (Alarm):'],
                        [
                            'type' => 'RowLayout',
                            'items' => [
                                ['type' => 'ValidationTextBox', 'name' => 'MP3P_Track_High', 'caption' => 'Track (z.B. 1)'],
                                ['type' => 'NumberSpinner', 'name' => 'MP3P_Volume_High', 'caption' => 'Lautstaerke (%)', 'minimum' => 0, 'maximum' => 100, 'suffix' => '%'],
                                ['type' => 'NumberSpinner', 'name' => 'MP3P_Track_Duration_High', 'caption' => 'Track Dauer (s, 0=1x)', 'minimum' => 0, 'suffix' => 's'],
                                ['type' => 'Select', 'name' => 'MP3P_LED_Color_High', 'caption' => 'LED Farbe', 'options' => [
                                    ['caption' => 'Aus', 'value' => 0], ['caption' => 'Blau', 'value' => 1], ['caption' => 'Gruen', 'value' => 2],
                                    ['caption' => 'Tuerkis', 'value' => 3], ['caption' => 'Rot', 'value' => 4], ['caption' => 'Violett', 'value' => 5],
                                    ['caption' => 'Gelb/Orange', 'value' => 6], ['caption' => 'Weiss', 'value' => 7],
                                ]],
                                ['type' => 'NumberSpinner', 'name' => 'MP3P_LED_Duration_High', 'caption' => 'LED Dauer (s, 0=unendlich)', 'minimum' => 0, 'suffix' => 's'],
                            ],
                        ],
                        ['type' => 'Label', 'bold' => true, 'caption' => 'Low / Medium Priority (Hinweis):'],
                        [
                            'type' => 'RowLayout',
                            'items' => [
                                ['type' => 'ValidationTextBox', 'name' => 'MP3P_Track_Low', 'caption' => 'Track (z.B. 2)'],
                                ['type' => 'NumberSpinner', 'name' => 'MP3P_Volume_Low', 'caption' => 'Lautstaerke (%)', 'minimum' => 0, 'maximum' => 100, 'suffix' => '%'],
                                ['type' => 'NumberSpinner', 'name' => 'MP3P_Track_Duration_Low', 'caption' => 'Track Dauer (s, 0=1x)', 'minimum' => 0, 'suffix' => 's'],
                                ['type' => 'Select', 'name' => 'MP3P_LED_Color_Low', 'caption' => 'LED Farbe', 'options' => [
                                    ['caption' => 'Aus', 'value' => 0], ['caption' => 'Blau', 'value' => 1], ['caption' => 'Gruen', 'value' => 2],
                                    ['caption' => 'Tuerkis', 'value' => 3], ['caption' => 'Rot', 'value' => 4], ['caption' => 'Violett', 'value' => 5],
                                    ['caption' => 'Gelb/Orange', 'value' => 6], ['caption' => 'Weiss', 'value' => 7],
                                ]],
                                ['type' => 'NumberSpinner', 'name' => 'MP3P_LED_Duration_Low', 'caption' => 'LED Dauer (s, 0=unendlich)', 'minimum' => 0, 'suffix' => 's'],
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Routing-Regeln (Nach Prioritaet)',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Definiert, welche Nachrichten-Prioritaet ueber welche Kanaele gesendet wird.'],
                        [
                            'type' => 'List',
                            'name' => 'RoutingRules',
                            'caption' => 'Aktions-Matrix',
                            'add' => false,
                            'delete' => false,
                            'columns' => [
                                ['caption' => 'Prioritaet', 'name' => 'Level', 'width' => '150px', 'edit' => ['type' => 'Select', 'options' => [
                                    ['caption' => '0 (Info)', 'value' => 0],
                                    ['caption' => '1 (Hinweis)', 'value' => 1],
                                    ['caption' => '2 (Alarm)', 'value' => 2],
                                ]]],
                                ['caption' => 'Push', 'name' => 'Push', 'width' => '80px', 'edit' => ['type' => 'CheckBox']],
                                ['caption' => 'Sprache', 'name' => 'TTS', 'width' => '80px', 'edit' => ['type' => 'CheckBox']],
                                ['caption' => 'MP3', 'name' => 'MP3', 'width' => '80px', 'edit' => ['type' => 'CheckBox']],
                                ['caption' => 'Vestaboard', 'name' => 'Vesta', 'width' => '80px', 'edit' => ['type' => 'CheckBox']],
                                ['caption' => 'E-Mail', 'name' => 'Mail', 'width' => '80px', 'edit' => ['type' => 'CheckBox']],
                            ],
                        ],
                    ],
                ],
            ],
            'actions' => [
                [
                    'type' => 'RowLayout',
                    'items' => [
                        ['type' => 'Button', 'caption' => 'Monitor jetzt ausfuehren', 'onClick' => 'NOTIFY_RunMonitor($id); echo "Monitor-Durchlauf abgeschlossen.";'],
                        ['type' => 'Button', 'caption' => 'Subscriptions aktualisieren', 'onClick' => 'NOTIFY_RefreshSubscriptions($id); echo "Subscriptions aktualisiert.";'],
                    ],
                ],
                ['type' => 'Button', 'caption' => 'Test: Info (Prio 0)', 'onClick' => 'NOTIFY_SendMessage($id, \'Test\', \'Dies ist eine Info-Meldung.\', 0);'],
                ['type' => 'Button', 'caption' => 'Test: Alarm (Prio 2)', 'onClick' => 'NOTIFY_SendMessage($id, \'Alarm\', \'Dies ist ein kritischer Test!\', 2);'],
            ],
        ]);
    }

    // =========================================================================
    // Nachrichtenverarbeitung
    // =========================================================================

    protected function OnCentralStateChanged(string $stateName, mixed $newValue): void
    {
        if ($stateName === 'ActivityMode' && (int) $newValue === 0) {
            $this->ProcessMorningQueue();
        }
    }

    private function ProcessEvent(string $title, string $message, int $priority, array $actions): void
    {
        $this->SLogInfo("Message received: [$title] $message (Prio: $priority)");

        $isHome    = $this->IsHome();
        $isSleeping = $this->IsSleeping();
        $isCinema  = $this->IsCinema();

        // Schlaf-Modus: Prio < 2 in Queue
        if ($isSleeping && $priority < 2) {
            $this->QueueMessage($title, $message);
            $this->SLogInfo('Schlafmodus aktiv: Nachricht in die Morgen-Warteschlange verschoben.');
            return;
        }

        $rules = json_decode($this->ReadPropertyString('RoutingRules'), true) ?: [];
        $rule  = ['Push' => false, 'TTS' => false, 'MP3' => false, 'Vesta' => false, 'Mail' => false];
        foreach ($rules as $r) {
            if ((int) ($r['Level'] ?? 0) === $priority) {
                $rule = $r;
                break;
            }
        }

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
            $canSpeak = true;
            if ($isCinema && $priority < 2) {
                $canSpeak = false;
            }
            if ($isSleeping && $priority === 2) {
                $canSpeak = true;
            }

            if ($canSpeak) {
                if (!empty($rule['TTS'])) {
                    $prefix = ($priority === 2) ? 'Achtung! ' : '';
                    $this->TriggerTTS($prefix . $title . ': ' . $message);
                }

                if (!empty($rule['MP3'])) {
                    $high         = ($priority === 2);
                    $track        = $this->ReadPropertyString($high ? 'MP3P_Track_High' : 'MP3P_Track_Low');
                    $vol          = $this->ReadPropertyInteger($high ? 'MP3P_Volume_High' : 'MP3P_Volume_Low');
                    $trackDur     = $this->ReadPropertyInteger($high ? 'MP3P_Track_Duration_High' : 'MP3P_Track_Duration_Low');
                    $color        = $this->ReadPropertyInteger($high ? 'MP3P_LED_Color_High' : 'MP3P_LED_Color_Low');
                    $ledDur       = $this->ReadPropertyInteger($high ? 'MP3P_LED_Duration_High' : 'MP3P_LED_Duration_Low');
                    if ($track !== '') {
                        $this->TriggerMP3P($track, $vol, $trackDur, $color, $ledDur);
                    }
                }
            }
        }
    }

    private function QueueMessage(string $title, string $message): void
    {
        $queue = json_decode($this->GetBuffer('MessageQueue') ?: '[]', true) ?: [];
        $queue[] = ['time' => time(), 'title' => $title, 'message' => $message];
        $this->SetBuffer('MessageQueue', json_encode($queue));
        $this->SLogInfo("Nachricht in Morning-Queue gespeichert: $message");
    }

    private function ProcessMorningQueue(): void
    {
        $queue = json_decode($this->GetBuffer('MessageQueue') ?: '[]', true) ?: [];
        if (count($queue) === 0) {
            return;
        }

        $count = count($queue);
        $this->SLogInfo("Guten Morgen. Verarbeite $count gesammelte Nachrichten.");

        $ttsMsg = "Guten Morgen. Waehrend du geschlafen hast, gab es $count Meldungen. ";
        foreach ($queue as $item) {
            $ttsMsg .= $item['title'] . ': ' . $item['message'] . '. ';
        }

        $this->TriggerTTS($ttsMsg);
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
                $targetId = (int) $actions[0];
            }
            $icon = match ($sound) {
                'alarm'   => 'Alert',
                'warning' => 'Warning',
                default   => 'Information',
            };
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
                $this->SLogError('Fehler bei Sonos TTS: ' . $e->getMessage());
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
                @IPS_RunScriptText('VESTAG_PushAlert(' . $vestaId . ', ' . var_export(substr($message, 0, 132), true) . ', true);');
            } catch (Exception $e) {
                $this->SLogError('Fehler beim Senden an Vestaboard: ' . $e->getMessage());
            }
        }
    }

    private function TriggerEmail(string $title, string $message): void
    {
        if (!$this->ReadPropertyBoolean('EnableSMTP')) return;

        $smtp  = $this->ReadPropertyInteger('TargetSMTP');
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
                $this->SLogError('Fehler beim Senden der E-Mail: ' . $e->getMessage());
            }
        }
    }
}
