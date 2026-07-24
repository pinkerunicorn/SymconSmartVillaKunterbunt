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
    // Standard Mode-Konstanten
    private const MODE_PRESENT  = 0;  // Anwesenheit
    private const MODE_ABSENT   = 1;  // Abwesenheit
    private const MODE_VACATION = 2;  // Urlaub
    private const MODE_PARTY    = 3;  // Party
    private const MODE_CINEMA   = 4;  // Heimkino
    private const MODE_SLEEP    = 5;  // Schlafen

    /**
     * Registriert die HouseModeVariableID Property.
     * Aufruf in Create().
     */
    private function RegisterHouseModeAwareness(): void
    {
        $this->RegisterPropertyInteger('HouseModeVariableID', 0);
    }

    /**
     * Registriert den VM_UPDATE Listener für die HouseMode-Variable.
     * Aufruf in ApplyChanges().
     */
    private function ApplyHouseModeSubscription(): void
    {
        $id = $this->ReadPropertyInteger('HouseModeVariableID');
        if ($id > 0 && @IPS_VariableExists($id)) {
            $this->RegisterMessage($id, VM_UPDATE);
        }
    }

    /**
     * Verarbeitet eingehende VM_UPDATE Nachrichten.
     * Aufruf in MessageSink(): if ($this->HandleHouseModeMessage($MessageID, $Data)) return;
     *
     * @return bool true wenn die Nachricht verarbeitet wurde
     */
    private function HandleHouseModeMessage(int $senderID, int $messageID, mixed $data): bool
    {
        $houseModeId = $this->ReadPropertyInteger('HouseModeVariableID');
        if ($houseModeId > 0 && $messageID === VM_UPDATE && $senderID === $houseModeId) {
            $mode = (int)GetValue($houseModeId);
            $this->OnHouseModeChanged($mode, $this->IsAbsent($mode), $this->IsSleep($mode));
            return true;
        }
        return false;
    }

    /**
     * Gibt den aktuellen Haus-Modus zurück.
     */
    private function GetHouseMode(): int
    {
        $id = $this->ReadPropertyInteger('HouseModeVariableID');
        return ($id > 0 && @IPS_VariableExists($id)) ? (int)GetValue($id) : self::MODE_PRESENT;
    }

    /**
     * Prüft ob das Haus im Abwesenheits-Modus ist (Abwesenheit oder Urlaub).
     */
    private function IsAbsent(?int $mode = null): bool
    {
        $m = $mode ?? $this->GetHouseMode();
        return in_array($m, [self::MODE_ABSENT, self::MODE_VACATION]);
    }

    /**
     * Prüft ob das Haus im Schlaf-Modus ist.
     */
    private function IsSleep(?int $mode = null): bool
    {
        return ($mode ?? $this->GetHouseMode()) === self::MODE_SLEEP;
    }

    /**
     * Prüft ob jemand zu Hause und wach ist.
     */
    private function IsPresent(?int $mode = null): bool
    {
        return !$this->IsAbsent($mode) && !$this->IsSleep($mode);
    }

    /**
     * Liest die verfügbaren Haus-Modi aus dem Variablen-Profil.
     * Für dynamische Dropdown-Listen in form.json.
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
