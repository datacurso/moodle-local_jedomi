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
$string['agent'] = 'Agente';
$string['agent_actions'] = 'Acciones';
$string['agent_actions_menu'] = 'Acciones del agente';
$string['agent_apply_filters'] = 'Aplicar Filtros';
$string['agent_by_role'] = 'Agentes por Rol';
$string['agent_by_status'] = 'Agentes por Estado';
$string['agent_chart_no_data'] = 'Sin datos';
$string['agent_context'] = 'Contexto';
$string['agent_context_id'] = 'ID del contexto';
$string['agent_context_id_placeholder'] = 'p. ej. 42';
$string['agent_context_type_category'] = 'Categoría';
$string['agent_context_type_course'] = 'Curso';
$string['agent_context_type_site'] = 'Sitio';
$string['agent_context_type_user'] = 'Usuario';
$string['agent_count'] = '{$a} agentes';
$string['agent_create'] = 'Crear Agente';
$string['agent_create_success'] = 'Agente creado correctamente.';
$string['agent_created_no_name'] = 'Agente creado sin nombre. Editá el agente para agregarle un nombre.';
$string['agent_created_with_name'] = 'Agente "{$a}" creado correctamente.';
$string['agent_creator'] = 'Creador';
$string['agent_custom_cron'] = 'Personalizado (expresión cron)';
$string['agent_daily'] = 'Diario';
$string['agent_delete'] = 'Eliminar';
$string['agent_delete_confirm'] = '¿Eliminar este agente? Esta acción no se puede deshacer.';
$string['agent_disable'] = 'Desactivar';
$string['agent_disabled'] = 'Desactivado';
$string['agent_disabled_notice'] = 'Agente desactivado';
$string['agent_disabled_reason_creator_deleted'] = 'El creador del agente fue eliminado.';
$string['agent_disabled_reason_creator_missing'] = 'El creador del agente ya no existe.';
$string['agent_disabled_reason_creator_suspended'] = 'El creador del agente está suspendido.';
$string['agent_disabled_reason_no_access'] = 'El creador ya no tiene acceso al contexto del agente.';
$string['agent_edit'] = 'Editar';
$string['agent_enable'] = 'Activar';
$string['agent_enabled'] = 'Activo';
$string['agent_error_create'] = 'Error al crear agente: {$a}';
$string['agent_error_delete'] = 'Error al eliminar agente: {$a}';
$string['agent_error_load'] = 'Error al cargar agentes.';
$string['agent_error_nopermission'] = 'No puedes gestionar los agentes de usuarios con más permisos que los tuyos.';
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
$string['agent_invalid_context_type'] = 'tipo de contexto no válido';
$string['agent_invalid_cron'] = 'expresión cron no válida';
$string['agent_lastrun'] = 'Última ejecución';
$string['agent_name'] = 'Nombre';
$string['agent_name_placeholder'] = 'ej. Reporte Diario';
$string['agent_nextrun'] = 'Próxima ejecución';
$string['agent_no_results'] = 'No se encontraron agentes con esos filtros.';
$string['agent_not_found'] = 'Agente no encontrado.';
$string['agent_number'] = 'Agente #{$a}';
$string['agent_preview'] = 'Vista previa';
$string['agent_preview_arguments'] = 'Argumentos';
$string['agent_preview_cached'] = 'Resultado en caché del';
$string['agent_preview_clear_cache'] = 'Borrar caché';
$string['agent_preview_error'] = 'La vista previa falló. Revisa el prompt e inténtalo de nuevo.';
$string['agent_preview_prompt_required'] = 'Primero escribe una instrucción.';
$string['agent_preview_result'] = 'Resultado de la vista previa';
$string['agent_preview_result_n'] = 'Resultado {$a}';
$string['agent_preview_result_preview'] = 'Vista previa del resultado';
$string['agent_preview_role_assistant'] = 'Asistente';
$string['agent_preview_role_system'] = 'Sistema';
$string['agent_preview_role_tool'] = 'Herramienta';
$string['agent_preview_role_user'] = 'Usuario';
$string['agent_preview_round'] = 'Ronda {$a}';
$string['agent_preview_timeline'] = 'Línea de tiempo de la conversación';
$string['agent_preview_tool_stats'] = '{$a->duration} ms · {$a->rows} filas';
$string['agent_preview_total_time'] = 'Total: {$a} ms';
$string['agent_prompt'] = 'Prompt';
$string['agent_prompt_placeholder'] = 'Ej: "Resumí la actividad nueva del sitio y notificame"';
$string['agent_purge'] = 'Eliminar Agentes';
$string['agent_purge_btn'] = 'Eliminar';
$string['agent_purge_confirm'] = 'Esto va a eliminar todos los agentes que coincidan y sus resultados cacheados. ¿Continuar?';
$string['agent_purge_context'] = 'Tipo de contexto';
$string['agent_purge_context_help'] = 'Eliminar todos los agentes de un contexto específico.';
$string['agent_purge_id_required'] = 'Introduce el id del curso, la categoría o el usuario a purgar.';
$string['agent_purge_invalid_type'] = 'Tipo de purga no válido: {$a}';
$string['agent_purge_role_not_found'] = 'No se encontró el rol.';
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
$string['agent_user_id'] = 'Usuario #{$a}';
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
$string['analyze_file'] = 'Resume este archivo.';
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
$string['attach_audio'] = 'Adjuntar archivo de audio';
$string['attach_file'] = 'Adjuntar archivo PDF, CSV, TXT, MD, Word o PowerPoint';
$string['attach_image'] = 'Adjuntar imagen';
$string['attached_file_info'] = '[Archivo adjunto: {$a}]';
$string['attached_image'] = 'Imagen adjunta';
$string['attached_image_content'] = '[Contenido de la imagen adjunta: {$a}]';
$string['audio_enabled'] = 'Habilitar el procesamiento de audio';
$string['audio_enabled_help'] = 'Permite dictar con el micrófono y adjuntar archivos de audio en el chat web, y enviar mensajes de voz y audios en Telegram. El audio lo transcribe el modelo de audio, que consume créditos por minuto de audio.';
$string['audio_error_disabled'] = 'El procesamiento de audio no está habilitado en este sitio.';
$string['audio_error_too_long'] = 'El audio es demasiado largo para transcribirlo. Envíe uno más corto.';
$string['audio_error_unreadable'] = 'No se pudo entender el audio. Inténtelo de nuevo, hablando más cerca del micrófono.';
$string['audio_error_unsupported'] = 'Este formato de audio no es compatible. Use MP3, WAV, M4A, OGG o WEBM.';
$string['audio_file'] = 'Audio';
$string['audio_model'] = 'Modelo de audio';
$string['audio_model_help'] = 'Modelo que transcribe el audio. En las pruebas, Qwen3-ASR 1.7B es el que mejor escribe nombres y tildes.';
$string['audio_processing'] = 'Habilitar procesamiento de audio';
$string['cachedef_speech_rate'] = 'Pedidos de la conversación por voz por usuario y minuto';
$string['cancel'] = 'Cancelar';
$string['cancelled'] = 'Cancelado.';
$string['category_list'] = 'IDs de categorías';
$string['category_list_help'] = 'Ingresá los IDs de categorías separados por comas. Todos los cursos de esas categorías quedan '
    . 'incluidos. Ejemplo: 2, 5, 8';
$string['chat_error_course_unavailable'] = 'Jedomi no está disponible en este curso.';
$string['chat_error_guest'] = 'Jedomi solo está disponible para usuarios que inician sesión con su propia cuenta.';
$string['chat_error_role_hidden'] = 'Jedomi no está disponible para su rol.';
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
$string['confirm_keyword_required'] = 'Escriba la palabra de confirmación para ejecutar esta acción.';
$string['confirm_send_bulk'] = '¿Enviar este mensaje a {count} alumno(s)?';
$string['confirm_type_to_proceed'] = 'Escribí CONFIRMAR para proceder:';
$string['connection_error'] = 'Error de conexión:';
$string['conversation_cleared'] = 'Conversación borrada.';
$string['copytoclipboard'] = 'Copiar al portapapeles';
$string['course_availability_courses'] = 'Cursos seleccionados';
$string['course_availability_courses_help'] = 'Cursos donde se muestra el chat (modo incluir) o se oculta (modo excluir). Busca por nombre o nombre corto del curso.';
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
$string['drop_files_here'] = 'Suelta los archivos aquí';
$string['drop_pending_fail'] = '❌ Error al eliminar pendientes — revisá la respuesta de la API de Telegram.';
$string['drop_pending_running'] = 'Descartando...';
$string['drop_pending_success'] = '✅ Pendientes eliminados correctamente.';
$string['drop_pending_updates'] = 'Eliminar pendientes';
$string['drop_pending_updates_help'] = 'Limpia la cola de actualizaciones de Telegram para que mensajes viejos no se procesen al '
    . 'reconectar el webhook. Útil durante pruebas.';
$string['encryption_failed'] = 'Falló la encriptación. Verificá la configuración de OpenSSL del servidor.';
$string['error_fallback_iterations'] = 'Lo siento, no pude completar la operación después de varios intentos.';
$string['error_invalid_hash'] = 'Clave de caché no válida.';
$string['error_invalid_max_tokens'] = 'Ingrese un número entero de tokens.';
$string['error_invalid_model'] = 'Seleccione uno de los modelos de la lista.';
$string['error_invalid_voice'] = 'Seleccione una de las voces del modelo elegido.';
$string['error_not_own_course'] = 'Ese curso no es uno de sus cursos.';
$string['error_own_data_arguments'] = 'Argumento(s) no admitido(s): {$a}. Estas herramientas solo leen los datos del usuario '
    . 'actual.';
$string['error_permission_denied'] = 'permission_denied';
$string['error_permission_denied_ws'] = 'permission_denied: function not in allowlist';
$string['error_post_required'] = 'Esta solicitud debe enviarse con POST.';
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
$string['error_unknown_action'] = 'Acción desconocida: {$a}';
$string['error_unknown_tool'] = 'Herramienta desconocida: {$a}';
$string['error_value_range'] = 'Introduce un valor entre {$a->min} y {$a->max}.';
$string['error_voice_requires_audio'] = 'La conversación por voz necesita el procesamiento de audio: habilítelo también.';
$string['error_ws_describe_failed'] = 'ws_describe failed: {$a}';
$string['error_ws_execute_failed'] = 'ws_execute failed: {$a}';
$string['error_ws_search_failed'] = 'ws_search failed: {$a}';
$string['error_wsname_required'] = 'wsname is required';
$string['execute_sql_error'] = 'Error de base de datos al ejecutar la query.';
$string['export_conversation'] = 'Exportar conversación';
$string['file'] = 'Archivo';
$string['file_audio'] = 'Audio: {seconds} segundos';
$string['file_csv'] = 'CSV: {rows} filas, {columns} columnas';
$string['file_error_no_text'] = 'No se pudo leer texto de este archivo.';
$string['file_error_too_large'] = 'El archivo es demasiado grande para leerlo.';
$string['file_error_unsupported'] = 'Solo se pueden adjuntar archivos PDF, CSV, TXT, MD, Word (DOC, DOCX) y PowerPoint (PPT, PPTX), '
    . 'y archivos de audio cuando el procesamiento de audio está habilitado.';
$string['file_loaded'] = 'cargado';
$string['file_loaded_hint'] = 'Podés hacer preguntas sobre su contenido.';
$string['file_not_found'] = 'Archivo no encontrado o no legible.';
$string['file_pdf'] = 'PDF: {pages} páginas';
$string['file_process_error'] = 'No se pudo procesar';
$string['file_text'] = 'Texto: {chars} caracteres';
$string['files_enabled'] = 'Habilitar el procesamiento de archivos e imágenes';
$string['files_enabled_help'] = 'Permite adjuntar imágenes y archivos en el chat web, enviar fotos y documentos en Telegram, y que la IA lea los PDF de las actividades del curso. Las imágenes y las páginas escaneadas de los PDF las lee el modelo de archivos e imágenes, que consume créditos.';
$string['files_error_disabled'] = 'El procesamiento de archivos e imágenes no está habilitado en este sitio.';
$string['files_processing'] = 'Habilitar procesamiento de archivos e imágenes';
$string['filter_all'] = 'Todos';
$string['filter_read'] = 'Solo lectura';
$string['filter_type'] = 'Tipo:';
$string['filter_write'] = 'Solo escritura';
$string['full_width'] = 'Pantalla completa';
$string['greeting'] = '¡Hola, {name}!';
$string['half_width'] = 'Media pantalla';
$string['hello'] = 'Soy Jedomi, tu asistente IA de Moodle. Pregúntame sobre cursos, alumnos, notas o configuraciones.';
$string['hide_from_guests'] = 'Ocultar a usuarios invitados';
$string['hide_from_guests_help'] = 'Los invitados y los usuarios sin sesión iniciada nunca ven Jedomi ni pueden usarlo: siempre están '
    . 'excluidos, sea cual sea el valor de esta opción.';
$string['hint_sql_braces'] = ' | PISTA: En SQL de Moodle, los nombres de tablas deben usar llaves (ej: {course}, {user}).';
$string['image'] = 'Imagen';
$string['image_error_no_text'] = 'No se pudo leer contenido de este archivo.';
$string['image_error_unsupported'] = 'Solo se pueden leer archivos PDF, JPG, PNG y WEBP.';
$string['improvement_heading'] = 'Mejora continua';
$string['improvement_heading_help'] = 'Recolección opcional de datos para mejorar Jedomi con el tiempo.';
$string['invalid_session'] = 'Su sesión cambió. Recargue la página para seguir usando Jedomi.';
$string['invalid_signature'] = 'Los permisos del chat no son válidos. Recargue la página para seguir usando Jedomi.';
$string['jedomi:companion_api'] = 'Usar las funciones del servicio complementario de Jedomi';
$string['jedomi:manageagents'] = 'Gestionar los agentes de Jedomi';
$string['jedomi:managetrainingdata'] = 'Gestionar los datos de entrenamiento de Jedomi';
$string['jedomi:viewtrainingdata'] = 'Ver los datos de entrenamiento de Jedomi';
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
$string['model'] = 'Modelo de chat';
$string['model_help'] = 'Modelo que responde en el chat web, los agentes programados, la vista previa de agentes, Telegram y la CLI. Solo se muestran los modelos con precio registrado.';
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
$string['open_sidebar'] = 'Abrir Jedomi AI';
$string['path_not_allowed'] = 'Esta ruta no está permitida.';
$string['pending_action_denied'] = 'La acción no se ejecutó: no tienes permiso para hacerla.';
$string['pending_action_expired'] = 'Esta acción venció. Vuelve a pedírsela a Jedomi.';
$string['pending_action_failed'] = 'No se pudo completar la acción.';
$string['pending_action_needed'] = 'Esta acción necesita tu confirmación.';
$string['pending_action_not_found'] = 'Esta acción no existe o ya se resolvió.';
$string['pending_desc_admin_execute'] = 'Ejecutar la operación de administración "{$a}".';
$string['pending_desc_create_agent'] = 'Crear el agente programado "{$a}".';
$string['pending_desc_delete_agent'] = 'Eliminar el agente programado #{$a}.';
$string['pending_desc_send_messages'] = 'Enviar un mensaje de Moodle a {$a} usuario(s).';
$string['pending_desc_ws_execute'] = 'Ejecutar la función de servicio web "{$a}".';
$string['pluginname'] = 'Jedomi AI Sidebar';
$string['privacy:metadata:jedomi_ai_service'] = 'Las conversaciones se envían a través del proveedor de IA de Datacurso a su servicio de IA para generar las respuestas.';
$string['privacy:metadata:jedomi_ai_service:messages'] = 'Los mensajes de la conversación y los resultados de las herramientas usadas para responderlos.';
$string['privacy:metadata:jedomi_ai_service:userid'] = 'Un identificador seudónimo del usuario, generado por el proveedor de IA de Datacurso.';
$string['privacy:metadata:jedomi_speech_service'] = 'En la conversación por voz del chat web, las respuestas se envían frase por frase por el proveedor de IA de Datacurso a su servicio de IA para leerlas en voz alta. El servicio de IA no guarda el texto.';
$string['privacy:metadata:jedomi_speech_service:lang'] = 'El idioma del usuario.';
$string['privacy:metadata:jedomi_speech_service:site_id'] = 'Un identificador del sitio, que el servicio de IA usa para separar las solicitudes de cada sitio.';
$string['privacy:metadata:jedomi_speech_service:site_url'] = 'La dirección del sitio.';
$string['privacy:metadata:jedomi_speech_service:text'] = 'El texto de la respuesta que se lee en voz alta, anonimizado en el sitio con el nivel de la pestaña Privacidad de Jedomi antes de enviarse: los nombres y las direcciones de correo de los usuarios del sitio (y, en el nivel Estricto, códigos como los números de estudiante) se sustituyen por palabras genéricas como "una persona".';
$string['privacy:metadata:jedomi_speech_service:userid'] = 'Un identificador seudónimo del usuario, derivado por el proveedor de IA de Datacurso.';
$string['privacy:metadata:local_jedomi_agent_cache'] = 'Resultados de las ejecuciones de los agentes programados, guardados para entregarlos y reutilizarlos.';
$string['privacy:metadata:local_jedomi_agent_cache:agentid'] = 'El agente al que pertenece el resultado.';
$string['privacy:metadata:local_jedomi_agent_cache:result_json'] = 'El resultado producido por el agente, que puede incluir datos leídos del sitio.';
$string['privacy:metadata:local_jedomi_agent_cache:timecreated'] = 'Cuándo se produjo el resultado.';
$string['privacy:metadata:local_jedomi_agents'] = 'Agentes programados creados por el usuario, que ejecutan periódicamente una petición a la IA.';
$string['privacy:metadata:local_jedomi_agents:name'] = 'El nombre del agente.';
$string['privacy:metadata:local_jedomi_agents:prompt'] = 'La petición que ejecuta el agente.';
$string['privacy:metadata:local_jedomi_agents:schedule_cron'] = 'Cuándo se ejecuta el agente.';
$string['privacy:metadata:local_jedomi_agents:timecreated'] = 'Cuándo se creó el agente.';
$string['privacy:metadata:local_jedomi_agents:userid'] = 'El usuario propietario del agente.';
$string['privacy:metadata:local_jedomi_feedback'] = 'Valoraciones que los usuarios dan a las respuestas de Jedomi.';
$string['privacy:metadata:local_jedomi_feedback:messagetext'] = 'Un fragmento de la respuesta valorada.';
$string['privacy:metadata:local_jedomi_feedback:rating'] = 'Si la respuesta se valoró como útil o como mejorable.';
$string['privacy:metadata:local_jedomi_feedback:sessionid'] = 'La sesión de chat de la respuesta valorada.';
$string['privacy:metadata:local_jedomi_feedback:timecreated'] = 'Cuándo se dio la valoración.';
$string['privacy:metadata:local_jedomi_feedback:userid'] = 'El usuario que valoró la respuesta.';
$string['privacy:metadata:local_jedomi_pending_actions'] = 'Acciones del chat web que esperan la confirmación del usuario durante un máximo de 15 minutos.';
$string['privacy:metadata:local_jedomi_pending_actions:argsjson'] = 'Argumentos de la acción, como el mensaje a enviar y sus destinatarios.';
$string['privacy:metadata:local_jedomi_pending_actions:severity'] = 'Nivel de cuidado que exige la confirmación.';
$string['privacy:metadata:local_jedomi_pending_actions:timecreated'] = 'Cuándo se pidió la acción.';
$string['privacy:metadata:local_jedomi_pending_actions:tool'] = 'Herramienta que ejecuta la acción.';
$string['privacy:metadata:local_jedomi_pending_actions:userid'] = 'Usuario que debe confirmar la acción.';
$string['privacy:metadata:local_jedomi_query_log'] = 'Consultas a la base de datos ejecutadas por los agentes, guardadas para el perfilador de consultas.';
$string['privacy:metadata:local_jedomi_query_log:agentid'] = 'El agente que ejecutó la consulta.';
$string['privacy:metadata:local_jedomi_query_log:query'] = 'El texto de la consulta, que puede incluir nombres u otros datos de usuarios.';
$string['privacy:metadata:local_jedomi_query_log:timecreated'] = 'Cuándo se ejecutó la consulta.';
$string['privacy:metadata:local_jedomi_telegram_links'] = 'El vínculo entre la cuenta de Moodle y un chat de Telegram.';
$string['privacy:metadata:local_jedomi_telegram_links:chatid'] = 'El chat de Telegram vinculado a la cuenta.';
$string['privacy:metadata:local_jedomi_telegram_links:timecreated'] = 'Cuándo se creó el vínculo.';
$string['privacy:metadata:local_jedomi_telegram_links:userid'] = 'El usuario de Moodle vinculado.';
$string['privacy:metadata:local_jedomi_telegram_links:username'] = 'El nombre de usuario de Telegram.';
$string['privacy:metadata:local_jedomi_telegram_pending_actions'] = 'Acciones solicitadas desde Telegram que esperan la confirmación del usuario.';
$string['privacy:metadata:local_jedomi_telegram_pending_actions:actionjson'] = 'La acción y sus argumentos.';
$string['privacy:metadata:local_jedomi_telegram_pending_actions:chatid'] = 'El chat de Telegram que solicitó la acción.';
$string['privacy:metadata:local_jedomi_telegram_rate_limit'] = 'Contadores de los mensajes enviados al bot, usados para limitar su cantidad.';
$string['privacy:metadata:local_jedomi_telegram_rate_limit:blockeduntil'] = 'Hasta cuándo el chat no puede enviar mensajes.';
$string['privacy:metadata:local_jedomi_telegram_rate_limit:chatid'] = 'El chat de Telegram.';
$string['privacy:metadata:local_jedomi_telegram_rate_limit:msgcount'] = 'Cuántos mensajes se enviaron en el periodo actual.';
$string['privacy:metadata:local_jedomi_telegram_sessions'] = 'La conversación reciente con el bot en Telegram.';
$string['privacy:metadata:local_jedomi_telegram_sessions:chatid'] = 'El chat de Telegram.';
$string['privacy:metadata:local_jedomi_telegram_sessions:lastactivity'] = 'Cuándo se intercambió el último mensaje.';
$string['privacy:metadata:local_jedomi_telegram_sessions:messages'] = 'Los mensajes de la conversación y los resultados de las herramientas usadas para responderlos.';
$string['privacy:metadata:preference:local_jedomi_grade_notifications'] = 'Tareas cuya calificación se notificó recientemente al usuario en Telegram, con la hora de cada notificación.';
$string['privacy:metadata:telegram'] = 'Las respuestas del bot, las notificaciones y los resultados de los agentes se entregan en el chat de Telegram vinculado.';
$string['privacy:metadata:telegram:chatid'] = 'El chat de Telegram al que se envían los datos.';
$string['privacy:metadata:telegram:messages'] = 'Las respuestas, notificaciones y resultados de agentes, que pueden incluir datos del sitio.';
$string['privacy_access_heading'] = 'Control de acceso';
$string['privacy_access_heading_help'] = 'Quién puede ver y usar el sidebar de Jedomi.';
$string['privacy_agents'] = 'Agentes programados';
$string['privacy_anon_heading'] = 'Anonimización de datos';
$string['privacy_anon_heading_help'] = 'Controla cómo se anonimiza la información de las conversaciones antes de almacenarla.';
$string['privacy_feedback'] = 'Valoraciones de respuestas';
$string['privacy_pending_actions'] = 'Acciones pendientes de confirmación';
$string['privacy_telegram'] = 'Telegram';
$string['processing_file'] = 'Procesando {$a}...';
$string['provider_settings_info'] = 'El interruptor Habilitado, el modelo de chat, el procesamiento de archivos e imágenes, el procesamiento de audio y los tokens máximos de Jedomi se configuran en su página de ajustes del proveedor de IA Datacurso.';
$string['provider_settings_link'] = 'Abrir los ajustes de Jedomi en el proveedor de IA Datacurso';
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
$string['qa_course_current'] = 'actual';
$string['qa_course_with_id'] = 'con ID {$a}';
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
$string['qa_teacher_notify_group_msg'] = 'Redacta un mensaje para los estudiantes del curso {$a} recordándoles las actividades '
    . 'pendientes y envíaselo como mensaje de Moodle con send_messages';
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
$string['query_profiler_min_ms'] = 'Duración mínima (ms)';
$string['query_profiler_ms'] = '{$a} ms';
$string['query_profiler_recent'] = 'Consultas recientes';
$string['query_profiler_rows'] = 'Filas';
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
$string['reading_image'] = 'Leyendo la imagen...';
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
$string['speech_error_disabled'] = 'La conversación por voz no está habilitada en este sitio.';
$string['speech_error_empty'] = 'No hay texto para leer en voz alta.';
$string['speech_error_rate_limited'] = 'Se leyeron demasiadas respuestas en voz alta en el último minuto. Espere un momento e inténtelo de nuevo.';
$string['speech_error_too_long'] = 'El texto es demasiado largo para leerlo en voz alta (como máximo {$a} caracteres).';
$string['speech_model'] = 'Modelo de voz';
$string['speech_model_help'] = 'Modelo que lee las respuestas en voz alta en la conversación por voz. En las pruebas, Qwen3-TTS tiene la mejor calidad en español; Inworld TTS 1.5 mini es más rápido. Consume créditos por carácter leído.';
$string['speech_placeholder_email'] = 'una dirección de correo';
$string['speech_placeholder_id'] = 'un identificador';
$string['speech_placeholder_person'] = 'una persona';
$string['speech_placeholder_text'] = 'un texto';
$string['speech_voice'] = 'Voz';
$string['speech_voice_help'] = 'Voz del modelo de voz elegido que lee las respuestas. Cada modelo tiene sus propias voces.';
$string['spt_secret'] = 'Secreto del token de permisos';
$string['spt_secret_help'] = 'Secreto usado para firmar los tokens de permisos del chat. Se genera automáticamente para este '
    . 'sitio y se guarda en Moodle, por lo que no hace falta configurarlo. Cambiarlo invalida los tokens ya emitidos: los usuarios '
    . 'deben recargar la página para seguir chateando.';
$string['sql_construct_rejected'] = 'La consulta usa una sintaxis fuera del subconjunto de solo lectura permitido, por lo que no '
    . 'se ejecutó. Escriba un único SELECT con FROM, [LEFT] JOIN ... ON, condiciones WHERE unidas solo con AND (=, <>, <, >, '
    . 'IN (números), IS [NOT] NULL, LIKE \'texto\'), GROUP BY, ORDER BY y LIMIT, y solo las funciones COUNT, SUM, AVG, MIN, '
    . 'MAX, ROUND y COALESCE. No use CASE, OR, NOT, subconsultas, UNION, comentarios, nombres entre comillas, variables ni '
    . 'punto y coma.';
$string['sql_course_rejected'] = 'La consulta menciona un curso que usted no gestiona, por lo que no se ejecutó.';
$string['sql_course_unfiltered_rejected'] = 'La consulta lee datos de cursos sin limitarlos a los cursos que usted gestiona, por '
    . 'lo que no se ejecutó. Limite cada tabla de cursos al id de uno de sus cursos (por ejemplo WHERE c.id = 12 o WHERE '
    . 'e.courseid IN (12, 15)) y no use OR, UNION, BETWEEN ni subconsultas.';
$string['sql_function_rejected'] = 'La consulta usa una función o cláusula que puede leer o escribir archivos del servidor, '
    . 'pausar o bloquear la base de datos, acceder a otras bases de datos o revelar variables del servidor (como LOAD_FILE, SLEEP, '
    . 'BENCHMARK, INTO OUTFILE o @@), por lo que no se ejecutó.';
$string['sql_sensitive_rejected'] = 'La consulta lee credenciales, claves, tokens, configuración del sitio o sesiones, por lo que no se ejecutó.';
$string['sql_table_not_allowed'] = 'La consulta lee una tabla que no está disponible para los cursos que usted gestiona (como '
    . 'mensajes, notificaciones, preferencias de usuario o la lista completa de usuarios), por lo que no se ejecutó. Lea tablas de '
    . 'cursos limitadas a sus cursos, y lea nombres de usuarios solo uniendo {user} a las matriculaciones, roles o calificaciones '
    . 'de uno de sus cursos (por ejemplo JOIN {user_enrolments} ue ON ue.userid = u.id JOIN {enrol} e ON e.id = ue.enrolid '
    . 'WHERE e.courseid = 12).';
$string['sql_user_column_rejected'] = 'La consulta lee columnas de usuario distintas del id y los nombres (como el correo, el '
    . 'teléfono, la dirección o la última IP), o todas las columnas con *, por lo que no se ejecutó. Indique solo u.id, '
    . 'u.firstname, u.lastname, u.middlename, u.alternatename, u.firstnamephonetic o u.lastnamephonetic.';
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
$string['task_skipped_plugin_disabled'] = 'Jedomi está deshabilitado en el proveedor de IA Datacurso; se omite la ejecución.';
$string['telegram_admin_not_configured_desc'] = 'Configura el bot de Telegram en los ajustes de Jedomi. Cuando esté configurado, los usuarios verán aquí la opción para vincular Telegram.';
$string['telegram_admin_not_configured_title'] = 'Telegram no está configurado (solo los administradores ven este mensaje)';
$string['telegram_admin_settings_link'] = 'Ajustes de Jedomi → Integraciones';
$string['telegram_bot_token'] = 'Token del bot';
$string['telegram_bot_token_help'] = 'Pegá el token de @BotFather. Se encripta antes de guardarlo.';
$string['telegram_bot_token_placeholder'] = 'Pega el token de @BotFather';
$string['telegram_bot_token_placeholder_saved'] = 'Guardado. Déjalo en blanco para conservarlo.';
$string['telegram_code_expired'] = 'Vencido';
$string['telegram_confirm_action'] = '⚠️ Esta acción modifica datos y solo se ejecuta si la confirmas: {$a}';
$string['telegram_connection_status'] = 'Estado de conexión';
$string['telegram_cooldown'] = 'Tiempo de espera';
$string['telegram_cooldown_help'] = 'Minutos para bloquear a un usuario después de exceder el límite.';
$string['telegram_drop_pending_failed'] = 'No se pudieron descartar las actualizaciones pendientes. Revisa la respuesta de la API de Telegram.';
$string['telegram_enabled'] = 'Habilitar integración con Telegram';
$string['telegram_enabled_save_failed'] = 'No se pudo guardar el interruptor de la integración con Telegram. Inténtalo de nuevo.';
$string['telegram_gateway_timeout'] = 'Tiempo máximo de respuesta de la IA (segundos)';
$string['telegram_gateway_timeout_help'] = 'Segundos máximos de espera de cada petición a la IA del bot de Telegram, enviada a '
    . 'través del proveedor de IA de Datacurso (5-300).';
$string['telegram_heading'] = 'Telegram';
$string['telegram_heading_help'] = 'Conectá Jedomi a un bot de Telegram para que los usuarios conversen vía chat.';
$string['telegram_help_footer'] = 'Después de vincular, enviá cualquier mensaje y lo voy a reenviar a Jedomi AI para obtener '
    . 'una respuesta.';
$string['telegram_help_heading'] = '🤖 <b>Bot Jedomi — Comandos disponibles</b>';
$string['telegram_help_help'] = '❓ <b>/help</b> — Mostrar este mensaje de ayuda';
$string['telegram_help_new'] = '🗑️ <b>/new</b> o <b>/nuevo</b> — Borrar conversación y empezar de nuevo';
$string['telegram_help_start'] = '🔗 <b>/start CÓDIGO</b> — Vinculá tu cuenta de Moodle con Telegram';
$string['telegram_help_unlink'] = '🚫 <b>/desvincular</b> o <b>/unlink</b> — Desvinculá tu Telegram de Moodle';
$string['telegram_howto_confirm'] = 'Las acciones peligrosas muestran botones de confirmación antes de ejecutarse.';
$string['telegram_howto_question'] = 'Envía cualquier pregunta y Jedomi responde con datos actuales de Moodle.';
$string['telegram_howto_screenshot'] = 'Envía una captura de un error y Jedomi la analiza (visión con IA).';
$string['telegram_howto_title'] = 'Cómo usar Jedomi desde Telegram';
$string['telegram_howto_unlink'] = 'Envía <code>/desvincular</code> para desvincular esta cuenta de Telegram.';
$string['telegram_keyboard_help'] = '❓ Ayuda';
$string['telegram_keyboard_howto_link'] = '🔗 ¿Cómo vincular?';
$string['telegram_keyboard_new'] = '📝 Nueva conversación';
$string['telegram_keyboard_unlink'] = '🚫 Desvincular';
$string['telegram_link_code_expiry'] = 'Este código vence en 10 minutos.';
$string['telegram_link_generate'] = 'Generar código';
$string['telegram_link_instructions'] = 'Abrí Telegram, buscá tu bot, y enviá /start seguido del código.';
$string['telegram_link_new_code'] = 'Generar un código nuevo';
$string['telegram_link_open_bot'] = 'Abrir @{$a} en Telegram';
$string['telegram_link_step1_desc'] = 'Toca el botón de abajo para abrir el bot directamente, o busca <strong>@{$a}</strong> en Telegram.';
$string['telegram_link_step1_title'] = 'Abre el bot en Telegram';
$string['telegram_link_step2_desc'] = 'Envía este comando a <strong>@{$a}</strong>:';
$string['telegram_link_step3_desc'] = 'El bot confirma la vinculación al instante. Después, envía cualquier mensaje para empezar a usar Jedomi desde Telegram.';
$string['telegram_link_step3_title'] = '¡Listo!';
$string['telegram_link_title'] = 'Vincular Telegram';
$string['telegram_linked_as'] = 'Vinculado como @{username}';
$string['telegram_linked_chatid'] = 'chat_id: {$a}';
$string['telegram_linked_on'] = 'Vinculado el {$a}';
$string['telegram_max_turns'] = 'Turnos máximos de conversación';
$string['telegram_max_turns_help'] = 'Cantidad de mensajes guardados en el historial de Telegram (1-100). Valores más bajos '
    . 'ahorran tokens.';
$string['telegram_msg_account_inactive'] = 'Tu cuenta de Moodle no está activa, por lo que el bot no puede atenderla. Contacta con el administrador del sitio.';
$string['telegram_msg_audio_disabled'] = 'Los mensajes de voz y audio no están habilitados en este sitio. Envíe su pregunta como texto.';
$string['telegram_msg_done'] = 'Listo.';
$string['telegram_msg_executing'] = 'Ejecutando...';
$string['telegram_msg_expired_code'] = 'Código inválido o vencido. Generá uno nuevo desde tu perfil de Moodle.';
$string['telegram_msg_file_unsupported'] = 'Solo se pueden leer archivos PDF, Word (DOC, DOCX), PowerPoint (PPT, PPTX), JPG, PNG y WEBP.';
$string['telegram_msg_files_disabled'] = 'Las fotos y los archivos no están habilitados en este sitio. Envíe su pregunta como texto.';
$string['telegram_msg_heard'] = '🎤 {$a}';
$string['telegram_msg_link_first'] = 'Primero vinculá tu cuenta: andá a tu perfil de Moodle y hacé clic en "Vincular Telegram".';
$string['telegram_msg_linked_success'] = '✅ Vinculado a Moodle como <b>{$a->fullname}</b> ({$a->username})';
$string['telegram_msg_max_iterations'] = '⚠️ Demasiados pasos. Simplificá tu consulta.';
$string['telegram_msg_new_conversation'] = '🗑️ Conversación borrada. ¡Empezá de nuevo!';
$string['telegram_msg_private_only'] = 'El bot solo funciona en un chat privado con cada usuario. Escríbele en un chat privado.';
$string['telegram_msg_provide_code'] = '🔗 Proporcione su código de vinculación.

Ejemplo: /start ABC123';
$string['telegram_msg_rate_check_failed'] = 'No se pudo procesar tu mensaje en este momento. Envíalo de nuevo en unos instantes.';
$string['telegram_msg_rate_limited'] = 'Demasiados mensajes. Espere {$a->minutes} minutos.';
$string['telegram_msg_role_blocked'] = 'Tu rol no tiene acceso a Jedomi Telegram.';
$string['telegram_msg_text_only'] = 'Solo puedo leer texto, fotos, documentos y mensajes de voz. Envíe su pregunta como texto.';
$string['telegram_msg_timeout'] = '⏳ Esta tarea es muy compleja para el límite de tiempo. Dividila en pasos más chicos o '
    . 'enviá el mismo mensaje de nuevo y sigo desde donde quedé.';
$string['telegram_msg_token_invalid'] = 'Token de bot inválido. Verificá el token de @BotFather e intentá de nuevo.';
$string['telegram_msg_unlinked'] = 'Desvinculado. Enviá /start para volver a vincular.';
$string['telegram_no_app_desc'] = 'Si estás en una computadora sin la app, abre Telegram en tu teléfono, busca <strong>@{$a}</strong> y envía este mensaje:';
$string['telegram_no_app_title'] = '¿No tienes la app de Telegram?';
$string['telegram_not_linked'] = 'Sin vincular';
$string['telegram_open_auto'] = 'Abrir en Telegram (automático)';
$string['telegram_open_auto_help'] = 'Te lleva directo a @{$a} con tu código listo para vincular.';
$string['telegram_progress'] = '🤔 Jedomi está pensando…';
$string['telegram_quick_step1_desc'] = 'aparece un código de 6 caracteres, válido por 10 minutos.';
$string['telegram_quick_step1_title'] = 'Haz clic en "Generar código" abajo';
$string['telegram_quick_step2_desc'] = 'envía <code>/start TU_CODIGO</code>.';
$string['telegram_quick_step2_title'] = 'Abre en Telegram:';
$string['telegram_quick_step3_desc'] = '{$a} confirma al instante. Pregunta lo que quieras sobre tu Moodle.';
$string['telegram_quick_step3_title'] = '¡Eso es todo!';
$string['telegram_rate_limit'] = 'Límite de mensajes';
$string['telegram_rate_limit_help'] = 'Máximo de mensajes por minuto que un usuario puede enviar. 0 = ilimitado.';
$string['telegram_regenerate_confirm'] = 'La URL del webhook cambia y se vuelve a registrar en Telegram. ¿Continuar?';
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
$string['telegram_status_invalid_token'] = 'token no válido, pégalo de nuevo y guarda.';
$string['telegram_status_pending'] = 'Pendiente — guardá el token del bot para registrar el webhook';
$string['telegram_step1_desc'] = 'Abrí Telegram, buscá <strong>@BotFather</strong>, enviá <code>/newbot</code>, elegí nombre '
    . 'y usuario. Copiá el token que te da (formato: <code>123456:ABC-DEF...</code>).';
$string['telegram_step1_title'] = 'Crear un bot en @BotFather';
$string['telegram_step2_desc'] = 'Pegá el token en el campo <strong>Token del bot</strong> abajo, activá el toggle de '
    . 'integración y hacé clic en <strong>Guardar cambios</strong>.';
$string['telegram_step2_title'] = 'Pegar el token y guardar';
$string['telegram_step3_desc'] = 'No hace falta hacer nada: el plugin registra la <strong>URL del webhook</strong> en Telegram '
    . 'automáticamente al guardar el token del bot. Si cambia la URL del sitio, guardá el token del bot de nuevo para registrar '
    . 'la nueva URL.';
$string['telegram_step3_note'] = 'Telegram solo entrega mensajes a una URL pública HTTPS. Para desarrollo local, exponé el sitio '
    . 'con un túnel como ngrok y usá esa URL como URL del sitio.';
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
$string['telegram_token_not_configured'] = 'El token del bot no está configurado.';
$string['telegram_webhook_auto_missing_desc'] = 'Intenta guardar el token de nuevo.';
$string['telegram_webhook_auto_missing_title'] = 'Webhook no registrado';
$string['telegram_webhook_auto_ok_desc'] = 'Al guardar el token, el webhook se registró automáticamente.';
$string['telegram_webhook_auto_ok_title'] = 'Webhook configurado automáticamente';
$string['telegram_webhook_info_failed'] = 'getWebhookInfo falló. Revisa el token del bot y el acceso HTTPS saliente.';
$string['telegram_webhook_secret'] = 'Secreto del webhook';
$string['telegram_webhook_secret_help'] = 'Secreto aleatorio que se agrega a la URL del webhook por seguridad. Regeneralo si se '
    . 'filtra.';
$string['telegram_webhook_status'] = 'Estado del Webhook';
$string['telegram_webhook_url'] = 'URL del webhook';
$string['telegram_webhook_url_help'] = 'Se registra en Telegram automáticamente al guardar el token del bot. Si cambia la URL '
    . 'del sitio, guardá el token del bot de nuevo para registrar la nueva URL.';
$string['thinking'] = 'Pensando...';
$string['toggle_thinking'] = 'Mostrar/ocultar animación de pensamiento';
$string['token_expired'] = 'Los permisos del chat vencieron. Recargue la página para seguir usando Jedomi.';
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
$string['tool_document_read_desc'] = 'Leer el PDF publicado en una actividad del curso (su descripción o contenido, nunca entregas ni '
    . 'devoluciones de estudiantes) usando su cmid (course module ID). Devuelve el texto de las '
    . 'páginas; las páginas escaneadas, tablas y diagramas los lee un modelo de visión. Úsalo cuando el usuario pida leer, '
    . 'analizar, resumir o explicar un documento de una actividad.';
$string['tool_document_read_pages'] = 'Páginas opcionales a leer, por ejemplo "1,3-5". Omítelo para leer todo el documento.';
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
$string['tool_my_assignments_desc'] = 'Solo lectura. Las tareas de los cursos del usuario actual, con sus fechas de apertura, '
    . 'entrega y cierre y el estado de la entrega, y los próximos vencimientos del calendario del usuario. Úsela cuando un '
    . 'estudiante pregunte por sus tareas o fechas de entrega.';
$string['tool_my_courses_desc'] = 'Solo lectura. Los cursos en los que el usuario actual tiene una matrícula activa. Úsela cuando '
    . 'un estudiante pregunte por sus cursos.';
$string['tool_my_grades_desc'] = 'Solo lectura. Las calificaciones que el usuario actual puede ver en sus cursos (no incluye '
    . 'calificaciones ocultas ni sin publicar). Úsela cuando un estudiante pregunte por sus calificaciones.';
$string['tool_own_data_courseid'] = 'Opcional: id de uno de los cursos del usuario, para leer solo ese curso.';
$string['tool_schema_lookup'] = 'Buscando tabla...';
$string['tool_search_tables'] = 'Buscando tablas...';
$string['tool_search_tables_desc'] = 'Busca tablas de Moodle por intención o palabras clave en INGLÉS. Busca en nombres de '
    . 'tablas Y sus descripciones COMMENT de install.xml. Devuelve los resultados más relevantes con nombre, descripción, '
    . 'nombres de campos y comentarios de campos. Usá SIEMPRE search_tables antes de describe_table o execute_sql.';
$string['tool_search_tables_limit'] = 'Máximo de resultados (default: 8, max: 15)';
$string['tool_search_tables_query'] = 'Intención o palabras clave en INGLÉS (ej: "user grades", "course enrolment", "student")';
$string['tool_send_messages_desc'] = 'Envía un mensaje interno de Moodle a uno o más usuarios. '
    . 'Solo cuando el usuario pide explícitamente enviar o notificar un mensaje; nunca para responder una pregunta '
    . 'o analizar datos. Primero busca los ids de usuario '
    . '(ws_search/ws_execute o execute_sql). El usuario confirma antes del envío: llama a esta herramienta una sola vez '
    . 'y no pidas confirmación en el texto.';
$string['tool_send_messages_message'] = 'Texto plano del mensaje, en el idioma del usuario.';
$string['tool_send_messages_user_ids'] = 'Ids de usuario de Moodle de los destinatarios.';
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
$string['vision_model'] = 'Modelo de archivos e imágenes';
$string['vision_model_help'] = 'Modelo que lee las imágenes y las páginas de los PDF sin texto (páginas escaneadas). Las páginas con texto se leen sin él. En las pruebas, Qwen3-VL 30B A3B es el más rápido y económico.';
$string['voice_cancel'] = 'Cancelar grabación';
$string['voice_conversation'] = 'Conversación por voz';
$string['voice_delete'] = 'Eliminar mensaje de voz';
$string['voice_enabled'] = 'Habilitar la conversación por voz';
$string['voice_enabled_help'] = 'Agrega al chat web un botón de conversación por voz: el usuario habla, Jedomi lo transcribe con el modelo de audio, responde y lee la respuesta en voz alta con el modelo de voz, y vuelve a escuchar. Necesita el procesamiento de audio. Leer en voz alta consume créditos por carácter; las acciones que modifican datos siguen pidiendo la confirmación en pantalla.';
$string['voice_input'] = 'Dictar (voz)';
$string['voice_message'] = '🎤 {$a}';
$string['voice_mode'] = 'Conversación por voz';
$string['voice_mode_confirm_notice'] = 'Confirme la acción en pantalla, por favor.';
$string['voice_mode_exit'] = 'Terminar la conversación por voz';
$string['voice_mode_listening'] = 'Escuchando...';
$string['voice_mode_paused'] = 'Esperando la confirmación en pantalla';
$string['voice_mode_speaking'] = 'Hablando...';
$string['voice_mode_speech_error'] = 'No se pudo leer la respuesta en voz alta; se muestra en el chat.';
$string['voice_mode_thinking'] = 'Pensando...';
$string['voice_mode_transcription_error'] = 'No se pudo entender lo que dijo. Hable de nuevo, por favor.';
$string['voice_pause'] = 'Pausar mensaje de voz';
$string['voice_play'] = 'Reproducir mensaje de voz';
$string['voice_recording'] = 'Grabando...';
$string['voice_send'] = 'Enviar mensaje de voz';
$string['voice_stop'] = 'Detener grabación';
$string['waiting_voice'] = 'Esperando voz...';
$string['webhook_checking'] = 'Comprobando...';
$string['webhook_error'] = 'Último error del webhook:';
$string['webhook_error_alert_desc'] = 'El token del bot se guardó correctamente, pero no se pudo registrar el webhook en '
    . 'Telegram.';
$string['webhook_error_alert_title'] = 'Problema con el registro del webhook';
$string['webhook_error_detail'] = 'Detalles del error:';
$string['webhook_live_api_error'] = 'Error de la API';
$string['webhook_live_healthy'] = 'El webhook funciona correctamente.';
$string['webhook_live_last_registration'] = 'Resultado del último registro:';
$string['webhook_live_network_error'] = 'Error de red';
$string['webhook_live_pending'] = 'Actualizaciones pendientes:';
$string['webhook_live_telegram_error'] = 'Error de Telegram:';
$string['webhook_live_unknown_error'] = 'Error desconocido';
$string['webhook_live_url'] = 'URL del webhook:';
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
