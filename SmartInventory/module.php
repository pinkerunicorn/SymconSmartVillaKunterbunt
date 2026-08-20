<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';

/**
 * SmartInventory — Zentraler Geräte-Katalog für Smart Home.
 *
 * Inventarisiert alle Variablen im Symcon-Objektbaum, die einen SI:-Tag
 * im Beschreibungsfeld tragen. Stellt eine saubere API für andere Module
 * (SmartNotifier, SecurityKachel, SmartController, SmartBriefing) bereit.
 *
 * Tags werden einmalig gesetzt (manuell oder per KI-Tagger) und danach
 * vom Scan nur noch gelesen – nie überschrieben.
 *
 * Dieses Modul macht KEIN Monitoring und KEIN Alerting.
 * Das übernimmt der SmartNotifier.
 */
class SmartInventory extends IPSModuleStrict
{
    use SmartLog_Trait;

    // Tag-Prefix
    private const TAG_PREFIX = 'SI:';

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
        $this->RegisterPropertyInteger('NotifierID', 0);              // Legacy – wird nicht mehr verwendet
        $this->RegisterPropertyInteger('GeminiIOID', 0);              // SmartGeminiIO Instanz

        // Datenbank
        $this->RegisterAttributeString('TagDatabase', '{}');

        // Persistenter Speicher für KI-Vorschläge (überlebt Modul-Updates)
        
        $this->RegisterAttributeString('AISuggestions', '[]');

        // Timer für periodischen Scan
        $this->RegisterTimer('ScanTimer', 0, 'SINV_Scan(' . $this->InstanceID . ');');

        // Status-Variablen (reine Statistik)
        $this->RegisterVariableInteger('DeviceCount', 'Geräte gesamt', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'microchip'
        ], 1);
        $this->RegisterVariableInteger('TaggedVarCount', 'Getaggte Variablen', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'tags'
        ], 2);
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

        // Alte Counter-Variablen entfernen (Migration)
        $this->UnregisterVariable('OfflineCount');
        $this->UnregisterVariable('LowBatteryCount');
        $this->UnregisterVariable('ActiveAlarmCount');
        $this->UnregisterVariable('OpenContactCount');

        $this->SetStatus(102);
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

        // Alle aktiven Variablen cachen, da externe Module (wie SmartRoomLighting) 
        // auch ueber SINV_GetByCategory('actor:switch') etc. zugreifen muessen.
        // Kurzschluessel (v/c/s/d/n/r/t/u) sparen weitere ~40% Bytes.
        $leanInventory = [];
        foreach ($inventory as $device) {
            $leanVars = [];
            foreach ($device['variables'] as $v) {
                if ($v['disabled']) {
                    continue;
                }
                $leanVars[] = [
                    'v' => $v['varID'],                // varID
                    'c' => $v['category'],             // category
                    's' => $v['subcategory'],          // subcategory
                    'n' => $v['normalState'],          // normalState
                    't' => $v['type'],                 // type
                    'u' => $v['lastUpdatedTS'],        // lastUpdatedTS
                ];
            }
            if (count($leanVars) === 0) {
                continue;
            }
            $leanInventory[] = [
                'i' => $device['instanceID'],      // instanceID
                'n' => $device['instanceName'],    // instanceName
                'r' => $device['room'],            // room
                'h' => $device['health'],          // health status
                'v' => $leanVars,                  // variables
            ];
        }
        $this->SetBuffer('Inventory', json_encode($leanInventory));
        $this->SetBuffer('UntaggedInstances', json_encode($untaggedInstances));

        // Status-Variablen aktualisieren
        if ($this->GetValue('DeviceCount') !== $deviceCount) {
            $this->SetValue('DeviceCount', $deviceCount);
        }
        if ($this->GetValue('TaggedVarCount') !== $taggedVarCount) {
            $this->SetValue('TaggedVarCount', $taggedVarCount);
        }

        $duration = round((microtime(true) - $startTime) * 1000);
        $this->SetValue('LastScan', date('d.m.Y H:i:s'));
        $this->SetValue('ScanDuration', $duration . ' ms');

        $this->SendDebug('Scan', "Geraete: $deviceCount, Variablen: $taggedVarCount, Ungetaggt: " . count($untaggedInstances), 0);

        // Notifier asynchron benachrichtigen, Subscriptions zu aktualisieren
        $notifierID = $this->ReadPropertyInteger('NotifierID');
        if ($notifierID > 0 && @IPS_InstanceExists($notifierID)) {
            IPS_RunScriptText('NOTIFY_RefreshSubscriptions(' . $notifierID . ');');
        }

        return json_encode([
            'devices'   => $deviceCount,
            'variables' => $taggedVarCount,
            'untagged'  => count($untaggedInstances),
            'duration'  => $duration . ' ms',
        ]);
    }


    /**
     * Baut die Inventar-Daten direkt aus dem Objektbaum auf.
     *
     * @return array{inventory: array, untagged: array}
     */
        private function buildInventoryData(): array
    {
        $inventory         = [];
        $untaggedInstances = [];
        $db = json_decode($this->ReadAttributeString('TagDatabase') ?: '{}', true);

        foreach (IPS_GetInstanceList() as $instanceID) {
            $instance = @IPS_GetInstance($instanceID);
            if ($instance === false) continue;
            if ($instance['ModuleInfo']['ModuleType'] !== 3) continue;
            if ($instanceID === $this->InstanceID) continue;

            $instanceName = IPS_GetName($instanceID);
            $moduleName   = $instance['ModuleInfo']['ModuleName'];
            $room         = $this->resolveRoom($instanceID);
            $children     = IPS_GetChildrenIDs($instanceID);
            
            $instanceVars = [];
            $hasTaggedVar = false;

            foreach ($children as $childID) {
                $obj = @IPS_GetObject($childID);
                if ($obj === false || $obj['ObjectType'] !== 2) continue;
                if (str_starts_with($obj['ObjectIdent'], '_SI_')) continue;

                $tagData = $db[$childID] ?? [];
                $info = $tagData['tag'] ?? '';
                if (str_starts_with($info, self::TAG_PREFIX)) {
                    $hasTaggedVar = true;
                }

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
                    'value'          => @GetValue($childID),
                    'valueFormatted' => $value,
                    'lastUpdate'     => date('Y-m-d H:i:s', $var['VariableUpdated']),
                    'lastUpdatedTS'  => $var['VariableUpdated'],
                ];
            }

            if (count($instanceVars) === 0) continue;

            $health = $this->calculateDeviceHealth($instanceVars);
            $inventory[] = [
                'instanceID'   => $instanceID,
                'instanceName' => $instanceName,
                'room'         => $room,
                'health'       => $health['status'],
                'healthDetail' => $health['detail'],
                'variables'    => $instanceVars,
            ];
            
            if (!$hasTaggedVar) {
                $untaggedInstances[] = [
                    'instanceID'   => $instanceID,
                    'instanceName' => $instanceName,
                    'moduleName'   => $moduleName,
                    'room'         => $room,
                    'varCount'     => count($instanceVars),
                ];
            }
        }
        return ['inventory' => $inventory, 'untagged' => $untaggedInstances];
    }

    /**
     * Berechnet den Gesundheitsstatus eines Geraets anhand seiner getaggten Variablen.
     *
     * Prioritaet:
     *   1. alarm        → aktiver Sicherheitsalarm (Rauch, Wasser, etc.)
     *   2. battery_dead → Batterie unter Schwellwert UND offline (Root-Cause)
     *   3. offline      → nicht erreichbar (Batterie OK oder unbekannt)
     *   4. battery_low  → Batterie unter Schwellwert, aber noch online
     *   5. stale        → kein Update seit 24h ohne erkennbare Ursache
     *   6. healthy      → alles gut
     *
     * @param array $vars Variablen-Array aus buildInventoryData
     * @return array{status: string, detail: string}
     */
    private function calculateDeviceHealth(array $vars): array
    {
        $threshold   = $this->ReadPropertyInteger('BatteryThreshold');
        $staleLimit  = 86400; // 24 Stunden
        $now         = time();

        $hasBatLow   = false;
        $batValue    = null;
        $isOffline   = false;
        $isStale     = false;
        $hasAlarm    = false;
        $alarmName   = '';
        $latestUpdate = 0;

        foreach ($vars as $v) {
            if ($v['disabled'] ?? false) {
                continue;
            }

            // Juengstes Update tracken
            $ts = $v['lastUpdatedTS'] ?? 0;
            if ($ts > $latestUpdate) {
                $latestUpdate = $ts;
            }

            $cat = $v['category'];
            $val = $v['value'];

            if ($cat === 'battery') {
                if (is_bool($val)) {
                    // Boolean: true = Batterie leer (HmIP Konvention)
                    if ($val === true) {
                        $hasBatLow = true;
                        $batValue  = 'leer';
                    }
                } elseif (is_numeric($val)) {
                    $batValue = (int)$val . '%';
                    if ((int)$val < $threshold) {
                        $hasBatLow = true;
                    }
                }
            } elseif ($cat === 'reachability') {
                $normal = $v['normalState'] ?? null;
                if ($normal !== null) {
                    $normalKey = $normal['key'] ?? 'ok';
                    $normalVal = $normal['value'] ?? null;
                    if ($normalVal !== null) {
                        $isOffline = ($this->castForComparison($val) != $this->castForComparison($normalVal));
                    }
                } else {
                    // Default: false = offline, true = online
                    $isOffline = !$val;
                }
            } elseif ($cat === 'alarm' || $cat === 'warning') {
                $normal = $v['normalState'] ?? null;
                $isTriggered = false;
                if ($normal !== null) {
                    $normalVal = $normal['value'] ?? null;
                    if ($normalVal !== null) {
                        $isTriggered = ($this->castForComparison($val) != $this->castForComparison($normalVal));
                    }
                } else {
                    $isTriggered = (bool)$val;
                }
                if ($isTriggered) {
                    $hasAlarm = true;
                    $alarmName = $v['name'] ?? ($v['subcategory'] ?? $cat);
                }
            }
        }

        // Stale: juengstes Update aelter als 24h
        if ($latestUpdate > 0 && ($now - $latestUpdate) > $staleLimit) {
            $isStale = true;
        }

        // Root-Cause-Analyse
        if ($hasAlarm) {
            return ['status' => 'alarm', 'detail' => "Alarm: $alarmName"];
        }
        if ($hasBatLow && $isOffline) {
            $detail = "Batterie $batValue, offline";
            if ($isStale) {
                $staleDays = (int)(($now - $latestUpdate) / 86400);
                $detail .= ", stale {$staleDays}d";
            }
            return ['status' => 'battery_dead', 'detail' => $detail];
        }
        if ($isOffline) {
            return ['status' => 'offline', 'detail' => 'Nicht erreichbar'];
        }
        if ($hasBatLow) {
            return ['status' => 'battery_low', 'detail' => "Batterie $batValue"];
        }
        if ($isStale) {
            $staleDays = max(1, (int)(($now - $latestUpdate) / 86400));
            return ['status' => 'stale', 'detail' => "Kein Update seit {$staleDays}d"];
        }

        return ['status' => 'healthy', 'detail' => ''];
    }

    /**
     * Hilfsfunktion: Wert fuer losen Vergleich casten (String "true"/"false" -> bool etc.)
     */
    private function castForComparison(mixed $val): mixed
    {
        if (is_string($val)) {
            $lower = strtolower($val);
            if ($lower === 'true') return true;
            if ($lower === 'false') return false;
            if (is_numeric($val)) return (float)$val;
        }
        return $val;
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
     *   SI:reachability:ok=false
     *   SI:contact:ok=CLOSED:disabled
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
    // API (öffentlich)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Gibt das komplette gecachte Inventar als JSON zurück.
     */
    public function GetInventory(): string
    {
        $json = $this->GetBuffer('Inventory');
        if ($json !== '' && $json !== false) {
            return $json;
        }
        // Buffer leer (nach Neustart): Scan asynchron anstoessen, diesmal leer zurueckgeben.
        // Synchroner Scan hier wuerde den PHP-Engine blockieren (396+ Instanzen).
        IPS_RunScriptText('SINV_Scan(' . $this->InstanceID . ');');
        return '[]';
    }

    /**
     * Gibt alle existierenden Kategorien zurück.
     */
    public function GetCategories(): string
    {
        $buffer = (string)$this->GetBuffer('Inventory');
        $inventory = json_decode($buffer === '' ? '[]' : $buffer, true) ?: [];
        $categories = [];

        foreach ($inventory as $device) {
            foreach ($device['variables'] as $v) {
                $cat = $v['category'];
                if ($v['subcategory'] !== '') {
                    $cat .= ':' . $v['subcategory'];
                }
                if (!in_array($cat, $categories)) {
                    $categories[] = $cat;
                }
            }
        }

        sort($categories);
        return json_encode($categories);
    }

    /**
     * Gibt alle Variablen einer Kategorie zurück.
     * z.B. SINV_GetByCategory($id, 'battery') oder SINV_GetByCategory($id, 'alarm:smoke')
     */
    public function GetByCategory(string $category): string
    {
        $buffer = (string)$this->GetBuffer('Inventory');
        if ($buffer === '') {
            $this->Scan();
            $buffer = (string)$this->GetBuffer('Inventory');
        }
        $inventory = json_decode($buffer === '' ? '[]' : $buffer, true) ?: [];
        $results = [];

        foreach ($inventory as $device) {
            $vars = $device['v'] ?? ($device['variables'] ?? []);
            foreach ($vars as $v) {
                $cat = $v['c'] ?? ($v['category'] ?? '');
                $sub = $v['s'] ?? ($v['subcategory'] ?? '');
                
                $matchTag = $cat;
                if ($sub !== '') {
                    $matchTag .= ':' . $sub;
                }

                if ($category === $cat || $category === $matchTag) {
                    $varID = $v['v'] ?? ($v['varID'] ?? 0);
                    if ($varID === 0 || !@IPS_VariableExists($varID)) continue;
                    
                    $results[] = [
                        'instanceID'    => $device['i'] ?? ($device['instanceID'] ?? 0),
                        'instanceName'  => $device['n'] ?? ($device['instanceName'] ?? ''),
                        'room'          => $device['r'] ?? ($device['room'] ?? ''),
                        'varID'         => $varID,
                        'varName'       => @IPS_GetName($varID) ?: 'Unbekannt',
                        'tag'           => 'SI:' . $matchTag,
                        'category'      => $cat,
                        'subcategory'   => $sub,
                        'type'          => $v['t'] ?? ($v['type'] ?? 0),
                        'value'         => GetValue($varID),
                        'valueFormatted' => $this->getFormattedValue($varID),
                        'normalState'   => $v['n'] ?? ($v['normalState'] ?? null),
                        'lastUpdatedTS' => $v['u'] ?? ($v['lastUpdatedTS'] ?? 0),
                    ];
                }
            }
        }

        return json_encode($results);
    }

    /**
     * Sucht Variablen nach Tag und optional Raum.
     */
    public function Query(string $tag, string $room = ''): string
    {
        $buffer = (string)$this->GetBuffer('Inventory');
        $inventory = json_decode($buffer === '' ? '[]' : $buffer, true) ?: [];
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
        $buffer = (string)$this->GetBuffer('Inventory');
        $inventory = json_decode($buffer === '' ? '[]' : $buffer, true) ?: [];
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
        $buffer = (string)$this->GetBuffer('Inventory');
        $inventory = json_decode($buffer === '' ? '[]' : $buffer, true) ?: [];
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
        $buffer = (string)$this->GetBuffer('Inventory');
        $inventory = json_decode($buffer === '' ? '[]' : $buffer, true) ?: [];
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
        $buffer = (string)$this->GetBuffer('Inventory');
        $inventory = json_decode($buffer === '' ? '[]' : $buffer, true) ?: [];
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
        $buffer = (string)$this->GetBuffer('Inventory');
        $inventory = json_decode($buffer === '' ? '[]' : $buffer, true) ?: [];
        $results = [];
        
        $isAway = false;
        $shcIds = @IPS_GetInstanceListByModuleID('{460D7C60-0766-4534-BFD8-5920737B1845}');
        if (count($shcIds) > 0) {
            $pm = @IPS_GetObjectIDByIdent('PresenceMode', $shcIds[0]);
            if ($pm && @IPS_VariableExists($pm)) {
                $val = GetValue($pm);
                $isAway = ($val == 1 || $val == 2);
            }
        }

        foreach ($inventory as $device) {
            foreach ($device['variables'] as $v) {
                $cat = $v['category'];
                if ((in_array($cat, ['alarm', 'warning']) || ($isAway && $cat === 'motion')) && !$v['disabled']) {
                    $vid = (int)$v['varID'];
                    if ($vid > 0 && @IPS_VariableExists($vid)) {
                        $liveVal = GetValue($vid);
                        $parsed = $this->parseTag($v['tag']);
                        if ($this->isValueTriggered($liveVal, $parsed)) {
                            $results[] = [
                                'instanceID'   => $device['instanceID'],
                                'instanceName' => $device['instanceName'],
                                'room'         => $device['room'],
                                'varID'        => $vid,
                                'varName'      => $v['name'],
                                'tag'          => $v['tag'],
                                'value'        => $liveVal,
                            ];
                        }
                    }
                }
            }
        }

        return json_encode($results);
    }

    /**
     * Gibt alle Geraete mit Problemen zurueck (health != healthy).
     * Sortiert nach Schwere: alarm > battery_dead > offline > battery_low > stale.
     * Jedes Geraet erscheint nur einmal (dedupliziert).
     */
    public function GetProblems(): string
    {
        $buffer = (string)$this->GetBuffer('Inventory');
        $inventory = json_decode($buffer === '' ? '[]' : $buffer, true) ?: [];
        $severity  = ['alarm' => 5, 'battery_dead' => 4, 'offline' => 3, 'battery_low' => 2, 'stale' => 1];
        $results   = [];

        foreach ($inventory as $device) {
            $h = $device['h'] ?? ($device['health'] ?? 'healthy');
            if ($h === 'healthy') {
                continue;
            }
            $results[] = [
                'instanceID'   => $device['i'] ?? $device['instanceID'],
                'instanceName' => $device['n'] ?? $device['instanceName'],
                'room'         => $device['r'] ?? $device['room'],
                'health'       => $h,
                'detail'       => $device['healthDetail'] ?? $h,
                'severity'     => $severity[$h] ?? 0,
            ];
        }

        // Nach Schwere sortieren (schwerste zuerst)
        usort($results, fn($a, $b) => $b['severity'] <=> $a['severity']);

        return json_encode($results);
    }

    /**
     * Gibt den Health-Status eines einzelnen Geraets zurueck.
     */
    public function GetDeviceHealth(int $instanceID): string
    {
        $buffer = (string)$this->GetBuffer('Inventory');
        $inventory = json_decode($buffer === '' ? '[]' : $buffer, true) ?: [];
        foreach ($inventory as $device) {
            $id = $device['i'] ?? ($device['instanceID'] ?? 0);
            if ($id === $instanceID) {
                return json_encode([
                    'health' => $device['h'] ?? ($device['health'] ?? 'healthy'),
                    'detail' => $device['healthDetail'] ?? '',
                ]);
            }
        }
        return json_encode(['health' => 'unknown', 'detail' => 'Geraet nicht im Inventar']);
    }

    /**
     * Gibt eine Zusammenfassung pro Raum zurueck.
     */
    public function GetRoomSummary(): string
    {
        $buffer = (string)$this->GetBuffer('Inventory');
        $inventory = json_decode($buffer === '' ? '[]' : $buffer, true) ?: [];
        $rooms     = [];

        foreach ($inventory as $device) {
            $room = $device['r'] ?? ($device['room'] ?? 'Unbekannt');
            $h    = $device['h'] ?? ($device['health'] ?? 'healthy');

            if (!isset($rooms[$room])) {
                $rooms[$room] = ['room' => $room, 'total' => 0, 'problems' => 0, 'worst' => 'healthy'];
            }
            $rooms[$room]['total']++;
            if ($h !== 'healthy') {
                $rooms[$room]['problems']++;
                $sev = ['alarm' => 5, 'battery_dead' => 4, 'offline' => 3, 'battery_low' => 2, 'stale' => 1, 'healthy' => 0];
                if (($sev[$h] ?? 0) > ($sev[$rooms[$room]['worst']] ?? 0)) {
                    $rooms[$room]['worst'] = $h;
                }
            }
        }

        $result = array_values($rooms);
        usort($result, fn($a, $b) => $b['problems'] <=> $a['problems']);
        return json_encode($result);
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
        public function SetRoom(int $id, string $room): bool
    {
        if (!@IPS_ObjectExists($id)) return false;
        
        $db = json_decode($this->ReadAttributeString('TagDatabase') ?: '{}', true);
        if ($room === '') {
            if (isset($db[$id])) {
                unset($db[$id]['room']);
                if (empty($db[$id])) unset($db[$id]);
            }
        } else {
            if (!isset($db[$id])) $db[$id] = [];
            $db[$id]['room'] = $room;
        }
        $this->WriteAttributeString('TagDatabase', json_encode($db));
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
        
        $db = json_decode($this->ReadAttributeString('TagDatabase') ?: '{}', true);
        if ($tag === '') {
            if (isset($db[$varID])) {
                unset($db[$varID]['tag']);
                if (empty($db[$varID])) unset($db[$varID]);
            }
        } else {
            if (!isset($db[$varID])) $db[$varID] = [];
            $db[$varID]['tag'] = $tag;
        }
        $this->WriteAttributeString('TagDatabase', json_encode($db));
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
SI:battery, SI:reachability, SI:reachability:ok=false (für UNREACH),
SI:alarm:smoke, SI:alarm:water, SI:alarm:co, SI:alarm:tamper, SI:alarm:generic,
SI:contact, SI:contact:ok=WERT (für String-Kontakte),
SI:sensor:temp, SI:sensor:humidity, SI:sensor:co2, SI:sensor:voc,
SI:sensor:pressure, SI:sensor:lux, SI:sensor:radon,
SI:sensor:power, SI:sensor:energy, SI:sensor:generic,
SI:actor:switch, SI:actor:dimmer, SI:actor:blind, SI:actor:thermostat,
SI:actor:lock, SI:actor:valve,
SI:warning, SI:info, SI:diagnostic, SKIP

Regeln:
- UNREACH/Nicht erreichbar: SI:reachability:ok=false (invertiert!)
- DeviceAvailable/Online: SI:reachability (normal)
- String-Kontakte z.B. "Geschlossen": SI:contact:ok=Geschlossen
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

            // Automatisch deaktivieren, wenn das Objekt im Baum versteckt ist ("Objekt nicht anzeigen")
            $objInfo = @IPS_GetObject($varID);
            if ($objInfo !== false && isset($objInfo['ObjectIsHidden']) && $objInfo['ObjectIsHidden']) {
                if (!str_ends_with($tag, ':disabled')) {
                    $tag .= ':disabled';
                }
            }

            $this->SetTag($varID, $tag);

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
            $this->SetTag($varID, $tag);

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
        ['inventory' => $inventory, 'untagged' => $untagged] = $this->buildInventoryData();

        $catCounts = [];
        foreach ($inventory as $device) {
            foreach ($device['variables'] as $v) {
                if ($v['disabled'] ?? false) continue;
                $parsed = $this->parseTag($v['tag']);
                if ($parsed['category'] !== '') {
                    $cat = 'SI:' . $parsed['category'];
                    if ($parsed['subcategory'] !== '') {
                        $cat .= ':' . $parsed['subcategory'];
                    }
                    $catCounts[$cat] = ($catCounts[$cat] ?? 0) + 1;
                }
            }
        }
        
        $catListValues = [];
        // We need tagOptions manually here if we do it early
        $earlyTagOpts = [
              ['caption' => 'Batterie', 'value' => 'SI:battery'],
              ['caption' => 'Erreichbarkeit (Offline/Online)', 'value' => 'SI:reachability'],
              ['caption' => 'Kontakt (Generisch)', 'value' => 'SI:contact'],
              ['caption' => 'Kontakt (Fenster)', 'value' => 'SI:contact:window'],
              ['caption' => 'Kontakt (Tuer)', 'value' => 'SI:contact:door'],
              ['caption' => 'Bewegungsmelder', 'value' => 'SI:motion'],
              ['caption' => 'Alarm (Generisch)', 'value' => 'SI:alarm'],
              ['caption' => 'Alarm (Wasser)', 'value' => 'SI:alarm:water'],
              ['caption' => 'Alarm (Rauch)', 'value' => 'SI:alarm:smoke'],
              ['caption' => 'Alarm (Hitze)', 'value' => 'SI:alarm:heat'],
              ['caption' => 'Alarm (Gas)', 'value' => 'SI:alarm:gas'],
              ['caption' => 'Alarm (Bewegung)', 'value' => 'SI:alarm:motion'],
              ['caption' => 'Warnung', 'value' => 'SI:warning'],
              ['caption' => 'Sensor (Taster)', 'value' => 'SI:sensor:button'],
            ['caption' => 'Sensor (Temperatur)', 'value' => 'SI:sensor:temp'],
              ['caption' => 'Sensor (Luftfeuchte)', 'value' => 'SI:sensor:humidity'],
              ['caption' => 'Sensor (Helligkeit)', 'value' => 'SI:sensor:lux'],
              ['caption' => 'Sensor (Leistung W)', 'value' => 'SI:sensor:power'],
              ['caption' => 'Sensor (Energie kWh)', 'value' => 'SI:sensor:energy'],
              ['caption' => 'Aktor (Schalter)', 'value' => 'SI:actor:switch'],
              ['caption' => 'Aktor (Dimmer)', 'value' => 'SI:actor:dimmer'],
              ['caption' => 'Aktor (Rollladen)', 'value' => 'SI:actor:blind'],
              ['caption' => 'Aktor (Thermostat)', 'value' => 'SI:actor:thermostat'],
              ['caption' => 'Aktor (Schloss)', 'value' => 'SI:actor:lock'],
              ['caption' => 'Diagnostik', 'value' => 'SI:diagnostic']
        ];
        foreach ($catCounts as $c => $count) {
            $caption = $c;
            foreach ($earlyTagOpts as $opt) {
                if ($opt['value'] === $c) {
                    $caption = $opt['caption'];
                    break;
                }
            }
            $catListValues[] = [
                'tag' => $c,
                'caption' => $caption,
                'count' => (string)$count
            ];
        }
        usort($catListValues, function($a, $b) { return strcmp($a['tag'], $b['tag']); });
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
            ['caption' => 'Bewegungsmelder', 'value' => 'SI:motion'],
            ['caption' => 'Alarm (Generisch)', 'value' => 'SI:alarm'],
            ['caption' => 'Alarm (Wasser)', 'value' => 'SI:alarm:water'],
            ['caption' => 'Alarm (Rauch)', 'value' => 'SI:alarm:smoke'],
            ['caption' => 'Alarm (Hitze)', 'value' => 'SI:alarm:heat'],
            ['caption' => 'Alarm (Gas)', 'value' => 'SI:alarm:gas'],
            ['caption' => 'Alarm (Bewegung)', 'value' => 'SI:alarm:motion'],
            ['caption' => 'Warnung', 'value' => 'SI:warning'],
            ['caption' => 'Sensor (Taster)', 'value' => 'SI:sensor:button'],
            ['caption' => 'Sensor (Temperatur)', 'value' => 'SI:sensor:temp'],
            ['caption' => 'Sensor (Luftfeuchte)', 'value' => 'SI:sensor:humidity'],
            ['caption' => 'Sensor (Helligkeit)', 'value' => 'SI:sensor:lux'],
            ['caption' => 'Sensor (Leistung W)', 'value' => 'SI:sensor:power'],
            ['caption' => 'Sensor (Energie kWh)', 'value' => 'SI:sensor:energy'],
            ['caption' => 'Aktor (Schalter)', 'value' => 'SI:actor:switch'],
            ['caption' => 'Aktor (Dimmer)', 'value' => 'SI:actor:dimmer'],
            ['caption' => 'Aktor (Rollladen)', 'value' => 'SI:actor:blind'],
            ['caption' => 'Aktor (Thermostat)', 'value' => 'SI:actor:thermostat'],
            ['caption' => 'Aktor (Schloss)', 'value' => 'SI:actor:lock'],
            ['caption' => 'Diagnostik', 'value' => 'SI:diagnostic'],
            ['caption' => 'Info', 'value' => 'SI:info'],
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

                // Für die Problemansicht: nur problematische Einträge zeigen
                $isProblem = false;
                if ($parsedTag['category'] === 'reachability' && $this->isProblematic($v)) $isProblem = true;
                elseif ($parsedTag['category'] === 'battery' && $this->isBatteryLow($v, $threshold)) $isProblem = true;
                elseif (in_array($parsedTag['category'], ['alarm', 'warning']) && $this->isProblematic($v)) $isProblem = true;
                elseif ($parsedTag['category'] === 'contact' && $this->isProblematic($v)) $isProblem = true;

                if ($isProblem) {
                    $initialCatalogList[] = [
                        'instanceName' => $device['instanceName'],
                        'room'         => $device['room'],
                        'tagBase'      => $tagBase,
                        'normalState'  => $normalStateStr,
                        'disabled'     => $v['disabled'],
                        'value'        => $v['valueFormatted'],
                        'ObjectID'     => $v['varID'],
                        'instanceID'   => $device['instanceID'],
                    ];
                }
            }
        }

        sort($catalogCategories);

        $onEditScript = '
            $listData = ${$IPS_VALUE};
            $vid = $listData["ObjectID"];
            $iid = $listData["instanceID"];
            
            $objType = IPS_GetObject($vid)["ObjectType"];
            if ($objType === 2) {
                // Variable -> Tag aktualisieren
                $newTag = $listData["tagBase"];
                $ns = $listData["normalState"] ?? "";
                // Doppelpunkte aus normalState entfernen (Injection-Schutz)
                $ns = str_replace(":", "", $ns);
                if ($ns !== "") {
                    $newTag .= ":ok=" . $ns;
                }
                if ($listData["disabled"]) {
                    $newTag .= ":disabled";
                }
                SINV_SetTag($id, $vid, $newTag);
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
            // We set the room on the object being edited (either Variable or Instance)
            SINV_SetRoom($id, $vid > 0 ? $vid : $iid, $newRoom);
            
            SINV_Scan($id);
        ';

        // ── Filter-Optionen aufbauen (Health-Filter + Sonder-Filter + Typen) ──
        $catalogOptions = [];
        // Health-Filter oben
        $catalogOptions[] = ['caption' => 'Alle Probleme',          'value' => 'problems'];
        $catalogOptions[] = ['caption' => 'Gesundheit: Alarm',       'value' => 'health:alarm'];
        $catalogOptions[] = ['caption' => 'Gesundheit: Batterie leer (offline)', 'value' => 'health:battery_dead'];
        $catalogOptions[] = ['caption' => 'Gesundheit: Nicht erreichbar', 'value' => 'health:offline'];
        $catalogOptions[] = ['caption' => 'Gesundheit: Batterie schwach', 'value' => 'health:battery_low'];
        $catalogOptions[] = ['caption' => 'Gesundheit: Kein Update', 'value' => 'health:stale'];
        $catalogOptions[] = ['caption' => 'Gesundheit: Alle gesunden', 'value' => 'health:healthy'];
        // Sonder-Filter
        $catalogOptions[] = ['caption' => 'Alle Geraete & Variablen', 'value' => 'all'];
        $catalogOptions[] = ['caption' => 'Nicht getaggte',           'value' => 'untagged'];
        $catalogOptions[] = ['caption' => 'Nur deaktivierte',         'value' => 'disabled'];
        // Typ-Filter (nach SI:-Kategorien)
        sort($catalogCategories);
        foreach ($catalogCategories as $cat) {
            $catalogOptions[] = ['caption' => $cat, 'value' => $cat];
        }

        // ── Initiale Liste: Alle Probleme (health != healthy) pro Geraet ──
        $healthLabels = [
            'alarm'        => 'Alarm',
            'battery_dead' => 'Batterie leer (offline)',
            'offline'      => 'Nicht erreichbar',
            'battery_low'  => 'Batterie schwach',
            'stale'        => 'Kein Update',
            'healthy'      => 'Gesund',
        ];
        $healthSeverity = ['alarm' => 5, 'battery_dead' => 4, 'offline' => 3, 'battery_low' => 2, 'stale' => 1, 'healthy' => 0];

        $initialCatalogList = [];
        foreach ($inventory as $device) {
            $h = $device['health'] ?? 'healthy';
            if ($h === 'healthy') continue;
            // Erste Problematische Variable fuer Tag-Anzeige finden
            $firstVar = null;
            foreach ($device['variables'] as $v) {
                if (!($v['disabled'] ?? false)) { $firstVar = $v; break; }
            }
            $parsedTag = $firstVar ? $this->parseTag($firstVar['tag']) : null;
            $tagBase = $parsedTag ? ('SI:' . $parsedTag['category'] . ($parsedTag['subcategory'] !== '' ? ':' . $parsedTag['subcategory'] : '')) : '';
            $normalStateStr = ($parsedTag && $parsedTag['normalState'] !== null) ? $parsedTag['normalState']['value'] : '';
            $initialCatalogList[] = [
                'instanceName' => $device['instanceName'],
                'room'         => $device['room'],
                'health'       => $healthLabels[$h] ?? $h,
                'detail'       => $device['healthDetail'] ?? '',
                'severity'     => $healthSeverity[$h] ?? 0,
                'tagBase'      => $tagBase,
                'normalState'  => $normalStateStr,
                'disabled'     => false,
                'value'        => $firstVar ? $this->getFormattedValue($firstVar['varID'] ?? 0) : '',
                'ObjectID'     => $firstVar['varID'] ?? ($device['instanceID']),
                'instanceID'   => $device['instanceID'],
            ];
        }
        usort($initialCatalogList, fn($a, $b) => $b['severity'] <=> $a['severity']);

        $problemCount = count($initialCatalogList);
        $healthyCount = count($inventory) - $problemCount;
        $totalDevices = count($inventory);
        $totalVars    = array_sum(array_map(fn($d) => count($d['variables']), $inventory));

        $summaryText = $totalDevices === 0
            ? 'Inventar leer - bitte einmal "Jetzt scannen" druecken.'
            : "$totalDevices Geraete, $totalVars getaggte Variablen"
                . ($problemCount > 0 ? " – $problemCount Probleme" : ' – alles gesund');

        $form = [
            'elements' => [
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Einstellungen',
                    'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'ScanInterval', 'caption' => 'Scan-Intervall', 'suffix' => 'Minuten (0 = nur manuell)', 'minimum' => 0, 'maximum' => 1440],
                        ['type' => 'NumberSpinner', 'name' => 'BatteryThreshold', 'caption' => 'Batterie-Schwellwert', 'suffix' => '%', 'minimum' => 5, 'maximum' => 50],
                        ['type' => 'NumberSpinner', 'name' => 'RoomPathSegment', 'caption' => 'Raum-Segment (von rechts im Pfad)', 'minimum' => 1, 'maximum' => 10],
                        ['type' => 'SelectInstance', 'name' => 'NotifierID', 'caption' => 'SmartNotifier (Auto-RefreshSubscriptions nach Scan)'],
                        ['type' => 'SelectInstance', 'name' => 'GeminiIOID', 'caption' => 'SmartGeminiIO (fuer KI-Tagging)'],
                    ],
                ],
            ],
            'actions' => [
                [
                    'type' => 'RowLayout',
                    'items' => [
                        ['type' => 'Button', 'caption' => 'Jetzt scannen', 'onClick' => 'SINV_Scan($id); SINV_UpdateCatalogList($id, isset($CatalogFilter) ? $CatalogFilter : \'problems\'); echo "Scan abgeschlossen.";'],
                        ['type' => 'Button', 'caption' => 'KI-Tagging starten (Auto-Uebernahme)', 'onClick' => 'IPS_RunScriptText(\'SINV_ClassifyWithAI(\' . $id . \');\'); echo "KI-Tagging laeuft im Hintergrund.";'],
                    ],
                ],
                [
                    'type' => 'Label',
                    'caption' => $summaryText,
                ],
                                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Statistik: Getaggte Kategorien',
                    'items' => [
                        [
                            'type' => 'List',
                            'name' => 'CatStats',
                            'caption' => '',
                            'rowCount' => min(max(count($catListValues), 3), 15),
                            'add' => false,
                            'delete' => false,
                            'columns' => [
                                ['name' => 'caption', 'caption' => 'Kategorie', 'width' => '250px'],
                                ['name' => 'tag', 'caption' => 'Tag', 'width' => '150px'],
                                ['name' => 'count', 'caption' => 'Anzahl Variablen', 'width' => '150px']
                            ],
                            'values' => $catListValues
                        ]
                    ]
                ],
                // ── Einzige Hauptliste (Geraete + Variablen) ──
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Inventar & Pflege',
                    'expanded' => true,
                    'items' => [
                        [
                            'type' => 'Select',
                            'name' => 'CatalogFilter',
                            'caption' => 'Filter',
                            'options' => $catalogOptions,
                            'value' => 'problems',
                            'onChange' => 'SINV_UpdateCatalogList($id, $CatalogFilter);',
                        ],
                        [
                            'type' => 'List',
                            'name' => 'CatalogList',
                            'caption' => '',
                            'rowCount' => min(max(count($initialCatalogList), 5), 25),
                            'sort' => ['column' => 'severity', 'direction' => 'descending'],
                            'onEdit' => str_replace('$IPS_VALUE', '"CatalogList"', $onEditScript),
                            'columns' => [
                                ['name' => 'health',       'caption' => 'Gesundheit',   'width' => '180px'],
                                ['name' => 'detail',       'caption' => 'Detail',        'width' => '200px'],
                                ['name' => 'instanceName', 'caption' => 'Geraet',        'width' => '180px'],
                                ['name' => 'room',         'caption' => 'Raum',          'width' => '110px', 'edit' => ['type' => 'Select', 'options' => $roomOptions]],
                                ['name' => 'tagBase',      'caption' => 'Kategorie',     'width' => '140px', 'edit' => ['type' => 'Select', 'options' => $tagOptions]],
                                ['name' => 'normalState',  'caption' => 'OK-Wert',       'width' => '100px', 'edit' => ['type' => 'ValidationTextBox']],
                                ['name' => 'disabled',     'caption' => 'Deaktiviert',   'width' => '80px',  'edit' => ['type' => 'CheckBox']],
                                ['name' => 'value',        'caption' => 'Aktuell',       'width' => '120px'],
                                ['name' => 'ObjectID',     'caption' => 'ID',            'width' => '65px',  'edit' => ['type' => 'SelectObject']],
                                ['name' => 'severity',     'caption' => 'Prio',          'width' => '50px',  'visible' => false],
                            ],
                            'values' => $initialCatalogList,
                        ],
                    ],
                ],
            ],
        ];

        return json_encode($form);
    }

    public function UpdateCatalogList(string $category): void
    {
        ['inventory' => $inventory, 'untagged' => $untagged] = $this->buildInventoryData();
        $threshold = $this->ReadPropertyInteger('BatteryThreshold');

        $healthLabels = [
            'alarm'        => 'Alarm',
            'battery_dead' => 'Batterie leer (offline)',
            'offline'      => 'Nicht erreichbar',
            'battery_low'  => 'Batterie schwach',
            'stale'        => 'Kein Update',
            'healthy'      => 'Gesund',
        ];
        $healthSeverity = ['alarm' => 5, 'battery_dead' => 4, 'offline' => 3, 'battery_low' => 2, 'stale' => 1, 'healthy' => 0];

        $list = [];

        // ── Geraete-zentrische Filter (health:*, problems) ──
        $isHealthFilter  = str_starts_with($category, 'health:');
        $filterHealth    = $isHealthFilter ? substr($category, 7) : null;
        $isProblemsFilter = ($category === 'problems');

        if ($isHealthFilter || $isProblemsFilter) {
            foreach ($inventory as $device) {
                $h = $device['health'] ?? 'healthy';
                $match = $isProblemsFilter ? ($h !== 'healthy') : ($h === $filterHealth);
                if (!$match) continue;

                // Erste nicht-deaktivierte Variable fuer Tag-Anzeige
                $firstVar = null;
                foreach ($device['variables'] as $v) {
                    if (!($v['disabled'] ?? false)) { $firstVar = $v; break; }
                }
                $parsedTag = $firstVar ? $this->parseTag($firstVar['tag']) : null;
                $tagBase = $parsedTag ? ('SI:' . $parsedTag['category'] . ($parsedTag['subcategory'] !== '' ? ':' . $parsedTag['subcategory'] : '')) : '';
                $normalStateStr = ($parsedTag && $parsedTag['normalState'] !== null) ? $parsedTag['normalState']['value'] : '';

                $list[] = [
                    'health'       => $healthLabels[$h] ?? $h,
                    'detail'       => $device['healthDetail'] ?? '',
                    'severity'     => $healthSeverity[$h] ?? 0,
                    'instanceName' => $device['instanceName'],
                    'room'         => $device['room'],
                    'tagBase'      => $tagBase,
                    'normalState'  => $normalStateStr,
                    'disabled'     => false,
                    'value'        => $firstVar ? $this->getFormattedValue($firstVar['varID'] ?? 0) : '',
                    'ObjectID'     => $firstVar['varID'] ?? $device['instanceID'],
                    'instanceID'   => $device['instanceID'],
                ];
            }
            usort($list, fn($a, $b) => $b['severity'] <=> $a['severity']);

        } elseif ($category === 'untagged') {
            // ── Nicht getaggte Instanzen ──
            foreach ($untagged as $u) {
                $ignoreVarID = @IPS_GetObjectIDByIdent('_SI_Ignore', $u['instanceID']);
                $isDisabled = ($ignoreVarID !== false && GetValue($ignoreVarID));
                $list[] = [
                    'health'       => '',
                    'detail'       => '',
                    'severity'     => 0,
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
            // ── Variablen-zentrische Filter (all, disabled, SI:...) ──
            foreach ($inventory as $device) {
                $h = $device['health'] ?? 'healthy';
                $deviceHealth  = $healthLabels[$h] ?? '';
                $deviceDetail  = $device['healthDetail'] ?? '';
                $deviceSeverity = $healthSeverity[$h] ?? 0;

                foreach ($device['variables'] as $v) {
                    $parsed  = $this->parseTag($v['tag']);
                    $tagBase = 'SI:' . $parsed['category'] . ($parsed['subcategory'] !== '' ? ':' . $parsed['subcategory'] : '');

                    $match = false;
                    if ($category === 'disabled' && $parsed['disabled']) {
                        $match = true;
                    } elseif ($category !== 'disabled' && $tagBase === $category && !$parsed['disabled']) {
                        $match = true;
                    } elseif ($category === 'all') {
                        $match = true;
                    }

                    if ($match) {
                        $normalStateStr = $parsed['normalState'] !== null ? $parsed['normalState']['value'] : '';
                        $list[] = [
                            'health'       => $deviceHealth,
                            'detail'       => $deviceDetail,
                            'severity'     => $deviceSeverity,
                            'instanceName' => $device['instanceName'],
                            'room'         => $device['room'],
                            'tagBase'      => $tagBase,
                            'normalState'  => $normalStateStr,
                            'disabled'     => $parsed['disabled'],
                            'value'        => $this->getFormattedValue($v['varID']),
                            'ObjectID'     => $v['varID'],
                            'instanceID'   => $device['instanceID'],
                        ];
                    }
                }
            }
        }

        $this->UpdateFormField('CatalogList', 'values', json_encode($list));
        $this->UpdateFormField('CatalogList', 'rowCount', min(max(count($list), 5), 25));
    }

    // ─────────────────────────────────────────────────────────────────
    // Hilfsfunktionen
    // ─────────────────────────────────────────────────────────────────

    /**
     * Ermittelt den Raum einer Instanz (_SI_Room Override oder Objektbaum-Pfad).
     */
        private function resolveRoom(int $id): string
    {
        $db = json_decode($this->ReadAttributeString('TagDatabase') ?: '{}', true);
        if (isset($db[$id]) && !empty($db[$id]['room'])) {
            return $db[$id]['room'];
        }
        
        // Fallback for Variables: Check parent instance
        $obj = IPS_GetObject($id);
        if ($obj['ObjectType'] === 2) { // Variable
            $parentId = $obj['ParentID'];
            if (isset($db[$parentId]) && !empty($db[$parentId]['room'])) {
                return $db[$parentId]['room'];
            }
            $id = $parentId;
        }

        // Fallback: _SI_Room (Legacy)
        $roomVarID = @IPS_GetObjectIDByIdent('_SI_Room', $id);
        if ($roomVarID !== false && IPS_VariableExists($roomVarID)) {
            $userRoom = GetValue($roomVarID);
            if ($userRoom !== '') return $userRoom;
        }

        // Fallback: Path
        $path = IPS_GetLocation($id);
        $segments = explode('\\', $path);
        $segmentIndex = $this->ReadPropertyInteger('RoomPathSegment');
        $idx = count($segments) - $segmentIndex;
        if ($idx >= 0 && $idx < count($segments)) {
            return $segments[$idx];
        }
        return '';
    }

    /**
     * Prüft ob eine Batterie-Variable als "niedrig" gilt.
     * Wird für UI-Zeilenfarben und API-Filter verwendet.
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
     * Wird für UI-Zeilenfarben und API-Filter verwendet.
     */
    private function isProblematic(array $v): bool
    {
        $value = $v['value'];
        $parsed = $this->parseTag($v['tag']);

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
            return in_array($lower, ['offline', 'nicht erreichbar', 'offen', 'open', 'alarm', 'fehler', 'error', 'true', 'ja']);
        }

        return false;
    }

    /**
     * Gibt den formatierten Wert einer Variable zurück.
     */
    private function getFormattedValue(int $varID): string
    {
        if (!@IPS_VariableExists($varID)) {
            return '(gelöscht)';
        }
        $value = @GetValue($varID);
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    }
}
