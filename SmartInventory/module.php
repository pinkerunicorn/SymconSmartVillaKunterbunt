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
        $this->RegisterPropertyInteger('GeminiIOID', 0);
        $this->RegisterPropertyString('RoomMapping', '[]');
        $this->RegisterPropertyString('Rooms', '[]');              // SmartGeminiIO Instanz

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
        $this->Scan();
    }

    // ─────────────────────────────────────────────────────────────────
    // Scan
    // ─────────────────────────────────────────────────────────────────

        private function getUntaggedFile(): string
    {
        return IPS_GetLogDir() . 'sinv_' . $this->InstanceID . '_untagged.json';
    }

    private function getCacheFile(): string
    {
        return IPS_GetLogDir() . 'sinv_' . $this->InstanceID . '_inventory.json';
    }

    public function Scan(): string
    {
        $startTime = microtime(true);

        ['inventory' => $inventory, 'untagged' => $untaggedInstances] = $this->buildInventoryData();

        $deviceCount    = count($inventory);
        $taggedVarCount = array_sum(array_map(fn($d) => count($d['variables']), $inventory));

        // Alle aktiven Variablen cachen, da externe Module (wie SmartRoomLighting) 
        // auch ueber SINV_GetByCategory('actor:switch') etc. zugreifen muessen.
        // Kurzschluessel (v/c/s/d/n/r/t/u) sparen weitere ~40% Bytes.
        $leanInventory = $this->optimizeInventory($inventory);
        $file = $this->getCacheFile();
        $bytes = file_put_contents($file, json_encode($leanInventory));
        IPS_LogMessage('Smart Inventory', 'Saved Cache: ' . $file . ' (' . $bytes . ' bytes)');
        file_put_contents($this->getUntaggedFile(), json_encode($untaggedInstances));

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
                $db = json_decode(@$this->ReadAttributeString('TagDatabase') ?: '{}', true);


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
                if ($obj === false || $obj['ObjectType'] !== 2) {
                    continue;
                }
                if (str_starts_with($obj['ObjectIdent'], '_SI_')) {
                    continue;
                }

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
                    'room'           => $tagData['room'] ?? $room,
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

            $inventory[] = [
                'instanceID'   => $instanceID,
                'instanceName' => $instanceName,
                'room'         => $room,
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
        $file = $this->getCacheFile();
        $json = file_exists($file) ? file_get_contents($file) : '';
        if ($json === '' || $json === false) {
            IPS_RunScriptText('SINV_Scan(' . $this->InstanceID . ');');
            return '[]';
        }

        $leanInventory = json_decode($json, true);
        if (!is_array($leanInventory)) return '[]';

        $fatInventory = [];
        foreach ($leanInventory as $device) {
            $vars = $device['v'] ?? ($device['variables'] ?? []);
            $fatVars = [];
            foreach ($vars as $v) {
                // Determine if it's already fat or lean
                if (isset($v['category'])) {
                    $fatVars[] = $v;
                    continue;
                }
                
                $cat = $v['c'] ?? '';
                $sub = $v['s'] ?? '';
                $tag = $cat !== '' ? 'SI:' . $cat . ($sub !== '' ? ':' . $sub : '') : '';
                
                $fatVars[] = [
                    'varID'         => $v['v'] ?? 0,
                    'category'      => $cat,
                    'subcategory'   => $sub,
                    'normalState'   => $v['n'] ?? null,
                    'room'          => $v['r'] ?? '',
                    'disabled'      => $v['d'] ?? false,
                    'type'          => $v['t'] ?? 0,
                    'lastUpdatedTS' => $v['u'] ?? 0,
                    'tag'           => $tag
                ];
            }
            $fatInventory[] = [
                'instanceID'   => $device['i'] ?? ($device['instanceID'] ?? 0),
                'instanceName' => $device['n'] ?? ($device['instanceName'] ?? ''),
                'room'         => $device['r'] ?? ($device['room'] ?? ''),
                'variables'    => $fatVars
            ];
        }

        return json_encode($fatInventory);
    }

    /**
     * Gibt alle existierenden Kategorien zurück.
     */
    public function GetCategories(): string
    {
        $buffer = $this->GetInventory();
        $inventory = json_decode($buffer, true) ?: [];
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
        $file = $this->getCacheFile();
        $buffer = file_exists($file) ? file_get_contents($file) : '';
        if ($buffer === '') {
            $this->Scan();
            $file = $this->getCacheFile();
        $buffer = file_exists($file) ? file_get_contents($file) : '';
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
                    if ($v['d'] ?? ($v['disabled'] ?? false)) continue;
                    
                    $results[] = [
                        'instanceID'    => $device['i'] ?? ($device['instanceID'] ?? 0),
                        'instanceName'  => $device['n'] ?? ($device['instanceName'] ?? ''),
                        'room'          => (!empty($v['r']) ? $v['r'] : (!empty($device['r']) ? $device['r'] : ($device['room'] ?? ''))),
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
        $file = $this->getCacheFile();
        $buffer = file_exists($file) ? file_get_contents($file) : '';
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
                        'room'         => $v['room'] ?? $device['room'],
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
        $file = $this->getCacheFile();
        $buffer = file_exists($file) ? file_get_contents($file) : '';
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
        $file = $this->getCacheFile();
        $buffer = file_exists($file) ? file_get_contents($file) : '';
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
     * Gibt alle nicht getaggten Instanzen zurück.
     */
    public function GetUntagged(): string
    {
        $file = $this->getUntaggedFile();
        return file_exists($file) ? file_get_contents($file) : '[]';
    }

    /**
     * Setzt den Raum einer Instanz manuell.
     */
        public function SetRoom(int $id, string $room): bool
    {
        if (!@IPS_ObjectExists($id)) return false;
        
                $db = json_decode(@$this->ReadAttributeString('TagDatabase') ?: '{}', true);

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
     * Gibt den Tag einer Variable zurück.
     */
    public function GetTag(int $varID): string
    {
        $db = json_decode(@$this->ReadAttributeString('TagDatabase') ?: '{}', true);
        return $db[$varID]['tag'] ?? '';
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
        
                $db = json_decode(@$this->ReadAttributeString('TagDatabase') ?: '{}', true);

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
    /**
     * Setzt alle Tags zurueck und startet das KI-Tagging fuer das komplette Haus neu.
     */
    public function RetagAllWithAI(): string
    {
        $this->WriteAttributeString('TagDatabase', '{}');
        $this->WriteAttributeString('AISuggestions', '[]');
        return $this->ClassifyWithAI();
    }

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

                $db = json_decode(@$this->ReadAttributeString('TagDatabase') ?: '{}', true);


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
                if (!empty($db[$childID]['tag'])) {
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
SI:motion, SI:contact, SI:contact:ok=WERT (für String-Kontakte),
SI:sensor:temp, SI:sensor:humidity, SI:sensor:co2, SI:sensor:voc,
SI:sensor:pressure, SI:sensor:lux, SI:sensor:radon, SI:sensor:button (Taster),
SI:sensor:power, SI:sensor:energy, SI:sensor:generic,
SI:actor:light, SI:actor:dimmer, SI:actor:color, SI:actor:switch, SI:actor:blind, SI:actor:thermostat,
SI:actor:lock, SI:actor:valve,
SI:warning, SI:info, SI:diagnostic, SKIP

Regeln:
- UNREACH/Nicht erreichbar: SI:reachability:ok=false (invertiert!)
- DeviceAvailable/Online/Geraetestatus: SI:reachability (normal)
- Helligkeit (steuern/Prozent): SI:actor:dimmer
- Helligkeit (nur messen): SI:sensor:lux
- String-Kontakte z.B. "Geschlossen": SI:contact:ok=Geschlossen
- Raum = vorletztes Pfadsegment (vor Gerätename)
- SKIP für irrelevante Variablen (interne Zähler, Config, Darstellung)

Homematic / HmIP Hints:
- PRESS_SHORT / PRESS_LONG: SI:sensor:button:ok=CLOSED (ersetze CLOSED durch den Trigger-Wert)
- MOTION: SI:motion
- ACTUAL_TEMPERATURE: SI:sensor:temp
- SET_POINT_TEMPERATURE: SI:actor:thermostat
- LEVEL: SI:actor:dimmer oder SI:actor:blind (nach Name entscheiden)
- STATE: SI:contact (bei Fenster/Tür-Sensoren) oder SI:actor:switch (Steckdosen) oder SI:actor:light (Lampen)
- LOWBAT / LOW_BAT: SI:battery
- UNREACH: SI:reachability:ok=false
- ERROR_SABOTAGE: SI:alarm:tamper
- ILLUMINATION: SI:sensor:lux
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
        $suggestions = json_decode(@$this->ReadAttributeString('AISuggestions') ?: '[]', true);
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
              ['caption' => 'Sensor (CO2)', 'value' => 'SI:sensor:co2'],
              ['caption' => 'Sensor (VOC)', 'value' => 'SI:sensor:voc'],
              ['caption' => 'Sensor (Luftdruck)', 'value' => 'SI:sensor:pressure'],
              ['caption' => 'Sensor (Radon)', 'value' => 'SI:sensor:radon'],
              ['caption' => 'Sensor (Leistung W)', 'value' => 'SI:sensor:power'],
              ['caption' => 'Sensor (Energie kWh)', 'value' => 'SI:sensor:energy'],
              ['caption' => 'Sensor (Generisch)', 'value' => 'SI:sensor:generic'],
              ['caption' => 'Aktor (Licht/Lampe)', 'value' => 'SI:actor:light'],
                ['caption' => 'Aktor (RGB Licht)', 'value' => 'SI:actor:color'],
                ['caption' => 'Aktor (Schalter/Steckdose)', 'value' => 'SI:actor:switch'],
              ['caption' => 'Aktor (Dimmer)', 'value' => 'SI:actor:dimmer'],
              ['caption' => 'Aktor (Rollladen)', 'value' => 'SI:actor:blind'],
              ['caption' => 'Aktor (Thermostat)', 'value' => 'SI:actor:thermostat'],
              ['caption' => 'Aktor (Schloss)', 'value' => 'SI:actor:lock'],
              ['caption' => 'Aktor (Ventil)', 'value' => 'SI:actor:valve'],
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

                        // Räume zählen (exakt so wie sie als Zeilen im UI auftauchen)
        $roomCounts = [];
        foreach ($inventory as $device) {
            foreach ($device['variables'] as $v) {
                if (str_starts_with($v['tag'] ?? '', 'SI:')) {
                    $r = $v['room'] ?? $device['room'] ?? '';
                    if ($r !== '') $roomCounts[$r] = ($roomCounts[$r] ?? 0) + 1;
                }
            }
        }
        foreach ($untagged as $device) {
            $r = $device['room'] ?? '';
            if ($r !== '') $roomCounts[$r] = ($roomCounts[$r] ?? 0) + 1;
        }

        $roomsProp = json_decode(@$this->ReadPropertyString('Rooms') ?: '[]', true) ?: [];
        
        // Auto-populate ONLY if completely empty (initial setup / migration)
        if (empty($roomsProp)) {
            $legacy = json_decode(@$this->ReadPropertyString('RoomMapping') ?: '[]', true) ?: [];
            if (!empty($legacy)) {
                foreach ($legacy as $m) {
                    if (!($m['Hide'] ?? false)) {
                        $name = trim($m['Mapped'] ?? $m['Original'] ?? '');
                        if ($name !== '' && !in_array(['RoomName' => $name], $roomsProp)) {
                            $roomsProp[] = ['RoomName' => $name];
                        }
                    }
                }
            } else {
                $rawRooms = [];
                $segmentIndex = @$this->ReadPropertyInteger('RoomPathSegment') ?: 2;
                foreach (IPS_GetInstanceList() as $iid) {
                    $inst = @IPS_GetInstance($iid);
                    if ($inst && $inst['ModuleInfo']['ModuleType'] === 3) {
                        $path = @IPS_GetLocation($iid);
                        $segments = explode('\\', $path);
                        $idx = count($segments) - $segmentIndex;
                        if ($idx >= 0 && $idx < count($segments)) {
                            $rr = trim($segments[$idx]);
                            if ($rr !== '') $rawRooms[$rr] = true;
                        }
                    }
                }
                foreach (array_keys($rawRooms) as $r) {
                    $roomsProp[] = ['RoomName' => $r];
                }
            }
        }
        
        // Auto-append actively used rooms that were deleted, and calculate Info column
        foreach ($roomsProp as &$r) {
            $name = trim($r['RoomName'] ?? '');
            $count = $roomCounts[$name] ?? 0;
            $r['Info'] = $count === 0 ? '(leer)' : "($count Geräte)";
        }
        unset($r);
        
        foreach ($roomCounts as $name => $count) {
            $found = false;
            foreach ($roomsProp as $r) {
                if (trim($r['RoomName'] ?? '') === $name) { $found = true; break; }
            }
            if (!$found && $name !== '') {
                $roomsProp[] = ['RoomName' => $name, 'Info' => "($count Geräte)"];
            }
        }

        $finalRooms = [];
        foreach ($roomsProp as $r) {
            $name = trim($r['RoomName'] ?? '');
            if ($name !== '' && !in_array($name, $finalRooms)) {
                $finalRooms[] = $name;
            }
        }
        natcasesort($finalRooms);
        $finalRooms = array_values($finalRooms);
        
        $roomOptions = [['caption' => '(Kein Raum)', 'value' => '']];
        foreach ($finalRooms as $r) {
            $c = $roomCounts[$r] ?? 0;
            $caption = $c === 0 ? "($r)" : $r;
            $roomOptions[] = ['caption' => $caption, 'value' => $r];
        }

        $tagOptions = array_merge(
            [['caption' => '(Nicht getaggt)', 'value' => '']],
            $earlyTagOpts,
            [
                ['caption' => 'Alarm (CO)', 'value' => 'SI:alarm:co'],
                ['caption' => 'Alarm (Sabotage/Tamper)', 'value' => 'SI:alarm:tamper'],
                ['caption' => 'Diagnostik', 'value' => 'SI:diagnostic'],
                ['caption' => 'Info', 'value' => 'SI:info'],
            ]
        );
        $seen = [];
        $unique = [];
        foreach ($tagOptions as $t) {
            if (!isset($seen[$t['value']])) {
                $unique[] = $t;
                $seen[$t['value']] = true;
            }
        }
        $tagOptions = $unique;

        $initialCatalogList = [];
        $catalogCategories = [];

        foreach ($inventory as $device) {
            foreach ($device['variables'] as $v) {
                $parsedTag = $this->parseTag($v['tag']);
                $tagBase = $parsedTag['category'] !== '' ? 'SI:' . $parsedTag['category'] . ($parsedTag['subcategory'] !== '' ? ':' . $parsedTag['subcategory'] : '') : '';
                
                if ($tagBase !== '' && !in_array($tagBase, $catalogCategories)) {
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
                        'room'         => $v['room'] ?? $device['room'],
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
            
            $objType = IPS_GetObject($vid)["ObjectType"];
            if ($objType === 2) {
                // Variable -> Tag aktualisieren
                  $newTag = $listData["tagBase"];
                  $ns = $listData["normalState"] ?? "";
                  $ns = str_replace(":", "", $ns);
                  if ($ns !== "") {
                      if ($newTag === "") $newTag = "SI:";
                      $newTag .= ":ok=" . $ns;
                  }
                  if ($listData["disabled"]) {
                      if ($newTag === "") $newTag = "SI:";
                      $newTag .= ":disabled";
                  }
                  SINV_SetTag($id, $vid, $newTag);
            } elseif ($objType === 1) {
                // Instanz (Nicht getaggt) -> Ignore setzen
                $ignoreVarID = @IPS_GetObjectIDByIdent("_SI_Ignore", $vid);
                if ($listData["disabled"]) {
                    if ($ignoreVarID === false) {
                        $ignoreVarID = IPS_CreateVariable(0);
                        IPS_SetParent($ignoreVarID, $vid);
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
        // Sonder-Filter
        $catalogOptions[] = ['caption' => 'Alle Geraete & Variablen', 'value' => 'all'];
        $catalogOptions[] = ['caption' => 'Nicht getaggte',           'value' => 'untagged'];

        $roomFilterOptions = [['caption' => 'Alle Räume', 'value' => 'all']];
        foreach ($finalRooms as $r) {
            $roomFilterOptions[] = ['caption' => $r, 'value' => $r];
        }
        $catalogOptions[] = ['caption' => 'Nur deaktivierte',         'value' => 'disabled'];
        // Typ-Filter (nach SI:-Kategorien)
        sort($catalogCategories);
        foreach ($catalogCategories as $cat) {
            $catalogOptions[] = ['caption' => $cat, 'value' => $cat];
        }

        $initialCatalogList = [];
        foreach ($inventory as $device) {
            foreach ($device['variables'] as $v) {
                $parsed  = $this->parseTag($v['tag']);
                if ($parsed['disabled']) continue;

                $tagBase = $parsed['category'] !== '' ? 'SI:' . $parsed['category'] . ($parsed['subcategory'] !== '' ? ':' . $parsed['subcategory'] : '') : '';
                $normalStateStr = $parsed['normalState'] !== null ? $parsed['normalState']['value'] : '';
                
                $initialCatalogList[] = [
                    'instanceName' => $device['instanceName'],
                    'room'         => $v['room'] ?? $device['room'],
                    'tagBase'      => $tagBase,
                    'normalState'  => $normalStateStr,
                    'disabled'     => $parsed['disabled'] ?? false,
                    'value'        => $this->getFormattedValue($v['varID'] ?? 0),
                    'ObjectID'     => $v['varID'],
                    'instanceID'   => $device['instanceID'],
                ];
            }
        }

                $totalDevices = count($inventory);
        $totalVars    = array_sum(array_map(fn($d) => count($d['variables']), $inventory));

        $summaryText = $totalDevices === 0
            ? 'Inventar leer - bitte einmal "Jetzt scannen" druecken.'
            : "$totalDevices Geraete, $totalVars getaggte Variablen";

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
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Räume verwalten',
                    'items' => [
                        [
                                                        'type' => 'List',
                            'name' => 'Rooms',
                            'caption' => 'Räume verwalten',
                            'rowCount' => 10,
                            'add' => true,
                            'delete' => true,
                            'columns' => [
                                ['caption' => 'Raumname', 'name' => 'RoomName', 'width' => 'auto', 'add' => 'Neuer Raum', 'edit' => ['type' => 'ValidationTextBox']],
                                ['caption' => 'Nutzung', 'name' => 'Info', 'width' => '150px']
                            ],
                            'values' => $roomsProp
                        ]
                    ]
                ]
            ],
            'actions' => [
                [
                    'type' => 'RowLayout',
                    'items' => [
                        ['type' => 'Button', 'caption' => 'Jetzt scannen', 'onClick' => 'SINV_Scan($id); SINV_UpdateCatalogList($id, isset($CatalogFilter) ? $CatalogFilter : \'problems\', isset($RoomFilter) ? $RoomFilter : "all", isset($SearchText) ? $SearchText : "", isset($ShowDisabled) ? $ShowDisabled : false); echo "Scan abgeschlossen.";'],
                        ['type' => 'Button', 'caption' => 'KI-Tagging starten (Auto-Uebernahme)', 'onClick' => 'IPS_RunScriptText(\'SINV_ClassifyWithAI(\' . $id . \');\'); echo "KI-Tagging laeuft im Hintergrund.";'],
                        ['type' => 'Button', 'caption' => 'ALLES neu KI-taggen (Achtung: Ueberschreibt alles!)', 'onClick' => 'IPS_RunScriptText(\'SINV_RetagAllWithAI(\' . $id . \');\'); echo "Komplettes KI-Tagging laeuft im Hintergrund.";'],
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
                            'type' => 'RowLayout',
                            'items' => [
                                [
                                    'type' => 'Select',
                                    'name' => 'CatalogFilter',
                                    'caption' => 'Kategorie-Filter',
                                    'options' => $catalogOptions,
                                    'value' => 'all',
                                    'onChange' => 'SINV_UpdateCatalogList($id, $CatalogFilter, $RoomFilter ?? "all", $SearchText ?? "", $ShowDisabled ?? false);',
                                ],
                                [
                                    'type' => 'Select',
                                    'name' => 'RoomFilter',
                                    'caption' => 'Raum-Filter',
                                    'options' => $roomFilterOptions,
                                    'value' => 'all',
                                    'onChange' => 'SINV_UpdateCatalogList($id, $CatalogFilter ?? "all", $RoomFilter, $SearchText ?? "", $ShowDisabled ?? false);',
                                ]
                            ]
                        ],
                        [
                            'type' => 'RowLayout',
                            'items' => [
                                [
                                    'type' => 'ValidationTextBox',
                                    'name' => 'SearchText',
                                    'caption' => 'Suchbegriff (Geraet, Raum, ID...)'
                                ],
                                [
                                    'type' => 'CheckBox',
                                    'name' => 'ShowDisabled',
                                    'caption' => 'Deaktivierte einblenden',
                                    'value' => false,
                                    'onChange' => 'SINV_UpdateCatalogList($id, $CatalogFilter ?? "all", $RoomFilter ?? "all", $SearchText ?? "", $ShowDisabled);'
                                ],
                                [
                                    'type' => 'Button',
                                    'caption' => 'Suchen',
                                    'onClick' => 'SINV_UpdateCatalogList($id, $CatalogFilter ?? "all", $RoomFilter ?? "all", $SearchText ?? "", $ShowDisabled ?? false);'
                                ]
                            ]
                        ],
                        [
                            'type' => 'List',
                            'name' => 'CatalogList',
                            'caption' => '',
                            'rowCount' => min(max(count($initialCatalogList), 5), 25),
                                                        'onEdit' => str_replace('$IPS_VALUE', '"CatalogList"', $onEditScript),
                            'columns' => [
                                ['name' => 'instanceName', 'caption' => 'Geraet', 'width' => 'auto'],
                                ['name' => 'room',         'caption' => 'Raum',          'width' => '110px', 'edit' => ['type' => 'Select', 'options' => $roomOptions]],
                                ['name' => 'tagBase',      'caption' => 'Kategorie',     'width' => '140px', 'edit' => ['type' => 'Select', 'options' => $tagOptions]],
                                ['name' => 'normalState',  'caption' => 'Ref-Wert (OK / Trigger)',       'width' => '100px', 'edit' => ['type' => 'ValidationTextBox']],
                                ['name' => 'disabled',     'caption' => 'Deaktiviert',   'width' => '80px',  'edit' => ['type' => 'CheckBox']],
                                ['name' => 'value',        'caption' => 'Aktuell',       'width' => '120px'],
                                ['name' => 'ObjectID',     'caption' => 'ID',            'width' => '65px',  'edit' => ['type' => 'SelectObject']],
                            ],
                            'values' => $initialCatalogList,
                        ],
                    ],
                ],
            ],
        ];

        return json_encode($form);
    }

    public function UpdateCatalogList(string $category, string $room = "all", string $search = "", bool $showDisabled = false): void
    {
        ['inventory' => $inventory, 'untagged' => $untagged] = $this->buildInventoryData();
        $leanInventory = $this->optimizeInventory($inventory);
        $file = $this->getCacheFile();
        $bytes = file_put_contents($file, json_encode($leanInventory));
        IPS_LogMessage('Smart Inventory', 'Saved Cache: ' . $file . ' (' . $bytes . ' bytes)');
        $threshold = $this->ReadPropertyInteger('BatteryThreshold');

        $list = [];

        if ($category === 'untagged') {
            // Nicht getaggte Instanzen (deaktivierte ausblenden, es sei denn showDisabled)
            foreach ($untagged as $u) {
                $ignoreVarID = @IPS_GetObjectIDByIdent('_SI_Ignore', $u['instanceID']);
                $isDisabled = ($ignoreVarID !== false && GetValue($ignoreVarID));
                if ($isDisabled && !$showDisabled) continue;
                
                $list[] = [
                    'instanceName' => $u['instanceName'],
                    'room'         => $u['room'],
                    'tagBase'      => '',
                    'normalState'  => '',
                    'disabled'     => false,
                    'value'        => $u['moduleName'] . ' (' . $u['varCount'] . ' Variablen)',
                    'ObjectID'     => $u['instanceID'],
                    'instanceID'   => $u['instanceID'],
                ];
            }
        } elseif ($category === 'disabled') {
            // Nur deaktivierte (Variablen und Instanzen)
            foreach ($inventory as $device) {
                foreach ($device['variables'] as $v) {
                    $parsed  = $this->parseTag($v['tag']);
                    if ($parsed['disabled']) {
                        $tagBase = $parsed['category'] !== '' ? 'SI:' . $parsed['category'] . ($parsed['subcategory'] !== '' ? ':' . $parsed['subcategory'] : '') : '';
                        $list[] = [
                                                    'instanceName' => $device['instanceName'],
                            'room'         => $v['room'] ?? $device['room'],
                            'tagBase'      => $tagBase,
                            'normalState'  => $parsed['normalState'] !== null ? $parsed['normalState']['value'] : '',
                            'disabled'     => true,
                            'value'        => $this->getFormattedValue($v['varID']),
                            'ObjectID'     => $v['varID'],
                            'instanceID'   => $device['instanceID'],
                        ];
                    }
                }
            }
            foreach ($untagged as $u) {
                $ignoreVarID = @IPS_GetObjectIDByIdent('_SI_Ignore', $u['instanceID']);
                $isDisabled = ($ignoreVarID !== false && GetValue($ignoreVarID));
                if ($isDisabled) {
                    $list[] = [
                                    'instanceName' => $u['instanceName'],
                        'room'         => $u['room'],
                        'tagBase'      => '',
                        'normalState'  => '',
                        'disabled'     => true,
                        'value'        => $u['moduleName'] . ' (' . $u['varCount'] . ' Variablen)',
                        'ObjectID'     => $u['instanceID'],
                        'instanceID'   => $u['instanceID'],
                    ];
                }
            }
        } else {
            // Variablen-zentrische Filter (all, SI:...)
            foreach ($inventory as $device) {
                foreach ($device['variables'] as $v) {
                    $parsed  = $this->parseTag($v['tag']);
                    
                    $tagBase = $parsed['category'] !== '' ? 'SI:' . $parsed['category'] . ($parsed['subcategory'] !== '' ? ':' . $parsed['subcategory'] : '') : '';

                    $match = false;
                    if ($category === 'all') {
                        $match = true;
                    } elseif ($tagBase === $category) {
                        $match = true;
                    }

                    $isDisabled = $parsed['disabled'] ?? false;
                    if (!$showDisabled && $isDisabled && $category !== 'disabled') {
                        continue;
                    }

                    if ($match) {
                        $normalStateStr = $parsed['normalState'] !== null ? $parsed['normalState']['value'] : '';
                        $list[] = [
                            'instanceName' => $device['instanceName'],
                            'room'         => $v['room'] ?? $device['room'],
                            'tagBase'      => $tagBase,
                            'normalState'  => $normalStateStr,
                            'disabled'     => $parsed['disabled'] ?? false,
                            'value'        => $this->getFormattedValue($v['varID']),
                            'ObjectID'     => $v['varID'],
                            'instanceID'   => $device['instanceID'],
                        ];
                    }
                }
            }
        }

        if ($room !== 'all') {
            $list = array_values(array_filter($list, function($row) use ($room) {
                return (isset($row['room']) && $row['room'] === $room);
            }));
        }

        if ($search !== '') {
            $search = strtolower($search);
            $list = array_values(array_filter($list, function($row) use ($search) {
                foreach ($row as $k => $v) {
                    if ($k === 'severity') continue;
                    if (str_contains(strtolower((string)$v), $search)) return true;
                }
                return false;
            }));
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
        private function optimizeInventory(array $inventory): array
    {
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
                    'r' => $v['room'] ?? '',          // room
                    'd' => $v['disabled'],            // disabled
                    't' => $v['type'],                 // type
                    'u' => $v['lastUpdatedTS'] ?? 0,        // lastUpdatedTS
                ];
            }
            if (count($leanVars) === 0) {
                continue;
            }
            $leanInventory[] = [
                'i' => $device['instanceID'],      // instanceID
                'n' => $device['instanceName'],    // instanceName
                'r' => $device['room'],            // room
                'v' => $leanVars,                  // variables
            ];
        }
        return $leanInventory;
    }

    private function resolveRoom(int $id): string
    {
                $db = json_decode(@$this->ReadAttributeString('TagDatabase') ?: '{}', true);

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

        // Fallback: Path (only if exact match with our Rooms list)
        $path = IPS_GetLocation($id);
        $segments = explode('\\', $path);
        $segmentIndex = @$this->ReadPropertyInteger('RoomPathSegment') ?: 2;
        $idx = count($segments) - $segmentIndex;
        if ($idx >= 0 && $idx < count($segments)) {
            $rawRoom = trim($segments[$idx]);
            
            static $validRoomsCache = null;
            if ($validRoomsCache === null) {
                $validRoomsCache = [];
                $roomsProp = json_decode(@$this->ReadPropertyString('Rooms') ?: '[]', true) ?: [];
                foreach ($roomsProp as $r) {
                    $name = trim($r['RoomName'] ?? '');
                    if ($name !== '') $validRoomsCache[$name] = true;
                }
            }
            
            if (isset($validRoomsCache[$rawRoom])) {
                return $rawRoom;
            }
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




