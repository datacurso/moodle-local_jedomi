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
 * Spanish language strings for local_jedomi.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['action_copy'] = 'Copiar';
$string['action_edit'] = 'Editar';
$string['action_edit_title'] = 'Editar mensaje';
$string['action_helpful'] = 'Útil';
$string['action_improve'] = 'Mejorar';
$string['action_improve_title'] = 'Mejorable';
$string['agent_actions'] = 'Acciones';
$string['agent_actions_menu'] = 'Acciones del agente';
$string['agent_apply_filters'] = 'Aplicar Filtros';
$string['agent_by_role'] = 'Agentes por Rol';
$string['agent_by_status'] = 'Agentes por Estado';
$string['agent_context'] = 'Contexto';
$string['agent_context_type_category'] = 'Categoría';
$string['agent_context_type_course'] = 'Curso';
$string['agent_context_type_site'] = 'Sitio';
$string['agent_context_type_user'] = 'Usuario';
$string['agent_create'] = 'Crear Agente';
$string['agent_create_success'] = 'Agente creado correctamente.';
$string['agent_created_no_name'] = 'Agente creado sin nombre. Editá el agente para agregarle un nombre.';
$string['agent_created_with_name'] = 'Agente &quot;{name}&quot; creado correctamente.';
$string['agent_creator'] = 'Creador';
$string['agent_custom_cron'] = 'Personalizado (expresión cron)';
$string['agent_daily'] = 'Diario';
$string['agent_delete'] = 'Eliminar';
$string['agent_delete_confirm'] = '¿Eliminar este agente? Esta acción no se puede deshacer.';
$string['agent_disable'] = 'Desactivar';
$string['agent_disabled'] = 'Desactivado';
$string['agent_edit'] = 'Editar';
$string['agent_enable'] = 'Activar';
$string['agent_enabled'] = 'Activo';
$string['agent_error_create'] = 'Error al crear agente: {$a}';
$string['agent_error_delete'] = 'Error al eliminar agente: {$a}';
$string['agent_error_load'] = 'Error al cargar agentes.';
$string['agent_error_update'] = 'Error al actualizar agente: {$a}';
$string['agent_every_15min'] = 'Cada 15 minutos';
$string['agent_every_1h'] = 'Cada hora';
$string['agent_every_30min'] = 'Cada 30 minutos';
$string['agent_every_5min'] = 'Cada 5 minutos';
$string['agent_every_6h'] = 'Cada 6 horas';
$string['agent_executions_30d'] = 'Ejecuciones (30 días)';
$string['agent_failing'] = 'Fallando';
$string['agent_filter_apply'] = 'Aplicar';
$string['agent_filter_context'] = 'Contexto';
$string['agent_filter_reset'] = 'Reiniciar';
$string['agent_filter_role'] = 'Rol';
$string['agent_filter_status'] = 'Estado';
$string['agent_filters'] = 'Filtros';
$string['agent_heading'] = 'Agentes de IA Programados';
$string['agent_heading_help'] = 'Configurá agentes de IA que se ejecutan automáticamente y envían resultados por Telegram.';
$string['agent_lastrun'] = 'Última ejecución';
$string['agent_name'] = 'Nombre';
$string['agent_name_placeholder'] = 'ej. Reporte Diario';
$string['agent_nextrun'] = 'Próxima ejecución';
$string['agent_no_results'] = 'No se encontraron agentes con esos filtros.';
$string['agent_not_found'] = 'Agente no encontrado.';
$string['agent_preview'] = 'Vista previa';
$string['agent_preview_error'] = 'La vista previa falló. Revisa el prompt e inténtalo de nuevo.';
$string['agent_preview_result'] = 'Resultado de la vista previa';
$string['agent_prompt'] = 'Prompt';
$string['agent_prompt_placeholder'] = 'Ej: "Resumí la actividad nueva del sitio y notificame"';
$string['agent_purge'] = 'Eliminar Agentes';
$string['agent_purge_btn'] = 'Eliminar';
$string['agent_purge_confirm'] = 'Esto va a eliminar todos los agentes que coincidan y sus resultados cacheados. ¿Continuar?';
$string['agent_purge_context'] = 'Tipo de contexto';
$string['agent_purge_context_help'] = 'Eliminar todos los agentes de un contexto específico.';
$string['agent_purge_success'] = '{$a} agente(s) eliminados correctamente.';
$string['agent_run_now'] = 'Ejecutar ahora';
$string['agent_run_queued'] = 'Agente encolado para ejecución (próximo ciclo de cron).';
$string['agent_runner_task'] = 'Jedomi — Ejecutar agentes de IA programados';
$string['agent_schedule'] = 'Programación';
$string['agent_schedule_presets'] = 'Programación predefinida';
$string['agent_search'] = 'Buscar prompt...';
$string['agent_status'] = 'Estado';
$string['agent_status_all'] = 'Todos';
$string['agent_summary'] = 'Resumen';
$string['agent_table'] = 'Todos los Agentes';
$string['agent_unnamed'] = 'Agente';
$string['agent_update_success'] = 'Agente actualizado correctamente.';
$string['agent_view_details'] = 'Ver detalles';
$string['agent_weekly'] = 'Semanal';
$string['agent_ws_allowlist'] = 'Lista permitida de WS para agentes';
$string['agent_ws_allowlist_help'] = 'Lista separada por comas de funciones de webservice de Moodle que los agentes pueden '
    . 'llamar. Dejá vacío para usar el conjunto predeterminado.';
$string['ai_disclaimer'] = 'Jedomi es una IA y puede cometer errores.';
$string['ai_error_connection'] = 'No se pudo conectar con Jedomi. Revisa tu conexión e inténtalo de nuevo.';
$string['ai_error_generic'] = 'Jedomi no pudo responder esta vez. Inténtalo de nuevo en un momento.';
$string['ai_error_invalidanswer'] = 'El servicio de IA devolvió una respuesta que no se pudo leer.';
$string['ai_error_license'] = 'La licencia de IA de este sitio falta o no es válida. Ponte en contacto con el administrador del sitio.';
$string['ai_error_nocredit'] = 'Tu organización no tiene saldo de IA. Ponte en contacto con el administrador del sitio para recargarlo.';
$string['ai_error_ratelimit'] = 'Has alcanzado el límite de uso de IA por ahora. Puedes volver a intentarlo después de {$a}.';
$string['ai_error_ratelimit_noretry'] = 'Has alcanzado el límite de uso de IA por ahora. Inténtalo de nuevo más tarde.';
$string['ai_error_timeout'] = 'El asistente tardó demasiado en responder. Inténtalo de nuevo con una solicitud más breve.';
$string['ai_error_unavailable'] = 'El servicio de IA no está disponible en este momento. Inténtalo de nuevo en unos minutos.';
$string['ai_heading'] = 'Servicio de IA';
$string['ai_heading_help'] = 'Jedomi envía sus solicitudes de IA a través del proveedor de IA de Datacurso, con su clave de licencia y sus límites de uso.';
$string['analyze_image'] = 'Analizá esta imagen.';
$string['anon_basic'] = 'Básico (nombres y emails)';
$string['anon_basic_desc'] = 'Reemplaza nombres y correos. Conserva la mayor parte del contexto.';
$string['anon_feedback_only'] = 'Solo feedback (no se guarda el texto de conversaciones)';
$string['anon_feedback_only_desc'] = 'Solo guarda calificaciones y botones de feedback. Sin texto de conversación.';
$string['anon_strict'] = 'Estricto (nombres, emails, IDs, texto libre)';
$string['anon_strict_desc'] = 'Reemplaza nombres, correos, IDs y texto libre. Máxima privacidad.';
$string['anonymization_level'] = 'Nivel de anonimización';
$string['anonymization_level_help'] = 'Qué tan agresivamente anonimizar las conversaciones guardadas.';
$string['api_role_admin'] = 'Admin';
$string['api_role_manager'] = 'Manager';
$string['api_role_student'] = 'Student';
$string['api_role_teacher'] = 'Teacher';
$string['attach'] = 'Adjuntar';
$string['attach_file'] = 'Adjuntar PDF/CSV';
$string['attach_image'] = 'Adjuntar imagen';
$string['attached_file_info'] = '[Archivo adjunto: {$a}]';
$string['attached_image'] = 'Imagen adjunta';
$string['cancel'] = 'Cancelar';
$string['cancelled'] = 'Cancelado.';
$string['category_list'] = 'IDs de categorías';
$string['category_list_help'] = 'Ingresá los IDs de categorías separados por comas. Todos los cursos de esas categorías quedan '
    . 'incluidos. Ejemplo: 2, 5, 8';
$string['chat_placeholder'] = 'Preguntá a Jedomi...';
$string['check_webhook'] = 'Verificar webhook';
$string['cleanup_telegram_sessions'] = 'Jedomi — Limpiar sesiones de Telegram vencidas';
$string['close'] = 'Cerrar (Esc)';
$string['collect_training_data'] = 'Recopilar conversaciones anonimizadas para entrenamiento';
$string['collect_training_data_help'] = 'Cuando está activo, las conversaciones se guardan de forma anónima para mejorar '
    . 'versiones futuras. No se retienen datos personales.';
$string['component_all'] = 'Todos los componentes';
$string['config_key_blocked'] = 'Esta clave de configuración está bloqueada por seguridad.';
$string['config_key_blocked_msg'] = 'Esta clave de configuración está bloqueada por seguridad.';
$string['config_updated_msg'] = 'Config \'{$a}\' actualizada correctamente.';
$string['confirm_btn_no'] = 'Cancelar';
$string['confirm_btn_yes'] = 'Confirmar';
$string['confirm_cancelled'] = 'Acción cancelada.';
$string['confirm_done'] = 'Acción ejecutada correctamente.';
$string['confirm_executing'] = 'Ejecutando...';
$string['confirm_send_bulk'] = '¿Enviar este mensaje a {count} alumno(s)?';
$string['confirm_type_to_proceed'] = 'Escribí CONFIRMAR para proceder:';
$string['connection_error'] = 'Error de conexión:';
$string['copytoclipboard'] = 'Copiar al portapapeles';
$string['course_availability_courses'] = 'Cursos seleccionados';
$string['course_availability_heading'] = 'Disponibilidad por curso';
$string['course_availability_heading_help'] = 'Controlá en qué cursos aparece el sidebar de Jedomi.';
$string['course_list'] = 'IDs de cursos';
$string['course_list_help'] = 'Ingresá los IDs de cursos separados por comas. Ejemplo: 5, 10, 23. Dejá vacío para sin '
    . 'restricción.';
$string['course_mode'] = 'Modo de disponibilidad';
$string['course_mode_all'] = 'Todos los cursos (por defecto)';
$string['course_mode_all_desc'] = 'El sidebar aparecerá en todos los cursos del sitio.';
$string['course_mode_blacklist'] = 'Todos los cursos excepto los seleccionados (lista negra)';
$string['course_mode_exclude'] = 'Ocultar en cursos seleccionados';
$string['course_mode_exclude_desc'] = 'El sidebar aparecerá en todos los cursos excepto los que elijas abajo.';
$string['course_mode_help'] = 'Seleccioná cómo controlar la disponibilidad por curso.';
$string['course_mode_include'] = 'Mostrar solo en cursos seleccionados';
$string['course_mode_include_desc'] = 'El sidebar aparecerá únicamente en los cursos que elijas abajo.';
$string['course_mode_whitelist'] = 'Solo los cursos seleccionados (lista blanca)';
$string['course_none_selected'] = 'Todavía no hay cursos seleccionados.';
$string['course_remove'] = 'Quitar curso';
$string['course_search_hint'] = 'Escribe al menos 2 caracteres para buscar.';
$string['course_search_noresults'] = 'No se encontraron cursos.';
$string['course_search_placeholder'] = 'Buscar por nombre o nombre corto...';
$string['csv_download'] = '📥 Descargar CSV';
$string['ctx_activity'] = 'Actividad';
$string['ctx_admin'] = 'Administración';
$string['ctx_calendar'] = 'Calendario';
$string['ctx_course'] = 'Curso';
$string['ctx_dashboard'] = 'Dashboard';
$string['ctx_files'] = 'Archivos';
$string['ctx_grades'] = 'Calificaciones';
$string['ctx_loc_activity'] = 'Actualmente en una actividad de tipo';
$string['ctx_loc_activity_suffix'] = '".';
$string['ctx_loc_admin'] = 'Actualmente en el panel de administración de Moodle.';
$string['ctx_loc_calendar'] = 'Viendo actualmente el calendario.';
$string['ctx_loc_course'] = 'Viendo actualmente un curso';
$string['ctx_loc_course_id'] = ' (ID: {$a}).';
$string['ctx_loc_dashboard'] = 'Actualmente en el dashboard de Moodle.';
$string['ctx_loc_files'] = 'Actualmente en el área de archivos.';
$string['ctx_loc_gradebook'] = 'Viendo actualmente el libro de calificaciones.';
$string['ctx_loc_messages'] = 'Actualmente en la sección de mensajes.';
$string['ctx_loc_profile'] = 'Viendo actualmente un perfil de usuario.';
$string['ctx_messages'] = 'Mensajes';
$string['ctx_profile'] = 'Perfil';
$string['dblabel_mysql'] = 'MySQL/MariaDB';
$string['dblabel_pgsql'] = 'PostgreSQL';
$string['decryption_failed'] = 'Falló la desencriptación. El token puede estar corrompido o el salt del sitio cambió.';
$string['docservice_url'] = 'URL del servicio de documento a imagen';
$string['docservice_url_help'] = 'URL del servicio que convierte PDFs a imágenes base64 (ej. http://localhost:8000).';
$string['drop_files_here'] = 'Suelta los archivos aquí';
$string['drop_pending_fail'] = '❌ Error al eliminar pendientes — revisá la respuesta de la API de Telegram.';
$string['drop_pending_success'] = '✅ Pendientes eliminados correctamente.';
$string['drop_pending_updates'] = 'Eliminar pendientes';
$string['drop_pending_updates_help'] = 'Limpia la cola de actualizaciones de Telegram para que mensajes viejos no se procesen al '
    . 'reconectar el webhook. Útil durante pruebas.';
$string['enabled'] = 'Activar sidebar de Jedomi';
$string['enabled_help'] = 'Muestra u oculta el sidebar de Jedomi en todo el sitio.';
$string['encryption_failed'] = 'Falló la encriptación. Verificá la configuración de OpenSSL del servidor.';
$string['error_fallback_iterations'] = 'Lo siento, no pude completar la operación después de varios intentos.';
$string['error_permission_denied'] = 'permission_denied';
$string['error_permission_denied_ws'] = 'permission_denied: function not in allowlist';
$string['error_query_required'] = 'query is required';
$string['error_search_tables_failed'] = 'search_tables failed: {$a}';
$string['error_telegram_e001'] = '❌ Chatbot no configurado (E001). Contactá al administrador.';
$string['error_telegram_e002'] = '❌ Error al enviar mensaje a Telegram (E002). Intentá de nuevo.';
$string['error_telegram_e003'] = '❌ Respuesta inesperada de Telegram (E003). Intentá de nuevo.';
$string['error_telegram_e004'] = '❌ Error de conexión con Jedomi (E004). Intentá de nuevo más tarde.';
$string['error_telegram_e005'] = '❌ El servicio Jedomi devolvió un error (E005). Intentá de nuevo.';
$string['error_telegram_e006'] = '❌ Respuesta inválida del servicio Jedomi (E006). Intentá de nuevo.';
$string['error_telegram_e007'] = '❌ Secreto de webhook inválido (E007). Contactá al administrador.';
$string['error_telegram_e008'] = '❌ No tenés permisos para consultar datos del curso ID {$a}. Solo podés consultar cursos '
    . 'donde tengas permisos de edición.';
$string['error_tool_failed'] = 'tool_failed';
$string['error_tool_loop_detected'] = 'Llamada a herramienta repetida bloqueada. Usá otra herramienta o ejecutá con nuevos '
    . 'parámetros.';
$string['error_unknown_tool'] = 'Herramienta desconocida: {$a}';
$string['error_ws_describe_failed'] = 'ws_describe failed: {$a}';
$string['error_ws_execute_failed'] = 'ws_execute failed: {$a}';
$string['error_ws_search_failed'] = 'ws_search failed: {$a}';
$string['error_wsname_required'] = 'wsname is required';
$string['execute_sql_error'] = 'Error de base de datos al ejecutar la query.';
$string['export_conversation'] = 'Exportar conversación';
$string['file'] = 'Archivo';
$string['file_attach_disabled'] = 'Adjuntar documentos no está disponible por ahora. Puedes adjuntar imágenes o pegar el texto en tu mensaje.';
$string['file_csv'] = 'CSV: {rows} filas, {columns} columnas';
$string['file_loaded'] = 'cargado';
$string['file_loaded_hint'] = 'Podés hacer preguntas sobre su contenido.';
$string['file_not_found'] = 'Archivo no encontrado o no legible.';
$string['file_pdf'] = 'PDF: {pages} páginas';
$string['file_process_error'] = 'No se pudo procesar';
$string['file_text'] = 'Texto: {chars} caracteres';
$string['filter_all'] = 'Todos';
$string['filter_read'] = 'Solo lectura';
$string['filter_type'] = 'Tipo:';
$string['filter_write'] = 'Solo escritura';
$string['full_width'] = 'Pantalla completa';
$string['greeting'] = '¡Hola, {name}!';
$string['half_width'] = 'Media pantalla';
$string['hello'] = 'Soy Jedomi, tu asistente IA de Moodle. Pregúntame sobre cursos, alumnos, notas o configuraciones.';
$string['hide_from_guests'] = 'Ocultar a usuarios invitados';
$string['hide_from_guests_help'] = 'Cuando está activado, los invitados y usuarios no autenticados no verán el sidebar.';
$string['hint_sql_braces'] = ' | PISTA: En SQL de Moodle, los nombres de tablas deben usar llaves (ej: {course}, {user}).';
$string['image'] = 'Imagen';
$string['improvement_heading'] = 'Mejora continua';
$string['improvement_heading_help'] = 'Recolección opcional de datos para mejorar Jedomi con el tiempo.';
$string['listening'] = 'Escuchando...';
$string['manage_course_availability'] = 'Gestionar disponibilidad por curso';
$string['max_tokens'] = 'Tokens máximos';
$string['max_tokens_help'] = 'Máximo de tokens de salida por respuesta (default: 16384). Aumentalo para respuestas más largas, '
    . 'reducilo para velocidad & costo.';
$string['message_input'] = 'Mensaje a Jedomi';
$string['messages_recipients_note'] = 'Los destinatarios los ven en su bandeja de mensajes de Moodle.';
$string['messages_sent'] = '{sent} mensaje(s) enviados como mensajes internos de Moodle.';
$string['messages_sent_fail'] = '({failed} fallaron)';
$string['mic_error'] = 'No se pudo acceder al micrófono. Verificá los permisos del navegador.';
$string['model'] = 'Modelo';
$string['model_current_unlisted'] = '{$a} (valor actual, no está en la lista)';
$string['model_help'] = 'Modelo que usan el chat web, los agentes programados, la vista previa de agentes, Telegram y la CLI. Solo se muestran los modelos con precio registrado.';
$string['new_conversation'] = 'Nueva conversación';
$string['new_conversation_msg'] = 'Nueva conversación iniciada. ¿En qué te ayudo?';
$string['no_messages_sent'] = 'No se enviaron mensajes.';
$string['non_select_rejected'] = 'Solo se permiten queries SELECT.';
$string['note_tables_found'] = 'Usá el nombre exacto con describe_table o execute_sql. Los nombres de tabla se muestran como '
    . 'están — usá {tablename} en las consultas SQL.';
$string['note_tables_not_found'] = 'No se encontraron tablas. Probá con otras palabras clave.';
$string['note_ws_found'] = 'Usá el campo "name" exacto con ws_execute. Revisá "params" para los parámetros requeridos(*).';
$string['note_ws_none_configured'] = 'No hay servicios web configurados para tu rol';
$string['note_ws_not_found'] = 'No se encontraron funciones. Probá con otras palabras clave.';
$string['open_admin_settings'] = 'Abrir configuración de Jedomi';
$string['open_sidebar'] = 'Abrir Jedomi AI';
$string['path_not_allowed'] = 'Esta ruta no está permitida.';
$string['pluginname'] = 'Jedomi AI Sidebar';
$string['privacy:metadata:jedomi_ai_service'] = 'Las conversaciones se envían a través del proveedor de IA de Datacurso a su servicio de IA para generar las respuestas.';
$string['privacy:metadata:jedomi_ai_service:messages'] = 'Los mensajes de la conversación y los resultados de las herramientas usadas para responderlos.';
$string['privacy:metadata:jedomi_ai_service:userid'] = 'Un identificador seudónimo del usuario, generado por el proveedor de IA de Datacurso.';
$string['privacy:metadata:local_jedomi_feedback'] = 'Valoraciones que los usuarios dan a las respuestas de Jedomi.';
$string['privacy:metadata:local_jedomi_feedback:messagetext'] = 'Un fragmento de la respuesta valorada.';
$string['privacy:metadata:local_jedomi_feedback:rating'] = 'Si la respuesta se valoró como útil o como mejorable.';
$string['privacy:metadata:local_jedomi_feedback:sessionid'] = 'La sesión de chat de la respuesta valorada.';
$string['privacy:metadata:local_jedomi_feedback:timecreated'] = 'Cuándo se dio la valoración.';
$string['privacy:metadata:local_jedomi_feedback:userid'] = 'El usuario que valoró la respuesta.';
$string['privacy_access_heading'] = 'Control de acceso';
$string['privacy_access_heading_help'] = 'Quién puede ver y usar el sidebar de Jedomi.';
$string['privacy_anon_heading'] = 'Anonimización de datos';
$string['privacy_anon_heading_help'] = 'Controla cómo se anonimiza la información de las conversaciones antes de almacenarla.';
$string['privacy_feedback'] = 'Valoraciones de respuestas';
$string['processing_file'] = 'Procesando {$a}...';
$string['qa_admin_active_users_label'] = 'Usuarios activos';
$string['qa_admin_active_users_msg'] = '¿Cuántos usuarios activos hay en el sistema?';
$string['qa_admin_alerts_label'] = 'Alertas';
$string['qa_admin_alerts_msg'] = '¿Qué necesita atención inmediata hoy?';
$string['qa_admin_at_risk_label'] = 'En riesgo';
$string['qa_admin_at_risk_msg'] = '¿Qué estudiantes están en riesgo de perder el curso {$a}?';
$string['qa_admin_empty_cohorts_label'] = 'Cohortes vacías';
$string['qa_admin_empty_cohorts_msg'] = '¿Hay cohortes sin miembros asignados?';
$string['qa_admin_external_emails_label'] = 'Emails externos';
$string['qa_admin_external_emails_msg'] = '¿Hay usuarios con correos no institucionales?';
$string['qa_admin_gdpr_pending_label'] = 'GDPR pendiente';
$string['qa_admin_gdpr_pending_msg'] = '¿Hay solicitudes de eliminación GDPR pendientes?';
$string['qa_admin_inactive_label'] = 'Inactivos';
$string['qa_admin_inactive_msg'] = '¿Qué estudiantes no accedieron al curso {$a} en los últimos 30 días?';
$string['qa_admin_missing_work_label'] = 'Tareas pendientes';
$string['qa_admin_missing_work_msg'] = '¿Qué estudiantes no entregaron actividades en el curso {$a}?';
$string['qa_admin_overview_label'] = 'Resumen';
$string['qa_admin_overview_msg'] = 'Dame un resumen del estado general de la plataforma';
$string['qa_admin_performance_label'] = 'Rendimiento';
$string['qa_admin_performance_msg'] = 'Mostrame el ranking de notas del curso {$a}';
$string['qa_admin_security_label'] = 'Seguridad';
$string['qa_admin_security_msg'] = '¿Hay usuarios con caracteres sospechosos en sus nombres?';
$string['qa_admin_storage_label'] = 'Almacenamiento';
$string['qa_admin_storage_msg'] = '¿Qué curso tiene los archivos más pesados?';
$string['qa_admin_top_courses_label'] = 'Mejores cursos';
$string['qa_admin_top_courses_msg'] = '¿Qué cursos tienen mejor rendimiento?';
$string['qa_admin_top_student_label'] = 'Mejor estudiante';
$string['qa_admin_top_student_msg'] = '¿Quién es el mejor estudiante en el curso {$a}?';
$string['qa_manager_at_risk_label'] = 'En riesgo';
$string['qa_manager_at_risk_msg'] = '¿Qué estudiantes están en riesgo en el curso {$a}?';
$string['qa_manager_enrolled_label'] = 'Inscriptos';
$string['qa_manager_enrolled_msg'] = '¿Cuántos estudiantes hay inscriptos en el curso {$a}?';
$string['qa_manager_global_risk_label'] = 'Riesgo global';
$string['qa_manager_global_risk_msg'] = '¿Hay estudiantes en riesgo de perder algún curso?';
$string['qa_manager_inactive_label'] = 'Inactivos';
$string['qa_manager_inactive_msg'] = '¿Qué estudiantes no accedieron al curso {$a} en los últimos 14 días?';
$string['qa_manager_inactive_users_label'] = 'Usuarios inactivos';
$string['qa_manager_inactive_users_msg'] = '¿Qué usuarios no accedieron al sistema en los últimos 30 días?';
$string['qa_manager_no_teacher_label'] = 'Sin profesor';
$string['qa_manager_no_teacher_msg'] = '¿Hay cursos sin profesor asignado?';
$string['qa_manager_overview_label'] = 'Resumen';
$string['qa_manager_overview_msg'] = 'Dame un resumen del curso {$a}';
$string['qa_manager_top_courses_label'] = 'Mejores cursos';
$string['qa_manager_top_courses_msg'] = '¿Qué cursos tienen más actividad esta semana?';
$string['qa_student_help_label'] = 'Ayuda';
$string['qa_student_help_msg'] = 'No entiendo el contenido del curso {$a}. ¿Podés explicármelo?';
$string['qa_student_my_courses_label'] = 'Mis cursos';
$string['qa_student_my_courses_msg'] = '¿En qué cursos estoy inscripto actualmente?';
$string['qa_student_my_grade_label'] = 'Mi nota';
$string['qa_student_my_grade_msg'] = '¿Cuál es mi promedio en el curso {$a}?';
$string['qa_student_my_grades_label'] = 'Mis notas';
$string['qa_student_my_grades_msg'] = '¿Cuál es mi promedio general en todos mis cursos?';
$string['qa_student_my_progress_label'] = 'Mi progreso';
$string['qa_student_my_progress_msg'] = '¿Cuántas actividades completé en el curso {$a}?';
$string['qa_student_my_tasks_label'] = 'Mis tareas';
$string['qa_student_my_tasks_msg'] = '¿Qué tareas tengo pendientes en el curso {$a}?';
$string['qa_student_this_week_label'] = 'Esta semana';
$string['qa_student_this_week_msg'] = '¿Qué actividades y fechas límite tengo próximamente?';
$string['qa_teacher_at_risk_label'] = 'En riesgo';
$string['qa_teacher_at_risk_msg'] = '¿Qué estudiantes están en riesgo en el curso {$a}?';
$string['qa_teacher_missing_work_label'] = 'Tareas pendientes';
$string['qa_teacher_missing_work_msg'] = '¿Quién no entregó la última tarea en el curso {$a}?';
$string['qa_teacher_my_alerts_label'] = 'Mis alertas';
$string['qa_teacher_my_alerts_msg'] = '¿Hay estudiantes en riesgo en alguno de mis cursos?';
$string['qa_teacher_my_courses_label'] = 'Mis cursos';
$string['qa_teacher_my_courses_msg'] = '¿En qué cursos soy profesor actualmente?';
$string['qa_teacher_never_logged_label'] = 'Nunca accedieron';
$string['qa_teacher_never_logged_msg'] = '¿Qué estudiantes nunca accedieron al curso {$a}?';
$string['qa_teacher_notify_group_label'] = 'Notificar grupo';
$string['qa_teacher_notify_group_msg'] = 'Redactá un mensaje para los estudiantes del curso {$a} recordándoles las actividades '
    . 'pendientes';
$string['qa_teacher_performance_label'] = 'Rendimiento';
$string['qa_teacher_performance_msg'] = '¿Cuál es el promedio de notas en el curso {$a}?';
$string['qa_teacher_top_students_label'] = 'Mejores estudiantes';
$string['qa_teacher_top_students_msg'] = '¿Quién tiene más de 90 puntos en el curso {$a}?';
$string['query_profiler'] = 'Perfil de Consultas';
$string['query_profiler_avg'] = 'Duración promedio';
$string['query_profiler_desc'] = 'Registra todas las consultas SQL ejecutadas por agentes, incluyendo tiempo de ejecución y '
    . 'cantidad de filas. Usá el filtro para encontrar consultas lentas.';
$string['query_profiler_empty'] = 'Todavía no hay consultas registradas. Los agentes registrarán consultas aquí cuando usen la '
    . 'herramienta SQL.';
$string['query_profiler_heading'] = 'Perfil de Consultas SQL';
$string['query_profiler_max'] = 'Duración máxima';
$string['query_profiler_recent'] = 'Consultas recientes';
$string['query_profiler_slow'] = 'Consultas lentas (>1s)';
$string['query_profiler_slowest'] = 'Consultas más lentas (promedio)';
$string['query_profiler_sort_duration'] = 'Duración';
$string['query_profiler_sort_query'] = 'Consulta';
$string['query_profiler_sort_time'] = 'Momento';
$string['query_profiler_threshold'] = 'Umbral de consulta lenta (ms)';
$string['query_profiler_total'] = 'Consultas totales';
$string['query_timeout'] = 'La query superó el tiempo límite.';
$string['read_file_blocked'] = 'El acceso a este archivo no está permitido.';
$string['read_file_not_allowed'] = 'Esta ruta no está en la lista de permitidos.';
$string['regenerate'] = 'Regenerar secreto';
$string['role_visibility_help'] = 'Desactivá el toggle para ocultar el sidebar a los usuarios con ese rol. Los administradores '
    . 'del sitio siempre lo ven.';
$string['role_visibility_label'] = 'Mostrar sidebar por rol';
$string['search_ws_placeholder'] = 'Buscar servicios web...';
$string['security_heading'] = 'Seguridad';
$string['security_heading_help'] = 'Secretos usados para firmar los tokens de permisos de la barra lateral de Jedomi.';
$string['send'] = 'Enviar';
$string['sending'] = 'Enviando...';
$string['session_expired_hint'] = 'Tu sesión de Moodle expiró. Recargá la página para continuar.';
$string['session_expired_title'] = 'Sesión expirada.';
$string['settings'] = 'Configuración de Jedomi';
$string['settings_redirect_help'] = 'La configuración de Jedomi se gestiona en una página dedicada con pestañas.';
$string['sidebar_title'] = 'Jedomi AI';
$string['silence_detected'] = 'Silencio...';
$string['spt_secret'] = 'Secreto del token de permisos';
$string['spt_secret_help'] = 'Secreto compartido entre Moodle y el Gateway para firmar los tokens de permisos. Debe coincidir con '
    . 'SPT_SECRET en el .env.';
$string['summary_config_data'] = 'datos de configuración recuperados';
$string['summary_done'] = 'listo';
$string['summary_error_prefix'] = 'error: ';
$string['summary_function_details'] = 'detalles de función recuperados';
$string['summary_functions_found'] = ' funciones encontradas';
$string['summary_invalid_json'] = 'error: respuesta JSON inválida';
$string['summary_matching_tables'] = ' tablas coincidentes';
$string['summary_rows_returned'] = ' filas devueltas';
$string['summary_table_structure'] = 'estructura de tabla recuperada';
$string['summary_ws_executed'] = 'servicio web ejecutado correctamente';
$string['tab_agents'] = 'Agentes';
$string['tab_apis'] = 'APIs';
$string['tab_apis_heading'] = 'Listas de servicios web permitidos';
$string['tab_apis_heading_help'] = 'Configurá qué servicios web de Moodle puede usar cada rol a través de Jedomi.';
$string['tab_general'] = 'General';
$string['tab_integrations'] = 'Integraciones';
$string['tab_privacy'] = 'Privacidad';
$string['tab_profiler'] = 'Perfil de Consultas';
$string['table_not_found'] = 'Tabla no encontrada: {$a}';
$string['task_instantiation_failed_msg'] = 'task_instantiation_failed';
$string['task_not_found_msg'] = 'task_not_found';
$string['telegram_bot_token'] = 'Token del bot';
$string['telegram_bot_token_help'] = 'Pegá el token de @BotFather. Se encripta antes de guardarlo.';
$string['telegram_connection_status'] = 'Estado de conexión';
$string['telegram_cooldown'] = 'Tiempo de espera';
$string['telegram_cooldown_help'] = 'Minutos para bloquear a un usuario después de exceder el límite.';
$string['telegram_enabled'] = 'Habilitar integración con Telegram';
$string['telegram_gateway_timeout'] = 'Timeout del Gateway (segundos)';
$string['telegram_gateway_timeout_help'] = 'Segundos máximos de espera para respuesta del Gateway Jedomi (5-300).';
$string['telegram_heading'] = 'Telegram';
$string['telegram_heading_help'] = 'Conectá Jedomi a un bot de Telegram para que los usuarios conversen vía chat.';
$string['telegram_help_footer'] = 'Después de vincular, enviá cualquier mensaje y lo voy a reenviar a Jedomi AI para obtener '
    . 'una respuesta.';
$string['telegram_help_heading'] = '🤖 <b>Bot Jedomi — Comandos disponibles</b>';
$string['telegram_help_help'] = '❓ <b>/help</b> — Mostrar este mensaje de ayuda';
$string['telegram_help_new'] = '🗑️ <b>/new</b> o <b>/nuevo</b> — Borrar conversación y empezar de nuevo';
$string['telegram_help_start'] = '🔗 <b>/start CÓDIGO</b> — Vinculá tu cuenta de Moodle con Telegram';
$string['telegram_help_unlink'] = '🚫 <b>/desvincular</b> o <b>/unlink</b> — Desvinculá tu Telegram de Moodle';
$string['telegram_keyboard_help'] = '❓ Ayuda';
$string['telegram_keyboard_howto_link'] = '🔗 ¿Cómo vincular?';
$string['telegram_keyboard_new'] = '📝 Nueva conversación';
$string['telegram_keyboard_unlink'] = '🚫 Desvincular';
$string['telegram_link_code_expiry'] = 'Este código vence en 10 minutos.';
$string['telegram_link_generate'] = 'Generar código';
$string['telegram_link_instructions'] = 'Abrí Telegram, buscá tu bot, y enviá /start seguido del código.';
$string['telegram_link_title'] = 'Vincular Telegram';
$string['telegram_linked_as'] = 'Vinculado como @{username}';
$string['telegram_max_turns'] = 'Turnos máximos de conversación';
$string['telegram_max_turns_help'] = 'Cantidad de mensajes guardados en el historial de Telegram (1-100). Valores más bajos '
    . 'ahorran tokens.';
$string['telegram_msg_cancel'] = 'Cancelar';
$string['telegram_msg_confirm'] = 'Confirmar';
$string['telegram_msg_done'] = 'Listo.';
$string['telegram_msg_executing'] = 'Ejecutando...';
$string['telegram_msg_expired_code'] = 'Código inválido o vencido. Generá uno nuevo desde tu perfil de Moodle.';
$string['telegram_msg_link_first'] = 'Primero vinculá tu cuenta: andá a tu perfil de Moodle y hacé clic en "Vincular Telegram".';
$string['telegram_msg_linked_success'] = '✅ Vinculado a Moodle como <b>{$a->fullname}</b> ({$a->username})';
$string['telegram_msg_max_iterations'] = '⚠️ Demasiados pasos. Simplificá tu consulta.';
$string['telegram_msg_new_conversation'] = '🗑️ Conversación borrada. ¡Empezá de nuevo!';
$string['telegram_msg_provide_code'] = '🔗 Proporcioná tu código de vinculación.\\n\\nEjemplo: /start ABC123';
$string['telegram_msg_rate_limited'] = 'Demasiados mensajes. Esperá {minutes} minutos.';
$string['telegram_msg_role_blocked'] = 'Tu rol no tiene acceso a Jedomi Telegram.';
$string['telegram_msg_timeout'] = '⏳ Esta tarea es muy compleja para el límite de tiempo. Dividila en pasos más chicos o '
    . 'enviá el mismo mensaje de nuevo y sigo desde donde quedé.';
$string['telegram_msg_token_invalid'] = 'Token de bot inválido. Verificá el token de @BotFather e intentá de nuevo.';
$string['telegram_msg_unlinked'] = 'Desvinculado. Enviá /start para volver a vincular.';
$string['telegram_not_linked'] = 'Sin vincular';
$string['telegram_progress'] = '🤔 Jedomi está pensando…';
$string['telegram_rate_limit'] = 'Límite de mensajes';
$string['telegram_rate_limit_help'] = 'Máximo de mensajes por minuto que un usuario puede enviar. 0 = ilimitado.';
$string['telegram_role_visibility_help'] = 'Desactivá para evitar que usuarios con este rol vinculen o reciban mensajes de '
    . 'Telegram. Los admins siempre tienen acceso.';
$string['telegram_role_visible'] = 'Permitir Telegram para rol: {role}';
$string['telegram_setup_done'] = 'Integración configurada';
$string['telegram_setup_done_help'] = 'Telegram está activo. Los usuarios con roles permitidos pueden vincular sus cuentas desde '
    . 'su perfil de Moodle.';
$string['telegram_setup_guide'] = 'Guía de configuración';
$string['telegram_setup_guide_help'] = 'Seguí estos pasos para conectar Jedomi con Telegram. Una vez configurado, los usuarios '
    . 'pueden vincular sus cuentas desde su perfil.';
$string['telegram_setup_hide'] = 'Ocultar guía';
$string['telegram_setup_show'] = 'Ver guía de configuración';
$string['telegram_status_connected'] = 'Conectado';
$string['telegram_status_disconnected'] = 'Desconectado';
$string['telegram_status_pending'] = 'Pendiente — pegá la URL del webhook en @BotFather';
$string['telegram_step1_desc'] = 'Abrí Telegram, buscá <strong>@BotFather</strong>, enviá <code>/newbot</code>, elegí nombre '
    . 'y usuario. Copiá el token que te da (formato: <code>123456:ABC-DEF...</code>).';
$string['telegram_step1_title'] = 'Crear un bot en @BotFather';
$string['telegram_step2_desc'] = 'Pegá el token en el campo <strong>Token del bot</strong> abajo, activá el toggle de '
    . 'integración y hacé clic en <strong>Guardar cambios</strong>.';
$string['telegram_step2_title'] = 'Pegar el token y guardar';
$string['telegram_step3_desc'] = 'Copiá la <strong>URL del webhook</strong> que aparece después de guardar. Luego andá a '
    . '@BotFather y ejecutá: <code>/setwebhook</code>. O usá este comando curl:';
$string['telegram_step3_note'] = 'Para desarrollo local (DDEV), primero usá ngrok para obtener una URL pública HTTPS: '
    . '<code>ngrok http https://moodle2.ddev.site --host-header=moodle2.ddev.site</code>';
$string['telegram_step3_title'] = 'Registrar el webhook en Telegram';
$string['telegram_step4_desc'] = 'Usá los toggles de roles abajo para controlar qué roles de Moodle pueden usar Telegram. Los '
    . 'admins siempre tienen acceso independientemente de esta configuración.';
$string['telegram_step4_title'] = 'Configurar acceso por rol (opcional)';
$string['telegram_stepU1_desc'] = 'Hacé clic en tu nombre en la barra superior → <strong>Perfil</strong>. Vas a ver una '
    . 'sección <strong>Jedomi AI</strong> con el link <em>Vincular Telegram</em>.';
$string['telegram_stepU1_title'] = 'Ir al perfil de Moodle';
$string['telegram_stepU2_desc'] = 'Hacé clic en <strong>Generar código</strong>. Aparece un código de 6 caracteres, válido '
    . 'por 10 minutos.';
$string['telegram_stepU2_title'] = 'Generar un código de vinculación';
$string['telegram_stepU3_desc'] = 'En Telegram, abrí el bot de tu institución y enviá: <code>/start TU_CODIGO</code>. El bot '
    . 'confirma el vínculo al instante.';
$string['telegram_stepU3_title'] = 'Enviar /start al bot';
$string['telegram_stepU4_desc'] = 'Enviá cualquier mensaje al bot. Jedomi responde con datos reales de tu Moodle. Para '
    . 'desvincular, enviá <code>/desvincular</code> o usá tu página de perfil.';
$string['telegram_stepU4_title'] = 'Empezar a chatear';
$string['telegram_step_admin_help'] = 'Se hace una sola vez. Requiere acceso a Telegram.';
$string['telegram_step_admin_title'] = 'Configuración del Administrador';
$string['telegram_step_user_help'] = 'Una vez que el admin configuró el bot, cada usuario vincula su cuenta de Telegram.';
$string['telegram_step_user_title'] = 'Configuración del Usuario (cada usuario lo hace una vez)';
$string['telegram_still_working'] = '🔄 Todavía estoy procesando, dame un momento…';
$string['telegram_webhook_secret'] = 'Secreto del webhook';
$string['telegram_webhook_secret_help'] = 'Secreto aleatorio que se agrega a la URL del webhook por seguridad. Regeneralo si se '
    . 'filtra.';
$string['telegram_webhook_status'] = 'Estado del Webhook';
$string['telegram_webhook_url'] = 'URL del webhook';
$string['telegram_webhook_url_help'] = 'Copiá esta URL en @BotFather con /setwebhook.';
$string['thinking'] = 'Pensando...';
$string['toggle_thinking'] = 'Mostrar/ocultar animación de pensamiento';
$string['tool_admin_describe_desc'] = 'Obtiene la documentación completa (parámetros, tipos, campos obligatorios) de una '
    . 'operación CLI de administración específica por su nombre. Úsala DESPUÉS de admin_search y ANTES de admin_execute para '
    . 'verificar los parámetros obligatorios.';
$string['tool_admin_describe_name'] = 'Nombre de la operación (p. ej. backup, scheduled_task, purge_caches).';
$string['tool_admin_execute_desc'] = 'Ejecuta una operación CLI de administración para crear o modificar datos de Moodle. '
    . 'Requiere rol de administrador o gestor. USAR CON PRECAUCIÓN: estas operaciones modifican la base de datos. COMBÍNALA con '
    . 'las herramientas WS: primero usa ws_search/ws_execute para descubrir cursos, secciones, usuarios o verificar datos, y '
    . 'luego llama a admin_execute con la información obtenida. Flujo de ejemplo: ws_execute(core_course_get_courses_by_field) '
    . 'para encontrar el curso → admin_execute(create_mod, ...) para crear una actividad.';
$string['tool_admin_execute_name'] = 'Nombre de la operación (p. ej. backup, scheduled_task, purge_caches).';
$string['tool_admin_execute_params'] = 'Objeto JSON con los parámetros de la operación (usa primero admin_describe para ver los '
    . 'campos obligatorios).';
$string['tool_admin_search_desc'] = 'Busca operaciones CLI disponibles para administradores (p. ej. copia de seguridad, purgar '
    . 'cachés, ejecutar tareas programadas). Úsala para descubrir qué operaciones existen antes de describirlas o ejecutarlas.';
$string['tool_admin_search_limit'] = 'Número máximo de resultados a devolver (por defecto: 8, máx.: 20).';
$string['tool_admin_search_query'] = 'Palabras clave de búsqueda para encontrar operaciones de administración relevantes.';
$string['tool_api_call'] = 'Ejecutando acción en Moodle...';
$string['tool_create_agent_context_id'] = 'The ID of the course, category, or user. Required when context_type is not "site".';
$string['tool_create_agent_context_type'] = 'Scope of execution: "site" for global, "course" for a specific course, "category" '
    . 'for a course category, "user" for a specific user. Defaults to "site".';
$string['tool_create_agent_desc'] = 'Create a scheduled AI agent. The agent will execute the given prompt on a recurring '
    . 'schedule, cache results, and send notifications via Telegram (if linked). Only available to admins and managers.';
$string['tool_create_agent_enabled'] = 'Whether the agent starts enabled. Default: true. Set to false to create inactive.';
$string['tool_create_agent_prompt'] = 'The task description or instruction the AI agent will execute each time it runs. Be '
    . 'specific about what data to fetch, analyze, or report. Example: "Summarize new enrollments from the last 24 hours" or '
    . '"Check for students at risk in course 42".';
$string['tool_create_agent_schedule'] = 'Cron expression defining how often the agent runs. Common presets: "*/5 * * * *" = every '
    . '5 minutes, "*/30 * * * *" = every 30 minutes, "0 * * * *" = hourly, "0 0 * * *" = daily at midnight, "0 0 * * 0" = weekly '
    . 'on Sunday.';
$string['tool_delete_agent_desc'] = 'Delete a scheduled AI agent by ID. Permanent — removes agent and all cached results. '
    . 'Cannot be undone.';
$string['tool_delete_agent_id'] = 'The numeric ID of the agent to delete. Use list_agents to find the ID.';
$string['tool_describe_table'] = 'Analizando tabla...';
$string['tool_describe_table_desc'] = 'Obtiene la estructura (columnas, tipos, claves, índices, foreign keys) de una tabla de la '
    . 'base de datos de Moodle. Cacheado 24h.';
$string['tool_describe_table_tablename'] = 'Nombre de la tabla (ej: course, user, grade_grades)';
$string['tool_document_read_cmid'] = 'Course module ID (cmid) de la actividad. Obtenelo del contexto de página o descubrilo via '
    . 'ws_search/ws_execute.';
$string['tool_document_read_desc'] = 'Leer un archivo PDF de CUALQUIER actividad del curso usando su cmid (course module ID). '
    . 'Convierte páginas PDF a imágenes base64 para ver el diseño ORIGINAL exacto. El cmid está disponible en el contexto '
    . 'cuando se ve una página de actividad.';
$string['tool_document_read_dpi'] = 'Resolución de imagen (default: 150). Valores más altos = mejor calidad pero más tokens.';
$string['tool_document_read_fmt'] = 'Formato de imagen: JPEG (más chico) o PNG (sin pérdida). Default: JPEG.';
$string['tool_execute_sql'] = 'Consultando base de datos...';
$string['tool_execute_sql_desc'] = 'Ejecuta una consulta SELECT en la base de datos de Moodle y devuelve las filas como JSON. '
    . 'SOLO lectura. Límite: 100 filas, timeout 5 segundos.';
$string['tool_execute_sql_params'] = 'Parámetros vinculados para la consulta (opcional)';
$string['tool_execute_sql_sql'] = 'Consulta SELECT a ejecutar. Usá ? como placeholder para parámetros. Ejemplo: SELECT id, '
    . 'fullname FROM {course} WHERE category = ?';
$string['tool_get_site_config'] = 'Obteniendo configuración...';
$string['tool_get_site_config_desc'] = 'Obtiene configuración del sitio Moodle (sitename, lang, timezone, etc.). Solo disponible '
    . 'para administradores.';
$string['tool_get_site_config_keys'] = 'Lista de claves de configuración. Válidas: sitename, siteurl, lang, country, timezone, '
    . 'maxbytes, passwordpolicy, enrol_plugins_enabled, auth_plugins_enabled';
$string['tool_list_agents_desc'] = 'List all configured scheduled AI agents, optionally filtered by status or context. Returns '
    . 'agent ID, prompt excerpt, schedule, context, status, last run, next run.';
$string['tool_list_agents_status'] = 'Optional filter: "enabled" for active agents, "disabled" for inactive. Empty = all.';
$string['tool_schema_lookup'] = 'Buscando tabla...';
$string['tool_search_tables'] = 'Buscando tablas...';
$string['tool_search_tables_desc'] = 'Busca tablas de Moodle por intención o palabras clave en INGLÉS. Busca en nombres de '
    . 'tablas Y sus descripciones COMMENT de install.xml. Devuelve los resultados más relevantes con nombre, descripción, '
    . 'nombres de campos y comentarios de campos. Usá SIEMPRE search_tables antes de describe_table o execute_sql.';
$string['tool_search_tables_limit'] = 'Máximo de resultados (default: 8, max: 15)';
$string['tool_search_tables_query'] = 'Intención o palabras clave en INGLÉS (ej: "user grades", "course enrolment", "student")';
$string['tool_sql_read'] = 'Consultando base de datos...';
$string['tool_unknown'] = 'Ejecutando herramienta...';
$string['tool_webservice_lookup'] = 'Buscando función API...';
$string['tool_ws_describe'] = 'Analizando función webservice...';
$string['tool_ws_describe_desc'] = 'Obtené documentación completa (descripción, parámetros con * = requerido, tipo de '
    . 'retorno) para una función de servicio web específica cuando ya sabés su nombre exacto. LLAMÁ esto SIEMPRE DESPUÉS de '
    . 'ws_search y ANTES de ws_execute para verificar los campos requeridos.';
$string['tool_ws_describe_wsname'] = 'Nombre exacto de la función (ej: core_user_create_users)';
$string['tool_ws_execute'] = 'Ejecutando acción...';
$string['tool_ws_execute_desc'] = 'Ejecutá un servicio web de Moodle en nombre del usuario actual. Usalo DESPUÉS de ws_search '
    . 'para obtener el nombre exacto de la función y los parámetros. Si el primer intento falla por "campo requerido faltante", '
    . 'reintentá con valores predeterminados para los campos opcionales. Para operaciones batch (misma acción en múltiples '
    . 'items), llamá esta función VARIAS VECES — una por cada item.';
$string['tool_ws_execute_params'] = 'Parámetros codificados en JSON para la función del servicio web. DEBE incluir todos los '
    . 'campos * (requerido). IMPORTANTE: Si el primer intento falla con "Detectado valor de parámetro no válido" o "Missing '
    . 'required key", significa que la instancia requiere campos que el schema marca como opcionales. En ese caso, REINTENTÁ '
    . 'agregando valores predeterminados para los campos faltantes (ej: description="", enrolmentkey="", idnumber="", '
    . 'visibility=0, participation=true).';
$string['tool_ws_execute_wsname'] = 'Nombre de la función del servicio web (ej: core_course_get_courses)';
$string['tool_ws_search'] = 'Buscando funciones webservices...';
$string['tool_ws_search_desc'] = 'Busca funciones de servicios web de Moodle por intención o palabras clave en INGLÉS. Devuelve '
    . 'las más relevantes con descripción completa, parámetros (* = requerido) y estructura de retorno. Si los resultados '
    . 'incluyen lo que necesitás: DEJÁ de buscar — llamá ws_describe con el nombre exacto, luego ws_execute. No llamés '
    . 'ws_search de nuevo para la misma tarea.';
$string['tool_ws_search_limit'] = 'Máximo de resultados a devolver (default: 6, max: 15)';
$string['tool_ws_search_query'] = 'Intención o palabras clave en INGLÉS (ej: "create user", "enrol student", "get course '
    . 'grades")';
$string['training_data_title'] = 'Datos de entrenamiento Jedomi';
$string['transcribing'] = 'Transcribiendo...';
$string['unauthorized'] = 'No autorizado.';
$string['unknown_error'] = 'Error desconocido';
$string['unlink'] = 'Desvincular';
$string['voice_input'] = 'Dictar (voz)';
$string['waiting_voice'] = 'Esperando voz...';
$string['webhook_error'] = 'Último error del webhook:';
$string['webhook_error_alert_desc'] = 'El token del bot se guardó correctamente, pero no se pudo registrar el webhook en '
    . 'Telegram.';
$string['webhook_error_alert_title'] = 'Problema con el registro del webhook';
$string['webhook_error_detail'] = 'Detalles del error:';
$string['webhook_not_checked'] = 'Sin verificar. Hacé clic en "Verificar webhook" para comprobar.';
$string['webhook_ok'] = 'Webhook configurado y funcionando.';
$string['ws_cannot_load_info'] = 'No se puede cargar información de la función: {$a}';
$string['ws_component'] = 'Componente';
$string['ws_debug_prefix'] = ' | debug: ';
$string['ws_description'] = 'Descripción';
$string['ws_execute_error'] = 'Error al ejecutar el servicio web: {$a}';
$string['ws_function_not_found'] = 'Función no encontrada: {$a}';
$string['ws_method_not_exists'] = 'El método {$a} no existe';
$string['ws_name'] = 'Nombre';
$string['ws_no_services'] = 'No hay servicios web configurados para este rol.';
$string['ws_none_found'] = 'Ningún servicio web coincide con tus filtros.';
$string['ws_not_found'] = 'Función de servicio web no encontrada.';
$string['ws_not_in_allowlist'] = 'Este servicio web no está en la lista permitida de tu rol.';
$string['ws_pagination'] = 'Página {$a->page} de {$a->pages} ({$a->total} servicios)';
$string['ws_type'] = 'Tipo';
$string['ws_type_all'] = 'Todos los tipos';
$string['ws_type_read'] = 'Lectura';
$string['ws_type_write'] = 'Escritura';
$string['yes_send'] = 'Sí, enviar';
