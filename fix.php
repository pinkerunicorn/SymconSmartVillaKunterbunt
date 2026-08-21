<?php
$path = 'SmartInventory/module.php';
$content = file_get_contents($path);
$content = str_replace("\r\n", "\n", $content);

$oldScan = <<<'EOD'
         = [];
        foreach ( as ) {
             = [];
            foreach (['variables'] as ) {
                if (['disabled']) {
                    continue;
                }
                [] = [
                    'v' => ['varID'],                // varID
                    'c' => ['category'],             // category
                    's' => ['subcategory'],          // subcategory
                    'n' => ['normalState'],          // normalState
                    'r' => ['room'] ?? '',          // room
                    'd' => ['disabled'],            // disabled
                    't' => ['type'],                 // type
                    'u' => ['lastUpdatedTS'],        // lastUpdatedTS
                ];
            }
            if (count() === 0) {
                continue;
            }
            [] = [
                'i' => ['instanceID'],      // instanceID
                'n' => ['instanceName'],    // instanceName
                'r' => ['room'],            // room
                'h' => ['health'],          // health status
                'v' => ,                  // variables
            ];
        }
EOD;

$newScan = <<<'EOD'
         = ->optimizeInventory();
EOD;

$content = str_replace(str_replace("\r\n", "\n", $oldScan), str_replace("\r\n", "\n", $newScan), $content);

$oldUpdate = <<<'EOD'
        ->SetBuffer('Inventory', json_encode());
        file_put_contents('/tmp/inv.json', json_encode());
EOD;

$newUpdate = <<<'EOD'
         = ->optimizeInventory();
        ->SetBuffer('Inventory', json_encode());
EOD;

$content = str_replace(str_replace("\r\n", "\n", $oldUpdate), str_replace("\r\n", "\n", $newUpdate), $content);

$oldResolve = <<<'EOD'
    private function resolveRoom(int ): string
EOD;

$newResolve = <<<'EOD'
    private function optimizeInventory(array ): array
    {
         = [];
        foreach ( as ) {
             = [];
            foreach (['variables'] as ) {
                if (['disabled']) {
                    continue;
                }
                [] = [
                    'v' => ['varID'],                // varID
                    'c' => ['category'],             // category
                    's' => ['subcategory'],          // subcategory
                    'n' => ['normalState'],          // normalState
                    'r' => ['room'] ?? '',          // room
                    'd' => ['disabled'],            // disabled
                    't' => ['type'],                 // type
                    'u' => ['lastUpdatedTS'] ?? 0,        // lastUpdatedTS
                ];
            }
            if (count() === 0) {
                continue;
            }
            [] = [
                'i' => ['instanceID'],      // instanceID
                'n' => ['instanceName'],    // instanceName
                'r' => ['room'],            // room
                'h' => ['health'] ?? 'healthy',          // health status
                'v' => ,                  // variables
            ];
        }
        return ;
    }

    private function resolveRoom(int ): string
EOD;

$content = str_replace(str_replace("\r\n", "\n", $oldResolve), str_replace("\r\n", "\n", $newResolve), $content);

file_put_contents($path, $content);