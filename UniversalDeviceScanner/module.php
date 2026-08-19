<?php

declare(strict_types=1);

/**
 * UniversalDeviceScanner – DEPRECATED
 *
 * Dieses Modul ist veraltet und wird nicht mehr aktiv entwickelt.
 * Die Funktionalitaet (Geraete-Auto-Discovery) wird vollstaendig von
 * SmartInventory (SINV_Scan + SINV_ClassifyWithAI) uebernommen.
 *
 * Bestehende Instanzen koennen geloescht werden.
 */
class UniversalDeviceScanner extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetStatus(104); // Inaktiv
    }

    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                [
                    'type'    => 'Label',
                    'caption' => 'DEPRECATED: UniversalDeviceScanner wird nicht mehr benoetigt.',
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'Bitte diese Instanz loeschen. Geraete-Discovery erfolgt jetzt ueber SmartInventory (Scan + KI-Tagging).',
                ],
            ],
            'status' => [
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Veraltet – Instanz kann geloescht werden.'],
            ],
        ]);
    }
}
