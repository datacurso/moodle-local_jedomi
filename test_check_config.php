<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

echo "=== Plugin Config ===\n";
$keys = ['gateway_url', 'api_key', 'model', 'spt_secret'];
foreach ($keys as $k) {
    $v = get_config('local_jedomi', $k);
    if ($v === false) $v = '(NOT SET)';
    if (strlen($v) > 40) $v = substr($v, 0, 40) . '...';
    echo "  {$k}: {$v}\n";
}

echo "\n=== JS config object ===\n";
// This is what the frontend receives
$config = [];
$config['gateway_url']  = get_config('local_jedomi', 'gateway_url') ?: 'http://localhost:8765';
$config['chatproxy_url'] = $CFG->wwwroot . '/local/jedomi/chatproxy.php';
$config['model']        = get_config('local_jedomi', 'model') ?: 'jedomi-e4b';
echo "  gateway_url: {$config['gateway_url']}\n";
echo "  chatproxy_url: {$config['chatproxy_url']}\n";
echo "  model: {$config['model']}\n";

echo "\n=== What's in the DB? ===\n";
global $DB;
$records = $DB->get_records_select('config_plugins', "plugin = 'local_jedomi' AND name IN ('gateway_url', 'api_key', 'model')");
foreach ($records as $r) {
    $val = $r->value;
    if (strlen($val) > 40) $val = substr($val, 0, 40) . '...';
    echo "  {$r->name} = {$val}\n";
}
