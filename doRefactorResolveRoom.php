<?php
$f = 'SmartInventory/module.php';
$c = file_get_contents($f);

$resolveStart = strpos($c, 'private function resolveRoom');
if ($resolveStart !== false) {
    $fallbackStart = strpos($c, '// Fallback: Path', $resolveStart);
    $resolveEnd = strpos($c, "return '';", $fallbackStart);
    
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

        
EOF;
        $c = substr_replace($c, $resolveReplacement, $fallbackStart, $resolveEnd - $fallbackStart);
        file_put_contents($f, $c);
        echo "Replaced resolveRoom successfully!";
    }
}