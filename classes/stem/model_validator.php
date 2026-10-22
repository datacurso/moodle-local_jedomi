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
 * Validation of the 3D models a teacher attaches to an activity: only self-contained binary glTF (GLB),
 * no external URIs (SSRF, tracking), with size and complexity limits (denial of service in the student browser).
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model_validator {
    /** @var int Maximum size of the GLB file in bytes. */
    public const MAX_BYTES = 30 * 1024 * 1024;
    /** @var int Maximum number of triangles (sum over indexed primitives). */
    public const MAX_TRIANGLES = 1500000;
    /** @var int Maximum number of embedded images. */
    public const MAX_IMAGES = 16;

    /**
     * Validates the content of a GLB and returns a summary; throws moodle_exception when it is not acceptable.
     *
     * @param string $content File bytes.
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
        if ($chunk['type'] !== 0x4E4F534A || 20 + $chunk['len'] > $len) {   // Chunk type 'JSON'.
            throw new \moodle_exception('stem_model_not_glb', 'local_jedomi');
        }
        $json = json_decode(substr($content, 20, $chunk['len']), true);
        if (!is_array($json) || empty($json['asset'])) {
            throw new \moodle_exception('stem_model_not_glb', 'local_jedomi');
        }
        // Self-contained: no «uri» key at ANY level of the JSON (buffers, images, extensions…), not even data: URIs.
        $found = self::find_uri($json);
        if ($found !== null) {
            throw new \moodle_exception('stem_model_external_uri', 'local_jedomi', '', s(substr($found, 0, 60)));
        }
        // Second chunk (BIN): type and length consistent with the file.
        $off = 20 + $chunk['len'];
        if ($off + 8 <= $len) {
            $bin = unpack('Vlen/Vtype', substr($content, $off, 8));
            if ($bin['type'] !== 0x004E4942 || $off + 8 + $bin['len'] > $len) {   // Chunk type 'BIN\0'.
                throw new \moodle_exception('stem_model_not_glb', 'local_jedomi');
            }
        }
        if (!empty($json['extensionsRequired'])) {
            // Required extensions (Draco, KTX2…) need decoders: they are rejected until the viewer supports them.
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
        return [
            'triangles' => $triangles,
            'meshes' => count($json['meshes'] ?? []),
            'images' => count($json['images'] ?? []),
            'bytes' => $len,
        ];
    }

    /**
     * Searches the glTF JSON recursively for a string «uri» key.
     *
     * @param mixed $node JSON node.
     * @return string|null The first uri found, or null.
     */
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
}
