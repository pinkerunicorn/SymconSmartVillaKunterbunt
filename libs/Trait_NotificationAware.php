<?php

declare(strict_types=1);

/**
 * Trait NotificationAware_Trait
 * 
 * Erlaubt es jedem IP-Symcon Modul, Nachrichten an den zentralen SmartNotifier zu senden,
 * ohne dessen Instanz-ID kennen zu müssen (Auto-Discovery).
 * 
 * @author Florian Graßinger
 * @url https://github.com/pinkerunicorn/
 */
if (!trait_exists('NotificationAware_Trait')) {
    trait NotificationAware_Trait
    {
        private const NOTIFIER_MODULE_GUID = '{B8A7F31D-E1D8-49A4-B9A9-5E9D5B4A1C8F}';

        /**
         * Prio 0 (Low): Hinweise, Info. Wird nachts gesammelt.
         */
        protected function NotifyLow(string $title, string $message): void
        {
            $this->SendToNotifier($title, $message, 0);
        }

        /**
         * Prio 1 (Medium): Warnungen, Erinnerungen. 
         */
        protected function NotifyMedium(string $title, string $message): void
        {
            $this->SendToNotifier($title, $message, 1);
        }

        /**
         * Prio 2 (High): Alarme, Kritische Warnungen. Unterbricht alles.
         */
        protected function NotifyHigh(string $title, string $message): void
        {
            $this->SendToNotifier($title, $message, 2);
        }

        private function SendToNotifier(string $title, string $message, int $priority): void
        {
            $instances = @IPS_GetInstanceListByModuleID(self::NOTIFIER_MODULE_GUID);
            if (is_array($instances) && count($instances) > 0) {
                $notifierID = $instances[0];
                try {
                    // Aufruf der öffentlichen API-Methode des Notifiers: NOTIFY_SendMessage
                    @IPS_RunScriptText("NOTIFY_SendMessage($notifierID, " . var_export($title, true) . ", " . var_export($message, true) . ", $priority);");
                } catch (Exception $e) {
                    if (method_exists($this, 'SLog')) {
                        $this->SLog('ERROR', 'Fehler beim Senden an SmartNotifier: ' . $e->getMessage());
                    }
                }
            } else {
                if (method_exists($this, 'SLog')) {
                    $this->SLog('WARNING', 'SmartNotifier Instanz nicht gefunden. Nachricht verworfen: ' . $message);
                }
            }
        }
    }
}
