<?php

declare(strict_types=1);

/**
 * HouseModeAware Trait — Einbinden in jedes Modul, das auf den Haus-Modus reagieren soll.
 *
 * Verwendung:
 *   require_once __DIR__ . '/../libs/Trait_HouseModeAware.php';
 *   class MeinModul extends IPSModuleStrict {
 *       use HouseModeAware_Trait;
 *       ...
 *       // In Create():
 *       $this->RegisterHouseModeAwareness();
 *
 *       // In ApplyChanges():
 *       $this->ApplyHouseModeSubscription();
 *
 *       // In MessageSink():
 *       if ($this->HandleHouseModeMessage($MessageID, $Data)) return;
 *
 *       // Implementieren:
 *       private function OnHouseModeChanged(int $mode, bool $isAbsence, bool $isSleep): void { ... }
 *   }
 */
trait HouseModeAware_Trait
{
    /**
     * Registriert die HouseModeVariableID Property.
     * Aufruf in Create().
     */
    private function RegisterHouseModeAwareness(): void
    {
        $this->RegisterPropertyInteger('HouseModeVariableID', 0);
    }

    /**
     * Registriert den VM_UPDATE Listener und cached den initialen Modus-Status.
     * Aufruf in ApplyChanges().
     */
    private function ApplyHouseModeSubscription(): void
    {
        $id = $this->ReadPropertyInteger('HouseModeVariableID');
        if ($id > 0 && @IPS_VariableExists($id)) {
            $this->RegisterMessage($id, VM_UPDATE);
            // Initialen Modus cachen
            $mode = (int)GetValue($id);
            [$isAbsence, $isSleep] = $this->ResolveModeFlags($mode);
            $this->SetBuffer('HMA_IsAbsent', $isAbsence ? '1' : '0');
            $this->SetBuffer('HMA_IsSleep',  $isSleep   ? '1' : '0');
        }
    }

    /**
     * Verarbeitet eingehende VM_UPDATE Nachrichten.
     * Aufruf in MessageSink(): if ($this->HandleHouseModeMessage($SenderID, $Message, $Data)) return;
     *
     * @return bool true wenn die Nachricht verarbeitet wurde
     */
    private function HandleHouseModeMessage(int $senderID, int $messageID, mixed $data): bool
    {
        $houseModeId = $this->ReadPropertyInteger('HouseModeVariableID');
        if ($houseModeId > 0 && $messageID === VM_UPDATE && $senderID === $houseModeId) {
            $mode = (int)GetValue($houseModeId);
            [$isAbsence, $isSleep] = $this->ResolveModeFlags($mode);
            // Flags cachen für IsAbsent()/IsSleep()
            $this->SetBuffer('HMA_IsAbsent', $isAbsence ? '1' : '0');
            $this->SetBuffer('HMA_IsSleep',  $isSleep   ? '1' : '0');
            $this->OnHouseModeChanged($mode, $isAbsence, $isSleep);
            return true;
        }
        return false;
    }

    /**
     * Liest IsAbsence/IsSleep-Flags aus dem SmartHomeControl HouseModes-Property.
     * Fallback: Modus 1+2 = Abwesenheit, Modus 5 = Schlafen.
     *
     * @return array{bool, bool} [$isAbsence, $isSleep]
     */
    private function ResolveModeFlags(int $mode): array
    {
        // GUID des SmartHomeControl Moduls
        $controlInstances = @IPS_GetInstanceListByModuleID('{4C8B2A6D-9E3F-4A7B-8C5D-1F6E2A3B7C4D}');
        if (is_array($controlInstances)) {
            foreach ($controlInstances as $instID) {
                $modesJson = @IPS_GetProperty($instID, 'HouseModes');
                if ($modesJson) {
                    $modes = json_decode($modesJson, true);
                    if (is_array($modes)) {
                        foreach ($modes as $m) {
                            if (($m['ModeID'] ?? -1) === $mode) {
                                return [
                                    (bool)($m['IsAbsence'] ?? false),
                                    (bool)($m['IsSleep']   ?? false)
                                ];
                            }
                        }
                    }
                }
            }
        }
        // Fallback
        return [in_array($mode, [1, 2]), $mode === 5];
    }

    /**
     * Gibt den aktuellen Haus-Modus zurück.
     */
    private function GetHouseMode(): int
    {
        $id = $this->ReadPropertyInteger('HouseModeVariableID');
        return ($id > 0 && @IPS_VariableExists($id)) ? (int)GetValue($id) : 0;
    }

    /**
     * Prüft ob das Haus im Abwesenheits-Modus ist (liest gecachten Buffer).
     */
    private function IsAbsent(): bool
    {
        return $this->GetBuffer('HMA_IsAbsent') === '1';
    }

    /**
     * Prüft ob das Haus im Schlaf-Modus ist (liest gecachten Buffer).
     */
    private function IsSleep(): bool
    {
        return $this->GetBuffer('HMA_IsSleep') === '1';
    }

    /**
     * Prüft ob jemand zu Hause und wach ist.
     */
    private function IsPresent(): bool
    {
        return !$this->IsAbsent() && !$this->IsSleep();
    }

    /**
     * Liest die verfügbaren Haus-Modi aus dem Variablen-Profil.
     * Für dynamische Dropdown-Listen in GetConfigurationForm().
     *
     * @return array<int, array{Value: int, Name: string}> Assoziationen aus dem Profil
     */
    private function GetAvailableHouseModes(): array
    {
        $id = $this->ReadPropertyInteger('HouseModeVariableID');
        if ($id <= 0 || !@IPS_VariableExists($id)) {
            return [];
        }

        $var = IPS_GetVariable($id);
        $profileName = $var['VariableCustomProfile'] ?: $var['VariableProfile'];
        if (!$profileName || !IPS_VariableProfileExists($profileName)) {
            return [];
        }

        $profile = IPS_GetVariableProfile($profileName);
        $modes = [];
        foreach ($profile['Associations'] as $assoc) {
            $modes[] = [
                'Value' => (int)$assoc['Value'],
                'Name'  => $assoc['Name']
            ];
        }
        return $modes;
    }

    /**
     * Callback — wird aufgerufen wenn sich der Haus-Modus ändert.
     * Muss vom einbindenden Modul implementiert werden.
     */
    abstract private function OnHouseModeChanged(int $mode, bool $isAbsence, bool $isSleep): void;
}
