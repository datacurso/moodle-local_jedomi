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

namespace local_jedomi\proxy;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');

/**
 * Tests for the validation of the chat permissions (signed permission tokens) issued to the browser.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\proxy\spt_validator
 * @covers     \local_jedomi_generate_spt()
 */
final class spt_validator_test extends \advanced_testcase {
    /**
     * Sign a payload the way someone outside the site would, with a key of their choice.
     *
     * @param array $payload Permissions without signature.
     * @param string $key Signing key.
     * @return array Permissions with the signature.
     */
    private function sign_with(array $payload, string $key): array {
        unset($payload['signature']);
        ksort($payload);
        $payload['signature'] = hash_hmac('sha256', json_encode($payload), $key);
        return $payload;
    }

    /**
     * Whether the validator accepts some permissions.
     *
     * @param array $spt Permissions to validate.
     * @return bool
     */
    private function accepted(array $spt): bool {
        try {
            spt_validator::validate($spt);
            return true;
        } catch (\moodle_exception $e) {
            return false;
        }
    }

    /**
     * MDL-UNIT-015: the permissions issued to a user are accepted with the role they carry.
     */
    public function test_issued_permissions_are_accepted_with_their_role(): void {
        $this->resetAfterTest();
        set_config('spt_secret', 'site-own-secret-for-tests', 'local_jedomi');
        $this->setUser($this->getDataGenerator()->create_user());

        $spt = \local_jedomi_generate_spt('student');

        $this->assertSame('student', spt_validator::validate($spt));
    }

    /**
     * MDL-UNIT-015: a signature cannot be reused over other content: altered permissions are rejected.
     */
    public function test_altered_permissions_are_rejected(): void {
        $this->resetAfterTest();
        set_config('spt_secret', 'site-own-secret-for-tests', 'local_jedomi');
        $this->setUser($this->getDataGenerator()->create_user());
        $spt = \local_jedomi_generate_spt('student');

        $escalated = $spt;
        $escalated['role'] = 'admin';
        $this->assertFalse($this->accepted($escalated), 'Changing the role must invalidate the signature');

        $otheruser = $spt;
        $otheruser['userid'] = (int) get_admin()->id;
        $this->assertFalse($this->accepted($otheruser), 'Changing the user must invalidate the signature');
    }

    /**
     * MDL-UNIT-014: permissions signed with a key that is not the site secret are rejected.
     */
    public function test_permissions_signed_with_another_key_are_rejected(): void {
        $this->resetAfterTest();
        set_config('spt_secret', 'site-own-secret-for-tests', 'local_jedomi');
        $this->setUser($this->getDataGenerator()->create_user());
        $spt = \local_jedomi_generate_spt('student');

        $forged = $this->sign_with(array_merge($spt, ['role' => 'admin']), 'some-other-key');

        $this->assertFalse($this->accepted($forged));
    }

    /**
     * MDL-UNIT-014: with the default (empty) secret no fixed key produces permissions the server accepts.
     * [Pendiente:fail] Today the built-in key "jedomi-spt-secret" is used, so anyone can forge admin permissions.
     */
    public function test_permissions_forged_with_the_built_in_key_are_rejected(): void {
        $this->resetAfterTest();
        set_config('spt_secret', '', 'local_jedomi');
        $this->setUser($this->getDataGenerator()->create_user());
        $spt = \local_jedomi_generate_spt('student');

        $forged = $this->sign_with(array_merge($spt, ['role' => 'admin']), 'jedomi-spt-secret');
        $emptykey = $this->sign_with(array_merge($spt, ['role' => 'admin']), '');

        $this->assertFalse($this->accepted($forged), 'Permissions signed with the built-in key must be rejected');
        $this->assertFalse($this->accepted($emptykey), 'Permissions signed with an empty key must be rejected');
    }
}
