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
     * @param  int    $line_start     First line to return (1-based).
     * @param  int    $line_count     Number of lines to return (capped at 200).
     * @param  string $hmac_signature HMAC-SHA256 hex signature from the gateway.
     * @param  int    $hmac_timestamp Unix timestamp from the request header.
     * @param  string $payload_hash   SHA256 hex of the request body.
     * @return array Normalised path, numbered content, total lines, returned range and existence flag.
     */
    public static function execute(
        string $path,
        int $line_start,
        int $line_count,
        string $hmac_signature,
        int $hmac_timestamp,
        string $payload_hash
    ): array {
        global $CFG;

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $validated = self::validate_parameters(
            self::execute_parameters(),
            compact(
                'path',
                'line_start',
                'line_count',
                'hmac_signature',
                'hmac_timestamp',
                'payload_hash'
            )
        );
        $path           = $validated['path'];
        $line_start     = max(1, $validated['line_start']);
        $line_count     = min(self::MAX_LINES, max(1, $validated['line_count']));
        $hmac_signature = $validated['hmac_signature'];
        $hmac_timestamp = $validated['hmac_timestamp'];
        $payload_hash   = $validated['payload_hash'];

        self::verify_hmac('jedomi_read_file', $hmac_timestamp, $payload_hash, $hmac_signature);

        // Normalise path: strip leading slash, resolve .. traversal
        $clean_path = ltrim(str_replace(['../', '..\\', '../'], '', $path), '/\\');
        $clean_path = str_replace('\\', '/', $clean_path);

        // Block sensitive files unconditionally
        foreach (self::BLOCKED_PATHS as $blocked) {
            if ($clean_path === $blocked || str_ends_with($clean_path, '/' . $blocked)) {
                \local_jedomi_log(sprintf(
                    '[local_jedomi] read_file BLOCKED sensitive path: %s ip=%s',
                    $clean_path,
                    getremoteaddr()
                ));
                throw new \moodle_exception('read_file_blocked', 'local_jedomi', '', $clean_path);
            }
        }

        // Enforce whitelist
        $allowed = false;
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($clean_path, $prefix)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new \moodle_exception('read_file_not_allowed', 'local_jedomi', '', $clean_path);
        }

        // Build absolute path and verify it's inside dirroot
        $abs_path = realpath($CFG->dirroot . '/' . $clean_path);
        $dirroot  = realpath($CFG->dirroot);

        if (!$abs_path || !str_starts_with($abs_path, $dirroot)) {
            return [
                'path'        => $clean_path,
                'content'     => '',
                'total_lines' => 0,
                'line_start'  => 0,
                'line_end'    => 0,
                'extension'   => pathinfo($clean_path, PATHINFO_EXTENSION),
                'exists'      => false,
            ];
        }

        if (!is_file($abs_path) || !is_readable($abs_path)) {
            return [
                'path'        => $clean_path,
                'content'     => '',
                'total_lines' => 0,
                'line_start'  => 0,
                'line_end'    => 0,
                'extension'   => pathinfo($clean_path, PATHINFO_EXTENSION),
                'exists'      => false,
            ];
        }

        // Read file
        $all_lines   = file($abs_path, FILE_IGNORE_NEW_LINES);
        $total_lines = count($all_lines);
        $line_start  = min($line_start, $total_lines);
        $line_end    = min($line_start + $line_count - 1, $total_lines);
        $slice       = array_slice($all_lines, $line_start - 1, $line_count);

        // Add line numbers to content
        $numbered = [];
        foreach ($slice as $i => $line) {
            $numbered[] = sprintf('%4d: %s', $line_start + $i, $line);
        }

        \local_jedomi_log(sprintf(
            '[local_jedomi] read_file: path=%s lines=%d-%d ip=%s',
            $clean_path,
            $line_start,
            $line_end,
            getremoteaddr()
        ));

        return [
            'path'        => $clean_path,
            'content'     => implode("\n", $numbered),
            'total_lines' => $total_lines,
            'line_start'  => $line_start,
            'line_end'    => $line_end,
            'extension'   => pathinfo($clean_path, PATHINFO_EXTENSION),
            'exists'      => true,
        ];
    }
}
