import os

file = 'SmartAlarmManager/module.php'
with open(file, 'r', encoding='utf-8') as f:
    content = f.read()

legacy_profile_str = """        if (!IPS_VariableProfileExists('SAM.SystemStatus')) {
            IPS_CreateVariableProfile('SAM.SystemStatus', 1);
        }
        IPS_SetVariableCustomProfile($this->GetIDForIdent('SystemStatus'), 'SAM.SystemStatus');
        IPS_SetVariableProfileAssociation('SAM.SystemStatus', 0, 'Alles OK', 'Ok', 0x00FF00);
        IPS_SetVariableProfileAssociation('SAM.SystemStatus', 1, 'Info / Hinweis', 'Information', 0xFFFF00);
        IPS_SetVariableProfileAssociation('SAM.SystemStatus', 2, 'ALARM!', 'Warning', 0xFF0000);
        IPS_SetVariableProfileAssociation('SAM.SystemStatus', 3, 'ESKALATION', 'Warning', 0xFF0000);
        IPS_SetVariableProfileAssociation('SAM.SystemStatus', 4, 'VOLLALARM', 'Alert', 0xFF0000);"""

new_profile_str = """        $options = json_encode([
            ['Value' => 0, 'Caption' => 'Alles OK', 'IconValue' => 'Ok', 'ColorDisplay' => 0x00FF00, 'ColorValue' => 0x00FF00, 'IconActive' => false, 'ColorActive' => false, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1],
            ['Value' => 1, 'Caption' => 'Info / Hinweis', 'IconValue' => 'Information', 'ColorDisplay' => 0xFFFF00, 'ColorValue' => 0xFFFF00, 'IconActive' => false, 'ColorActive' => false, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1],
            ['Value' => 2, 'Caption' => 'ALARM!', 'IconValue' => 'Warning', 'ColorDisplay' => 0xFF0000, 'ColorValue' => 0xFF0000, 'IconActive' => false, 'ColorActive' => false, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1],
            ['Value' => 3, 'Caption' => 'ESKALATION', 'IconValue' => 'Warning', 'ColorDisplay' => 0xFF0000, 'ColorValue' => 0xFF0000, 'IconActive' => false, 'ColorActive' => false, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1],
            ['Value' => 4, 'Caption' => 'VOLLALARM', 'IconValue' => 'Alert', 'ColorDisplay' => 0xFF0000, 'ColorValue' => 0xFF0000, 'IconActive' => false, 'ColorActive' => false, 'ContentColorActive' => false, 'ContentColorDisplay' => -1, 'ContentColorValue' => -1]
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('SystemStatus'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'DISPLAY_TYPE' => 0,
            'OPTIONS' => $options
        ]);
        IPS_SetVariableCustomProfile($this->GetIDForIdent('SystemStatus'), '');"""

content = content.replace(legacy_profile_str, new_profile_str)

sleep_str = """            if ($this->GetValue("SystemStatus") == 0) {
                $this->SetValue("SystemStatus", 1);
                IPS_Sleep(3000); 
                $this->UpdateStatusVariables(); 
            }"""

new_sleep_str = """            if ($this->GetValue("SystemStatus") == 0) {
                $this->SetValue("SystemStatus", 1);
                $this->SetTimerInterval("StatusResetTimer", 3000);
            }"""

content = content.replace(sleep_str, new_sleep_str)

timer_reg = """$this->RegisterTimer("DelayTimer", 0, 'SAM_HandleDelays($_IPS[\'TARGET\']);');"""
new_timer_reg = timer_reg + """\n        $this->RegisterTimer("StatusResetTimer", 0, 'SAM_UpdateStatusVariables($_IPS[\'TARGET\']); IPS_SetScriptTimer($_IPS[\'TARGET\'], "StatusResetTimer", 0);');"""
content = content.replace(timer_reg, new_timer_reg)

content = content.replace('private function UpdateStatusVariables()', 'public function UpdateStatusVariables()')

with open(file, 'w', encoding='utf-8', newline='\n') as f:
    f.write(content)
