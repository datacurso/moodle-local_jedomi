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
 * Tests for what an exception may tell the technical log, the AI model and the user (JEDOMI-SEC-009).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\safe_error
 */
final class safe_error_test extends \advanced_testcase {
    /**
     * JEDOMI-SEC-009: the technical log gets the class and the error code, never the message or the debug information.
     */
    public function test_log_context_has_no_message(): void {
        $e = new \moodle_exception('generalexceptionmessage', 'error', '', 'Raw marker', 'SELECT secret FROM {user}');

        $context = safe_error::log_context($e);

        // Moodle 4.5 aliases \moodle_exception to its namespaced class, which get_class() reports.
        $class = \core\exception\moodle_exception::class;
        $this->assertSame(['class' => $class, 'errorcode' => 'generalexceptionmessage'], $context);
        $this->assertSame($class . ' errorcode=generalexceptionmessage', safe_error::describe($e));
        $this->assertSame('RuntimeException', safe_error::describe(new \RuntimeException('Raw marker')));
    }

    /**
     * JEDOMI-SEC-009: the model gets the first line of the database error, without the query or its parameters.
     */
    public function test_model_gets_the_first_line_of_the_database_error(): void {
        $this->resetAfterTest();
        $error = "ERROR:  column \"nosuchcolumn\" does not exist\nLINE 1: SELECT nosuchcolumn FROM mdl_course";
        $dml = new \dml_read_exception($error, 'SELECT nosuchcolumn FROM mdl_course WHERE id = ?', [42]);
        $wrapped = new \moodle_exception('execute_sql_error', 'local_jedomi', '', null, $error);

        foreach ([$dml, $wrapped] as $e) {
            $message = safe_error::for_model($e);
            $this->assertStringStartsWith(get_string('execute_sql_error', 'local_jedomi'), $message, get_class($e));
            $this->assertStringContainsString('column "nosuchcolumn" does not exist', $message, get_class($e));
            $this->assertStringNotContainsString('SELECT', $message, get_class($e));
            $this->assertStringNotContainsString('42', $message, get_class($e));
        }
    }

    /**
     * JEDOMI-SEC-009: a Moodle exception gives its language string without debug information; any other error only
     * its class; a long database error is cut.
     */
    public function test_model_gets_no_debug_information_or_raw_message(): void {
        $this->resetAfterTest();
        $moodle = new \moodle_exception('sql_course_rejected', 'local_jedomi', '', null, '/var/www/secret/path.php');
        $this->assertSame(get_string('sql_course_rejected', 'local_jedomi'), safe_error::for_model($moodle));

        $raw = new \RuntimeException('Raw marker in /var/www/moodle/config.php');
        $message = safe_error::for_model($raw);
        $this->assertStringNotContainsString('Raw marker', $message);
        $this->assertStringNotContainsString('/var/www', $message);
        $this->assertStringContainsString('RuntimeException', $message);

        $long = new \moodle_exception('execute_sql_error', 'local_jedomi', '', null, str_repeat('x', 1000));
        $this->assertLessThanOrEqual(
            \core_text::strlen(get_string('execute_sql_error', 'local_jedomi')) + 1 + safe_error::MAX_DETAIL,
            \core_text::strlen(safe_error::for_model($long))
        );
    }

    /**
     * JEDOMI-SEC-009: an invalid web service parameter keeps the name of the wrong parameter for the model.
     */
    public function test_model_gets_the_invalid_parameter(): void {
        $this->resetAfterTest();
        $e = new \invalid_parameter_exception('courseid => Invalid value');

        $this->assertStringContainsString('courseid', safe_error::for_model($e));
    }

    /**
     * JEDOMI-SEC-009: the user gets the language string of a Moodle exception, and a generic message with a reference
     * for anything else; the technical log gets the reference with the class only.
     */
    public function test_user_gets_a_safe_message_with_a_reference(): void {
        global $CFG;
        $this->resetAfterTest();

        $moodle = new \moodle_exception('error_invalid_hash', 'local_jedomi', '', null, 'Raw debug marker');
        $this->assertSame(get_string('error_invalid_hash', 'local_jedomi'), safe_error::for_user($moodle));

        foreach ([new \RuntimeException('Raw marker'), new \dml_read_exception('Raw marker', 'SELECT secret FROM x')] as $e) {
            $message = safe_error::for_user($e, 'test');
            $this->assertStringNotContainsString('Raw marker', $message, get_class($e));
            $this->assertStringNotContainsString('SELECT', $message, get_class($e));
            $this->assertMatchesRegularExpression('/[0-9a-f]{8}/', $message, get_class($e));
        }
        $log = (string) @file_get_contents($CFG->dataroot . '/local/jedomi/jedomi.log');
        $this->assertStringContainsString('dml_read_exception', $log);
        $this->assertStringNotContainsString('Raw marker', $log);
    }

    /**
     * JEDOMI-SEC-009: the browser summary shows an error code, and a generic text for anything longer.
     */
    public function test_summary_shows_codes_only(): void {
        $this->assertSame('permission_denied', safe_error::summary('permission_denied'));
        $generic = get_string('summary_error_generic', 'local_jedomi');
        $this->assertSame($generic, safe_error::summary("Database error executing query. Unknown column 'x'"));
        $this->assertSame($generic, safe_error::summary(['nested' => 'error']));
    }
}
