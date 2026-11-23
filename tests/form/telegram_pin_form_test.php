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

namespace local_jedomi\form;

use local_jedomi\local\service\telegram_pin_service;

/**
 * Tests for the form that sets the Telegram PIN on the profile page.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\form\telegram_pin_form
 */
final class telegram_pin_form_test extends \advanced_testcase {
    /**
     * Submit the form with a PIN and its confirmation.
     *
     * @param string $pin PIN typed.
     * @param string $pinconfirm PIN typed again.
     * @return \stdClass|null Submitted data, or null when the form is not valid.
     */
    private function submit(string $pin, string $pinconfirm): ?\stdClass {
        telegram_pin_form::mock_submit(['pin' => $pin, 'pinconfirm' => $pinconfirm]);
        $form = new telegram_pin_form(new \moodle_url('/local/jedomi/telegram_profile.php'));
        return $form->get_data();
    }

    /**
     * A PIN of digits within the length range, typed twice, is accepted.
     *
     * @return void
     */
    public function test_valid_pin_is_returned(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $data = $this->submit('135790', '135790');

        $this->assertNotNull($data);
        $this->assertSame('135790', $data->pin);
        $this->assertNotNull($this->submit('12345678', '12345678'), 'The longest PIN is accepted');
    }

    /**
     * PINs that are not only digits within the length range.
     *
     * @return array
     */
    public static function invalid_pin_provider(): array {
        return [
            'letters' => ['12ab56'],
            'spaces' => ['123 456'],
            'symbols' => ['12-456'],
            'too short' => ['12345'],
            'too long' => ['123456789'],
            'empty' => [''],
        ];
    }

    /**
     * A PIN with anything but 6 to 8 digits is refused.
     *
     * @dataProvider invalid_pin_provider
     * @param string $pin PIN typed twice.
     * @return void
     */
    public function test_invalid_pin_is_refused(string $pin): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertNull($this->submit($pin, $pin));
    }

    /**
     * Two different PINs are refused with the mismatch message.
     *
     * @return void
     */
    public function test_mismatch_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertNull($this->submit('135790', '135791'));
        $form = new telegram_pin_form(new \moodle_url('/local/jedomi/telegram_profile.php'));
        $errors = $form->validation(['pin' => '135790', 'pinconfirm' => '135791'], []);
        $this->assertSame(get_string('telegram_pin_mismatch', 'local_jedomi'), $errors['pinconfirm']);
    }

    /**
     * The form shows the PIN format, has unmaskable fields and keeps the browser from filling the Moodle password.
     *
     * @return void
     */
    public function test_render_shows_format_and_disables_autofill(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = (new telegram_pin_form(new \moodle_url('/local/jedomi/telegram_profile.php')))->render();

        $format = get_string('telegram_pin_invalid', 'local_jedomi', (object) [
            'min' => telegram_pin_service::MIN_LENGTH,
            'max' => telegram_pin_service::MAX_LENGTH,
        ]);
        $this->assertStringContainsString(s($format), $html);
        $this->assertStringContainsString('name="pin"', $html);
        $this->assertStringContainsString('name="pinconfirm"', $html);
        $this->assertStringContainsString('autocomplete="new-password"', $html);
        $this->assertStringContainsString('data-passwordunmask="wrapper"', $html);
    }
}
