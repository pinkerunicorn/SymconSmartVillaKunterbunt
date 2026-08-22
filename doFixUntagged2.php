<?php
$f = 'SmartInventory/module.php';
$c = file_get_contents($f);

$search = <<<EOF
        \$initialCatalogList = [];
        foreach (\$inventory as \$device) {
            foreach (\$device['variables'] as \$v) {
                \$parsed  = \$this->parseTag(\$v['tag']);
                if (\$parsed['disabled']) continue;

                \$tagBase = \$parsed['category'] !== '' ? 'SI:' . \$parsed['category'] . (\$parsed['subcategory'] !== '' ? ':' . \$parsed['subcategory'] : '') : '';
                \$normalStateStr = \$parsed['normalState'] !== null ? \$parsed['normalState']['value'] : '';
                
                \$initialCatalogList[] = [
                    'instanceName' => \$device['instanceName'],
                    'room'         => \$v['room'] ?? \$device['room'],
                    'tagBase'      => \$tagBase,
                    'normalState'  => \$normalStateStr,
                    'disabled'     => \$parsed['disabled'] ?? false,
                    'value'        => \$this->getFormattedValue(\$v['varID'] ?? 0),
                    'ObjectID'     => \$v['varID'],
                    'instanceID'   => \$device['instanceID'],
                ];
            }
        }
EOF;

$replace = <<<EOF
        \$initialCatalogList = [];
        foreach (\$inventory as \$device) {
            foreach (\$device['variables'] as \$v) {
                \$parsed  = \$this->parseTag(\$v['tag']);
                if (\$parsed['disabled']) continue;

                \$tagBase = \$parsed['category'] !== '' ? 'SI:' . \$parsed['category'] . (\$parsed['subcategory'] !== '' ? ':' . \$parsed['subcategory'] : '') : '';
                \$normalStateStr = \$parsed['normalState'] !== null ? \$parsed['normalState']['value'] : '';
                
                \$initialCatalogList[] = [
                    'instanceName' => \$device['instanceName'],
                    'room'         => \$v['room'] ?? \$device['room'],
                    'tagBase'      => \$tagBase,
                    'normalState'  => \$normalStateStr,
                    'disabled'     => \$parsed['disabled'] ?? false,
                    'value'        => \$this->getFormattedValue(\$v['varID'] ?? 0),
                    'ObjectID'     => \$v['varID'],
                    'instanceID'   => \$device['instanceID'],
                ];
            }
        }
        foreach (\$untagged as \$u) {
            \$ignoreVarID = @IPS_GetObjectIDByIdent('_SI_Ignore', \$u['instanceID']);
            \$isDisabled = (\$ignoreVarID !== false && GetValue(\$ignoreVarID));
            if (\$isDisabled) continue;
            
            \$initialCatalogList[] = [
                'instanceName' => \$u['instanceName'],
                'room'         => \$u['room'],
                'tagBase'      => '',
                'normalState'  => '',
                'disabled'     => false,
                'value'        => \$u['moduleName'] . ' (' . \$u['varCount'] . ' Variablen)',
                'ObjectID'     => \$u['instanceID'],
                'instanceID'   => \$u['instanceID'],
            ];
        }
EOF;

$c = str_replace($search, $replace, $c);
file_put_contents($f, $c);
echo "Replaced in GetConfigurationForm!\n";