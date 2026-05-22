<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']           = 'Proveedor IA Jedomi';
$string['settings']             = 'Configuración del Gateway Jedomi';
$string['settings_help']        = 'Configura la conexión con el Gateway de IA de Jedomi. Los valores por defecto son los configurados en el plugin Jedomi Sidebar (local_jedomi).';
$string['gatewayurl']           = 'URL del Gateway';
$string['gatewayurl_help']      = 'URL del Gateway de Jedomi. Por defecto usa el valor de la configuración del plugin Jedomi Sidebar.';
$string['apikey']               = 'API Key';
$string['apikey_help']          = 'La API key de Jedomi. Por defecto usa el valor de la configuración del plugin Jedomi Sidebar.';
$string['defaultmodel']         = 'Modelo por defecto';
$string['defaultmodel_help']    = 'El modelo Jedomi a usar cuando no hay un modelo específico para la acción.';
$string['action:generate_text:model']                  = 'Modelo de generación de texto';
$string['action:generate_text:model_help']             = 'Modelo usado para acciones de generación de texto.';
$string['action:generate_text:systeminstruction']      = 'Instrucción de sistema';
$string['action:generate_text:systeminstruction_help'] = 'Prompt de sistema personalizado para generación de texto. Dejá vacío para usar el prompt por defecto de Jedomi.';
$string['action:summarise_text:model']                  = 'Modelo de resumen';
$string['action:summarise_text:model_help']             = 'Modelo usado para acciones de resumen.';
$string['action:summarise_text:systeminstruction']      = 'Instrucción de sistema';
$string['action:summarise_text:systeminstruction_help'] = 'Prompt de sistema personalizado para resúmenes. Dejá vacío para usar el prompt por defecto de Jedomi.';
$string['action:explain_text:model']                  = 'Modelo de explicación';
$string['action:explain_text:model_help']             = 'Modelo usado para acciones de explicación.';
$string['action:explain_text:systeminstruction']      = 'Instrucción de sistema';
$string['action:explain_text:systeminstruction_help'] = 'Prompt de sistema personalizado para explicaciones. Dejá vacío para usar el prompt por defecto de Jedomi.';
$string['privacy:metadata'] = 'El plugin proveedor de IA Jedomi no almacena datos personales. Los prompts del usuario se envían al Gateway de Jedomi configurado y no se retienen.';
