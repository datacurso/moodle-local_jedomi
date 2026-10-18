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

/**
 * SPT (Signed Permission Token) validator.
 *
 * Validates SPT signature, TTL, and sesskey binding. Throws moodle_exception
 * on failure instead of using die().
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\proxy;

defined('MOODLE_INTERNAL') || die();

class spt_validator {

    /**
     * Validate an SPT (Signed Permission Token).
     *
     * @param  array $spt The signed permission token.
     * @return string     The resolved role (admin|manager|teacher|student).
     * @throws \moodle_exception
     */
    public static function validate(array $spt): string {
        // Check sesskey binding.
        $expectedHash = substr(hash('sha256', sesskey()), 0, 16);
        if (!hash_equals($expectedHash, $spt['sesskey_hash'] ?? '')) {
            throw new \moodle_exception('invalid_session', 'local_jedomi');
        }

        // Check TTL.
        if (time() >= ($spt['expires'] ?? 0)) {
            throw new \moodle_exception('token_expired', 'local_jedomi');
        }

        // Bind the token to the logged-in user (QA r7: a token with another userid was accepted).
        global $USER;
        if (empty($USER->id) || (int) ($spt['userid'] ?? 0) !== (int) $USER->id) {
            throw new \moodle_exception('invalid_signature', 'local_jedomi');
        }

        // Reconstruct and verify signature.
        require_once(__DIR__ . '/../../lib.php');
        $secret = \local_jedomi_spt_secret();
        $payload = $spt;
        unset($payload['signature']);
        ksort($payload);
        $expected = hash_hmac('sha256', json_encode($payload), $secret);

        if (!hash_equals($expected, $spt['signature'] ?? '')) {
            throw new \moodle_exception('invalid_signature', 'local_jedomi');
        }

        return $spt['role'] ?? 'student';
    }

    /**
     * Get tool definitions for a given role in OpenAI function-calling format.
     *
     * @param  string $role User role: admin, manager, teacher, student.
     * @return array        Array of OpenAI-style tool definitions.
     */
    public static function get_tool_definitions(string $role): array {
        return \local_jedomi_get_tool_definitions($role);
    }
}
