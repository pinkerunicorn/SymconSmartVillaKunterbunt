<?php
$f = 'C:/Users/grass/Documents/Symcon/SymconSmartVillaKunterbunt/SmartInventory/module.php';
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

// 3. Replace the entire ExpansionPanel content for Räume verwalten
// Find the exact block we want to replace
$startPanel = strpos($c, "'caption' => 'Räume verwalten',");
if ($startPanel === false) {
    // try with umlaut issue?
    $startPanel = strpos($c, "'ExpansionPanel',");
    $startPanel = strpos($c, "R", $startPanel); // finding 'Räume verwalten'
    // actually, let's just use strpos of 'type' => 'ExpansionPanel', then finding the first 'RoomMapping'
}
// Let's just use preg_replace for the items array of that ExpansionPanel
$pattern = "/'caption'\s*=>\s*'R.ume verwalten',\s*'items'\s*=>\s*\[\s*\[\s*'type'\s*=>\s*'List',\s*'name'\s*=>\s*'RoomMapping'.*?'values'\s*=>\s*\\\$customRooms\s*\]\s*\]/s";

// Wait, the block ends with 'values' => $customRooms ] ]
// Let's test this regex!
if (preg_match($pattern, $c, $m)) {
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
    $c = preg_replace($pattern, $replacement, $c);
    file_put_contents($f, $c);
    echo "Replaced UI Block!\n";
} else {
    echo "Could not match UI Block!\n";
}