<?php
$f = 'SmartInventory/module.php';
$c = file_get_contents($f);

$search = <<<EOF
                    if (\$match) {
                        \$normalStateStr = \$parsed['normalState'] !== null ? \$parsed['normalState']['value'] : '';
                        \$list[] = [
                            'instanceName' => \$device['instanceName'],
                            'room'         => \$v['room'] ?? \$device['room'],
                            'tagBase'      => \$tagBase,
                            'normalState'  => \$normalStateStr,
                            'disabled'     => \$parsed['disabled'] ?? false,
                            'value'        => \$this->getFormattedValue(\$v['varID']),
                            'ObjectID'     => \$v['varID'],
                            'instanceID'   => \$device['instanceID'],
                        ];
                    }
                }
            }
        }

        if (\$room !== 'all') {
EOF;

$replace = <<<EOF
                    if (\$match) {
                        \$normalStateStr = \$parsed['normalState'] !== null ? \$parsed['normalState']['value'] : '';
                        \$list[] = [
                            'instanceName' => \$device['instanceName'],
                            'room'         => \$v['room'] ?? \$device['room'],
                            'tagBase'      => \$tagBase,
                            'normalState'  => \$normalStateStr,
                            'disabled'     => \$parsed['disabled'] ?? false,
                            'value'        => \$this->getFormattedValue(\$v['varID']),
                            'ObjectID'     => \$v['varID'],
                            'instanceID'   => \$device['instanceID'],
                        ];
                    }
                }
            }
            if (\$category === 'all') {
                foreach (\$untagged as \$u) {
                    \$ignoreVarID = @IPS_GetObjectIDByIdent('_SI_Ignore', \$u['instanceID']);
                    \$isDisabled = (\$ignoreVarID !== false && GetValue(\$ignoreVarID));
                    if (\$isDisabled && !\$showDisabled) continue;
                    
                    \$list[] = [
                        'instanceName' => \$u['instanceName'],
                        'room'         => \$u['room'],
                        'tagBase'      => '',
                        'normalState'  => '',
                        'disabled'     => \$isDisabled,
                        'value'        => \$u['moduleName'] . ' (' . \$u['varCount'] . ' Variablen)',
                        'ObjectID'     => \$u['instanceID'],
                        'instanceID'   => \$u['instanceID'],
                    ];
                }
            }
        }

        if (\$room !== 'all') {
EOF;

$c = str_replace($search, $replace, $c);
file_put_contents($f, $c);
echo "Replaced in UpdateCatalogList!\n";