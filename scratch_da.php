<?php
\ = [
    'C:\Users\grass\Documents\Symcon\SymconSmartVillaKunterbunt\SmartBriefing\module.php',
    'C:\Users\grass\Documents\Symcon\SymconSmartDevices\SmartFountain\module.php',
    'C:\Users\grass\Documents\Symcon\SymconSmartClimate\SmartWaterMonitor\module.php'
];

foreach (\ as \) {
    \ = file_get_contents(\);
    if (!str_contains(\, 'use DeviceAvailability_Trait')) {
        // Add use statement
        \ = preg_replace('/(class\s+[^{]+\s*\{)/', "\\n    use DeviceAvailability_Trait;", \);
        // Add require_once if not there
        if (!str_contains(\, 'Trait_DeviceAvailability.php')) {
            \ = str_replace('class ', "require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';\n\nclass ", \);
        }
        // Add to Create
        \ = preg_replace('/(public function Create\(\)\s*:[^\{]*\{.*?)(\n\s*parent::Create\(\);)/s', "\\\n        \->DA_RegisterAvailability(900);", \);
        // Add DA_ApplyPresentation to ApplyChanges
        \ = preg_replace('/(public function ApplyChanges\(\)\s*:[^\{]*\{.*?)(\n\s*parent::ApplyChanges\(\);)/s', "\\\n        \->DA_ApplyPresentation();\n        \->DA_SetAvailable(true);", \);
        
        file_put_contents(\, \);
        echo "Added DA to: \\n";
    }
}