<?php
$f = 'SmartInventory/module.php';
$c = file_get_contents($f);

// 1. Calculate Room Counts right before roomsProp loading
$startRooms = strpos($c, "\$roomsProp = json_decode");
if ($startRooms !== false) {
    // Look backwards to just after sort($allRooms);
    // Actually, just inject it right before $roomsProp
    $injection = <<<EOF
        // Räume zählen
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

EOF;
    $c = substr_replace($c, $injection . "        ", $startRooms, 0);
}

// 2. Replace the rest of roomsProp and finalRooms calculation up to the END of finalRooms, but NOT further!
$startFinal = strpos($c, '$finalRooms = [];');
$endFinal = strpos($c, '$roomOptions = [[');
if ($startFinal !== false && $endFinal !== false) {
    $replacement = <<<EOF
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
        
EOF;
    $c = substr_replace($c, $replacement, $startFinal, $endFinal - $startFinal);
}

// 3. Find where we populate $roomOptions (which is just after our new block, or currently $roomOptions = [['caption' => '(Kein Raum)', 'value' => '']];)
$roomOptsStart = strpos($c, '$roomOptions = [[\'caption\' => \'(Kein Raum)\', \'value\' => \'\']];');
$roomOptsEnd = strpos($c, '$catalogOptions[] = [\'caption\' => \'Nicht getaggte\',', $roomOptsStart);
if ($roomOptsStart !== false && $roomOptsEnd !== false) {
    $optsReplacement = <<<EOF
\$roomOptions = [['caption' => '(Kein Raum)', 'value' => '']];
        foreach (\$finalRooms as \$r) {
            \$c = \$roomCounts[\$r] ?? 0;
            \$caption = \$c === 0 ? "(\$r)" : \$r;
            \$roomOptions[] = ['caption' => \$caption, 'value' => \$r];
        }

        
EOF;
    $c = substr_replace($c, $optsReplacement, $roomOptsStart, $roomOptsEnd - $roomOptsStart);
}

// 4. Update UI
$c = str_replace(
    "['caption' => 'Raumname', 'name' => 'RoomName', 'width' => 'auto', 'add' => 'Neuer Raum', 'edit' => ['type' => 'ValidationTextBox']]",
    "['caption' => 'Raumname', 'name' => 'RoomName', 'width' => 'auto', 'add' => 'Neuer Raum', 'edit' => ['type' => 'ValidationTextBox']],\n                                ['caption' => 'Nutzung', 'name' => 'Info', 'width' => '150px']",
    $c
);

file_put_contents($f, $c);
echo "Refactoring applied!\n";