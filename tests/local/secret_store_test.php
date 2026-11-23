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

namespace local_jedomi\local;

/**
 * Tests for the secret store: a secret that cannot be decrypted is recorded so the administrator is warned.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\secret_store
 */
final class secret_store_test extends \advanced_testcase {
    /**
     * A value encrypted with the key of the site and then altered, as after a change of that key.
     *
     * @return string
     */
    public static function unreadable_value(): string {
        [$method, $data] = explode(':', secret_store::encrypt('sk-secret'), 2);
        $raw = base64_decode($data);
        $raw[30] = chr(ord($raw[30]) ^ 0x01);
        return $method . ':' . base64_encode($raw);
    }

    /**
     * A secret that cannot be decrypted reads empty and is recorded as unreadable; saving it again clears the record.
     *
     * @return void
     */
    public function test_unreadable_secret_is_recorded_until_saved_again(): void {
        $this->resetAfterTest();
        set_config('custom_api_key', self::unreadable_value(), 'local_jedomi');

        $this->assertSame('', secret_store::get('custom_api_key'));
        $this->assertTrue(secret_store::is_unreadable('custom_api_key'));
        $this->assertSame('1', get_config('local_jedomi', 'secret_unreadable_custom_api_key'));

        secret_store::set('custom_api_key', 'sk-new');

        $this->assertFalse(secret_store::is_unreadable('custom_api_key'));
        $this->assertFalse(get_config('local_jedomi', 'secret_unreadable_custom_api_key'));
        $this->assertSame('sk-new', secret_store::get('custom_api_key'));
    }

    /**
     * A secret that decrypts again (the key of the site was restored) clears the record; an empty one is not unreadable.
     *
     * @return void
     */
    public function test_readable_secret_clears_the_record(): void {
        $this->resetAfterTest();
        $this->assertFalse(secret_store::is_unreadable('custom_api_key'), 'A secret not saved is not unreadable');

        set_config('custom_api_key', secret_store::encrypt('sk-ok'), 'local_jedomi');
        secret_store::mark_unreadable('custom_api_key');

        $this->assertSame('sk-ok', secret_store::get('custom_api_key'));
        $this->assertFalse(get_config('local_jedomi', 'secret_unreadable_custom_api_key'));
        $this->assertFalse(secret_store::is_unreadable('custom_api_key'));
    }
}
