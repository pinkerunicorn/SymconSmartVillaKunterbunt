<?php
class IPSModuleStrict {
    protected \;
    public function __construct(\) { \->InstanceID = \; }
    protected function RegisterPropertyString(\, \){}
    protected function RegisterPropertyInteger(\, \){}
    protected function RegisterPropertyBoolean(\, \){}
    protected function RegisterTimer(\, \, \){}
    protected function ReadPropertyString(\) { return '[]'; }
    protected function ReadPropertyInteger(\) { return 0; }
    protected function ReadPropertyBoolean(\) { return false; }
    protected function RegisterVariableString(\, \, \, \){}
    protected function GetIDForIdent(\) { return 0; }
    protected function SendDebug(\, \, \){}
}
function IPS_GetObjectIDByIdent(\, \) { return false; }
function IPS_GetChildrenIDs(\) { return []; }
function IPS_GetName(\) { return "Test"; }
function IPS_GetLocation(\) { return "Test"; }
require_once 'C:\Users\grass\Documents\Symcon\SymconSmartVillaKunterbunt\DeviceRegistry\module.php';

\ = new SymconDeviceRegistry(12345);
\ = \->GetConfigurationForm();
\ = json_decode(\);
if (\ === null) {
    echo "INVALID JSON:\n" . json_last_error_msg() . "\n";
    // echo \;
} else {
    echo "VALID JSON\n";
}