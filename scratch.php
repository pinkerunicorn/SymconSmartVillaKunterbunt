<?php
require_once '/var/lib/symcon/scripts/__ipsmodule.inc.php';
\ = IPS_GetInstanceListByModuleID('{F3B4A7D9-C59E-401A-B826-17D3B5C2849E}')[0];
echo IPS_GetProperty(\, 'DevicesGenericSensor');