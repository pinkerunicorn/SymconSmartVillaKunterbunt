<?php

declare(strict_types=1);

/**
 * SmartMonitorDevice – DEPRECATED
 *
 * Dieses Modul ist veraltet und wird nicht mehr weiterentwickelt.
 * Das Device-Monitoring wurde in SmartNotifier integriert.
 * Bitte die Instanz in der Symcon-Konsole löschen.
 *
 * @author Florian Graßinger
 * @url https://github.com/pinkerunicorn/
 */
class SmartMonitorDevice extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        $this->RegisterTimer('HealthCheckTimer', 0, '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        foreach ($this->GetMessageList() as $senderID => $msgs) {
            foreach ($msgs as $msg) {
                $this->UnregisterMessage($senderID, $msg);
            }
        }
        $this->SetTimerInterval('HealthCheckTimer', 0);
        $this->SetStatus(104);
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
                    'caption' => 'Das Device-Monitoring wurde in SmartNotifier integriert. Diese Instanz kann gelöscht werden.',
                ],
            ],
            'actions' => [],
        ]);
    }

    public function CheckHealth(bool $triggerNotification = false): void {}
    public function GetVisualizationTile(): string { return ''; }
    public function RequestAction(string $Ident, mixed $Value): void {}
}
