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

namespace local_jedomi;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');

/**
 * Tests for the files served by the plugin: the custom avatar of the panel.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::local_jedomi_pluginfile
 */
final class pluginfile_test extends \advanced_testcase {
    /**
     * Serve a file of the plugin as pluginfile.php does.
     *
     * Sending the file closes the output buffers, so only whether the file was served is returned.
     *
     * @param \context $context Context of the request.
     * @param string $filearea File area.
     * @param array $args Item id, then the file name.
     * @return bool Whether the file was served.
     */
    private function serve(\context $context, string $filearea, array $args): bool {
        $level = ob_get_level();
        ob_start();
        // The headers cannot be sent under PHPUnit, which already wrote its own output: that warning is silenced.
        $result = @local_jedomi_pluginfile(null, null, $context, $filearea, $args, false, ['dontdie' => true]);
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
        // Give PHPUnit back the buffers that sending the file closed.
        while (ob_get_level() < $level) {
            ob_start();
        }
        return $result !== false;
    }

    /**
     * Store a custom avatar.
     *
     * @return void
     */
    private function store_avatar(): void {
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_jedomi',
            'filearea' => 'customavatar',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'robo.png',
        ], ''); // Empty: the served bytes go straight to the test output.
    }

    /**
     * The custom avatar is served to a logged-in user.
     *
     * @return void
     */
    public function test_custom_avatar_is_served_to_logged_in_users(): void {
        $this->resetAfterTest();
        $this->store_avatar();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertTrue($this->serve(\context_system::instance(), 'customavatar', [0, 'robo.png']));
    }

    /**
     * Visitors without a session and guests do not get the avatar, nor any other area or context.
     *
     * @return void
     */
    public function test_custom_avatar_needs_a_session_and_the_right_area(): void {
        $this->resetAfterTest();
        $this->store_avatar();
        $system = \context_system::instance();

        $this->setUser(null);
        $this->assertFalse($this->serve($system, 'customavatar', [0, 'robo.png']));
        $this->setGuestUser();
        $this->assertFalse($this->serve($system, 'customavatar', [0, 'robo.png']));

        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse($this->serve($system, 'other', [0, 'robo.png']));
        $this->assertFalse($this->serve($system, 'customavatar', [0, 'missing.png']));
        $course = $this->getDataGenerator()->create_course();
        $this->assertFalse($this->serve(\context_course::instance($course->id), 'customavatar', [0, 'robo.png']));
    }
}
