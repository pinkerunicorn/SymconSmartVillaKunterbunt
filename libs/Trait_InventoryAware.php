<?php

declare(strict_types=1);

/**
 * InventoryAware Trait - Zentraler Service-Locator für das Smart Home Ökosystem.
 *
 * Bietet Multi-House-fähige Discovery für:
 * - SmartInventory (pro Haus, über SmartInventoryID Property)
 * - SmartController (pro Haus, über SmartInventory oder GUID-Fallback)
 * - SmartNotifier (global, GUID-Singleton)
 * - SmartLog (global, GUID-Singleton)
 *
 * Verwendung:
 *   require_once __DIR__ . '/../libs/Trait_InventoryAware.php';
 *   class MeinModul extends IPSModuleStrict {
 *       use InventoryAware_Trait;
 *       ...
 *       $invID = $this->SINV_GetInventoryID();
 *       $ctrlID = $this->SINV_GetControllerID();
 *       $notifyID = $this->SINV_GetNotifierID();
 *   }
 *
 * Voraussetzung: Das Modul MUSS RegisterPropertyInteger('SmartInventoryID', 0) in Create() aufrufen,
 * damit der Fallback bei mehreren Inventories funktioniert.
 * Bei genau einer Inventory im System greift der automatische Fallback.
 *
 * @author Florian Graßinger
 * @url https://github.com/pinkerunicorn/
 */
if (!trait_exists('InventoryAware_Trait')) {
    trait InventoryAware_Trait
    {
        /**
         * Gibt die konfigurierte SmartInventoryID zurück.
         * Fallback: Wenn nicht gesetzt und genau 1 Inventory existiert -> diese verwenden.
         *
         * @return int InstanceID der SmartInventory, oder 0 wenn nicht gefunden
         */
        private function SINV_GetInventoryID(): int
        {
            // 1. Explizit konfigurierte Property
            try {
                $configured = $this->ReadPropertyInteger('SmartInventoryID');
                if ($configured > 0 && @IPS_InstanceExists($configured)) {
                    return $configured;
                }
            } catch (\Throwable $e) {
                // Property existiert bei diesem Modul nicht
            }

            // 2. Fallback: Einzige Inventory im System (1-Haus-Betrieb)
            $ids = @IPS_GetInstanceListByModuleID('{8F4A2B1C-D3E5-4F6A-B7C8-9D0E1F2A3B4C}');
            if (is_array($ids) && count($ids) === 1) {
                return $ids[0];
            }

            return 0;
        }

        /**
         * Findet den SmartController dieses Hauses.
         * Fallback auf GUID-Lookup
         *
         * @return int InstanceID des SmartControllers, oder 0 wenn nicht gefunden
         */
        private function SINV_GetControllerID(): int
        {
            $ids = @IPS_GetInstanceListByModuleID('{460D7C60-0766-4534-BFD8-5920737B1845}');
            return (is_array($ids) && count($ids) === 1) ? $ids[0] : 0;
        }

        /**
         * Findet den globalen SmartNotifier per GUID (Singleton).
         *
         * @return int InstanceID des SmartNotifiers, oder 0 wenn nicht gefunden
         */
        private function SINV_GetNotifierID(): int
        {
            $ids = @IPS_GetInstanceListByModuleID('{B8A7F31D-E1D8-49A4-B9A9-5E9D5B4A1C8F}');
            return (is_array($ids) && count($ids) > 0) ? $ids[0] : 0;
        }

        /**
         * Findet das globale SmartLog per GUID (Singleton).
         *
         * @return int InstanceID des SmartLog, oder 0 wenn nicht gefunden
         */
        private function SINV_GetLogID(): int
        {
            $ids = @IPS_GetInstanceListByModuleID('{E4375147-F095-4B6F-9E06-F3A65EB8B635}');
            return (is_array($ids) && count($ids) > 0) ? $ids[0] : 0;
        }
    }
}
