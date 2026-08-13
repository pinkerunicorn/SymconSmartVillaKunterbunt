<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';

class SmartApplianceSummary extends IPSModuleStrict
{
    use SmartLog_Trait;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('WasherID', 0);
        $this->RegisterPropertyInteger('DryerID', 0);
        $this->RegisterPropertyInteger('DishwasherID', 0);

        $this->RegisterVariableString('Summary', 'Hausgeräte Status', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Information'
        ], 1);
    }

    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                [
                    'type' => 'Label',
                    'caption' => 'Wähle hier deine Hausgeräte-Instanzen (Miele Washer, Miele Dryer, Imperial Dishwasher) aus.'
                ],
                [
                    'type' => 'SelectInstance',
                    'name' => 'WasherID',
                    'caption' => 'Waschmaschine'
                ],
                [
                    'type' => 'SelectInstance',
                    'name' => 'DryerID',
                    'caption' => 'Trockner'
                ],
                [
                    'type' => 'SelectInstance',
                    'name' => 'DishwasherID',
                    'caption' => 'Geschirrspüler'
                ]
            ]
        ]);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Alte Subscriptions entfernen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }

        $this->SubscribeDevice($this->ReadPropertyInteger('WasherID'));
        $this->SubscribeDevice($this->ReadPropertyInteger('DryerID'));
        $this->SubscribeDevice($this->ReadPropertyInteger('DishwasherID'));

        $this->UpdateSummary();
    }

    private function SubscribeDevice(int $instID): void
    {
        if ($instID > 1 && @IPS_InstanceExists($instID)) {
            $this->RegisterReference($instID);
            
            $statusVar = @IPS_GetObjectIDByIdent('StatusText', $instID);
            if ($statusVar !== false) {
                $this->RegisterMessage($statusVar, VM_UPDATE);
                $this->RegisterReference($statusVar);
            }
            
            $remVar = @IPS_GetObjectIDByIdent('RemainingTime', $instID);
            if ($remVar !== false) {
                $this->RegisterMessage($remVar, VM_UPDATE);
                $this->RegisterReference($remVar);
            }

            $powerVar = @IPS_GetObjectIDByIdent('PowerOn', $instID);
            if ($powerVar !== false) {
                $this->RegisterMessage($powerVar, VM_UPDATE);
                $this->RegisterReference($powerVar);
            }
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE) {
            $this->UpdateSummary();
        }
    }

    private function GetDeviceStatus(int $instID): string
    {
        if ($instID <= 1 || !@IPS_InstanceExists($instID)) {
            return 'Nicht konf.';
        }

        $powerVar = @IPS_GetObjectIDByIdent('PowerOn', $instID);
        if ($powerVar !== false && !GetValue($powerVar)) {
            return 'Aus';
        }

        $statusVar = @IPS_GetObjectIDByIdent('StatusText', $instID);
        $remVar = @IPS_GetObjectIDByIdent('RemainingTime', $instID);

        $status = 'Unbekannt';
        if ($statusVar !== false) {
            $status = (string)GetValue($statusVar);
        }

        if ($remVar !== false) {
            $rem = (int)GetValue($remVar);
            if ($rem > 0) {
                $status .= " ({$rem}m)";
            }
        }

        return $status;
    }

    public function UpdateSummary(): void
    {
        $washer = $this->GetDeviceStatus($this->ReadPropertyInteger('WasherID'));
        $dryer = $this->GetDeviceStatus($this->ReadPropertyInteger('DryerID'));
        $dish = $this->GetDeviceStatus($this->ReadPropertyInteger('DishwasherID'));

        $summary = "🧺 WM: $washer | 👕 TR: $dryer | 🍽️ GS: $dish";
        $this->SetValue('Summary', $summary);
    }
}