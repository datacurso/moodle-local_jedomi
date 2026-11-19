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
 * What an exception may tell the technical log, the AI model and the user (JEDOMI-SEC-009).
 *
 * The message of an exception and its debug information can quote a query, its parameters, a file path or personal
 * data. They never reach the technical log, the browser or Telegram as they are:
 *
 * - The technical log gets the class and the error code (log_context() and describe()).
 * - The user gets the message of a Moodle exception (a language string, without its debug information) or a generic
 *   message with a reference that finds the entry of the technical log (for_user()).
 * - The AI model also gets the first line of the database's own error, without the query, so it can fix its query
 *   (for_model(), SEC-JD-038).
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class safe_error {
    /** @var int Longest database error given to the model, in characters. */
    public const MAX_DETAIL = 300;

    /** @var string[] Error codes of local_jedomi whose debug information is the database's own error. */
    private const DATABASE_CODES = ['execute_sql_error', 'query_timeout'];

    /**
     * Context of an exception for the technical log: its class and error code, never its message.
     *
     * @param \Throwable $e Exception.
     * @return array{class: string, errorcode: string}
     */
    public static function log_context(\Throwable $e): array {
        return ['class' => get_class($e), 'errorcode' => $e instanceof \moodle_exception ? (string) $e->errorcode : ''];
    }

    /**
     * One line for a text entry of the technical log: the class and, for a Moodle exception, the error code.
     *
     * @param \Throwable $e Exception.
     * @return string Such as "dml_read_exception errorcode=dmlreadexception".
     */
    public static function describe(\Throwable $e): string {
        $context = self::log_context($e);
        return $context['class'] . ($context['errorcode'] !== '' ? ' errorcode=' . $context['errorcode'] : '');
    }

    /**
     * Message of an exception that can be shown to the user.
     *
     * A Moodle exception gives its language string (with its parameters, never its debug information); a database
     * exception, or any other error, gives a generic message with a reference, and the class goes to the technical log
     * with that reference.
     *
     * @param \Throwable $e Exception.
     * @param string $where Short label of the place for the technical log (such as "ajax/agent_create").
     * @return string
     */
    public static function for_user(\Throwable $e, string $where = ''): string {
        if ($e instanceof \moodle_exception && !$e instanceof \dml_exception) {
            $message = self::moodle_message($e);
            if ($message !== null) {
                return $message;
            }
        }
        $reference = bin2hex(random_bytes(4));
        require_once(__DIR__ . '/../../lib.php');
        \local_jedomi_log('ERROR', ['where' => $where, 'reference' => $reference] + self::log_context($e));
        return get_string('error_generic_reference', 'local_jedomi', $reference);
    }

    /**
     * Error of a tool for the AI model: what it needs to correct its call, never the query, its parameters or a path.
     *
     * A read or write error of the database gives the language string and the first line of the database's own error
     * (an unknown column, a syntax error), cut to MAX_DETAIL characters; another database exception only its language
     * string; an invalid web service parameter also gives the first line of its debug information (which parameter is
     * wrong); a Moodle exception gives its language string; any other error a generic text with its class.
     *
     * @param \Throwable $e Exception.
     * @return string
     */
    public static function for_model(\Throwable $e): string {
        if (($e instanceof \dml_read_exception || $e instanceof \dml_write_exception) && trim((string) $e->error) !== '') {
            // The error of the database itself, apart from the query and the parameters its debug information repeats.
            return get_string('execute_sql_error', 'local_jedomi') . ' ' . self::first_line((string) $e->error);
        }
        if ($e instanceof \dml_exception) {
            // Other database exceptions (a missing record...) quote the query in their debug information: only their
            // language string is given.
            return self::moodle_message($e) ?? get_string('execute_sql_error', 'local_jedomi');
        }
        if ($e instanceof \moodle_exception) {
            $message = self::moodle_message($e) ?? (string) $e->errorcode;
            $database = $e->module === 'local_jedomi' && in_array($e->errorcode, self::DATABASE_CODES, true);
            // An invalid parameter of a web service says which parameter is wrong, never a query or a path.
            if (($database || $e instanceof \invalid_parameter_exception) && trim((string) $e->debuginfo) !== '') {
                $message .= ' ' . self::first_line((string) $e->debuginfo);
            }
            return $message;
        }
        return get_string('error_tool_unexpected', 'local_jedomi', get_class($e));
    }

    /**
     * Short summary of a tool error for the browser: an error code is shown, any other text is replaced by a generic
     * message, so a detail meant for the model never reaches the page.
     *
     * @param mixed $error The error field of a tool result.
     * @return string
     */
    public static function summary(mixed $error): string {
        if (is_string($error) && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $error) === 1) {
            return $error;
        }
        return get_string('summary_error_generic', 'local_jedomi');
    }

    /**
     * First line of a text, cut to MAX_DETAIL characters: PostgreSQL repeats the query from the second line on.
     *
     * @param string $text Text.
     * @return string
     */
    public static function first_line(string $text): string {
        $line = trim((string) strtok(trim($text), "\r\n"));
        return \core_text::substr($line, 0, self::MAX_DETAIL);
    }

    /**
     * Language string of a Moodle exception without its debug information, or null when the string does not exist.
     *
     * @param \moodle_exception $e Exception.
     * @return string|null
     */
    private static function moodle_message(\moodle_exception $e): ?string {
        $module = (string) $e->module !== '' ? (string) $e->module : 'error';
        if (!get_string_manager()->string_exists((string) $e->errorcode, $module)) {
            return null;
        }
        return get_string((string) $e->errorcode, $module, $e->a);
    }
}
