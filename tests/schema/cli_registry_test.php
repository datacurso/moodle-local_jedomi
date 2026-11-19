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

namespace local_jedomi\schema;

/**
 * Tests for the administration operations offered to the AI.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\schema\cli_registry
 */
final class cli_registry_test extends \advanced_testcase {
    /**
     * SEC-JD-029: a backup folder is accepted only when it exists inside dataroot, given relative or absolute.
     */
    public function test_backup_folder_must_be_inside_dataroot(): void {
        global $CFG;
        $this->resetAfterTest();
        $inside = make_writable_directory($CFG->dataroot . '/jedomi_backups');
        $root = realpath($CFG->dataroot);

        $this->assertSame(realpath($inside), cli_registry::backup_folder('jedomi_backups'));
        $this->assertSame(realpath($inside), cli_registry::backup_folder($inside));
        $this->assertSame($root, cli_registry::backup_folder($CFG->dataroot));

        $this->assertNull(cli_registry::backup_folder($CFG->dirroot), 'The Moodle code folder is outside dataroot');
        $this->assertNull(cli_registry::backup_folder('/'));
        $this->assertNull(cli_registry::backup_folder('jedomi_backups/../..'), 'Traversal out of dataroot');
        $this->assertNull(cli_registry::backup_folder('no_such_folder'), 'A folder that does not exist');
        $this->assertNull(cli_registry::backup_folder(''));
        $this->assertNull(cli_registry::backup_folder("jedomi_backups\0/x"));
    }

    /**
     * SEC-JD-029: a symbolic link inside dataroot that leads out of it is refused.
     */
    public function test_backup_folder_refuses_a_link_out_of_dataroot(): void {
        global $CFG;
        $this->resetAfterTest();
        $outside = make_request_directory();
        $link = $CFG->dataroot . '/jedomi_link_out';
        if (strpos((string) realpath($outside), (string) realpath($CFG->dataroot) . DIRECTORY_SEPARATOR) === 0) {
            $this->markTestSkipped('The request directory is inside dataroot on this site.');
        }
        if (!@symlink($outside, $link)) {
            $this->markTestSkipped('Symbolic links cannot be created here.');
        }
        try {
            $this->assertNull(cli_registry::backup_folder('jedomi_link_out'));
            $this->assertNull(cli_registry::backup_folder($link));
        } finally {
            @unlink($link);
        }
    }

    /**
     * SEC-JD-029: a backup with a destination outside dataroot is refused before it is made, and nothing is written there.
     */
    public function test_backup_with_a_destination_outside_dataroot_is_refused(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $outside = $CFG->dirroot . '/local/jedomi';
        $before = $DB->count_records('backup_controllers');

        $result = cli_registry::execute(
            'backup',
            ['courseid' => (int) $course->id, 'destination' => $outside],
            (int) get_admin()->id
        );

        $this->assertSame('destination_outside_dataroot', $result['error'] ?? null);
        $this->assertSame(get_string('cli_backup_destination_outside_dataroot', 'local_jedomi'), $result['message']);
        $this->assertSame($before, $DB->count_records('backup_controllers'), 'No backup was started');
        $this->assertEmpty(glob($outside . '/*.mbz'));
    }

    /**
     * SEC-JD-029: a backup with a folder inside dataroot is stored there.
     */
    public function test_backup_with_a_destination_inside_dataroot_is_stored_there(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $inside = make_writable_directory($CFG->dataroot . '/jedomi_backups');

        $result = cli_registry::execute(
            'backup',
            ['courseid' => (int) $course->id, 'destination' => 'jedomi_backups'],
            (int) get_admin()->id
        );

        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertStringStartsWith(realpath($inside) . DIRECTORY_SEPARATOR, $result['backup']['stored_at']);
        $this->assertFileExists($result['backup']['stored_at']);
    }

    /**
     * A backup whose copy to the destination fails keeps the stored backup file and returns an error.
     *
     * @return void
     */
    public function test_failed_backup_copy_keeps_the_stored_file(): void {
        $this->resetAfterTest();
        $dir = make_request_directory();
        $file = $this->createMock(\stored_file::class);
        $file->method('copy_content_to')->willReturn(false);
        $file->expects($this->never())->method('delete');

        $result = cli_registry::store_backup_copy($file, $dir, 'backup.mbz');

        $this->assertSame('copy to the destination failed', $result['error']);
        $this->assertArrayNotHasKey('stored_at', $result);
    }

    /**
     * A destination that is a link is refused as a link, and the stored backup file is kept.
     *
     * @return void
     */
    public function test_backup_copy_to_a_link_is_refused(): void {
        $this->resetAfterTest();
        $dir = make_request_directory();
        if (!@symlink($dir . '/elsewhere.mbz', $dir . '/backup.mbz')) {
            $this->markTestSkipped('Symbolic links cannot be created here.');
        }
        $file = $this->createMock(\stored_file::class);
        $file->expects($this->never())->method('copy_content_to');
        $file->expects($this->never())->method('delete');

        $result = cli_registry::store_backup_copy($file, $dir, 'backup.mbz');

        $this->assertSame('destination is a link', $result['error']);
    }

    /**
     * A successful copy deletes the stored backup file and returns where the copy is.
     *
     * @return void
     */
    public function test_successful_backup_copy_returns_the_destination(): void {
        $this->resetAfterTest();
        $dir = make_request_directory();
        $file = $this->createMock(\stored_file::class);
        $file->method('copy_content_to')->willReturn(true);
        $file->expects($this->once())->method('delete');

        $result = cli_registry::store_backup_copy($file, $dir, 'backup.mbz');

        $this->assertSame(['stored_at' => $dir . DIRECTORY_SEPARATOR . 'backup.mbz'], $result);
    }
}
