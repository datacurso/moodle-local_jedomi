<?php
// ── Plugin identity ───────────────────────────────────────────────────────────
$string['pluginname']      = 'Jedomi AI Sidebar';

// ── Sidebar UI ────────────────────────────────────────────────────────────────
$string['sidebar_title']   = 'Jedomi AI';
$string['open_sidebar']    = 'Abrir Jedomi AI';
$string['close']           = 'Cerrar (Esc)';
$string['new_conversation']= 'Nueva conversación';
$string['export_conversation'] = 'Exportar conversación';
$string['toggle_thinking'] = 'Mostrar/ocultar animación de pensamiento';
$string['half_width']      = 'Media pantalla';
$string['full_width']      = 'Pantalla completa';

// ── Input area ────────────────────────────────────────────────────────────────
$string['attach']           = 'Adjuntar';
$string['attach_file']      = 'Adjuntar PDF/CSV';
$string['attach_image']     = 'Adjuntar imagen';
$string['chat_placeholder'] = 'Preguntá a Jedomi...';
$string['file']             = 'Archivo';
$string['image']            = 'Imagen';
$string['message_input']    = 'Mensaje a Jedomi';
$string['send']             = 'Enviar';
$string['voice_input']      = 'Dictar (voz)';

// ── Greeting ──────────────────────────────────────────────────────────────────
$string['greeting']        = '¡Hola, {name}!';
$string['hello']           = 'Soy Jedomi, tu asistente IA de Moodle. Pregúntame sobre cursos, alumnos, notas o configuraciones.';
$string['thinking']        = 'Pensando...';

// ── New conversation ──────────────────────────────────────────────────────────
$string['new_conversation_msg'] = 'Nueva conversación iniciada. ¿En qué te ayudo?';

// ── Image / file attachment ───────────────────────────────────────────────────
$string['analyze_image']       = 'Analizá esta imagen.';
$string['attached_file_info']  = '[Archivo adjunto: {$a}]';
$string['attached_image']      = 'Imagen adjunta';
$string['csv_download']        = '📥 Descargar CSV';
$string['file_csv']            = 'CSV: {rows} filas, {columns} columnas';
$string['file_loaded']         = 'cargado';
$string['file_loaded_hint']    = 'Podés hacer preguntas sobre su contenido.';
$string['file_pdf']            = 'PDF: {pages} páginas';
$string['file_process_error']  = 'No se pudo procesar';
$string['file_text']           = 'Texto: {chars} caracteres';
$string['processing_file']     = 'Procesando {$a}...';

// ── Tool execution status ─────────────────────────────────────────────────────
$string['tool_sql_read']          = 'Consultando base de datos...';
$string['tool_api_call']          = 'Ejecutando acción en Moodle...';
$string['tool_schema_lookup']     = 'Buscando tabla...';
$string['tool_webservice_lookup'] = 'Buscando función API...';

// ── Message actions ───────────────────────────────────────────────────────────
$string['action_copy']         = 'Copiar';
$string['action_helpful']      = 'Útil';
$string['action_improve']      = 'Mejorar';
$string['action_improve_title']= 'Mejorable';
$string['action_edit']         = 'Editar';
$string['action_edit_title']   = 'Editar mensaje';

// ── Bulk message confirmation ─────────────────────────────────────────────────
$string['confirm_send_bulk']       = '¿Enviar este mensaje a {count} alumno(s)?';
$string['yes_send']                = 'Sí, enviar';
$string['cancel']                  = 'Cancelar';
$string['sending']                 = 'Enviando...';
$string['cancelled']               = 'Cancelado.';
$string['messages_sent']           = '{sent} mensaje(s) enviados como mensajes internos de Moodle.';
$string['messages_sent_fail']      = '({failed} fallaron)';
$string['messages_recipients_note']= 'Los destinatarios los ven en su bandeja de mensajes de Moodle.';
$string['no_messages_sent']        = 'No se enviaron mensajes.';
$string['unknown_error']           = 'Error desconocido';
$string['connection_error']        = 'Error de conexión:';

// ── Gateway error ─────────────────────────────────────────────────────────────
$string['gateway_error_title'] = 'No pude conectarme al Gateway Jedomi.';
$string['gateway_error_hint']  = 'Verificá que el servicio esté corriendo:';

// ── Microphone ────────────────────────────────────────────────────────────────
$string['listening']        = 'Escuchando...';
$string['mic_error']        = 'No se pudo acceder al micrófono. Verificá los permisos del navegador.';
$string['silence_detected'] = 'Silencio...';
$string['transcribing']     = 'Transcribiendo...';
$string['waiting_voice']    = 'Esperando voz...';

// ── Confirmation system (destructive actions) ────────────────────────────────
$string['confirm_type_to_proceed'] = 'Escribí CONFIRMAR para proceder:';
$string['confirm_btn_yes']         = 'Confirmar';
$string['confirm_btn_no']          = 'Cancelar';
$string['confirm_executing']       = 'Ejecutando...';
$string['confirm_done']            = 'Acción ejecutada correctamente.';
$string['confirm_cancelled']       = 'Acción cancelada.';

// ── Context labels (sidebar badge) ───────────────────────────────────────────
$string['ctx_activity']  = 'Actividad';
$string['ctx_admin']     = 'Administración';
$string['ctx_calendar']  = 'Calendario';
$string['ctx_course']    = 'Curso';
$string['ctx_dashboard'] = 'Dashboard';
$string['ctx_files']     = 'Archivos';
$string['ctx_grades']    = 'Calificaciones';
$string['ctx_messages']  = 'Mensajes';
$string['ctx_profile']   = 'Perfil';

// ── Quick actions: Admin ──────────────────────────────────────────────────────
$string['qa_admin_active_users_label']    = 'Usuarios activos';
$string['qa_admin_active_users_msg']      = '¿Cuántos usuarios activos hay en el sistema?';
$string['qa_admin_alerts_label']          = 'Alertas';
$string['qa_admin_alerts_msg']            = '¿Qué necesita atención inmediata hoy?';
$string['qa_admin_at_risk_label']         = 'En riesgo';
$string['qa_admin_at_risk_msg']           = '¿Qué estudiantes están en riesgo de perder el curso {$a}?';
$string['qa_admin_empty_cohorts_label']   = 'Cohortes vacías';
$string['qa_admin_empty_cohorts_msg']     = '¿Hay cohortes sin miembros asignados?';
$string['qa_admin_external_emails_label'] = 'Emails externos';
$string['qa_admin_external_emails_msg']   = '¿Hay usuarios con correos no institucionales?';
$string['qa_admin_gdpr_pending_label']    = 'GDPR pendiente';
$string['qa_admin_gdpr_pending_msg']      = '¿Hay solicitudes de eliminación GDPR pendientes?';
$string['qa_admin_inactive_label']        = 'Inactivos';
$string['qa_admin_inactive_msg']          = '¿Qué estudiantes no accedieron al curso {$a} en los últimos 30 días?';
$string['qa_admin_missing_work_label']    = 'Tareas pendientes';
$string['qa_admin_missing_work_msg']      = '¿Qué estudiantes no entregaron actividades en el curso {$a}?';
$string['qa_admin_overview_label']        = 'Resumen';
$string['qa_admin_overview_msg']          = 'Dame un resumen del estado general de la plataforma';
$string['qa_admin_performance_label']     = 'Rendimiento';
$string['qa_admin_performance_msg']       = 'Mostrame el ranking de notas del curso {$a}';
$string['qa_admin_security_label']        = 'Seguridad';
$string['qa_admin_security_msg']          = '¿Hay usuarios con caracteres sospechosos en sus nombres?';
$string['qa_admin_storage_label']         = 'Almacenamiento';
$string['qa_admin_storage_msg']           = '¿Qué curso tiene los archivos más pesados?';
$string['qa_admin_top_courses_label']     = 'Mejores cursos';
$string['qa_admin_top_courses_msg']       = '¿Qué cursos tienen mejor rendimiento?';
$string['qa_admin_top_student_label']     = 'Mejor estudiante';
$string['qa_admin_top_student_msg']       = '¿Quién es el mejor estudiante en el curso {$a}?';

// ── Quick actions: Manager ────────────────────────────────────────────────────
$string['qa_manager_at_risk_label']       = 'En riesgo';
$string['qa_manager_at_risk_msg']         = '¿Qué estudiantes están en riesgo en el curso {$a}?';
$string['qa_manager_enrolled_label']      = 'Inscriptos';
$string['qa_manager_enrolled_msg']        = '¿Cuántos estudiantes hay inscriptos en el curso {$a}?';
$string['qa_manager_global_risk_label']   = 'Riesgo global';
$string['qa_manager_global_risk_msg']     = '¿Hay estudiantes en riesgo de perder algún curso?';
$string['qa_manager_inactive_label']      = 'Inactivos';
$string['qa_manager_inactive_msg']        = '¿Qué estudiantes no accedieron al curso {$a} en los últimos 14 días?';
$string['qa_manager_inactive_users_label'] = 'Usuarios inactivos';
$string['qa_manager_inactive_users_msg']  = '¿Qué usuarios no accedieron al sistema en los últimos 30 días?';
$string['qa_manager_no_teacher_label']    = 'Sin profesor';
$string['qa_manager_no_teacher_msg']      = '¿Hay cursos sin profesor asignado?';
$string['qa_manager_overview_label']      = 'Resumen';
$string['qa_manager_overview_msg']        = 'Dame un resumen del curso {$a}';
$string['qa_manager_top_courses_label']   = 'Mejores cursos';
$string['qa_manager_top_courses_msg']     = '¿Qué cursos tienen más actividad esta semana?';

// ── Quick actions: Teacher ────────────────────────────────────────────────────
$string['qa_teacher_at_risk_label']       = 'En riesgo';
$string['qa_teacher_at_risk_msg']         = '¿Qué estudiantes están en riesgo en el curso {$a}?';
$string['qa_teacher_my_alerts_label']     = 'Mis alertas';
$string['qa_teacher_my_alerts_msg']       = '¿Hay estudiantes en riesgo en alguno de mis cursos?';
$string['qa_teacher_my_courses_label']    = 'Mis cursos';
$string['qa_teacher_my_courses_msg']      = '¿En qué cursos soy profesor actualmente?';
$string['qa_teacher_missing_work_label']  = 'Tareas pendientes';
$string['qa_teacher_missing_work_msg']    = '¿Quién no entregó la última tarea en el curso {$a}?';
$string['qa_teacher_never_logged_label']  = 'Nunca accedieron';
$string['qa_teacher_never_logged_msg']    = '¿Qué estudiantes nunca accedieron al curso {$a}?';
$string['qa_teacher_notify_group_label']  = 'Notificar grupo';
$string['qa_teacher_notify_group_msg']    = 'Redactá un mensaje para los estudiantes del curso {$a} recordándoles las actividades pendientes';
$string['qa_teacher_performance_label']   = 'Rendimiento';
$string['qa_teacher_performance_msg']     = '¿Cuál es el promedio de notas en el curso {$a}?';
$string['qa_teacher_top_students_label']  = 'Mejores estudiantes';
$string['qa_teacher_top_students_msg']    = '¿Quién tiene más de 90 puntos en el curso {$a}?';

// ── Quick actions: Student ────────────────────────────────────────────────────
$string['qa_student_help_label']          = 'Ayuda';
$string['qa_student_help_msg']            = 'No entiendo el contenido del curso {$a}. ¿Podés explicármelo?';
$string['qa_student_my_courses_label']    = 'Mis cursos';
$string['qa_student_my_courses_msg']      = '¿En qué cursos estoy inscripto actualmente?';
$string['qa_student_my_grade_label']      = 'Mi nota';
$string['qa_student_my_grade_msg']        = '¿Cuál es mi promedio en el curso {$a}?';
$string['qa_student_my_grades_label']     = 'Mis notas';
$string['qa_student_my_grades_msg']       = '¿Cuál es mi promedio general en todos mis cursos?';
$string['qa_student_my_progress_label']   = 'Mi progreso';
$string['qa_student_my_progress_msg']     = '¿Cuántas actividades completé en el curso {$a}?';
$string['qa_student_my_tasks_label']      = 'Mis tareas';
$string['qa_student_my_tasks_msg']        = '¿Qué tareas tengo pendientes en el curso {$a}?';
$string['qa_student_this_week_label']     = 'Esta semana';
$string['qa_student_this_week_msg']       = '¿Qué actividades y fechas límite tengo próximamente?';

// ── Admin settings ────────────────────────────────────────────────────────────
$string['settings_redirect_help']     = 'La configuración de Jedomi se gestiona en una página dedicada con pestañas.';
$string['open_admin_settings']        = 'Abrir configuración de Jedomi';
$string['tab_general']                = 'General';
$string['tab_privacy']                = 'Privacidad';
$string['gateway_heading']            = 'Conexión al Gateway';
$string['gateway_heading_help']       = 'Configuración de conexión con el backend de IA de Jedomi.';
$string['security_heading']           = 'Seguridad';
$string['security_heading_help']      = 'Tokens y secretos compartidos entre Moodle y el Gateway.';
$string['improvement_heading']        = 'Mejora continua';
$string['improvement_heading_help']   = 'Recolección opcional de datos para mejorar Jedomi con el tiempo.';
$string['enabled_help']               = 'Muestra u oculta el sidebar de Jedomi en todo el sitio.';
$string['privacy_access_heading']     = 'Control de acceso';
$string['privacy_access_heading_help']= 'Quién puede ver y usar el sidebar de Jedomi.';
$string['role_visibility_label']      = 'Mostrar sidebar por rol';
$string['role_visibility_help']       = 'Desactivá el toggle para ocultar el sidebar a los usuarios con ese rol. Los administradores del sitio siempre lo ven.';
$string['privacy_anon_heading']       = 'Anonimización de datos';
$string['privacy_anon_heading_help']  = 'Controla cómo se anonimiza la información de las conversaciones antes de almacenarla.';
$string['hide_from_guests_help']      = 'Cuando está activado, los invitados y usuarios no autenticados no verán el sidebar.';
$string['anon_basic_desc']            = 'Reemplaza nombres y correos. Conserva la mayor parte del contexto.';
$string['anon_strict_desc']           = 'Reemplaza nombres, correos, IDs y texto libre. Máxima privacidad.';
$string['anon_feedback_only_desc']    = 'Solo guarda calificaciones y botones de feedback. Sin texto de conversación.';
$string['settings']        = 'Configuración de Jedomi';
$string['gateway_url']     = 'URL del Gateway';
$string['gateway_url_help']= 'URL del Gateway de Jedomi. Ejemplo: http://localhost:8765';
$string['api_key']         = 'API Key';
$string['model']           = 'Modelo';
$string['enabled']         = 'Activar sidebar de Jedomi';
$string['spt_secret']      = 'Secreto del token de permisos';
$string['spt_secret_help'] = 'Secreto compartido entre Moodle y el Gateway para firmar los tokens de permisos. Debe coincidir con SPT_SECRET en el .env.';
$string['collect_training_data']     = 'Recopilar conversaciones anonimizadas para entrenamiento';
$string['collect_training_data_help']= 'Cuando está activo, las conversaciones se guardan de forma anónima para mejorar versiones futuras. No se retienen datos personales.';
$string['anonymization_level']       = 'Nivel de anonimización';
$string['anonymization_level_help']  = 'Qué tan agresivamente anonimizar las conversaciones guardadas.';
$string['anon_basic']                = 'Básico (nombres y emails)';
$string['anon_strict']               = 'Estricto (nombres, emails, IDs, texto libre)';
$string['anon_feedback_only']        = 'Solo feedback (no se guarda el texto de conversaciones)';
$string['training_data_title']       = 'Datos de entrenamiento Jedomi';
$string['course_availability_heading']     = 'Disponibilidad por curso';
$string['course_availability_heading_help']= 'Controlá en qué cursos aparece el sidebar de Jedomi.';
$string['course_mode']               = 'Modo de disponibilidad';
$string['course_mode_help']          = 'Seleccioná cómo controlar la disponibilidad por curso.';
$string['course_mode_all']           = 'Todos los cursos (por defecto)';
$string['course_mode_whitelist']     = 'Solo los cursos seleccionados (lista blanca)';
$string['course_mode_blacklist']     = 'Todos los cursos excepto los seleccionados (lista negra)';
$string['course_list']               = 'IDs de cursos';
$string['course_list_help']          = 'Ingresá los IDs de cursos separados por comas. Ejemplo: 5, 10, 23. Dejá vacío para sin restricción.';
$string['hide_from_guests']          = 'Ocultar a usuarios invitados';
$string['category_list']             = 'IDs de categorías';
$string['category_list_help']        = 'Ingresá los IDs de categorías separados por comas. Todos los cursos de esas categorías quedan incluidos. Ejemplo: 2, 5, 8';

// ── WS / security errors ──────────────────────────────────────────────────────
$string['non_select_rejected']    = 'Solo se permiten queries SELECT.';
$string['query_timeout']          = 'La query superó el tiempo límite.';
$string['execute_sql_error']      = 'Error de base de datos al ejecutar la query.';
$string['unauthorized']           = 'No autorizado.';
$string['read_file_blocked']      = 'El acceso a este archivo no está permitido.';
$string['read_file_not_allowed']  = 'Esta ruta no está en la lista de permitidos.';
$string['file_not_found']         = 'Archivo no encontrado o no legible.';
$string['config_key_blocked']     = 'Esta clave de configuración está bloqueada por seguridad.';
$string['path_not_allowed']       = 'Esta ruta no está permitida.';
$string['drop_files_here']            = 'Suelta los archivos aquí';
$string['manage_course_availability'] = 'Gestionar disponibilidad por curso';
$string['course_mode_include']        = 'Mostrar solo en cursos seleccionados';
$string['course_mode_include_desc']   = 'El sidebar aparecerá únicamente en los cursos que elijas abajo.';
$string['course_mode_exclude']        = 'Ocultar en cursos seleccionados';
$string['course_mode_exclude_desc']   = 'El sidebar aparecerá en todos los cursos excepto los que elijas abajo.';
$string['course_mode_all_desc']       = 'El sidebar aparecerá en todos los cursos del sitio.';
$string['course_availability_courses'] = 'Cursos seleccionados';
$string['course_search_placeholder']  = 'Buscar por nombre o nombre corto...';
$string['course_search_hint']         = 'Escribe al menos 2 caracteres para buscar.';
$string['course_none_selected']       = 'Todavía no hay cursos seleccionados.';
$string['course_search_noresults']    = 'No se encontraron cursos.';
$string['course_remove']              = 'Quitar curso';
$string['ai_disclaimer']          = 'Jedomi es una IA y puede cometer errores.';

// ── Integración Telegram ──────────────────────────────────────────────────────
$string['tab_integrations']                = 'Integraciones';
$string['telegram_heading']                = 'Telegram';
$string['telegram_heading_help']           = 'Conectá Jedomi a un bot de Telegram para que los usuarios conversen vía chat.';
$string['telegram_enabled']                = 'Habilitar integración con Telegram';
$string['telegram_bot_token']              = 'Token del bot';
$string['telegram_bot_token_help']         = 'Pegá el token de @BotFather. Se encripta antes de guardarlo.';
$string['telegram_webhook_url']            = 'URL del webhook';
$string['telegram_webhook_url_help']      = 'Copiá esta URL en @BotFather con /setwebhook.';
$string['telegram_webhook_secret']         = 'Secreto del webhook';
$string['telegram_webhook_secret_help']    = 'Secreto aleatorio que se agrega a la URL del webhook por seguridad. Regeneralo si se filtra.';
$string['telegram_connection_status']     = 'Estado de conexión';
$string['telegram_status_connected']      = 'Conectado';
$string['telegram_status_disconnected']    = 'Desconectado';
$string['telegram_status_pending']         = 'Pendiente — pegá la URL del webhook en @BotFather';
$string['telegram_rate_limit']             = 'Límite de mensajes';
$string['telegram_rate_limit_help']        = 'Máximo de mensajes por minuto que un usuario puede enviar. 0 = ilimitado.';
$string['telegram_cooldown']              = 'Tiempo de espera';
$string['telegram_cooldown_help']           = 'Minutos para bloquear a un usuario después de exceder el límite.';
$string['telegram_role_visible']          = 'Permitir Telegram para rol: {role}';
$string['telegram_role_visibility_help']   = 'Desactivá para evitar que usuarios con este rol vinculen o reciban mensajes de Telegram. Los admins siempre tienen acceso.';
$string['telegram_link_title']            = 'Vincular Telegram';
$string['telegram_link_generate']         = 'Generar código';
$string['telegram_link_code_expiry']      = 'Este código vence en 10 minutos.';
$string['telegram_linked_as']              = 'Vinculado como @{username}';
$string['telegram_not_linked']            = 'Sin vincular';
$string['telegram_link_instructions']      = 'Abrí Telegram, buscá tu bot, y enviá /start seguido del código.';
$string['telegram_msg_link_first']       = 'Primero vinculá tu cuenta: andá a tu perfil de Moodle y hacé clic en "Vincular Telegram".';
$string['telegram_msg_rate_limited']     = 'Demasiados mensajes. Esperá {minutes} minutos.';
$string['telegram_msg_role_blocked']     = 'Tu rol no tiene acceso a Jedomi Telegram.';
$string['telegram_msg_confirm']          = 'Confirmar';
$string['telegram_msg_cancel']            = 'Cancelar';
$string['telegram_msg_executing']         = 'Ejecutando...';
$string['telegram_msg_done']              = 'Listo.';
$string['telegram_msg_expired_code']      = 'Código inválido o vencido. Generá uno nuevo desde tu perfil de Moodle.';
$string['telegram_msg_unlinked']          = 'Desvinculado. Enviá /start para volver a vincular.';
$string['unlink']                        = 'Desvincular';
$string['regenerate']                    = 'Regenerar secreto';
$string['cleanup_telegram_sessions']     = 'Jedomi — Limpiar sesiones de Telegram vencidas';
$string['encryption_failed']             = 'Falló la encriptación. Verificá la configuración de OpenSSL del servidor.';
$string['decryption_failed']             = 'Falló la desencriptación. El token puede estar corrompido o el salt del sitio cambió.';

// ── Guía de configuración de Telegram ────────────────────────────────────────
$string['copytoclipboard']               = 'Copiar al portapapeles';
$string['telegram_setup_guide']          = 'Guía de configuración';
$string['telegram_setup_guide_help']     = 'Seguí estos pasos para conectar Jedomi con Telegram. Una vez configurado, los usuarios pueden vincular sus cuentas desde su perfil.';
$string['telegram_setup_done']           = 'Integración configurada';
$string['telegram_setup_done_help']      = 'Telegram está activo. Los usuarios con roles permitidos pueden vincular sus cuentas desde su perfil de Moodle.';
$string['telegram_setup_show']           = 'Ver guía de configuración';
$string['telegram_setup_hide']           = 'Ocultar guía';

$string['telegram_step_admin_title']     = 'Configuración del Administrador';
$string['telegram_step_admin_help']      = 'Se hace una sola vez. Requiere acceso a Telegram.';
$string['telegram_step1_title']          = 'Crear un bot en @BotFather';
$string['telegram_step1_desc']           = 'Abrí Telegram, buscá <strong>@BotFather</strong>, enviá <code>/newbot</code>, elegí nombre y usuario. Copiá el token que te da (formato: <code>123456:ABC-DEF...</code>).';
$string['telegram_step2_title']          = 'Pegar el token y guardar';
$string['telegram_step2_desc']           = 'Pegá el token en el campo <strong>Token del bot</strong> abajo, activá el toggle de integración y hacé clic en <strong>Guardar cambios</strong>.';
$string['telegram_step3_title']          = 'Registrar el webhook en Telegram';
$string['telegram_step3_desc']           = 'Copiá la <strong>URL del webhook</strong> que aparece después de guardar. Luego andá a @BotFather y ejecutá: <code>/setwebhook</code>. O usá este comando curl:';
$string['telegram_step3_note']           = 'Para desarrollo local (DDEV), primero usá ngrok para obtener una URL pública HTTPS: <code>ngrok http https://moodle2.ddev.site --host-header=moodle2.ddev.site</code>';
$string['telegram_step4_title']          = 'Configurar acceso por rol (opcional)';
$string['telegram_step4_desc']           = 'Usá los toggles de roles abajo para controlar qué roles de Moodle pueden usar Telegram. Los admins siempre tienen acceso independientemente de esta configuración.';

$string['telegram_step_user_title']      = 'Configuración del Usuario (cada usuario lo hace una vez)';
$string['telegram_step_user_help']       = 'Una vez que el admin configuró el bot, cada usuario vincula su cuenta de Telegram.';
$string['telegram_stepU1_title']         = 'Ir al perfil de Moodle';
$string['telegram_stepU1_desc']          = 'Hacé clic en tu nombre en la barra superior → <strong>Perfil</strong>. Vas a ver una sección <strong>Jedomi AI</strong> con el link <em>Vincular Telegram</em>.';
$string['telegram_stepU2_title']         = 'Generar un código de vinculación';
$string['telegram_stepU2_desc']          = 'Hacé clic en <strong>Generar código</strong>. Aparece un código de 6 caracteres, válido por 10 minutos.';
$string['telegram_stepU3_title']         = 'Enviar /start al bot';
$string['telegram_stepU3_desc']          = 'En Telegram, abrí el bot de tu institución y enviá: <code>/start TU_CODIGO</code>. El bot confirma el vínculo al instante.';
$string['telegram_stepU4_title']         = 'Empezar a chatear';
$string['telegram_stepU4_desc']          = 'Enviá cualquier mensaje al bot. Jedomi responde con datos reales de tu Moodle. Para desvincular, enviá <code>/desvincular</code> o usá tu página de perfil.';

// ── WS Discovery / Execution ────────────────────────────────────────────────
$string['tab_apis']                = 'APIs';
$string['tab_apis_heading']        = 'Listas de servicios web permitidos';
$string['tab_apis_heading_help']   = 'Configurá qué servicios web de Moodle puede usar cada rol a través de Jedomi.';
$string['api_role_admin']          = 'Admin';
$string['api_role_manager']        = 'Manager';
$string['api_role_teacher']        = 'Teacher';
$string['api_role_student']        = 'Student';
$string['filter_all']              = 'Todos';
$string['filter_read']             = 'Solo lectura';
$string['filter_write']            = 'Solo escritura';
$string['filter_type']             = 'Tipo:';
$string['component_all']           = 'Todos los componentes';
$string['ws_name']                 = 'Nombre';
$string['ws_description']          = 'Descripción';
$string['ws_component']            = 'Componente';
$string['ws_type']                 = 'Tipo';
$string['ws_type_read']            = 'Lectura';
$string['ws_type_write']           = 'Escritura';
$string['ws_type_all']             = 'Todos los tipos';
$string['search_ws_placeholder']   = 'Buscar servicios web...';
$string['ws_not_in_allowlist']     = 'Este servicio web no está en la lista permitida de tu rol.';
$string['ws_not_found']            = 'Función de servicio web no encontrada.';
$string['ws_execute_error']        = 'Error al ejecutar el servicio web: {$a}';
$string['ws_no_services']          = 'No hay servicios web configurados para este rol.';
$string['ws_pagination']           = 'Página {$a->page} de {$a->pages} ({$a->total} servicios)';
$string['ws_none_found']           = 'Ningún servicio web coincide con tus filtros.';
$string['tool_ws_search_desc']     = 'Buscar funciones de servicios web por intención o palabras clave y devolver las más relevantes con descripciones completas de parámetros y tipos de retorno.';
$string['tool_ws_describe_desc']   = 'Obtener documentación completa (descripción, parámetros, tipo de retorno) de una función de servicio web específica por su nombre exacto.';
$string['tool_ws_execute_desc']    = 'Ejecutá un servicio web de Moodle en nombre del usuario actual. Usalo DESPUÉS de ws_search para obtener el nombre exacto de la función y los parámetros. Para operaciones batch (misma acción en múltiples items), llamá esta función VARIAS VECES — una por cada item.';

// ── Tool execution errors ────────────────────────────────────────────
$string['error_tool_loop_detected'] = 'Llamada a herramienta repetida bloqueada. Usá otra herramienta o ejecutá con nuevos parámetros.';
$string['error_fallback_iterations'] = 'Lo siento, no pude completar la operación después de varios intentos.';
$string['error_unknown_tool'] = 'Herramienta desconocida: {$a}';
$string['error_permission_denied'] = 'permission_denied';
$string['error_query_required'] = 'query is required';
$string['error_search_tables_failed'] = 'search_tables failed: {$a}';
$string['error_ws_search_failed'] = 'ws_search failed: {$a}';
$string['error_ws_describe_failed'] = 'ws_describe failed: {$a}';
$string['error_ws_execute_failed'] = 'ws_execute failed: {$a}';
$string['error_wsname_required'] = 'wsname is required';
$string['error_permission_denied_ws'] = 'permission_denied: function not in allowlist';
$string['error_tool_failed'] = 'tool_failed';

// ── Tool hints ───────────────────────────────────────────────────────
$string['hint_sql_braces'] = ' | PISTA: En SQL de Moodle, los nombres de tablas deben usar llaves (ej: {course}, {user}).';

// ── Tool result notes ────────────────────────────────────────────────
$string['note_tables_not_found'] = 'No se encontraron tablas. Probá con otras palabras clave.';
$string['note_tables_found'] = 'Usá el nombre exacto con describe_table o execute_sql. Los nombres de tabla se muestran como están — usá {tablename} en las consultas SQL.';
$string['note_ws_none_configured'] = 'No hay servicios web configurados para tu rol';
$string['note_ws_not_found'] = 'No se encontraron funciones. Probá con otras palabras clave.';
$string['note_ws_found'] = 'Usá el campo "name" exacto con ws_execute. Revisá "params" para los parámetros requeridos(*).';

// ── SSE tool result summaries ────────────────────────────────────────
$string['summary_invalid_json'] = 'error: respuesta JSON inválida';
$string['summary_error_prefix'] = 'error: ';
$string['summary_rows_returned'] = ' filas devueltas';
$string['summary_table_structure'] = 'estructura de tabla recuperada';
$string['summary_matching_tables'] = ' tablas coincidentes';
$string['summary_config_data'] = 'datos de configuración recuperados';
$string['summary_functions_found'] = ' funciones encontradas';
$string['summary_function_details'] = 'detalles de función recuperados';
$string['summary_ws_executed'] = 'servicio web ejecutado correctamente';
$string['summary_done'] = 'listo';

// ── Context labels (system prompt) ───────────────────────────────────
$string['ctx_loc_course'] = 'Viendo actualmente un curso';
$string['ctx_loc_course_id'] = ' (ID: {$a}).';
$string['ctx_loc_activity'] = 'Actualmente en una actividad de tipo';
$string['ctx_loc_activity_suffix'] = '".';
$string['ctx_loc_gradebook'] = 'Viendo actualmente el libro de calificaciones.';
$string['ctx_loc_admin'] = 'Actualmente en el panel de administración de Moodle.';
$string['ctx_loc_dashboard'] = 'Actualmente en el dashboard de Moodle.';
$string['ctx_loc_messages'] = 'Actualmente en la sección de mensajes.';
$string['ctx_loc_profile'] = 'Viendo actualmente un perfil de usuario.';
$string['ctx_loc_calendar'] = 'Viendo actualmente el calendario.';
$string['ctx_loc_files'] = 'Actualmente en el área de archivos.';
$string['dblabel_mysql'] = 'MySQL/MariaDB';
$string['dblabel_pgsql'] = 'PostgreSQL';

// ── Tool descriptions (lib.php) ──────────────────────────────────────
$string['tool_describe_table_desc'] = 'Obtiene la estructura (columnas, tipos, claves, índices, foreign keys) de una tabla de la base de datos de Moodle. Cacheado 24h.';
$string['tool_describe_table_tablename'] = 'Nombre de la tabla (ej: course, user, grade_grades)';
$string['tool_search_tables_desc'] = 'Busca tablas de Moodle por intención o palabras clave en INGLÉS. Busca en nombres de tablas Y sus descripciones COMMENT de install.xml. Devuelve los resultados más relevantes con nombre, descripción, nombres de campos y comentarios de campos. Usá SIEMPRE search_tables antes de describe_table o execute_sql.';
$string['tool_search_tables_query'] = 'Intención o palabras clave en INGLÉS (ej: "user grades", "course enrolment", "student")';
$string['tool_search_tables_limit'] = 'Máximo de resultados (default: 8, max: 15)';
$string['tool_execute_sql_desc'] = 'Ejecuta una consulta SELECT en la base de datos de Moodle y devuelve las filas como JSON. SOLO lectura. Límite: 100 filas, timeout 5 segundos.';
$string['tool_execute_sql_sql'] = 'Consulta SELECT a ejecutar. Usá ? como placeholder para parámetros. Ejemplo: SELECT id, fullname FROM {course} WHERE category = ?';
$string['tool_execute_sql_params'] = 'Parámetros vinculados para la consulta (opcional)';
$string['tool_get_site_config_desc'] = 'Obtiene configuración del sitio Moodle (sitename, lang, timezone, etc.). Solo disponible para administradores.';
$string['tool_get_site_config_keys'] = 'Lista de claves de configuración. Válidas: sitename, siteurl, lang, country, timezone, maxbytes, passwordpolicy, enrol_plugins_enabled, auth_plugins_enabled';
$string['tool_ws_search_desc'] = 'Busca funciones de servicios web de Moodle por intención o palabras clave en INGLÉS. Devuelve las más relevantes con descripción completa, parámetros (* = requerido) y estructura de retorno. Si los resultados incluyen lo que necesitás: DEJÁ de buscar — llamá ws_describe con el nombre exacto, luego ws_execute. No llamés ws_search de nuevo para la misma tarea.';
$string['tool_ws_search_query'] = 'Intención o palabras clave en INGLÉS (ej: "create user", "enrol student", "get course grades")';
$string['tool_ws_search_limit'] = 'Máximo de resultados a devolver (default: 6, max: 15)';
$string['tool_ws_describe_desc'] = 'Obtené documentación completa (descripción, parámetros con * = requerido, tipo de retorno) para una función de servicio web específica cuando ya sabés su nombre exacto. LLAMÁ esto SIEMPRE DESPUÉS de ws_search y ANTES de ws_execute para verificar los campos requeridos.';
$string['tool_ws_describe_wsname'] = 'Nombre exacto de la función (ej: core_user_create_users)';
$string['tool_ws_execute_wsname'] = 'Nombre de la función del servicio web (ej: core_course_get_courses)';
$string['tool_ws_execute_params'] = 'Parámetros codificados en JSON para la función del servicio web. DEBE incluir todos los campos * (requerido). Si el usuario no proporcionó un valor, generá un valor predeterminado sensato (ej: contraseña aleatoria, timestamp actual, array vacío).';

// ── External WS errors ───────────────────────────────────────────────
$string['ws_function_not_found'] = 'Función no encontrada: {$a}';
$string['ws_cannot_load_info'] = 'No se puede cargar información de la función: {$a}';

// ── Table descriptor errors ──────────────────────────────────────────
$string['table_not_found'] = 'Tabla no encontrada: {$a}';

// ── Set site config ──────────────────────────────────────────────────
$string['config_key_blocked_msg'] = 'Esta clave de configuración está bloqueada por seguridad.';
$string['config_updated_msg'] = 'Config \'{$a}\' actualizada correctamente.';

// ── Run task ─────────────────────────────────────────────────────────
$string['task_not_found_msg'] = 'task_not_found';
$string['task_instantiation_failed_msg'] = 'task_instantiation_failed';

// ── WS execute errors ────────────────────────────────────────────────
$string['ws_method_not_exists'] = 'El método {$a} no existe';
$string['ws_debug_prefix'] = ' | debug: ';

// ── Document reader ─────────────────────────────────────────────────
$string['tool_document_read_desc'] = 'Leer un archivo PDF de CUALQUIER actividad del curso usando su cmid (course module ID). Convierte páginas PDF a imágenes base64 para ver el diseño ORIGINAL exacto. El cmid está disponible en el contexto cuando se ve una página de actividad.';
$string['tool_document_read_cmid'] = 'Course module ID (cmid) de la actividad. Obtenelo del contexto de página o descubrilo via ws_search/ws_execute.';
$string['tool_document_read_dpi'] = 'Resolución de imagen (default: 150). Valores más altos = mejor calidad pero más tokens.';
$string['tool_document_read_fmt'] = 'Formato de imagen: JPEG (más chico) o PNG (sin pérdida). Default: JPEG.';
$string['docservice_url'] = 'URL del servicio de documento a imagen';
$string['docservice_url_help'] = 'URL del servicio que convierte PDFs a imágenes base64 (ej. http://localhost:8000).';
