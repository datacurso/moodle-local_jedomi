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
 * Tests for the form of the General tab of the Jedomi settings.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\form\general_settings_form
 */
final class general_settings_form_test extends \advanced_testcase {
    /**
     * A submitted form returns the secret and the training data flag.
     *
     * @return void
     */
    public function test_submitted_values_are_returned(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        general_settings_form::mock_submit(['spt_secret' => 's3cret', 'collect_training_data' => 1]);

        $data = (new general_settings_form())->get_data();

        $this->assertSame('s3cret', $data->spt_secret);
        $this->assertSame(1, (int) $data->collect_training_data);
    }

    /**
     * An unchecked training data box is returned as 0.
     *
     * @return void
     */
    public function test_unchecked_training_data_is_zero(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        general_settings_form::mock_submit(['spt_secret' => '']);

        $data = (new general_settings_form())->get_data();

        $this->assertSame(0, (int) $data->collect_training_data);
    }
}
