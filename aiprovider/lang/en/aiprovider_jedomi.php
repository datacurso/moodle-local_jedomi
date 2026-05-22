<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']           = 'Jedomi AI Provider';
$string['settings']             = 'Jedomi Gateway Settings';
$string['settings_help']        = 'Configure the connection to your Jedomi AI Gateway. Values default to those set in the Jedomi Sidebar plugin (local_jedomi).';
$string['gatewayurl']           = 'Gateway URL';
$string['gatewayurl_help']      = 'URL of the Jedomi Gateway. Defaults to the value configured in the Jedomi Sidebar settings.';
$string['apikey']               = 'API Key';
$string['apikey_help']          = 'The Jedomi API key. Defaults to the value configured in the Jedomi Sidebar settings.';
$string['defaultmodel']         = 'Default model';
$string['defaultmodel_help']    = 'The Jedomi model to use when no action-specific model is configured.';
$string['action:generate_text:model']                  = 'Text generation model';
$string['action:generate_text:model_help']             = 'Model used for text generation actions.';
$string['action:generate_text:systeminstruction']      = 'System instruction';
$string['action:generate_text:systeminstruction_help'] = 'Custom system prompt for text generation. Leave empty to use the Jedomi default.';
$string['action:summarise_text:model']                  = 'Summarisation model';
$string['action:summarise_text:model_help']             = 'Model used for summarisation actions.';
$string['action:summarise_text:systeminstruction']      = 'System instruction';
$string['action:summarise_text:systeminstruction_help'] = 'Custom system prompt for summarisation. Leave empty to use the Jedomi default.';
$string['action:explain_text:model']                  = 'Explanation model';
$string['action:explain_text:model_help']             = 'Model used for explanation actions.';
$string['action:explain_text:systeminstruction']      = 'System instruction';
$string['action:explain_text:systeminstruction_help'] = 'Custom system prompt for explanations. Leave empty to use the Jedomi default.';
$string['privacy:metadata'] = 'The Jedomi AI provider plugin does not store any personal data. User prompts are sent to the configured Jedomi Gateway and are not retained.';
