<?php
$f = 'SmartInventory/module.php';
$c = file_get_contents($f);

// 1. Register Rooms property
$c = str_replace("\$this->RegisterPropertyString('RoomMapping', '[]');", "\$this->RegisterPropertyString('RoomMapping', '[]');\n        \$this->RegisterPropertyString('Rooms', '[]');", $c);

// 2. Replace GetConfigurationForm logic
$start = strpos($c, '// Auto-populate RoomMapping');
if ($start === false) $start = strpos($c, '$roomMapping = json_decode(');

$end = strpos($c, '$roomOptions = [[');
if ($start !== false && $end !== false) {
    $replacement = <<<EOF
        \$roomsProp = json_decode(@\$this->ReadPropertyString('Rooms') ?: '[]', true) ?: [];
        
        // Auto-populate ONLY if completely empty (initial setup / migration)
        if (empty(\$roomsProp)) {
            \$legacy = json_decode(@\$this->ReadPropertyString('RoomMapping') ?: '[]', true) ?: [];
            if (!empty(\$legacy)) {
                foreach (\$legacy as \$m) {
                    if (!(\$m['Hide'] ?? false)) {
                        \$name = trim(\$m['Mapped'] ?? \$m['Original'] ?? '');
                        if (\$name !== '' && !in_array(['RoomName' => \$name], \$roomsProp)) {
                            \$roomsProp[] = ['RoomName' => \$name];
                        }
                    }
                }
            } else {
                \$rawRooms = [];
                \$segmentIndex = @\$this->ReadPropertyInteger('RoomPathSegment') ?: 2;
                foreach (IPS_GetInstanceList() as \$iid) {
                    \$inst = @IPS_GetInstance(\$iid);
                    if (\$inst && \$inst['ModuleInfo']['ModuleType'] === 3) {
                        \$path = @IPS_GetLocation(\$iid);
                        \$segments = explode('\\\\', \$path);
                        \$idx = count(\$segments) - \$segmentIndex;
                        if (\$idx >= 0 && \$idx < count(\$segments)) {
                            \$rr = trim(\$segments[\$idx]);
                            if (\$rr !== '') \$rawRooms[\$rr] = true;
                        }
                    }
                }
                foreach (array_keys(\$rawRooms) as \$r) {
                    \$roomsProp[] = ['RoomName' => \$r];
                }
            }
        }
        
        \$finalRooms = [];
        foreach (\$roomsProp as \$r) {
            \$name = trim(\$r['RoomName'] ?? '');
            if (\$name !== '' && !in_array(\$name, \$finalRooms)) {
                \$finalRooms[] = \$name;
            }
        }
        
EOF;
    $c = substr_replace($c, $replacement, $start, $end - $start);
}

// 3. Replace the UI List definition for RoomMapping -> Rooms
$listReplace = <<<EOF
                            'type' => 'List',
                            'name' => 'Rooms',
                            'caption' => 'Räume verwalten',
                            'rowCount' => 10,
                            'add' => true,
                            'delete' => true,
                            'columns' => [
                                ['caption' => 'Raumname', 'name' => 'RoomName', 'width' => 'auto', 'add' => 'Neuer Raum', 'edit' => ['type' => 'ValidationTextBox']]
                            ],
                            'values' => \$roomsProp
EOF;
$c = preg_replace("/'type'\s*=>\s*'List',\s*'name'\s*=>\s*'RoomMapping',.*?'values'\s*=>\s*\\\$roomMapping/s", $listReplace, $c);

// 4. Update resolveRoom()
$resolveStart = strpos($c, 'private function resolveRoom');
if ($resolveStart !== false) {
    $fallbackStart = strpos($c, '// Fallback: Path', $resolveStart);
    // Find the end of the method by looking for the next method:
    $nextMethod = strpos($c, 'private function isBatteryLow', $fallbackStart);
    $resolveEnd = strrpos(substr($c, 0, $nextMethod), "}"); // The closing brace of resolveRoom
    
    // We want to replace everything from '// Fallback: Path' up to the closing brace, but LEAVE the closing brace!
    if ($fallbackStart !== false && $resolveEnd !== false) {
        $resolveReplacement = <<<EOF
// Fallback: Path (only if exact match with our Rooms list)
        \$path = IPS_GetLocation(\$id);
        \$segments = explode('\\\\', \$path);
        \$segmentIndex = @\$this->ReadPropertyInteger('RoomPathSegment') ?: 2;
        \$idx = count(\$segments) - \$segmentIndex;
        if (\$idx >= 0 && \$idx < count(\$segments)) {
            \$rawRoom = trim(\$segments[\$idx]);
            
            static \$validRoomsCache = null;
            if (\$validRoomsCache === null) {
                \$validRoomsCache = [];
                \$roomsProp = json_decode(@\$this->ReadPropertyString('Rooms') ?: '[]', true) ?: [];
                foreach (\$roomsProp as \$r) {
                    \$name = trim(\$r['RoomName'] ?? '');
                    if (\$name !== '') \$validRoomsCache[\$name] = true;
                }
            }
            
            if (isset(\$validRoomsCache[\$rawRoom])) {
                return \$rawRoom;
            }
        }
        return '';
    
EOF;
        $c = substr_replace($c, $resolveReplacement, $fallbackStart, $resolveEnd - $fallbackStart);
    }
}

file_put_contents($f, $c);
echo "Refactoring applied!\n";