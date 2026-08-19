<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';

/**
 * SmartInventory — Automatische Geräte-Inventarisierung & Überwachung.
 *
 * Inventarisiert alle Variablen im Symcon-Objektbaum, die einen SI:-Tag
 * im Beschreibungsfeld tragen. Überwacht Batterie, Erreichbarkeit, Alarme
 * und Kontakte per MessageSink und meldet Probleme an den SmartNotifier.
 *
 * Tags werden einmalig gesetzt (manuell oder per KI-Tagger) und danach
 * vom Scan nur noch gelesen – nie überschrieben.
 */
class SmartInventory extends IPSModuleStrict
{
    use SmartLog_Trait;

    // Tag-Prefix
    private const TAG_PREFIX = 'SI:';

    // Überwachte Kategorien (lösen MessageSink-Events aus)
    private const MONITORED_CATEGORIES = [
        'battery', 'reachability', 'alarm', 'warning', 'contact', 'info'
    ];

    // ─────────────────────────────────────────────────────────────────
    // Lifecycle
    // ─────────────────────────────────────────────────────────────────

    public function Create(): void
    {
        parent::Create();

        // Konfiguration
        $this->RegisterPropertyInteger('ScanInterval', 60);           // Minuten (0 = nur manuell)
        $this->RegisterPropertyInteger('BatteryThreshold', 15);       // Prozent
        $this->RegisterPropertyInteger('RoomPathSegment', 2);         // Segment von rechts im Pfad
        $this->RegisterPropertyInteger('NotifierID', 0);              // SmartNotifier Instanz
        $this->RegisterPropertyInteger('GeminiIOID', 0);              // SmartGeminiIO Instanz

        // Persistenter Speicher für KI-Vorschläge (überlebt Modul-Updates)
        $this->RegisterAttributeString('AISuggestions', '[]');

        // Timer für periodischen Scan
        $this->RegisterTimer('ScanTimer', 0, 'SINV_Scan(' . $this->InstanceID . ');');

        // Status-Variablen
        $this->RegisterVariableInteger('DeviceCount', 'Geräte gesamt', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'microchip'
        ], 1);
        $this->RegisterVariableInteger('TaggedVarCount', 'Getaggte Variablen', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'tags'
        ], 2);
        $this->RegisterVariableInteger('OfflineCount', 'Geräte offline', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'wifi-slash',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS' => json_encode([
                [
                    'IntervalMinValue' => 0, 'IntervalMaxValue' => 1,
                    'ConstantActive' => false, 'ConstantValue' => '',
                    'ConversionFactor' => 1,
                    'PrefixActive' => false, 'PrefixValue' => '',
                    'SuffixActive' => false, 'SuffixValue' => '',
                    'DigitsActive' => false, 'DigitsValue' => 0,
                    'IconActive' => true, 'IconValue' => 'wifi',
                    'ColorActive' => true, 'ColorValue' => 0x00CC00,
                    'ContentColorActive' => false, 'ContentColorValue' => -1
                ],
                [
                    'IntervalMinValue' => 1, 'IntervalMaxValue' => 999,
                    'ConstantActive' => false, 'ConstantValue' => '',
                    'ConversionFactor' => 1,
                    'PrefixActive' => false, 'PrefixValue' => '',
                    'SuffixActive' => false, 'SuffixValue' => '',
                    'DigitsActive' => false, 'DigitsValue' => 0,
                    'IconActive' => true, 'IconValue' => 'wifi-slash',
                    'ColorActive' => true, 'ColorValue' => 0xFF4400,
                    'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
                ]
            ])
        ], 3);
        $this->RegisterVariableInteger('LowBatteryCount', 'Batterien kritisch', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'battery-quarter',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS' => json_encode([
                [
                    'IntervalMinValue' => 0, 'IntervalMaxValue' => 1,
                    'ConstantActive' => false, 'ConstantValue' => '',
                    'ConversionFactor' => 1,
                    'PrefixActive' => false, 'PrefixValue' => '',
                    'SuffixActive' => false, 'SuffixValue' => '',
                    'DigitsActive' => false, 'DigitsValue' => 0,
                    'IconActive' => true, 'IconValue' => 'battery-full',
                    'ColorActive' => true, 'ColorValue' => 0x00CC00,
                    'ContentColorActive' => false, 'ContentColorValue' => -1
                ],
                [
                    'IntervalMinValue' => 1, 'IntervalMaxValue' => 999,
                    'ConstantActive' => false, 'ConstantValue' => '',
                    'ConversionFactor' => 1,
                    'PrefixActive' => false, 'PrefixValue' => '',
                    'SuffixActive' => false, 'SuffixValue' => '',
                    'DigitsActive' => false, 'DigitsValue' => 0,
                    'IconActive' => true, 'IconValue' => 'battery-quarter',
                    'ColorActive' => true, 'ColorValue' => 0xFF4400,
                    'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
                ]
            ])
        ], 10);
        $this->RegisterVariableInteger('ActiveAlarmCount', 'Alarme aktiv', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'bell',
            'INTERVALS_ACTIVE' => true,
            'INTERVALS' => json_encode([
                [
                    'IntervalMinValue' => 0, 'IntervalMaxValue' => 1,
                    'ConstantActive' => false, 'ConstantValue' => '',
                    'ConversionFactor' => 1,
                    'PrefixActive' => false, 'PrefixValue' => '',
                    'SuffixActive' => false, 'SuffixValue' => '',
                    'DigitsActive' => false, 'DigitsValue' => 0,
                    'IconActive' => true, 'IconValue' => 'bell',
                    'ColorActive' => true, 'ColorValue' => 0x00CC00,
                    'ContentColorActive' => false, 'ContentColorValue' => -1
                ],
                [
                    'IntervalMinValue' => 1, 'IntervalMaxValue' => 999,
                    'ConstantActive' => false, 'ConstantValue' => '',
                    'ConversionFactor' => 1,
                    'PrefixActive' => false, 'PrefixValue' => '',
                    'SuffixActive' => false, 'SuffixValue' => '',
                    'DigitsActive' => false, 'DigitsValue' => 0,
                    'IconActive' => true, 'IconValue' => 'bell-exclamation',
                    'ColorActive' => true, 'ColorValue' => 0xFF0000,
                    'ContentColorActive' => true, 'ContentColorValue' => 0xFFFFFF
                ]
            ])
        ], 20);
        $this->RegisterVariableInteger('OpenContactCount', 'Kontakte offen', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'door-open'
        ], 30);
        $this->RegisterVariableString('LastScan', 'Letzter Scan', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'clock'
        ], 900);
        $this->RegisterVariableString('ScanDuration', 'Scan-Dauer', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'stopwatch'
        ], 901);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Timer setzen
        $interval = $this->ReadPropertyInteger('ScanInterval');
        $this->SetTimerInterval('ScanTimer', $interval > 0 ? $interval * 60 * 1000 : 0);

        // Auf Instanz-Erstellung/Löschung lauschen
        $this->RegisterMessage(0, IPS_INSTANCEMESSAGE);

        // Initialen Scan anstoßen (verzögert, damit alle Module geladen sind)
        if (IPS_GetKernelRunlevel() >= KR_READY) {
            $this->resubscribeAll();
        }

        $this->SetStatus(102);
    }

    // ─────────────────────────────────────────────────────────────────
    // MessageSink
    // ─────────────────────────────────────────────────────────────────

    public function MessageSink(int $timeStamp, int $senderID, int $message, array $data): void
    {
        // Instanz-Events
        if ($message === IPS_INSTANCEMESSAGE) {
            if ($data[0] === IM_CREATE) {
                $this->handleNewInstance($senderID);
            } elseif ($data[0] === IM_DELETE) {
                $this->handleDeletedInstance($senderID);
            }
            return;
        }

        // Variablen-Updates
        if ($message !== IPS_VARIABLEMESSAGE || $data[0] !== VM_UPDATE) {
            return;
        }

        $info = @IPS_GetObject($senderID);
        if ($info === false) {
            return;
        }
        $tag = $info['ObjectInfo'];
        if (!str_starts_with($tag, self::TAG_PREFIX)) {
            return;
        }

        $parsed = $this->parseTag($tag);
        if ($parsed['disabled']) {
            return;
        }

        $newValue = $data[1];
        $oldValue = $data[2];
        if ($newValue === $oldValue) {
            return;
        }

        match ($parsed['category']) {
            'battery'      => $this->handleBattery($senderID, $newValue, $parsed),
            'reachability' => $this->handleReachability($senderID, $newValue, $parsed),
            'alarm'        => $this->handleAlarm($senderID, $newValue, $parsed),
            'warning'      => $this->handleWarning($senderID, $newValue),
            'contact'      => $this->handleContact($senderID, $newValue, $parsed),
            default        => null,
        };
    }

    // ─────────────────────────────────────────────────────────────────
    // Scan
    // ─────────────────────────────────────────────────────────────────

    public function Scan(): string
    {
        $startTime = microtime(true);

        ['inventory' => $inventory, 'untagged' => $untaggedInstances] = $this->buildInventoryData();

        $deviceCount    = count($inventory);
        $taggedVarCount = array_sum(array_map(fn($d) => count($d['variables']), $inventory));

        // Inventar speichern (für MessageSink-Nachschlagen)
        $this->SetBuffer('Inventory', json_encode($inventory));
        $this->SetBuffer('UntaggedInstances', json_encode($untaggedInstances));

        // Counters berechnen
        $offlineCount     = 0;
        $lowBatteryCount  = 0;
        $activeAlarmCount = 0;
        $openContactCount = 0;
        $threshold        = $this->ReadPropertyInteger('BatteryThreshold');

        foreach ($inventory as $device) {
            foreach ($device['variables'] as $v) {
                if ($v['disabled']) {
                    continue;
                }
                match ($v['category']) {
                    'reachability' => $offlineCount     += $this->isProblematic($v) ? 1 : 0,
                    'battery'      => $lowBatteryCount  += $this->isBatteryLow($v, $threshold) ? 1 : 0,
                    'alarm'        => $activeAlarmCount += $this->isProblematic($v) ? 1 : 0,
                    'warning'      => $activeAlarmCount += $this->isProblematic($v) ? 1 : 0,
                    'contact'      => $openContactCount += $this->isContactOpen($v) ? 1 : 0,
                    default        => null,
                };
            }
        }

        // Status-Variablen aktualisieren
        if ($this->GetValue('DeviceCount') !== $deviceCount) {
            $this->SetValue('DeviceCount', $deviceCount);
        }
        if ($this->GetValue('TaggedVarCount') !== $taggedVarCount) {
            $this->SetValue('TaggedVarCount', $taggedVarCount);
        }
        if ($this->GetValue('OfflineCount') !== $offlineCount) {
            $this->SetValue('OfflineCount', $offlineCount);
        }
        if ($this->GetValue('LowBatteryCount') !== $lowBatteryCount) {
            $this->SetValue('LowBatteryCount', $lowBatteryCount);
        }
        if ($this->GetValue('ActiveAlarmCount') !== $activeAlarmCount) {
            $this->SetValue('ActiveAlarmCount', $activeAlarmCount);
        }
        if ($this->GetValue('OpenContactCount') !== $openContactCount) {
            $this->SetValue('OpenContactCount', $openContactCount);
        }

        // MessageSink-Subscriptions aktualisieren
        $this->resubscribeAll();

        $duration = round((microtime(true) - $startTime) * 1000);
        $this->SetValue('LastScan', date('d.m.Y H:i:s'));
        $this->SetValue('ScanDuration', $duration . ' ms');

        $this->SendDebug('Scan', "Geräte: $deviceCount, Variablen: $taggedVarCount, Ungetaggt: " . count($untaggedInstances), 0);

        $this->ReloadForm();

        return json_encode([
            'devices'    => $deviceCount,
            'variables'  => $taggedVarCount,
            'untagged'   => count($untaggedInstances),
            'offline'    => $offlineCount,
            'lowBattery' => $lowBatteryCount,
            'alarms'     => $activeAlarmCount,
            'contacts'   => $openContactCount,
            'duration'   => $duration . ' ms',
        ]);
    }

    /**
     * Baut die Inventar-Daten direkt aus dem Objektbaum auf (kein Buffer).
     * Wird von Scan() UND GetConfigurationForm() aufgerufen → immer aktuell.
     *
     * @return array{inventory: array, untagged: array}
     */
    private function buildInventoryData(): array
    {
        $inventory         = [];
        $untaggedInstances = [];

        foreach (IPS_GetInstanceList() as $instanceID) {
            $instance = @IPS_GetInstance($instanceID);
            if ($instance === false) {
                continue;
            }

            // Nur Device-Instanzen (ModuleType 3)
            if ($instance['ModuleInfo']['ModuleType'] !== 3) {
                continue;
            }

            // Sich selbst überspringen
            if ($instanceID === $this->InstanceID) {
                continue;
            }

            $instanceName = IPS_GetName($instanceID);
            $moduleName   = $instance['ModuleInfo']['ModuleName'];
            $moduleGUID   = $instance['ModuleInfo']['ModuleID'];
            $room         = $this->resolveRoom($instanceID);

            $children     = IPS_GetChildrenIDs($instanceID);
            $instanceVars = [];
            $hasTaggedVar = false;

            foreach ($children as $childID) {
                $obj = @IPS_GetObject($childID);
                if ($obj === false || $obj['ObjectType'] !== 2) {
                    continue;
                }

                if (str_starts_with($obj['ObjectIdent'], '_SI_')) {
                    continue;
                }

                $info = $obj['ObjectInfo'];
                if (!str_starts_with($info, self::TAG_PREFIX)) {
                    continue;
                }

                $hasTaggedVar = true;

                $var    = IPS_GetVariable($childID);
                $parsed = $this->parseTag($info);
                $value  = $this->getFormattedValue($childID);

                $instanceVars[] = [
                    'varID'          => $childID,
                    'ident'          => $obj['ObjectIdent'],
                    'name'           => $obj['ObjectName'],
                    'tag'            => $info,
                    'category'       => $parsed['category'],
                    'subcategory'    => $parsed['subcategory'],
                    'disabled'       => $parsed['disabled'],
                    'normalState'    => $parsed['normalState'],
                    'type'           => $var['VariableType'],
                    'value'          => GetValue($childID),
                    'valueFormatted' => $value,
                    'lastUpdate'     => date('Y-m-d H:i:s', $var['VariableUpdated']),
                ];
            }

            if ($hasTaggedVar) {
                $inventory[] = [
                    'instanceID'   => $instanceID,
                    'instanceName' => $instanceName,
                    'moduleName'   => $moduleName,
                    'moduleGUID'   => $moduleGUID,
                    'room'         => $room,
                    'variables'    => $instanceVars,
                ];
            } else {
                $varCount = 0;
                foreach ($children as $cid) {
                    $co = @IPS_GetObject($cid);
                    if ($co !== false && $co['ObjectType'] === 2 && !str_starts_with($co['ObjectIdent'], '_SI_')) {
                        $varCount++;
                    }
                }
                if ($varCount > 0) {
                    $ignoreVarID = @IPS_GetObjectIDByIdent('_SI_Ignore', $instanceID);
                    if ($ignoreVarID !== false && GetValue($ignoreVarID)) {
                        continue;
                    }
                    $untaggedInstances[] = [
                        'instanceID'   => $instanceID,
                        'instanceName' => $instanceName,
                        'moduleName'   => $moduleName,
                        'varCount'     => $varCount,
                        'room'         => $room,
                    ];
                }
            }
        }

        return ['inventory' => $inventory, 'untagged' => $untaggedInstances];
    }


    // ─────────────────────────────────────────────────────────────────
    // Tag-Parsing
    // ─────────────────────────────────────────────────────────────────

    /**
     * Parst einen SI:-Tag-String in seine Bestandteile.
     *
     * Formate:
     *   SI:battery
     *   SI:battery:disabled
     *   SI:alarm:smoke
     *   SI:alarm:smoke:disabled
     *   SI:reachability:online=false
     *   SI:contact:closed=CLOSED:disabled
     */
    private function parseTag(string $tag): array
    {
        $result = [
            'category'    => '',
            'subcategory' => '',
            'normalState' => null,
            'disabled'    => false,
        ];

        if (!str_starts_with($tag, self::TAG_PREFIX)) {
            return $result;
        }

        $stripped = substr($tag, strlen(self::TAG_PREFIX)); // Remove "SI:"
        $parts = explode(':', $stripped);

        // Erstes Teil ist immer die Kategorie
        $result['category'] = $parts[0] ?? '';

        // Restliche Teile durchgehen
        for ($i = 1; $i < count($parts); $i++) {
            $p = $parts[$i];
            if ($p === 'disabled') {
                $result['disabled'] = true;
            } elseif (str_contains($p, '=')) {
                [$key, $val] = explode('=', $p, 2);
                $result['normalState'] = ['key' => $key, 'value' => $val];
            } else {
                // Unterkategorie (z.B. alarm:smoke, sensor:temp)
                $result['subcategory'] = $p;
            }
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────
    // Monitoring Handlers
    // ─────────────────────────────────────────────────────────────────

    private function handleBattery(int $varID, mixed $newValue, array $parsed): void
    {
        $threshold = $this->ReadPropertyInteger('BatteryThreshold');
        $var = IPS_GetVariable($varID);
        $isLow = false;

        if ($var['VariableType'] === 0) {
            // Boolean: true = batterie schwach
            $isLow = (bool) $newValue;
        } elseif (is_numeric($newValue)) {
            // Numerisch: unter Schwellwert
            $isLow = ((float) $newValue) < $threshold;
        }

        if ($this->shouldNotify($varID, $isLow)) {
            $instanceName = $this->getParentInstanceName($varID);
            $room = $this->getVarRoom($varID);
            $roomStr = $room !== '' ? " ($room)" : '';

            if ($isLow) {
                $this->notifyProblem(
                    'Batterie schwach',
                    $instanceName . $roomStr . ': Batterie kritisch',
                    1
                );
            }
        }

        $this->recalculateCounters();
    }

    private function handleReachability(int $varID, mixed $newValue, array $parsed): void
    {
        $isOffline = $this->evaluateReachability($newValue, $parsed);

        if ($this->shouldNotify($varID, $isOffline)) {
            $instanceName = $this->getParentInstanceName($varID);
            $room = $this->getVarRoom($varID);
            $roomStr = $room !== '' ? " ($room)" : '';

            if ($isOffline) {
                $this->notifyProblem(
                    'Gerät offline',
                    $instanceName . $roomStr . ': Nicht erreichbar',
                    2
                );
            }
        }

        $this->recalculateCounters();
    }

    private function handleAlarm(int $varID, mixed $newValue, array $parsed): void
    {
        $isActive = $this->isValueTriggered($newValue, $parsed);

        if ($this->shouldNotify($varID, $isActive)) {
            $instanceName = $this->getParentInstanceName($varID);
            $varName = IPS_GetName($varID);
            $room = $this->getVarRoom($varID);
            $roomStr = $room !== '' ? " ($room)" : '';
            $sub = $parsed['subcategory'] !== '' ? ' (' . $parsed['subcategory'] . ')' : '';

            if ($isActive) {
                $this->notifyProblem(
                    'Alarm' . $sub,
                    $instanceName . $roomStr . ': ' . $varName,
                    3
                );
            }
        }

        $this->recalculateCounters();
    }

    private function handleWarning(int $varID, mixed $newValue): void
    {
        $isActive = (bool) $newValue;

        if ($this->shouldNotify($varID, $isActive)) {
            $instanceName = $this->getParentInstanceName($varID);
            $varName = IPS_GetName($varID);
            $room = $this->getVarRoom($varID);
            $roomStr = $room !== '' ? " ($room)" : '';

            if ($isActive) {
                $this->notifyProblem(
                    'Warnung',
                    $instanceName . $roomStr . ': ' . $varName,
                    1
                );
            }
        }

        $this->recalculateCounters();
    }

    private function handleContact(int $varID, mixed $newValue, array $parsed): void
    {
        $this->recalculateCounters();
    }

    // ─────────────────────────────────────────────────────────────────
    // Instanz-Events
    // ─────────────────────────────────────────────────────────────────

    private function handleNewInstance(int $instanceID): void
    {
        $instance = @IPS_GetInstance($instanceID);
        if ($instance === false || $instance['ModuleInfo']['ModuleType'] !== 3) {
            return;
        }

        $this->SendDebug('NewInstance', 'Neue Instanz erkannt: ' . IPS_GetName($instanceID) . " (#$instanceID)", 0);
        // Inventar wird beim nächsten Scan aktualisiert – neue Instanz erscheint als "ungetaggt"
    }

    private function handleDeletedInstance(int $instanceID): void
    {
        $this->SendDebug('DeletedInstance', "Instanz gelöscht: #$instanceID", 0);
        // Bereinige MessageSink-Subscriptions beim nächsten Scan
    }

    // ─────────────────────────────────────────────────────────────────
    // API (öffentlich)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Sucht Variablen nach Tag und optional Raum.
     */
    public function Query(string $tag, string $room = ''): string
    {
        $inventory = json_decode($this->GetBuffer('Inventory'), true) ?: [];
        $results = [];

        foreach ($inventory as $device) {
            if ($room !== '' && strcasecmp($device['room'], $room) !== 0) {
                continue;
            }
            foreach ($device['variables'] as $v) {
                $matchTag = $v['category'];
                if ($v['subcategory'] !== '') {
                    $matchTag .= ':' . $v['subcategory'];
                }
                if ($tag === $v['category'] || $tag === $matchTag) {
                    $results[] = [
                        'instanceID'   => $device['instanceID'],
                        'instanceName' => $device['instanceName'],
                        'room'         => $device['room'],
                        'varID'        => $v['varID'],
                        'varName'      => $v['name'],
                        'tag'          => $v['tag'],
                        'value'        => $v['value'],
                        'valueFormatted' => $v['valueFormatted'],
                    ];
                }
            }
        }

        return json_encode($results);
    }

    /**
     * Gibt alle bekannten Räume zurück.
     */
    public function GetRooms(): string
    {
        $inventory = json_decode($this->GetBuffer('Inventory'), true) ?: [];
        $rooms = [];
        foreach ($inventory as $device) {
            if ($device['room'] !== '' && !in_array($device['room'], $rooms)) {
                $rooms[] = $device['room'];
            }
        }
        sort($rooms);
        return json_encode($rooms);
    }

    /**
     * Gibt alle Geräte eines Raums zurück.
     */
    public function GetByRoom(string $room): string
    {
        $inventory = json_decode($this->GetBuffer('Inventory'), true) ?: [];
        $results = [];
        foreach ($inventory as $device) {
            if (strcasecmp($device['room'], $room) === 0) {
                $results[] = $device;
            }
        }
        return json_encode($results);
    }

    /**
     * Gibt alle Batterien unter dem Schwellwert zurück.
     */
    public function GetLowBattery(int $threshold = 0): string
    {
        if ($threshold <= 0) {
            $threshold = $this->ReadPropertyInteger('BatteryThreshold');
        }
        $inventory = json_decode($this->GetBuffer('Inventory'), true) ?: [];
        $results = [];

        foreach ($inventory as $device) {
            foreach ($device['variables'] as $v) {
                if ($v['category'] === 'battery' && !$v['disabled'] && $this->isBatteryLow($v, $threshold)) {
                    $results[] = [
                        'instanceID'   => $device['instanceID'],
                        'instanceName' => $device['instanceName'],
                        'room'         => $device['room'],
                        'varID'        => $v['varID'],
                        'value'        => $v['value'],
                    ];
                }
            }
        }

        return json_encode($results);
    }

    /**
     * Gibt alle offline gemeldeten Geräte zurück.
     */
    public function GetOffline(): string
    {
        $inventory = json_decode($this->GetBuffer('Inventory'), true) ?: [];
        $results = [];

        foreach ($inventory as $device) {
            foreach ($device['variables'] as $v) {
                if ($v['category'] === 'reachability' && !$v['disabled'] && $this->isProblematic($v)) {
                    $results[] = [
                        'instanceID'   => $device['instanceID'],
                        'instanceName' => $device['instanceName'],
                        'room'         => $device['room'],
                        'varID'        => $v['varID'],
                        'value'        => $v['value'],
                    ];
                }
            }
        }

        return json_encode($results);
    }

    /**
     * Gibt alle aktiven Alarme zurück.
     */
    public function GetActiveAlarms(): string
    {
        $inventory = json_decode($this->GetBuffer('Inventory'), true) ?: [];
        $results = [];
        foreach ($inventory as $device) {
            foreach ($device['variables'] as $v) {
                if (in_array($v['category'], ['alarm', 'warning']) && !$v['disabled'] && $this->isProblematic($v)) {
                    $results[] = [
                        'instanceID'   => $device['instanceID'],
                        'instanceName' => $device['instanceName'],
                        'room'         => $device['room'],
                        'varID'        => $v['varID'],
                        'varName'      => $v['name'],
                        'tag'          => $v['tag'],
                        'value'        => $v['value'],
                    ];
                }
            }
        }

        return json_encode($results);
    }

    /**
     * Gibt alle nicht getaggten Instanzen zurück.
     */
    public function GetUntagged(): string
    {
        return $this->GetBuffer('UntaggedInstances') ?: '[]';
    }

    /**
     * Setzt den Raum einer Instanz manuell.
     */
    public function SetRoom(int $instanceID, string $room): bool
    {
        if (!@IPS_InstanceExists($instanceID)) {
            return false;
        }

        $roomVarID = @IPS_GetObjectIDByIdent('_SI_Room', $instanceID);
        if ($roomVarID === false) {
            $roomVarID = IPS_CreateVariable(3); // String
            IPS_SetParent($roomVarID, $instanceID);
            IPS_SetIdent($roomVarID, '_SI_Room');
            IPS_SetName($roomVarID, 'Raum (SmartInventory)');
            IPS_SetHidden($roomVarID, true);
        }

        SetValue($roomVarID, $room);
        return true;
    }

    /**
     * Setzt den Tag einer Variable.
     */
    public function SetTag(int $varID, string $tag): bool
    {
        if (!@IPS_VariableExists($varID)) {
            return false;
        }
        if ($tag !== '' && !str_starts_with($tag, self::TAG_PREFIX)) {
            $tag = self::TAG_PREFIX . $tag;
        }
        IPS_SetInfo($varID, $tag);
        return true;
    }

    // ─────────────────────────────────────────────────────────────────
    // KI-Tagger
    // ─────────────────────────────────────────────────────────────────

    /**
     * Lässt die KI alle ungetaggten Variablen klassifizieren.
     * Gibt die Vorschläge als JSON zurück (für die Review-Liste im Formular).
     */
    public function ClassifyWithAI(): string
    {
        $geminiID = $this->ReadPropertyInteger('GeminiIOID');
        if ($geminiID === 0 || !@IPS_InstanceExists($geminiID)) {
            $ids = @IPS_GetInstanceListByModuleID('{4C8B2A6D-9E3F-4A7B-8C5D-1F6E2A3B7C4D}');
            if (is_array($ids) && count($ids) > 0) {
                $geminiID = $ids[0];
            } else {
                return json_encode(['error' => 'Kein SmartGeminiIO gefunden. Bitte in den Einstellungen konfigurieren.']);
            }
        }

        // Ungetaggte Variablen sammeln
        $batches = [];
        $totalVars = 0;
        foreach (IPS_GetInstanceList() as $instanceID) {
            $instance = @IPS_GetInstance($instanceID);
            if ($instance === false || $instance['ModuleInfo']['ModuleType'] !== 3) {
                continue;
            }
            if ($instanceID === $this->InstanceID) {
                continue;
            }

            $children = IPS_GetChildrenIDs($instanceID);
            $vars = [];
            foreach ($children as $childID) {
                $obj = @IPS_GetObject($childID);
                if ($obj === false || $obj['ObjectType'] !== 2) {
                    continue;
                }
                if (str_starts_with($obj['ObjectIdent'], '_SI_')) {
                    continue;
                }
                if (str_starts_with($obj['ObjectInfo'], self::TAG_PREFIX)) {
                    continue;
                }

                $var = IPS_GetVariable($childID);
                $typeNames = ['Boolean', 'Integer', 'Float', 'String'];
                $vars[] = [
                    'v'  => $childID,                                         // varID (kurz)
                    'i'  => $obj['ObjectIdent'],                              // ident
                    'n'  => $obj['ObjectName'],                               // name
                    't'  => $typeNames[$var['VariableType']] ?? '?',           // type
                    'w'  => $this->getFormattedValue($childID),               // wert
                ];
                $totalVars++;
            }

            if (count($vars) > 0) {
                $batches[] = [
                    'id'   => $instanceID,
                    'name' => IPS_GetName($instanceID),
                    'mod'  => $instance['ModuleInfo']['ModuleName'],
                    'path' => IPS_GetLocation($instanceID),
                    'vars' => $vars,
                ];
            }
        }

        if (count($batches) === 0) {
            return json_encode(['message' => 'Keine ungetaggten Variablen gefunden.']);
        }

        // System-Prompt
        $systemPrompt = <<<'PROMPT'
Du klassifizierst Smart-Home-Variablen. Für jede Variable: Tag zuweisen, Raum aus Pfad ableiten.

Tags (exakt verwenden):
SI:battery, SI:reachability, SI:reachability:online=false (für UNREACH),
SI:alarm:smoke, SI:alarm:water, SI:alarm:co, SI:alarm:tamper, SI:alarm:generic,
SI:contact, SI:contact:closed=WERT (für String-Kontakte),
SI:sensor:temp, SI:sensor:humidity, SI:sensor:co2, SI:sensor:voc,
SI:sensor:pressure, SI:sensor:lux, SI:sensor:radon,
SI:sensor:power, SI:sensor:energy, SI:sensor:generic,
SI:actor:switch, SI:actor:dimmer, SI:actor:blind, SI:actor:thermostat,
SI:actor:lock, SI:actor:valve,
SI:warning, SI:info, SI:diagnostic, SKIP

Regeln:
- UNREACH/Nicht erreichbar: SI:reachability:online=false (invertiert!)
- DeviceAvailable/Online: SI:reachability (normal)
- String-Kontakte z.B. "Geschlossen": SI:contact:closed=Geschlossen
- Raum = vorletztes Pfadsegment (vor Gerätename)
- SKIP für irrelevante Variablen (interne Zähler, Config, Darstellung)
PROMPT;

        $schema = json_encode([
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'v' => ['type' => 'integer', 'description' => 'varID'],
                    'tag' => ['type' => 'string'],
                    'room' => ['type' => 'string'],
                    'r' => ['type' => 'string', 'description' => 'reason'],
                ],
                'required' => ['v', 'tag', 'room', 'r'],
            ],
        ]);

        // In Batches aufteilen (max 10 Instanzen pro API-Call)
        $batchSize = 10;
        $chunks = array_chunk($batches, $batchSize);
        $allSuggestions = [];
        $errors = [];
        $totalBatches = count($chunks);

        $this->SendDebug('KI-Tagger', "Start: $totalVars Variablen in " . count($batches) . " Instanzen, $totalBatches Batches", 0);
        $this->SetValue('ScanDuration', "KI-Tagging: 0/$totalBatches...");

        foreach ($chunks as $batchIdx => $chunk) {
            $batchNum = $batchIdx + 1;
            $this->SetValue('ScanDuration', "KI-Tagging: $batchNum/$totalBatches...");

            $prompt = json_encode($chunk, JSON_UNESCAPED_UNICODE);
            $this->SendDebug('KI-Tagger', "Batch $batchNum/$totalBatches: " . count($chunk) . " Instanzen, " . strlen($prompt) . " Bytes", 0);

            $result = GIO_Query($geminiID, $prompt, $systemPrompt, $schema, 0.1);

            if (empty($result)) {
                $lastError = '';
                $errorVarID = @IPS_GetObjectIDByIdent('LastError', $geminiID);
                if ($errorVarID !== false) {
                    $lastError = GetValue($errorVarID);
                }
                $errors[] = "Batch $batchNum: $lastError";
                $this->SendDebug('KI-Tagger', "Batch $batchNum FEHLER: $lastError", 0);
                continue;
            }

            $batchSuggestions = json_decode($result, true);
            if (!is_array($batchSuggestions)) {
                $errors[] = "Batch $batchNum: JSON ungültig";
                $this->SendDebug('KI-Tagger', "Batch $batchNum JSON-FEHLER: " . substr($result, 0, 300), 0);
                continue;
            }

            foreach ($batchSuggestions as $s) {
                $tag = $s['tag'] ?? '';
                if ($tag !== 'SKIP' && $tag !== '' && $tag !== 'null') {
                    $allSuggestions[] = [
                        'varID'  => $s['v'] ?? $s['varID'] ?? 0,
                        'tag'    => $tag,
                        'room'   => $s['room'] ?? '',
                        'reason' => $s['r'] ?? $s['reason'] ?? '',
                    ];
                }
            }

            $this->SendDebug('KI-Tagger', "Batch $batchNum OK: " . count($batchSuggestions) . " analysiert, " . count($allSuggestions) . " Tags bisher", 0);
        }

        $this->WriteAttributeString('AISuggestions', json_encode($allSuggestions));
        $this->SetValue('ScanDuration', count($allSuggestions) . " KI-Vorschläge werden übernommen...");
        $this->SendDebug('KI-Tagger', "Fertig: " . count($allSuggestions) . " Vorschläge, " . count($errors) . " Fehler – übernehme automatisch...", 0);

        // Direkt übernehmen
        $this->ApplyAllSuggestions();

        return json_encode([
            'suggestions' => count($allSuggestions),
            'batches'     => $totalBatches,
            'errors'      => $errors,
        ]);
    }
    /**
     * Übernimmt ALLE KI-Vorschläge aus dem Buffer auf einmal.
     */
    public function ApplyAllSuggestions(): string
    {
        $suggestions = json_decode($this->ReadAttributeString('AISuggestions') ?: '[]', true);
        if (empty($suggestions)) {
            return 'Keine KI-Vorschläge im Buffer. Zuerst KI-Tagging starten.';
        }

        $applied = 0;
        $skipped = 0;
        $total = count($suggestions);

        foreach ($suggestions as $sel) {
            $varID = $sel['varID'] ?? 0;
            $tag = $sel['tag'] ?? '';
            $room = $sel['room'] ?? '';

            if ($varID === 0 || $tag === '' || $tag === 'SKIP' || $tag === 'null') {
                $skipped++;
                continue;
            }

            if (!@IPS_VariableExists($varID)) {
                $skipped++;
                continue;
            }

            // Tag setzen
            if (!str_starts_with($tag, self::TAG_PREFIX)) {
                $tag = self::TAG_PREFIX . $tag;
            }
            IPS_SetInfo($varID, $tag);

            // Raum setzen
            if ($room !== '') {
                $parentID = IPS_GetParent($varID);
                if ($parentID > 0) {
                    $this->SetRoom($parentID, $room);
                }
            }

            $applied++;
        }

        $this->SendDebug('ApplyAll', "$applied Tags gesetzt, $skipped übersprungen (von $total)", 0);

        // Attribut leeren
        $this->WriteAttributeString('AISuggestions', '[]');

        // Inventar neu scannen
        $scanResult = $this->Scan();

        return "$applied Tags gesetzt, $skipped übersprungen. Scan-Ergebnis: $scanResult";
    }

    /**
     * Wendet ausgewählte KI-Vorschläge an (Tag + Raum setzen).
     */
    public function ApplyAISuggestions(string $selectionsJson): bool
    {
        $selections = json_decode($selectionsJson, true);
        if (!is_array($selections)) {
            return false;
        }

        $applied = 0;
        foreach ($selections as $sel) {
            $varID = $sel['varID'] ?? 0;
            $tag = $sel['tag'] ?? '';
            $room = $sel['room'] ?? '';

            if ($varID === 0 || $tag === '' || $tag === 'null') {
                continue;
            }

            // Tag setzen
            if (!str_starts_with($tag, self::TAG_PREFIX)) {
                $tag = self::TAG_PREFIX . $tag;
            }
            IPS_SetInfo($varID, $tag);

            // Raum setzen
            if ($room !== '') {
                $parentID = IPS_GetParent($varID);
                if ($parentID > 0) {
                    $this->SetRoom($parentID, $room);
                }
            }

            $applied++;
        }

        $this->SendDebug('ApplyAI', "$applied Vorschläge angewendet", 0);

        // Inventar neu scannen
        $this->Scan();

        return true;
    }

    // ─────────────────────────────────────────────────────────────────
    // Formular
    // ─────────────────────────────────────────────────────────────────

    public function GetConfigurationForm(): string
    {
        // Direkt aus dem Objektbaum lesen – kein Buffer, immer aktuell
        ['inventory' => $inventory, 'untagged' => $untagged] = $this->buildInventoryData();

        $threshold = $this->ReadPropertyInteger('BatteryThreshold');

        // Räume sammeln für Dropdown
        $allRooms = [];
        foreach ($inventory as $device) {
            if ($device['room'] !== '') {
                $allRooms[$device['room']] = true;
            }
        }
        foreach ($untagged as $device) {
            if ($device['room'] !== '') {
                $allRooms[$device['room']] = true;
            }
        }
        $allRooms = array_keys($allRooms);
        sort($allRooms);
        $roomOptions = [['caption' => '(Kein Raum)', 'value' => '']];
        foreach ($allRooms as $r) {
            $roomOptions[] = ['caption' => $r, 'value' => $r];
        }

        $tagOptions = [
            ['caption' => 'Batterie', 'value' => 'SI:battery'],
            ['caption' => 'Erreichbarkeit (Offline/Online)', 'value' => 'SI:reachability'],
            ['caption' => 'Kontakt (Generisch)', 'value' => 'SI:contact'],
            ['caption' => 'Kontakt (Fenster)', 'value' => 'SI:contact:window'],
            ['caption' => 'Kontakt (Tür)', 'value' => 'SI:contact:door'],
            ['caption' => 'Alarm (Generisch)', 'value' => 'SI:alarm'],
            ['caption' => 'Alarm (Wasser)', 'value' => 'SI:alarm:water'],
            ['caption' => 'Alarm (Rauch)', 'value' => 'SI:alarm:smoke'],
            ['caption' => 'Alarm (Hitze)', 'value' => 'SI:alarm:heat'],
            ['caption' => 'Alarm (Gas)', 'value' => 'SI:alarm:gas'],
            ['caption' => 'Alarm (Bewegung)', 'value' => 'SI:alarm:motion'],
            ['caption' => 'Warnung', 'value' => 'SI:warning'],
        ];

        $initialCatalogList = [];

        $catalogCategories = [];

        foreach ($inventory as $device) {
            foreach ($device['variables'] as $v) {
                $parsedTag = $this->parseTag($v['tag']);
                $tagBase = 'SI:' . $parsedTag['category'] . ($parsedTag['subcategory'] !== '' ? ':' . $parsedTag['subcategory'] : '');
                
                if (!in_array($tagBase, $catalogCategories)) {
                    $catalogCategories[] = $tagBase;
                }

                if ($v['disabled']) {
                    continue;
                }
                
                $normalStateStr = $parsedTag['normalState'] !== null ? $parsedTag['normalState']['value'] : '';

                $rowColor = '';
                if ($parsedTag['category'] === 'reachability' && $this->isProblematic($v)) $rowColor = '#FF4400';
                elseif ($parsedTag['category'] === 'battery' && $this->isBatteryLow($v, $threshold)) $rowColor = '#FF8800';
                elseif (in_array($parsedTag['category'], ['alarm', 'warning']) && $this->isProblematic($v)) $rowColor = '#FF0000';
                elseif ($parsedTag['category'] === 'contact' && $this->isContactOpen($v)) $rowColor = '#FFAA00';

                if ($rowColor !== '') {
                    $initialCatalogList[] = [
                        'instanceName' => $device['instanceName'],
                        'room'         => $device['room'],
                        'tagBase'      => $tagBase,
                        'normalState'  => $normalStateStr,
                        'disabled'     => $v['disabled'],
                        'value'        => $v['valueFormatted'],
                        'ObjectID'     => $v['varID'],
                        'instanceID'   => $device['instanceID'],
                        'rowColor'     => $rowColor,
                    ];
                }
            }
        }

        sort($catalogCategories);
        $catalogOptions = [];
        $catalogOptions[] = ['caption' => '--- Aktuelle Probleme & Alarme ---', 'value' => 'problems'];
        $catalogOptions[] = ['caption' => '--- Bitte waehlen ---', 'value' => 'none'];
        $catalogOptions[] = ['caption' => '--- Alle Typen ---', 'value' => 'all'];
        $catalogOptions[] = ['caption' => '--- Nicht getaggte (Ignoriert) ---', 'value' => 'untagged'];
        $catalogOptions[] = ['caption' => '--- Nur Deaktivierte ---', 'value' => 'disabled'];
        foreach ($catalogCategories as $cat) {
            $catalogOptions[] = ['caption' => $cat, 'value' => $cat];
        }

        $onEditScript = '
            $listData = ${$IPS_VALUE};
            $vid = $listData["ObjectID"];
            $iid = $listData["instanceID"];
            
            $objType = IPS_GetObject($vid)["ObjectType"];
            if ($objType === 2) {
                // Variable -> Tag aktualisieren
                $newTag = $listData["tagBase"];
                if (isset($listData["normalState"]) && $listData["normalState"] !== "") {
                    $newTag .= ":ok=" . $listData["normalState"];
                }
                if ($listData["disabled"]) {
                    $newTag .= ":disabled";
                }
                IPS_SetInfo($vid, $newTag);
            } elseif ($objType === 3) {
                // Instanz (Nicht getaggt) -> Ignore setzen
                $ignoreVarID = @IPS_GetObjectIDByIdent("_SI_Ignore", $iid);
                if ($listData["disabled"]) {
                    if ($ignoreVarID === false) {
                        $ignoreVarID = IPS_CreateVariable(0);
                        IPS_SetParent($ignoreVarID, $iid);
                        IPS_SetIdent($ignoreVarID, "_SI_Ignore");
                        IPS_SetName($ignoreVarID, "SmartInventory Ignoriert");
                        IPS_SetHidden($ignoreVarID, true);
                    }
                    SetValue($ignoreVarID, true);
                } elseif ($ignoreVarID !== false) {
                    SetValue($ignoreVarID, false);
                }
            }
            
            // Raum aktualisieren
            $newRoom = $listData["room"];
            $roomVarID = @IPS_GetObjectIDByIdent("_SI_Room", $iid);
            if ($roomVarID === false && $newRoom !== "") {
                $roomVarID = IPS_CreateVariable(3);
                IPS_SetParent($roomVarID, $iid);
                IPS_SetIdent($roomVarID, "_SI_Room");
                IPS_SetName($roomVarID, "SmartInventory Raum Override");
                IPS_SetHidden($roomVarID, true);
            }
            if ($roomVarID !== false) {
                SetValue($roomVarID, $newRoom);
            }
            
            SINV_Scan($id);
        ';

        $form = [
            'elements' => [
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Einstellungen',
                    'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'ScanInterval', 'caption' => 'Scan-Intervall', 'suffix' => 'Minuten (0 = nur manuell)', 'minimum' => 0, 'maximum' => 1440],
                        ['type' => 'NumberSpinner', 'name' => 'BatteryThreshold', 'caption' => 'Batterie-Schwellwert', 'suffix' => '%', 'minimum' => 5, 'maximum' => 50],
                        ['type' => 'NumberSpinner', 'name' => 'RoomPathSegment', 'caption' => 'Raum-Segment (von rechts im Pfad)', 'minimum' => 1, 'maximum' => 10],
                        ['type' => 'SelectInstance', 'name' => 'NotifierID', 'caption' => 'SmartNotifier'],
                        ['type' => 'SelectInstance', 'name' => 'GeminiIOID', 'caption' => 'SmartGeminiIO (für KI-Tagging)'],
                    ],
                ],
            ],
            'actions' => [
                [
                    'type' => 'RowLayout',
                    'items' => [
                        ['type' => 'Button', 'caption' => 'Jetzt scannen', 'onClick' => 'echo SINV_Scan($id);'],
                        ['type' => 'Button', 'caption' => 'KI-Tagging starten (Auto-Uebernahme)', 'onClick' => 'IPS_RunScriptText(\'SINV_ClassifyWithAI(\' . $id . \');\'); echo "KI-Tagging laeuft im Hintergrund.\nFortschritt: Scan-Dauer Variable beobachten.\nWenn fertig: \'Jetzt scannen\' druecken um Listen zu aktualisieren.";'],
                    ],
                ],
                [
                    'type' => 'Label',
                    'caption' => count($inventory) === 0
                        ? 'Inventar leer - bitte einmal "Jetzt scannen" druecken um die Listen zu fuellen.'
                        : 'Inventar: ' . count($inventory) . ' Geraete, ' . array_sum(array_map(fn($d) => count($d['variables']), $inventory)) . ' getaggte Variablen.',
                ],
                // Katalog / Pflege
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Katalog / Pflege (Alle Geräte)',
                    'expanded' => true,
                    'items' => [
                        [
                            'type' => 'Select',
                            'name' => 'CatalogFilter',
                            'caption' => 'Typ-Filter',
                            'options' => $catalogOptions,
                            'value' => 'problems',
                            'onChange' => 'SINV_UpdateCatalogList($id, $CatalogFilter);'
                        ],
                        [
                            'type' => 'List',
                            'name' => 'CatalogList',
                            'caption' => '',
                            'rowCount' => min(count($initialCatalogList) > 0 ? count($initialCatalogList) : 20, 20),
                            'sort' => ['column' => 'instanceName', 'direction' => 'ascending'],
                            'onEdit' => str_replace('$IPS_VALUE', '"CatalogList"', $onEditScript),
                            'columns' => [
                                ['name' => 'instanceName', 'caption' => 'Gerät', 'width' => '200px'],
                                ['name' => 'room', 'caption' => 'Raum', 'width' => '120px', 'edit' => ['type' => 'Select', 'options' => $roomOptions]],
                                ['name' => 'tagBase', 'caption' => 'Kategorie', 'width' => '150px', 'edit' => ['type' => 'Select', 'options' => $tagOptions]],
                                ['name' => 'normalState', 'caption' => 'OK bei (z.B. true/false)', 'width' => '150px', 'edit' => ['type' => 'ValidationTextBox']],
                                ['name' => 'disabled', 'caption' => 'Deaktiviert (bzw. Ignoriert)', 'width' => '100px', 'edit' => ['type' => 'CheckBox']],
                                ['name' => 'value', 'caption' => 'Aktueller Wert', 'width' => '150px'],
                                ['name' => 'ObjectID', 'caption' => 'ID', 'width' => '70px', 'edit' => ['type' => 'SelectObject']],
                            ],
                            'values' => $initialCatalogList,
                        ],
                    ],
                ],
        // UpdateCatalogList will handle filling it!
            ],
        ];

        return json_encode($form);
    }

    public function UpdateCatalogList(string $category): void
    {
        ['inventory' => $inventory, 'untagged' => $untagged] = $this->buildInventoryData();
        $threshold = $this->ReadPropertyInteger('BatteryThreshold');
        
        $list = [];

        if ($category === 'untagged') {
            foreach ($untagged as $u) {
                // Ignore-Status ermitteln
                $ignoreVarID = @IPS_GetObjectIDByIdent('_SI_Ignore', $u['instanceID']);
                $isDisabled = ($ignoreVarID !== false && GetValue($ignoreVarID));

                $list[] = [
                    'instanceName' => $u['instanceName'],
                    'room'         => $u['room'],
                    'tagBase'      => '',
                    'normalState'  => '',
                    'disabled'     => $isDisabled,
                    'value'        => $u['moduleName'] . ' (' . $u['varCount'] . ' Variablen)',
                    'ObjectID'     => $u['instanceID'],
                    'instanceID'   => $u['instanceID'],
                ];
            }
        } else {
            foreach ($inventory as $device) {
                foreach ($device['variables'] as $v) {
                    $parsed = $this->parseTag($v['tag']);
                    $tagBase = 'SI:' . $parsed['category'] . ($parsed['subcategory'] !== '' ? ':' . $parsed['subcategory'] : '');
                    
                    $rowColor = '';
                    if ($parsed['category'] === 'reachability' && $this->isProblematic($v)) $rowColor = '#FF4400';
                    elseif ($parsed['category'] === 'battery' && $this->isBatteryLow($v, $threshold)) $rowColor = '#FF8800';
                    elseif (in_array($parsed['category'], ['alarm', 'warning']) && $this->isProblematic($v)) $rowColor = '#FF0000';
                    elseif ($parsed['category'] === 'contact' && $this->isContactOpen($v)) $rowColor = '#FFAA00';

                    $match = false;
                    if ($category === 'problems') {
                        if ($rowColor !== '') $match = true;
                    } elseif ($category === 'disabled' && $parsed['disabled']) {
                        $match = true;
                    } elseif ($category !== 'disabled' && $tagBase === $category && !$parsed['disabled']) {
                        $match = true;
                    } elseif ($category === 'all') {
                        $match = true;
                    }
                    
                    if ($match) {
                        $normalStateStr = $parsed['normalState'] !== null ? $parsed['normalState']['value'] : '';
                        $entry = [
                            'instanceName' => $device['instanceName'],
                            'room'         => $device['room'],
                            'tagBase'      => $tagBase,
                            'normalState'  => $normalStateStr,
                            'disabled'     => $parsed['disabled'],
                            'value'        => $this->getFormattedValue($v['varID']),
                            'ObjectID'     => $v['varID'],
                            'instanceID'   => $device['instanceID'],
                        ];
                        if ($rowColor !== '') {
                            $entry['rowColor'] = $rowColor;
                        }
                        $list[] = $entry;
                    }
                }
            }
        }
        
        $this->UpdateFormField('CatalogList', 'values', json_encode($list));
    }

    // ─────────────────────────────────────────────────────────────────
    // Hilfsfunktionen
    // ─────────────────────────────────────────────────────────────────

    /**
     * Ermittelt den Raum einer Instanz (_SI_Room Override oder Objektbaum-Pfad).
     */
    private function resolveRoom(int $instanceID): string
    {
        // 1. Override prüfen
        $roomVarID = @IPS_GetObjectIDByIdent('_SI_Room', $instanceID);
        if ($roomVarID !== false && IPS_VariableExists($roomVarID)) {
            $userRoom = GetValue($roomVarID);
            if ($userRoom !== '') {
                return $userRoom;
            }
        }

        // 2. Aus Objektbaum-Pfad ableiten
        $path = IPS_GetLocation($instanceID);
        $segments = explode('\\', $path);
        $segmentIndex = $this->ReadPropertyInteger('RoomPathSegment');

        // Von rechts zählen: Segment 1 = Instanz selbst, 2 = Parent-Kategorie, etc.
        $idx = count($segments) - $segmentIndex;
        if ($idx >= 0 && $idx < count($segments)) {
            return $segments[$idx];
        }

        return '';
    }

    /**
     * Registriert alle MessageSink-Subscriptions für getaggte Variablen.
     */
    private function resubscribeAll(): void
    {
        $inventory = json_decode($this->GetBuffer('Inventory'), true) ?: [];

        foreach ($inventory as $device) {
            foreach ($device['variables'] as $v) {
                if ($v['disabled']) {
                    continue;
                }
                if (in_array($v['category'], self::MONITORED_CATEGORIES)) {
                    $this->RegisterMessage($v['varID'], IPS_VARIABLEMESSAGE);
                }
            }
        }
    }

    /**
     * Prüft ob eine Batterie-Variable als "niedrig" gilt.
     */
    private function isBatteryLow(array $v, int $threshold): bool
    {
        $value = $v['value'];
        if ($v['type'] === 0) { // Boolean
            return (bool) $value; // true = leer
        }
        if (is_numeric($value)) {
            return ((float) $value) > 0 && ((float) $value) < $threshold;
        }
        return false;
    }

    /**
     * Prüft ob eine Variable in einem "problematischen" Zustand ist.
     */
    private function isProblematic(array $v): bool
    {
        $value = $v['value'];
        $parsed = $this->parseTag($v['tag']);

        return $this->isValueTriggered($value, $parsed);
    }

    /**
     * Evaluiert ob ein Erreichbarkeits-Wert als "offline" gilt.
     */
    private function evaluateReachability(mixed $value, array $parsed): bool
    {
        return $this->isValueTriggered($value, $parsed);
    }

    /**
     * Prüft ob ein Wert als "ausgelöst/problematisch" gilt, unter Berücksichtigung des NormalState.
     */
    private function isValueTriggered(mixed $value, array $parsed): bool
    {
        $normalState = $parsed['normalState'];

        if ($normalState !== null) {
            // Expliziter NormalState im Tag
            $normalVal = $normalState['value'];

            // String-Vergleich
            if (is_string($value)) {
                return strcasecmp((string) $value, $normalVal) !== 0;
            }

            // Boolean: "true"/"false" parsen
            if (is_bool($value)) {
                $normalBool = filter_var($normalVal, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($normalBool !== null) {
                    return $value !== $normalBool;
                }
            }

            // Numerisch
            if (is_numeric($value) && is_numeric($normalVal)) {
                return (float) $value !== (float) $normalVal;
            }

            return (string) $value !== $normalVal;
        }

        // Standard-Logik (kein NormalState): true/non-zero = ausgelöst
        if (is_bool($value)) {
            return $value === true;
        }
        if (is_numeric($value)) {
            return (float) $value > 0;
        }
        if (is_string($value)) {
            $lower = strtolower($value);
            // Typische "problematische" String-Werte
            return in_array($lower, ['offline', 'nicht erreichbar', 'offen', 'open', 'alarm', 'fehler', 'error', 'true', 'ja']);
        }

        return false;
    }

    /**
     * Prüft ob ein Kontakt als "offen" gilt.
     */
    private function isContactOpen(array $v): bool
    {
        return $this->isProblematic($v);
    }

    /**
     * Benachrichtigungs-State-Tracking: Nur einmal pro Zustandswechsel benachrichtigen.
     */
    private function shouldNotify(int $varID, bool $isProblematic): bool
    {
        $notified = json_decode($this->GetBuffer('NotifiedVars') ?: '[]', true);
        $key = (string) $varID;

        if ($isProblematic && !in_array($key, $notified)) {
            $notified[] = $key;
            $this->SetBuffer('NotifiedVars', json_encode($notified));
            return true;
        }

        if (!$isProblematic && in_array($key, $notified)) {
            $notified = array_values(array_diff($notified, [$key]));
            $this->SetBuffer('NotifiedVars', json_encode($notified));
        }

        return false;
    }

    /**
     * Sendet eine Benachrichtigung an den SmartNotifier.
     */
    private function notifyProblem(string $title, string $message, int $priority): void
    {
        $notifierID = $this->ReadPropertyInteger('NotifierID');
        if ($notifierID === 0 || !@IPS_InstanceExists($notifierID)) {
            $this->SendDebug('Notify', "Kein Notifier: $title - $message", 0);
            return;
        }

        if (method_exists($this, 'SLogWarning')) {
            $this->SLogWarning($title, $message);
        }

        // SmartNotifier API aufrufen
        if (function_exists('NOTIFY_SendMessage')) {
            @NOTIFY_SendMessage($notifierID, $title, $message, $priority);
        }
    }

    /**
     * Zähler neu berechnen (nach Wertänderung).
     */
    private function recalculateCounters(): void
    {
        $inventory = json_decode($this->GetBuffer('Inventory'), true) ?: [];
        $threshold = $this->ReadPropertyInteger('BatteryThreshold');
        $offline = 0;
        $lowBat = 0;
        $alarms = 0;
        $contacts = 0;

        foreach ($inventory as $device) {
            foreach ($device['variables'] as &$v) {
                if ($v['disabled']) {
                    continue;
                }
                // Live-Wert aktualisieren
                if (@IPS_VariableExists($v['varID'])) {
                    $v['value'] = GetValue($v['varID']);
                }
                match ($v['category']) {
                    'reachability' => $offline += $this->isProblematic($v) ? 1 : 0,
                    'battery'      => $lowBat += $this->isBatteryLow($v, $threshold) ? 1 : 0,
                    'alarm', 'warning' => $alarms += $this->isProblematic($v) ? 1 : 0,
                    'contact'      => $contacts += $this->isContactOpen($v) ? 1 : 0,
                    default        => null,
                };
            }
        }

        // Buffer aktualisieren
        $this->SetBuffer('Inventory', json_encode($inventory));

        if ($this->GetValue('OfflineCount') !== $offline) {
            $this->SetValue('OfflineCount', $offline);
        }
        if ($this->GetValue('LowBatteryCount') !== $lowBat) {
            $this->SetValue('LowBatteryCount', $lowBat);
        }
        if ($this->GetValue('ActiveAlarmCount') !== $alarms) {
            $this->SetValue('ActiveAlarmCount', $alarms);
        }
        if ($this->GetValue('OpenContactCount') !== $contacts) {
            $this->SetValue('OpenContactCount', $contacts);
        }
    }

    /**
     * Gibt den formatierten Wert einer Variable zurück.
     */
    private function getFormattedValue(int $varID): string
    {
        $value = GetValue($varID);
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    }

    /**
     * Gibt den Instanz-Namen der Eltern-Instanz einer Variable zurück.
     */
    private function getParentInstanceName(int $varID): string
    {
        $parentID = IPS_GetParent($varID);
        if ($parentID > 0 && @IPS_InstanceExists($parentID)) {
            return IPS_GetName($parentID);
        }
        return 'Unbekannt';
    }

    /**
     * Gibt den Raum der Eltern-Instanz einer Variable zurück.
     */
    private function getVarRoom(int $varID): string
    {
        $parentID = IPS_GetParent($varID);
        if ($parentID > 0) {
            return $this->resolveRoom($parentID);
        }
        return '';
    }
}
