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

/**
 * CLI Operations Registry — index of admin actions not exposed via web services.
 *
 * Each operation has a name, description, parameter definition, and a handler
 * callback. The AI can search, describe, and execute operations through this
 * registry, following the same pattern as ws_indexer for web services.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\schema;

/**
 * Registry of admin CLI operations the AI can search, describe,
 * and execute, mirroring the ws_indexer pattern for web services.
 */
class cli_registry {
    /**
     * Return all registered CLI operations.
     *
     * Each entry: name, description, params (name → type/required/description).
     *
     * @return array
     */
    public static function get_all(): array {
        return [
            [
                'name'        => 'backup',
                'description' => 'Perform a full Moodle course backup. Creates a .mbz backup file.',
                'params'      => [
                    'courseid' => [
                        'type'        => 'int',
                        'required'    => true,
                        'description' => 'Course ID to back up.',
                    ],
                    'destination' => [
                        'type'        => 'string',
                        'required'    => false,
                        'description' => 'Optional filesystem path to store the backup file.',
                    ],
                ],
            ],
            [
                'name'        => 'scheduled_task',
                'description' => 'Run a specific Moodle scheduled task by class name.',
                'params'      => [
                    'task_class' => [
                        'type'        => 'string',
                        'required'    => true,
                        'description' => 'Full class name of the task (e.g. core\task\send_new_user_passwords).',
                    ],
                ],
            ],
            [
                'name'        => 'purge_caches',
                'description' => 'Purge all Moodle caches (theme, JS, language, filter, etc.). No parameters required.',
                'params'      => [],
            ],
            [
                'name'        => 'create_mod',
                'description' => 'Create a course module (activity/resource) inside a course'
                    . ' using natural-language-generated parameters.',
                'params'      => [
                    'courseid' => [
                        'type'        => 'int',
                        'required'    => true,
                        'description' => 'Course ID where the module will be created.',
                    ],
                    'resource_type' => [
                        'type'        => 'string',
                        'required'    => true,
                        'description' => 'Module plugin name (e.g. page, assign, quiz, forum,'
                            . ' resource, label, folder, glossary, choice).',
                    ],
                    'parameters' => [
                        'type'        => 'object',
                        'required'    => true,
                        'description' => 'JSON object of module creation parameters.'
                            . ' Required fields for every module type: name (string, the activity'
                            . ' title) and intro (HTML string, the description). Content fields'
                            . ' depend on the resource_type: for "page" use content (HTML), for'
                            . ' "assign" use activity (HTML) and duedate (timestamp), for "quiz"'
                            . ' use question and answer structures. Standard fields supported:'
                            . ' name, intro, introformat (0=MOODLE, 1=HTML, 2=PLAIN), visible'
                            . ' (0/1), timemodified, section. Module-specific fields are passed'
                            . ' through as-is.',
                    ],
                    'section' => [
                        'type'        => 'int',
                        'required'    => false,
                        'description' => 'Section number where to place the module (default: 0, the first section).',
                    ],
                    'beforemod' => [
                        'type'        => 'int',
                        'required'    => false,
                        'description' => 'Optional cm id to insert this module before.',
                    ],
                ],
            ],
        ];
    }

    /**
     * Search operations by keyword across name and description.
     *
     * @param  string $query Search keywords.
     * @param  int    $limit Max results.
     * @return array
     */
    public static function search(string $query, int $limit = 8): array {
        $all     = self::get_all();
        $results = [];
        $query   = strtolower(trim($query));

        if (empty($query)) {
            return array_slice($all, 0, $limit);
        }

        foreach ($all as $op) {
            $name        = strtolower($op['name']);
            $description = strtolower($op['description']);

            $score = 0;
            if (strpos($name, $query) !== false) {
                $score += 10;
            }
            if (strpos($description, $query) !== false) {
                $score += 5;
            }
            // Check individual words.
            foreach (explode(' ', $query) as $word) {
                if (strlen($word) < 2) {
                    continue;
                }
                if (strpos($name, $word) !== false) {
                    $score += 3;
                }
                if (strpos($description, $word) !== false) {
                    $score += 1;
                }
            }

            if ($score > 0) {
                $results[] = [
                    'name'        => $op['name'],
                    'description' => $op['description'],
                    'score'       => $score,
                ];
            }
        }

        usort($results, static fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($results, 0, $limit);
    }

    /**
     * Get full documentation for a specific operation by name.
     *
     * @param  string $name Operation name.
     * @return array
     */
    public static function describe(string $name): array {
        foreach (self::get_all() as $op) {
            if ($op['name'] === $name) {
                return $op;
            }
        }
        return ['error' => 'operation not found: ' . $name];
    }

    /**
     * Whether a user may run the administration operations: a site administrator or a user with moodle/site:config.
     *
     * @param int $userid Moodle user id.
     * @return bool
     */
    public static function can_execute(int $userid): bool {
        if ($userid <= 0) {
            return false;
        }
        return is_siteadmin($userid) || has_capability('moodle/site:config', \context_system::instance(), $userid);
    }

    /**
     * Execute a CLI operation.
     *
     * Defense in depth: every operation here (purge_caches, scheduled tasks,
     * backups, create_mod) is a privileged admin action. Callers are expected
     * to have already role-gated the call, but this check is enforced again
     * here — independently of any caller-supplied role — so a bug or bypass
     * upstream cannot reach these operations without real Moodle capability.
     *
     * @param  string $name   Operation name.
     * @param  array  $params Operation parameters.
     * @param  int    $userid Moodle user id performing the operation.
     * @return array           Result with success/error + data.
     */
    public static function execute(string $name, array $params, int $userid): array {
        if (!self::can_execute($userid)) {
            return ['error' => 'permission_denied'];
        }

        return match ($name) {
            'backup'         => self::execute_backup($params),
            'scheduled_task' => self::execute_scheduled_task($params),
            'purge_caches'   => self::execute_purge_caches($params),
            'create_mod'     => self::execute_create_mod($params),
            default          => ['error' => 'unknown operation: ' . $name],
        };
    }

    /**
     * Execute a course backup.
     *
     * @param  array $params Operation parameters (courseid, destination).
     * @return array         Result with success/error + data.
     */
    private static function execute_backup(array $params): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

        $courseid    = (int) ($params['courseid'] ?? 0);
        $destination = trim($params['destination'] ?? '');

        if ($courseid <= 1) {
            return ['error' => 'invalid course id'];
        }

        try {
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        } catch (\Exception $e) {
            return ['error' => 'course not found: ' . $e->getMessage()];
        }

        $admin = get_admin();
        if (!$admin) {
            return ['error' => 'no admin account found'];
        }

        try {
            $bc = new \backup_controller(
                \backup::TYPE_1COURSE,
                $course->id,
                \backup::FORMAT_MOODLE,
                \backup::INTERACTIVE_YES,
                \backup::MODE_GENERAL,
                $admin->id
            );

            $format  = $bc->get_format();
            $type    = $bc->get_type();
            $id      = $bc->get_id();
            $users   = $bc->get_plan()->get_setting('users')->get_value();
            $anon    = $bc->get_plan()->get_setting('anonymize')->get_value();
            $filename = \backup_plan_dbops::get_default_backup_filename($format, $type, $id, $users, $anon);
            $bc->get_plan()->get_setting('filename')->set_value($filename);

            $bc->finish_ui();
            $bc->execute_plan();
            $results = $bc->get_results();
            $file    = $results['backup_destination'] ?? null;

            $filedetails = $file ? [
                'filename' => $filename,
                'size'     => $file->get_filesize(),
                'mimetype' => $file->get_mimetype(),
            ] : [];

            if (!empty($destination) && $file) {
                $dir = rtrim($destination, '/');
                if (!is_dir($dir) || !is_writable($dir)) {
                    $bc->destroy();
                    return ['error' => 'destination not writable', 'backup' => $filedetails];
                }
                $file->copy_content_to($dir . '/' . $filename);
                $file->delete();
                $filedetails['stored_at'] = $dir . '/' . $filename;
            }

            $bc->destroy();

            return [
                'success'   => true,
                'courseid'  => $course->id,
                'coursename' => $course->fullname,
                'backup'    => $filedetails,
            ];
        } catch (\Throwable $e) {
            if (isset($bc)) {
                $bc->destroy();
            }
            return ['error' => 'backup failed: ' . $e->getMessage()];
        }
    }

    /**
     * Execute a scheduled task.
     *
     * @param  array $params Operation parameters (task_class).
     * @return array         Result with success/error + data.
     */
    private static function execute_scheduled_task(array $params): array {
        global $CFG;
        require_once($CFG->libdir . '/cronlib.php');

        $taskclass = $params['task_class'] ?? '';

        if (empty($taskclass)) {
            return ['error' => 'task_class is required'];
        }

        if (!class_exists($taskclass)) {
            return ['error' => 'task class not found: ' . $taskclass];
        }

        try {
            $task = \core\task\manager::get_scheduled_task($taskclass);
            if (!$task) {
                return ['error' => 'scheduled task not found: ' . $taskclass];
            }

            if (!$task->is_component_enabled()) {
                return ['error' => 'task component is disabled: ' . $taskclass];
            }

            \core\task\manager::run_from_cli($task);
            return ['success' => true, 'task' => $taskclass];
        } catch (\Throwable $e) {
            return ['error' => 'task execution failed: ' . $e->getMessage()];
        }
    }

    /**
     * Purge all Moodle caches.
     *
     * @param  array $params Operation parameters (unused).
     * @return array         Result with success/error + data.
     */
    private static function execute_purge_caches(array $params): array {
        try {
            purge_all_caches();
            return ['success' => true, 'message' => 'All Moodle caches purged successfully.'];
        } catch (\Throwable $e) {
            return ['error' => 'cache purge failed: ' . $e->getMessage()];
        }
    }

    /**
     * Create a course module using local_coursegen's mod_manager.
     *
     * @param  array $params Operation parameters (courseid, resource_type, ...).
     * @return array         Result with success/error + data.
     */
    private static function execute_create_mod(array $params): array {
        global $CFG, $DB;

        $courseid     = (int) ($params['courseid'] ?? 0);
        $resourcetype = trim($params['resource_type'] ?? '');
        $rawparams    = $params['parameters'] ?? [];
        $sectionnum   = (int) ($params['section'] ?? 0);
        $beforemod    = isset($params['beforemod']) ? (int) $params['beforemod'] : null;

        if ($courseid <= 1) {
            return ['error' => 'invalid course id'];
        }
        if (empty($resourcetype)) {
            return ['error' => 'resource_type is required'];
        }

        $coursegenpath = $CFG->dirroot . '/local/coursegen/classes/mod_manager.php';
        if (!file_exists($coursegenpath)) {
            return ['error' => 'local_coursegen plugin is not installed'];
        }
        require_once($coursegenpath);

        try {
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        } catch (\Exception $e) {
            return ['error' => 'course not found: ' . $e->getMessage()];
        }

        try {
            $resultinfo = [
                'result' => [
                    'resource_type' => $resourcetype,
                    'parameters'    => (array) $rawparams,
                ],
            ];

            $newcm = \local_coursegen\mod_manager::create_from_ai_result(
                $resultinfo,
                $course,
                $sectionnum,
                $beforemod
            );

            return [
                'success'      => true,
                'courseid'     => (int) $course->id,
                'coursename'   => $course->fullname,
                'cmid'         => (int) $newcm->id,
                'modname'      => $newcm->modname ?? $resourcetype,
                'instance'     => (int) ($newcm->instance ?? 0),
                'sectionnum'   => $sectionnum,
                'resource_type' => $resourcetype,
            ];
        } catch (\Throwable $e) {
            return ['error' => 'create_mod failed: ' . $e->getMessage()];
        }
    }
}
