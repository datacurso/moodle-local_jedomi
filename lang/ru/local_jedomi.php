<?php
// ── Plugin identity ───────────────────────────────────────────────────────────
$string['pluginname']      = 'Jedomi AI Sidebar';

// ── Sidebar UI ────────────────────────────────────────────────────────────────
$string['sidebar_title']   = 'Jedomi AI';
$string['open_sidebar']    = 'Открыть Jedomi AI';
$string['close']           = 'Закрыть (Esc)';
$string['new_conversation']= 'Новый диалог';
$string['export_conversation'] = 'Экспортировать диалог';
$string['toggle_thinking'] = 'Показать/скрыть анимацию размышления';
$string['half_width']      = 'Половина ширины';
$string['full_width']      = 'Полная ширина';

// ── Input area ────────────────────────────────────────────────────────────────
$string['attach']           = 'Прикрепить';
$string['attach_file']      = 'Прикрепить PDF/CSV';
$string['attach_image']     = 'Прикрепить изображение';
$string['chat_placeholder'] = 'Спросите Jedomi...';
$string['file']             = 'Файл';
$string['image']            = 'Изображение';
$string['message_input']    = 'Сообщение для Jedomi';
$string['send']             = 'Отправить';
$string['voice_input']      = 'Голосовой ввод';

// ── Greeting ──────────────────────────────────────────────────────────────────
$string['greeting']        = 'Привет, {name}!';
$string['hello']           = 'Я Jedomi, ваш ИИ-ассистент Moodle. Спросите меня о курсах, пользователях, оценках или настройках.';
$string['thinking']        = 'Думаю...';

// ── New conversation ──────────────────────────────────────────────────────────
$string['new_conversation_msg'] = 'Начат новый диалог. Как я могу помочь?';

// ── Image / file attachment ───────────────────────────────────────────────────
$string['analyze_image']       = 'Проанализируй это изображение.';
$string['attached_file_info']  = '[Прикреплённый файл: {$a}]';
$string['attached_image']      = 'Прикреплённое изображение';
$string['csv_download']        = '📥 Скачать CSV';
$string['file_csv']            = 'CSV: {rows} строк, {columns} столбцов';
$string['file_loaded']         = 'загружен';
$string['file_loaded_hint']    = 'Вы можете задавать вопросы о его содержимом.';
$string['file_pdf']            = 'PDF: {pages} страниц';
$string['file_process_error']  = 'Не удалось обработать';
$string['file_text']           = 'Текст: {chars} символов';
$string['processing_file']     = 'Обработка {$a}...';

// ── Tool execution status ─────────────────────────────────────────────────────
$string['tool_sql_read']          = 'Запрос к базе данных...';
$string['tool_api_call']          = 'Выполнение действия Moodle...';
$string['tool_schema_lookup']     = 'Поиск таблицы...';
$string['tool_webservice_lookup'] = 'Поиск функции API...';
$string['tool_get_site_config']    = 'Получение конфигурации сайта...';
$string['tool_execute_sql']       = 'Запрос к базе данных...';
$string['tool_search_tables']     = 'Поиск таблиц...';
$string['tool_describe_table']    = 'Анализ таблицы...';
$string['tool_ws_search']         = 'Поиск функций веб-сервиса...';
$string['tool_ws_describe']       = 'Получение сведений о функции веб-сервиса...';
$string['tool_ws_execute']        = 'Выполнение веб-сервиса...';
$string['tool_unknown']           = 'Выполнение инструмента...';

// ── Message actions ───────────────────────────────────────────────────────────
$string['action_copy']         = 'Копировать';
$string['action_helpful']      = 'Полезно';
$string['action_improve']      = 'Улучшить';
$string['action_improve_title']= 'Требует улучшения';
$string['action_edit']         = 'Редактировать';
$string['action_edit_title']   = 'Редактировать сообщение';

// ── Bulk message confirmation ─────────────────────────────────────────────────
$string['confirm_send_bulk']       = 'Отправить это сообщение {count} студенту(ам)?';
$string['yes_send']                = 'Да, отправить';
$string['cancel']                  = 'Отмена';
$string['sending']                 = 'Отправка...';
$string['cancelled']               = 'Отменено.';
$string['messages_sent']           = '{sent} сообщение(й) отправлено как внутренние сообщения Moodle.';
$string['messages_sent_fail']      = '({failed} не удалось)';
$string['messages_recipients_note']= 'Получатели увидят их в своих входящих сообщениях Moodle.';
$string['no_messages_sent']        = 'Сообщения не отправлены.';
$string['unknown_error']           = 'Неизвестная ошибка';
$string['connection_error']        = 'Ошибка соединения:';

// ── Gateway error ─────────────────────────────────────────────────────────────
$string['gateway_error_title'] = 'Не удалось подключиться к Jedomi Gateway.';
$string['gateway_error_hint']  = 'Убедитесь, что сервис запущен:';

// ── Session expired ───────────────────────────────────────────────────────────
$string['session_expired_title'] = 'Сессия истекла.';
$string['session_expired_hint']  = 'Ваша сессия Moodle истекла. Пожалуйста, обновите страницу, чтобы продолжить.';

// ── Microphone ────────────────────────────────────────────────────────────────
$string['listening']        = 'Слушаю...';
$string['mic_error']        = 'Не удалось получить доступ к микрофону. Проверьте разрешения браузера.';
$string['silence_detected'] = 'Тишина...';
$string['transcribing']     = 'Распознавание речи...';
$string['waiting_voice']    = 'Ожидание голоса...';

// ── Confirmation system (destructive actions) ────────────────────────────────
$string['confirm_type_to_proceed'] = 'Введите ПОДТВЕРДИТЬ, чтобы продолжить:';
$string['confirm_btn_yes']         = 'Подтвердить';
$string['confirm_btn_no']          = 'Отмена';
$string['confirm_executing']       = 'Выполнение...';
$string['confirm_done']            = 'Действие выполнено успешно.';
$string['confirm_cancelled']       = 'Действие отменено.';

// ── Context labels (sidebar badge) ───────────────────────────────────────────
$string['ctx_activity']  = 'Элемент курса';
$string['ctx_admin']     = 'Администрирование';
$string['ctx_calendar']  = 'Календарь';
$string['ctx_course']    = 'Курс';
$string['ctx_dashboard'] = 'Личный кабинет';
$string['ctx_files']     = 'Файлы';
$string['ctx_grades']    = 'Оценки';
$string['ctx_messages']  = 'Сообщения';
$string['ctx_profile']   = 'Профиль';

// ── Quick actions: Admin ──────────────────────────────────────────────────────
$string['qa_admin_active_users_label']    = 'Активные пользователи';
$string['qa_admin_active_users_msg']      = 'Сколько активных пользователей в системе?';
$string['qa_admin_alerts_label']          = 'Оповещения';
$string['qa_admin_alerts_msg']            = 'Что требует немедленного внимания сегодня?';
$string['qa_admin_at_risk_label']         = 'Группа риска';
$string['qa_admin_at_risk_msg']           = 'Какие студенты рискуют не сдать курс {$a}?';
$string['qa_admin_empty_cohorts_label']   = 'Пустые глобальные группы';
$string['qa_admin_empty_cohorts_msg']     = 'Есть ли глобальные группы без участников?';
$string['qa_admin_external_emails_label'] = 'Внешние адреса эл. почты';
$string['qa_admin_external_emails_msg']   = 'Есть ли пользователи с не институциональными адресами электронной почты?';
$string['qa_admin_gdpr_pending_label']    = 'Ожидающие GDPR';
$string['qa_admin_gdpr_pending_msg']      = 'Есть ли ожидающие запросы на удаление данных по GDPR?';
$string['qa_admin_inactive_label']        = 'Неактивные';
$string['qa_admin_inactive_msg']          = 'Какие студенты не заходили в курс {$a} последние 30 дней?';
$string['qa_admin_missing_work_label']    = 'Невыполненные задания';
$string['qa_admin_missing_work_msg']      = 'Какие студенты не отправили элементы курса {$a}?';
$string['qa_admin_overview_label']        = 'Обзор';
$string['qa_admin_overview_msg']          = 'Дай мне сводку общего состояния платформы';
$string['qa_admin_performance_label']     = 'Успеваемость';
$string['qa_admin_performance_msg']       = 'Покажи рейтинг оценок по курсу {$a}';
$string['qa_admin_security_label']        = 'Безопасность';
$string['qa_admin_security_msg']          = 'Есть ли пользователи с подозрительными символами в именах?';
$string['qa_admin_storage_label']         = 'Хранилище';
$string['qa_admin_storage_msg']           = 'В каком курсе загружены самые тяжёлые файлы?';
$string['qa_admin_top_courses_label']     = 'Лучшие курсы';
$string['qa_admin_top_courses_msg']       = 'Какие курсы показывают лучшую успеваемость?';
$string['qa_admin_top_student_label']     = 'Лучший студент';
$string['qa_admin_top_student_msg']       = 'Кто лучший студент в курсе {$a}?';

// ── Quick actions: Manager ────────────────────────────────────────────────────
$string['qa_manager_at_risk_label']       = 'Группа риска';
$string['qa_manager_at_risk_msg']         = 'Какие студенты в группе риска в курсе {$a}?';
$string['qa_manager_enrolled_label']      = 'Зачислено';
$string['qa_manager_enrolled_msg']        = 'Сколько студентов зачислено на курс {$a}?';
$string['qa_manager_global_risk_label']   = 'Общий риск';
$string['qa_manager_global_risk_msg']     = 'Есть ли студенты, рискующие не сдать какой-либо курс?';
$string['qa_manager_inactive_label']      = 'Неактивные';
$string['qa_manager_inactive_msg']        = 'Какие студенты не заходили в курс {$a} последние 14 дней?';
$string['qa_manager_inactive_users_label'] = 'Неактивные пользователи';
$string['qa_manager_inactive_users_msg']  = 'Какие пользователи не заходили в систему последние 30 дней?';
$string['qa_manager_no_teacher_label']    = 'Без преподавателя';
$string['qa_manager_no_teacher_msg']      = 'Есть ли курсы без назначенного преподавателя?';
$string['qa_manager_overview_label']      = 'Обзор';
$string['qa_manager_overview_msg']        = 'Дай мне сводку по курсу {$a}';
$string['qa_manager_top_courses_label']   = 'Лучшие курсы';
$string['qa_manager_top_courses_msg']     = 'Какие курсы самые активные на этой неделе?';

// ── Quick actions: Teacher ────────────────────────────────────────────────────
$string['qa_teacher_at_risk_label']       = 'Группа риска';
$string['qa_teacher_at_risk_msg']         = 'Какие студенты в группе риска в курсе {$a}?';
$string['qa_teacher_my_alerts_label']     = 'Мои оповещения';
$string['qa_teacher_my_alerts_msg']       = 'Есть ли студенты в группе риска в каких-либо из моих курсов?';
$string['qa_teacher_my_courses_label']    = 'Мои курсы';
$string['qa_teacher_my_courses_msg']      = 'В каких курсах я сейчас преподаю?';
$string['qa_teacher_missing_work_label']  = 'Невыполненные задания';
$string['qa_teacher_missing_work_msg']    = 'Кто не отправил последнее задание в курсе {$a}?';
$string['qa_teacher_never_logged_label']  = 'Никогда не заходили';
$string['qa_teacher_never_logged_msg']    = 'Какие студенты никогда не заходили в курс {$a}?';
$string['qa_teacher_notify_group_label']  = 'Уведомить группу';
$string['qa_teacher_notify_group_msg']    = 'Составь сообщение для студентов курса {$a}, напоминающее о невыполненных элементах курса';
$string['qa_teacher_performance_label']   = 'Успеваемость';
$string['qa_teacher_performance_msg']     = 'Какая средняя оценка в курсе {$a}?';
$string['qa_teacher_top_students_label']  = 'Лучшие студенты';
$string['qa_teacher_top_students_msg']    = 'У кого больше 90 баллов в курсе {$a}?';

// ── Quick actions: Student ────────────────────────────────────────────────────
$string['qa_student_help_label']          = 'Помощь';
$string['qa_student_help_msg']            = 'Я не понимаю содержание курса {$a}. Можешь объяснить?';
$string['qa_student_my_courses_label']    = 'Мои курсы';
$string['qa_student_my_courses_msg']      = 'На какие курсы я сейчас зачислен?';
$string['qa_student_my_grade_label']      = 'Моя оценка';
$string['qa_student_my_grade_msg']        = 'Какая у меня средняя оценка в курсе {$a}?';
$string['qa_student_my_grades_label']     = 'Мои оценки';
$string['qa_student_my_grades_msg']       = 'Какая у меня общая средняя оценка по всем курсам?';
$string['qa_student_my_progress_label']   = 'Мой прогресс';
$string['qa_student_my_progress_msg']     = 'Сколько элементов курса я выполнил в курсе {$a}?';
$string['qa_student_my_tasks_label']      = 'Мои задания';
$string['qa_student_my_tasks_msg']        = 'Какие задания у меня ожидают выполнения в курсе {$a}?';
$string['qa_student_this_week_label']     = 'На этой неделе';
$string['qa_student_this_week_msg']       = 'Какие элементы курса и сроки у меня приближаются?';

// ── Admin settings ────────────────────────────────────────────────────────────
$string['settings']        = 'Настройки Jedomi';
// ── Admin tabs ────────────────────────────────────────────────────────────────
$string['settings_redirect_help']     = 'Настройки Jedomi управляются на отдельной странице с интерфейсом вкладок.';
$string['open_admin_settings']        = 'Открыть настройки Jedomi';
$string['tab_general']                = 'Общие';
$string['tab_privacy']                = 'Конфиденциальность';
// ── General tab headings ──────────────────────────────────────────────────────
$string['gateway_heading']            = 'Подключение к Gateway';
$string['gateway_heading_help']       = 'Параметры подключения к бэкенду Jedomi AI.';
$string['security_heading']           = 'Безопасность';
$string['security_heading_help']      = 'Токены и секреты, общие для Moodle и Gateway.';
$string['improvement_heading']        = 'Непрерывное улучшение';
$string['improvement_heading_help']   = 'Дополнительный сбор данных для улучшения Jedomi с течением времени.';
$string['enabled_help']               = 'Показать или скрыть боковую панель Jedomi на всём сайте.';
// ── Privacy tab headings ──────────────────────────────────────────────────────
$string['privacy_access_heading']     = 'Контроль доступа';
$string['privacy_access_heading_help']= 'Кто может видеть и использовать боковую панель Jedomi.';
$string['role_visibility_label']      = 'Показывать панель по роли';
$string['role_visibility_help']       = 'Отключите, чтобы скрыть боковую панель Jedomi от пользователей с этой ролью. Администраторы сайта всегда видят её.';
$string['privacy_anon_heading']       = 'Анонимизация данных';
$string['privacy_anon_heading_help']  = 'Управление тем, как данные диалогов анонимизируются перед сохранением.';
$string['hide_from_guests_help']      = 'Если включено, гости и неавторизованные пользователи не будут видеть боковую панель.';
// ── Anonymization descriptions ────────────────────────────────────────────────
$string['anon_basic_desc']            = 'Заменяет имена и адреса электронной почты. Сохраняет большую часть контекста.';
$string['anon_strict_desc']           = 'Заменяет имена, адреса электронной почты, идентификаторы и произвольный текст. Максимальная конфиденциальность.';
$string['anon_feedback_only_desc']    = 'Сохраняет только оценки и кнопки обратной связи. Без текста диалога.';
$string['gateway_url']     = 'URL Gateway';
$string['gateway_url_help']= 'URL шлюза Jedomi Gateway. Пример: http://localhost:8765';
$string['api_key']         = 'API-ключ';
$string['model']                     = 'Модель';
$string['max_tokens']                = 'Максимум токенов';
$string['max_tokens_help']           = 'Максимальное количество токенов вывода на ответ (по умолчанию: 16384). Увеличьте для более длинных ответов, уменьшите для скорости и экономии.';
$string['enabled']         = 'Включить боковую панель Jedomi';
$string['spt_secret']      = 'Секрет токена разрешений';
$string['spt_secret_help'] = 'Общий секрет между Moodle и Jedomi Gateway для подписи токенов разрешений. Должен совпадать с JEDOMI_SPT_SECRET в вашем файле .env.';
$string['collect_training_data']     = 'Собирать анонимизированные диалоги для обучения';
$string['collect_training_data_help']= 'Если включено, диалоги сохраняются анонимно для улучшения будущих версий модели. Персональные данные не сохраняются.';
$string['anonymization_level']       = 'Уровень анонимизации';
$string['anonymization_level_help']  = 'Насколько агрессивно анонимизировать сохранённые диалоги.';
$string['anon_basic']                = 'Базовый (имена и адреса электронной почты)';
$string['anon_strict']               = 'Строгий (имена, адреса электронной почты, идентификаторы, произвольный текст)';
$string['anon_feedback_only']        = 'Только обратная связь (текст диалога не сохраняется)';
$string['training_data_title']       = 'Данные обучения Jedomi';
$string['course_availability_heading']     = 'Доступность курсов';
$string['course_availability_heading_help']= 'Управление тем, в каких курсах отображается боковая панель Jedomi.';
$string['course_mode']               = 'Режим доступности';
$string['course_mode_help']          = 'Выберите способ управления доступностью курсов.';
$string['course_mode_all']           = 'Все курсы (по умолчанию)';
$string['course_mode_whitelist']     = 'Только выбранные курсы (белый список)';
$string['course_mode_blacklist']     = 'Все курсы, кроме выбранных (чёрный список)';
$string['course_list']               = 'Идентификаторы курсов';
$string['course_list_help']          = 'Введите идентификаторы курсов, разделённые запятыми или переводом строки. Пример: 5, 10, 23. Оставьте пустым, чтобы не ограничивать.';
$string['hide_from_guests']          = 'Скрыть от гостей';
$string['category_list']             = 'Идентификаторы категорий';
$string['category_list_help']        = 'Введите идентификаторы категорий, разделённые запятыми. Будут включены все курсы в этих категориях (включая подкатегории). Пример: 2, 5, 8';

// ── WS / security errors ──────────────────────────────────────────────────────
$string['non_select_rejected']    = 'Разрешены только запросы SELECT.';
$string['query_timeout']          = 'Превышено время ожидания запроса.';
$string['execute_sql_error']      = 'Ошибка базы данных при выполнении запроса.';
$string['unauthorized']           = 'Не авторизовано.';
$string['read_file_blocked']      = 'Доступ к этому файлу не разрешён.';
$string['read_file_not_allowed']  = 'Этот путь не входит в список разрешённых.';
$string['file_not_found']         = 'Файл не найден или недоступен для чтения.';
$string['config_key_blocked']     = 'Этот ключ конфигурации блокируется по соображениям безопасности.';
$string['path_not_allowed']       = 'Этот путь не разрешён.';
$string['drop_files_here']            = 'Перетащите файлы сюда';
$string['manage_course_availability'] = 'Управление доступностью курсов';
$string['course_mode_include']        = 'Показывать только в выбранных курсах';
$string['course_mode_include_desc']   = 'Боковая панель будет отображаться только в курсах, выбранных ниже.';
$string['course_mode_exclude']        = 'Скрыть в выбранных курсах';
$string['course_mode_exclude_desc']   = 'Боковая панель будет отображаться везде, кроме курсов, выбранных ниже.';
$string['course_mode_all_desc']       = 'Боковая панель будет отображаться во всех курсах сайта.';
$string['course_availability_courses'] = 'Выбранные курсы';
$string['course_search_placeholder']  = 'Поиск по названию или короткому имени...';
$string['course_search_hint']         = 'Введите минимум 2 символа для поиска.';
$string['course_none_selected']       = 'Курсы пока не выбраны.';
$string['course_search_noresults']    = 'Курсы не найдены.';
$string['course_remove']              = 'Удалить курс';
$string['ai_disclaimer']          = 'Jedomi — это ИИ и может ошибаться.';

// ── Telegram integration ──────────────────────────────────────────────────────
$string['tab_integrations']                = 'Интеграции';
$string['telegram_heading']                = 'Telegram';
$string['telegram_heading_help']           = 'Подключите Jedomi к боту Telegram, чтобы пользователи могли общаться через Telegram.';
$string['telegram_webhook_status']         = 'Статус вебхука';
$string['check_webhook']                   = 'Проверить вебхук';
$string['webhook_error']                   = 'Последняя ошибка вебхука:';
$string['webhook_ok']                      = 'Вебхук настроен и работает исправно.';
$string['webhook_error_alert_title']       = 'Проблема регистрации вебхука';
$string['webhook_error_alert_desc']        = 'Токен бота был сохранён и проверен, но вебхук не удалось зарегистрировать в Telegram.';
$string['webhook_error_detail']            = 'Детали ошибки:';
$string['webhook_not_checked']             = 'Пока не проверено. Нажмите «Проверить вебхук», чтобы проверить.';
$string['drop_pending_updates']            = 'Сбросить ожидающие обновления';
$string['drop_pending_updates_help']       = 'Очищает очередь обновлений Telegram, чтобы устаревшие сообщения не обрабатывались при переподключении вебхука. Полезно при тестировании.';
$string['drop_pending_success']            = '✅ Ожидающие обновления успешно сброшены.';
$string['drop_pending_fail']               = '❌ Не удалось сбросить ожидающие обновления — проверьте ответ API Telegram.';
$string['telegram_enabled']                = 'Включить интеграцию с Telegram';
$string['telegram_bot_token']              = 'Токен бота';
$string['telegram_bot_token_help']         = 'Вставьте токен от @BotFather. Он шифруется перед сохранением.';
$string['telegram_webhook_url']            = 'URL вебхука';
$string['telegram_webhook_url_help']      = 'Скопируйте этот URL в @BotFather с помощью /setwebhook.';
$string['telegram_webhook_secret']         = 'Секрет вебхука';
$string['telegram_webhook_secret_help']    = 'Случайный секрет, добавляемый к URL вебхука для безопасности. Сгенерируйте новый, если он скомпрометирован.';
$string['telegram_connection_status']     = 'Статус подключения';
$string['telegram_status_connected']      = 'Подключено';
$string['telegram_status_disconnected']    = 'Отключено';
$string['telegram_status_pending']         = 'Ожидание — вставьте URL вебхука в @BotFather';
$string['telegram_rate_limit']             = 'Лимит частоты';
$string['telegram_rate_limit_help']        = 'Максимум сообщений в минуту, которые может отправить пользователь. 0 = без ограничений.';
$string['telegram_cooldown']              = 'Задержка';
$string['telegram_cooldown_help']           = 'Количество минут блокировки пользователя после превышения лимита частоты.';
$string['telegram_role_visible']          = 'Разрешить Telegram для роли: {role}';
$string['telegram_role_visibility_help']   = 'Отключите, чтобы запретить пользователям с этой ролью привязывать Telegram или получать сообщения. У администраторов доступ есть всегда.';
$string['telegram_link_title']            = 'Привязка Telegram';
$string['telegram_link_generate']         = 'Сгенерировать код';
$string['telegram_link_code_expiry']      = 'Этот код действителен 10 минут.';
$string['telegram_linked_as']              = 'Привязан как @{username}';
$string['telegram_not_linked']            = 'Не привязан';
$string['telegram_link_instructions']      = 'Откройте Telegram, найдите своего бота и отправьте /start, а затем код.';
$string['telegram_msg_link_first']       = 'Сначала привяжите свой аккаунт: перейдите в свой профиль Moodle и нажмите «Привязать Telegram».';
$string['telegram_msg_rate_limited']     = 'Слишком много сообщений. Пожалуйста, подождите {minutes} минут.';
$string['telegram_msg_role_blocked']     = 'У вашей роли нет доступа к Jedomi Telegram.';
$string['telegram_msg_confirm']          = 'Подтвердить';
$string['telegram_msg_cancel']            = 'Отмена';
$string['telegram_msg_executing']         = 'Выполнение...';
$string['telegram_msg_done']              = 'Готово.';
$string['telegram_msg_expired_code']      = 'Неверный или устаревший код. Сгенерируйте новый в своём профиле Moodle.';
$string['telegram_msg_unlinked']          = 'Отвязан. Отправьте /start, чтобы привязать заново.';
$string['telegram_msg_linked_success']    = '✅ Привязан к Moodle как <b>{$a->fullname}</b> ({$a->username})';
$string['telegram_msg_provide_code']      = '🔗 Пожалуйста, укажите свой код привязки.\n\nПример: /start ABC123';
$string['telegram_msg_token_invalid']      = 'Неверный токен бота. Проверьте токен от @BotFather и попробуйте снова.';
$string['telegram_msg_max_iterations']     = '⚠️ Слишком много шагов. Пожалуйста, упростите свой запрос.';
$string['telegram_still_working']          = '🔄 Ещё работаю над этим, подождите немного…';
$string['telegram_msg_timeout']            = '⏳ Эта задача слишком сложна для лимита времени. Попробуйте разбить её на более простые шаги или отправьте её снова, и я продолжу с того места, где остановился.';
$string['telegram_msg_new_conversation']   = '🗑️ Диалог очищен. Начнём с чистого листа!';

// ── Telegram help / commands ──────────────────────────────────
$string['telegram_help_heading']          = '🤖 <b>Бот Jedomi — доступные команды</b>';
$string['telegram_help_start']            = '🔗 <b>/start КОД</b> — привязать ваш аккаунт Moodle к Telegram';
$string['telegram_help_help']             = '❓ <b>/help</b> — показать это сообщение помощи';
$string['telegram_help_new']              = '🗑️ <b>/new</b> или <b>/nuevo</b> — очистить диалог и начать заново';
$string['telegram_help_unlink']           = '🚫 <b>/desvincular</b> или <b>/unlink</b> — отвязать Telegram от Moodle';
$string['telegram_help_footer']           = 'После привязки отправьте любое сообщение, и я передам его Jedomi AI для ответа.';

// ── Telegram inline keyboard labels ───────────────────────────
$string['telegram_keyboard_new']          = '📝 Новый диалог';
$string['telegram_keyboard_help']         = '❓ Помощь';
$string['telegram_keyboard_unlink']       = '🚫 Отвязать';
$string['telegram_keyboard_howto_link']   = '🔗 Как привязать?';

// ── Telegram error codes ──────────────────────────────────────
$string['error_telegram_e001']            = '❌ Чат-бот не настроен (E001). Обратитесь к администратору.';
$string['error_telegram_e002']            = '❌ Не удалось отправить сообщение в Telegram (E002). Попробуйте снова.';
$string['error_telegram_e003']            = '❌ Неожиданный ответ Telegram (E003). Попробуйте снова.';
$string['error_telegram_e004']            = '❌ Не удалось подключиться к Jedomi (E004). Попробуйте позже.';
$string['error_telegram_e005']            = '❌ Сервис Jedomi вернул ошибку (E005). Попробуйте снова.';
$string['error_telegram_e006']            = '❌ Некорректный ответ от сервиса Jedomi (E006). Попробуйте снова.';
$string['error_telegram_e007']            = '❌ Неверный секрет вебхука (E007). Обратитесь к администратору.';
$string['error_telegram_e008']            = '❌ У вас нет разрешения на запрос курса с ID {$a}. Разрешены только курсы, где у вас есть права редактирования.';

// ── Telegram config ───────────────────────────────────────────
$string['telegram_progress']              = '🤔 Jedomi думает…';
$string['telegram_max_turns']             = 'Максимум ходов диалога';
$string['telegram_max_turns_help']        = 'Количество сообщений, сохраняемых в истории диалога Telegram (1-100). Меньшие значения экономят токены.';
$string['telegram_gateway_timeout']       = 'Таймаут Gateway (секунды)';
$string['telegram_gateway_timeout_help']  = 'Максимальное время ожидания ответа от Jedomi Gateway, в секундах (5-300).';
$string['unlink']                        = 'Отвязать';
$string['regenerate']                    = 'Пересоздать секрет';
$string['cleanup_telegram_sessions']     = 'Jedomi — очистка просроченных сессий Telegram';
$string['encryption_failed']             = 'Ошибка шифрования. Проверьте настройки OpenSSL на сервере.';
$string['decryption_failed']             = 'Ошибка расшифровки. Токен может быть повреждён, либо изменилась соль сайта.';

// ── Telegram setup guide ──────────────────────────────────────────────────────
// ── Agent Scheduled Tasks ──────────────────────────────────────────────────────
$string['tab_profiler']              = 'Профилировщик запросов';
$string['tab_agents']                = 'Агенты';
$string['agent_heading']             = 'Запланированные ИИ-агенты';
$string['agent_heading_help']        = 'Настройте запланированных ИИ-агентов, которые выполняются автоматически и отправляют результаты через Telegram.';
$string['agent_summary']             = 'Сводка';
$string['agent_by_role']             = 'Агенты по ролям';
$string['agent_by_status']           = 'Агенты по статусу';
$string['agent_executions_30d']      = 'Выполнения (30 дней)';
$string['agent_filters']             = 'Фильтры';
$string['agent_filter_role']         = 'Роль';
$string['agent_filter_status']       = 'Статус';
$string['agent_filter_context']      = 'Контекст';
$string['agent_search']              = 'Поиск запроса...';
$string['agent_apply_filters']       = 'Применить фильтры';
$string['agent_table']               = 'Все агенты';
$string['agent_creator']             = 'Создатель';
$string['agent_prompt']              = 'Запрос';
$string['agent_context']             = 'Контекст';
$string['agent_schedule']            = 'Расписание';
$string['agent_status']              = 'Статус';
$string['agent_lastrun']             = 'Последний запуск';
$string['agent_nextrun']             = 'Следующий запуск';
$string['agent_actions']             = 'Действия';
$string['agent_edit']                = 'Редактировать';
$string['agent_disable']             = 'Отключить';
$string['agent_delete']              = 'Удалить';
$string['agent_create']              = 'Создать агента';
$string['agent_purge']               = 'Очистить агентов';
$string['agent_purge_confirm']       = 'Это удалит всех подходящих агентов и их кэшированные результаты. Продолжить?';
$string['agent_no_results']          = 'Агенты, соответствующие вашим фильтрам, не найдены.';
$string['agent_create_success']      = 'Агент успешно создан.';
$string['agent_created_with_name']   = 'Агент &quot;{name}&quot; успешно создан.';
$string['agent_created_no_name']     = 'Агент создан, но имя не задано. Отредактируйте агента, чтобы добавить имя.';
$string['agent_preview']             = 'Предпросмотр';
$string['agent_preview_result']      = 'Результат предпросмотра агента';
$string['agent_preview_error']       = 'Ошибка предпросмотра. Проверьте настройки шлюза и запрос.';
$string['agent_update_success']      = 'Агент успешно обновлён.';
$string['agent_delete_confirm']      = 'Удалить этого агента? Это действие невозможно отменить.';
$string['agent_disabled']            = 'Отключён';
$string['agent_enabled']             = 'Включён';
$string['agent_failing']             = 'Ошибка выполнения';
$string['agent_run_now']             = 'Запустить сейчас';
$string['agent_schedule_presets']    = 'Готовые расписания';
$string['agent_every_5min']          = 'Каждые 5 минут';
$string['agent_every_15min']         = 'Каждые 15 минут';
$string['agent_every_30min']         = 'Каждые 30 минут';
$string['agent_every_1h']            = 'Каждый час';
$string['agent_every_6h']            = 'Каждые 6 часов';
$string['agent_daily']               = 'Ежедневно';
$string['agent_weekly']              = 'Еженедельно';
$string['agent_custom_cron']         = 'Свой вариант (выражение cron)';
$string['agent_context_type_site']   = 'Сайт';
$string['agent_context_type_course'] = 'Курс';
$string['agent_context_type_category'] = 'Категория';
$string['agent_context_type_user']   = 'Пользователь';
$string['agent_prompt_placeholder']  = 'например, «Обобщи новую активность на сайте и уведоми меня»';
$string['agent_status_all']          = 'Все';
$string['agent_filter_apply']        = 'Применить';
$string['agent_filter_reset']        = 'Сбросить';
$string['agent_purge_context']       = 'Тип контекста';
$string['agent_purge_context_help']  = 'Удалить всех агентов, относящихся к определённому контексту.';
$string['agent_purge_btn']           = 'Очистить';
$string['agent_purge_success']       = '{$a} агент(ов) успешно удалено.';
$string['agent_error_load']          = 'Ошибка загрузки агентов.';
$string['agent_error_create']        = 'Ошибка создания агента: {$a}';
$string['agent_error_update']        = 'Ошибка обновления агента: {$a}';
$string['agent_error_delete']        = 'Ошибка удаления агента: {$a}';
$string['agent_not_found']           = 'Агент не найден.';
$string['agent_run_queued']          = 'Агент поставлен в очередь на выполнение (следующий цикл cron).';
$string['agent_runner_task']         = 'Jedomi — выполнение запланированных ИИ-агентов';
$string['agent_enable']              = 'Включить';

$string['copytoclipboard']               = 'Копировать в буфер обмена';
$string['telegram_setup_guide']          = 'Руководство по настройке';
$string['telegram_setup_guide_help']     = 'Выполните эти шаги, чтобы подключить Jedomi к Telegram. После настройки пользователи смогут привязать свои аккаунты в своём профиле.';
$string['telegram_setup_done']           = 'Интеграция настроена';
$string['telegram_setup_done_help']      = 'Telegram активен. Пользователи с разрешёнными ролями могут привязать свои аккаунты в своём профиле Moodle.';
$string['telegram_setup_show']           = 'Показать руководство по настройке';
$string['telegram_setup_hide']           = 'Скрыть руководство';

$string['telegram_step_admin_title']     = 'Настройка администратором';
$string['telegram_step_admin_help']      = 'Нужно выполнить только один раз. Требуется доступ к Telegram.';
$string['telegram_step1_title']          = 'Создайте бота в @BotFather';
$string['telegram_step1_desc']           = 'Откройте Telegram, найдите <strong>@BotFather</strong>, отправьте <code>/newbot</code>, выберите имя и имя пользователя. Скопируйте выданный токен (формат: <code>123456:ABC-DEF...</code>).';
$string['telegram_step2_title']          = 'Вставьте токен и сохраните';
$string['telegram_step2_desc']           = 'Вставьте токен в поле <strong>Токен бота</strong> ниже, включите переключатель интеграции и нажмите <strong>Сохранить изменения</strong>.';
$string['telegram_step3_title']          = 'Зарегистрируйте вебхук в Telegram';
$string['telegram_step3_desc']           = 'Скопируйте <strong>URL вебхука</strong>, показанный после сохранения. Затем перейдите к @BotFather и выполните: <code>/setwebhook</code>. Либо используйте эту команду curl:';
$string['telegram_step3_note']           = 'Для локальной разработки (DDEV) сначала используйте ngrok, чтобы получить публичный URL HTTPS: <code>ngrok http https://moodle2.ddev.site --host-header=moodle2.ddev.site</code>';
$string['telegram_step4_title']          = 'Настройте доступ по ролям (необязательно)';
$string['telegram_step4_desc']           = 'Используйте переключатели ролей ниже, чтобы управлять тем, какие роли Moodle могут использовать Telegram. У администраторов сайта доступ есть всегда, независимо от этой настройки.';

$string['telegram_step_user_title']      = 'Настройка пользователем (каждый пользователь делает это один раз)';
$string['telegram_step_user_help']       = 'После того как администратор настроит бота, пользователи привязывают свои собственные аккаунты Telegram.';
$string['telegram_stepU1_title']         = 'Перейдите в свой профиль Moodle';
$string['telegram_stepU1_desc']          = 'Нажмите своё имя в верхней панели → <strong>Профиль</strong>. Вы увидите раздел <strong>Jedomi AI</strong> со ссылкой <em>Привязать Telegram</em>.';
$string['telegram_stepU2_title']         = 'Сгенерируйте код привязки';
$string['telegram_stepU2_desc']          = 'Нажмите <strong>Сгенерировать код</strong>. Появится 6-значный код, действительный 10 минут.';
$string['telegram_stepU3_title']         = 'Отправьте /start боту';
$string['telegram_stepU3_desc']          = 'В Telegram откройте бота вашей организации и отправьте: <code>/start ВАШ_КОД</code>. Бот мгновенно подтвердит привязку.';
$string['telegram_stepU4_title']         = 'Начните общение';
$string['telegram_stepU4_desc']          = 'Отправьте боту любое сообщение. Jedomi ответит реальными данными из вашей Moodle. Чтобы отвязать, отправьте <code>/desvincular</code> или используйте страницу своего профиля.';

// ── WS Discovery / Execution ────────────────────────────────────────────────
$string['tab_apis']                = 'API';
$string['tab_apis_heading']        = 'Списки разрешённых веб-сервисов';
$string['tab_apis_heading_help']   = 'Настройте, к каким веб-сервисам Moodle имеет доступ каждая роль через Jedomi.';
$string['api_role_admin']          = 'Администратор';
$string['api_role_manager']        = 'Менеджер';
$string['api_role_teacher']        = 'Преподаватель';
$string['api_role_student']        = 'Студент';
$string['filter_all']              = 'Все';
$string['filter_read']             = 'Только чтение';
$string['filter_write']            = 'Только запись';
$string['filter_type']             = 'Тип:';
$string['component_all']           = 'Все компоненты';
$string['ws_name']                 = 'Имя';
$string['ws_description']          = 'Описание';
$string['ws_component']            = 'Компонент';
$string['ws_type']                 = 'Тип';
$string['ws_type_read']            = 'Чтение';
$string['ws_type_write']           = 'Запись';
$string['ws_type_all']             = 'Все типы';
$string['search_ws_placeholder']   = 'Поиск веб-сервисов...';
$string['ws_not_in_allowlist']     = 'Этот веб-сервис не входит в список разрешённых для вашей роли.';
$string['ws_not_found']            = 'Функция веб-сервиса не найдена.';
$string['ws_execute_error']        = 'Ошибка выполнения веб-сервиса: {$a}';
$string['ws_no_services']          = 'Для этой роли не настроено веб-сервисов.';
$string['ws_pagination']           = 'Страница {$a->page} из {$a->pages} ({$a->total} сервисов)';
$string['ws_none_found']           = 'Веб-сервисы не соответствуют вашим фильтрам.';
$string['tool_ws_execute_desc']    = 'Execute a Moodle web service function on behalf of the current user. Use AFTER ws_search to get the exact function name and parameters. If the first attempt fails with "Missing required key" or "invalid parameter", retry with default values for optional fields. For batch operations (same action on multiple items), call this function MULTIPLE times — once per item.';

// ── Tool execution errors ────────────────────────────────────────────
$string['error_tool_loop_detected'] = 'Повторный идентичный вызов инструмента заблокирован. Используйте другой инструмент или выполните его с новыми параметрами.';
$string['error_fallback_iterations'] = 'Извините, не удалось выполнить операцию после нескольких попыток.';
$string['error_unknown_tool'] = 'Неизвестный инструмент: {$a}';
$string['error_permission_denied'] = 'permission_denied';
$string['error_query_required'] = 'Требуется параметр query';
$string['error_search_tables_failed'] = 'Ошибка search_tables: {$a}';
$string['error_ws_search_failed'] = 'Ошибка ws_search: {$a}';
$string['error_ws_describe_failed'] = 'Ошибка ws_describe: {$a}';
$string['error_ws_execute_failed'] = 'Ошибка ws_execute: {$a}';
$string['error_wsname_required'] = 'Требуется параметр wsname';
$string['error_permission_denied_ws'] = 'permission_denied: function not in allowlist';
$string['error_tool_failed'] = 'tool_failed';

// ── Tool hints ───────────────────────────────────────────────────────
$string['hint_sql_braces'] = ' | HINT: In Moodle SQL, table names should use braces (e.g. {course}, {user}).';

// ── Tool result notes ────────────────────────────────────────────────
$string['note_tables_not_found'] = 'No matching tables found. Try different keywords.';
$string['note_tables_found'] = 'Use the exact "name" with describe_table or execute_sql. Table names shown as provided — use {tablename} in SQL queries.';
$string['note_ws_none_configured'] = 'No web services configured for your role';
$string['note_ws_not_found'] = 'No matching functions found. Try different keywords.';
$string['note_ws_found'] = 'Use the exact "name" field with ws_execute. Check "params" for required(*) parameters.';

// ── SSE tool result summaries ────────────────────────────────────────
$string['summary_invalid_json'] = 'ошибка: недействительный ответ JSON';
$string['summary_error_prefix'] = 'ошибка: ';
$string['summary_rows_returned'] = ' строк возвращено';
$string['summary_table_structure'] = 'структура таблицы получена';
$string['summary_matching_tables'] = ' подходящих таблиц';
$string['summary_config_data'] = 'данные конфигурации получены';
$string['summary_functions_found'] = ' функций найдено';
$string['summary_function_details'] = 'сведения о функции получены';
$string['summary_ws_executed'] = 'веб-сервис успешно выполнен';
$string['summary_done'] = 'готово';

// ── Context labels (system prompt) ───────────────────────────────────
$string['ctx_loc_course'] = 'Currently viewing a course';
$string['ctx_loc_course_id'] = ' (ID: {$a}).';
$string['ctx_loc_activity'] = 'Currently in an activity of type';
$string['ctx_loc_activity_suffix'] = '".';
$string['ctx_loc_gradebook'] = 'Currently viewing the gradebook.';
$string['ctx_loc_admin'] = 'Currently in the Moodle administration panel.';
$string['ctx_loc_dashboard'] = 'Currently on the Moodle dashboard.';
$string['ctx_loc_messages'] = 'Currently in the messages section.';
$string['ctx_loc_profile'] = 'Currently viewing a user profile.';
$string['ctx_loc_calendar'] = 'Currently viewing the calendar.';
$string['ctx_loc_files'] = 'Currently in the files area.';
$string['dblabel_mysql'] = 'MySQL/MariaDB';
$string['dblabel_pgsql'] = 'PostgreSQL';

// ── Tool descriptions (lib.php) ──────────────────────────────────────
$string['tool_describe_table_desc'] = 'Gets the structure (columns, types, keys, indexes, foreign keys) of a Moodle database table. Cached for 24h.';
$string['tool_describe_table_tablename'] = 'Table name (e.g. course, user, grade_grades)';
$string['tool_search_tables_desc'] = 'Search Moodle tables by intent or keywords in ENGLISH. FALLBACK for when web services cannot provide the needed data. Only use this AFTER confirming no relevant web service exists. Searches across table names AND their COMMENT descriptions from install.xml. Returns name, comment, field names, and field comments.';
$string['tool_search_tables_query'] = 'Intent or keywords in ENGLISH (e.g. "user grades", "course enrolment", "student")';
$string['tool_search_tables_limit'] = 'Maximum results (default: 8, max: 15)';
$string['tool_execute_sql_desc'] = 'Executes a SELECT query on the Moodle database and returns the rows as JSON. READ ONLY. Limit: 100 rows, 5 second timeout.';
$string['tool_execute_sql_sql'] = 'SELECT query to execute. Use ? as a placeholder for parameters. Example: SELECT id, fullname FROM {course} WHERE category = ?';
$string['tool_execute_sql_params'] = 'Bound parameters for the query (optional)';
$string['tool_get_site_config_desc'] = 'Gets Moodle site configuration (sitename, lang, timezone, etc.). Only available to administrators.';
$string['tool_get_site_config_keys'] = 'List of configuration keys. Valid: sitename, siteurl, lang, country, timezone, maxbytes, passwordpolicy, enrol_plugins_enabled, auth_plugins_enabled';
$string['tool_ws_search_desc'] = 'Search Moodle web service functions by intent or keywords in ENGLISH. Returns the most relevant matches with full description, parameters (* = required), and return structure. If results include what you need: STOP searching entirely — call ws_describe on the exact name, then ws_execute. Do NOT call SQL tools (search_tables, describe_table, execute_sql) when WS already covers your need. Do NOT call ws_search again for the same task.';
$string['tool_ws_search_query'] = 'Intent or keywords in ENGLISH (e.g. "create user", "enrol student", "get course grades")';
$string['tool_ws_search_limit'] = 'Maximum results to return (default: 6, max: 15)';
$string['tool_ws_describe_desc'] = 'Get full documentation (description, parameters with * = required, return type) for a specific web service function when you already know its exact name. ALWAYS call this AFTER ws_search and BEFORE ws_execute to verify required fields.';
$string['tool_ws_describe_wsname'] = 'Exact function name (e.g. core_user_create_users)';
$string['tool_ws_execute_wsname'] = 'Web service function name (e.g. core_course_get_courses)';
$string['tool_ws_execute_params'] = 'JSON-encoded parameters for the web service function. MUST include every * (required) field. IMPORTANT: If the first attempt fails with "Invalid parameter value detected" or "Missing required key", the instance requires fields that the schema marks as optional. In that case, RETRY by adding default values for missing optional fields (e.g. description="", enrolmentkey="", idnumber="", visibility=0, participation=true).';

// ── Agent tools ───────────────────────────────────────────────────────────────
$string['tool_create_agent_desc'] = 'Create a scheduled AI agent. The agent will execute the given prompt on a recurring schedule, cache results, and send notifications via Telegram (if linked). Only available to admins and managers. Use this when a user wants to automate recurring AI tasks like daily summaries, monitoring, or periodic reports. Returns the new agent ID.';
$string['tool_create_agent_prompt'] = 'The task description or instruction the AI agent will execute each time it runs. Be specific about what data to fetch, analyze, or report. Example: "Summarize new enrollments and activity from the last 24 hours" or "Check for students at risk of failing in course 42 and notify me".';
$string['tool_create_agent_schedule'] = 'Cron expression defining how often the agent runs. Common presets: "*/5 * * * *" = every 5 minutes, "*/30 * * * *" = every 30 minutes, "0 * * * *" = hourly, "0 0 * * *" = daily at midnight, "0 0 * * 0" = weekly on Sunday.';
$string['tool_create_agent_context_type'] = 'Scope of execution: "site" for global, "course" to limit to a specific course, "category" for a course category, "user" for a specific user. Defaults to "site".';
$string['tool_create_agent_context_id'] = 'The ID of the course, category, or user context. Required when context_type is not "site".';
$string['tool_create_agent_enabled'] = 'Whether the agent starts enabled. Default: true. Set to false to create but not activate yet.';
$string['tool_list_agents_desc'] = 'List all configured scheduled AI agents, optionally filtered by status or context. Returns agent ID, prompt excerpt, schedule, context, status, last run, and next run.';
$string['tool_list_agents_status'] = 'Optional filter: "enabled" to show only active agents, "disabled" for inactive ones. Leave empty to show all.';
$string['tool_delete_agent_desc'] = 'Delete a scheduled AI agent by its ID. This permanently removes the agent and all its cached results. Cannot be undone.';
$string['tool_delete_agent_id'] = 'The numeric ID of the agent to delete. Use list_agents to find the ID.';

// ── Admin CLI tools (non-WS actions) ─────────────────────────────────
$string['tool_admin_search_desc'] = 'Search for CLI operations available to administrators (e.g. backup, purge caches, run scheduled tasks). Use this to discover what operations exist before describing or executing them.';
$string['tool_admin_search_query'] = 'Search keywords to find relevant admin operations.';
$string['tool_admin_search_limit'] = 'Maximum number of results to return (default: 8, max: 20).';
$string['tool_admin_describe_desc'] = 'Get full documentation (parameters, types, required fields) for a specific admin CLI operation by name. Use this AFTER admin_search and BEFORE admin_execute to verify required parameters.';
$string['tool_admin_describe_name'] = 'Operation name (e.g. backup, scheduled_task, purge_caches).';
$string['tool_admin_execute_desc'] = 'Execute an admin CLI operation to create or modify Moodle data. Requires admin or manager role. USE WITH CAUTION — these operations modify the database. COMBINE with WS tools: first use ws_search/ws_execute to discover courses, sections, users, or verify data, then call admin_execute with the gathered info. Example flow: ws_execute(core_course_get_courses_by_field) to find course → admin_execute(create_mod, ...) to create an activity.';
$string['tool_admin_execute_name'] = 'Operation name (e.g. backup, scheduled_task, purge_caches).';
$string['tool_admin_execute_params'] = 'JSON object of parameters for the operation (use admin_describe first to see required fields).';

// ── External WS errors ───────────────────────────────────────────────
$string['ws_function_not_found'] = 'Функция не найдена: {$a}';
$string['ws_cannot_load_info'] = 'Не удалось загрузить сведения о функции: {$a}';

// ── Table descriptor errors ──────────────────────────────────────────
$string['table_not_found'] = 'Таблица не найдена: {$a}';

// ── Set site config ──────────────────────────────────────────────────
$string['config_key_blocked_msg'] = 'Ключ блокируется по соображениям безопасности.';
$string['config_updated_msg'] = 'Конфигурация «{$a}» успешно обновлена.';

// ── Run task ─────────────────────────────────────────────────────────
$string['task_not_found_msg'] = 'task_not_found';
$string['task_instantiation_failed_msg'] = 'task_instantiation_failed';

// ── WS execute errors ────────────────────────────────────────────────
$string['ws_method_not_exists'] = 'Метод {$a} не существует';
$string['ws_debug_prefix'] = ' | отладка: ';

// ── Agent SQL profiler ───────────────────────────────────────────────
$string['query_profiler'] = 'Профилировщик запросов';
$string['query_profiler_heading'] = 'Профилировщик SQL-запросов';
$string['query_profiler_desc'] = 'Регистрирует все SQL-запросы, выполняемые агентами, включая время выполнения и количество строк. Используйте фильтр ниже, чтобы найти медленные запросы.';
$string['query_profiler_total'] = 'Всего запросов';
$string['query_profiler_slow'] = 'Медленные запросы (>1с)';
$string['query_profiler_avg'] = 'Средняя длительность';
$string['query_profiler_max'] = 'Максимальная длительность';
$string['query_profiler_sort_duration'] = 'Длительность';
$string['query_profiler_sort_time'] = 'Время';
$string['query_profiler_sort_query'] = 'Запрос';
$string['query_profiler_empty'] = 'Запросы пока не зарегистрированы. Агенты будут регистрировать запросы здесь при использовании инструмента SQL.';
$string['query_profiler_threshold'] = 'Порог медленного запроса (мс)';
$string['query_profiler_recent'] = 'Последние запросы';
$string['query_profiler_slowest'] = 'Самые медленные запросы (в среднем)';

// ── Agent WS allowlist ───────────────────────────────────────────────
$string['agent_ws_allowlist'] = 'Список разрешённых веб-сервисов для агентов';
$string['agent_ws_allowlist_help'] = 'Список имён функций веб-сервисов Moodle, разделённых запятыми, которые агентам разрешено вызывать. Оставьте пустым для набора по умолчанию.';

// ── Agent name ───────────────────────────────────────────────────────
$string['agent_name'] = 'Имя';
$string['agent_name_placeholder'] = 'например, Ежедневный отчёт';
$string['agent_unnamed'] = 'Агент';
// ── Document reader ─────────────────────────────────────────────────
$string['tool_document_read_desc'] = 'Read a PDF file from ANY course activity using its cmid (course module ID). Converts PDF pages to base64 images so you see the EXACT original layout, tables, images, and formatting. Use this when the user asks about a document. The cmid is available in the context when viewing an activity page.';
$string['tool_document_read_cmid'] = 'Course module ID (cmid) of the activity. Get it from the page context when viewing an activity, or discover it via ws_search/ws_execute first.';
$string['tool_document_read_dpi'] = 'Image resolution (default: 150). Higher values = better quality but more tokens.';
$string['tool_document_read_fmt'] = 'Image format: JPEG (smaller) or PNG (lossless). Default: JPEG.';
$string['docservice_url'] = 'URL сервиса преобразования документов в изображения';
$string['docservice_url_help'] = 'URL сервиса преобразования документов в изображения, который конвертирует PDF в base64-изображения (например, http://localhost:8000).';
