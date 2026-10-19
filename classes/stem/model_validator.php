<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_jedomi\stem;

/**
 * Validación de modelos 3D que el docente adjunta a una actividad (QA r11 #3): sólo GLB binario autocontenido,
 * sin URIs externas (SSRF/tracking), con topes de tamaño y de complejidad (DoS en el navegador del alumno).
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model_validator {

    /** Tamaño máximo del fichero GLB. */
    public const MAX_BYTES = 30 * 1024 * 1024;
    /** Triángulos máximos (suma de primitivas indexadas). */
    public const MAX_TRIANGLES = 1500000;
    /** Imágenes embebidas máximas. */
    public const MAX_IMAGES = 16;

    /**
     * Valida el contenido de un GLB. Devuelve un resumen (triángulos, mallas, imágenes) o lanza moodle_exception.
     *
     * @param string $content bytes del fichero
     * @return array ['triangles' => int, 'meshes' => int, 'images' => int, 'bytes' => int]
     */
    public static function validate_glb(string $content): array {
        $len = strlen($content);
        if ($len > self::MAX_BYTES) {
            throw new \moodle_exception('stem_model_too_big', 'local_jedomi', '', round(self::MAX_BYTES / 1048576));
        }
        if ($len < 20 || substr($content, 0, 4) !== 'glTF') {
            throw new \moodle_exception('stem_model_not_glb', 'local_jedomi');
        }
        $hdr = unpack('Vmagic/Vversion/Vlength', substr($content, 0, 12));
        if ((int) $hdr['version'] !== 2 || (int) $hdr['length'] !== $len) {
            throw new \moodle_exception('stem_model_not_glb', 'local_jedomi');
        }
        $chunk = unpack('Vlen/Vtype', substr($content, 12, 8));
        if ($chunk['type'] !== 0x4E4F534A || 20 + $chunk['len'] > $len) {   // 'JSON'
            throw new \moodle_exception('stem_model_not_glb', 'local_jedomi');
        }
        $json = json_decode(substr($content, 20, $chunk['len']), true);
        if (!is_array($json) || empty($json['asset'])) {
            throw new \moodle_exception('stem_model_not_glb', 'local_jedomi');
        }
        // Autocontenido: ninguna clave «uri» en NINGÚN nivel del JSON (buffers, imágenes, extensiones…), ni siquiera data: (QA P13-J).
        $found = self::find_uri($json);
        if ($found !== null) {
            throw new \moodle_exception('stem_model_external_uri', 'local_jedomi', '', s(substr($found, 0, 60)));
        }
        // Segundo chunk (BIN): tipo y longitud coherentes con el fichero (QA P13-M).
        $off = 20 + $chunk['len'];
        if ($off + 8 <= $len) {
            $bin = unpack('Vlen/Vtype', substr($content, $off, 8));
            if ($bin['type'] !== 0x004E4942 || $off + 8 + $bin['len'] > $len) {   // 'BIN\0'
                throw new \moodle_exception('stem_model_not_glb', 'local_jedomi');
            }
        }
        if (!empty($json['extensionsRequired'])) {
            // Extensiones obligatorias (Draco, KTX2…) requieren decodificadores: se rechazan hasta soportarlas en el visor.
            throw new \moodle_exception('stem_model_extension', 'local_jedomi', '', s(implode(', ', $json['extensionsRequired'])));
        }
        $triangles = 0;
        $accessors = $json['accessors'] ?? [];
        foreach ($json['meshes'] ?? [] as $mesh) {
            foreach ($mesh['primitives'] ?? [] as $prim) {
                if (isset($prim['indices'], $accessors[$prim['indices']]['count'])) {
                    $triangles += intdiv((int) $accessors[$prim['indices']]['count'], 3);
                } else if (isset($prim['attributes']['POSITION'], $accessors[$prim['attributes']['POSITION']]['count'])) {
                    $triangles += intdiv((int) $accessors[$prim['attributes']['POSITION']]['count'], 3);
                }
            }
        }
        if ($triangles > self::MAX_TRIANGLES) {
            throw new \moodle_exception('stem_model_too_complex', 'local_jedomi', '', $triangles);
        }
        if (count($json['images'] ?? []) > self::MAX_IMAGES) {
            throw new \moodle_exception('stem_model_too_complex', 'local_jedomi', '', 'images');
        }
        return ['triangles' => $triangles, 'meshes' => count($json['meshes'] ?? []), 'images' => count($json['images'] ?? []), 'bytes' => $len];
    }

    /** Busca recursivamente una clave «uri» (string) en el JSON del glTF; devuelve su valor o null. */
    private static function find_uri($node): ?string {
        if (!is_array($node)) {
            return null;
        }
        foreach ($node as $k => $v) {
            if ($k === 'uri' && is_string($v)) {
                return $v;
            }
            if (is_array($v)) {
                $r = self::find_uri($v);
                if ($r !== null) {
                    return $r;
                }
            }
        }
        return null;
    }

    /** Devuelve el fichero GLB de la actividad o null. */
    public static function get_model(\context_module $context): ?\stored_file {
        $files = get_file_storage()->get_area_files($context->id, 'local_jedomi', 'stem_model', 0, 'itemid, filepath, filename', false);
        return $files ? reset($files) : null;
    }
}
