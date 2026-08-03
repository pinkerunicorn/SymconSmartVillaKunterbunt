<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_CentralStateAware.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class SmartHomeSecurity extends IPSModuleStrict
{
    use SmartLog_Trait;
    use CentralStateAware_Trait;
    use DeviceAvailability_Trait;

    public function Create(): void
    {
        parent::Create();
        
        $this->DA_RegisterAvailability(900);

        $this->RegisterPropertyString('DoorVariables', '[]');
        $this->RegisterPropertyString('WindowVariables', '[]');



        // Variablen für den WebFront-Status
        $this->RegisterVariableInteger('OpenWindowsCount', '🚪 Offene Fenster / Türen (Zähler)', [
            'PRESENTATION'   => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'           => 'Window',
            'SUFFIX'         => ' offen'
        ], 1);
        $this->RegisterVariableString('OpenWindowsList', '📝 Offene Fenster / Türen (Namen)', [
            'PRESENTATION'   => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'           => 'Information'
        ], 2);
        $this->RegisterVariableBoolean('AlarmWindowsOpenDuringAbsence', 'Alarm: Fenster/Tür offen bei Abwesenheit', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'Warning'
        ], 3);
        $this->EnableAction('AlarmWindowsOpenDuringAbsence');
        
        $this->RegisterVariableString('VestaboardStatus', 'Kurz-Status (Vestaboard)', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Information'
        ], 4);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->DA_ApplyPresentation();

        $this->SubscribeToCentralStates(['PresenceMode', 'ActivityMode']);
        // --- Auto-generated References ---
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $list_DoorVariables = json_decode($this->ReadPropertyString('DoorVariables'), true);
        if (is_array($list_DoorVariables)) {
            foreach ($list_DoorVariables as $item) {
                $vid = $item['SensorVariableID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
                $vid = $item['VariableID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        $list_WindowVariables = json_decode($this->ReadPropertyString('WindowVariables'), true);
        if (is_array($list_WindowVariables)) {
            foreach ($list_WindowVariables as $item) {
                $vid = $item['VariableID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        // ---------------------------------

        // VestaboardStatus wird nun in Create() über RegisterVariable verwaltet

        $windowVars = json_decode($this->ReadPropertyString('WindowVariables'), true);
        if (is_array($windowVars)) {
            foreach ($windowVars as $win) {
                $id = $win['VariableID'];
                if ($id > 0 && IPS_VariableExists($id)) {
                    $this->RegisterMessage($id, VM_UPDATE);
                }
            }
        }
        $doorVars = json_decode($this->ReadPropertyString('DoorVariables'), true);
        if (is_array($doorVars)) {
            foreach ($doorVars as $door) {
                if (isset($door['SensorVariableID'])) {
                    $id = $door['SensorVariableID'];
                    if ($id > 0 && IPS_VariableExists($id)) {
                        $this->RegisterMessage($id, VM_UPDATE);
                    }
                }
            }
        }
        $this->CalculateOpenWindows();


        $this->SetStatus(102);
        $this->DA_SetAvailable(true);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($this->HandleCentralStateMessage($SenderID, $Message, $Data)) return;
        if ($Message == VM_UPDATE) {
            $this->CalculateOpenWindows();
        }
    }

    private function CalculateOpenWindows(): void
    {
        $windowVars = json_decode($this->ReadPropertyString('WindowVariables'), true);
        $count = 0;
        $openNames = [];
        if (is_array($windowVars)) {
            foreach ($windowVars as $win) {
                $id = $win['VariableID'];
                if ($id > 0 && IPS_VariableExists($id)) {
                    $currentVal = GetValue($id);
                    $checkVal = $win['ClosedValue'];
                    
                    $isClosed = false;
                    if (is_bool($currentVal)) {
                        $targetBool = ($checkVal === 'true' || $checkVal === '1' || strtolower($checkVal) === 'wahr');
                        $isClosed = ($currentVal === $targetBool);
                    } else if (is_int($currentVal)) {
                        $isClosed = ($currentVal === (int)$checkVal);
                    } else if (is_float($currentVal)) {
                        $isClosed = ($currentVal === (float)$checkVal);
                    } else if (is_string($currentVal)) {
                        $isClosed = (strtolower(trim($currentVal)) === strtolower(trim($checkVal)));
                    } else {
                        $isClosed = ($currentVal == $checkVal);
                    }
                    
                    if (!$isClosed) {
                        $count++;
                        $name = isset($win['Name']) && $win['Name'] != '' ? $win['Name'] : IPS_GetName($id);
                        $openNames[] = $name;
                    }
                }
            }
        }

        $doorVars = json_decode($this->ReadPropertyString('DoorVariables'), true);
        if (is_array($doorVars)) {
            foreach ($doorVars as $door) {
                if (isset($door['SensorVariableID'])) {
                    $id = $door['SensorVariableID'];
                    if ($id > 0 && IPS_VariableExists($id)) {
                        $currentVal = GetValue($id);
                        $checkVal = isset($door['ClosedValue']) ? $door['ClosedValue'] : 'false';
                        
                        $isClosed = false;
                        if (is_bool($currentVal)) {
                            $targetBool = ($checkVal === 'true' || $checkVal === '1' || strtolower($checkVal) === 'wahr');
                            $isClosed = ($currentVal === $targetBool);
                        } else if (is_int($currentVal)) {
                            $isClosed = ($currentVal === (int)$checkVal);
                        } else if (is_float($currentVal)) {
                            $isClosed = ($currentVal === (float)$checkVal);
                        } else if (is_string($currentVal)) {
                            $isClosed = (strtolower(trim($currentVal)) === strtolower(trim($checkVal)));
                        } else {
                            $isClosed = ($currentVal == $checkVal);
                        }
                        
                        if (!$isClosed) {
                            $count++;
                            $name = isset($door['Name']) && $door['Name'] != '' ? $door['Name'] : IPS_GetName($id);
                            $openNames[] = $name;
                        }
                    }
                }
            }
        }

        $this->SetValueIfChanged('OpenWindowsCount', $count);
        
        if ($count == 0) {
            $this->SetValueIfChanged('OpenWindowsList', 'Alle geschlossen');
            $this->SetValueIfChanged('VestaboardStatus', '');
        } else {
            $namesStr = implode(", ", $openNames);
            $this->SetValueIfChanged('OpenWindowsList', $namesStr);
            $this->SetValueIfChanged('VestaboardStatus', $count . ' offen');
        }
    }

    public function GetOpenWindows(): array
    {
        $this->CalculateOpenWindows();
        $count = GetValue($this->GetIDForIdent('OpenWindowsCount'));
        if ($count > 0) {
            $list = GetValue($this->GetIDForIdent('OpenWindowsList'));
            return explode(", ", $list);
        }
        return [];
    }

    protected function OnCentralStateChanged(string $stateName, mixed $newValue): void
    {
        $this->updateSecurityMode();
    }

    private function updateSecurityMode(): void
    {
        $isAbsence = $this->IsAway() || $this->IsVacation();

        // Alarm Check
        if ($isAbsence) {
            $this->CalculateOpenWindows();
            if ($this->GetValue('OpenWindowsCount') > 0) {
                $this->SetValueIfChanged('AlarmWindowsOpenDuringAbsence', true);
                $this->SLogWarning( 'Alarm: Fenster/Türen offen!', "Liste: " . $this->GetValue('OpenWindowsList'));
            }
        }
    }



    private function SetValueIfChanged(string $Ident, $Value): void
    {
        $id = $this->GetIDForIdent($Ident);
        if (GetValue($id) !== $Value) {
            $this->SetValue($Ident, $Value);
        }
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "List",
            "name": "DoorVariables",
            "caption": "Türen (Kontakte)",
            "rowCount": 10,
            "add": true,
            "delete": true,
            "changeOrder": true,
            "columns": [
                {
                    "caption": "Name",
                    "name": "Name",
                    "width": "150px",
                    "add": "",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Tür-Kontakt (Sensor)",
                    "name": "SensorVariableID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Wert f. Geschlossen",
                    "name": "ClosedValue",
                    "width": "150px",
                    "add": "false",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                }
            ]
        },
        {
            "type": "List",
            "name": "WindowVariables",
            "caption": "Fenster-Kontakte (Sicherheit)",
            "rowCount": 15,
            "add": true,
            "delete": true,
            "changeOrder": true,
            "columns": [
                {
                    "caption": "Name (für Meldungen)",
                    "name": "Name",
                    "width": "150px",
                    "add": "",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Variable",
                    "name": "VariableID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Wert für Geschlossen",
                    "name": "ClosedValue",
                    "width": "150px",
                    "add": "false",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                }
            ]
        }
    ]
}
EOT;
    }
}


