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
$string['agent_approve_writes_owner_only'] = 'Solo el propietario del agente puede aprobar escrituras para él. Puede mantener o quitar las escrituras aprobadas, pero no agregar nuevas.';
$string['agent_approved_writes'] = 'Escrituras que se ejecutan sin confirmación';
$string['agent_approved_writes_empty'] = 'Ninguna: todas las escrituras esperan la aprobación del creador';
$string['agent_approved_writes_help'] = 'Funciones de servicio web que modifican datos y que este agente puede ejecutar sin su confirmación, solo dentro de su curso o contexto y solo para usted y los usuarios de ese contexto. Cualquier otra escritura espera su aprobación en el asistente de Jedomi. Cada ejecución hace como máximo {$a->writes} escrituras aprobadas a como máximo {$a->recipients} usuarios distintos; si se supera, la ejecución se detiene.';
$string['agent_approved_writes_none'] = 'No hay funciones de escritura para aprobar: guarde una lista de servicios web con funciones de escritura para su rol en la pestaña Servicios web.';
$string['agent_approved_writes_notice'] = 'Desde la versión 1.6.1 un agente programado solo ejecuta las escrituras aprobadas en su formulario, dentro de su curso o contexto. Los agentes existentes no tienen escrituras aprobadas: sus escrituras esperan la aprobación de su creador, que recibe una notificación.';
$string['agent_by_role'] = 'Agentes por rol';
$string['agent_by_status'] = 'Agentes por estado';
$string['agent_chart_no_data'] = 'Sin datos';
$string['agent_context'] = 'Contexto';
$string['agent_context_id'] = 'ID del contexto';
$string['agent_context_id_help'] = 'Identificador del contexto de Moodle, no el del curso, la categoría o el usuario: por '
    . 'ejemplo, el número de contexto que aparece en la dirección de la página Permisos del curso.';
$string['agent_context_id_placeholder'] = 'p. ej. 42';
$string['agent_context_label'] = '{$a->type}: {$a->name}';
$string['agent_context_missing'] = '{$a->type} #{$a->id} (no encontrado)';
$string['agent_context_type_category'] = 'Categoría';
$string['agent_context_type_course'] = 'Curso';
$string['agent_context_type_site'] = 'Sitio';
$string['agent_context_type_user'] = 'Usuario';
$string['agent_create'] = 'Crear agente';
$string['agent_created_message'] = '✅ Agente «{$a}» creado.';
$string['agent_created_no_name'] = 'Agente creado sin nombre. Edite el agente para agregarle un nombre.';
$string['agent_created_with_name'] = 'Agente "{$a}" creado correctamente.';
$string['agent_creator'] = 'Creador';
$string['agent_cron_custom'] = 'Personalizada: {$a}';
$string['agent_cron_daily_at'] = 'Cada día a las {$a}';
$string['agent_cron_hourly_at'] = 'Cada hora en el minuto {$a}';
$string['agent_cron_monthly_at'] = 'Cada mes el día {$a->day} a las {$a->time}';
$string['agent_cron_weekly_at'] = 'Cada {$a->day} a las {$a->time}';
$string['agent_custom_cron'] = 'Personalizado (expresión cron)';
$string['agent_daily'] = 'Diario';
$string['agent_delete'] = 'Eliminar';
$string['agent_delete_confirm'] = '¿Eliminar este agente? Esta acción no se puede deshacer.';
$string['agent_deleted_message'] = 'Agente n.º {$a} eliminado.';
$string['agent_detail_already_done'] = 'Esta acción no se ejecutó de nuevo: un intento anterior de esta ejecución programada ya la completó correctamente. No la repita; continúe con el resto de la instrucción.';
$string['agent_detail_held'] = 'Esta acción no se ejecutó: espera la aprobación del creador del agente. No la repita; continúe con el resto de la instrucción.';
$string['agent_detail_out_of_scope'] = 'Esta acción no se ejecutó: sus argumentos están fuera del curso o contexto del agente o de sus usuarios permitidos.';
$string['agent_disable'] = 'Desactivar';
$string['agent_disabled'] = 'Desactivado';
$string['agent_disabled_notice'] = 'Agente desactivado';
$string['agent_disabled_reason_creator_deleted'] = 'El creador del agente fue eliminado.';
$string['agent_disabled_reason_creator_missing'] = 'El creador del agente ya no existe.';
$string['agent_disabled_reason_creator_suspended'] = 'El creador del agente está suspendido.';
$string['agent_disabled_reason_failures'] = 'El agente falló {$a} veces seguidas y se desactivó. Revíselo y vuelva a activarlo.';
$string['agent_disabled_reason_no_access'] = 'El creador ya no tiene acceso al contexto del agente.';
$string['agent_edit'] = 'Editar';
$string['agent_enable'] = 'Activar';
$string['agent_enabled'] = 'Activo';
$string['agent_enabled_help'] = 'Los agentes activos se ejecutan según su programación. Un agente desactivado no se ejecuta, ni '
    . 'siquiera con Ejecutar ahora.';
$string['agent_error_create'] = 'Error al crear agente: {$a}';
$string['agent_error_empty_answer'] = 'El servicio de IA devolvió una respuesta vacía.';
$string['agent_error_load'] = 'Error al cargar agentes.';
$string['agent_error_nopermission'] = 'No puede gestionar los agentes de usuarios con más permisos que los suyos.';
$string['agent_error_run_failed'] = 'La ejecución falló por un error inesperado (referencia {$a}). Los detalles están en el '
    . 'registro técnico de Jedomi.';
$string['agent_error_update'] = 'Error al actualizar agente: {$a}';
$string['agent_every_15min'] = 'Cada 15 minutos';
$string['agent_every_1h'] = 'Cada hora';
$string['agent_every_30min'] = 'Cada 30 minutos';
$string['agent_every_5min'] = 'Cada 5 minutos';
$string['agent_every_6h'] = 'Cada 6 horas';
$string['agent_executions_30d'] = 'Ejecuciones (30 días)';
$string['agent_failing'] = 'Fallando';
$string['agent_failing_count'] = '{$a} ejecuciones fallidas seguidas: se reintenta más tarde y se desactiva si sigue fallando.';
$string['agent_filter_context'] = 'Contexto';
$string['agent_filter_role'] = 'Rol';
$string['agent_filter_status'] = 'Estado';
$string['agent_invalid'] = '{$a}';
$string['agent_invalid_approved_write'] = 'la función de escritura "{$a}" no se puede aprobar para este agente: no es una función de escritura de la lista de servicios web del rol de su propietario';
$string['agent_invalid_context_excluded'] = 'el curso de este contexto está excluido de Jedomi en la pestaña Disponibilidad por curso';
$string['agent_invalid_context_id'] = 'el ID de contexto no corresponde a un contexto de Moodle existente de tipo {$a}';
$string['agent_invalid_context_type'] = 'tipo de contexto no válido';
$string['agent_invalid_cron'] = 'programación personalizada no válida: use cinco campos (minuto, hora, día del mes, mes, día de '
    . 'la semana) con valores dentro de su rango, por ejemplo "30 6 * * 1-5"';
$string['agent_invalid_prompt'] = 'la instrucción no puede estar vacía';
$string['agent_lasterror'] = 'Último error';
$string['agent_lasterror_hidden'] = 'La última ejecución falló.';
$string['agent_lastresult'] = 'Último resultado';
$string['agent_lastresult_none'] = 'El agente todavía no ha producido un resultado.';
$string['agent_lastrun'] = 'Última ejecución';
$string['agent_name'] = 'Nombre';
$string['agent_name_placeholder'] = 'ej. Reporte diario';
$string['agent_nextrun'] = 'Próxima ejecución';
$string['agent_not_found'] = 'Agente no encontrado.';
$string['agent_notice_success'] = 'Ejecutado correctamente';
$string['agent_number'] = 'Agente #{$a}';
$string['agent_preview'] = 'Vista previa';
$string['agent_preview_arguments'] = 'Argumentos';
$string['agent_preview_cached'] = 'Resultado en caché del';
$string['agent_preview_clear_cache'] = 'Borrar caché';
$string['agent_preview_error'] = 'La vista previa falló. Revise la instrucción e inténtelo de nuevo.';
$string['agent_preview_prompt_required'] = 'Primero escriba una instrucción.';
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
$string['agent_prompt'] = 'Instrucción';
$string['agent_prompt_hidden'] = 'Oculta: solo su creador, los administradores del sitio y los usuarios con un rol superior pueden ver esta instrucción.';
$string['agent_prompt_placeholder'] = 'Ej: "Resuma la actividad nueva del sitio y notifíqueme"';
$string['agent_purge'] = 'Eliminar agentes';
$string['agent_purge_btn'] = 'Eliminar agentes del contexto';
$string['agent_purge_confirm'] = 'Esto va a eliminar todos los agentes que coincidan y sus resultados cacheados. ¿Continuar?';
$string['agent_purge_context'] = 'Tipo de contexto';
$string['agent_purge_context_help'] = 'Eliminar todos los agentes de un contexto específico.';
$string['agent_purge_id_required'] = 'Introduzca el ID del contexto de Moodle del curso, la categoría o el usuario a purgar.';
$string['agent_purge_invalid_type'] = 'Tipo de purga no válido: {$a}';
$string['agent_purge_role_not_found'] = 'No se encontró el rol.';
$string['agent_purge_success'] = '{$a} agente(s) eliminados correctamente.';
$string['agent_roles_help'] = 'El agente solo se ejecuta mientras su creador tenga al menos uno de los roles marcados en el '
    . 'contexto del agente. Deje todas las casillas sin marcar para que se ejecute siempre.';
$string['agent_run_disabled'] = 'Este agente está desactivado, por eso no se puso en cola. Actívelo para ejecutarlo.';
$string['agent_run_now'] = 'Ejecutar ahora';
$string['agent_run_queued'] = 'Agente encolado para ejecución (próximo ciclo de cron).';
$string['agent_runner_task'] = 'Jedomi — Ejecutar agentes de IA programados';
$string['agent_schedule'] = 'Programación';
$string['agent_skipped_course_excluded'] = 'No se ejecutó: el curso de su contexto está excluido de Jedomi. Volverá a '
    . 'ejecutarse cuando el curso vuelva a estar en Jedomi.';
$string['agent_skipped_role'] = 'No se ejecutó: el creador no tiene ninguno de los roles elegidos para el agente en su '
    . 'contexto.';
$string['agent_status'] = 'Estado';
$string['agent_summary'] = 'Resumen';
$string['agent_table'] = 'Todos los agentes';
$string['agent_unnamed'] = 'Agente';
$string['agent_update_success'] = 'Agente actualizado correctamente.';
$string['agent_user_id'] = 'Usuario #{$a}';
$string['agent_view_details'] = 'Ver detalles';
$string['agent_weekly'] = 'Semanal';
$string['agent_write_cap_reached'] = 'La ejecución se detuvo: alcanzó el límite de {$a->writes} escrituras aprobadas o {$a->recipients} destinatarios distintos por ejecución.';
$string['agent_writes_blocked_heading'] = 'Escrituras rechazadas por estar fuera del alcance del agente: {$a}';
$string['agent_writes_done_heading'] = 'Escrituras aprobadas ejecutadas sin confirmación: {$a}';
$string['agent_writes_failed'] = 'fallida, {$a} destinatarios';
$string['agent_writes_held_heading'] = 'Escrituras que esperan su aprobación: {$a}';
$string['agent_writes_held_help'] = 'Abra el asistente de Jedomi para aprobarlas o rechazarlas. Vencen a los 7 días.';
$string['agent_writes_ok'] = 'realizada, {$a} destinatarios';
$string['agent_writes_open_chat'] = 'Abrir el asistente de Jedomi';
$string['agent_writes_stopped'] = 'La ejecución se detuvo porque alcanzó el límite de escrituras o de destinatarios de una ejecución.';
$string['agent_writes_subject'] = 'Agente de Jedomi "{$a}": escrituras de la última ejecución';
$string['agents_menu_link'] = 'Agentes de Jedomi';
$string['ai_disclaimer'] = '{$a} es una IA y puede cometer errores.';
$string['ai_error_connection'] = 'No se pudo conectar con Jedomi. Revise su conexión e inténtelo de nuevo.';
$string['ai_error_generic'] = 'Jedomi no pudo responder esta vez. Inténtelo de nuevo en un momento.';
$string['ai_error_invalidanswer'] = 'El servicio de IA devolvió una respuesta que no se pudo leer.';
$string['ai_error_license'] = 'La licencia de IA de este sitio falta o no es válida. Póngase en contacto con el administrador del sitio.';
$string['ai_error_nocredit'] = 'Su organización no tiene saldo de IA. Póngase en contacto con el administrador del sitio para recargarlo.';
$string['ai_error_quota'] = 'El servicio de IA alcanzó su límite de uso. Póngase en contacto con el administrador del sitio.';
$string['ai_error_ratelimit'] = 'Ha alcanzado el límite de uso de IA por ahora. Puede volver a intentarlo después de {$a}.';
$string['ai_error_ratelimit_noretry'] = 'Ha alcanzado el límite de uso de IA por ahora. Inténtelo de nuevo más tarde.';
$string['ai_error_timeout'] = 'El asistente tardó demasiado en responder. Inténtelo de nuevo con una solicitud más breve.';
$string['ai_error_tools_unsupported'] = 'El modelo del servicio de IA personalizado no admite llamadas a herramientas, que Jedomi necesita para responder. Solicite al administrador del sitio que elija un modelo que las admita.';
$string['ai_error_unavailable'] = 'El servicio de IA no está disponible en este momento. Inténtelo de nuevo en unos minutos.';
$string['ai_heading'] = 'Servicio de IA';
$string['ai_heading_help'] = 'Jedomi envía sus solicitudes de IA a través del proveedor de IA de Datacurso, con su clave de licencia y sus límites de uso.';
$string['ai_service'] = 'Servicio de IA';
$string['ai_service_help'] = 'Servicio Datacurso: Jedomi usa el servicio de IA de Datacurso con los modelos de abajo, con los créditos y límites de la licencia de Datacurso.<br>Servicio personalizado: Jedomi llama directamente a un servicio compatible con OpenAI de su elección (DeepInfra, OpenAI, un modelo local como Ollama, LM Studio o vLLM). Las llamadas no usan créditos de Datacurso ni se registran en el historial de consumo. Las imágenes las lee el modelo de imágenes. Los archivos PDF y de texto (.txt, .csv, .md) solo se leen con «Documentos» activado y un servicio de documentos, que convierte las páginas PDF en imágenes para el modelo de imágenes; los archivos de Word y PowerPoint no se pueden leer. Los datos personales se anonimizan igual que con el servicio de Datacurso.<br>Para desactivar Jedomi, desmarque «Habilitado».';
$string['ai_timeout'] = 'Tiempo de espera de la IA (segundos)';
$string['ai_timeout_help'] = 'Cuánto espera Jedomi cada respuesta del servicio de IA en el chat web, con el servicio de Datacurso y con el servicio personalizado, de 10 a 600 segundos (valor predeterminado: 180). Telegram y los agentes programados conservan sus propios tiempos de espera. Un proxy delante del sitio puede cerrar la petición antes (Cloudflare la cierra a los 100 segundos), igual que el max_execution_time de PHP o el tiempo de espera del servidor web: auméntelos también o mantenga este valor por debajo de ellos.';
$string['analyze_file'] = 'Resuma este archivo.';
$string['analyze_image'] = 'Analice esta imagen.';
$string['anon_basic'] = 'Básico (nombres, correos electrónicos e identificadores)';
$string['anon_basic_desc'] = 'Reemplaza nombres, correos e identificadores (nombre de usuario, número de identificación, teléfonos, dirección, dirección IP, cuentas de mensajería, URL de la página web). Conserva la mayor parte del contexto.';
$string['anon_feedback_only'] = 'Solo comentarios (no se guarda el texto de conversaciones)';
$string['anon_feedback_only_desc'] = 'Solo guarda calificaciones y botones de comentarios. Sin texto de conversación.';
$string['anon_strict'] = 'Estricto (nombres, correos electrónicos, identificadores, texto libre y códigos)';
$string['anon_strict_desc'] = 'Reemplaza lo mismo que Básico (nombres, correos e identificadores) y, además, el texto libre largo y los códigos que mezclan letras y números dentro de los textos. Máxima privacidad.';
$string['anonymization_level'] = 'Nivel de anonimización';
$string['anonymization_level_help'] = 'Los datos personales se reemplazan por marcadores antes de llegar al servicio de IA: los mensajes del usuario, el texto de los archivos, imágenes y audios adjuntos, el contexto de la página, los mensajes anteriores de la conversación y los resultados de las herramientas, en el chat web, Telegram, los agentes programados y la vista previa de agentes. El usuario sigue viendo los datos reales en la respuesta.<br>Básico: nombres de los usuarios del sitio, direcciones de correo electrónico e identificadores (nombre de usuario, número de identificación, teléfono, dirección, dirección IP, cuentas de mensajería, página web).<br>Estricto: además, textos libres largos y códigos que mezclan letras y números dentro de los textos.<br>Solo comentarios: la IA recibe lo mismo que con Básico, y las valoraciones se guardan sin el texto de la conversación.';
$string['answer_stopped'] = 'Respuesta detenida.';
$string['api_role_admin'] = 'Administrador';
$string['api_role_manager'] = 'Gestor';
$string['api_role_student'] = 'Estudiante';
$string['api_role_teacher'] = 'Profesor';
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
$string['audio_error_too_short'] = 'El audio es demasiado corto para transcribirlo. Grabe al menos una frase completa.';
$string['audio_error_unreadable'] = 'No se pudo entender el audio. Inténtelo de nuevo, hablando más cerca del micrófono.';
$string['audio_error_unsupported'] = 'Este formato de audio no es compatible. Use MP3, WAV, M4A, OGG o WEBM.';
$string['audio_file'] = 'Audio';
$string['audio_model'] = 'Modelo de audio';
$string['audio_model_help'] = 'Modelo que transcribe el audio. El predeterminado, Whisper large-v3 turbo, es rápido; en las pruebas, Qwen3-ASR 1.7B es el que mejor escribe nombres y tildes.';
$string['audio_processing'] = 'Habilitar procesamiento de audio';
$string['cachedef_chat_rate'] = 'Mensajes del chat web por usuario y minuto';
$string['cachedef_companion_signatures'] = 'Firmas ya usadas de los servicios web complementarios de Jedomi';
$string['cachedef_speech_rate'] = 'Pedidos de la conversación por voz por usuario y minuto';
$string['cachedef_telegram_updates'] = 'Actualizaciones de Telegram ya recibidas, por bot';
$string['cachedef_verify_rate'] = 'Verificaciones del servicio de IA personalizado por administrador y minuto';
$string['cancel'] = 'Cancelar';
$string['cancelled'] = 'Cancelado.';
$string['chat_error_course_access'] = 'No puede usar Jedomi en este curso.';
$string['chat_error_course_mismatch'] = 'El curso de la solicitud no es el curso de la página. Vuelva a cargar la página e inténtelo de nuevo.';
$string['chat_error_course_unavailable'] = 'Jedomi no está disponible en este curso.';
$string['chat_error_guest'] = 'Jedomi solo está disponible para usuarios que inician sesión con su propia cuenta.';
$string['chat_error_rate_limit_busy'] = 'Su solicitud anterior todavía se está procesando. Intente de nuevo en un momento.';
$string['chat_error_rate_limited'] = 'Ha enviado demasiadas preguntas en poco tiempo. Espere {$a} segundos antes de enviar otra.';
$string['chat_error_request_too_large'] = 'El mensaje es demasiado grande para enviarlo. Quite algunos archivos adjuntos o inicie una nueva conversación.';
$string['chat_error_role_hidden'] = 'Jedomi no está disponible para su rol.';
$string['chat_limit_heading'] = 'Uso del chat web';
$string['chat_limit_heading_help'] = 'Límites que protegen los créditos de IA y el servidor de un usuario que envía demasiadas preguntas.';
$string['chat_placeholder'] = 'Pregunte a {$a}...';
$string['chat_rate_limit'] = 'Mensajes por usuario y minuto (chat web)';
$string['chat_rate_limit_help'] = 'Máximo de solicitudes que un usuario puede hacer desde el panel del chat en un minuto, de 0 a 120 (valor predeterminado: 10; 0 significa sin límite). Cada pregunta cuenta una, y también cada archivo, imagen o mensaje de voz adjunto que se lee o se transcribe, porque cada uno es una solicitud a la IA aparte: una pregunta con dos archivos adjuntos cuenta tres. Las solicitudes rechazadas porque el usuario no puede usar Jedomi en ese lugar no cuentan. Se aplica con el servicio de IA de Datacurso y con el servicio de IA personalizado. Al usuario que supera el límite se le indica cuántos segundos debe esperar y no se hace ninguna solicitud a la IA. Los administradores del sitio nunca tienen límite. Telegram conserva su propio límite, y los agentes programados y el servicio web complementario no se ven afectados.';
$string['chat_rate_limit_invalid'] = 'Escriba un número entero entre 0 y 120.';
$string['check_webhook'] = 'Verificar webhook';
$string['cleanup_telegram_sessions'] = 'Jedomi — Limpiar sesiones de Telegram vencidas';
$string['close'] = 'Cerrar (Esc)';
$string['component_all'] = 'Todos los componentes';
$string['config_key_blocked_msg'] = 'Esta clave de configuración está bloqueada por seguridad.';
$string['config_load_error'] = 'No se pudo cargar el panel de Jedomi. Inténtelo de nuevo o, si el problema continúa, comuníquese con el administrador del sitio.';
$string['config_load_retry'] = 'Reintentar';
$string['config_updated_msg'] = 'Config \'{$a}\' actualizada correctamente.';
$string['confirm_btn_no'] = 'Cancelar';
$string['confirm_btn_yes'] = 'Confirmar';
$string['confirm_cancelled'] = 'Acción cancelada.';
$string['confirm_done'] = 'Acción ejecutada correctamente.';
$string['confirm_executing'] = 'Ejecutando...';
$string['confirm_keyword_required'] = 'Escriba la palabra de confirmación para ejecutar esta acción.';
$string['confirm_send_bulk'] = '¿Enviar este mensaje a {count} destinatario(s)?';
$string['confirm_type_to_proceed'] = 'Escriba CONFIRMAR para proceder:';
$string['connection_error'] = 'Error de conexión:';
$string['conversation_cleared'] = 'Conversación borrada.';
$string['copytoclipboard'] = 'Copiar al portapapeles';
$string['course_availability_courses'] = 'Cursos seleccionados';
$string['course_availability_courses_help'] = 'Cursos que están en Jedomi («Solo los cursos seleccionados») o fuera de '
    . 'Jedomi («Todos los cursos excepto los seleccionados»). Un curso excluido queda fuera de Jedomi, no solo sin el panel: '
    . 'no le da el rol de profesor en la página principal, en Telegram ni en los agentes, las herramientas de profesor, las '
    . 'llamadas a servicios web y el SQL de profesor nunca lo devuelven ni lo aceptan, y sus agentes no se '
    . 'ejecutan. El SQL de administrador de todo el sitio no se limita con esta lista. Busque por nombre o nombre corto del curso.';
$string['course_availability_heading'] = 'Disponibilidad por curso';
$string['course_availability_heading_help'] = 'Controle qué cursos están en Jedomi. Un curso excluido queda fuera de Jedomi en '
    . 'todos los canales: el chat web, Telegram y los agentes.';
$string['course_mode_all'] = 'Todos los cursos (por defecto)';
$string['course_mode_all_desc'] = 'Todos los cursos están en Jedomi.';
$string['course_mode_exclude'] = 'Todos los cursos excepto los seleccionados';
$string['course_mode_exclude_desc'] = 'Los cursos que elija abajo quedan fuera de Jedomi: sin panel, y sin rol de profesor, '
    . 'herramientas, SQL ni agentes para ellos.';
$string['course_mode_include'] = 'Solo los cursos seleccionados';
$string['course_mode_include_desc'] = 'Todo curso que no elija abajo queda fuera de Jedomi: sin panel, y sin rol de profesor, '
    . 'herramientas, SQL ni agentes para él. Las páginas que no pertenecen a un curso (área personal, perfil, calendario, '
    . 'administración del sitio) mantienen el panel.';
$string['course_none_selected'] = 'Todavía no hay cursos seleccionados.';
$string['course_search_placeholder'] = 'Buscar por nombre o nombre corto...';
$string['csv_download'] = '📥 Descargar CSV';
$string['ctx_activity'] = 'Actividad';
$string['ctx_admin'] = 'Administración';
$string['ctx_calendar'] = 'Calendario';
$string['ctx_course'] = 'Curso';
$string['ctx_dashboard'] = 'Área personal';
$string['ctx_files'] = 'Archivos';
$string['ctx_grades'] = 'Calificaciones';
$string['ctx_loc_activity'] = 'Actualmente en una actividad de tipo';
$string['ctx_loc_activity_suffix'] = '".';
$string['ctx_loc_admin'] = 'Actualmente en el panel de administración de Moodle.';
$string['ctx_loc_calendar'] = 'Viendo actualmente el calendario.';
$string['ctx_loc_course'] = 'Viendo actualmente un curso';
$string['ctx_loc_course_id'] = ' (ID: {$a}).';
$string['ctx_loc_dashboard'] = 'Actualmente en el Área personal de Moodle.';
$string['ctx_loc_files'] = 'Actualmente en el área de archivos.';
$string['ctx_loc_gradebook'] = 'Viendo actualmente el libro de calificaciones.';
$string['ctx_loc_messages'] = 'Actualmente en la sección de mensajes.';
$string['ctx_loc_profile'] = 'Viendo actualmente un perfil de usuario.';
$string['ctx_messages'] = 'Mensajes';
$string['ctx_profile'] = 'Perfil';
$string['custom_allow_http'] = 'Permitir HTTP para modelos locales';
$string['custom_allow_http_help'] = 'Permite HTTP sin cifrar, y omite los hosts bloqueados y los puertos permitidos del sitio, solo para un modelo de este servidor o de la red local (localhost, 127.0.0.1, 10.x, 172.16-31.x, 192.168.x), como Ollama, LM Studio o vLLM. Cualquier otra dirección necesita HTTPS. Desactivado por defecto.';
$string['custom_api_key'] = 'Clave de API';
$string['custom_api_key_help'] = 'Clave del servicio, enviada como token Bearer. No se vuelve a mostrar una vez guardada: deje el campo vacío para conservar la clave guardada. Los modelos locales no suelen necesitarla. La clave guardada solo se envía a la dirección guardada; si cambia la dirección, escriba la clave de nuevo.';
$string['custom_api_key_placeholder'] = 'Escriba la clave de API (opcional para modelos locales)';
$string['custom_api_key_saved'] = 'Hay una clave guardada. Deje el campo vacío para conservarla.';
$string['custom_audio_enabled'] = 'Habilitar procesamiento de audio';
$string['custom_audio_enabled_help'] = 'Permite dictar con el micrófono y adjuntar archivos de audio en el chat web, y enviar mensajes de voz y de audio en Telegram. El audio lo transcribe el modelo de audio del servicio personalizado, que es obligatorio mientras esta opción esté activada.';
$string['custom_audio_model'] = 'Modelo de audio (servicio personalizado)';
$string['custom_audio_model_help'] = 'Id del modelo que transcribe el audio, tal como lo lista el servicio (por ejemplo, openai/whisper-large-v3).';
$string['custom_audio_processing'] = 'Procesamiento de audio (servicio personalizado)';
$string['custom_context_window'] = 'Ventana de contexto (tokens)';
$string['custom_context_window_detected'] = '{$a} (automático)';
$string['custom_context_window_help'] = 'Tokens que el modelo de chat acepta en una petición, pregunta y respuesta juntas. Verificar conexión la lee del servicio cuando este la indica (max_model_len de vLLM, context_length o context_window) y la muestra como valor automático; un valor escrito aquí lo reemplaza. Antes de cada petición del chat web, Jedomi estima los tokens (unos cuatro caracteres cada uno), reserva los tokens máximos para la respuesta y quita los mensajes más antiguos de la conversación que no caben; las instrucciones y la última pregunta siempre se conservan. Déjelo vacío cuando no se detecte nada para no quitar nunca mensajes.';
$string['custom_docservice_url'] = 'URL del servicio de documentos';
$string['custom_docservice_url_help'] = 'Dirección de un servicio que convierte los archivos PDF en imágenes de sus páginas (POST /api/v1/pdf/to-images), como https://docs.example.com. Luego el modelo de imágenes lee las páginas, por lo que el procesamiento de imágenes debe estar activado. Sigue las mismas reglas que la URL del servicio: HTTPS, o HTTP solo para un servicio local con «Permitir HTTP para modelos locales»; sin usuario, contraseña, consulta ni fragmento. Los archivos de texto (.txt, .csv, .md) se leen en el sitio, pero también necesitan «Documentos» activado y esta dirección; los archivos de Word y PowerPoint no se pueden leer con el servicio personalizado. Un PDF completo se lee dentro del tiempo de espera de la IA: las páginas que quedan cuando se agota se omiten, por lo que en un sitio detrás de un proxy (Cloudflare cierra las peticiones a los 100 segundos) solicite menos páginas de los documentos largos.';
$string['custom_documents_enabled'] = 'Habilitar documentos';
$string['custom_documents_enabled_help'] = 'Permite adjuntar archivos PDF y de texto (.txt, .csv, .md) en el chat web y enviar archivos PDF en Telegram, y permite que la IA lea los archivos PDF de las actividades del curso. Los archivos PDF se envían al servicio de documentos, que convierte sus páginas en imágenes (como máximo 20 páginas, primero las solicitadas), y el modelo de imágenes las lee una por una dentro del tiempo de espera de la IA; una página que falla se omite y las demás se conservan. Los archivos de texto se leen en el sitio. Necesita el procesamiento de imágenes y la URL del servicio de documentos.';
$string['custom_documents_processing'] = 'Documentos (servicio personalizado)';
$string['custom_error_http'] = 'El servicio de IA personalizado respondió con el estado HTTP {$a}.';
$string['custom_error_unreachable'] = 'El servicio de IA personalizado no respondió.';
$string['custom_error_url'] = 'La dirección del servicio de IA personalizado no está permitida ({$a}).';
$string['custom_files_enabled'] = 'Habilitar procesamiento de imágenes';
$string['custom_files_enabled_help'] = 'Permite adjuntar imágenes en el chat web y enviar fotos en Telegram. Las imágenes las lee el modelo de imágenes del servicio personalizado, que es obligatorio mientras esta opción esté activada.';
$string['custom_files_processing'] = 'Procesamiento de imágenes (servicio personalizado)';
$string['custom_gateway_url'] = 'URL del servicio';
$string['custom_gateway_url_help'] = 'Introduzca la URL base de la API compatible con OpenAI tal como la documenta el proveedor (por ejemplo, https://api.openai.com/v1, https://api.deepinfra.com/v1/openai o http://localhost:11434 para Ollama). Si la URL no tiene ruta, se añade /v1. Se exige HTTPS, salvo para modelos locales con «Permitir HTTP para modelos locales».';
$string['custom_max_tokens'] = 'Tokens máximos (servicio personalizado)';
$string['custom_max_tokens_help'] = 'Máximo de tokens de salida por respuesta (valor predeterminado: 16384). Con una ventana de contexto, debe ser menor que la ventana: se reserva para la respuesta.';
$string['custom_model'] = 'Modelo de chat (servicio personalizado)';
$string['custom_model_help'] = 'Id del modelo de chat, tal como lo lista el servicio (por ejemplo, meta-llama/Llama-3.3-70B-Instruct). Debe admitir llamadas a herramientas, que Jedomi usa para leer los datos de Moodle.';
$string['custom_speech_model'] = 'Modelo de voz (servicio personalizado)';
$string['custom_speech_model_help'] = 'Id del modelo que lee las respuestas en voz alta. La conversación por voz también necesita el procesamiento de audio.';
$string['custom_speech_voice'] = 'Voz (servicio personalizado)';
$string['custom_speech_voice_help'] = 'Voz del modelo de voz, tal como la nombra el servicio (por ejemplo, alloy).';
$string['custom_verify'] = 'Verificar conexión';
$string['custom_verify_blocked_host'] = 'Esta dirección está bloqueada por la configuración de seguridad HTTP del sitio (hosts bloqueados y puertos permitidos de cURL).';
$string['custom_verify_checking'] = 'Verificando la conexión...';
$string['custom_verify_http_error'] = 'El servicio respondió con el estado HTTP {$a}.';
$string['custom_verify_http_not_allowed'] = 'HTTP sin cifrar solo se permite para un modelo local con «Permitir HTTP para modelos locales» activado. Use HTTPS.';
$string['custom_verify_invalid_url'] = 'Escriba una dirección http o https válida, sin usuario, contraseña, consulta ni fragmento.';
$string['custom_verify_key_required'] = 'Escriba de nuevo la clave de API: la clave guardada solo se envía a la dirección guardada.';
$string['custom_verify_models'] = 'Modelos del servicio:';
$string['custom_verify_models_shown'] = 'Se muestran {$a->shown} de {$a->total} modelos.';
$string['custom_verify_no_response'] = 'El servicio no respondió. Revise la dirección y que el servicio esté en funcionamiento.';
$string['custom_verify_not_openai'] = 'El servicio respondió, pero no como una API compatible con OpenAI: GET /models debe devolver una lista de modelos.';
$string['custom_verify_ok'] = 'Conexión verificada: el servicio lista {$a} modelos. Elija o escriba abajo los id de los modelos.';
$string['custom_verify_rate_limited'] = 'Demasiadas verificaciones. Espere un minuto e inténtelo de nuevo.';
$string['custom_verify_unauthorized'] = 'El servicio rechazó la clave de API (HTTP 401 o 403).';
$string['custom_vision_model'] = 'Modelo de imágenes (servicio personalizado)';
$string['custom_vision_model_help'] = 'Id del modelo que lee las imágenes y las páginas de los archivos PDF, tal como lo lista el servicio (por ejemplo, Qwen/Qwen2.5-VL-7B-Instruct).';
$string['custom_voice_conversation'] = 'Conversación por voz (servicio personalizado)';
$string['custom_voice_enabled'] = 'Habilitar conversación por voz';
$string['custom_voice_enabled_help'] = 'Agrega un botón de conversación por voz al chat web: el usuario habla, Jedomi lo transcribe con el modelo de audio, responde y lee la respuesta en voz alta con el modelo de voz y la voz del servicio personalizado. Necesita el procesamiento de audio.';
$string['dblabel_mysql'] = 'MySQL/MariaDB';
$string['dblabel_pgsql'] = 'PostgreSQL';
$string['decryption_failed'] = 'Falló la desencriptación. El token puede estar corrompido o el salt del sitio cambió.';
$string['docservice_error_http'] = 'El servicio de documentos respondió con el estado HTTP {$a}.';
$string['docservice_error_invalid'] = 'El servicio de documentos no respondió con imágenes de las páginas.';
$string['docservice_error_too_large'] = 'La respuesta del servicio de documentos es demasiado grande.';
$string['docservice_error_unreachable'] = 'El servicio de documentos no respondió.';
$string['docservice_error_url'] = 'La dirección del servicio de documentos no está permitida ({$a}).';
$string['document_page'] = 'Página {$a}:';
$string['documents_error_custom'] = 'Los documentos (PDF, Word, PowerPoint y archivos de texto) no se pueden leer con el servicio de IA personalizado de este sitio.';
$string['documents_error_custom_office'] = 'Los archivos de Word y PowerPoint no se pueden leer con el servicio de IA personalizado de este sitio. Guarde el archivo como PDF y vuelva a adjuntarlo.';
$string['documents_error_pages'] = 'Las páginas solicitadas no están en el documento, que tiene {$a} páginas.';
$string['documents_error_service'] = 'No se pudo leer el documento: el servicio de documentos no lo convirtió. Inténtelo de nuevo más tarde.';
$string['drop_files_here'] = 'Suelte los archivos aquí';
$string['drop_pending_fail'] = '❌ Error al eliminar pendientes — revise la respuesta de la API de Telegram.';
$string['drop_pending_running'] = 'Descartando...';
$string['drop_pending_success'] = '✅ Pendientes eliminados correctamente.';
$string['drop_pending_updates'] = 'Eliminar pendientes';
$string['drop_pending_updates_help'] = 'Limpia la cola de actualizaciones de Telegram para que mensajes viejos no se procesen al '
    . 'reconectar el webhook. Útil durante pruebas.';
$string['encryption_failed'] = 'Falló la encriptación. Verifique la configuración de OpenSSL del servidor.';
$string['entity_agent'] = 'Agente';
$string['entity_query_log'] = 'Consulta registrada';
$string['error_argument_unplaced'] = 'Esta llamada nombra un registro que Jedomi no puede ubicar en un curso ({$a}) mientras '
    . 'algunos cursos están fuera de Jedomi, por lo que no se ejecutó. Consulte a un administrador o use una herramienta '
    . 'más específica o argumentos que nombren el curso.';
$string['error_course_excluded'] = 'Ese curso está excluido de Jedomi.';
$string['error_course_include_empty'] = 'Seleccione al menos un curso o elija otro modo: sin cursos seleccionados, todos los cursos quedarían fuera de Jedomi.';
$string['error_custom_model_invalid'] = 'Escriba un id de modelo sin espacios, tal como lo lista el servicio.';
$string['error_custom_model_required'] = 'Escriba el id del modelo de chat.';
$string['error_custom_model_required_switch'] = 'Escriba el id del modelo o desactive su opción.';
$string['error_custom_not_verified'] = 'Verifique la conexión con esta dirección y esta clave antes de guardar.';
$string['error_custom_voice_required'] = 'Escriba la voz del modelo de voz.';
$string['error_docservice_url_required'] = 'Escriba la dirección del servicio de documentos o desactive los documentos.';
$string['error_documents_require_vision'] = 'Los documentos necesitan el procesamiento de imágenes, que lee sus páginas: actívelo también.';
$string['error_fallback_iterations'] = 'Lo siento, no pude completar la operación después de varios intentos.';
$string['error_invalid_ai_timeout'] = 'Escriba un número entero de segundos entre 10 y 600.';
$string['error_invalid_context_window'] = 'Escriba un número entero de tokens entre 1024 y 10000000, o déjelo vacío.';
$string['error_invalid_hash'] = 'Clave de caché no válida.';
$string['error_invalid_max_tokens'] = 'Ingrese un número entero de tokens.';
$string['error_invalid_model'] = 'Seleccione uno de los modelos de la lista.';
$string['error_invalid_voice'] = 'Seleccione una de las voces del modelo elegido.';
$string['error_max_tokens_exceed_context'] = 'Los tokens máximos deben ser menores que la ventana de contexto ({$a} tokens), que también contiene la pregunta.';
$string['error_not_own_course'] = 'Ese curso no es uno de sus cursos.';
$string['error_own_data_arguments'] = 'Argumento(s) no admitido(s): {$a}. Estas herramientas solo leen los datos del usuario '
    . 'actual.';
$string['error_post_required'] = 'Esta solicitud debe enviarse con POST.';
$string['error_search_tables_failed'] = 'search_tables failed: {$a}';
$string['error_telegram_e006'] = '❌ Respuesta inválida del servicio Jedomi (E006). Inténtelo de nuevo.';
$string['error_telegram_e007'] = '❌ Secreto de webhook inválido (E007). Contacte al administrador.';
$string['error_tool_loop_detected'] = 'Llamada a herramienta repetida bloqueada. Use otra herramienta o ejecute con nuevos '
    . 'parámetros.';
$string['error_unknown_action'] = 'Acción desconocida: {$a}';
$string['error_unknown_tool'] = 'Herramienta desconocida: {$a}';
$string['error_value_range'] = 'Introduzca un valor entre {$a->min} y {$a->max}.';
$string['error_voice_requires_audio'] = 'La conversación por voz necesita el procesamiento de audio: habilítelo también.';
$string['error_ws_describe_failed'] = 'ws_describe failed: {$a}';
$string['error_ws_execute_failed'] = 'ws_execute failed: {$a}';
$string['error_ws_restricted'] = 'Mientras haya cursos fuera de Jedomi, solo está disponible un conjunto reducido de '
    . 'funciones de servicios web. {$a} no es una de ellas.';
$string['error_ws_search_failed'] = 'ws_search failed: {$a}';
$string['event_action_cancelled'] = 'Acción del asistente cancelada';
$string['event_action_confirmed'] = 'Acción del asistente confirmada';
$string['event_agent_created'] = 'Agente creado';
$string['event_agent_deleted'] = 'Agente eliminado';
$string['event_agent_run'] = 'Agente ejecutado';
$string['event_agent_updated'] = 'Agente actualizado';
$string['event_chat_answered'] = 'Respuesta del asistente enviada';
$string['event_settings_updated'] = 'Configuración de Jedomi actualizada';
$string['event_telegram_linked'] = 'Cuenta de Telegram vinculada';
$string['event_telegram_unlinked'] = 'Cuenta de Telegram desvinculada';
$string['execute_sql_error'] = 'Error de base de datos al ejecutar la consulta.';
$string['export_conversation'] = 'Exportar conversación';
$string['export_csv_filename'] = 'jedomi_datos';
$string['export_filename'] = 'jedomi-conversacion';
$string['export_role_assistant'] = 'Jedomi';
$string['export_role_user'] = 'Usuario';
$string['export_title'] = 'Conversación con Jedomi';
$string['feedback_comment'] = '¿Qué podría mejorarse? (opcional)';
$string['feedback_comment_saved'] = 'Gracias por su comentario.';
$string['feedback_comment_send'] = 'Enviar comentario';
$string['file'] = 'Archivo';
$string['file_attach_limit'] = 'Puede adjuntar hasta {$a} archivos en un mensaje; los demás archivos no se adjuntaron.';
$string['file_audio'] = 'Audio: {seconds} segundos';
$string['file_csv'] = 'CSV: {rows} filas, {columns} columnas';
$string['file_error_no_text'] = 'No se pudo leer texto de este archivo.';
$string['file_error_too_large'] = 'El archivo es demasiado grande para leerlo.';
$string['file_error_unsupported'] = 'Solo se pueden adjuntar archivos PDF, CSV, TXT, MD, Word (DOC, DOCX) y PowerPoint (PPT, PPTX), '
    . 'y archivos de audio cuando el procesamiento de audio está habilitado.';
$string['file_error_upload_too_large'] = 'El archivo supera los {$a} MB, el tamaño máximo que se puede adjuntar.';
$string['file_pdf'] = 'PDF: {pages} páginas';
$string['file_process_error'] = 'No se pudo procesar';
$string['file_remove'] = 'Quitar {$a}';
$string['file_text'] = 'Texto: {chars} caracteres';
$string['file_truncated'] = 'El texto de {$a} era demasiado largo: solo se envió su primera parte.';
$string['files_enabled'] = 'Habilitar el procesamiento de archivos e imágenes';
$string['files_enabled_help'] = 'Permite adjuntar imágenes y archivos en el chat web, enviar fotos y documentos en Telegram, y que la IA lea los PDF de las actividades del curso. Las imágenes y las páginas escaneadas de los PDF las lee el modelo de archivos e imágenes, que consume créditos.';
$string['files_error_disabled'] = 'El procesamiento de archivos e imágenes no está habilitado en este sitio.';
$string['files_processing'] = 'Habilitar procesamiento de archivos e imágenes';
$string['filter_type'] = 'Tipo:';
$string['full_width'] = 'Pantalla completa';
$string['half_width'] = 'Media pantalla';
$string['hint_sql_braces'] = ' | PISTA: En SQL de Moodle, los nombres de tablas deben usar llaves (ej: {course}, {user}).';
$string['image'] = 'Imagen';
$string['image_attach_limit'] = 'Solo se puede adjuntar una imagen por mensaje; las demás imágenes no se adjuntaron.';
$string['image_error_no_text'] = 'No se pudo leer contenido de este archivo.';
$string['image_error_unsupported'] = 'Solo se pueden leer archivos PDF, JPG, PNG y WEBP.';
$string['image_not_kept'] = 'Imagen adjunta (no se conserva al recargar la página)';
$string['invalid_session'] = 'Su sesión cambió. Recargue la página para seguir usando Jedomi.';
$string['invalid_signature'] = 'Los permisos del chat no son válidos. Recargue la página para seguir usando Jedomi.';
$string['jedomi:companion_api'] = 'Usar las funciones del servicio complementario de Jedomi';
$string['jedomi:manageagents'] = 'Gestionar los agentes de Jedomi';
$string['keyboard_shortcuts'] = 'Atajos de teclado: Alt+Mayús+J abre o cierra Jedomi, Alt+Mayús+N inicia una conversación nueva y Esc cierra el panel.';
$string['link_type_admin'] = 'Administración';
$string['link_type_assign'] = 'Tarea';
$string['link_type_course'] = 'Curso';
$string['link_type_grades'] = 'Calificaciones';
$string['link_type_messages'] = 'Mensajes';
$string['link_type_moodle'] = 'Moodle';
$string['link_type_profile'] = 'Perfil';
$string['link_type_quiz'] = 'Cuestionario';
$string['listening'] = 'Escuchando...';
$string['max_tokens'] = 'Tokens máximos';
$string['max_tokens_help'] = 'Máximo de tokens de salida por respuesta (valor predeterminado: 16384). Auméntelo para respuestas más largas y redúzcalo para ganar velocidad y reducir el costo.';
$string['message_input'] = 'Mensaje a Jedomi';
$string['messageprovider:agentwrites'] = 'Escrituras de sus agentes programados de Jedomi';
$string['messageprovider:telegramwebhook'] = 'Fallos de registro del webhook de Telegram de Jedomi';
$string['messages_recipients_note'] = 'Los destinatarios los ven en su bandeja de mensajes de Moodle.';
$string['messages_sent'] = '{sent} mensaje(s) enviados como mensajes internos de Moodle.';
$string['messages_sent_fail'] = '({failed} fallaron)';
$string['mic_error'] = 'No se pudo acceder al micrófono. Verifique los permisos del navegador.';
$string['model'] = 'Modelo de chat';
$string['model_help'] = 'Modelo que responde en el chat web, los agentes programados, la vista previa de agentes, Telegram y la CLI. Solo se muestran los modelos con precio registrado.';
$string['new_conversation'] = 'Nueva conversación';
$string['new_conversation_confirm'] = '¿Desea iniciar una conversación nueva? Se borrará la conversación actual.';
$string['new_conversation_msg'] = 'Nueva conversación iniciada. ¿En qué le ayudo?';
$string['no_messages_sent'] = 'No se enviaron mensajes.';
$string['non_select_rejected'] = 'Solo se permiten queries SELECT.';
$string['note_tables_found'] = 'Use el nombre exacto con describe_table o execute_sql. Los nombres de tabla se muestran como '
    . 'están — use {tablename} en las consultas SQL.';
$string['note_tables_not_found'] = 'No se encontraron tablas. Pruebe con otras palabras clave.';
$string['note_ws_found'] = 'Use el campo "name" exacto con ws_execute. Revise "params" para los parámetros requeridos(*).';
$string['note_ws_none_configured'] = 'No hay servicios web configurados para su rol';
$string['note_ws_not_found'] = 'No se encontraron funciones. Pruebe con otras palabras clave.';
$string['open_sidebar'] = 'Abrir {$a}';
$string['pending_action_denied'] = 'La acción no se ejecutó: no tiene permiso para hacerla.';
$string['pending_action_expired'] = 'Esta acción venció. Vuelva a pedírsela a Jedomi.';
$string['pending_action_failed'] = 'No se pudo completar la acción.';
$string['pending_action_needed'] = 'Esta acción necesita su confirmación.';
$string['pending_action_not_creator'] = 'Solo el creador actual del agente que propuso esta acción puede aprobarla. Todavía puede cancelarla.';
$string['pending_action_not_found'] = 'Esta acción no existe o ya se resolvió.';
$string['pending_action_others_not_run'] = 'No se ejecutaron otras {$a} acción(es) propuesta(s) en esta respuesta. Solicítela(s) de nuevo '
    . 'cuando haya confirmado o cancelado esta.';
$string['pending_desc_admin_execute'] = 'Ejecutar la operación de administración "{$a}".';
$string['pending_desc_create_agent'] = 'Crear el agente programado "{$a}".';
$string['pending_desc_delete_agent'] = 'Eliminar el agente programado #{$a}.';
$string['pending_desc_send_messages'] = 'Enviar un mensaje de Moodle a {$a} destinatario(s).';
$string['pending_desc_ws_execute'] = 'Ejecutar la función de servicio web "{$a}".';
$string['pending_from_agent'] = 'Agente "{$a->agent}": {$a->description}';
$string['personalization_assistant_name'] = 'Nombre del asistente';
$string['personalization_assistant_name_help'] = 'Nombre que se muestra en el botón, en el panel y en el mensaje de bienvenida, y que la IA usa como propio en el chat web, la conversación por voz y Telegram. Hasta 50 caracteres; vacío usa Jedomi.';
$string['personalization_avatar'] = 'Avatar';
$string['personalization_avatar_default'] = 'Ícono de Jedomi';
$string['personalization_avatar_help'] = 'Imagen que se muestra en el botón flotante y en la cabecera del panel. El ícono de Jedomi es el que el panel tenía hasta ahora. Si sube un avatar propio, se muestra ese en su lugar.';
$string['personalization_avatar_option'] = 'Avatar {$a}';
$string['personalization_corner'] = 'Esquina del botón';
$string['personalization_corner_bottom_left'] = 'Abajo a la izquierda';
$string['personalization_corner_bottom_right'] = 'Abajo a la derecha';
$string['personalization_corner_help'] = 'Esquina de la página donde se muestra el botón flotante, y su distancia en píxeles a los dos bordes de esa esquina (de 0 a 500). En pantallas de hasta 767 px de ancho el botón queda siempre en la esquina inferior, para no tapar la página.';
$string['personalization_corner_top_left'] = 'Arriba a la izquierda';
$string['personalization_corner_top_right'] = 'Arriba a la derecha';
$string['personalization_custom_prompt'] = 'Instrucciones del sitio para la IA';
$string['personalization_custom_prompt_help'] = 'Instrucciones que se agregan al final de las instrucciones de la IA, por ejemplo un tono o el nombre de la institución, en el chat web, la conversación por voz, Telegram y los agentes. Tienen menor prioridad que las reglas de Jedomi: no pueden cambiar las reglas de seguridad, permisos, privacidad, idioma ni confirmaciones. Hasta 2000 caracteres.';
$string['personalization_custom_prompt_toolong'] = 'Las instrucciones del sitio pueden tener como máximo {$a} caracteres.';
$string['personalization_customavatar'] = 'Avatar propio';
$string['personalization_customavatar_help'] = 'Una imagen (JPG, PNG, GIF, SVG o WebP) de hasta 512 KB, preferentemente cuadrada. Mientras esté subida reemplaza al avatar seleccionado; elimínela para volver a usar el avatar seleccionado.';
$string['personalization_heading_assistant'] = 'Asistente';
$string['personalization_heading_assistant_help'] = 'Nombre, mensaje de bienvenida e instrucciones del sitio que utiliza el asistente.';
$string['personalization_heading_avatar'] = 'Avatar';
$string['personalization_heading_avatar_help'] = 'Imagen que representa al asistente en el botón y en el chat.';
$string['personalization_heading_position'] = 'Posición del botón';
$string['personalization_heading_position_help'] = 'Lugar de la página donde se ubica el botón que abre la barra lateral de Jedomi.';
$string['personalization_offset_invalid'] = 'Escriba un número entero de píxeles entre 0 y {$a}.';
$string['personalization_offset_x'] = 'Distancia horizontal (px)';
$string['personalization_offset_y'] = 'Distancia vertical (px)';
$string['personalization_preview'] = 'Vista previa';
$string['personalization_preview_help'] = 'Arrastre el botón en la vista previa, o selecciónelo y use las flechas del teclado (con Mayús, de a 10 px), para cambiar sus distancias.';
$string['personalization_side'] = 'El panel se abre desde';
$string['personalization_side_left'] = 'El lado izquierdo';
$string['personalization_side_right'] = 'El lado derecho';
$string['personalization_welcome_message'] = 'Mensaje de bienvenida';
$string['personalization_welcome_message_help'] = 'Texto que se muestra al comenzar una conversación nueva en el panel. Puede usar {assistantname}, {firstname}, {username} (nombre completo del usuario) y {coursename} (vacío fuera de un curso). Hasta 1000 caracteres; vacío usa el mensaje predeterminado en el idioma de cada usuario.';
$string['personalization_welcome_message_toolong'] = 'El mensaje de bienvenida puede tener como máximo {$a} caracteres.';
$string['pluginname'] = 'Panel lateral de IA Jedomi';
$string['preview_detail_confirmation'] = 'La vista previa no ejecuta acciones que modifican datos; necesitan la confirmación del usuario.';
$string['preview_detail_loop'] = 'Se llamó {$a} veces a la misma herramienta con los mismos argumentos.';
$string['preview_error_fetch_failed'] = 'El agente no pudo obtener los datos que necesitaba. Inténtelo de nuevo más tarde.';
$string['preview_error_no_answer'] = 'La IA no devolvió ninguna respuesta para esta instrucción. Inténtelo de nuevo o reformule la instrucción.';
$string['privacy:metadata:jedomi_ai_service'] = 'Las conversaciones se envían a través del proveedor de IA de Datacurso a su servicio de IA para generar las respuestas.';
$string['privacy:metadata:jedomi_ai_service:lang'] = 'El idioma del usuario.';
$string['privacy:metadata:jedomi_ai_service:messages'] = 'Los mensajes de la conversación y los resultados de las herramientas usadas para responderlos.';
$string['privacy:metadata:jedomi_ai_service:site_id'] = 'Un identificador del sitio, que el servicio de IA usa para separar las solicitudes de cada sitio.';
$string['privacy:metadata:jedomi_ai_service:site_url'] = 'La dirección del sitio.';
$string['privacy:metadata:jedomi_ai_service:userid'] = 'Un identificador seudónimo del usuario, generado por el proveedor de IA de Datacurso.';
$string['privacy:metadata:jedomi_custom_ai_service'] = 'Cuando el administrador del sitio elige un servicio de IA personalizado, las conversaciones, las respuestas leídas en voz alta y las imágenes y el audio adjuntos en el chat web o enviados por Telegram se envían directamente a ese servicio, fuera de Datacurso, para generar las respuestas. No se envía ningún identificador del usuario ni del sitio.';
$string['privacy:metadata:jedomi_custom_ai_service:filename'] = 'El nombre del archivo de audio o de la grabación que se transcribe.';
$string['privacy:metadata:jedomi_custom_ai_service:files'] = 'Las imágenes y las grabaciones de audio adjuntas al chat o enviadas por Telegram, y las imágenes de las páginas de los archivos PDF leídos cuando los documentos están activados, para leerlas o transcribirlas. Se envían tal cual, sin anonimizar, por lo que pueden contener los datos personales que se ven o se oyen en ellas.';
$string['privacy:metadata:jedomi_custom_ai_service:language'] = 'El idioma del usuario, que se indica al modelo de audio para transcribir la grabación.';
$string['privacy:metadata:jedomi_custom_ai_service:messages'] = 'Los mensajes de la conversación y los resultados de las herramientas usadas para responderlos, anonimizados con el nivel de la pestaña Privacidad de Jedomi.';
$string['privacy:metadata:jedomi_custom_ai_service:mimetype'] = 'El tipo de la imagen, por ejemplo image/png.';
$string['privacy:metadata:jedomi_custom_ai_service:question'] = 'La pregunta que el usuario hace sobre una imagen o un archivo, o el pie de una foto de Telegram, que se envía tal como se escribió: no se anonimiza.';
$string['privacy:metadata:jedomi_custom_ai_service:text'] = 'El texto de la respuesta que se lee en voz alta en la conversación por voz, anonimizado en el sitio antes de enviarse.';
$string['privacy:metadata:jedomi_custom_document_service'] = 'Cuando el administrador del sitio elige un servicio de IA personalizado y activa sus documentos, los archivos PDF adjuntos al chat, enviados por Telegram o leídos de las actividades del curso se envían al servicio de documentos configurado por el administrador, fuera de Datacurso, para convertirlos en imágenes de sus páginas. No se envía ningún identificador del usuario ni del sitio.';
$string['privacy:metadata:jedomi_custom_document_service:filename'] = 'El nombre del archivo PDF.';
$string['privacy:metadata:jedomi_custom_document_service:files'] = 'Los archivos PDF que se convierten en imágenes de sus páginas, enviados tal cual, sin anonimizar, por lo que pueden contener datos de los usuarios.';
$string['privacy:metadata:jedomi_file_service'] = 'Con el servicio de Datacurso, los archivos que un usuario adjunta en el chat web o envía por Telegram (documentos, archivos PDF, archivos de imagen y archivos o grabaciones de audio) y los archivos de las actividades del curso que se leen para el usuario se envían por el proveedor de IA de Datacurso a su servicio de IA, para leer su texto, describir una imagen o transcribir el audio.';
$string['privacy:metadata:jedomi_file_service:file'] = 'El contenido del archivo, la imagen o la grabación de audio. Se envía tal cual, sin anonimizar, por lo que puede contener los datos personales que se ven o se oyen en él.';
$string['privacy:metadata:jedomi_file_service:filename'] = 'El nombre del archivo.';
$string['privacy:metadata:jedomi_file_service:lang'] = 'El idioma del usuario.';
$string['privacy:metadata:jedomi_file_service:language'] = 'El idioma del usuario, que se indica al modelo de audio para transcribir la grabación.';
$string['privacy:metadata:jedomi_file_service:mimetype'] = 'El tipo del archivo, por ejemplo application/pdf o image/png.';
$string['privacy:metadata:jedomi_file_service:question'] = 'La pregunta que el usuario hace sobre una imagen o un archivo, o el pie de una foto de Telegram, que se envía tal como se escribió: no se anonimiza.';
$string['privacy:metadata:jedomi_file_service:site_id'] = 'Un identificador del sitio, que el servicio de IA usa para separar las solicitudes de cada sitio.';
$string['privacy:metadata:jedomi_file_service:site_url'] = 'La dirección del sitio.';
$string['privacy:metadata:jedomi_file_service:userid'] = 'Un identificador seudónimo del usuario, derivado por el proveedor de IA de Datacurso.';
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
$string['privacy:metadata:local_jedomi_agents:lasterror'] = 'El motivo de la última ejecución fallida del agente.';
$string['privacy:metadata:local_jedomi_agents:lastresult'] = 'La respuesta de la última ejecución del agente.';
$string['privacy:metadata:local_jedomi_agents:name'] = 'El nombre del agente.';
$string['privacy:metadata:local_jedomi_agents:prompt'] = 'La petición que ejecuta el agente.';
$string['privacy:metadata:local_jedomi_agents:runwrites'] = 'Las escrituras que la ejecución actual del agente ya intentó: una huella de cada una, si se completó y los identificadores de los usuarios a los que iba dirigida. Se conservan hasta que una ejecución termina correctamente.';
$string['privacy:metadata:local_jedomi_agents:schedule_cron'] = 'Cuándo se ejecuta el agente.';
$string['privacy:metadata:local_jedomi_agents:timecreated'] = 'Cuándo se creó el agente.';
$string['privacy:metadata:local_jedomi_agents:userid'] = 'El usuario propietario del agente.';
$string['privacy:metadata:local_jedomi_feedback'] = 'Valoraciones que los usuarios dan a las respuestas de Jedomi.';
$string['privacy:metadata:local_jedomi_feedback:feedbackcomment'] = 'El comentario opcional que acompaña a una respuesta valorada como mejorable.';
$string['privacy:metadata:local_jedomi_feedback:messageid'] = 'El identificador que el navegador asigna a la respuesta valorada, para que cada respuesta tenga una sola valoración.';
$string['privacy:metadata:local_jedomi_feedback:messagetext'] = 'Un fragmento de la respuesta valorada.';
$string['privacy:metadata:local_jedomi_feedback:rating'] = 'Si la respuesta se valoró como útil o como mejorable.';
$string['privacy:metadata:local_jedomi_feedback:sessionid'] = 'La sesión de chat de la respuesta valorada.';
$string['privacy:metadata:local_jedomi_feedback:timecreated'] = 'Cuándo se dio la valoración.';
$string['privacy:metadata:local_jedomi_feedback:timemodified'] = 'Cuándo cambió por última vez la valoración o su comentario.';
$string['privacy:metadata:local_jedomi_feedback:userid'] = 'El usuario que valoró la respuesta.';
$string['privacy:metadata:local_jedomi_pending_actions'] = 'Acciones del chat web que esperan la confirmación del usuario durante un máximo de 15 minutos.';
$string['privacy:metadata:local_jedomi_pending_actions:argsjson'] = 'Argumentos de la acción, como el mensaje a enviar y sus destinatarios.';
$string['privacy:metadata:local_jedomi_pending_actions:severity'] = 'Nivel de cuidado que exige la confirmación.';
$string['privacy:metadata:local_jedomi_pending_actions:timecreated'] = 'Cuándo se pidió la acción.';
$string['privacy:metadata:local_jedomi_pending_actions:tool'] = 'Herramienta que ejecuta la acción.';
$string['privacy:metadata:local_jedomi_pending_actions:userid'] = 'Usuario que debe confirmar la acción.';
$string['privacy:metadata:local_jedomi_query_log'] = 'Consultas a la base de datos ejecutadas por los agentes, sus vistas previas y los canales de chat, guardadas para el perfilador de consultas.';
$string['privacy:metadata:local_jedomi_query_log:agentid'] = 'El agente que ejecutó la consulta.';
$string['privacy:metadata:local_jedomi_query_log:channel'] = 'Dónde se ejecutó la consulta: un agente programado, la vista previa de un agente, el chat web, Telegram o el servicio web complementario.';
$string['privacy:metadata:local_jedomi_query_log:query'] = 'El texto de la consulta, que puede incluir nombres u otros datos de usuarios.';
$string['privacy:metadata:local_jedomi_query_log:timecreated'] = 'Cuándo se ejecutó la consulta.';
$string['privacy:metadata:local_jedomi_query_log:userid'] = 'El usuario para el que se ejecutó la consulta.';
$string['privacy:metadata:local_jedomi_telegram_links'] = 'El vínculo entre la cuenta de Moodle y un chat de Telegram.';
$string['privacy:metadata:local_jedomi_telegram_links:chatid'] = 'El chat de Telegram vinculado a la cuenta.';
$string['privacy:metadata:local_jedomi_telegram_links:lastused'] = 'Cuándo se usó por última vez el chat vinculado; el vínculo vence 30 días después.';
$string['privacy:metadata:local_jedomi_telegram_links:pinfailures'] = 'PIN de Telegram incorrectos escritos seguidos.';
$string['privacy:metadata:local_jedomi_telegram_links:pinhash'] = 'El hash del PIN de Telegram de un administrador o gestor; el PIN en sí nunca se guarda.';
$string['privacy:metadata:local_jedomi_telegram_links:pinlockeduntil'] = 'Hasta cuándo quedan bloqueados los cambios por Telegram tras demasiados PIN incorrectos.';
$string['privacy:metadata:local_jedomi_telegram_links:pinverifiedat'] = 'Cuándo se escribió por última vez el PIN de Telegram correcto.';
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
$string['privacy:metadata:technical_log'] = 'El registro técnico del plugin, el archivo local/jedomi/jedomi.log del directorio de '
    . 'datos del sitio, con su traza de funcionamiento y de seguridad. Nunca guarda identificadores de usuario, números de chat de '
    . 'Telegram ni los textos de las conversaciones: los usuarios y los chats aparecen como seudónimos (un código calculado con '
    . 'una clave aleatoria del sitio), y la dirección IP de origen se guarda solo en las peticiones rechazadas por motivos de '
    . 'seguridad. El archivo tiene un límite de tamaño: se rota al llegar a 5 MB y conserva los cinco archivos anteriores (unos 30 '
    . 'MB en total), por lo que las entradas más antiguas se eliminan a medida que se escriben otras nuevas; un administrador '
    . 'puede eliminar los archivos en cualquier momento. Las entradas no se exportan ni se eliminan por usuario porque no '
    . 'contienen ningún identificador del usuario.';
$string['privacy:metadata:technical_log:chat'] = 'Un seudónimo del chat de Telegram, nunca su número.';
$string['privacy:metadata:technical_log:ipaddress'] = 'La dirección IP de la petición, solo cuando se rechazó por motivos de '
    . 'seguridad.';
$string['privacy:metadata:technical_log:user'] = 'Un seudónimo del usuario que creó un agente o usa el bot de Telegram, nunca su '
    . 'identificador.';
$string['privacy:metadata:telegram'] = 'Las respuestas del bot, las notificaciones y los resultados de los agentes se entregan en el chat de Telegram vinculado.';
$string['privacy:metadata:telegram:chatid'] = 'El chat de Telegram al que se envían los datos.';
$string['privacy:metadata:telegram:messages'] = 'Las respuestas, notificaciones y resultados de agentes, que pueden incluir datos del sitio.';
$string['privacy_access_heading'] = 'Control de acceso';
$string['privacy_access_heading_help'] = 'Quién puede ver y usar el panel lateral de Jedomi.';
$string['privacy_agents'] = 'Agentes programados';
$string['privacy_anon_heading'] = 'Anonimización de datos';
$string['privacy_anon_heading_help'] = 'Cómo se protegen los datos personales antes de que lleguen al servicio de IA.';
$string['privacy_feedback'] = 'Valoraciones de respuestas';
$string['privacy_pending_actions'] = 'Acciones pendientes de confirmación';
$string['privacy_queries'] = 'Consultas a la base de datos';
$string['privacy_telegram'] = 'Telegram';
$string['processing_file'] = 'Procesando {$a}...';
$string['profiler_heading'] = 'Perfil de consultas';
$string['profiler_heading_help'] = 'Cuándo una consulta se considera lenta y durante cuánto tiempo se conserva el registro de consultas.';
$string['profiler_retention_days'] = 'Conservar los registros de consultas (días)';
$string['profiler_retention_days_help'] = 'La tarea programada «Eliminar los registros antiguos del perfil de consultas» elimina una vez al día los registros del perfil de consultas con más días que este número. Valor predeterminado: 30.';
$string['profiler_retention_days_invalid'] = 'Introduzca un número de días entre 1 y 3650.';
$string['profiler_slow_ms'] = 'Umbral de consulta lenta (ms)';
$string['profiler_slow_ms_help'] = 'Las consultas que tardan al menos estos milisegundos se consideran lentas en la pestaña Perfil de consultas: el indicador las cuenta y la lista las resalta. Valor predeterminado: 1000.';
$string['profiler_slow_ms_invalid'] = 'Introduzca un número de milisegundos entre 1 y 600000.';
$string['provider_settings_info'] = 'El interruptor Habilitado, el modelo de chat, el procesamiento de archivos e imágenes, el procesamiento de audio, la conversación por voz, el modelo de voz, la voz y los tokens máximos de Jedomi se configuran en su página de ajustes del proveedor de IA Datacurso.';
$string['provider_settings_link'] = 'Abrir los ajustes de Jedomi en el proveedor de IA Datacurso';
$string['qa_admin_active_users_label'] = 'Usuarios activos';
$string['qa_admin_active_users_msg'] = '¿Cuántos usuarios activos hay en el sistema?';
$string['qa_admin_alerts_label'] = 'Alertas';
$string['qa_admin_alerts_msg'] = '¿Qué necesita atención inmediata hoy?';
$string['qa_admin_at_risk_label'] = 'En riesgo';
$string['qa_admin_at_risk_msg'] = '¿Qué estudiantes están en riesgo de perder el curso {$a}?';
$string['qa_admin_empty_cohorts_label'] = 'Cohortes vacías';
$string['qa_admin_empty_cohorts_msg'] = '¿Hay cohortes sin miembros asignados?';
$string['qa_admin_external_emails_label'] = 'Correos electrónicos externos';
$string['qa_admin_external_emails_msg'] = '¿Hay usuarios con correos no institucionales?';
$string['qa_admin_gdpr_pending_label'] = 'GDPR pendiente';
$string['qa_admin_gdpr_pending_msg'] = '¿Hay solicitudes de eliminación GDPR pendientes?';
$string['qa_admin_inactive_label'] = 'Inactivos';
$string['qa_admin_inactive_msg'] = '¿Qué estudiantes no accedieron al curso {$a} en los últimos 30 días?';
$string['qa_admin_missing_work_label'] = 'Tareas pendientes';
$string['qa_admin_missing_work_msg'] = '¿Qué estudiantes no entregaron actividades en el curso {$a}?';
$string['qa_admin_overview_label'] = 'Resumen';
$string['qa_admin_overview_msg'] = 'Deme un resumen del estado general de la plataforma';
$string['qa_admin_performance_label'] = 'Rendimiento';
$string['qa_admin_performance_msg'] = 'Muéstreme el ranking de notas del curso {$a}';
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
$string['qa_manager_overview_msg'] = 'Deme un resumen del curso {$a}';
$string['qa_manager_top_courses_label'] = 'Mejores cursos';
$string['qa_manager_top_courses_msg'] = '¿Qué cursos tienen más actividad esta semana?';
$string['qa_student_help_label'] = 'Ayuda';
$string['qa_student_help_msg'] = 'No entiendo el contenido del curso {$a}. ¿Puede explicármelo?';
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
$string['qa_teacher_notify_group_msg'] = 'Redacte un mensaje para los estudiantes del curso {$a} recordándoles las actividades '
    . 'pendientes y envíeselo como mensaje de Moodle con send_messages';
$string['qa_teacher_performance_label'] = 'Rendimiento';
$string['qa_teacher_performance_msg'] = '¿Cuál es el promedio de notas en el curso {$a}?';
$string['qa_teacher_top_students_label'] = 'Mejores estudiantes';
$string['qa_teacher_top_students_msg'] = '¿Quién tiene más de 90 puntos en el curso {$a}?';
$string['query_profiler'] = 'Perfil de consultas';
$string['query_profiler_avg'] = 'Duración promedio';
$string['query_profiler_channel'] = 'Canal';
$string['query_profiler_count'] = 'Ejecuciones';
$string['query_profiler_desc'] = 'Registra las consultas SQL de todos los canales (agentes, vistas previas de agentes, chat web, '
    . 'Telegram y el servicio web complementario), con su tiempo de ejecución y cantidad de filas. Use los filtros para '
    . 'encontrar consultas lentas.';
$string['query_profiler_heading'] = 'Perfil de consultas SQL';
$string['query_profiler_max'] = 'Duración máxima';
$string['query_profiler_min_ms'] = 'Duración mínima (ms)';
$string['query_profiler_ms'] = '{$a} ms';
$string['query_profiler_origin'] = 'Origen';
$string['query_profiler_origin_agent'] = 'Agente';
$string['query_profiler_origin_companion'] = 'Servicio web complementario';
$string['query_profiler_origin_preview'] = 'Vista previa';
$string['query_profiler_origin_preview_agent'] = 'Vista previa del agente #{$a}';
$string['query_profiler_origin_telegram'] = 'Telegram';
$string['query_profiler_origin_web'] = 'Chat web';
$string['query_profiler_recent'] = 'Consultas recientes';
$string['query_profiler_rows'] = 'Filas';
$string['query_profiler_slow'] = 'Consultas lentas (>1s)';
$string['query_profiler_slowest'] = 'Consultas más lentas (promedio)';
$string['query_profiler_sort_duration'] = 'Duración';
$string['query_profiler_sort_query'] = 'Consulta';
$string['query_profiler_sort_time'] = 'Momento';
$string['query_profiler_total'] = 'Consultas totales';
$string['query_profiler_user'] = 'Usuario';
$string['query_timeout'] = 'La consulta superó el tiempo límite.';
$string['read_file_blocked'] = 'El acceso a este archivo no está permitido.';
$string['read_file_not_allowed'] = 'Esta ruta no está en la lista de permitidos.';
$string['reading_image'] = 'Leyendo la imagen...';
$string['regenerate'] = 'Regenerar secreto';
$string['role_visibility_help'] = 'Desactive un rol para ocultar el panel lateral a los usuarios con ese rol. Un usuario queda '
    . 'bloqueado solo cuando todos los roles que tiene en la página (en la actividad, el curso, sus categorías y el sitio) '
    . 'están desactivados; un usuario con otro rol activado conserva el panel. Un usuario sin roles asignados se evalúa con el '
    . 'rol de usuario autenticado. Los administradores del sitio siempre lo ven. Los invitados y los usuarios sin sesión '
    . 'iniciada nunca lo ven, por eso los roles de invitado no aparecen en la lista.';
$string['role_visibility_label'] = 'Mostrar el panel lateral por rol';
$string['search_ws_placeholder'] = 'Buscar servicios web...';
$string['security_heading'] = 'Seguridad';
$string['security_heading_help'] = 'Secretos usados para firmar los tokens de permisos de la barra lateral de Jedomi.';
$string['send'] = 'Enviar';
$string['sending'] = 'Enviando...';
$string['service_custom'] = 'Servicio personalizado';
$string['service_datacurso'] = 'Servicio Datacurso';
$string['session_expired_hint'] = 'Su sesión de Moodle expiró. Recargue la página para continuar.';
$string['session_expired_title'] = 'Sesión expirada.';
$string['settings'] = 'Configuración de Jedomi';
$string['sidebar_title'] = 'Jedomi AI';
$string['silence_detected'] = 'Silencio...';
$string['speech_error_disabled'] = 'La conversación por voz no está habilitada en este sitio.';
$string['speech_error_empty'] = 'No hay texto para leer en voz alta.';
$string['speech_error_rate_limited'] = 'Se leyeron demasiadas respuestas en voz alta en el último minuto. Espere un momento e inténtelo de nuevo.';
$string['speech_error_too_long'] = 'El texto es demasiado largo para leerlo en voz alta (como máximo {$a} caracteres).';
$string['speech_model'] = 'Modelo de voz';
$string['speech_model_help'] = 'Modelo que lee las respuestas en voz alta en la conversación por voz. El predeterminado, Inworld TTS 1.5 mini (voz Rafael), es el más rápido; en las pruebas, Qwen3-TTS tiene la mejor calidad en español. Consume créditos por carácter leído.';
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
$string['sql_carriage_return_rejected'] = 'La consulta contiene un retorno de carro (\\r), por lo que no se ejecutó. '
    . 'Escriba los saltos de línea solo como \\n.';
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
$string['sql_heading'] = 'Consultas del chat web';
$string['sql_heading_help'] = 'Límites de las consultas a la base de datos que la IA ejecuta en el chat web.';
$string['sql_row_limit_web'] = 'Máximo de filas por consulta (chat web)';
$string['sql_row_limit_web_help'] = 'Máximo de filas que una consulta a la base de datos del chat web devuelve a la IA, de 10 a 1000 (valor predeterminado: 100). Más filas dan respuestas más completas a preguntas amplias, pero hacen cada respuesta más lenta y más extensa. Telegram, el servicio web complementario y los agentes programados conservan sus propios límites.';
$string['sql_row_limit_web_invalid'] = 'Escriba un número entero entre 10 y 1000.';
$string['sql_sensitive_rejected'] = 'La consulta lee credenciales, claves, tokens, configuración del sitio o sesiones, por lo que no se ejecutó.';
$string['sql_table_not_allowed'] = 'La consulta lee una tabla que no está disponible para los cursos que usted gestiona (como '
    . 'mensajes, notificaciones, preferencias de usuario o la lista completa de usuarios), por lo que no se ejecutó. Lea tablas de '
    . 'cursos limitadas a sus cursos, y lea nombres de usuarios solo uniendo {user} a las matriculaciones, roles o calificaciones '
    . 'de uno de sus cursos (por ejemplo JOIN {user_enrolments} ue ON ue.userid = u.id JOIN {enrol} e ON e.id = ue.enrolid '
    . 'WHERE e.courseid = 12).';
$string['sql_too_long_rejected'] = 'La consulta tiene más de 8000 caracteres, por lo que no se ejecutó. '
    . 'Escriba una consulta más corta.';
$string['sql_user_column_rejected'] = 'La consulta lee columnas de usuario distintas del id y los nombres (como el correo, el '
    . 'teléfono, la dirección o la última IP), o todas las columnas con *, por lo que no se ejecutó. Indique solo u.id, '
    . 'u.firstname, u.lastname, u.middlename, u.alternatename, u.firstnamephonetic o u.lastnamephonetic.';
$string['stop_answer'] = 'Detener la respuesta';
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
$string['tab_apis_heading_help'] = 'Configure qué servicios web de Moodle puede usar cada rol a través de Jedomi.';
$string['tab_general'] = 'General';
$string['tab_integrations'] = 'Integraciones';
$string['tab_personalization'] = 'Personalización';
$string['tab_privacy'] = 'Privacidad';
$string['tab_profiler'] = 'Perfil de consultas';
$string['table_not_found'] = 'Tabla no encontrada: {$a}';
$string['task_instantiation_failed_msg'] = 'task_instantiation_failed';
$string['task_not_found_msg'] = 'task_not_found';
$string['task_purge_query_profiler'] = 'Eliminar los registros antiguos del perfil de consultas';
$string['task_register_telegram_webhook'] = 'Registrar de nuevo el webhook de Telegram';
$string['task_skipped_plugin_disabled'] = 'Jedomi está deshabilitado en el proveedor de IA Datacurso; se omite la ejecución.';
$string['telegram_access_heading'] = 'Acceso a Telegram por rol';
$string['telegram_admin_not_configured_desc'] = 'Configure el bot de Telegram en los ajustes de Jedomi. Cuando esté configurado, los usuarios verán aquí la opción para vincular Telegram.';
$string['telegram_admin_not_configured_title'] = 'Telegram no está configurado (solo los administradores ven este mensaje)';
$string['telegram_admin_settings_link'] = 'Ajustes de Jedomi → Integraciones';
$string['telegram_bot_heading'] = 'Bot de Telegram';
$string['telegram_bot_token'] = 'Token del bot';
$string['telegram_bot_token_help'] = 'Pegue el token de @BotFather. Se encripta antes de guardarlo.';
$string['telegram_bot_token_placeholder'] = 'Pegue el token de @BotFather';
$string['telegram_bot_token_placeholder_saved'] = 'Guardado. Déjelo en blanco para conservarlo.';
$string['telegram_code_expired'] = 'Vencido';
$string['telegram_confirm_action'] = '⚠️ Esta acción modifica datos y solo se ejecuta si usted la confirma: {$a}';
$string['telegram_confirm_keyword'] = 'Esta acción no se puede deshacer. Para ejecutarla, escriba {$a} en este chat.';
$string['telegram_cooldown'] = 'Tiempo de bloqueo (minutos)';
$string['telegram_cooldown_help'] = 'Minutos para bloquear a un usuario después de exceder el límite.';
$string['telegram_drop_pending_failed'] = 'No se pudieron descartar las actualizaciones pendientes. Revise la respuesta de la API de Telegram.';
$string['telegram_enabled'] = 'Habilitar integración con Telegram';
$string['telegram_enabled_save_failed'] = 'No se pudo guardar el interruptor de la integración con Telegram. Inténtelo de nuevo.';
$string['telegram_gateway_timeout'] = 'Tiempo máximo de respuesta de la IA (segundos)';
$string['telegram_gateway_timeout_help'] = 'Segundos máximos de espera de cada petición a la IA del bot de Telegram, enviada a '
    . 'través del proveedor de IA de Datacurso (5-50). Cada petición debe caber en una ejecución del bot, por lo que no '
    . 'se aplican esperas mayores.';
$string['telegram_heading'] = 'Telegram';
$string['telegram_heading_help'] = 'Conecte Jedomi a un bot de Telegram para que los usuarios conversen vía chat.';
$string['telegram_help_footer'] = 'Después de vincular, envíe cualquier mensaje y lo reenviaré a Jedomi AI para obtener '
    . 'una respuesta.';
$string['telegram_help_heading'] = '🤖 <b>Bot Jedomi — Comandos disponibles</b>';
$string['telegram_help_help'] = '❓ <b>/help</b> — Mostrar este mensaje de ayuda';
$string['telegram_help_new'] = '🗑️ <b>/new</b> o <b>/nuevo</b> — Borrar conversación y empezar de nuevo';
$string['telegram_help_start'] = '🔗 <b>/start CÓDIGO</b> — Vincular su cuenta de Moodle con Telegram';
$string['telegram_help_unlink'] = '🚫 <b>/desvincular</b> o <b>/unlink</b> — Desvincular su Telegram de Moodle';
$string['telegram_howto_confirm'] = 'Las acciones peligrosas muestran botones de confirmación antes de ejecutarse.';
$string['telegram_howto_question'] = 'Envíe cualquier pregunta y Jedomi responde con datos actuales de Moodle.';
$string['telegram_howto_screenshot'] = 'Envíe una captura de un error y Jedomi la analiza (visión con IA).';
$string['telegram_howto_title'] = 'Cómo usar Jedomi desde Telegram';
$string['telegram_howto_unlink'] = 'Envíe <code>/desvincular</code> para desvincular esta cuenta de Telegram.';
$string['telegram_keyboard_help'] = '❓ Ayuda';
$string['telegram_keyboard_howto_link'] = '🔗 ¿Cómo vincular?';
$string['telegram_keyboard_new'] = '📝 Nueva conversación';
$string['telegram_keyboard_unlink'] = '🚫 Desvincular';
$string['telegram_limits_heading'] = 'Límites y tiempo de respuesta de Telegram';
$string['telegram_link_code_expiry'] = 'Este código vence en 10 minutos.';
$string['telegram_link_generate'] = 'Generar código';
$string['telegram_link_instructions'] = 'Abra Telegram, busque su bot y envíe /start seguido del código.';
$string['telegram_link_new_code'] = 'Generar un código nuevo';
$string['telegram_link_open_bot'] = 'Abrir @{$a} en Telegram';
$string['telegram_link_step1_desc'] = 'Toque el botón de abajo para abrir el bot directamente, o busque <strong>@{$a}</strong> en Telegram.';
$string['telegram_link_step1_title'] = 'Abra el bot en Telegram';
$string['telegram_link_step2_desc'] = 'Envíe este comando a <strong>@{$a}</strong>:';
$string['telegram_link_step3_desc'] = 'El bot confirma la vinculación al instante. Después, envíe cualquier mensaje para empezar a usar Jedomi desde Telegram.';
$string['telegram_link_step3_title'] = '¡Listo!';
$string['telegram_link_subtitle'] = 'Vincule su cuenta de Telegram para conversar con Jedomi desde Telegram, con los mismos permisos que tiene en Moodle.';
$string['telegram_link_title'] = 'Vincular Telegram';
$string['telegram_linked_chatid'] = 'chat_id: {$a}';
$string['telegram_linked_on'] = 'Vinculado el {$a}';
$string['telegram_max_turns'] = 'Turnos máximos de conversación';
$string['telegram_max_turns_help'] = 'Cantidad de mensajes guardados en el historial de Telegram (1-100). Valores más bajos '
    . 'ahorran tokens.';
$string['telegram_msg_account_inactive'] = 'Su cuenta de Moodle no está activa, por lo que el bot no puede atenderla. Contacte con el administrador del sitio.';
$string['telegram_msg_audio_disabled'] = 'Los mensajes de voz y audio no están habilitados en este sitio. Envíe su pregunta como texto.';
$string['telegram_msg_done'] = '✅ Listo.';
$string['telegram_msg_expired_code'] = 'Código inválido o vencido. Genere uno nuevo desde su perfil de Moodle.';
$string['telegram_msg_file_unsupported'] = 'Solo se pueden leer archivos PDF, Word (DOC, DOCX), PowerPoint (PPT, PPTX), JPG, PNG y WEBP.';
$string['telegram_msg_files_disabled'] = 'Las fotos y los archivos no están habilitados en este sitio. Envíe su pregunta como texto.';
$string['telegram_msg_heard'] = '🎤 {$a}';
$string['telegram_msg_keepalive'] = '🔄 Sigo procesando su solicitud…';
$string['telegram_msg_link_expired'] = 'Su vínculo de Telegram venció tras 30 días sin uso. Genere un nuevo código de vinculación en la página Telegram de Jedomi de su perfil de Moodle para volver a vincular este chat.';
$string['telegram_msg_link_first'] = 'Primero vincule su cuenta: vaya a su perfil de Moodle y haga clic en "Vincular Telegram".';
$string['telegram_msg_linked_success'] = '✅ Vinculado a Moodle como <b>{$a->fullname}</b> ({$a->username})';
$string['telegram_msg_max_iterations'] = '⚠️ Demasiados pasos. Simplifique su consulta.';
$string['telegram_msg_new_conversation'] = '🗑️ Conversación borrada. ¡Empiece de nuevo!';
$string['telegram_msg_private_only'] = 'El bot solo funciona en un chat privado con cada usuario. Escríbale en un chat privado.';
$string['telegram_msg_provide_code'] = '🔗 Proporcione su código de vinculación.

Ejemplo: /start ABC123';
$string['telegram_msg_rate_check_failed'] = 'No se pudo procesar su mensaje en este momento. Envíelo de nuevo en unos instantes.';
$string['telegram_msg_rate_limited'] = 'Demasiados mensajes. Espere {$a->minutes} minutos.';
$string['telegram_msg_role_blocked'] = 'Su rol no tiene acceso a Jedomi Telegram.';
$string['telegram_msg_state_save_failed'] = '⚠️ Lo sentimos, no se pudo guardar el avance de su solicitud. Inténtelo de nuevo con /new.';
$string['telegram_msg_still_processing'] = '⏳ Sigo procesando su solicitud anterior, por lo que este mensaje no se procesó. Envíelo de nuevo cuando haya respondido.';
$string['telegram_msg_text_only'] = 'Solo puedo leer texto, fotos, documentos y mensajes de voz. Envíe su pregunta como texto.';
$string['telegram_msg_token_invalid'] = 'El token del bot no es válido y no se guardó. Se eliminó el token anterior y el bot quedó '
    . 'desconectado; los demás cambios se guardaron. Verifique el token de @BotFather y guárdelo de nuevo.';
$string['telegram_msg_token_removed'] = 'Se eliminó el token del bot y su webhook en Telegram. El bot ya no responde.';
$string['telegram_msg_unavailable'] = 'El bot de Jedomi no está disponible en este momento. Inténtelo de nuevo más tarde.';
$string['telegram_msg_unlinked'] = 'Desvinculado. Envíe /start para volver a vincular.';
$string['telegram_new_conversation_greeting'] = 'Hola, soy {$a}';
$string['telegram_no_app_desc'] = 'Si está en una computadora sin la app, abra Telegram en su teléfono, busque <strong>@{$a}</strong> y envíe este mensaje:';
$string['telegram_no_app_title'] = '¿No tiene la app de Telegram?';
$string['telegram_not_linked'] = 'Sin vincular';
$string['telegram_notify_graded'] = '📝 Su tarea <b>{$a->assignment}</b> del curso <b>{$a->course}</b> ha sido calificada.';
$string['telegram_open_auto'] = 'Abrir en Telegram (automático)';
$string['telegram_open_auto_help'] = 'Lo lleva directamente a @{$a} con su código listo para vincular.';
$string['telegram_pin_info'] = 'Los cambios que pida a Jedomi en Telegram se confirman con este PIN en lugar del botón Confirmar. Tras el PIN correcto no se vuelve a pedir durante 15 minutos; 3 PIN incorrectos bloquean sus cambios por Telegram durante 15 minutos. Las consultas no necesitan el PIN.';
$string['telegram_pin_invalid'] = 'El PIN debe tener de {$a->min} a {$a->max} dígitos.';
$string['telegram_pin_is_set'] = 'Hay un PIN definido. Si guarda uno nuevo, lo reemplaza.';
$string['telegram_pin_locked'] = 'Demasiados PIN incorrectos: sus cambios por Telegram quedan bloqueados durante {$a} minuto(s) más. Las consultas siguen funcionando.';
$string['telegram_pin_mismatch'] = 'Los dos PIN no coinciden.';
$string['telegram_pin_missing'] = 'Este cambio necesita su PIN de Telegram y todavía no lo ha definido. Defínalo en la página Telegram de Jedomi de su perfil: {$a}. Las consultas siguen funcionando sin él.';
$string['telegram_pin_new'] = 'PIN nuevo';
$string['telegram_pin_not_set'] = 'No hay PIN definido: sus cambios por Telegram se rechazan hasta que lo defina.';
$string['telegram_pin_prompt'] = 'Escriba su PIN de Telegram para confirmar.';
$string['telegram_pin_repeat'] = 'Repita el PIN';
$string['telegram_pin_save'] = 'Guardar PIN';
$string['telegram_pin_saved'] = 'Se guardó el PIN de Telegram.';
$string['telegram_pin_title'] = 'PIN de Telegram';
$string['telegram_pin_wrong'] = 'PIN incorrecto. Intentos restantes: {$a}.';
$string['telegram_quick_step1_desc'] = 'aparece un código de 6 caracteres, válido por 10 minutos.';
$string['telegram_quick_step1_title'] = 'Haga clic en "Generar código" abajo';
$string['telegram_quick_step2_desc'] = 'envíe <code>/start SU_CODIGO</code>.';
$string['telegram_quick_step2_title'] = 'Abra en Telegram:';
$string['telegram_quick_step3_desc'] = '{$a} confirma al instante. Pregunte lo que quiera sobre su Moodle.';
$string['telegram_quick_step3_title'] = '¡Eso es todo!';
$string['telegram_rate_limit'] = 'Límite de mensajes';
$string['telegram_rate_limit_help'] = 'Máximo de mensajes por minuto que un usuario puede enviar. 0 = ilimitado.';
$string['telegram_regenerate_confirm'] = 'La URL del webhook cambia y se vuelve a registrar en Telegram. ¿Continuar?';
$string['telegram_remove_token'] = 'Eliminar el token del bot';
$string['telegram_remove_token_desc'] = 'Desconectar el bot: se borra el token y se elimina el webhook en Telegram.';
$string['telegram_require_pin'] = 'Exigir PIN en Telegram para las acciones de administradores y gestores';
$string['telegram_require_pin_desc'] = 'Los administradores y gestores confirman sus cambios en Telegram con un PIN que definen en su perfil.';
$string['telegram_require_pin_help'] = 'Con la casilla marcada, cada administrador y gestor define un PIN de 6 a 8 dígitos en la página Telegram de Jedomi de su perfil. Sus cambios por Telegram se confirman con el PIN en lugar del botón Confirmar; tras el PIN correcto no se les vuelve a pedir durante 15 minutos, y 3 PIN incorrectos bloquean sus cambios por Telegram durante 15 minutos. Mientras no definan el PIN, sus cambios por Telegram se rechazan y las consultas siguen funcionando. No afecta a profesores ni estudiantes.';
$string['telegram_role_visibility_help'] = 'Desactive para evitar que usuarios con este rol vinculen o reciban mensajes de '
    . 'Telegram. Los admins siempre tienen acceso.';
$string['telegram_secret_regenerated'] = 'Se regeneró el secreto del webhook y se registró la nueva URL del webhook en Telegram.';
$string['telegram_secret_regenerated_unregistered'] = 'Se regeneró el secreto del webhook, pero la nueva URL del webhook no pudo registrarse '
    . 'en Telegram. Revise el estado del webhook.';
$string['telegram_setup_guide'] = 'Guía de configuración';
$string['telegram_status_connected'] = 'Conectado';
$string['telegram_status_disconnected'] = 'Desconectado';
$string['telegram_status_invalid_token'] = 'token no válido, péguelo de nuevo y guarde.';
$string['telegram_step1_desc'] = 'Abra Telegram, busque <strong>@BotFather</strong>, envíe <code>/newbot</code>, elija nombre '
    . 'y usuario. Copie el token que le entrega (formato: <code>123456:ABC-DEF...</code>).';
$string['telegram_step1_title'] = 'Crear un bot en @BotFather';
$string['telegram_step2_desc'] = 'Pegue el token en el campo <strong>Token del bot</strong> abajo y haga clic en <strong>Guardar '
    . 'cambios</strong>. El interruptor de la integración se guarda en cuanto se cambia.';
$string['telegram_step2_title'] = 'Pegar el token y guardar';
$string['telegram_step3_desc'] = 'No hace falta hacer nada: el plugin registra la <strong>URL del webhook</strong> en Telegram '
    . 'automáticamente al guardar el token del bot. Si cambia la URL del sitio, guarde el token del bot de nuevo para registrar '
    . 'la nueva URL.';
$string['telegram_step3_title'] = 'Registrar el webhook en Telegram';
$string['telegram_step4_desc'] = 'Use los interruptores de roles abajo para controlar qué roles de Moodle pueden usar Telegram. Los '
    . 'admins siempre tienen acceso independientemente de esta configuración.';
$string['telegram_step4_title'] = 'Configurar acceso por rol (opcional)';
$string['telegram_stepU1_desc'] = 'Haga clic en su nombre en la barra superior → <strong>Perfil</strong>. Verá una '
    . 'sección <strong>{$a}</strong> con el enlace <em>Vincular Telegram</em>.';
$string['telegram_stepU1_title'] = 'Ir al perfil de Moodle';
$string['telegram_stepU2_desc'] = 'Haga clic en <strong>Generar código</strong>. Aparece un código de 6 caracteres, válido '
    . 'por 10 minutos.';
$string['telegram_stepU2_title'] = 'Generar un código de vinculación';
$string['telegram_stepU3_desc'] = 'En Telegram, abra el bot de su institución y envíe: <code>/start SU_CODIGO</code>. El bot '
    . 'confirma el vínculo al instante.';
$string['telegram_stepU3_title'] = 'Enviar /start al bot';
$string['telegram_stepU4_desc'] = 'Envíe cualquier mensaje al bot. Jedomi responde con datos reales de su Moodle. Para '
    . 'desvincular, envíe <code>/desvincular</code> o use su página de perfil.';
$string['telegram_stepU4_title'] = 'Empezar a chatear';
$string['telegram_step_admin_title'] = 'Configuración del administrador';
$string['telegram_step_user_title'] = 'Configuración del usuario (cada usuario lo hace una vez)';
$string['telegram_task_alert_failing'] = '{$a->name}: con errores (siguiente intento en {$a->delay} s)';
$string['telegram_task_alert_footer'] = 'Revíselas en la página de tareas programadas: {$a}';
$string['telegram_task_alert_heading'] = '⚠️ <b>Tareas programadas con problemas</b>';
$string['telegram_token_not_configured'] = 'El token del bot no está configurado.';
$string['telegram_webhook_auto_missing_desc'] = 'Intente guardar el token de nuevo.';
$string['telegram_webhook_auto_missing_title'] = 'Webhook no registrado';
$string['telegram_webhook_auto_ok_desc'] = 'Al guardar el token, el webhook se registró automáticamente.';
$string['telegram_webhook_auto_ok_title'] = 'Webhook configurado automáticamente';
$string['telegram_webhook_failed_body'] = 'El webhook de Telegram de Jedomi no pudo registrarse con su nuevo secreto, por lo que '
    . 'el bot no recibe mensajes. El sitio lo sigue intentando durante unas 17 horas. Compruebe que el sitio es accesible por '
    . 'HTTPS y que puede conectarse a api.telegram.org, o guarde de nuevo el token del bot en la pestaña Integraciones de Jedomi.';
$string['telegram_webhook_failed_final_body'] = 'El webhook de Telegram de Jedomi sigue sin poder registrarse tras unas 17 '
    . 'horas de intentos y el sitio dejó de intentarlo, por lo que el bot no recibe mensajes. Guarde de nuevo el token del '
    . 'bot en la pestaña Integraciones de Jedomi para registrarlo.';
$string['telegram_webhook_failed_open'] = 'Abrir la pestaña Integraciones';
$string['telegram_webhook_failed_subject'] = 'Jedomi: el bot de Telegram no recibe mensajes';
$string['telegram_webhook_info_failed'] = 'getWebhookInfo falló. Revise el token del bot y el acceso HTTPS saliente.';
$string['telegram_webhook_needs_registration'] = 'La actualización no pudo volver a registrar el webhook de Telegram con su nuevo '
    . 'secreto, por lo que el bot no recibe mensajes. El sitio lo reintenta en segundo plano durante unas 17 horas; también '
    . 'puede guardar de nuevo el token del bot para registrarlo.';
$string['telegram_webhook_retry_failed'] = 'No se pudo registrar el webhook de Telegram; la tarea lo intentará de nuevo.';
$string['telegram_webhook_secret'] = 'Secreto del webhook';
$string['telegram_webhook_secret_help'] = 'Secreto aleatorio que Telegram envía en una cabecera con cada mensaje, nunca en la URL '
    . 'del webhook. Regenérelo si se filtra.';
$string['telegram_webhook_status'] = 'Estado del webhook';
$string['telegram_webhook_url'] = 'URL del webhook';
$string['telegram_webhook_url_help'] = 'Se registra en Telegram automáticamente al guardar el token del bot. Si cambia la URL '
    . 'del sitio, guarde el token del bot de nuevo para registrar la nueva URL.';
$string['thinking'] = 'Pensando...';
$string['toggle_thinking'] = 'Mostrar/ocultar animación de pensamiento';
$string['token_expired'] = 'Los permisos del chat vencieron. Recargue la página para seguir usando Jedomi.';
$string['tool_admin_describe_desc'] = 'Obtiene la documentación completa (parámetros, tipos, campos obligatorios) de una '
    . 'operación CLI de administración específica por su nombre. Úsela DESPUÉS de admin_search y ANTES de admin_execute para '
    . 'verificar los parámetros obligatorios.';
$string['tool_admin_describe_name'] = 'Nombre de la operación (p. ej. backup, scheduled_task, purge_caches).';
$string['tool_admin_execute_desc'] = 'Ejecuta una operación CLI de administración para crear o modificar datos de Moodle. '
    . 'Requiere rol de administrador o gestor. USAR CON PRECAUCIÓN: estas operaciones modifican la base de datos. COMBÍNELA con '
    . 'las herramientas WS: primero use ws_search/ws_execute para descubrir cursos, secciones, usuarios o verificar datos, y '
    . 'luego llame a admin_execute con la información obtenida. Flujo de ejemplo: ws_execute(core_course_get_courses_by_field) '
    . 'para encontrar el curso → admin_execute(create_mod, ...) para crear una actividad.';
$string['tool_admin_execute_name'] = 'Nombre de la operación (p. ej. backup, scheduled_task, purge_caches).';
$string['tool_admin_execute_params'] = 'Objeto JSON con los parámetros de la operación (use primero admin_describe para ver los '
    . 'campos obligatorios).';
$string['tool_admin_search_desc'] = 'Busca operaciones CLI disponibles para administradores (p. ej. copia de seguridad, purgar '
    . 'cachés, ejecutar tareas programadas). Úsela para descubrir qué operaciones existen antes de describirlas o ejecutarlas.';
$string['tool_admin_search_limit'] = 'Número máximo de resultados a devolver (por defecto: 8, máx.: 20).';
$string['tool_admin_search_query'] = 'Palabras clave de búsqueda para encontrar operaciones de administración relevantes.';
$string['tool_api_call'] = 'Ejecutando acción en Moodle...';
$string['tool_create_agent_context_id'] = 'El ID del contexto de Moodle (contexto del curso, la categoría o el usuario), no el '
    . 'ID del curso, la categoría o el usuario. Obligatorio cuando context_type no es "site".';
$string['tool_create_agent_context_type'] = 'Ámbito de ejecución: "site" para todo el sitio, "course" para un curso concreto, '
    . '"category" para una categoría de cursos, "user" para un usuario concreto. Por defecto: "site".';
$string['tool_create_agent_desc'] = 'Crea un agente de IA programado. El agente ejecutará la instrucción indicada de forma '
    . 'periódica, guardará los resultados en caché y enviará notificaciones por Telegram (si está vinculado). Solo disponible '
    . 'para administradores y gestores.';
$string['tool_create_agent_enabled'] = 'Si el agente empieza activado. Por defecto: true. Use false para crearlo inactivo.';
$string['tool_create_agent_prompt'] = 'La descripción de la tarea o la instrucción que el agente de IA ejecutará cada vez. '
    . 'Sea concreto sobre qué datos obtener, analizar o informar. Ejemplo: "Resuma las matriculaciones nuevas de las últimas '
    . '24 horas" o "Revise los estudiantes en riesgo del curso 42".';
$string['tool_create_agent_schedule'] = 'Expresión cron que define cada cuánto se ejecuta el agente. Valores habituales: '
    . '"*/5 * * * *" = cada 5 minutos, "*/30 * * * *" = cada 30 minutos, "0 * * * *" = cada hora, "0 0 * * *" = cada día a '
    . 'medianoche, "0 0 * * 0" = cada semana el domingo.';
$string['tool_delete_agent_desc'] = 'Elimina un agente de IA programado por su ID. Es permanente: borra el agente y todos sus '
    . 'resultados en caché. No se puede deshacer.';
$string['tool_delete_agent_id'] = 'El ID numérico del agente que se eliminará. Use list_agents para encontrar el ID.';
$string['tool_describe_table'] = 'Analizando tabla...';
$string['tool_describe_table_desc'] = 'Obtiene la estructura (columnas, tipos, claves, índices, claves foráneas) de una '
    . 'tabla de la base de datos de Moodle. Se guarda en caché 24 horas.';
$string['tool_describe_table_tablename'] = 'Nombre de la tabla (ej: course, user, grade_grades)';
$string['tool_document_read_cmid'] = 'Identificador del módulo del curso (cmid) de la actividad. Obténgalo del contexto de '
    . 'página o descúbralo vía ws_search/ws_execute.';
$string['tool_document_read_desc'] = 'Leer el PDF publicado en una actividad del curso (su descripción o contenido, nunca entregas ni '
    . 'devoluciones de estudiantes) usando su cmid (identificador del módulo del curso). Devuelve el texto de las '
    . 'páginas; las páginas escaneadas, tablas y diagramas los lee un modelo de visión. Úselo cuando el usuario pida leer, '
    . 'analizar, resumir o explicar un documento de una actividad.';
$string['tool_document_read_pages'] = 'Páginas opcionales a leer, por ejemplo "1,3-5". Omítalo para leer todo el documento.';
$string['tool_execute_sql'] = 'Consultando base de datos...';
$string['tool_execute_sql_desc'] = 'Ejecuta una consulta SELECT en la base de datos de Moodle y devuelve las filas como JSON. Solo lectura. Límites en este canal: como máximo {$a->rows} filas (el resultado indica si se recortó) y {$a->seconds} segundos por consulta en MySQL, MariaDB y PostgreSQL.';
$string['tool_execute_sql_params'] = 'Parámetros vinculados de la consulta, en el orden de los marcadores ? (opcional)';
$string['tool_execute_sql_sql'] = 'Consulta SELECT a ejecutar. Use ? como marcador de posición de los parámetros. '
    . 'Ejemplo: SELECT id, fullname FROM {course} WHERE category = ?';
$string['tool_get_site_config'] = 'Obteniendo configuración...';
$string['tool_get_site_config_desc'] = 'Obtiene configuración del sitio Moodle (sitename, lang, timezone, etc.). Solo disponible '
    . 'para administradores.';
$string['tool_get_site_config_keys'] = 'Lista de claves de configuración. Válidas: sitename, siteurl, lang, country, timezone, '
    . 'maxbytes, passwordpolicy, enrol_plugins_enabled, auth_plugins_enabled';
$string['tool_list_agents_desc'] = 'Lista todos los agentes de IA programados, con un filtro opcional por estado o contexto. '
    . 'Devuelve el ID del agente, un extracto de su instrucción, la programación, el contexto, el estado y la última y la '
    . 'próxima ejecución.';
$string['tool_list_agents_status'] = 'Filtro opcional: "enabled" para los agentes activos, "disabled" para los inactivos. '
    . 'Vacío = todos.';
$string['tool_my_assignments_desc'] = 'Solo lectura. Las tareas de los cursos del usuario actual, con sus fechas de apertura, '
    . 'entrega y cierre que le aplican (con sus excepciones y la fecha de ampliación cuando se le concedió una) y el estado de la '
    . 'entrega, y los próximos vencimientos del calendario del usuario. Úsela cuando un '
    . 'estudiante pregunte por sus tareas o fechas de entrega.';
$string['tool_my_courses_desc'] = 'Solo lectura. Los cursos en los que el usuario actual tiene una matrícula activa. Úsela cuando '
    . 'un estudiante pregunte por sus cursos.';
$string['tool_my_grades_desc'] = 'Solo lectura. Las calificaciones que el usuario actual puede ver en sus cursos (no incluye '
    . 'calificaciones ocultas ni sin publicar). Úsela cuando un estudiante pregunte por sus calificaciones.';
$string['tool_own_data_courseid'] = 'Opcional: id de uno de los cursos del usuario, para leer solo ese curso.';
$string['tool_progress_create_agent'] = 'Creando agente';
$string['tool_progress_create_assign'] = 'Creando tareas';
$string['tool_progress_create_book'] = 'Creando libros';
$string['tool_progress_create_categories'] = 'Creando categorías';
$string['tool_progress_create_courses'] = 'Creando cursos';
$string['tool_progress_create_forum'] = 'Creando foros';
$string['tool_progress_create_group'] = 'Creando grupos';
$string['tool_progress_create_grouping'] = 'Creando agrupamientos';
$string['tool_progress_create_groupings_groups'] = 'Agregando grupos a agrupamientos';
$string['tool_progress_create_interactive'] = 'Creando contenido interactivo';
$string['tool_progress_create_label'] = 'Creando áreas de texto y medios';
$string['tool_progress_create_lesson'] = 'Creando lecciones';
$string['tool_progress_create_page'] = 'Creando páginas';
$string['tool_progress_create_quiz'] = 'Creando cuestionarios';
$string['tool_progress_create_users'] = 'Creando usuarios';
$string['tool_progress_delete_agent'] = 'Eliminando agente';
$string['tool_progress_enrol_users'] = 'Matriculando usuarios';
$string['tool_progress_list_agents'] = 'Listando agentes';
$string['tool_progress_set_course_sections'] = 'Organizando las secciones del curso';
$string['tool_progress_upload_content_files'] = 'Subiendo archivos';
$string['tool_schema_lookup'] = 'Buscando tabla...';
$string['tool_search_tables'] = 'Buscando tablas...';
$string['tool_search_tables_desc'] = 'Busca tablas de Moodle por intención o palabras clave en INGLÉS. Busca en nombres de '
    . 'tablas Y sus descripciones COMMENT de install.xml. Devuelve los resultados más relevantes con nombre, descripción, '
    . 'nombres de campos y comentarios de campos. Use SIEMPRE search_tables antes de describe_table o execute_sql.';
$string['tool_search_tables_limit'] = 'Máximo de resultados (por defecto: 8, máx.: 15)';
$string['tool_search_tables_query'] = 'Intención o palabras clave en INGLÉS (ej: "user grades", "course enrolment", "student")';
$string['tool_send_messages_desc'] = 'Envía un mensaje interno de Moodle a uno o más usuarios. '
    . 'Solo cuando el usuario pide explícitamente enviar o notificar un mensaje; nunca para responder una pregunta '
    . 'o analizar datos. Primero busque los ids de usuario '
    . '(ws_search/ws_execute o execute_sql). El usuario confirma antes del envío: llame a esta herramienta una sola vez '
    . 'y no pida confirmación en el texto.';
$string['tool_send_messages_message'] = 'Texto plano del mensaje, en el idioma del usuario.';
$string['tool_send_messages_user_ids'] = 'Ids de usuario de Moodle de los destinatarios.';
$string['tool_site_overview'] = 'Obteniendo el resumen de la plataforma...';
$string['tool_site_overview_days'] = 'Días del periodo usado para contar los usuarios activos recientemente '
    . '(de 1 a 365, por defecto 30).';
$string['tool_site_overview_desc'] = 'Obtiene en UNA sola llamada un resumen de toda la plataforma: sitio '
    . '(nombre, dominio, versión de Moodle, idioma, zona horaria, última ejecución del cron), usuarios (total, activos, '
    . 'suspendidos, con actividad reciente, sin ningún acceso), cursos (total, visibles, ocultos, categorías, sin profesor), '
    . 'matriculaciones activas, actividades (total y tipos más usados), agentes programados de Jedomi y almacenamiento de '
    . 'archivos. Llámela PRIMERO para un resumen general o el estado general de la plataforma y responda con sus datos; '
    . 'use otras herramientas solo para los detalles que no incluya.';
$string['tool_sql_read'] = 'Consultando base de datos...';
$string['tool_unknown'] = 'Ejecutando herramienta...';
$string['tool_webservice_lookup'] = 'Buscando función API...';
$string['tool_ws_describe'] = 'Analizando función de servicio web...';
$string['tool_ws_describe_desc'] = 'Obtiene documentación completa (descripción, parámetros con * = requerido, tipo de '
    . 'retorno) para una función de servicio web específica cuando ya sabe su nombre exacto. LLAME a esto SIEMPRE DESPUÉS de '
    . 'ws_search y ANTES de ws_execute para verificar los campos requeridos.';
$string['tool_ws_describe_wsname'] = 'Nombre exacto de la función (ej: core_user_create_users)';
$string['tool_ws_execute'] = 'Ejecutando acción...';
$string['tool_ws_execute_desc'] = 'Ejecuta un servicio web de Moodle en nombre del usuario actual. Úselo DESPUÉS de ws_search '
    . 'para obtener el nombre exacto de la función y los parámetros. Si el primer intento falla por "campo requerido faltante", '
    . 'reintente con valores predeterminados para los campos opcionales. Para operaciones por lotes (misma acción en varios '
    . 'elementos), llame a esta función VARIAS VECES, una por cada elemento.';
$string['tool_ws_execute_params'] = 'Parámetros codificados en JSON para la función del servicio web. DEBE incluir todos los '
    . 'campos * (requerido). IMPORTANTE: Si el primer intento falla con "Detectado valor de parámetro no válido" o "Missing '
    . 'required key", significa que la instancia requiere campos que el esquema marca como opcionales. En ese caso, REINTENTE '
    . 'agregando valores predeterminados para los campos faltantes (ej: description="", enrolmentkey="", idnumber="", '
    . 'visibility=0, participation=true).';
$string['tool_ws_execute_wsname'] = 'Nombre de la función del servicio web (ej: core_course_get_courses)';
$string['tool_ws_search'] = 'Buscando funciones de servicios web...';
$string['tool_ws_search_desc'] = 'Busca funciones de servicios web de Moodle por intención o palabras clave en INGLÉS. Devuelve '
    . 'las más relevantes con descripción completa, parámetros (* = requerido) y estructura de retorno. Si los resultados '
    . 'incluyen lo que necesita: DEJE de buscar — llame a ws_describe con el nombre exacto, luego a ws_execute. No llame a '
    . 'ws_search de nuevo para la misma tarea.';
$string['tool_ws_search_limit'] = 'Máximo de resultados a devolver (por defecto: 6, máx.: 15)';
$string['tool_ws_search_query'] = 'Intención o palabras clave en INGLÉS (ej: "create user", "enrol student", "get course '
    . 'grades")';
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
$string['voice_mode_busy_pending'] = 'La conversación por voz no puede iniciarse mientras hay un mensaje de voz pendiente de enviar. Envíelo o descártelo primero.';
$string['voice_mode_busy_recording'] = 'La conversación por voz no puede iniciarse mientras se graba un mensaje de voz. Detenga la grabación primero.';
$string['voice_mode_busy_sending'] = 'La conversación por voz no puede iniciarse mientras se envía un mensaje. Espere la respuesta e inténtelo de nuevo.';
$string['voice_mode_confirm_notice'] = 'Confirme la acción en pantalla, por favor.';
$string['voice_mode_exit'] = 'Terminar la conversación por voz';
$string['voice_mode_idle_exit'] = 'La conversación por voz terminó tras un minuto sin escucharle.';
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
$string['voice_too_long'] = 'La grabación se detuvo al llegar a la duración máxima de {$a} minutos. Puede escucharla y enviarla.';
$string['voice_too_short'] = 'El mensaje de voz era demasiado corto y se descartó. Grabe durante un poco más de tiempo.';
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
$string['webhook_not_checked'] = 'Sin verificar. Haga clic en "Verificar webhook" para comprobar.';
$string['webhook_ok'] = 'Webhook configurado y funcionando.';
$string['welcome_message_default'] = "¡Hola, {username}!\nSoy {assistantname}, su asistente IA de Moodle. "
    . 'Pregúnteme sobre cursos, alumnos, notas o configuraciones.';
$string['ws_agent_allowlist_required'] = 'Los agentes no ejecutan ninguna función de servicio web hasta que se guarde una lista de servicios web para el rol de su creador en la pestaña Servicios web.';
$string['ws_cannot_load_info'] = 'No se puede cargar información de la función: {$a}';
$string['ws_component'] = 'Componente';
$string['ws_debug_prefix'] = ' | debug: ';
$string['ws_description'] = 'Descripción';
$string['ws_execute_error'] = 'Error al ejecutar el servicio web: {$a}';
$string['ws_function_not_found'] = 'Función no encontrada: {$a}';
$string['ws_invalid_parameters'] = 'Parámetros no válidos para el servicio web: {$a}';
$string['ws_name'] = 'Nombre';
$string['ws_none_found'] = 'Ningún servicio web coincide con sus filtros.';
$string['ws_not_found'] = 'Función de servicio web no encontrada.';
$string['ws_not_in_allowlist'] = 'Este servicio web no está en la lista permitida de su rol.';
$string['ws_pagination'] = 'Página {$a->page} de {$a->pages} ({$a->total} servicios)';
$string['ws_role_filter'] = 'Rol:';
$string['ws_type'] = 'Tipo';
$string['ws_type_all'] = 'Todos los tipos';
$string['ws_type_read'] = 'Lectura';
$string['ws_type_write'] = 'Escritura';
$string['yes_send'] = 'Sí, enviar';
