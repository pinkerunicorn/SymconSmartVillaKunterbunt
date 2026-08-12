<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_HardwareControl.php';
require_once __DIR__ . '/../libs/Trait_CentralStateAware.php';

class SmartRoomLighting extends IPSModuleStrict
{
    use SmartLog_Trait;
    use HardwareControl_Trait;
    use CentralStateAware_Trait;

    // Maximum number of concurrent timers
    private const MAX_TIMERS = 20;

    // SmartSequencer module GUID
    private const SEQUENCER_GUID = '{9F8E7D6C-5B4A-3C2D-1E0F-A1B2C3D4E5F6}';

    public function Create(): void
    {
        parent::Create();

        // === Properties ===
        // Device Registry integration (optional)
        $this->RegisterPropertyInteger('RegistryID', 0);
        // Scene mode: map a name to a SmartSequencer instance
        $this->RegisterPropertyString('Scenes', '[]');
        // Direct scene lamps: map a scene name to a set of lamps
        $this->RegisterPropertyString('SceneDevices', '[]');
        $this->RegisterPropertyString('MotionTriggers', '[]');
        $this->RegisterPropertyString('SwitchTriggers', '[]');
        $this->RegisterPropertyString('DoorRules', '[]');
        $this->RegisterPropertyString('TwilightRules', '[]');
        $this->RegisterPropertyString('SyncRules', '[]');
        $this->RegisterPropertyInteger('SunsetVariableID', 0);
        $this->RegisterPropertyInteger('SunriseVariableID', 0);
        $this->RegisterPropertyString('MasterOnScene', '');

        // === State ===
        $this->RegisterVariableBoolean('MasterSwitch', 'Raum Ein/Aus', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'Light'
        ], 1);
        $this->EnableAction('MasterSwitch');

        // === Timers ===
        $this->RegisterTimer('DailyTwilightRecalc', 0, 'SRL_CalculateTwilightTimers($_IPS[\'TARGET\']);');

        for ($i = 0; $i < self::MAX_TIMERS; $i++) {
            $this->RegisterTimer("MotionOffTimer_$i", 0, 'SRL_ProcessMotionOff($_IPS[\'TARGET\'], ' . $i . ');');
            $this->RegisterTimer("DoorOffTimer_$i", 0, 'SRL_ProcessDoorOff($_IPS[\'TARGET\'], ' . $i . ');');
            $this->RegisterTimer("TwilightTimer_$i", 0, 'SRL_ProcessTwilightTrigger($_IPS[\'TARGET\'], ' . $i . ');');
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Subscribe to central house state
        $this->SubscribeToCentralStates(['PresenceMode', 'ActivityMode']);

        // --- References ---
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }

        $this->registerPropertyReference('RegistryID');
        $this->registerPropertyReference('SunsetVariableID');
        $this->registerPropertyReference('SunriseVariableID');
        $this->registerListReferences('SceneDevices', ['TargetID', 'ManualTargetID']);
        $this->registerListReferences('MotionTriggers', ['SensorID', 'ManualSensorID', 'LuxSensorID']);
        $this->registerListReferences('SwitchTriggers', ['SwitchID', 'ManualSwitchID']);
        $this->registerListReferences('DoorRules', ['DoorSensorID', 'ManualDoorVariableID', 'LuxSensorID']);
        $this->registerListReferences('TwilightRules', ['TargetLightID']);
        $this->registerListReferences('SyncRules', ['MasterVariableID', 'TargetLightID']);

        // Register Sequencer instance references from Scenes
        $scenes = $this->safeJsonDecode($this->ReadPropertyString('Scenes'), true) ?: [];
        foreach ($scenes as $scene) {
            $seqId = $scene['SequencerID'] ?? 0;
            if ($seqId > 1 && @IPS_ObjectExists($seqId)) {
                $this->RegisterReference($seqId);
            }
            $offSeqId = $scene['OffSequencerID'] ?? 0;
            if ($offSeqId > 1 && @IPS_ObjectExists($offSeqId)) {
                $this->RegisterReference($offSeqId);
            }
        }

        // --- Cache property data in buffers ---
        $this->SetBuffer('ScenesCache', $this->ReadPropertyString('Scenes'));
        $this->SetBuffer('SceneDevicesCache', $this->ReadPropertyString('SceneDevices'));
        $this->SetBuffer('MotionTriggersCache', $this->ReadPropertyString('MotionTriggers'));
        $this->SetBuffer('SwitchTriggersCache', $this->ReadPropertyString('SwitchTriggers'));
        $this->SetBuffer('DoorRulesCache', $this->ReadPropertyString('DoorRules'));
        $this->SetBuffer('TwilightRulesCache', $this->ReadPropertyString('TwilightRules'));
        $this->SetBuffer('SyncRulesCache', $this->ReadPropertyString('SyncRules'));
        
        // -------------------------------------------------------------
        // Cache Registry Devices
        // -------------------------------------------------------------
        $switchDefaults = [];
        $deviceMap = [];
        $regId = (int)@$this->ReadPropertyInteger('RegistryID');
        
        if ($regId > 0 && @IPS_InstanceExists($regId)) {
            // Wall Switches
            $devices = @SDR_GetDevicesByType($regId, 'DevicesWallSwitch');
            if (is_array($devices)) {
                foreach ($devices as $dev) {
                    $key = ($dev['room'] ?? '') . '::' . ($dev['name'] ?? 'Unbenannt');
                    $varId = (int)($dev['OnOff_VarID'] ?? 0);
                    if ($varId > 0) $deviceMap[$key] = $varId;
                    
                    $closedVal = trim((string)($dev['ClosedValue'] ?? ''));
                    if ($varId > 0 && $closedVal !== '') {
                        $switchDefaults[$varId] = $closedVal;
                    }
                }
            }
            
            // Motion Sensors
            $devices = @SDR_GetDevicesByType($regId, 'DevicesMotionSensor');
            if (is_array($devices)) {
                foreach ($devices as $dev) {
                    $key = ($dev['room'] ?? '') . '::' . ($dev['name'] ?? 'Unbenannt');
                    $varId = (int)($dev['Status_VarID'] ?? 0);
                    if ($varId > 0) $deviceMap[$key] = $varId;
                }
            }

            // Contact Sensors
            $devices = @SDR_GetDevicesByType($regId, 'DevicesContactSensor');
            if (is_array($devices)) {
                foreach ($devices as $dev) {
                    $key = ($dev['room'] ?? '') . '::' . ($dev['name'] ?? 'Unbenannt');
                    $varId = (int)($dev['Status_VarID'] ?? 0);
                    if ($varId > 0) $deviceMap[$key] = $varId;
                }
            }
            
            // Lights
            $lightTypes = ['DevicesLight', 'DevicesLightDimmer', 'DevicesLightColor'];
            foreach ($lightTypes as $type) {
                $devices = @SDR_GetDevicesByType($regId, $type);
                if (is_array($devices)) {
                    foreach ($devices as $dev) {
                        $baseKey = ($dev['room'] ?? '') . '::' . ($dev['name'] ?? 'Unbenannt');
                        if (!empty($dev['ColorRGB_VarID'])) $deviceMap[$baseKey . '::Color'] = (int)$dev['ColorRGB_VarID'];
                        if (!empty($dev['Brightness_VarID'])) $deviceMap[$baseKey . '::Dimmer'] = (int)$dev['Brightness_VarID'];
                        if (!empty($dev['OnOff_VarID'])) $deviceMap[$baseKey . '::Switch'] = (int)$dev['OnOff_VarID'];
                    }
                }
            }
        }
        $this->SetBuffer('SwitchDefaultsCache', json_encode($switchDefaults));
        $this->SetBuffer('DeviceMapCache', json_encode($deviceMap));

        // Reset manual override
        $this->SetBuffer('ManualOverride', 'false');

        // --- Unregister all messages ---
        foreach ($this->GetMessageList() as $senderID => $senderMessages) {
            foreach ($senderMessages as $messageID) {
                $this->UnregisterMessage($senderID, $messageID);
            }
        }

        // --- Register sensors ---
        $this->registerSensorMessages('MotionTriggers', 'SensorID', 'ManualSensorID');
        $this->registerSensorMessages('SwitchTriggers', 'SwitchID', 'ManualSwitchID');
        $this->registerSensorMessages('DoorRules', 'DoorSensorID', 'ManualDoorVariableID');
        $this->registerSensorMessages('SyncRules', 'MasterVariableID');

        // --- Twilight Timers ---
        $this->CalculateTwilightTimers();

        // Timer runs every night at 00:05
        $now = time();
        $nextMidnight = strtotime('tomorrow 00:05');
        $this->SetTimerInterval('DailyTwilightRecalc', ($nextMidnight - $now) * 1000);

        // --- Dynamic Scene Switches ---
        $allScenes = $this->getAllSceneNames();
        $sceneIdents = [];
        foreach ($allScenes as $sceneName) {
            $ident = 'Scene_' . md5($sceneName);
            $sceneIdents[$ident] = $sceneName;
            
            $this->RegisterVariableBoolean($ident, 'Szene: ' . $sceneName, [
                'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
                'ICON' => 'Script'
            ], 10);
            $this->EnableAction($ident);
        }
        $this->SetBuffer('SceneIdents', json_encode($sceneIdents));

        // Cleanup obsolete scene variables
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childId) {
            $obj = IPS_GetObject($childId);
            if ($obj['ObjectType'] == 2) { // Variable
                $ident = $obj['ObjectIdent'];
                if (str_starts_with($ident, 'Scene_') && !isset($sceneIdents[$ident])) {
                    $this->UnregisterVariable($ident);
                }
            }
        }
    }

    // =====================================================================
    // === Message Sink ===
    // =====================================================================

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($this->HandleCentralStateMessage($SenderID, $Message, $Data)) {
            return;
        }

        if ($Message !== VM_UPDATE) {
            return;
        }

        $val = $Data[0];
        $isTrigger = $this->evaluateTriggerValue($val);

        // --- Scene-based Motion Triggers ---
        $motionTriggers = $this->safeJsonDecode($this->GetBuffer('MotionTriggersCache'), true) ?: [];
        foreach ($motionTriggers as $index => $trigger) {
            $sensorId = $this->resolveDeviceId($trigger['SensorID'] ?? 0);
            $manualId = (int)($trigger['ManualSensorID'] ?? 0);
            if ($sensorId <= 0 && $manualId > 0) {
                $sensorId = $manualId;
            }
            if ($sensorId == $SenderID && $isTrigger) {
                $this->processMotionTrigger($trigger, $index);
            }
        }

        // --- Switch Triggers ---
        $switchTriggers = $this->safeJsonDecode($this->GetBuffer('SwitchTriggersCache'), true) ?: [];
        $switchDefaults = $this->safeJsonDecode($this->GetBuffer('SwitchDefaultsCache'), true) ?: [];
        foreach ($switchTriggers as $trigger) {
            $switchId = $this->resolveDeviceId($trigger['SwitchID'] ?? 0);
            $manualId = (int)($trigger['ManualSwitchID'] ?? 0);
            if ($switchId <= 0 && $manualId > 0) {
                $switchId = $manualId;
            }
            if ($switchId == $SenderID) {
                $rawTriggerVal = trim((string)($trigger['TriggerValue'] ?? ''));
                if ($rawTriggerVal === '') {
                    $rawTriggerVal = $switchDefaults[$SenderID] ?? 'true';
                }
                $triggerValStr = strtolower($rawTriggerVal);
                $currentValStr = strtolower(trim((string)$val));
                $matched = ($triggerValStr === 'true') ? $isTrigger : ($triggerValStr === $currentValStr);

                if ($matched) {
                    $this->processSwitchTrigger($trigger);
                }
            }
        }

        // --- Door Rules ---
        $doorRules = $this->safeJsonDecode($this->GetBuffer('DoorRulesCache'), true) ?: [];
        foreach ($doorRules as $index => $rule) {
            $doorSensorId = $this->resolveDeviceId($rule['DoorSensorID'] ?? 0);
            $manualDoorId = (int)($rule['ManualDoorVariableID'] ?? 0);
            if ($doorSensorId <= 0 && $manualDoorId > 0) {
                $doorSensorId = $manualDoorId;
            }
            if ($doorSensorId == $SenderID) {
                $this->processDoorTrigger($rule, $index, $isTrigger);
            }
        }

        // --- Sync Rules ---
        $syncRules = $this->safeJsonDecode($this->GetBuffer('SyncRulesCache'), true) ?: [];
        foreach ($syncRules as $rule) {
            if (($rule['MasterVariableID'] ?? 0) == $SenderID) {
                $this->processSyncRule($rule, $val);
            }
        }
    }

    // =====================================================================
    // === Scene Engine (delegates to SmartSequencer) ===
    // =====================================================================

    /**
     * Activate a scene by name.
     * Looks up the SmartSequencer instance and runs its entry sequence.
     */
    private function activateScene(string $sceneName, string $context = ''): void
    {
        if ($sceneName === '') {
            return;
        }

        $sceneIdents = $this->safeJsonDecode($this->GetBuffer('SceneIdents'), true) ?: [];
        
        // 1. Find other active scenes and deactivate them FIRST (so their lights turn off)
        foreach ($sceneIdents as $ident => $sName) {
            if ($sName !== $sceneName) {
                $varId = @$this->GetIDForIdent($ident);
                if ($varId > 0 && @GetValue($varId)) {
                    $this->deactivateScene($sName, $context !== '' ? $context . ' (Auto-Off)' : 'Auto-Off');
                }
            }
        }

        // 2. Set the state for this scene to true
        $this->SetValue('MasterSwitch', true);
        foreach ($sceneIdents as $ident => $sName) {
            if ($sName === $sceneName) {
                $this->SetValue($ident, true);
            }
        }

        $scenes = $this->safeJsonDecode($this->GetBuffer('ScenesCache'), true) ?: [];
        $sceneDevices = $this->safeJsonDecode($this->GetBuffer('SceneDevicesCache'), true) ?: [];
        $found = false;

        // 1. Check for Sequencer mapped to this scene
        foreach ($scenes as $scene) {
            if (($scene['SceneName'] ?? '') === $sceneName) {
                $seqId = $scene['SequencerID'] ?? 0;

                if ($seqId > 0 && @IPS_InstanceExists($seqId)) {
                    $found = true;
                    $logContext = $context !== '' ? "$context / $sceneName" : $sceneName;
                    $this->SLogInfo("Szene aktiviert ($logContext)", "Sequencer: " . IPS_GetName($seqId) . " (#$seqId)");

                    // Execute the entry sequence of the SmartSequencer instance
                    SHSQ_RunSequence($seqId);
                }
                break;
            }
        }

        // 2. Check for Direct Devices mapped to this scene
        foreach ($sceneDevices as $devRule) {
            if (($devRule['SceneName'] ?? '') === $sceneName) {
                $found = true;
                $targetId = $this->resolveDeviceId($devRule['TargetID'] ?? 0);
                $manualId = (int)($devRule['ManualTargetID'] ?? 0);
                if ($targetId <= 0 && $manualId > 0) {
                    $targetId = $manualId;
                }
                if ($targetId > 0 && IPS_VariableExists($targetId)) {
                    $valStr = (string)($devRule['ActionValue'] ?? '');
                    if ($valStr !== '') {
                        $var = IPS_GetVariable($targetId);
                        $typedValue = $this->castToVariableType($var['VariableType'], $valStr);
                        $res = $this->safeRequestAction($targetId, $typedValue);
                        $this->logSwitch($targetId, $typedValue, $res, $context !== '' ? "$context / $sceneName" : $sceneName);
                    }
                }
            }
        }

        if (!$found) {
            $this->SLogWarning('Szene nicht gefunden', "Name: $sceneName");
        }
    }

    /**
     * Deactivate a scene by name.
     * Runs the deactivation sequence of the linked SmartSequencer instance.
     */
    private function deactivateScene(string $sceneName, string $context = ''): void
    {
        if ($sceneName === '') {
            return;
        }

        $sceneIdents = $this->safeJsonDecode($this->GetBuffer('SceneIdents'), true) ?: [];
        foreach ($sceneIdents as $ident => $sName) {
            if ($sName === $sceneName) {
                $this->SetValue($ident, false);
            }
        }

        $anyActive = false;
        foreach ($sceneIdents as $ident => $sName) {
            $varId = @$this->GetIDForIdent($ident);
            if ($varId > 0 && @GetValue($varId)) {
                $anyActive = true;
                break;
            }
        }
        $this->SetValue('MasterSwitch', $anyActive);

        $scenes = $this->safeJsonDecode($this->GetBuffer('ScenesCache'), true) ?: [];
        $sceneDevices = $this->safeJsonDecode($this->GetBuffer('SceneDevicesCache'), true) ?: [];
        $found = false;

        // 1. Check for Sequencer mapped to this scene
        foreach ($scenes as $scene) {
            if (($scene['SceneName'] ?? '') === $sceneName) {
                $found = true;
                // Check if there's a dedicated off-sequencer
                $offSeqId = $scene['OffSequencerID'] ?? 0;
                if ($offSeqId > 0 && @IPS_InstanceExists($offSeqId)) {
                    $logContext = $context !== '' ? "$context / $sceneName (AUS)" : "$sceneName (AUS)";
                    $this->SLogInfo("Szene deaktiviert ($logContext)", "Off-Sequencer: " . IPS_GetName($offSeqId) . " (#$offSeqId)");
                    SHSQ_RunSequence($offSeqId);
                    break;
                }

                // Fallback: run the deactivation sequence of the main sequencer
                $seqId = $scene['SequencerID'] ?? 0;
                if ($seqId > 0 && @IPS_InstanceExists($seqId)) {
                    $logContext = $context !== '' ? "$context / $sceneName (Austritt)" : "$sceneName (Austritt)";
                    $this->SLogInfo("Szene deaktiviert ($logContext)", "Sequencer: " . IPS_GetName($seqId) . " (#$seqId)");
                    SHSQ_RunDeactivationSequence($seqId);
                    break;
                }
            }
        }

        // 2. Check for Direct Devices mapped to this scene
        foreach ($sceneDevices as $devRule) {
            if (($devRule['SceneName'] ?? '') === $sceneName) {
                $found = true;
                $targetId = $this->resolveDeviceId($devRule['TargetID'] ?? 0);
                $manualId = (int)($devRule['ManualTargetID'] ?? 0);
                if ($targetId <= 0 && $manualId > 0) {
                    $targetId = $manualId;
                }
                if ($targetId > 0 && IPS_VariableExists($targetId)) {
                    $valStr = (string)($devRule['DeactivateValue'] ?? '');
                    if ($valStr !== '') {
                        $var = IPS_GetVariable($targetId);
                        $typedValue = $this->castToVariableType($var['VariableType'], $valStr);
                        $res = $this->safeRequestAction($targetId, $typedValue);
                        $this->logSwitch($targetId, $typedValue, $res, $context !== '' ? "$context / $sceneName (AUS)" : "$sceneName (AUS)");
                    }
                }
            }
        }
        
        if (!$found) {
            $this->SLogWarning('Szene nicht gefunden (weder Sequencer noch Geraete)', "Szene: $sceneName");
        }
    }

    // =====================================================================
    // === Scene-based Motion Trigger Logic ===
    // =====================================================================

    private function processMotionTrigger(array $trigger, int $index): void
    {
        // 1. Check Manual Override
        $respectOverride = $trigger['RespectOverride'] ?? true;
        if ($respectOverride && $this->isManualOverride()) {
            $this->SendDebug('Motion', 'Manual Override aktiv – ignoriert', 0);
            return;
        }

        // 2. Check Lux threshold
        $luxSensorId = $this->resolveDeviceId($trigger['LuxSensorID'] ?? 0);
        $maxLux = $trigger['MaxLux'] ?? 50;
        if ($luxSensorId > 0 && IPS_VariableExists($luxSensorId)) {
            $currentLux = GetValue($luxSensorId);
            if ($currentLux >= $maxLux) {
                $this->SendDebug('Motion', "Lux $currentLux >= $maxLux – ignoriert", 0);
                return;
            }
        }

        // 3. Determine scene based on time conditions
        $nightFrom = $trigger['NightFrom'] ?? '23:00';
        $nightTo = $trigger['NightTo'] ?? '06:00';
        $nightSceneName = $trigger['NightSceneName'] ?? '';
        $daySceneName = $trigger['DaySceneName'] ?? '';

        $isNight = $this->isInTimeRange($nightFrom, $nightTo);
        $sceneName = $isNight ? $nightSceneName : $daySceneName;

        if ($sceneName === '') {
            return;
        }

        // 4. Activate scene
        $this->activateScene($sceneName, 'Bewegung');

        // 5. Set/Reset off-delay timer
        $duration = $trigger['DurationSec'] ?? 120;
        $timerName = "MotionOffTimer_$index";
        $this->SetTimerInterval($timerName, $duration * 1000);

        // Track which scene to deactivate when timer expires
        $this->trackActiveTimer($timerName, $sceneName);
    }

    public function ProcessMotionOff(int $ruleIndex): void
    {
        $timerName = "MotionOffTimer_$ruleIndex";
        $this->SetTimerInterval($timerName, 0);

        // Don't turn off if manual override is active
        if ($this->isManualOverride()) {
            $this->untrackActiveTimer($timerName);
            return;
        }

        $activeTimers = $this->safeJsonDecode($this->GetBuffer('ActiveTimers'), true) ?: [];
        $sceneName = $activeTimers[$timerName] ?? '';

        if ($sceneName !== '') {
            $this->deactivateScene($sceneName, 'Bewegung-Nachlauf');
        }

        $this->untrackActiveTimer($timerName);
    }

    // =====================================================================
    // === Switch Trigger Logic ===
    // =====================================================================

    private function processSwitchTrigger(array $trigger): void
    {
        $sceneName = $trigger['SceneName'] ?? '';
        $toggle = $trigger['Toggle'] ?? true;
        $setsOverride = $trigger['SetsOverride'] ?? true;

        // Debounce: 1 second
        $switchId = $this->resolveDeviceId($trigger['SwitchID'] ?? 0);
        $manualId = (int)($trigger['ManualSwitchID'] ?? 0);
        if ($switchId <= 0 && $manualId > 0) {
            $switchId = $manualId;
        }
        
        $debounceCache = $this->safeJsonDecode($this->GetBuffer('SwitchDebounceCache'), true) ?: [];
        $lastTrigger = $debounceCache[$switchId] ?? 0;
        $now = microtime(true);
        if (($now - $lastTrigger) < 1.0) {
            return;
        }
        $debounceCache[$switchId] = $now;
        $this->SetBuffer('SwitchDebounceCache', json_encode($debounceCache));

        if ($toggle) {
            // Toggle: if override is currently active → deactivate scene + release override
            if ($this->isManualOverride()) {
                if ($sceneName !== '') {
                    $this->deactivateScene($sceneName, 'Schalter AUS');
                }
                $this->setManualOverride(false);
                $this->cancelAllMotionTimers();
                $this->SLogInfo('Schalter Toggle', 'AUS – Manual Override aufgehoben');
                return;
            }
        }

        // Activate scene
        if ($sceneName !== '') {
            $this->activateScene($sceneName, 'Schalter');
        }

        // Set override
        if ($setsOverride) {
            $this->setManualOverride(true);
            $this->cancelAllMotionTimers();
            $this->SLogInfo('Schalter', "Szene: $sceneName – Manual Override gesetzt");
        }
    }

    // =====================================================================
    // === Helpers ===
    // =====================================================================

    private function getAllSceneNames(): array
    {
        $names = [];
        $scenes = $this->safeJsonDecode($this->GetBuffer('ScenesCache'), true) ?: [];
        foreach ($scenes as $s) {
            if (!empty($s['SceneName'])) $names[] = $s['SceneName'];
        }
        $devs = $this->safeJsonDecode($this->GetBuffer('SceneDevicesCache'), true) ?: [];
        foreach ($devs as $d) {
            if (!empty($d['SceneName'])) $names[] = $d['SceneName'];
        }
        return array_unique($names);
    }

    private function getRegistrySwitchOptions(int $regId): array
    {
        $options = [['label' => '(Manuell per Variable)', 'value' => 0]];
        if ($regId <= 0 || !@IPS_InstanceExists($regId)) {
            return $options;
        }

        $dynamicOptions = [];
        $addedVarIds = [];
        $devices = @SDR_GetDevicesByType($regId, 'DevicesWallSwitch');
        if (!is_array($devices)) {
            return $options;
        }
        foreach ($devices as $dev) {
            $name = ($dev['room'] ?? '') . ' / ' . ($dev['name'] ?? 'Unbenannt');
            $varId = (int)($dev['OnOff_VarID'] ?? 0);
            $deviceKey = ($dev['room'] ?? '') . '::' . ($dev['name'] ?? 'Unbenannt');
            if ($varId > 0 && !in_array($deviceKey, $addedVarIds)) {
                $addedVarIds[] = $deviceKey;
                $dynamicOptions[] = ['label' => $name . ' (Taster)', 'value' => $deviceKey];
            }
        }
        
        usort($dynamicOptions, function ($a, $b) {
            return strcasecmp($a['label'], $b['label']);
        });

        return array_merge($options, $dynamicOptions);
    }

    // =====================================================================
    // Traits overrides
    // =====================================================================

    private function processDoorTrigger(array $rule, int $ruleIndex, bool $isOpen): void
    {
        $sceneName = $rule['SceneName'] ?? '';
        $timerName = "DoorOffTimer_$ruleIndex";
        $occupancyLock = $rule['OccupancyLock'] ?? false;

        if ($isOpen) {
            // Door opened
            $this->SetTimerInterval($timerName, 0);

            if ($occupancyLock && ($this->GetBuffer('OccupancyLocked') === 'true')) {
                // Room was occupied, door opens → release and start off-timer
                $this->SetBuffer('OccupancyLocked', 'false');
                $duration = $rule['DurationSec'] ?? 10;
                if ($duration > 0) {
                    $this->SetTimerInterval($timerName, $duration * 1000);
                    $this->trackActiveTimer($timerName, $sceneName);
                } else {
                    $this->deactivateScene($sceneName, 'Tuer geoeffnet');
                }
                return;
            }

            // Normal door open: check lux, then activate scene
            $luxSensorId = $this->resolveDeviceId($rule['LuxSensorID'] ?? 0);
            $maxLux = $rule['MaxLux'] ?? 1000;
            if ($luxSensorId > 0 && IPS_VariableExists($luxSensorId)) {
                if (GetValue($luxSensorId) >= $maxLux) {
                    return;
                }
            }

            if ($sceneName !== '') {
                $this->activateScene($sceneName, 'Tuer/Fenster');
                $this->trackActiveTimer($timerName, $sceneName);
            }
        } else {
            // Door closed
            if ($occupancyLock) {
                // Wasp-in-a-box: lock occupancy
                $this->SetBuffer('OccupancyLocked', 'true');
                $this->SLogInfo('Wasp-in-a-Box', 'Raum als belegt markiert – Licht bleibt an');
                return;
            }

            // Normal: start off-delay
            $duration = $rule['DurationSec'] ?? 10;
            if ($duration === 0) {
                $this->ProcessDoorOff($ruleIndex);
            } else {
                $this->SetTimerInterval($timerName, $duration * 1000);
            }
        }
    }

    public function ProcessDoorOff(int $ruleIndex): void
    {
        $timerName = "DoorOffTimer_$ruleIndex";
        $this->SetTimerInterval($timerName, 0);

        $activeTimers = $this->safeJsonDecode($this->GetBuffer('ActiveTimers'), true) ?: [];
        $sceneName = $activeTimers[$timerName] ?? '';

        if ($sceneName !== '') {
            $this->deactivateScene($sceneName, 'Tuer/Fenster-Nachlauf');
        }

        $this->untrackActiveTimer($timerName);
    }

    // =====================================================================
    // === Twilight Rules ===
    // =====================================================================

    public function CalculateTwilightTimers(): void
    {
        for ($i = 0; $i < self::MAX_TIMERS; $i++) {
            @$this->SetTimerInterval("TwilightTimer_$i", 0);
        }

        $rules = $this->safeJsonDecode($this->ReadPropertyString('TwilightRules'), true) ?: [];
        $sunsetId = $this->ReadPropertyInteger('SunsetVariableID');
        $sunriseId = $this->ReadPropertyInteger('SunriseVariableID');

        $sunsetTime = ($sunsetId > 0 && IPS_VariableExists($sunsetId)) ? (int)GetValue($sunsetId) : 0;
        $sunriseTime = ($sunriseId > 0 && IPS_VariableExists($sunriseId)) ? (int)GetValue($sunriseId) : 0;
        $now = time();

        foreach ($rules as $index => $rule) {
            if ($index >= self::MAX_TIMERS) {
                break;
            }
            if (!($rule['Active'] ?? true)) {
                continue;
            }

            $triggerType = $rule['TriggerType'] ?? 1;
            $timeVal = $rule['TimeValue'] ?? '0';
            $targetTime = 0;

            if ($triggerType == 1 && $sunsetTime > 0) {
                $targetTime = $sunsetTime + ((int)$timeVal * 60);
            } elseif ($triggerType == 2 && $sunriseTime > 0) {
                $targetTime = $sunriseTime + ((int)$timeVal * 60);
            } elseif ($triggerType == 3) {
                $timeParts = explode(':', $timeVal);
                if (count($timeParts) == 2) {
                    $targetTime = mktime((int)$timeParts[0], (int)$timeParts[1], 0, (int)date('m'), (int)date('d'), (int)date('Y'));
                }
            }

            if ($targetTime > 0) {
                if ($targetTime <= $now) {
                    $targetTime += 86400;
                }
                $this->SetTimerInterval("TwilightTimer_$index", ($targetTime - $now) * 1000);
            }
        }
    }

    public function ProcessTwilightTrigger(int $ruleIndex): void
    {
        $this->SetTimerInterval("TwilightTimer_$ruleIndex", 0);

        $rules = $this->safeJsonDecode($this->ReadPropertyString('TwilightRules'), true) ?: [];
        if (isset($rules[$ruleIndex]) && ($rules[$ruleIndex]['Active'] ?? true)) {
            $sceneName = $rules[$ruleIndex]['SceneName'] ?? '';
            $targetId = $this->resolveDeviceId($rules[$ruleIndex]['TargetLightID'] ?? 0);

            if ($sceneName !== '') {
                // Scene-based twilight
                $this->activateScene($sceneName, 'Daemmerung');
            } elseif ($targetId > 0 && IPS_VariableExists($targetId)) {
                // Legacy: direct target
                $actionVal = $rules[$ruleIndex]['ActionValue'] ?? 1;
                $var = IPS_GetVariable($targetId);
                if ($var['VariableType'] == 0) {
                    $actVal = ($actionVal == 1);
                    $res = $this->safeRequestAction($targetId, $actVal);
                    $this->logSwitch($targetId, $actVal, $res, 'Daemmerung');
                } else {
                    $actVal = ($actionVal == 1) ? 100 : 0;
                    $res = $this->safeRequestAction($targetId, $actVal);
                    $this->logSwitch($targetId, $actVal, $res, 'Daemmerung');
                }
            }
        }

        $this->CalculateTwilightTimers();
    }

    // =====================================================================
    // === Sync Rules ===
    // =====================================================================

    private function processSyncRule(array $rule, mixed $val): void
    {
        $targetId = $this->resolveDeviceId($rule['TargetLightID'] ?? 0);
        $sourceId = $rule['MasterVariableID'] ?? 0;
        if ($targetId <= 0 || !IPS_VariableExists($targetId)) {
            return;
        }
        if ($sourceId <= 0 || !IPS_VariableExists($sourceId)) {
            return;
        }

        $targetVar = IPS_GetVariable($targetId);
        $sourceVar = IPS_GetVariable($sourceId);
        $actionValue = $val;

        if ($targetVar['VariableType'] == 0 && $sourceVar['VariableType'] != 0) {
            $actionValue = ($val > 0);
        } elseif ($targetVar['VariableType'] != 0 && $sourceVar['VariableType'] == 0) {
            $targetRange = $this->getProfileMinMax($targetId);
            $actionValue = $val ? $targetRange['max'] : $targetRange['min'];
        } elseif ($targetVar['VariableType'] != 0 && $sourceVar['VariableType'] != 0) {
            $sourceRange = $this->getProfileMinMax($sourceId);
            $targetRange = $this->getProfileMinMax($targetId);
            $sourcePercentage = ($val - $sourceRange['min']) / max(0.001, $sourceRange['max'] - $sourceRange['min']);
            $sourcePercentage = max(0, min(1, $sourcePercentage));
            $actionValue = $targetRange['min'] + ($sourcePercentage * ($targetRange['max'] - $targetRange['min']));
        }

        $typedValue = $this->castToVariableType($targetVar['VariableType'], $actionValue);
        $res = $this->safeRequestAction($targetId, $typedValue);
        $this->logSwitch($targetId, $typedValue, $res, 'Sync');
    }

    // =====================================================================
    // === Central State Handling ===
    // =====================================================================

    protected function OnCentralStateChanged(string $stateName, mixed $newValue): void
    {
        match ($stateName) {
            'PresenceMode' => $this->handlePresenceChange((int)$newValue),
            'ActivityMode' => $this->handleActivityChange((int)$newValue),
            default => null
        };
    }

    private function handlePresenceChange(int $mode): void
    {
        // Away (1) or Vacation (2): turn off everything
        if ($mode === 1 || $mode === 2) {
            $this->emergencyShutdown("Haus-Modus: $mode");
        }
    }

    private function handleActivityChange(int $mode): void
    {
        if ($mode === 2) {
            $this->SLogInfo('ActivityMode', 'Schlafen-Modus aktiviert');
        }
    }

    /**
     * Cancel all timers, reset override, deactivate all tracked scenes.
     */
    private function emergencyShutdown(string $reason): void
    {
        $activeTimers = $this->safeJsonDecode($this->GetBuffer('ActiveTimers'), true) ?: [];
        foreach ($activeTimers as $timerName => $sceneName) {
            $this->SetTimerInterval($timerName, 0);
            if ($sceneName !== '') {
                $this->deactivateScene($sceneName, 'Haus-Modus');
            }
        }
        $this->SetBuffer('ActiveTimers', '[]');

        $this->setManualOverride(false);
        $this->SetBuffer('OccupancyLocked', 'false');

        $this->SLogInfo('Notabschaltung', $reason);
    }

    // =====================================================================
    // === RequestAction ===
    // =====================================================================

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if (str_starts_with($Ident, 'Scene_')) {
            $sceneIdents = $this->safeJsonDecode($this->GetBuffer('SceneIdents'), true) ?: [];
            $sceneName = $sceneIdents[$Ident] ?? '';
            if ($sceneName !== '') {
                if ($Value) {
                    $this->activateScene($sceneName, 'WebFront');
                } else {
                    $this->deactivateScene($sceneName, 'WebFront');
                }
            }
            return;
        }

        switch ($Ident) {
            case 'MasterSwitch':
                if ($Value) {
                    $scene = $this->ReadPropertyString('MasterOnScene');
                    if ($scene !== '') {
                        $this->activateScene($scene, 'MasterSwitch');
                    } else {
                        $this->SLogWarning('MasterSwitch', 'Keine Standard-Szene in der Instanzkonfiguration definiert!');
                    }
                } else {
                    $allScenes = $this->getAllSceneNames();
                    foreach ($allScenes as $scene) {
                        $this->deactivateScene($scene, 'MasterSwitch');
                    }
                }
                $this->SetValue($Ident, $Value);
                break;
        }
    }

    // =====================================================================
    // === Manual Override Management ===
    // =====================================================================

    private function isManualOverride(): bool
    {
        return $this->GetBuffer('ManualOverride') === 'true';
    }

    private function setManualOverride(bool $state): void
    {
        $this->SetBuffer('ManualOverride', $state ? 'true' : 'false');
    }

    // =====================================================================
    // === Timer Tracking ===
    // =====================================================================

    private function trackActiveTimer(string $timerName, string $sceneName): void
    {
        $timers = $this->safeJsonDecode($this->GetBuffer('ActiveTimers'), true) ?: [];
        $timers[$timerName] = $sceneName;
        $this->SetBuffer('ActiveTimers', json_encode($timers));
    }

    private function untrackActiveTimer(string $timerName): void
    {
        $timers = $this->safeJsonDecode($this->GetBuffer('ActiveTimers'), true) ?: [];
        unset($timers[$timerName]);
        $this->SetBuffer('ActiveTimers', json_encode($timers));
    }

    private function cancelAllMotionTimers(): void
    {
        for ($i = 0; $i < self::MAX_TIMERS; $i++) {
            $this->SetTimerInterval("MotionOffTimer_$i", 0);
        }
        $timers = $this->safeJsonDecode($this->GetBuffer('ActiveTimers'), true) ?: [];
        foreach (array_keys($timers) as $key) {
            if (str_starts_with($key, 'MotionOffTimer_')) {
                unset($timers[$key]);
            }
        }
        $this->SetBuffer('ActiveTimers', json_encode($timers));
    }

    // =====================================================================
    // === Helper Methods ===
    // =====================================================================

    private function evaluateTriggerValue(mixed $val): bool
    {
        if (is_bool($val)) {
            return $val;
        }
        if (is_int($val) || is_float($val)) {
            return ($val > 0);
        }
        if (is_string($val)) {
            return in_array(strtolower(trim($val)), ['true', 'open', 'on', '1', 'geoeffnet']);
        }
        return false;
    }

    private function isInTimeRange(string $from, string $to): bool
    {
        $nowMinutes = (int)date('H') * 60 + (int)date('i');

        $fromParts = explode(':', $from);
        $toParts = explode(':', $to);
        if (count($fromParts) !== 2 || count($toParts) !== 2) {
            return false;
        }

        $fromMinutes = (int)$fromParts[0] * 60 + (int)$fromParts[1];
        $toMinutes = (int)$toParts[0] * 60 + (int)$toParts[1];

        if ($fromMinutes <= $toMinutes) {
            return $nowMinutes >= $fromMinutes && $nowMinutes < $toMinutes;
        }
        // Overnight range (e.g., 23:00 - 06:00)
        return $nowMinutes >= $fromMinutes || $nowMinutes < $toMinutes;
    }

    private function castToVariableType(int $variableType, mixed $value): mixed
    {
        if (is_string($value)) {
            $valLower = strtolower(trim($value));
            if ($variableType === 1 || $variableType === 2) { // Integer or Float
                if ($valLower === 'true' || $valLower === 'on') return ($variableType === 1) ? 100 : 100.0;
                if ($valLower === 'false' || $valLower === 'off') return ($variableType === 1) ? 0 : 0.0;
            }
        }
        
        return match ($variableType) {
            0 => is_string($value) ? in_array(strtolower(trim($value)), ['true', '1', 'on', 'geoeffnet']) : (bool)$value,
            1 => (int)round((float)$value),
            2 => (float)$value,
            3 => (string)$value,
            default => $value
        };
    }

    private function getProfileMinMax(int $variableId): array
    {
        $min = 0;
        $max = 100;
        if (IPS_VariableExists($variableId)) {
            $var = IPS_GetVariable($variableId);
            $profileName = $var['VariableCustomProfile'] != '' ? $var['VariableCustomProfile'] : $var['VariableProfile'];
            if ($profileName != '' && IPS_VariableProfileExists($profileName)) {
                $profile = IPS_GetVariableProfile($profileName);
                if ($profile['MaxValue'] > $profile['MinValue']) {
                    $min = $profile['MinValue'];
                    $max = $profile['MaxValue'];
                }
            }
        }
        return ['min' => $min, 'max' => $max];
    }

    private function getObjectLabel(int $id): string
    {
        if ($id <= 0 || !@IPS_ObjectExists($id)) {
            return "Unbekannt ($id)";
        }
        $location = @IPS_GetLocation($id);
        return $location !== '' ? $location : IPS_GetName($id);
    }

    private function logSwitch(int $targetId, mixed $value, bool $success, string $context = ''): void
    {
        $name = $this->getObjectLabel($targetId);
        $formattedVal = is_bool($value) ? ($value ? 'true' : 'false') : var_export($value, true);
        $msgTitle = $success ? 'Aktor geschaltet' : 'Aktor-Befehl fehlgeschlagen';
        $message = $context !== '' ? "$msgTitle ($context)." : "$msgTitle.";
        $details = "Name: $name | ID: $targetId | Wert: $formattedVal";

        if ($success) {
            $this->SLogInfo($message, $details);
        } else {
            $this->SLogWarning($message, $details);
        }
    }

    private function registerPropertyReference(string $propertyName): void
    {
        $id = $this->ReadPropertyInteger($propertyName);
        if ($id > 1 && @IPS_ObjectExists($id)) {
            $this->RegisterReference($id);
        }
    }

    private function registerListReferences(string $propertyName, array $fields): void
    {
        $list = $this->safeJsonDecode($this->ReadPropertyString($propertyName), true) ?: [];
        foreach ($list as $item) {
            foreach ($fields as $field) {
                $vid = $this->resolveDeviceId($item[$field] ?? 0);
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
    }

    // =====================================================================
    // === Registration Helpers ===
    // =====================================================================

    private function registerSensorMessages(string $property, string $key, string $manualKey = ''): void
    {
        $rules = $this->safeJsonDecode($this->ReadPropertyString($property), true) ?: [];
        foreach ($rules as $rule) {
            $id = $this->resolveDeviceId($rule[$key] ?? 0);
            if ($manualKey !== '' && $id <= 0) {
                $manId = (int)($rule[$manualKey] ?? 0);
                if ($manId > 0) {
                    $id = $manId;
                }
            }
            if ($id > 0 && IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }
    }

    
    private function resolveDeviceId(string|int $idStr): int
    {
        if (is_numeric($idStr)) {
            return (int)$idStr;
        }
        $map = $this->safeJsonDecode($this->GetBuffer('DeviceMapCache'), true) ?: [];
        return (int)($map[$idStr] ?? 0);
    }

    private function safeJsonDecode(string $json, bool $assoc = true): mixed
    {
        try {
            if (trim($json) === '') {
                return $assoc ? [] : null;
            }
            return json_decode($json, $assoc, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->SLogWarning('JSON Decode Exception', $e->getMessage());
            return $assoc ? [] : null;
        }
    }

    // =====================================================================
    // === DeviceRegistry Integration ===
    // =====================================================================

    /**
     * Query the DeviceRegistry (SDR) for light devices.
     * Returns Select-compatible options array.
     */
    private function getRegistryLightOptions(int $regId): array
    {
        $options = [['label' => '(Manuell per Variable)', 'value' => 0]];
        if ($regId <= 0 || !@IPS_InstanceExists($regId)) {
            return $options;
        }

        $dynamicOptions = [];
        $addedVarIds = [];
        $lightTypes = ['DevicesLight', 'DevicesLightDimmer', 'DevicesLightColor'];
        foreach ($lightTypes as $type) {
            $devices = @SDR_GetDevicesByType($regId, $type);
            if (!is_array($devices)) {
                continue;
            }
            foreach ($devices as $dev) {
                $baseName = ($dev['room'] ?? '') . ' / ' . ($dev['name'] ?? 'Unbenannt');
                
                // Add Color option
                if (!empty($dev['ColorRGB_VarID']) && (int)$dev['ColorRGB_VarID'] > 0) {
                    $varId = (int)$dev['ColorRGB_VarID'];
                    $deviceKey = ($dev['room'] ?? '') . '::' . ($dev['name'] ?? 'Unbenannt') . '::Color';
                    if (!in_array($deviceKey, $addedVarIds)) {
                        $addedVarIds[] = $deviceKey;
                        $dynamicOptions[] = ['label' => $baseName . ' (Farbe)', 'value' => $deviceKey];
                    }
                }
                
                // Add Dimmer option
                if (!empty($dev['Brightness_VarID']) && (int)$dev['Brightness_VarID'] > 0) {
                    $varId = (int)$dev['Brightness_VarID'];
                    $deviceKey = ($dev['room'] ?? '') . '::' . ($dev['name'] ?? 'Unbenannt') . '::Dimmer';
                    if (!in_array($deviceKey, $addedVarIds)) {
                        $addedVarIds[] = $deviceKey;
                        $dynamicOptions[] = ['label' => $baseName . ' (Dimmer)', 'value' => $deviceKey];
                    }
                }
                
                // Add Switch option
                if (!empty($dev['OnOff_VarID']) && (int)$dev['OnOff_VarID'] > 0) {
                    $varId = (int)$dev['OnOff_VarID'];
                    $deviceKey = ($dev['room'] ?? '') . '::' . ($dev['name'] ?? 'Unbenannt') . '::Switch';
                    if (!in_array($deviceKey, $addedVarIds)) {
                        $addedVarIds[] = $deviceKey;
                        $dynamicOptions[] = ['label' => $baseName . ' (Schalter)', 'value' => $deviceKey];
                    }
                }
            }
        }

        usort($dynamicOptions, fn($a, $b) => strcasecmp($a['label'], $b['label']));
        return array_merge($options, $dynamicOptions);
    }

    /**
     * Query the DeviceRegistry (SDR) for motion sensors.
     */
    private function getRegistryMotionSensorOptions(int $regId): array
    {
        $options = [['label' => '(Manuell per Variable)', 'value' => 0]];
        if ($regId <= 0 || !@IPS_InstanceExists($regId)) {
            return $options;
        }

        $dynamicOptions = [];
        $devices = @SDR_GetDevicesByType($regId, 'DevicesMotionSensor');
        if (!is_array($devices)) {
            return $options;
        }

        foreach ($devices as $dev) {
            $name = ($dev['room'] ?? '') . ' / ' . ($dev['name'] ?? 'Unbenannt');
            $varId = (int)($dev['Status_VarID'] ?? 0);
            $deviceKey = ($dev['room'] ?? '') . '::' . ($dev['name'] ?? 'Unbenannt');
            if ($varId > 0) {
                $dynamicOptions[] = ['label' => $name, 'value' => $deviceKey];
            }
        }

        usort($dynamicOptions, fn($a, $b) => strcasecmp($a['label'], $b['label']));
        return array_merge($options, $dynamicOptions);
    }

    /**
     * Query the DeviceRegistry (SDR) for contact sensors.
     */
    private function getRegistryContactSensorOptions(int $regId): array
    {
        $options = [['label' => '(Manuell per Variable)', 'value' => 0]];
        if ($regId <= 0 || !@IPS_InstanceExists($regId)) {
            return $options;
        }

        $dynamicOptions = [];
        $devices = @SDR_GetDevicesByType($regId, 'DevicesContactSensor');
        if (!is_array($devices)) {
            return $options;
        }

        foreach ($devices as $dev) {
            $name = ($dev['room'] ?? '') . ' / ' . ($dev['name'] ?? 'Unbenannt');
            $varId = (int)($dev['Status_VarID'] ?? 0);
            $deviceKey = ($dev['room'] ?? '') . '::' . ($dev['name'] ?? 'Unbenannt');
            if ($varId > 0) {
                $dynamicOptions[] = ['label' => $name, 'value' => $deviceKey];
            }
        }

        usort($dynamicOptions, fn($a, $b) => strcasecmp($a['label'], $b['label']));
        return array_merge($options, $dynamicOptions);
    }

    // =====================================================================
    // === Dynamic Configuration Form ===
    // =====================================================================

    public function GetConfigurationForm(): string
    {
        $regId = $this->ReadPropertyInteger('RegistryID');
        $hasRegistry = ($regId > 0 && @IPS_InstanceExists($regId));

        $definedScenes = $this->safeJsonDecode($this->ReadPropertyString('Scenes'), true) ?: [];
        $sceneOptions = [['label' => '(Bitte waehlen)', 'value' => '']];
        foreach ($definedScenes as $scene) {
            $name = $scene['SceneName'] ?? '';
            if ($name !== '') {
                $sceneOptions[] = ['label' => $name, 'value' => $name];
            }
        }

        // Build device options from registry
        $lightOptions = $hasRegistry ? $this->getRegistryLightOptions($regId) : [];
        $switchOptions = $hasRegistry ? $this->getRegistrySwitchOptions($regId) : [];
        $motionOptions = $hasRegistry ? $this->getRegistryMotionSensorOptions($regId) : [];
        $contactOptions = $hasRegistry ? $this->getRegistryContactSensorOptions($regId) : [];

        // --- SceneDevices columns (dynamic based on registry) ---
        $sceneDevicesColumns = [
            ['caption' => 'Szenen-Name', 'name' => 'SceneName', 'width' => '150px', 'add' => '', 'edit' => ['type' => 'Select', 'options' => $sceneOptions]],
        ];

        if ($hasRegistry && count($lightOptions) > 1) {
            $sceneDevicesColumns[] = [
                'caption' => 'Lampe / Dimmer (Registry)', 'name' => 'TargetID', 'width' => '250px',
                'add' => 0, 'edit' => ['type' => 'Select', 'options' => $lightOptions]
            ];
            $sceneDevicesColumns[] = [
                'caption' => 'Oder: Variable (manuell)', 'name' => 'ManualTargetID', 'width' => '180px',
                'add' => 0, 'edit' => ['type' => 'SelectVariable']
            ];
        } else {
            $sceneDevicesColumns[] = [
                'caption' => 'Lampe / Dimmer (Variable)', 'name' => 'TargetID', 'width' => '250px',
                'add' => 0, 'edit' => ['type' => 'SelectVariable']
            ];
        }

        $sceneDevicesColumns[] = ['caption' => 'Wert (AN)', 'name' => 'ActionValue', 'width' => '100px', 'add' => 'true', 'edit' => ['type' => 'ValidationTextBox']];
        $sceneDevicesColumns[] = ['caption' => 'Wert (AUS)', 'name' => 'DeactivateValue', 'width' => '100px', 'add' => 'false', 'edit' => ['type' => 'ValidationTextBox']];

        // --- MotionTriggers columns (dynamic based on registry) ---
        $motionTriggersColumns = [];
        if ($hasRegistry && count($motionOptions) > 1) {
            $motionTriggersColumns[] = ['caption' => 'Sensor (Registry)', 'name' => 'SensorID', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'Select', 'options' => $motionOptions]];
            $motionTriggersColumns[] = ['caption' => 'Oder manuell', 'name' => 'ManualSensorID', 'width' => '150px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']];
        } else {
            $motionTriggersColumns[] = ['caption' => 'Sensor (BWM)', 'name' => 'SensorID', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']];
        }

        $motionTriggersColumns = array_merge($motionTriggersColumns, [
            ['caption' => 'Lux-Sensor', 'name' => 'LuxSensorID', 'width' => '180px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
            ['caption' => 'Max Lux', 'name' => 'MaxLux', 'width' => '70px', 'add' => 50, 'edit' => ['type' => 'NumberSpinner']],
            ['caption' => 'Nachlauf (Sek)', 'name' => 'DurationSec', 'width' => '90px', 'add' => 120, 'edit' => ['type' => 'NumberSpinner']],
            ['caption' => 'Nacht-Szene', 'name' => 'NightSceneName', 'width' => '120px', 'add' => '', 'edit' => ['type' => 'Select', 'options' => $sceneOptions]],
            ['caption' => 'Nacht von', 'name' => 'NightFrom', 'width' => '70px', 'add' => '23:00', 'edit' => ['type' => 'ValidationTextBox']],
            ['caption' => 'Nacht bis', 'name' => 'NightTo', 'width' => '70px', 'add' => '06:00', 'edit' => ['type' => 'ValidationTextBox']],
            ['caption' => 'Tag-Szene', 'name' => 'DaySceneName', 'width' => '120px', 'add' => '', 'edit' => ['type' => 'Select', 'options' => $sceneOptions]],
            ['caption' => 'Override', 'name' => 'RespectOverride', 'width' => '70px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
        ]);

        // --- DoorRules columns (dynamic based on registry) ---
        $doorRulesColumns = [];
        if ($hasRegistry && count($contactOptions) > 1) {
            $doorRulesColumns[] = ['caption' => 'Kontakt (Registry)', 'name' => 'DoorSensorID', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'Select', 'options' => $contactOptions]];
            $doorRulesColumns[] = ['caption' => 'Oder manuell', 'name' => 'ManualDoorVariableID', 'width' => '150px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']];
        } else {
            $doorRulesColumns[] = ['caption' => 'Sensor', 'name' => 'DoorSensorID', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']];
        }

        $doorRulesColumns = array_merge($doorRulesColumns, [
            ['caption' => 'Lux-Sensor', 'name' => 'LuxSensorID', 'width' => '180px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
            ['caption' => 'Max Lux', 'name' => 'MaxLux', 'width' => '80px', 'add' => 1000, 'edit' => ['type' => 'NumberSpinner']],
            ['caption' => 'Nachlauf (Sek)', 'name' => 'DurationSec', 'width' => '90px', 'add' => 10, 'edit' => ['type' => 'NumberSpinner']],
            ['caption' => 'Szene', 'name' => 'SceneName', 'width' => '150px', 'add' => '', 'edit' => ['type' => 'Select', 'options' => $sceneOptions]],
            ['caption' => 'Wasp-in-a-Box', 'name' => 'OccupancyLock', 'width' => '100px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
        ]);

        // Build complete form
        $form = [
            'elements' => [
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Allgemeine Einstellungen',
                    'expanded' => true,
                    'items' => [
                        [
                            'type' => 'Select',
                            'name' => 'MasterOnScene',
                            'caption' => 'Standard-Szene (beim Einschalten ueber Master-Schalter)',
                            'options' => $sceneOptions
                        ]
                    ]
                ],
                // --- Registry Selection ---
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Geraete-Quelle',
                    'items' => [
                        [
                            'type' => 'SelectInstance',
                            'name' => 'RegistryID',
                            'caption' => 'Device Registry (SDR) Instanz (optional)',
                        ],
                        [
                            'type' => 'Label',
                            'caption' => $hasRegistry
                                ? 'Registry verbunden: Lampen und Sensoren werden als Dropdown angezeigt.'
                                : 'Keine Registry gesetzt. Variablen muessen manuell ausgewaehlt werden.',
                        ],
                    ],
                ],
                // --- Scenes ---
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Szenen-Definitionen',
                    'expanded' => true,
                    'items' => [
                        [
                            'type' => 'Label',
                            'caption' => 'Hier legst du alle Namen deiner Szenen für diesen Raum fest (z.B. "Standard", "Kino"). Du kannst optional direkt einen SmartSequencer verknüpfen, musst das aber nicht (0 lassen).',
                        ],
                        [
                            'type' => 'List',
                            'name' => 'Scenes',
                            'caption' => '1. Szenen anlegen & definieren',
                            'rowCount' => 5,
                            'add' => true,
                            'delete' => true,
                            'columns' => [
                                ['caption' => 'Szenen-Name', 'name' => 'SceneName', 'width' => '200px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                                ['caption' => 'Sequencer (Eintritt)', 'name' => 'SequencerID', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'SelectInstance']],
                                ['caption' => 'Off-Sequencer (optional)', 'name' => 'OffSequencerID', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'SelectInstance']],
                            ],
                        ],
                        [
                            'type' => 'Label',
                            'caption' => '2. Direkt-Geraete (optional)',
                        ],
                        [
                            'type' => 'List',
                            'name' => 'SceneDevices',
                            'caption' => 'Szenen -> Lampen / Dimmer',
                            'rowCount' => 8,
                            'add' => true,
                            'delete' => true,
                            'columns' => $sceneDevicesColumns,
                        ],
                    ],
                ],
                // --- Scene-based Motion Triggers ---
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Bewegungsmelder-Trigger (Szenen-Modus)',
                    'items' => [
                        [
                            'type' => 'Label',
                            'caption' => 'Fuer komplexe Szenarien (z.B. Bad): Bewegungsmelder loest je nach Tageszeit unterschiedliche Szenen aus.',
                        ],
                        [
                            'type' => 'List',
                            'name' => 'MotionTriggers',
                            'caption' => 'Bewegungsmelder-Trigger',
                            'rowCount' => 5,
                            'add' => true,
                            'delete' => true,
                            'columns' => $motionTriggersColumns,
                        ],
                    ],
                ],
                // --- Switch Triggers ---
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Schalter / Taster',
                    'items' => [
                        [
                            'type' => 'Label',
                            'caption' => 'Wandschalter und Taster loesen Szenen aus und koennen den manuellen Override setzen.',
                        ],
                        [
                            'type' => 'List',
                            'name' => 'SwitchTriggers',
                            'caption' => 'Schalter-Konfiguration',
                            'rowCount' => 5,
                            'add' => true,
                            'delete' => true,
                            'columns' => [
                                ['caption' => 'Schalter/Taster (Registry)', 'name' => 'SwitchID', 'width' => '250px', 'add' => 0, 'edit' => ['type' => 'Select', 'options' => $switchOptions]],
                                ['caption' => 'Oder: Variable (manuell)', 'name' => 'ManualSwitchID', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                                ['caption' => 'Szene', 'name' => 'SceneName', 'width' => '150px', 'add' => '', 'edit' => ['type' => 'Select', 'options' => $sceneOptions]],
                                ['caption' => 'Ausloese-Wert (leer = Registry)', 'name' => 'TriggerValue', 'width' => '150px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                                ['caption' => 'Toggle', 'name' => 'Toggle', 'width' => '60px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                                ['caption' => 'Setzt Override', 'name' => 'SetsOverride', 'width' => '100px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                            ],
                        ],
                    ],
                ],
                // --- Door Rules ---
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Tuer-/Fenster-Regeln',
                    'items' => [
                        [
                            'type' => 'List',
                            'name' => 'DoorRules',
                            'caption' => 'Tuer-/Fenster-Kontakte',
                            'rowCount' => 5,
                            'add' => true,
                            'delete' => true,
                            'columns' => $doorRulesColumns,
                        ],
                    ],
                ],
                // --- Twilight Rules ---
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Daemmerungs- / Zeitsteuerung',
                    'items' => [
                        [
                            'type' => 'List',
                            'name' => 'TwilightRules',
                            'caption' => 'Daemmerungs-Regeln',
                            'rowCount' => 5,
                            'add' => true,
                            'delete' => true,
                            'columns' => [
                                ['caption' => 'Aktiv', 'name' => 'Active', 'width' => '60px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                                ['caption' => 'Trigger-Typ', 'name' => 'TriggerType', 'width' => '150px', 'add' => 1, 'edit' => ['type' => 'Select', 'options' => [
                                    ['label' => 'Sonnenuntergang', 'value' => 1],
                                    ['label' => 'Sonnenaufgang', 'value' => 2],
                                    ['label' => 'Uhrzeit', 'value' => 3],
                                ]]],
                                ['caption' => 'Offset (Min) / Zeit (HH:MM)', 'name' => 'TimeValue', 'width' => '150px', 'add' => '-30', 'edit' => ['type' => 'ValidationTextBox']],
                                ['caption' => 'Szene', 'name' => 'SceneName', 'width' => '150px', 'add' => '', 'edit' => ['type' => 'Select', 'options' => $sceneOptions]],
                                ['caption' => 'Ziel-Licht (Legacy)', 'name' => 'TargetLightID', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                                ['caption' => 'Aktion (Legacy)', 'name' => 'ActionValue', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'Select', 'options' => [
                                    ['label' => 'Einschalten', 'value' => 1],
                                    ['label' => 'Ausschalten', 'value' => 0],
                                ]]],
                            ],
                        ],
                    ],
                ],
                // --- Sync Rules ---
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Synchronisation (Master-Slave)',
                    'items' => [
                        [
                            'type' => 'List',
                            'name' => 'SyncRules',
                            'caption' => 'Licht-Synchronisation',
                            'rowCount' => 5,
                            'add' => true,
                            'delete' => true,
                            'columns' => [
                                ['caption' => 'Master-Licht / Dimmer', 'name' => 'MasterVariableID', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                                ['caption' => 'Ziel-Licht (Slave)', 'name' => 'TargetLightID', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                            ],
                        ],
                    ],
                ],
                // --- Dependencies ---
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Abhaengigkeiten',
                    'items' => [
                        ['type' => 'SelectVariable', 'name' => 'SunsetVariableID', 'caption' => 'Variable fuer Sonnenuntergang (Unix Timestamp)'],
                        ['type' => 'SelectVariable', 'name' => 'SunriseVariableID', 'caption' => 'Variable fuer Sonnenaufgang (Unix Timestamp)'],
                    ],
                ],
                // --- Simulation ---
                [
                    'type' => 'CheckBox',
                    'name' => 'SimulationMode',
                    'caption' => 'Simulationsmodus (Testbetrieb)',
                ],
            ],
        ];

        return json_encode($form);
    }
}
