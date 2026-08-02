import os

shading_file = 'SmartHomeShading/module.php'
with open(shading_file, 'r', encoding='utf-8') as f:
    content = f.read()

content = content.replace('private function CalculateBlindState(array $blind, bool $isNight, bool $isHotAndBright, float $azimuth): ?int', 'private function CalculateBlindState(array $blind, bool $isNight, bool $isHotAndBright, float $azimuth): ?float')
content = content.replace('return (int) $targetValueStr;', 'return (float) $targetValueStr;')
content = content.replace('$targetValueInt = $this->CalculateBlindState', '$targetValueFloat = $this->CalculateBlindState')
content = content.replace('if ($targetValueInt == (int)($blind[\'ValueClose\'] ?? "1")) {', 'if ($targetValueFloat == (float)($blind[\'ValueClose\'] ?? "1")) {')
content = content.replace('} elseif ($targetValueInt == (int)($blind[\'ValueShade\'] ?? "0.1")) {', '} elseif ($targetValueFloat == (float)($blind[\'ValueShade\'] ?? "0.1")) {')
content = content.replace('} elseif ($targetValueInt == (int)($blind[\'ValueVentilate\'] ?? "0.3")) {', '} elseif ($targetValueFloat == (float)($blind[\'ValueVentilate\'] ?? "0.3")) {')

with open(shading_file, 'w', encoding='utf-8', newline='\n') as f:
    f.write(content)
