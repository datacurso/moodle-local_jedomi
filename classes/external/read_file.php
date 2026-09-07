<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * jedomi_read_file — CompanionWS external function.
 *
 * Lee un archivo del código fuente de Moodle para diagnóstico.
 * El 23% de los casos del corpus requieren leer código PHP para
 * entender y resolver el problema.
 *
 * Seguridad:
 *  - Solo permite paths dentro de $CFG->dirroot
 *  - Blocklist de paths con credenciales (config.php, .env, etc.)
 *  - Whitelist de prefijos permitidos (lib/, mod/, admin/, etc.)
 *  - HMAC auth obligatorio
 *  - Requiere moodle/site:config
 *  - Máximo 200 líneas por llamada
 *  - Nunca retorna config.php ni archivos con passwords
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\external;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_jedomi\companion_hmac;

/**
 * External function that reads a slice of a Moodle source file with path allowlist and blocklist checks.
 */
class read_file extends external_api {
    use companion_hmac;

    /** Maximum lines to return per call. */
    private const MAX_LINES = 200;

    /**
     * Paths that can NEVER be read — contain credentials or sensitive data.
     */
    private const BLOCKED_PATHS = [
        'config.php',
        'config-dist.php',
        '.env',
        '.htpasswd',
        'auth/db/config.php',
    ];

    /**
     * Directories that may contain Moodle source code (readable).
     * Everything outside this list is blocked.
     */
    private const ALLOWED_PREFIXES = [
        'lib/',
        'admin/',
        'grade/',
        'enrol/',
        'auth/',
        'mod/',
        'blocks/',
        'local/',
        'report/',
        'theme/',
        'backup/',
        'course/',
        'user/',
        'message/',
        'calendar/',
        'question/',
        'group/',
        'cohort/',
        'completion/',
        'webservice/',
        'analytics/',
        'files/',
        'cache/',
        'output/',
        'classes/',
    ];

    // -------------------------------------------------------------------------

    /**
     * Describe the parameters accepted by the execute function.
     *
     * @return external_function_parameters File path, line range and HMAC auth fields.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'path'           => new external_value(PARAM_PATH, 'Relative path from Moodle dirroot'),
            'line_start'     => new external_value(PARAM_INT, 'First line to return (1-based)', VALUE_DEFAULT, 1),
            'line_count'     => new external_value(PARAM_INT, 'Number of lines to return (max 200)', VALUE_DEFAULT, 100),
            'hmac_signature' => new external_value(PARAM_ALPHANUMEXT, 'HMAC-SHA256 hex signature'),
            'hmac_timestamp' => new external_value(PARAM_INT, 'Unix timestamp'),
            'payload_hash'   => new external_value(PARAM_ALPHANUMEXT, 'SHA256 hex of request body'),
        ]);
    }

    /**
     * Describe the structure returned by the execute function.
     *
     * @return external_single_structure Normalised path, numbered content, line range and existence flag.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'path'        => new external_value(PARAM_TEXT, 'Normalized path that was read'),
            'content'     => new external_value(PARAM_RAW, 'File content (requested lines)'),
            'total_lines' => new external_value(PARAM_INT, 'Total lines in file'),
            'line_start'  => new external_value(PARAM_INT, 'First line returned'),
            'line_end'    => new external_value(PARAM_INT, 'Last line returned'),
            'extension'   => new external_value(PARAM_TEXT, 'File extension'),
            'exists'      => new external_value(PARAM_BOOL, 'Whether the file exists'),
        ]);
    }

    /**
     * Authenticate the request and return up to 200 numbered lines from an allowed source file.
     *
     * @param  string $path           Relative path from Moodle dirroot.
     * @param  int    $linestart     First line to return (1-based).
     * @param  int    $linecount     Number of lines to return (capped at 200).
     * @param  string $hmacsignature HMAC-SHA256 hex signature from the gateway.
     * @param  int    $hmactimestamp Unix timestamp from the request header.
     * @param  string $payloadhash   SHA256 hex of the request body.
     * @return array Normalised path, numbered content, total lines, returned range and existence flag.
     */
    public static function execute(
        string $path,
        int $linestart,
        int $linecount,
        string $hmacsignature,
        int $hmactimestamp,
        string $payloadhash
    ): array {
        global $CFG;

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $validated = self::validate_parameters(
            self::execute_parameters(),
            [
                'path'           => $path,
                'line_start'     => $linestart,
                'line_count'     => $linecount,
                'hmac_signature' => $hmacsignature,
                'hmac_timestamp' => $hmactimestamp,
                'payload_hash'   => $payloadhash,
            ]
        );
        $path           = $validated['path'];
        $linestart     = max(1, $validated['line_start']);
        $linecount     = min(self::MAX_LINES, max(1, $validated['line_count']));
        $hmacsignature = $validated['hmac_signature'];
        $hmactimestamp = $validated['hmac_timestamp'];
        $payloadhash   = $validated['payload_hash'];

        self::verify_hmac('jedomi_read_file', $hmactimestamp, $payloadhash, $hmacsignature);

        // Normalise separators only. No traversal "cleaning" is attempted:
        // any path with a .. segment is rejected outright further down.
        $cleanpath = ltrim(str_replace('\\', '/', $path), '/');

        // Block sensitive files unconditionally.
        foreach (self::BLOCKED_PATHS as $blocked) {
            if ($cleanpath === $blocked || str_ends_with($cleanpath, '/' . $blocked)) {
                \local_jedomi_log(sprintf(
                    '[local_jedomi] read_file BLOCKED sensitive path: %s ip=%s',
                    $cleanpath,
                    getremoteaddr()
                ));
                throw new \moodle_exception('read_file_blocked', 'local_jedomi', '', $cleanpath);
            }
        }

        // Enforce whitelist.
        $allowed = false;
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($cleanpath, $prefix)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new \moodle_exception('read_file_not_allowed', 'local_jedomi', '', $cleanpath);
        }

        // Build absolute path and verify it's inside dirroot.
        $abspath = self::resolve_inside_root($cleanpath, $CFG->dirroot);

        if ($abspath === null) {
            \local_jedomi_log(sprintf(
                '[local_jedomi] read_file BLOCKED path outside dirroot: %s ip=%s',
                $cleanpath,
                getremoteaddr()
            ));
            return [
                'path'        => $cleanpath,
                'content'     => '',
                'total_lines' => 0,
                'line_start'  => 0,
                'line_end'    => 0,
                'extension'   => pathinfo($cleanpath, PATHINFO_EXTENSION),
                'exists'      => false,
            ];
        }

        if (!is_file($abspath) || !is_readable($abspath)) {
            return [
                'path'        => $cleanpath,
                'content'     => '',
                'total_lines' => 0,
                'line_start'  => 0,
                'line_end'    => 0,
                'extension'   => pathinfo($cleanpath, PATHINFO_EXTENSION),
                'exists'      => false,
            ];
        }

        // Read file.
        $alllines   = file($abspath, FILE_IGNORE_NEW_LINES);
        $totallines = count($alllines);
        $linestart  = min($linestart, $totallines);
        $lineend    = min($linestart + $linecount - 1, $totallines);
        $slice       = array_slice($alllines, $linestart - 1, $linecount);

        // Add line numbers to content.
        $numbered = [];
        foreach ($slice as $i => $line) {
            $numbered[] = sprintf('%4d: %s', $linestart + $i, $line);
        }

        \local_jedomi_log(sprintf(
            '[local_jedomi] read_file: path=%s lines=%d-%d ip=%s',
            $cleanpath,
            $linestart,
            $lineend,
            getremoteaddr()
        ));

        return [
            'path'        => $cleanpath,
            'content'     => implode("\n", $numbered),
            'total_lines' => $totallines,
            'line_start'  => $linestart,
            'line_end'    => $lineend,
            'extension'   => pathinfo($cleanpath, PATHINFO_EXTENSION),
            'exists'      => true,
        ];
    }

    /**
     * Resolve a relative path against a root directory, enforcing the boundary.
     *
     * No input cleaning is attempted: any path containing a traversal segment
     * is rejected outright, and the realpath() result must be the root itself
     * or live under root plus a separator — a sibling directory whose name
     * shares the root's prefix (e.g. moodle-backup/ next to moodle/) must
     * never pass.
     *
     * @param string $cleanpath Relative path (forward slashes, no leading slash)
     * @param string $rootdir Absolute allowed root directory
     * @return string|null Resolved absolute path, or null when rejected
     */
    private static function resolve_inside_root(string $cleanpath, string $rootdir): ?string {
        if (preg_match('~(^|/)\.\.(/|$)~', $cleanpath)) {
            return null;
        }
        $abspath = realpath($rootdir . '/' . $cleanpath);
        $realroot = realpath($rootdir);
        if ($abspath === false || $realroot === false) {
            return null;
        }
        if ($abspath !== $realroot && !str_starts_with($abspath, $realroot . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $abspath;
    }
}
