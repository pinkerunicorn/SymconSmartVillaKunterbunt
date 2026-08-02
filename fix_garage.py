import os

file = 'SmartHomeGarage/module.php'
with open(file, 'r', encoding='utf-8') as f:
    content = f.read()

legacy_profile_str = """        if (!IPS_VariableProfileExists('Garage.DoorState')) {
            IPS_CreateVariableProfile('Garage.DoorState', 1);
        }
        IPS_SetVariableCustomProfile($this->GetIDForIdent('DoorState'), 'Garage.DoorState');
        IPS_SetVariableProfileAssociation('Garage.DoorState', 0, 'Zu', 'LockClosed', -1);
        IPS_SetVariableProfileAssociation('Garage.DoorState', 1, 'Auf', 'LockOpen', -1);
        IPS_SetVariableProfileAssociation('Garage.DoorState', 2, 'Fährt Auf...', 'ArrowUp', -1);
        IPS_SetVariableProfileAssociation('Garage.DoorState', 3, 'Fährt Zu...', 'ArrowDown', -1);
        IPS_SetVariableProfileAssociation('Garage.DoorState', 4, 'Teiloffen / Gestoppt', 'Warning', 0xFF8000);"""

new_profile_str = """        $options = json_encode([
            ['Value' => 0, 'Caption' => 'Zu', 'IconValue' => 'LockClosed', 'ColorDisplay' => -1, 'ColorValue' => -1, 'IconActive' => false, 'ColorActive' => false, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1],
            ['Value' => 1, 'Caption' => 'Auf', 'IconValue' => 'LockOpen', 'ColorDisplay' => -1, 'ColorValue' => -1, 'IconActive' => false, 'ColorActive' => false, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1],
            ['Value' => 2, 'Caption' => 'Fährt Auf...', 'IconValue' => 'ArrowUp', 'ColorDisplay' => -1, 'ColorValue' => -1, 'IconActive' => false, 'ColorActive' => false, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1],
            ['Value' => 3, 'Caption' => 'Fährt Zu...', 'IconValue' => 'ArrowDown', 'ColorDisplay' => -1, 'ColorValue' => -1, 'IconActive' => false, 'ColorActive' => false, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1],
            ['Value' => 4, 'Caption' => 'Teiloffen / Gestoppt', 'IconValue' => 'Warning', 'ColorDisplay' => 0xFF8000, 'ColorValue' => 0xFF8000, 'IconActive' => false, 'ColorActive' => false, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1]
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('DoorState'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'DISPLAY_TYPE' => 0,
            'OPTIONS' => $options
        ]);
        IPS_SetVariableCustomProfile($this->GetIDForIdent('DoorState'), '');"""

content = content.replace(legacy_profile_str, new_profile_str)

with open(file, 'w', encoding='utf-8', newline='\n') as f:
    f.write(content)
