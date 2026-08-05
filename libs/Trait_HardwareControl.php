<?php

declare(strict_types=1);

/**
 * HardwareControl Trait — Zentraler Wrapper für Schaltbefehle (Dry Run / Simulation).
 *
 * Verwendung:
 *   require_once __DIR__ . '/../libs/Trait_SmartLog.php';
 *   require_once __DIR__ . '/../libs/Trait_HardwareControl.php';
 *   class MeinModul extends IPSModuleStrict {
 *       use SmartLog_Trait;
 *       use HardwareControl_Trait;
 *       ...
 *       $this->safeRequestAction($id, $wert);
 *   }
 */
if (!trait_exists('HardwareControl_Trait')) {
    trait HardwareControl_Trait
    {
        /**
         * Führt ein RequestAction sicher aus, es sei denn der Simulationsmodus ist aktiv.
         *
         * @param int $id Die VariableID des Aktors
         * @param mixed $value Der zu setzende Wert
         * @return bool
         */
        protected function safeRequestAction(int $id, mixed $value): bool
        {
            // 1. Prüfe globalen Simulationsmodus im SmartController
            $globalSimulation = false;
            $ctrlInstances = @IPS_GetInstanceListByModuleID('{460D7C60-0766-4534-BFD8-5920737B1845}');
            if (is_array($ctrlInstances) && count($ctrlInstances) > 0) {
                $ctrlId = $ctrlInstances[0];
                $varId = @IPS_GetObjectIDByIdent('GlobalSimulationMode', $ctrlId);
                if ($varId !== false) {
                    $globalSimulation = (bool)GetValue($varId);
                }
            }

            // 2. Prüfe lokalen Simulationsmodus im aktuellen Modul
            $localSimulation = false;
            try {
                $localSimulation = $this->ReadPropertyBoolean('SimulationMode');
            } catch (\Throwable $e) {
                // Property existiert bei diesem Modul (noch) nicht
            }

            // 3. Auswertung
            if ($globalSimulation || $localSimulation) {
                $modeStr = $globalSimulation ? 'GLOBAL' : 'LOKAL';
                $name = @IPS_GetName($id) ?: 'Unbekannt';
                $valStr = is_bool($value) ? ($value ? 'An' : 'Aus') : (string)$value;
                
                if (method_exists($this, 'SLogInfo')) {
                    $this->SLogInfo("🛠️ SIMULATION ($modeStr)", "Schaltbefehl übersprungen: $name (#$id) -> $valStr");
                }
                
                if (method_exists($this, 'SendDebug')) {
                    $this->SendDebug("Simulation", "RequestAction($id, $valStr) übersprungen.", 0);
                }
                return true; // Im Simulationsmodus tun wir so, als wäre es erfolgreich gewesen
            }

            // 4. Echter Schaltbefehl
            if (!@IPS_VariableExists($id)) {
                if (method_exists($this, 'SLogWarning')) {
                    $this->SLogWarning("RequestAction failed", "Variable #$id existiert nicht.");
                }
                return false;
            }

            $res = @RequestAction($id, $value);
            if (!$res) {
                if (method_exists($this, 'SLogWarning')) {
                    $this->SLogWarning("RequestAction failed", "RequestAction($id, " . var_export($value, true) . ") lieferte false.");
                }
            }
            return $res;
        }
    }
}
