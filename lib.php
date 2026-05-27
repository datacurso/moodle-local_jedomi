<?php
/**
 * local_jedomi — AI Sidebar for Moodle
 * Theme-proof injection strategy:
 *
 *  1. before_footer() → intenta inyectar via hook estándar
 *  2. El AMD module sidebar.js verifica si el HTML existe y si no lo inyecta él mismo
 *     directo en document.body (bypasea cualquier problema de tema)
 *  3. CSS usa position:fixed !important y z-index:99999 para sobrevivir cualquier tema
 *  4. Arregla body overflow:hidden que rompe fixed positioning
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Hook principal — antes del </body>.
 * Pasa la config al AMD module. El HTML lo inyecta el JS para mayor robustez.
 */
/**
 * Legacy before_footer callback — now handled by the hook system.
 *
 * Kept as an empty function so Moodle's get_plugins_with_function('before_footer')
 * finds it and does NOT log a missing-migration warning.
 *
 * All sidebar injection logic moved to:
 *   \local_jedomi\hook_callbacks::before_footer()
 * registered via db/hooks.php for the core\hook\output\before_footer_html_generation hook.
 *
 * @return string Always empty — sidebar is injected via AMD module.
 */
function local_jedomi_before_footer(): string {
    // Logic moved to \local_jedomi\hook_callbacks::before_footer
    return '';
}

/**
 * Build the full sidebar config array (strings, connection info, SPT, etc.).
 *
 * @return array
 */
function local_jedomi_get_sidebar_config(): array {
    global $USER, $CFG;

    $context = local_jedomi_get_page_context();

    return [
        'gateway_url'   => get_config('local_jedomi', 'gateway_url') ?: 'http://localhost:8765',
        'api_key'       => get_config('local_jedomi', 'api_key')     ?: 'jedomi-local-key',
        'model'         => get_config('local_jedomi', 'model')        ?: 'jedomi-e4b',
        'chatproxy_url' => $CFG->wwwroot . '/local/jedomi/chatproxy.php',
        'user_name'     => fullname($USER),
        'user_role'     => local_jedomi_get_user_role(),
        'wwwroot'       => $CFG->wwwroot,
        'context'       => $context,
        'sesskey'       => sesskey(),
        'strings'       => [
            // UI chrome
            'title'             => get_string('sidebar_title',        'local_jedomi'),
            'open_sidebar'      => get_string('open_sidebar',         'local_jedomi'),
            'close'             => get_string('close',                 'local_jedomi'),
            'new_conversation'  => get_string('new_conversation',      'local_jedomi'),
            'export_conversation'=> get_string('export_conversation',  'local_jedomi'),
            'toggle_thinking'   => get_string('toggle_thinking',       'local_jedomi'),
            'half_width'        => get_string('half_width',            'local_jedomi'),
            'full_width'        => get_string('full_width',            'local_jedomi'),
            // Input
            'attach'            => get_string('attach',               'local_jedomi'),
            'attach_file'       => get_string('attach_file',          'local_jedomi'),
            'attach_image'      => get_string('attach_image',         'local_jedomi'),
            'file'              => get_string('file',                 'local_jedomi'),
            'image'             => get_string('image',                'local_jedomi'),
            'message_input'     => get_string('message_input',        'local_jedomi'),
            'placeholder'       => get_string('chat_placeholder',     'local_jedomi'),
            'send'              => get_string('send',                  'local_jedomi'),
            'voice_input'       => get_string('voice_input',          'local_jedomi'),
            // Greeting
            'greeting'          => get_string('greeting',             'local_jedomi'),
            'hello'             => get_string('hello',                'local_jedomi'),
            'thinking'          => get_string('thinking',             'local_jedomi'),
            'new_conversation_msg' => get_string('new_conversation_msg', 'local_jedomi'),
            // Files
            'analyze_image'        => get_string('analyze_image',         'local_jedomi'),
            'attached_file_info'   => get_string('attached_file_info',    'local_jedomi'),
            'attached_image'       => get_string('attached_image',        'local_jedomi'),
            'csv_download'         => get_string('csv_download',          'local_jedomi'),
            'file_csv'             => get_string('file_csv',              'local_jedomi'),
            'file_loaded'          => get_string('file_loaded',           'local_jedomi'),
            'file_loaded_hint'     => get_string('file_loaded_hint',      'local_jedomi'),
            'file_pdf'             => get_string('file_pdf',              'local_jedomi'),
            'file_process_error'   => get_string('file_process_error',    'local_jedomi'),
            'file_text'            => get_string('file_text',             'local_jedomi'),
            'processing_file'      => get_string('processing_file',       'local_jedomi'),
            // Footer / disclaimer
            'ai_disclaimer'     => get_string('ai_disclaimer',        'local_jedomi'),
            'drop_files_here'   => get_string('drop_files_here',      'local_jedomi'),
            // Tools
            'tool_sql_read'          => get_string('tool_sql_read',          'local_jedomi'),
            'tool_api_call'          => get_string('tool_api_call',          'local_jedomi'),
            'tool_schema_lookup'     => get_string('tool_schema_lookup',     'local_jedomi'),
            'tool_webservice_lookup' => get_string('tool_webservice_lookup', 'local_jedomi'),
            // Message actions
            'action_copy'          => get_string('action_copy',          'local_jedomi'),
            'action_helpful'       => get_string('action_helpful',       'local_jedomi'),
            'action_improve'       => get_string('action_improve',       'local_jedomi'),
            'action_improve_title' => get_string('action_improve_title', 'local_jedomi'),
            'action_edit'          => get_string('action_edit',          'local_jedomi'),
            'action_edit_title'    => get_string('action_edit_title',    'local_jedomi'),
            // Bulk message
            'confirm_send_bulk'        => get_string('confirm_send_bulk',        'local_jedomi'),
            'yes_send'                 => get_string('yes_send',                 'local_jedomi'),
            'cancel'                   => get_string('cancel',                   'local_jedomi'),
            'sending'                  => get_string('sending',                  'local_jedomi'),
            'cancelled'                => get_string('cancelled',                'local_jedomi'),
            'messages_sent'            => get_string('messages_sent',            'local_jedomi'),
            'messages_sent_fail'       => get_string('messages_sent_fail',       'local_jedomi'),
            'messages_recipients_note' => get_string('messages_recipients_note', 'local_jedomi'),
            'no_messages_sent'         => get_string('no_messages_sent',         'local_jedomi'),
            'unknown_error'            => get_string('unknown_error',            'local_jedomi'),
            'connection_error'         => get_string('connection_error',         'local_jedomi'),
            // Context labels
            'ctx_activity'  => get_string('ctx_activity',  'local_jedomi'),
            'ctx_admin'     => get_string('ctx_admin',     'local_jedomi'),
            'ctx_calendar'  => get_string('ctx_calendar',  'local_jedomi'),
            'ctx_course'    => get_string('ctx_course',    'local_jedomi'),
            'ctx_dashboard' => get_string('ctx_dashboard', 'local_jedomi'),
            'ctx_files'     => get_string('ctx_files',     'local_jedomi'),
            'ctx_grades'    => get_string('ctx_grades',    'local_jedomi'),
            'ctx_messages'  => get_string('ctx_messages',  'local_jedomi'),
            'ctx_profile'   => get_string('ctx_profile',   'local_jedomi'),
            // Errors
            'gateway_error_title'   => get_string('gateway_error_title', 'local_jedomi'),
            'gateway_error_hint'    => get_string('gateway_error_hint',  'local_jedomi'),
            'listening'             => get_string('listening',           'local_jedomi'),
            'mic_error'             => get_string('mic_error',           'local_jedomi'),
            'silence_detected'      => get_string('silence_detected',    'local_jedomi'),
            'transcribing'          => get_string('transcribing',        'local_jedomi'),
            'waiting_voice'         => get_string('waiting_voice',       'local_jedomi'),
            // Confirmation system
            'confirm_type_to_proceed' => get_string('confirm_type_to_proceed', 'local_jedomi'),
            'confirm_btn_yes'         => get_string('confirm_btn_yes',         'local_jedomi'),
            'confirm_btn_no'          => get_string('confirm_btn_no',          'local_jedomi'),
            'confirm_executing'       => get_string('confirm_executing',       'local_jedomi'),
            'confirm_done'            => get_string('confirm_done',            'local_jedomi'),
            'confirm_cancelled'       => get_string('confirm_cancelled',       'local_jedomi'),
            // Quick actions: Admin
            'qa_admin_active_users_label'    => get_string('qa_admin_active_users_label',    'local_jedomi'),
            'qa_admin_active_users_msg'      => get_string('qa_admin_active_users_msg',      'local_jedomi'),
            'qa_admin_alerts_label'          => get_string('qa_admin_alerts_label',          'local_jedomi'),
            'qa_admin_alerts_msg'            => get_string('qa_admin_alerts_msg',            'local_jedomi'),
            'qa_admin_at_risk_label'         => get_string('qa_admin_at_risk_label',         'local_jedomi'),
            'qa_admin_at_risk_msg'           => get_string('qa_admin_at_risk_msg',           'local_jedomi'),
            'qa_admin_empty_cohorts_label'   => get_string('qa_admin_empty_cohorts_label',   'local_jedomi'),
            'qa_admin_empty_cohorts_msg'     => get_string('qa_admin_empty_cohorts_msg',     'local_jedomi'),
            'qa_admin_external_emails_label' => get_string('qa_admin_external_emails_label', 'local_jedomi'),
            'qa_admin_external_emails_msg'   => get_string('qa_admin_external_emails_msg',   'local_jedomi'),
            'qa_admin_gdpr_pending_label'    => get_string('qa_admin_gdpr_pending_label',    'local_jedomi'),
            'qa_admin_gdpr_pending_msg'      => get_string('qa_admin_gdpr_pending_msg',      'local_jedomi'),
            'qa_admin_inactive_label'        => get_string('qa_admin_inactive_label',        'local_jedomi'),
            'qa_admin_inactive_msg'          => get_string('qa_admin_inactive_msg',          'local_jedomi'),
            'qa_admin_missing_work_label'    => get_string('qa_admin_missing_work_label',    'local_jedomi'),
            'qa_admin_missing_work_msg'      => get_string('qa_admin_missing_work_msg',      'local_jedomi'),
            'qa_admin_overview_label'        => get_string('qa_admin_overview_label',        'local_jedomi'),
            'qa_admin_overview_msg'          => get_string('qa_admin_overview_msg',          'local_jedomi'),
            'qa_admin_performance_label'     => get_string('qa_admin_performance_label',     'local_jedomi'),
            'qa_admin_performance_msg'       => get_string('qa_admin_performance_msg',       'local_jedomi'),
            'qa_admin_security_label'        => get_string('qa_admin_security_label',        'local_jedomi'),
            'qa_admin_security_msg'          => get_string('qa_admin_security_msg',          'local_jedomi'),
            'qa_admin_storage_label'         => get_string('qa_admin_storage_label',         'local_jedomi'),
            'qa_admin_storage_msg'           => get_string('qa_admin_storage_msg',           'local_jedomi'),
            'qa_admin_top_courses_label'     => get_string('qa_admin_top_courses_label',     'local_jedomi'),
            'qa_admin_top_courses_msg'       => get_string('qa_admin_top_courses_msg',       'local_jedomi'),
            'qa_admin_top_student_label'     => get_string('qa_admin_top_student_label',     'local_jedomi'),
            'qa_admin_top_student_msg'       => get_string('qa_admin_top_student_msg',       'local_jedomi'),
            // Quick actions: Manager
            'qa_manager_at_risk_label'       => get_string('qa_manager_at_risk_label',       'local_jedomi'),
            'qa_manager_at_risk_msg'         => get_string('qa_manager_at_risk_msg',         'local_jedomi'),
            'qa_manager_enrolled_label'      => get_string('qa_manager_enrolled_label',      'local_jedomi'),
            'qa_manager_enrolled_msg'        => get_string('qa_manager_enrolled_msg',        'local_jedomi'),
            'qa_manager_global_risk_label'   => get_string('qa_manager_global_risk_label',   'local_jedomi'),
            'qa_manager_global_risk_msg'     => get_string('qa_manager_global_risk_msg',     'local_jedomi'),
            'qa_manager_inactive_label'      => get_string('qa_manager_inactive_label',      'local_jedomi'),
            'qa_manager_inactive_msg'        => get_string('qa_manager_inactive_msg',        'local_jedomi'),
            'qa_manager_inactive_users_label' => get_string('qa_manager_inactive_users_label', 'local_jedomi'),
            'qa_manager_inactive_users_msg'  => get_string('qa_manager_inactive_users_msg',  'local_jedomi'),
            'qa_manager_no_teacher_label'    => get_string('qa_manager_no_teacher_label',    'local_jedomi'),
            'qa_manager_no_teacher_msg'      => get_string('qa_manager_no_teacher_msg',      'local_jedomi'),
            'qa_manager_overview_label'      => get_string('qa_manager_overview_label',      'local_jedomi'),
            'qa_manager_overview_msg'        => get_string('qa_manager_overview_msg',        'local_jedomi'),
            'qa_manager_top_courses_label'   => get_string('qa_manager_top_courses_label',   'local_jedomi'),
            'qa_manager_top_courses_msg'     => get_string('qa_manager_top_courses_msg',     'local_jedomi'),
            // Quick actions: Teacher
            'qa_teacher_at_risk_label'       => get_string('qa_teacher_at_risk_label',       'local_jedomi'),
            'qa_teacher_at_risk_msg'         => get_string('qa_teacher_at_risk_msg',         'local_jedomi'),
            'qa_teacher_my_alerts_label'     => get_string('qa_teacher_my_alerts_label',     'local_jedomi'),
            'qa_teacher_my_alerts_msg'       => get_string('qa_teacher_my_alerts_msg',       'local_jedomi'),
            'qa_teacher_my_courses_label'    => get_string('qa_teacher_my_courses_label',    'local_jedomi'),
            'qa_teacher_my_courses_msg'      => get_string('qa_teacher_my_courses_msg',      'local_jedomi'),
            'qa_teacher_missing_work_label'  => get_string('qa_teacher_missing_work_label',  'local_jedomi'),
            'qa_teacher_missing_work_msg'    => get_string('qa_teacher_missing_work_msg',    'local_jedomi'),
            'qa_teacher_never_logged_label'  => get_string('qa_teacher_never_logged_label',  'local_jedomi'),
            'qa_teacher_never_logged_msg'    => get_string('qa_teacher_never_logged_msg',    'local_jedomi'),
            'qa_teacher_notify_group_label'  => get_string('qa_teacher_notify_group_label',  'local_jedomi'),
            'qa_teacher_notify_group_msg'    => get_string('qa_teacher_notify_group_msg',    'local_jedomi'),
            'qa_teacher_performance_label'   => get_string('qa_teacher_performance_label',   'local_jedomi'),
            'qa_teacher_performance_msg'     => get_string('qa_teacher_performance_msg',     'local_jedomi'),
            'qa_teacher_top_students_label'  => get_string('qa_teacher_top_students_label',  'local_jedomi'),
            'qa_teacher_top_students_msg'    => get_string('qa_teacher_top_students_msg',    'local_jedomi'),
            // Quick actions: Student
            'qa_student_help_label'          => get_string('qa_student_help_label',          'local_jedomi'),
            'qa_student_help_msg'            => get_string('qa_student_help_msg',            'local_jedomi'),
            'qa_student_my_courses_label'    => get_string('qa_student_my_courses_label',    'local_jedomi'),
            'qa_student_my_courses_msg'      => get_string('qa_student_my_courses_msg',      'local_jedomi'),
            'qa_student_my_grade_label'      => get_string('qa_student_my_grade_label',      'local_jedomi'),
            'qa_student_my_grade_msg'        => get_string('qa_student_my_grade_msg',        'local_jedomi'),
            'qa_student_my_grades_label'     => get_string('qa_student_my_grades_label',     'local_jedomi'),
            'qa_student_my_grades_msg'       => get_string('qa_student_my_grades_msg',       'local_jedomi'),
            'qa_student_my_progress_label'   => get_string('qa_student_my_progress_label',   'local_jedomi'),
            'qa_student_my_progress_msg'     => get_string('qa_student_my_progress_msg',     'local_jedomi'),
            'qa_student_my_tasks_label'      => get_string('qa_student_my_tasks_label',      'local_jedomi'),
            'qa_student_my_tasks_msg'        => get_string('qa_student_my_tasks_msg',        'local_jedomi'),
            'qa_student_this_week_label'     => get_string('qa_student_this_week_label',     'local_jedomi'),
            'qa_student_this_week_msg'       => get_string('qa_student_this_week_msg',       'local_jedomi'),
        ],
    ];
}

/**
 * Hook adicional: se ejecuta en page_init para cargar CSS lo antes posible.
 * Previene flash de contenido sin estilos en temas lentos.
 */
function local_jedomi_extend_navigation(\global_navigation $nav): void {
    // Hook vacío requerido por algunos themes.
}

/**
 * Adds a "Jedomi Telegram" link to the user profile navigation.
 * Only shown when Telegram is enabled and the user's role is allowed.
 *
 * @param \core_user\output\myprofile\tree $tree
 * @param stdClass $user
 * @param bool $iscurrentuser
 * @param stdClass $course
 */
function local_jedomi_myprofile_navigation(\core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course): void {
    global $USER;

    if (!$iscurrentuser) {
        return;
    }

    // Require Telegram enabled AND bot token verified by getMe().
    // If the admin hasn't pasted a valid token yet, don't expose the link.
    if (!\local_jedomi\telegram\manager::is_enabled()) {
        return;
    }

    if (!get_config('local_jedomi', 'telegram_bot_verified')) {
        return;
    }

    if (!local_jedomi_is_telegram_role_allowed()) {
        return;
    }

    $url = new moodle_url('/local/jedomi/telegram_profile.php', ['userid' => $user->id]);
    $node = new core_user\output\myprofile\node(
        'jedomi',
        'telegram',
        get_string('telegram_link_title', 'local_jedomi'),
        null,
        $url
    );

    $category = new core_user\output\myprofile\category(
        'jedomi',
        get_string('pluginname', 'local_jedomi'),
        null
    );

    $tree->add_category($category);
    $tree->add_node($node);
}

/**
 * Detecta el contexto de la página actual.
 */
function local_jedomi_get_page_context(): array {
    global $PAGE, $COURSE, $USER;

    $url = '';
    try {
        $url = $PAGE->url->out(false);
    } catch (Exception $e) {
        $url = $_SERVER['REQUEST_URI'] ?? '';
    }

    $pagetype = $PAGE->pagetype ?? '';
    $context  = [
        'page_url'      => $url,
        'page_type'     => $pagetype,
        'page_title'    => $PAGE->title ?? '',
        'user_id'       => $USER->id ?? 0,
        'user_fullname' => fullname($USER),
        'user_role'     => local_jedomi_get_user_role(),
        'course_id'     => 0,
        'course_name'   => '',
        'activity_id'   => 0,
        'activity_type' => '',
        'location'      => 'unknown',
    ];

    if (!empty($COURSE->id) && $COURSE->id > 1) {
        $context['course_id']   = $COURSE->id;
        $context['course_name'] = $COURSE->fullname ?? '';
        $context['location']    = 'course';
    }

    if (preg_match('/[?&]id=(\d+)/', $url, $m) && strpos($url, '/mod/') !== false) {
        $context['activity_id'] = (int)$m[1];
        $context['location']    = 'activity';
        if (preg_match('/^mod-(\w+)-/', $pagetype, $mt)) {
            $context['activity_type'] = $mt[1];
        }
    }

    $locationMap = [
        '/admin/'   => 'admin',
        '/my/'      => 'dashboard',
        '/grade/'   => 'grades',
        '/message/' => 'messages',
        '/user/'    => 'profile',
    ];
    foreach ($locationMap as $path => $loc) {
        if (strpos($url, $path) !== false) {
            $context['location'] = $loc;
            break;
        }
    }
    if ($pagetype === 'my-index') {
        $context['location'] = 'dashboard';
    }

    return $context;
}

/**
 * Genera un Signed Permission Token (SPT) usando has_capability() nativo de Moodle.
 * El Gateway lo valida criptográficamente — sin llamadas de red. Latencia: 0ms.
 * TTL: 5 minutos (300 segundos).
 */
/**
 * Verifica si Jedomi está disponible en el curso actual según la configuración.
 * Soporta modos: all (todos), whitelist (solo listados), blacklist (excepto listados).
 */
function local_jedomi_is_available_in_course(): bool {
    global $COURSE;
    return \local_jedomi\course_access::is_enabled((int)($COURSE->id ?? 0));
}

/**
 * Returns true when the sidebar should be shown to the current user
 * based on admin-configured per-role visibility.
 *
 * Site admins are always allowed.
 * A user is blocked only if ALL of their active roles are in the hidden list.
 */
function local_jedomi_is_role_allowed(): bool {
    global $USER, $PAGE;

    // Site admins always see the sidebar.
    if (is_siteadmin()) {
        return true;
    }

    $hidden = get_config('local_jedomi', 'hidden_roles') ?: '';
    if (empty($hidden)) {
        return true;
    }

    $hidden_ids = array_filter(array_map('intval', explode(',', $hidden)));
    if (empty($hidden_ids)) {
        return true;
    }

    // Collect the user's roles in the current page context + system context.
    $contexts_to_check = [context_system::instance()];
    if (!empty($PAGE->context)) {
        $contexts_to_check[] = $PAGE->context;
    }

    $user_role_ids = [];
    foreach ($contexts_to_check as $ctx) {
        $roles = get_user_roles($ctx, $USER->id, false);
        foreach ($roles as $r) {
            $user_role_ids[] = (int) $r->roleid;
        }
    }
    $user_role_ids = array_unique($user_role_ids);

    if (empty($user_role_ids)) {
        return true; // No roles assigned — show sidebar.
    }

    // If EVERY role the user has is in the hidden list → block.
    // If AT LEAST ONE role is not hidden → allow.
    foreach ($user_role_ids as $rid) {
        if (!in_array($rid, $hidden_ids, true)) {
            return true; // Has at least one non-hidden role → allowed.
        }
    }

    return false; // All roles are hidden.
}

/**
 * Get tool definitions for a given role in OpenAI function-calling format.
 * These are embedded in the SPT so the gateway can configure the model.
 *
 * @param  string $role User role: admin, manager, teacher, student.
 * @return array        Array of OpenAI-style tool definitions.
 */
function local_jedomi_get_tool_definitions(string $role): array {
    $tools = [];

    // describe_table — todos los roles.
    $tools[] = [
        'type' => 'function',
        'function' => [
            'name'        => 'describe_table',
            'description' => get_string('tool_describe_table_desc', 'local_jedomi'),
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'tablename' => [
                        'type'        => 'string',
                        'description' => get_string('tool_describe_table_tablename', 'local_jedomi'),
                    ],
                ],
                'required' => ['tablename'],
            ],
        ],
    ];

    // search_tables — todos los roles.
    $tools[] = [
        'type' => 'function',
        'function' => [
            'name'        => 'search_tables',
            'description' => get_string('tool_search_tables_desc', 'local_jedomi'),
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => get_string('tool_search_tables_query', 'local_jedomi'),
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => get_string('tool_search_tables_limit', 'local_jedomi'),
                    ],
                ],
                'required' => ['query'],
            ],
        ],
    ];

    // execute_sql — admin, manager, teacher.
    if (in_array($role, ['admin', 'manager', 'teacher'], true)) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name'        => 'execute_sql',
                'description' => get_string('tool_execute_sql_desc', 'local_jedomi'),
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'sql' => [
                            'type'        => 'string',
                            'description' => get_string('tool_execute_sql_sql', 'local_jedomi'),
                        ],
                        'params' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' => get_string('tool_execute_sql_params', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['sql'],
                ],
            ],
        ];
    }

    // get_site_config — admin only.
    if ($role === 'admin') {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name'        => 'get_site_config',
                'description' => get_string('tool_get_site_config_desc', 'local_jedomi'),
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'keys' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' => get_string('tool_get_site_config_keys', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['keys'],
                ],
            ],
        ];
    }

    // ws_search — todos los roles. Semantic keyword search returning full function docs.
    $tools[] = [
        'type' => 'function',
        'function' => [
            'name'        => 'ws_search',
            'description' => get_string('tool_ws_search_desc', 'local_jedomi'),
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => get_string('tool_ws_search_query', 'local_jedomi'),
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => get_string('tool_ws_search_limit', 'local_jedomi'),
                    ],
                ],
                'required' => ['query'],
            ],
        ],
    ];

    // ws_describe — todos los roles. Full docs for a single known WS function.
    $tools[] = [
        'type' => 'function',
        'function' => [
            'name'        => 'ws_describe',
            'description' => get_string('tool_ws_describe_desc', 'local_jedomi'),
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'wsname' => [
                        'type'        => 'string',
                        'description' => get_string('tool_ws_describe_wsname', 'local_jedomi'),
                    ],
                ],
                'required' => ['wsname'],
            ],
        ],
    ];

    // ws_execute — admin, manager, teacher.
    if (in_array($role, ['admin', 'manager', 'teacher'], true)) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name'        => 'ws_execute',
                'description' => get_string('tool_ws_execute_desc', 'local_jedomi'),
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'wsname' => [
                            'type'        => 'string',
                            'description' => get_string('tool_ws_execute_wsname', 'local_jedomi'),
                        ],
                        'params' => [
                            'type'        => 'string',
                            'description' => get_string('tool_ws_execute_params', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['wsname', 'params'],
                ],
            ],
        ];

    }

    // Agent tools — create, list, and delete scheduled AI agents.
    if (in_array($role, ['admin', 'manager'], true)) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name'        => 'create_agent',
                'description' => get_string('tool_create_agent_desc', 'local_jedomi'),
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'prompt' => [
                            'type'        => 'string',
                            'description' => get_string('tool_create_agent_prompt', 'local_jedomi'),
                        ],
                        'schedule_cron' => [
                            'type'        => 'string',
                            'description' => get_string('tool_create_agent_schedule', 'local_jedomi'),
                        ],
                        'context_type' => [
                            'type'        => 'string',
                            'enum'        => ['site', 'course', 'category', 'user'],
                            'description' => get_string('tool_create_agent_context_type', 'local_jedomi'),
                        ],
                        'context_id' => [
                            'type'        => 'integer',
                            'description' => get_string('tool_create_agent_context_id', 'local_jedomi'),
                        ],
                        'enabled' => [
                            'type'        => 'boolean',
                            'description' => get_string('tool_create_agent_enabled', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['prompt', 'schedule_cron'],
                ],
            ],
        ];

        $tools[] = [
            'type' => 'function',
            'function' => [
                'name'        => 'list_agents',
                'description' => get_string('tool_list_agents_desc', 'local_jedomi'),
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'status' => [
                            'type'        => 'string',
                            'enum'        => ['enabled', 'disabled'],
                            'description' => get_string('tool_list_agents_status', 'local_jedomi'),
                        ],
                    ],
                ],
            ],
        ];

        $tools[] = [
            'type' => 'function',
            'function' => [
                'name'        => 'delete_agent',
                'description' => get_string('tool_delete_agent_desc', 'local_jedomi'),
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'id' => [
                            'type'        => 'integer',
                            'description' => get_string('tool_delete_agent_id', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['id'],
                ],
            ],
        ];
    }

    // Admin CLI tools — search, describe, execute admin operations not exposed via WS.
    if (in_array($role, ['admin', 'manager'], true)) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name'        => 'admin_search',
                'description' => get_string('tool_admin_search_desc', 'local_jedomi'),
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => [
                            'type'        => 'string',
                            'description' => get_string('tool_admin_search_query', 'local_jedomi'),
                        ],
                        'limit' => [
                            'type'        => 'integer',
                            'description' => get_string('tool_admin_search_limit', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name'        => 'admin_describe',
                'description' => get_string('tool_admin_describe_desc', 'local_jedomi'),
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'name' => [
                            'type'        => 'string',
                            'description' => get_string('tool_admin_describe_name', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['name'],
                ],
            ],
        ];
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name'        => 'admin_execute',
                'description' => get_string('tool_admin_execute_desc', 'local_jedomi'),
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'name' => [
                            'type'        => 'string',
                            'description' => get_string('tool_admin_execute_name', 'local_jedomi'),
                        ],
                        'params' => [
                            'type'        => 'object',
                            'description' => get_string('tool_admin_execute_params', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['name'],
                ],
            ],
        ];
    }

    // Document reader — available for teacher / editingteacher and above.
    if (in_array($role, ['teacher', 'editingteacher', 'manager', 'admin'], true)) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name'        => 'document_read',
                'description' => get_string('tool_document_read_desc', 'local_jedomi'),
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'cmid' => [
                            'type'        => 'integer',
                            'description' => get_string('tool_document_read_cmid', 'local_jedomi'),
                        ],
                        'dpi' => [
                            'type'        => 'integer',
                            'description' => get_string('tool_document_read_dpi', 'local_jedomi'),
                        ],
                        'fmt' => [
                            'type'        => 'string',
                            'enum'        => ['JPEG', 'PNG'],
                            'description' => get_string('tool_document_read_fmt', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['cmid'],
                ],
            ],
        ];
    }

    return $tools;
}

function local_jedomi_generate_spt(string $role): array {
    global $USER, $COURSE, $CFG, $DB;

    $sysctx    = context_system::instance();
    $coursectx = (!empty($COURSE->id) && $COURSE->id > 1)
                    ? context_course::instance($COURSE->id)
                    : $sysctx;

    // Capabilities reales de Moodle — heredadas del sistema de roles
    $capabilities = [
        'can_view_all_grades'    => has_capability('moodle/grade:viewall',       $coursectx),
        'can_manage_users'       => has_capability('moodle/user:update',         $sysctx),
        'can_send_messages'      => has_capability('moodle/site:sendmessage',    $sysctx),
        'can_manage_courses'     => has_capability('moodle/course:update',       $coursectx),
        'can_view_reports'       => has_capability('moodle/course:viewhiddenactivities', $coursectx),
        'can_enrol_users'        => has_capability('enrol/manual:enrol',         $coursectx),
        'can_access_all_groups'  => has_capability('moodle/site:accessallgroups', $coursectx),
    ];

    // Cursos según rol (para scope de RBAC en el gateway)
    $course_ids_owned   = [];   // cursos donde el usuario tiene poder (teacher / admin / manager)
    $course_ids_enrolled = [];  // cursos donde el usuario está inscrito (student)

    if ($role === 'teacher' || $role === 'manager') {
        $courses = enrol_get_users_courses($USER->id, true);
        foreach ($courses as $c) {
            $ctx = context_course::instance($c->id);
            if (has_capability('moodle/course:manageactivities', $ctx)) {
                $course_ids_owned[] = (int)$c->id;
            }
        }
    } elseif ($role === 'admin') {
        // Admin no necesita lista de cursos — tiene acceso total
        $course_ids_owned = [];
    } elseif ($role === 'student') {
        // Para student: lista de cursos inscritos (usada por el gateway para filtrar)
        $courses = enrol_get_users_courses($USER->id, true);
        foreach ($courses as $c) {
            $course_ids_enrolled[] = (int)$c->id;
        }
    }

    $tools = local_jedomi_get_tool_definitions($role);

    $payload = [
        'userid'              => (int) $USER->id,
        'username'            => $USER->username,
        'role'                => $role,
        'course_id'           => (int) ($COURSE->id ?? 0),
        'course_ids_owned'    => $course_ids_owned,    // teacher/manager: sus cursos
        'course_ids_enrolled' => $course_ids_enrolled, // student: cursos inscritos
        'capabilities'        => $capabilities,
        'tools'               => $tools,               // Tool defs en OpenAI format — gateway las usa para configurar el modelo
        'moodle_url'          => $CFG->wwwroot,
        'expires'             => time() + 1800,  // 30 minutos (conversaciones con múltiples tool calls)
    ];

    // Firma HMAC con el static secret — el mismo que usa el Gateway Python para verificar.
    // El sesskey() se incluye en el payload (no como key) para binding de sesión:
    // si la sesión cambia, el payload cambia y el SPT queda inválido de todas formas.
    $static_secret = get_config('local_jedomi', 'spt_secret') ?: 'jedomi-spt-secret';
    $payload['sesskey_hash'] = substr(hash('sha256', sesskey()), 0, 16); // binding sin exponer sesskey

    ksort($payload);  // ordenar claves antes de serializar (consistencia con Python sort_keys)
    $to_sign   = json_encode($payload);
    $signature = hash_hmac('sha256', $to_sign, $static_secret);

    $payload['signature'] = $signature;
    return $payload;
}

/**
 * Generate an SPT (Secure Payload Token) for a specific user (not global $USER).
 *
 * Used by the Telegram webhook where there is no page context or global $USER.
 * Creates a self-signed payload with user identity, capabilities, course lists,
 * and HMAC signature for the gateway to verify.
 *
 * @param  int    $userid The user ID to generate the SPT for.
 * @param  string $role   The Jedomi role shortname (admin|manager|teacher|student).
 * @return array          The signed SPT payload, or empty array if user not found/deleted.
 */
function local_jedomi_generate_spt_for_user(int $userid, string $role): array {
    global $CFG, $DB;

    $sysctx = context_system::instance();
    $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id, username, firstname, lastname');
    if (!$user) {
        return [];
    }

    // Capabilities for this specific user (not global $USER).
    $capabilities = [
        'can_view_all_grades'   => has_capability('moodle/grade:viewall', $sysctx, $userid),
        'can_manage_users'      => has_capability('moodle/user:update', $sysctx, $userid),
        'can_send_messages'     => has_capability('moodle/site:sendmessage', $sysctx, $userid),
        'can_manage_courses'    => has_capability('moodle/course:update', $sysctx, $userid),
        'can_view_reports'      => has_capability('moodle/course:viewhiddenactivities', $sysctx, $userid),
        'can_enrol_users'       => has_capability('enrol/manual:enrol', $sysctx, $userid),
        'can_access_all_groups' => has_capability('moodle/site:accessallgroups', $sysctx, $userid),
    ];

    // Course lists based on role (same logic as generate_spt but for specific user).
    $course_ids_owned = [];
    $course_ids_enrolled = [];

    if ($role === 'teacher' || $role === 'manager') {
        $courses = enrol_get_users_courses($userid, true, ['id']);
        foreach ($courses as $c) {
            $ctx = context_course::instance($c->id);
            if (has_capability('moodle/course:manageactivities', $ctx, $userid)) {
                $course_ids_owned[] = (int)$c->id;
            }
        }
    } elseif ($role === 'student') {
        $courses = enrol_get_users_courses($userid, true, ['id']);
        foreach ($courses as $c) {
            $course_ids_enrolled[] = (int)$c->id;
        }
    }
    // Admin: no course list (full access).

    $tools = local_jedomi_get_tool_definitions($role);

    $payload = [
        'userid'              => $userid,
        'username'            => $user->username,
        'role'                => $role,
        'course_id'           => 0,
        'course_ids_owned'    => $course_ids_owned,
        'course_ids_enrolled' => $course_ids_enrolled,
        'capabilities'        => $capabilities,
        'tools'               => $tools,
        'moodle_url'          => $CFG->wwwroot,
        'expires'             => time() + 1800,
    ];

    // Same HMAC signing as the existing generate_spt function.
    $static_secret = get_config('local_jedomi', 'spt_secret') ?: 'jedomi-spt-secret';

    ksort($payload);
    $to_sign = json_encode($payload);
    $signature = hash_hmac('sha256', $to_sign, $static_secret);

    $payload['signature'] = $signature;
    return $payload;
}

function local_jedomi_get_user_role(): string {
    global $USER, $COURSE;

    // 1. Siteadmin → acceso total (arquetipo: admin)
    if (is_siteadmin()) return 'admin';

    $sysctx = context_system::instance();

    // 2. Manager: tiene moodle/user:update a nivel de sistema pero NO es siteadmin.
    //    Esto cubre el arquetipo 'manager' de Moodle y cualquier rol personalizado
    //    con privilegios equivalentes (gestión de usuarios/cursos sin ser admin).
    if (has_capability('moodle/user:update', $sysctx)) return 'manager';

    // 3. Teacher: puede gestionar actividades en el curso actual.
    //    Cubre arquetipos 'editingteacher' y cualquier rol personalizado con esa capacidad.
    if (!empty($COURSE->id) && $COURSE->id > 1) {
        $ctx = context_course::instance($COURSE->id);
        if (has_capability('moodle/course:manageactivities', $ctx)) return 'teacher';
        return 'student';
    }

    // 4. Fuera de un curso específico: student con tools propias (grades, calendar, cursos inscritos).
    //    Un docente que navega fuera de un curso obtiene acceso student — seguro y aceptable.
    return 'student';
}

/**
 * Determine a user's Jedomi role WITHOUT depending on $COURSE or $PAGE globals.
 *
 * Used by the Telegram webhook where there is no page context or current course.
 * Checks admin status via get_admins(), manager via system-level capability,
 * teacher via manageactivities in any enrolled course, and falls back to student.
 *
 * @param  int    $userid The user ID to check.
 * @return string         'admin', 'manager', 'teacher', or 'student'.
 */
function local_jedomi_get_user_role_for_user(int $userid): string {

    // 1. Check if user is site admin via get_admins().
    $admins = get_admins();
    foreach ($admins as $admin) {
        if ((int) $admin->id === $userid) {
            return 'admin';
        }
    }

    $sysctx = context_system::instance();

    // 2. Manager: has moodle/user:update at system level.
    if (has_capability('moodle/user:update', $sysctx, $userid)) {
        return 'manager';
    }

    // 3. Teacher: has moodle/course:manageactivities in ANY of their enrolled courses.
    $courses = enrol_get_users_courses($userid, true, ['id']);
    foreach ($courses as $c) {
        $ctx = context_course::instance($c->id);
        if (has_capability('moodle/course:manageactivities', $ctx, $userid)) {
            return 'teacher';
        }
    }

    // 4. Fallback: student.
    return 'student';
}

/**
 * Returns true when the Telegram integration should be shown/available to the current user.
 * Reuses the same per-role visibility config pattern as the sidebar (hidden_roles),
 * but with a separate config key: telegram_hidden_roles.
 *
 * Site admins always see it.
 *
 * @return bool
 */
function local_jedomi_is_telegram_role_allowed(): bool {
    global $USER, $PAGE;

    // Site admins always have access.
    if (is_siteadmin()) {
        return true;
    }

    $hidden = get_config('local_jedomi', 'telegram_hidden_roles') ?: '';
    if (empty($hidden)) {
        return true; // No restrictions configured yet.
    }

    $hidden_ids = array_filter(array_map('intval', explode(',', $hidden)));
    if (empty($hidden_ids)) {
        return true;
    }

    // Collect the user's roles in the current page context + system context.
    $contexts_to_check = [context_system::instance()];
    if (!empty($PAGE->context)) {
        $contexts_to_check[] = $PAGE->context;
    }

    $user_role_ids = [];
    foreach ($contexts_to_check as $ctx) {
        $roles = get_user_roles($ctx, $USER->id, false);
        foreach ($roles as $r) {
            $user_role_ids[] = (int) $r->roleid;
        }
    }
    $user_role_ids = array_unique($user_role_ids);

    if (empty($user_role_ids)) {
        return true; // No roles assigned — allow by default.
    }

    // If EVERY role the user has is in the hidden list → block.
    // If AT LEAST ONE role is not hidden → allow.
    foreach ($user_role_ids as $rid) {
        if (!in_array($rid, $hidden_ids, true)) {
            return true; // Has at least one non-hidden role.
        }
    }

    return false; // All roles are hidden.
}

/**
 * Get the allowlist of web services for a given role.
 *
 * @param  string $role The role shortname (admin|manager|teacher|student).
 * @return array        List of allowed WS names.
 */
if (!function_exists('local_jedomi_get_role_allowlist')) {
function local_jedomi_get_role_allowlist(string $role): array {
    $json = get_config('local_jedomi', 'ws_allowlist_' . $role);
    if (empty($json)) {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
    }
}

/**
 * Get ALL registered Moodle web services.
 *
 * @return array Records with name, description, component, type.
 */
function local_jedomi_get_all_ws(): array {
    global $DB;
    $sql = "SELECT DISTINCT ef.name, '' AS description, ef.component
              FROM {external_functions} ef
          ORDER BY ef.component, ef.name";
    return $DB->get_records_sql($sql);
}

/**
 * Resolve the FQCN for a web service function name.
 *
 * @param  string      $wsname The WS function name (e.g. core_course_get_courses).
 * @return string|null         The fully qualified class name, or null.
 */
function local_jedomi_resolve_ws_classname(string $wsname): ?string {
    global $DB, $CFG;
    $record = $DB->get_record('external_functions', ['name' => $wsname], 'classname, classpath, component');
    if (!$record) {
        return null;
    }
    // Try class autoloading first (namespaced classes in Moodle 4.x+).
    if (class_exists($record->classname)) {
        return $record->classname;
    }
    // Fallback: load externallib.php via classpath.
    if (!empty($record->classpath)) {
        $path = "{$CFG->dirroot}/{$record->classpath}";
    } else {
        $path = core_component::get_component_directory($record->component) . '/externallib.php';
    }
    if (file_exists($path)) {
        require_once($path);
    }
    return class_exists($record->classname) ? $record->classname : null;
}

/**
 * Recursively convert a Moodle external structure to a plain JSON-serializable array.
 *
 * @param  mixed  $struct An external_value / external_single_structure / external_multiple_structure.
 * @return array|null
 */
function local_jedomi_convert_external_structure($struct): ?array {
    if ($struct instanceof \core_external\external_value) {
        return [
            'type' => $struct->type,
            'required' => ($struct->required == VALUE_REQUIRED),
            'default' => ($struct->required != VALUE_REQUIRED) ? $struct->default : null,
        ];
    }
    if ($struct instanceof \core_external\external_single_structure) {
        $keys = [];
        foreach ($struct->keys as $key => $val) {
            $keys[$key] = local_jedomi_convert_external_structure($val);
        }
        return ['type' => 'object', 'keys' => $keys];
    }
    if ($struct instanceof \core_external\external_multiple_structure) {
        return [
            'type' => 'array',
            'content' => local_jedomi_convert_external_structure($struct->content),
        ];
    }
    return null;
}

/**
 * Get the parameter schema for a web service function via reflection.
 *
 * @param  string     $wsname The WS function name.
 * @return array|null         JSON-serializable schema, or null on failure.
 */
function local_jedomi_get_ws_schema(string $wsname): ?array {
    global $DB;
    $classname = local_jedomi_resolve_ws_classname($wsname);
    if (!$classname || !class_exists($classname)) {
        return null;
    }
    try {
        // Try execute_parameters() first (single-method WS classes like
        // local_jedomi\external\ws_execute).
        if (method_exists($classname, 'execute_parameters')) {
            $ref = new ReflectionMethod($classname, 'execute_parameters');
            if ($ref->isStatic()) {
                $params = @$ref->invoke(null);
                if ($params instanceof \core_external\external_function_parameters) {
                    return local_jedomi_convert_external_structure($params);
                }
            }
        }

        // Fallback: try {methodname}_parameters() for multi-method classes
        // like core_course_external where the WS name maps to create_categories()
        // and the schema method is create_categories_parameters().
        $record = $DB->get_record('external_functions', ['name' => $wsname], 'methodname');
        if ($record && !empty($record->methodname)) {
            $methodname = $record->methodname . '_parameters';
            if (method_exists($classname, $methodname)) {
                $ref = new ReflectionMethod($classname, $methodname);
                if ($ref->isStatic()) {
                    $params = @$ref->invoke(null);
                    if ($params instanceof \core_external\external_function_parameters) {
                        return local_jedomi_convert_external_structure($params);
                    }
                }
            }
        }

        return null;
    } catch (\Exception $e) {
        return null;
    }
}

/**
 * Classify a web service function as Lectura (read) or Escritura (write) based on its name.
 *
 * Uses the last word of the function name and compound patterns to determine the access type.
 * Returns empty string if classification is ambiguous.
 *
 * @param  string $wsname The web service function name (e.g. 'core_course_get_courses').
 * @return string         'Lectura', 'Escritura', or ''.
 */
function local_jedomi_classify_ws_access(string $wsname): string {
    // Extract the action part after the component prefix.
    $parts = explode('_', $wsname);
    $action = end($parts);

    // Read keywords (lectura).
    if (preg_match('/^(get|view|search|list|is|check|validate|export|download|fetch|retrieve|count|exists)$/i', $action)) {
        return 'Lectura';
    }

    // Also check the full name for compound read patterns.
    if (preg_match('/_(get|view|search|list|check|export|download|fetch|retrieve)_/i', $wsname)) {
        return 'Lectura';
    }

    // Write keywords (escritura).
    if (preg_match('/^(create|update|delete|add|remove|set|edit|save|insert|upload|import|purge|reset|restore|reorder|toggle|approve|reject|block|unblock|lock|unlock|archive|trash|publish|unpublish|submit|enrol|unenrol|assign|unassign|mark|flag|clear|move|copy|duplicate|switch|change|revoke|grant)$/i', $action)) {
        return 'Escritura';
    }
    if (preg_match('/_(create|update|delete|add|remove|save|insert|upload|import|purge|enrol|assign|grant|revoke)_/i', $wsname)) {
        return 'Escritura';
    }

    return '';
}

/**
 * Write an entry to the Jedomi debug log.
 *
 * Logs are stored in {$CFG->dataroot}/local/jedomi/jedomi.log
 * in a human-readable format with timestamp and structured context.
 *
 * @param string $message Short description of what happened.
 * @param mixed  $data    Optional context data (array, string, or anything json-serializable).
 */
if (!function_exists('local_jedomi_log')) {
    function local_jedomi_log(string $message, mixed $data = null): void {
        global $CFG;

        static $logdir = null;
        static $logfile = null;

        if ($logdir === null) {
            $logdir = $CFG->dataroot . '/local/jedomi';
            check_dir_exists($logdir, true, true);
            $logfile = $logdir . '/jedomi.log';
        }

        $timestamp = date('Y-m-d H:i:s.v');
        $entry = "[{$timestamp}] {$message}";

        if ($data !== null) {
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE);
            $entry .= ' | ' . $encoded;
        }

        $entry .= "\n";
        file_put_contents($logfile, $entry, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Convert HTML tables in a string to pipe-delimited plain text.
 *
 * Telegram's sendMessage HTML mode does NOT support <table>, <tr>, <td>, <th>.
 * When the LLM returns a table, strip_tags removes all table tags and the
 * data becomes unreadable (e.g. "IDName1Admin2buendata...").
 *
 * This function finds every <table>...</table> block, converts it to a
 * pipe-delimited format, and wraps it in a <pre> block so the user sees
 * a readable monospace table.
 *
 * @param  string $html The HTML content (may contain tables).
 * @return string       Content with tables converted to pipe-delimited <pre> blocks.
 */
function local_jedomi_sanitize_html_tables(string $html): string {
    return preg_replace_callback('/<table[^>]*>(.*?)<\/table>/is', function($tableMatch) {
        $tableHtml = $tableMatch[1];

        // ── Extract headers ────────────────────────────────────────────
        $headers = [];
        if (preg_match('/<tr[^>]*>(.*?)<\/tr>/is', $tableHtml, $headerRow)) {
            preg_match_all('/<th[^>]*>(.*?)<\/th>/is', $headerRow[1], $headerCells);
            foreach ($headerCells[1] as $cell) {
                $headers[] = trim(strip_tags($cell));
            }
        }

        // ── Extract rows ───────────────────────────────────────────────
        $rows = [];
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $tableHtml, $rowMatches);
        foreach ($rowMatches[1] as $rowHtml) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $rowHtml, $cellMatches);
            $row = [];
            foreach ($cellMatches[1] as $cell) {
                $row[] = trim(strip_tags($cell));
            }
            if (!empty($row)) {
                $rows[] = $row;
            }
        }

        // If no <th> found, use first row as header.
        if (empty($headers) && !empty($rows)) {
            $headers = array_shift($rows);
        }

        // ── Build pipe-delimited output ────────────────────────────────
        $allRows = [];
        if (!empty($headers)) {
            $allRows[] = $headers;
        }
        foreach ($rows as $row) {
            $allRows[] = $row;
        }

        if (empty($allRows)) {
            return ''; // Empty table → remove it.
        }

        // Calculate column widths.
        $colCount = max(array_map('count', $allRows));
        $widths = array_fill(0, $colCount, 0);
        foreach ($allRows as $row) {
            for ($i = 0; $i < $colCount; $i++) {
                $cell = $row[$i] ?? '';
                $widths[$i] = max($widths[$i], mb_strlen($cell));
            }
        }

        // Format with padding.
        $lines = [];
        foreach ($allRows as $ri => $row) {
            $parts = [];
            for ($i = 0; $i < $colCount; $i++) {
                $cell = $row[$i] ?? '';
                $parts[] = str_pad($cell, $widths[$i]);
            }
            $lines[] = '| ' . implode(' | ', $parts) . ' |';

            // Separator after header row.
            if ($ri === 0 && !empty($headers)) {
                $sepParts = [];
                for ($i = 0; $i < $colCount; $i++) {
                    $sepParts[] = str_repeat('-', $widths[$i]);
                }
                $lines[] = '|-' . implode('-|-', $sepParts) . '-|';
            }
        }

        return '<pre>' . htmlspecialchars(implode("\n", $lines), ENT_QUOTES, 'UTF-8') . '</pre>';
    }, $html) ?? $html;
}

/**
 * Send an agent execution notification to Telegram.
 *
 * Decides automatically:
 * - If text ≤ 3800 chars → sendMessage (HTML formatted).
 * - If text > 3800 chars → write to a .txt file in dataroot/jedomi/telegram/,
 *   send via sendDocument, then delete the temp file.
 *
 * @param  int|string $chatid    Telegram chat ID.
 * @param  string     $agent     Agent label (name or "Agent #N").
 * @param  string     $content   The AI's response text (raw, may contain HTML).
 * @return bool                  True if sent successfully.
 */
function local_jedomi_send_agent_notification(int|string $chatid, string $agent, string $content): bool {
    global $CFG;

    // Sanitize HTML tables before any strip_tags call.
    $content = \local_jedomi_sanitize_html_tables($content);

    // ── Build caption (fits within Telegram's 1024-char limit) ─────────
    // Header with agent name + status + date.
    $agentLabel = strip_tags($agent);
    $dateStr = userdate(time());
    $header = "🤖 {$agentLabel}\n✅ Executed successfully\n📅 {$dateStr}\n\n";

    // Plain-text summary (no HTML) — truncate so header + wrapper fits ≤ 1024.
    $summary = mb_substr(strip_tags($content), 0, 1500);
    $maxSummaryLen = 1024 - mb_strlen($header) - 7; // 7 = <i></i>
    if ($maxSummaryLen < 0) $maxSummaryLen = 0;
    $summary = mb_substr($summary, 0, $maxSummaryLen);

    // ── Decision: message vs file ──────────────────────────────────────
    $client = new \local_jedomi\telegram\api_client();

    if (mb_strlen($content) < 3800) {
        // Full content fits in a single message — send it inline.
        $body = strip_tags($content);
        $msg = $header . "<i>" . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . "</i>";
        return $client->send_message($chatid, $msg);
    }

    // ── Large result → send as .txt file ──────────────────────────────
    // Extract a brief one-line description from the AI response (first meaningful line,
    // not a code fence or empty line). This goes in the caption so the user knows what
    // the result is about without seeing the actual data.
    $plaintext = strip_tags($content);
    $lines = explode("\n", $plaintext);
    $oneliner = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if (!empty($line) && !str_starts_with($line, '```')) {
            $oneliner = $line;
            break;
        }
    }
    // Keep it short — truncate at first sentence boundary (~200 chars max).
    if (mb_strlen($oneliner) > 200) {
        $truncated = mb_substr($oneliner, 0, 197);
        $lastSentence = max(
            mb_strrpos($truncated, '.'),
            mb_strrpos($truncated, '!'),
            mb_strrpos($truncated, '?')
        );
        if ($lastSentence > 20) {
            $oneliner = mb_substr($truncated, 0, $lastSentence + 1);
        } else {
            $oneliner = $truncated . '...';
        }
    }

    // Caption: header + one-liner. No data preview. Full result goes in the file.
    $caption = $header . htmlspecialchars($oneliner, ENT_QUOTES, 'UTF-8');

    $tmpdir = $CFG->dataroot . '/jedomi/telegram';
    check_dir_exists($tmpdir, true, true);

    $filename = 'agent_' . time() . '_' . bin2hex(random_bytes(4)) . '.txt';
    $filepath = $tmpdir . '/' . $filename;

    // If the response has a fenced code block (```csv, ```sql, ```, etc.) or a
    // <pre> block, extract just the content inside it for the file.
    // Otherwise use the full stripped content.
    $fileContent = strip_tags($content);
    if (preg_match('/```(?:\w+)?\s*\n(.+?)\n```/s', $content, $m)) {
        $fileContent = trim($m[1]);
    } else if (preg_match('/<pre>(.+?)<\/pre>/s', $content, $m)) {
        $fileContent = trim($m[1]);
    }
    $bytes = file_put_contents($filepath, $fileContent);

    $sent = $client->send_document($chatid, $filepath, $caption);

    // Clean up — file was sent, no longer needed.
    @unlink($filepath);

    return $sent;
}
