<?php

declare(strict_types=1);

/**
 * SmartMonitorAlarm – DEPRECATED
 *
 * Dieses Modul ist veraltet und wird nicht mehr weiterentwickelt.
 * Das Monitoring wurde in SmartNotifier integriert.
 * Bitte die Instanz in der Symcon-Konsole löschen.
 *
 * @author Florian Graßinger
 * @url https://github.com/pinkerunicorn/
 */
class SmartMonitorAlarm extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        // Alte Properties beibehalten damit Symcon keine Fehler wirft
        $this->RegisterPropertyInteger('RegistryID', 0);
        $this->RegisterPropertyBoolean('SimulationMode', false);
        $this->RegisterPropertyInteger('EscalationTimeLvl2', 300);
        $this->RegisterPropertyInteger('EscalationTimeLvl3', 900);
        $this->RegisterPropertyInteger('TargetNotifier', 0);
        // Timer deaktiviert
        $this->RegisterTimer('EscalationTimer', 0, '');
        $this->RegisterTimer('StatusResetTimer', 0, '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        // Alle Subscriptions entfernen
        foreach ($this->GetMessageList() as $senderID => $msgs) {
            foreach ($msgs as $msg) {
                $this->UnregisterMessage($senderID, $msg);
            }
        }
        // Timer aus
        $this->SetTimerInterval('EscalationTimer', 0);
        $this->SetTimerInterval('StatusResetTimer', 0);
        $this->SetStatus(104); // Inaktiv
    }

    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                [
                    'type'    => 'Label',
                    'bold'    => true,
                    'color'   => 16711680,
                    'caption' => 'VERALTET – Dieses Modul ist nicht mehr aktiv!',
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'Das Alarm-Monitoring wurde in SmartNotifier integriert. Diese Instanz kann gelöscht werden.',
                ],
            ],
            'actions' => [],
        ]);
    }

    // Stub-Methoden damit bestehende Timer-Aufrufe keinen Fatal Error verursachen
    public function CheckEscalation(): void {}
    public function UpdateStatusVariables(): void {}
    public function RequestAction(string $Ident, mixed $Value): void {}
}