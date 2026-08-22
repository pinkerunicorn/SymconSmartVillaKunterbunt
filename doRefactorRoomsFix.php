<?php
$f = 'SmartInventory/module.php';
$c = file_get_contents($f);

$startStr = "        // Rume sammeln fr Dropdown\n        \$allRooms = [];\n                foreach (\$inventory as \$device) {";
$endStr = "        foreach (\$finalRooms as \$r) {\n            \$roomOptions[] = ['caption' => \$r, 'value' => \$r];\n        }";

// try ascii equivalent
$startStr2 = "        // R";
$startStr3 = "\$allRooms = [];";
$startPos = strpos($c, $startStr3);
// go back to the comment
$startPos = strrpos(substr($c, 0, $startPos), "// R");

$endPos = strpos($c, $endStr);
if ($endPos !== false) {
    $endPos += strlen($endStr); // include the end string
}

if ($startPos !== false && $endPos !== false) {
    $replacement = <<<EOF
        // Räume sammeln für Dropdown und Nutzung zählen
        \$roomCounts = [];
        foreach (\$inventory as \$device) {
            \$r = \$device['room'] ?? '';
            if (\$r !== '') \$roomCounts[\$r] = (\$roomCounts[\$r] ?? 0) + 1;
            foreach (\$device['variables'] as \$v) {
                \$r2 = \$v['room'] ?? '';
                if (\$r2 !== '') \$roomCounts[\$r2] = (\$roomCounts[\$r2] ?? 0) + 1;
            }
        }
        foreach (\$untagged as \$device) {
            \$r = \$device['room'] ?? '';
            if (\$r !== '') \$roomCounts[\$r] = (\$roomCounts[\$r] ?? 0) + 1;
        }

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
        
        // Auto-append actively used rooms that were deleted, and calculate Info column
        foreach (\$roomsProp as &\$r) {
            \$name = trim(\$r['RoomName'] ?? '');
            \$count = \$roomCounts[\$name] ?? 0;
            \$r['Info'] = \$count === 0 ? '(leer)' : "(\$count Geräte)";
        }
        unset(\$r);
        
        foreach (\$roomCounts as \$name => \$count) {
            \$found = false;
            foreach (\$roomsProp as \$r) {
                if (trim(\$r['RoomName'] ?? '') === \$name) { \$found = true; break; }
            }
            if (!\$found && \$name !== '') {
                \$roomsProp[] = ['RoomName' => \$name, 'Info' => "(\$count Geräte)"];
            }
        }

        \$finalRooms = [];
        foreach (\$roomsProp as \$r) {
            \$name = trim(\$r['RoomName'] ?? '');
            if (\$name !== '' && !in_array(\$name, \$finalRooms)) {
                \$finalRooms[] = \$name;
            }
        }
        sort(\$finalRooms);
        
        \$roomOptions = [['caption' => '(Kein Raum)', 'value' => '']];
        foreach (\$finalRooms as \$r) {
            \$c = \$roomCounts[\$r] ?? 0;
            \$caption = \$c === 0 ? "(\$r)" : \$r;
            \$roomOptions[] = ['caption' => \$caption, 'value' => \$r];
        }
EOF;

    $c = substr_replace($c, $replacement, $startPos, $endPos - $startPos);
    
    // Now replace the UI List definition for Rooms to include 'Info' column
    $c = str_replace(
        "['caption' => 'Raumname', 'name' => 'RoomName', 'width' => 'auto', 'add' => 'Neuer Raum', 'edit' => ['type' => 'ValidationTextBox']]",
        "['caption' => 'Raumname', 'name' => 'RoomName', 'width' => 'auto', 'add' => 'Neuer Raum', 'edit' => ['type' => 'ValidationTextBox']],\n                                ['caption' => 'Nutzung', 'name' => 'Info', 'width' => '150px']",
        $c
    );
    
    file_put_contents($f, $c);
    echo "Refactoring applied perfectly!\n";
} else {
    echo "Could not find bounds!";
}