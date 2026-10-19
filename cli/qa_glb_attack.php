<?php
// QA destructivo del validador de modelos GLB (Zero Trust). Ejecuta validate_glb sobre cada *.glb del dir.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
$dir = __DIR__ . '/qa_glb';
$files = glob($dir . '/*.glb');
sort($files);
printf("%-34s %-8s %s\n", 'CASO', 'VEREDICTO', 'detalle');
foreach ($files as $f) {
    $content = file_get_contents($f);
    $name = basename($f);
    try {
        $info = \local_jedomi\stem\model_validator::validate_glb($content);
        printf("%-34s %-8s tri=%d meshes=%d imgs=%d bytes=%d\n", $name, 'ACEPTA', $info['triangles'], $info['meshes'], $info['images'], $info['bytes']);
    } catch (\moodle_exception $e) {
        printf("%-34s %-8s %s\n", $name, 'RECHAZA', $e->errorcode);
    } catch (\Throwable $t) {
        printf("%-34s %-8s %s: %s\n", $name, 'ERROR!', get_class($t), $t->getMessage());
    }
}
