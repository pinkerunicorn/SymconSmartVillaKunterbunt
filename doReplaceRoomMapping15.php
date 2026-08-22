<?php
$f = 'SmartInventory/module.php';
$c = file_get_contents($f);

// 1. Remove CustomRooms property
$c = preg_replace("/\\\$this->RegisterPropertyString\('CustomRooms',\s*'\[\]'\);\s*/", "", $c);

// 2. Remove CustomRooms logic
$searchLogic = <<<EOF
        \$customRooms = json_decode(@\$this->ReadPropertyString('CustomRooms'), true) ?: [];
        foreach (\$customRooms as \$cr) {
            \$rName = trim(\$cr['RoomName'] ?? '');
            if (\$rName !== '' && !in_array(\$rName, \$finalRooms)) {
                \$finalRooms[] = \$rName;
            }
        }
EOF;
$c = str_replace($searchLogic, "", $c);

// 3. Find Expansion Panel manually
$start = strpos($c, "'caption' => 'Rume verwalten',");
if ($start === false) {
    // try different encoding
    $start = strpos($c, "'ExpansionPanel',");
    $start = strpos($c, "ume verwalten", $start);
    $start = strrpos(substr($c, 0, $start), "'caption' =>");
}

if ($start !== false) {
    $end = strpos($c, "'values' => \$customRooms", $start);
    $end = strpos($c, "]", $end); // end of customRooms list
    $end = strpos($c, "]", $end + 1); // end of items array
    
    if ($end !== false) {
        $length = $end - $start + 1;
        $originalBlock = substr($c, $start, $length);
        
        $replacement = <<<EOF
'caption' => 'Räume verwalten',
                    'items' => [
                        [
                            'type' => 'List',
                            'name' => 'RoomMapping',
                            'caption' => 'Räume verwalten (Automatisch gefunden & Manuell)',
                            'rowCount' => 10,
                            'add' => true,
                            'delete' => true,
                            'columns' => [
                                ['caption' => 'Symcon-Ordner (Original)', 'name' => 'Original', 'width' => '250px', 'edit' => ['type' => 'ValidationTextBox']],
                                ['caption' => 'Anzeigename (Umbenannt)', 'name' => 'Mapped', 'width' => 'auto', 'edit' => ['type' => 'ValidationTextBox']],
                                ['caption' => 'Ausblenden', 'name' => 'Hide', 'width' => '100px', 'edit' => ['type' => 'CheckBox']]
                            ],
                            'values' => \$roomMapping
                        ]
                    ]
EOF;
        $c = substr_replace($c, $replacement, $start, $length);
        file_put_contents($f, $c);
        echo "Replaced successfully!";
    } else {
        echo "Could not find end of CustomRooms!";
    }
} else {
    echo "Could not find start!";
}