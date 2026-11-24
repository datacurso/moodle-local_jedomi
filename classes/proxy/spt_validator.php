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
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\proxy;

/**
 * Validates Signed Permission Tokens: signature, TTL and sesskey binding.
 *
 * An SPT is multi-use by design: the sidebar gets one when the page loads and sends it with every chat, history,
 * file and speech request of that page for up to 30 minutes. It is not a single-use request signature (unlike the
 * signatures of the companion web services, see \local_jedomi\companion_hmac): a copy is only usable with the same
 * Moodle session, because the token is bound to the session key, and every request using it must still pass the
 * session, session key, course and role checks of its endpoint.
 */
class spt_validator {
    /**
     * Validate an SPT (Signed Permission Token).
     *
     * @param  array $spt The signed permission token.
     * @return string     The resolved role (admin|manager|teacher|student).
     * @throws \moodle_exception
     */
    public static function validate(array $spt): string {
        require_once(__DIR__ . '/../../lib.php');

        // Guests and users not logged in never get usable chat permissions.
        if (!isloggedin() || isguestuser()) {
            throw new \moodle_exception('chat_error_guest', 'local_jedomi');
        }

        // Check sesskey binding. The fields come from the client, so a value of another type is rejected cleanly.
        $expectedhash = substr(hash('sha256', sesskey()), 0, 16);
        $sesskeyhash = $spt['sesskey_hash'] ?? null;
        if (!is_string($sesskeyhash) || !hash_equals($expectedhash, $sesskeyhash)) {
            throw new \moodle_exception('invalid_session', 'local_jedomi');
        }

        // Check TTL: only a whole number of seconds (or its digits) is an expiry.
        $expires = $spt['expires'] ?? null;
        $validexpiry = is_int($expires) || (is_string($expires) && ctype_digit($expires));
        if (!$validexpiry || time() >= (int) $expires) {
            throw new \moodle_exception('token_expired', 'local_jedomi');
        }

        // Reconstruct and verify signature.
        $secret = \local_jedomi_get_spt_secret();
        $expected = hash_hmac('sha256', \local_jedomi_spt_signing_string($spt), $secret);

        $signature = $spt['signature'] ?? null;
        if (!is_string($signature) || !hash_equals($expected, $signature)) {
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
