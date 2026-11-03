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

/**
 * Tests for the Telegram form of the Integrations tab of the Jedomi settings.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\form\telegram_settings_form
 */
final class telegram_settings_form_test extends \advanced_testcase {
    /**
     * Valid values for every field.
     *
     * @return array
     */
    private function valid_submission(): array {
        return [
            'telegram_bot_token' => '',
            'telegram_rate_limit' => 30,
            'telegram_cooldown' => 5,
            'telegram_max_turns' => 10,
            'telegram_gateway_timeout' => 120,
            'telegram_role_visible_3' => 0,
            'telegram_role_visible_5' => 1,
        ];
    }

    /**
     * A valid submission returns the limits and the visible roles.
     *
     * @return void
     */
    public function test_valid_submission_is_returned(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $roles = [['id' => 3, 'shortname' => 'editingteacher', 'name' => 'Teacher'],
            ['id' => 5, 'shortname' => 'student', 'name' => 'Student']];
        telegram_settings_form::mock_submit($this->valid_submission());

        $data = (new telegram_settings_form(null, ['roles' => $roles, 'tokenset' => false]))->get_data();

        $this->assertSame(30, (int) $data->telegram_rate_limit);
        $this->assertSame(120, (int) $data->telegram_gateway_timeout);
        $this->assertSame([5], telegram_settings_form::visible_role_ids($data, $roles));
        $this->assertEmpty($data->regeneratesecret ?? '');
    }

    /**
     * Values outside the allowed ranges.
     *
     * @return array
     */
    public static function out_of_range_provider(): array {
        return [
            'rate limit above 1000' => ['telegram_rate_limit', 1001],
            'negative cooldown' => ['telegram_cooldown', -1],
            'zero max turns' => ['telegram_max_turns', 0],
            'timeout below 5' => ['telegram_gateway_timeout', 4],
            'timeout above 300' => ['telegram_gateway_timeout', 301],
        ];
    }

    /**
     * Values outside the allowed ranges are rejected.
     *
     * @dataProvider out_of_range_provider
     * @param string $field Field name.
     * @param int $value Submitted value.
     * @return void
     */
    public function test_out_of_range_values_are_rejected(string $field, int $value): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        telegram_settings_form::mock_submit([$field => $value] + $this->valid_submission());

        $form = new telegram_settings_form(null, ['roles' => [], 'tokenset' => true]);

        $this->assertNull($form->get_data());
    }
}
