<?php
// ── Plugin identity ───────────────────────────────────────────────────────────
$string['pluginname']      = 'Jedomi AI Sidebar';

// ── Sidebar UI ────────────────────────────────────────────────────────────────
$string['sidebar_title']   = 'Jedomi AI';
$string['open_sidebar']    = 'Abrir Jedomi AI';
$string['close']           = 'Fechar (Esc)';
$string['new_conversation']= 'Nova conversa';
$string['export_conversation'] = 'Exportar conversa';
$string['toggle_thinking'] = 'Ativar/desativar animação de "a pensar"';
$string['half_width']      = 'Meia largura';
$string['full_width']      = 'Largura total';

// ── Input area ────────────────────────────────────────────────────────────────
$string['attach']           = 'Anexar';
$string['attach_file']      = 'Anexar PDF/CSV';
$string['attach_image']     = 'Anexar imagem';
$string['chat_placeholder'] = 'Pergunta ao Jedomi...';
$string['file']             = 'Ficheiro';
$string['image']            = 'Imagem';
$string['message_input']    = 'Mensagem para o Jedomi';
$string['send']             = 'Enviar';
$string['voice_input']      = 'Entrada de voz';

// ── Greeting ──────────────────────────────────────────────────────────────────
$string['greeting']        = 'Olá, {name}!';
$string['hello']           = "Sou o Jedomi, o teu assistente de IA do Moodle. Pergunta-me sobre disciplinas, utilizadores, notas ou configurações.";
$string['thinking']        = 'A pensar...';

// ── New conversation ──────────────────────────────────────────────────────────
$string['new_conversation_msg'] = 'Nova conversa iniciada. Como posso ajudar?';

// ── Image / file attachment ───────────────────────────────────────────────────
$string['analyze_image']       = 'Analisa esta imagem.';
$string['attached_file_info']  = '[Ficheiro anexado: {$a}]';
$string['attached_image']      = 'Imagem anexada';
$string['csv_download']        = '📥 Descarregar CSV';
$string['file_csv']            = 'CSV: {rows} linhas, {columns} colunas';
$string['file_loaded']         = 'carregado';
$string['file_loaded_hint']    = 'Pode fazer perguntas sobre o seu conteúdo.';
$string['file_pdf']            = 'PDF: {pages} páginas';
$string['file_process_error']  = 'Não foi possível processar';
$string['file_text']           = 'Texto: {chars} caracteres';
$string['processing_file']     = 'A processar {$a}...';

// ── Tool execution status ─────────────────────────────────────────────────────
$string['tool_sql_read']          = 'A consultar a base de dados...';
$string['tool_api_call']          = 'A executar ação do Moodle...';
$string['tool_schema_lookup']     = 'A consultar tabela...';
$string['tool_webservice_lookup'] = 'A consultar função da API...';
$string['tool_get_site_config']    = 'A obter configuração do site...';
$string['tool_execute_sql']       = 'A consultar a base de dados...';
$string['tool_search_tables']     = 'A procurar tabelas...';
$string['tool_describe_table']    = 'A analisar tabela...';
$string['tool_ws_search']         = 'A consultar funções de webservice...';
$string['tool_ws_describe']       = 'A obter detalhes da função de webservice...';
$string['tool_ws_execute']        = 'A executar webservice...';
$string['tool_unknown']           = 'A executar ferramenta...';

// ── Message actions ───────────────────────────────────────────────────────────
$string['action_copy']         = 'Copiar';
$string['action_helpful']      = 'Útil';
$string['action_improve']      = 'Melhorar';
$string['action_improve_title']= 'Precisa de melhorias';
$string['action_edit']         = 'Editar';
$string['action_edit_title']   = 'Editar mensagem';

// ── Bulk message confirmation ─────────────────────────────────────────────────
$string['confirm_send_bulk']       = 'Enviar esta mensagem a {count} aluno(s)?';
$string['yes_send']                = 'Sim, enviar';
$string['cancel']                  = 'Cancelar';
$string['sending']                 = 'A enviar...';
$string['cancelled']               = 'Cancelado.';
$string['messages_sent']           = '{sent} mensagem(ns) enviada(s) como mensagens internas do Moodle.';
$string['messages_sent_fail']      = '({failed} com falha)';
$string['messages_recipients_note']= 'Os destinatários vão vê-las na caixa de mensagens do Moodle.';
$string['no_messages_sent']        = 'Nenhuma mensagem enviada.';
$string['unknown_error']           = 'Erro desconhecido';
$string['connection_error']        = 'Erro de ligação:';

// ── Gateway error ─────────────────────────────────────────────────────────────
$string['gateway_error_title'] = 'Não foi possível ligar ao Jedomi Gateway.';
$string['gateway_error_hint']  = 'Certifique-se de que o serviço está em execução:';

// ── Session expired ───────────────────────────────────────────────────────────
$string['session_expired_title'] = 'Sessão expirada.';
$string['session_expired_hint']  = 'A sua sessão do Moodle expirou. Recarregue a página para continuar.';

// ── Microphone ────────────────────────────────────────────────────────────────
$string['listening']        = 'A escutar...';
$string['mic_error']        = 'Não foi possível aceder ao microfone. Verifique as permissões do seu navegador.';
$string['silence_detected'] = 'Silêncio...';
$string['transcribing']     = 'A transcrever...';
$string['waiting_voice']    = 'A aguardar voz...';

// ── Confirmation system (destructive actions) ────────────────────────────────
$string['confirm_type_to_proceed'] = 'Escreva CONFIRMAR para continuar:';
$string['confirm_btn_yes']         = 'Confirmar';
$string['confirm_btn_no']          = 'Cancelar';
$string['confirm_executing']       = 'A executar...';
$string['confirm_done']            = 'Ação executada com sucesso.';
$string['confirm_cancelled']       = 'Ação cancelada.';

// ── Context labels (sidebar badge) ───────────────────────────────────────────
$string['ctx_activity']  = 'Atividade';
$string['ctx_admin']     = 'Administração';
$string['ctx_calendar']  = 'Calendário';
$string['ctx_course']    = 'Disciplina';
$string['ctx_dashboard'] = 'Painel';
$string['ctx_files']     = 'Ficheiros';
$string['ctx_grades']    = 'Pauta';
$string['ctx_messages']  = 'Mensagens';
$string['ctx_profile']   = 'Perfil';

// ── Quick actions: Admin ──────────────────────────────────────────────────────
$string['qa_admin_active_users_label']    = 'Utilizadores ativos';
$string['qa_admin_active_users_msg']      = 'Quantos utilizadores ativos existem no sistema?';
$string['qa_admin_alerts_label']          = 'Alertas';
$string['qa_admin_alerts_msg']            = 'O que requer atenção imediata hoje?';
$string['qa_admin_at_risk_label']         = 'Em risco';
$string['qa_admin_at_risk_msg']           = 'Que alunos estão em risco de reprovar na disciplina {$a}?';
$string['qa_admin_empty_cohorts_label']   = 'Grupos globais vazios';
$string['qa_admin_empty_cohorts_msg']     = 'Existem grupos globais sem membros atribuídos?';
$string['qa_admin_external_emails_label'] = 'Emails externos';
$string['qa_admin_external_emails_msg']   = 'Existem utilizadores com emails não institucionais?';
$string['qa_admin_gdpr_pending_label']    = 'RGPD pendente';
$string['qa_admin_gdpr_pending_msg']      = 'Existem pedidos de eliminação RGPD pendentes?';
$string['qa_admin_inactive_label']        = 'Inativos';
$string['qa_admin_inactive_msg']          = 'Que alunos não acederam à disciplina {$a} nos últimos 30 dias?';
$string['qa_admin_missing_work_label']    = 'Trabalhos pendentes';
$string['qa_admin_missing_work_msg']      = 'Que alunos não submeteram atividades na disciplina {$a}?';
$string['qa_admin_overview_label']        = 'Visão geral';
$string['qa_admin_overview_msg']          = 'Dá-me um resumo do estado geral da plataforma';
$string['qa_admin_performance_label']     = 'Desempenho';
$string['qa_admin_performance_msg']       = 'Mostra-me a classificação de notas da disciplina {$a}';
$string['qa_admin_security_label']        = 'Segurança';
$string['qa_admin_security_msg']          = 'Existem utilizadores com caracteres suspeitos nos nomes?';
$string['qa_admin_storage_label']         = 'Armazenamento';
$string['qa_admin_storage_msg']           = 'Que disciplina tem os ficheiros mais pesados carregados?';
$string['qa_admin_top_courses_label']     = 'Melhores disciplinas';
$string['qa_admin_top_courses_msg']       = 'Que disciplinas têm melhor desempenho?';
$string['qa_admin_top_student_label']     = 'Melhor aluno';
$string['qa_admin_top_student_msg']       = 'Quem é o melhor aluno na disciplina {$a}?';

// ── Quick actions: Manager ────────────────────────────────────────────────────
$string['qa_manager_at_risk_label']       = 'Em risco';
$string['qa_manager_at_risk_msg']         = 'Que alunos estão em risco na disciplina {$a}?';
$string['qa_manager_enrolled_label']      = 'Inscritos';
$string['qa_manager_enrolled_msg']        = 'Quantos alunos estão inscritos na disciplina {$a}?';
$string['qa_manager_global_risk_label']   = 'Risco global';
$string['qa_manager_global_risk_msg']     = 'Existem alunos em risco de reprovar em alguma disciplina?';
$string['qa_manager_inactive_label']      = 'Inativos';
$string['qa_manager_inactive_msg']        = 'Que alunos não acederam à disciplina {$a} nos últimos 14 dias?';
$string['qa_manager_inactive_users_label'] = 'Utilizadores inativos';
$string['qa_manager_inactive_users_msg']  = 'Que utilizadores não acederam ao sistema nos últimos 30 dias?';
$string['qa_manager_no_teacher_label']    = 'Sem professor';
$string['qa_manager_no_teacher_msg']      = 'Existem disciplinas sem professor atribuído?';
$string['qa_manager_overview_label']      = 'Visão geral';
$string['qa_manager_overview_msg']        = 'Dá-me um resumo da disciplina {$a}';
$string['qa_manager_top_courses_label']   = 'Melhores disciplinas';
$string['qa_manager_top_courses_msg']     = 'Que disciplinas têm mais atividade esta semana?';

// ── Quick actions: Teacher ────────────────────────────────────────────────────
$string['qa_teacher_at_risk_label']       = 'Em risco';
$string['qa_teacher_at_risk_msg']         = 'Que alunos estão em risco na disciplina {$a}?';
$string['qa_teacher_my_alerts_label']     = 'Os meus alertas';
$string['qa_teacher_my_alerts_msg']       = 'Existem alunos em risco em alguma das minhas disciplinas?';
$string['qa_teacher_my_courses_label']    = 'As minhas disciplinas';
$string['qa_teacher_my_courses_msg']      = 'Em que disciplinas sou atualmente professor?';
$string['qa_teacher_missing_work_label']  = 'Trabalhos pendentes';
$string['qa_teacher_missing_work_msg']    = 'Quem não submeteu o último trabalho na disciplina {$a}?';
$string['qa_teacher_never_logged_label']  = 'Nunca acedeu';
$string['qa_teacher_never_logged_msg']    = 'Que alunos nunca acederam à disciplina {$a}?';
$string['qa_teacher_notify_group_label']  = 'Notificar grupo';
$string['qa_teacher_notify_group_msg']    = 'Redige uma mensagem para os alunos da disciplina {$a} a recordar as atividades pendentes';
$string['qa_teacher_performance_label']   = 'Desempenho';
$string['qa_teacher_performance_msg']     = 'Qual é a nota média na disciplina {$a}?';
$string['qa_teacher_top_students_label']  = 'Melhores alunos';
$string['qa_teacher_top_students_msg']    = 'Quem tem mais de 90 pontos na disciplina {$a}?';

// ── Quick actions: Student ────────────────────────────────────────────────────
$string['qa_student_help_label']          = 'Ajuda';
$string['qa_student_help_msg']            = 'Não entendo o conteúdo da disciplina {$a}. Podes explicar?';
$string['qa_student_my_courses_label']    = 'As minhas disciplinas';
$string['qa_student_my_courses_msg']      = 'Em que disciplinas estou atualmente inscrito?';
$string['qa_student_my_grade_label']      = 'A minha nota';
$string['qa_student_my_grade_msg']        = 'Qual é a minha média na disciplina {$a}?';
$string['qa_student_my_grades_label']     = 'As minhas notas';
$string['qa_student_my_grades_msg']       = 'Qual é a minha média geral em todas as minhas disciplinas?';
$string['qa_student_my_progress_label']   = 'O meu progresso';
$string['qa_student_my_progress_msg']     = 'Quantas atividades já completei na disciplina {$a}?';
$string['qa_student_my_tasks_label']      = 'As minhas tarefas';
$string['qa_student_my_tasks_msg']        = 'Que trabalhos tenho pendentes na disciplina {$a}?';
$string['qa_student_this_week_label']     = 'Esta semana';
$string['qa_student_this_week_msg']       = 'Que atividades e prazos tenho a chegar?';

// ── Admin settings ────────────────────────────────────────────────────────────
$string['settings']        = 'Configurações do Jedomi';
// ── Admin tabs ────────────────────────────────────────────────────────────────
$string['settings_redirect_help']     = 'As configurações do Jedomi são geridas numa página dedicada com interface de separadores.';
$string['open_admin_settings']        = 'Abrir Configurações do Jedomi';
$string['tab_general']                = 'Geral';
$string['tab_privacy']                = 'Privacidade';
// ── General tab headings ──────────────────────────────────────────────────────
$string['gateway_heading']            = 'Ligação ao Gateway';
$string['gateway_heading_help']       = 'Definições de ligação para o backend de IA do Jedomi.';
$string['security_heading']           = 'Segurança';
$string['security_heading_help']      = 'Tokens e segredos partilhados entre o Moodle e o Gateway.';
$string['improvement_heading']        = 'Melhoria contínua';
$string['improvement_heading_help']   = 'Recolha de dados opcional para melhorar o Jedomi ao longo do tempo.';
$string['enabled_help']               = 'Mostrar ou ocultar a barra lateral do Jedomi em todo o site.';
// ── Privacy tab headings ──────────────────────────────────────────────────────
$string['privacy_access_heading']     = 'Controlo de acesso';
$string['privacy_access_heading_help']= 'Quem pode ver e utilizar a barra lateral do Jedomi.';
$string['role_visibility_label']      = 'Mostrar barra lateral por papel';
$string['role_visibility_help']       = 'Desative para ocultar a barra lateral do Jedomi aos utilizadores com esse papel. Os administradores do site vêem-na sempre.';
$string['privacy_anon_heading']       = 'Anonimização de dados';
$string['privacy_anon_heading_help']  = 'Controla como os dados da conversa são anonimizados antes de serem armazenados.';
$string['hide_from_guests_help']      = 'Quando ativado, os convidados e utilizadores não autenticados não verão a barra lateral.';
// ── Anonymization descriptions ────────────────────────────────────────────────
$string['anon_basic_desc']            = 'Substitui nomes e emails. Mantém a maior parte do contexto intacto.';
$string['anon_strict_desc']           = 'Substitui nomes, emails, IDs e texto livre. Privacidade máxima.';
$string['anon_feedback_only_desc']    = 'Armazena apenas avaliações e botões de feedback. Sem texto da conversa.';
$string['gateway_url']     = 'URL do Gateway';
$string['gateway_url_help']= 'URL do Jedomi Gateway. Exemplo: http://localhost:8765';
$string['api_key']         = 'Chave da API';
$string['model']                     = 'Modelo';
$string['max_tokens']                = 'Máximo de tokens';
$string['max_tokens_help']           = 'Número máximo de tokens de saída por resposta (predefinição: 16384). Aumente para respostas mais longas, diminua para velocidade e custo.';
$string['enabled']         = 'Ativar barra lateral do Jedomi';
$string['spt_secret']      = 'Segredo do token de permissão';
$string['spt_secret_help'] = 'Segredo partilhado entre o Moodle e o Jedomi Gateway para assinar tokens de permissão. Deve corresponder a JEDOMI_SPT_SECRET no seu ficheiro .env.';
$string['collect_training_data']     = 'Recolher conversas anonimizadas para treino';
$string['collect_training_data_help']= 'Quando ativado, as conversas são armazenadas de forma anónima para melhorar futuras versões do modelo. Não são retidos dados pessoais.';
$string['anonymization_level']       = 'Nível de anonimização';
$string['anonymization_level_help']  = 'Grau de agressividade na anonimização das conversas armazenadas.';
$string['anon_basic']                = 'Básico (nomes e emails)';
$string['anon_strict']               = 'Estrito (nomes, emails, IDs, texto livre)';
$string['anon_feedback_only']        = 'Apenas feedback (sem texto de conversa armazenado)';
$string['training_data_title']       = 'Dados de Treino do Jedomi';
$string['course_availability_heading']     = 'Disponibilidade da disciplina';
$string['course_availability_heading_help']= 'Controla em que disciplinas é mostrada a barra lateral do Jedomi.';
$string['course_mode']               = 'Modo de disponibilidade';
$string['course_mode_help']          = 'Selecione como controlar a disponibilidade das disciplinas.';
$string['course_mode_all']           = 'Todas as disciplinas (predefinição)';
$string['course_mode_whitelist']     = 'Apenas disciplinas selecionadas (lista de permissões)';
$string['course_mode_blacklist']     = 'Todas as disciplinas exceto as selecionadas (lista de bloqueio)';
$string['course_list']               = 'IDs de disciplinas';
$string['course_list_help']          = 'Introduza os IDs das disciplinas separados por vírgulas ou quebras de linha. Exemplo: 5, 10, 23. Deixe vazio para não haver restrição.';
$string['hide_from_guests']          = 'Ocultar de utilizadores convidados';
$string['category_list']             = 'IDs de categorias';
$string['category_list_help']        = 'Introduza os IDs das categorias separados por vírgulas. Serão incluídas todas as disciplinas dessas categorias (incluindo subcategorias). Exemplo: 2, 5, 8';

// ── WS / security errors ──────────────────────────────────────────────────────
$string['non_select_rejected']    = 'Apenas são permitidas consultas SELECT.';
$string['query_timeout']          = 'A consulta excedeu o tempo limite.';
$string['execute_sql_error']      = 'Erro de base de dados ao executar a consulta.';
$string['unauthorized']           = 'Não autorizado.';
$string['read_file_blocked']      = 'O acesso a este ficheiro não é permitido.';
$string['read_file_not_allowed']  = 'Este caminho não está na lista permitida.';
$string['file_not_found']         = 'Ficheiro não encontrado ou não legível.';
$string['config_key_blocked']     = 'Esta chave de configuração está bloqueada por motivos de segurança.';
$string['path_not_allowed']       = 'Este caminho não é permitido.';
$string['drop_files_here']            = 'Largue os ficheiros aqui';
$string['manage_course_availability'] = 'Gerir disponibilidade da disciplina';
$string['course_mode_include']        = 'Mostrar apenas nas disciplinas selecionadas';
$string['course_mode_include_desc']   = 'A barra lateral só aparecerá nas disciplinas que escolher abaixo.';
$string['course_mode_exclude']        = 'Ocultar nas disciplinas selecionadas';
$string['course_mode_exclude_desc']   = 'A barra lateral aparecerá em todo o lado, exceto nas disciplinas que escolher abaixo.';
$string['course_mode_all_desc']       = 'A barra lateral aparecerá em todas as disciplinas do site.';
$string['course_availability_courses'] = 'Disciplinas selecionadas';
$string['course_search_placeholder']  = 'Pesquisar por nome ou nome abreviado...';
$string['course_search_hint']         = 'Escreva pelo menos 2 caracteres para pesquisar.';
$string['course_none_selected']       = 'Ainda não há disciplinas selecionadas.';
$string['course_search_noresults']    = 'Nenhuma disciplina encontrada.';
$string['course_remove']              = 'Remover disciplina';
$string['ai_disclaimer']          = 'O Jedomi é uma IA e pode cometer erros.';

// ── Telegram integration ──────────────────────────────────────────────────────
$string['tab_integrations']                = 'Integrações';
$string['telegram_heading']                = 'Telegram';
$string['telegram_heading_help']           = 'Liga o Jedomi a um bot do Telegram para que os utilizadores possam conversar através do Telegram.';
$string['telegram_webhook_status']         = 'Estado do webhook';
$string['check_webhook']                   = 'Verificar webhook';
$string['webhook_error']                   = 'Último erro do webhook:';
$string['webhook_ok']                      = 'O webhook está configurado e funcional.';
$string['webhook_error_alert_title']       = 'Problema no registo do webhook';
$string['webhook_error_alert_desc']        = 'O token do bot foi guardado e validado, mas não foi possível registar o webhook no Telegram.';
$string['webhook_error_detail']            = 'Detalhes do erro:';
$string['webhook_not_checked']             = 'Ainda não verificado. Clique em "Verificar webhook" para confirmar.';
$string['drop_pending_updates']            = 'Eliminar atualizações pendentes';
$string['drop_pending_updates_help']       = 'Limpa a fila de atualizações do Telegram para que mensagens antigas não sejam processadas quando o webhook reconectar. Útil durante testes.';
$string['drop_pending_success']            = '✅ Atualizações pendentes eliminadas com sucesso.';
$string['drop_pending_fail']               = '❌ Falha ao eliminar atualizações pendentes — verifique a resposta da API do Telegram.';
$string['telegram_enabled']                = 'Ativar integração com o Telegram';
$string['telegram_bot_token']              = 'Token do bot';
$string['telegram_bot_token_help']         = 'Cole o token do @BotFather. É encriptado antes de ser armazenado.';
$string['telegram_webhook_url']            = 'URL do Webhook';
$string['telegram_webhook_url_help']      = 'Copie este URL para o @BotFather com /setwebhook.';
$string['telegram_webhook_secret']         = 'Segredo do webhook';
$string['telegram_webhook_secret_help']    = 'Segredo aleatório adicionado ao URL do webhook por segurança. Regenere se for comprometido.';
$string['telegram_connection_status']     = 'Estado da ligação';
$string['telegram_status_connected']      = 'Ligado';
$string['telegram_status_disconnected']    = 'Desligado';
$string['telegram_status_pending']         = 'Pendente — cole o URL do webhook no @BotFather';
$string['telegram_rate_limit']             = 'Limite de taxa';
$string['telegram_rate_limit_help']        = 'Número máximo de mensagens por minuto que um utilizador pode enviar. 0 = ilimitado.';
$string['telegram_cooldown']              = 'Tempo de espera';
$string['telegram_cooldown_help']           = 'Minutos para bloquear um utilizador após exceder o limite de taxa.';
$string['telegram_role_visible']          = 'Permitir Telegram para o papel: {role}';
$string['telegram_role_visibility_help']   = 'Desative para impedir que utilizadores com este papel associem ou recebam mensagens do Telegram. Os administradores têm sempre acesso.';
$string['telegram_link_title']            = 'Associar Telegram';
$string['telegram_link_generate']         = 'Gerar código';
$string['telegram_link_code_expiry']      = 'Este código expira em 10 minutos.';
$string['telegram_linked_as']              = 'Associado como @{username}';
$string['telegram_not_linked']            = 'Não associado';
$string['telegram_link_instructions']      = 'Abra o Telegram, encontre o seu bot e envie /start seguido do código.';
$string['telegram_msg_link_first']       = 'Associe primeiro a sua conta: aceda ao seu perfil do Moodle e clique em "Associar Telegram".';
$string['telegram_msg_rate_limited']     = 'Demasiadas mensagens. Aguarde {minutes} minutos.';
$string['telegram_msg_role_blocked']     = 'O seu papel não tem acesso ao Jedomi Telegram.';
$string['telegram_msg_confirm']          = 'Confirmar';
$string['telegram_msg_cancel']            = 'Cancelar';
$string['telegram_msg_executing']         = 'A executar...';
$string['telegram_msg_done']              = 'Concluído.';
$string['telegram_msg_expired_code']      = 'Código inválido ou expirado. Gere um novo a partir do seu perfil do Moodle.';
$string['telegram_msg_unlinked']          = 'Associação removida. Envie /start para associar novamente.';
$string['telegram_msg_linked_success']    = '✅ Associado ao Moodle como <b>{$a->fullname}</b> ({$a->username})';
$string['telegram_msg_provide_code']      = '🔗 Indique o seu código de associação.\n\nExemplo: /start ABC123';
$string['telegram_msg_token_invalid']      = 'Token do bot inválido. Verifique o token do @BotFather e tente novamente.';
$string['telegram_msg_max_iterations']     = '⚠️ Demasiados passos. Simplifique o seu pedido.';
$string['telegram_still_working']          = '🔄 Ainda a trabalhar nisso, aguarde um momento…';
$string['telegram_msg_timeout']            = '⏳ Esta tarefa é demasiado complexa para o limite de tempo. Tente dividi-la em passos mais pequenos ou envie-a novamente e eu continuo de onde parei.';
$string['telegram_msg_new_conversation']   = '🗑️ Conversa limpa. Comece de novo!';

// ── Telegram help / commands ──────────────────────────────────
$string['telegram_help_heading']          = '🤖 <b>Bot Jedomi — Comandos Disponíveis</b>';
$string['telegram_help_start']            = '🔗 <b>/start CÓDIGO</b> — Associa a sua conta Moodle ao Telegram';
$string['telegram_help_help']             = '❓ <b>/help</b> — Mostra esta mensagem de ajuda';
$string['telegram_help_new']              = '🗑️ <b>/new</b> ou <b>/nuevo</b> — Limpa a conversa e começa de novo';
$string['telegram_help_unlink']           = '🚫 <b>/desvincular</b> ou <b>/unlink</b> — Remove a associação do Telegram ao Moodle';
$string['telegram_help_footer']           = 'Depois de associar, envie qualquer mensagem e eu reencaminho-a para o Jedomi AI para obter uma resposta.';

// ── Telegram inline keyboard labels ───────────────────────────
$string['telegram_keyboard_new']          = '📝 Nova conversa';
$string['telegram_keyboard_help']         = '❓ Ajuda';
$string['telegram_keyboard_unlink']       = '🚫 Remover associação';
$string['telegram_keyboard_howto_link']   = '🔗 Como associar?';

// ── Telegram error codes ──────────────────────────────────────
$string['error_telegram_e001']            = '❌ Chatbot não configurado (E001). Contacte o seu administrador.';
$string['error_telegram_e002']            = '❌ Falha ao enviar mensagem para o Telegram (E002). Tente novamente.';
$string['error_telegram_e003']            = '❌ Resposta inesperada do Telegram (E003). Tente novamente.';
$string['error_telegram_e004']            = '❌ Falha na ligação ao Jedomi (E004). Tente novamente mais tarde.';
$string['error_telegram_e005']            = '❌ O serviço Jedomi devolveu um erro (E005). Tente novamente.';
$string['error_telegram_e006']            = '❌ Resposta inválida do serviço Jedomi (E006). Tente novamente.';
$string['error_telegram_e007']            = '❌ Segredo de webhook inválido (E007). Contacte o seu administrador.';
$string['error_telegram_e008']            = '❌ Não tem permissão para consultar a disciplina com ID {$a}. Só são permitidas disciplinas onde tem permissões de edição.';

// ── Telegram config ───────────────────────────────────────────
$string['telegram_progress']              = '🤔 O Jedomi está a pensar…';
$string['telegram_max_turns']             = 'Número máximo de turnos de conversa';
$string['telegram_max_turns_help']        = 'Número de mensagens mantidas no histórico de conversa do Telegram (1-100). Valores mais baixos poupam tokens.';
$string['telegram_gateway_timeout']       = 'Tempo limite do Gateway (segundos)';
$string['telegram_gateway_timeout_help']  = 'Número máximo de segundos a aguardar pela resposta do Jedomi Gateway (5-300).';
$string['unlink']                        = 'Remover associação';
$string['regenerate']                    = 'Regenerar segredo';
$string['cleanup_telegram_sessions']     = 'Jedomi — Limpar sessões do Telegram expiradas';
$string['encryption_failed']             = 'Falha na encriptação. Verifique a configuração OpenSSL do seu servidor.';
$string['decryption_failed']             = 'Falha na desencriptação. O token pode ter sido corrompido ou o salt do site foi alterado.';

// ── Telegram setup guide ──────────────────────────────────────────────────────
// ── Agent Scheduled Tasks ──────────────────────────────────────────────────────
$string['tab_profiler']              = 'Analisador de Consultas';
$string['tab_agents']                = 'Agentes';
$string['agent_heading']             = 'Agentes de IA Agendados';
$string['agent_heading_help']        = 'Configure agentes de IA agendados que executam automaticamente e enviam resultados via Telegram.';
$string['agent_summary']             = 'Resumo';
$string['agent_by_role']             = 'Agentes por papel';
$string['agent_by_status']           = 'Agentes por estado';
$string['agent_executions_30d']      = 'Execuções (30 dias)';
$string['agent_filters']             = 'Filtros';
$string['agent_filter_role']         = 'Papel';
$string['agent_filter_status']       = 'Estado';
$string['agent_filter_context']      = 'Contexto';
$string['agent_search']              = 'Pesquisar prompt...';
$string['agent_apply_filters']       = 'Aplicar filtros';
$string['agent_table']               = 'Todos os agentes';
$string['agent_creator']             = 'Criador';
$string['agent_prompt']              = 'Prompt';
$string['agent_context']             = 'Contexto';
$string['agent_schedule']            = 'Agendamento';
$string['agent_status']              = 'Estado';
$string['agent_lastrun']             = 'Última execução';
$string['agent_nextrun']             = 'Próxima execução';
$string['agent_actions']             = 'Ações';
$string['agent_edit']                = 'Editar';
$string['agent_disable']             = 'Desativar';
$string['agent_delete']              = 'Eliminar';
$string['agent_create']              = 'Criar agente';
$string['agent_purge']               = 'Limpar agentes';
$string['agent_purge_confirm']       = 'Isto irá eliminar todos os agentes correspondentes e os seus resultados em cache. Continuar?';
$string['agent_no_results']          = 'Não foram encontrados agentes correspondentes aos seus filtros.';
$string['agent_create_success']      = 'Agente criado com sucesso.';
$string['agent_created_with_name']   = 'Agente &quot;{name}&quot; criado com sucesso.';
$string['agent_created_no_name']     = 'Agente criado, mas não foi definido um nome. Edite o agente para adicionar um nome.';
$string['agent_preview']             = 'Pré-visualização';
$string['agent_preview_result']      = 'Resultado da pré-visualização do agente';
$string['agent_preview_error']       = 'A pré-visualização falhou. Verifique a configuração do gateway e o prompt.';
$string['agent_update_success']      = 'Agente atualizado com sucesso.';
$string['agent_delete_confirm']      = 'Eliminar este agente? Esta ação não pode ser anulada.';
$string['agent_disabled']            = 'Desativado';
$string['agent_enabled']             = 'Ativado';
$string['agent_failing']             = 'Com falhas';
$string['agent_run_now']             = 'Executar agora';
$string['agent_schedule_presets']    = 'Predefinições de agendamento';
$string['agent_every_5min']          = 'A cada 5 minutos';
$string['agent_every_15min']         = 'A cada 15 minutos';
$string['agent_every_30min']         = 'A cada 30 minutos';
$string['agent_every_1h']            = 'A cada hora';
$string['agent_every_6h']            = 'A cada 6 horas';
$string['agent_daily']               = 'Diariamente';
$string['agent_weekly']              = 'Semanalmente';
$string['agent_custom_cron']         = 'Personalizado (expressão cron)';
$string['agent_context_type_site']   = 'Site';
$string['agent_context_type_course'] = 'Disciplina';
$string['agent_context_type_category'] = 'Categoria';
$string['agent_context_type_user']   = 'Utilizador';
$string['agent_prompt_placeholder']  = 'ex.: "Resume a nova atividade do site e notifica-me"';
$string['agent_status_all']          = 'Todos';
$string['agent_filter_apply']        = 'Aplicar';
$string['agent_filter_reset']        = 'Repor';
$string['agent_purge_context']       = 'Tipo de contexto';
$string['agent_purge_context_help']  = 'Elimina todos os agentes pertencentes a um contexto específico.';
$string['agent_purge_btn']           = 'Limpar';
$string['agent_purge_success']       = '{$a} agente(s) eliminado(s) com sucesso.';
$string['agent_error_load']          = 'Erro ao carregar agentes.';
$string['agent_error_create']        = 'Erro ao criar agente: {$a}';
$string['agent_error_update']        = 'Erro ao atualizar agente: {$a}';
$string['agent_error_delete']        = 'Erro ao eliminar agente: {$a}';
$string['agent_not_found']           = 'Agente não encontrado.';
$string['agent_run_queued']          = 'Agente colocado na fila para execução (próximo ciclo cron).';
$string['agent_runner_task']         = 'Jedomi — Executar agentes de IA agendados';
$string['agent_enable']              = 'Ativar';

$string['copytoclipboard']               = 'Copiar para a área de transferência';
$string['telegram_setup_guide']          = 'Guia de configuração';
$string['telegram_setup_guide_help']     = 'Siga estes passos para ligar o Jedomi ao Telegram. Depois de configurado, os utilizadores podem associar as suas contas a partir do seu perfil.';
$string['telegram_setup_done']           = 'Integração configurada';
$string['telegram_setup_done_help']      = 'O Telegram está ativo. Os utilizadores com papéis permitidos podem associar as suas contas a partir do seu perfil do Moodle.';
$string['telegram_setup_show']           = 'Mostrar guia de configuração';
$string['telegram_setup_hide']           = 'Ocultar guia';

$string['telegram_step_admin_title']     = 'Configuração do administrador';
$string['telegram_step_admin_help']      = 'Só precisa de ser feito uma vez. Requer acesso ao Telegram.';
$string['telegram_step1_title']          = 'Criar um bot no @BotFather';
$string['telegram_step1_desc']           = 'Abra o Telegram, procure por <strong>@BotFather</strong>, envie <code>/newbot</code>, escolha um nome e um nome de utilizador. Copie o token fornecido (formato: <code>123456:ABC-DEF...</code>).';
$string['telegram_step2_title']          = 'Colar o token e guardar';
$string['telegram_step2_desc']           = 'Cole o token no campo <strong>Token do bot</strong> abaixo, ative o interruptor de integração e clique em <strong>Guardar alterações</strong>.';
$string['telegram_step3_title']          = 'Registar o webhook no Telegram';
$string['telegram_step3_desc']           = 'Copie o <strong>URL do Webhook</strong> mostrado após guardar. Depois vá ao @BotFather e execute: <code>/setwebhook</code>. Ou utilize este comando curl:';
$string['telegram_step3_note']           = 'Para desenvolvimento local (DDEV), utilize primeiro o ngrok para obter um URL HTTPS público: <code>ngrok http https://moodle2.ddev.site --host-header=moodle2.ddev.site</code>';
$string['telegram_step4_title']          = 'Configurar acesso por papel (opcional)';
$string['telegram_step4_desc']           = 'Utilize os interruptores de papel abaixo para controlar que papéis do Moodle podem utilizar o Telegram. Os administradores do site têm sempre acesso, independentemente desta definição.';

$string['telegram_step_user_title']      = 'Configuração do utilizador (cada utilizador faz isto uma vez)';
$string['telegram_step_user_help']       = 'Depois de o administrador configurar o bot, os utilizadores associam as suas próprias contas do Telegram.';
$string['telegram_stepU1_title']         = 'Aceder ao seu perfil do Moodle';
$string['telegram_stepU1_desc']          = 'Clique no seu nome na barra superior → <strong>Perfil</strong>. Verá uma secção <strong>Jedomi AI</strong> com a ligação <em>Associar Telegram</em>.';
$string['telegram_stepU2_title']         = 'Gerar um código de associação';
$string['telegram_stepU2_desc']          = 'Clique em <strong>Gerar código</strong>. Aparece um código de 6 caracteres, válido por 10 minutos.';
$string['telegram_stepU3_title']         = 'Enviar /start ao bot';
$string['telegram_stepU3_desc']          = 'No Telegram, abra o bot da sua instituição e envie: <code>/start O_SEU_CÓDIGO</code>. O bot confirma a associação instantaneamente.';
$string['telegram_stepU4_title']         = 'Começar a conversar';
$string['telegram_stepU4_desc']          = 'Envie qualquer mensagem ao bot. O Jedomi responde com dados reais do seu Moodle. Para remover a associação, envie <code>/desvincular</code> ou utilize a sua página de perfil.';

// ── WS Discovery / Execution ────────────────────────────────────────────────
$string['tab_apis']                = 'APIs';
$string['tab_apis_heading']        = 'Listas de permissões de web services';
$string['tab_apis_heading_help']   = 'Configure a que web services do Moodle cada papel pode aceder através do Jedomi.';
$string['api_role_admin']          = 'Admin';
$string['api_role_manager']        = 'Gestor';
$string['api_role_teacher']        = 'Professor';
$string['api_role_student']        = 'Aluno';
$string['filter_all']              = 'Todos';
$string['filter_read']             = 'Apenas leitura';
$string['filter_write']            = 'Apenas escrita';
$string['filter_type']             = 'Tipo:';
$string['component_all']           = 'Todos os componentes';
$string['ws_name']                 = 'Nome';
$string['ws_description']          = 'Descrição';
$string['ws_component']            = 'Componente';
$string['ws_type']                 = 'Tipo';
$string['ws_type_read']            = 'Leitura';
$string['ws_type_write']           = 'Escrita';
$string['ws_type_all']             = 'Todos os tipos';
$string['search_ws_placeholder']   = 'Pesquisar web services...';
$string['ws_not_in_allowlist']     = 'Este web service não está na lista de permissões do seu papel.';
$string['ws_not_found']            = 'Função de web service não encontrada.';
$string['ws_execute_error']        = 'Erro ao executar o web service: {$a}';
$string['ws_no_services']          = 'Nenhum web service configurado para este papel.';
$string['ws_pagination']           = 'Página {$a->page} de {$a->pages} ({$a->total} serviços)';
$string['ws_none_found']           = 'Nenhum web service corresponde aos seus filtros.';
$string['tool_ws_execute_desc']    = 'Execute a Moodle web service function on behalf of the current user. Use AFTER ws_search to get the exact function name and parameters. If the first attempt fails with "Missing required key" or "invalid parameter", retry with default values for optional fields. For batch operations (same action on multiple items), call this function MULTIPLE times — once per item.';

// ── Tool execution errors ────────────────────────────────────────────
$string['error_tool_loop_detected'] = 'Chamada de ferramenta idêntica e repetida bloqueada. Utilize uma ferramenta diferente ou execute com novos parâmetros.';
$string['error_fallback_iterations'] = 'Desculpe, não consegui concluir a operação após várias tentativas.';
$string['error_unknown_tool'] = 'Ferramenta desconhecida: {$a}';
$string['error_permission_denied'] = 'permission_denied';
$string['error_query_required'] = 'É necessário indicar "query"';
$string['error_search_tables_failed'] = 'search_tables falhou: {$a}';
$string['error_ws_search_failed'] = 'ws_search falhou: {$a}';
$string['error_ws_describe_failed'] = 'ws_describe falhou: {$a}';
$string['error_ws_execute_failed'] = 'ws_execute falhou: {$a}';
$string['error_wsname_required'] = 'É necessário indicar "wsname"';
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
$string['summary_invalid_json'] = 'erro: resposta JSON inválida';
$string['summary_error_prefix'] = 'erro: ';
$string['summary_rows_returned'] = ' linhas devolvidas';
$string['summary_table_structure'] = 'estrutura da tabela obtida';
$string['summary_matching_tables'] = ' tabelas correspondentes';
$string['summary_config_data'] = 'dados de configuração obtidos';
$string['summary_functions_found'] = ' funções encontradas';
$string['summary_function_details'] = 'detalhes da função obtidos';
$string['summary_ws_executed'] = 'web service executado com sucesso';
$string['summary_done'] = 'concluído';

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
$string['ws_function_not_found'] = 'Função não encontrada: {$a}';
$string['ws_cannot_load_info'] = 'Não é possível carregar informação da função: {$a}';

// ── Table descriptor errors ──────────────────────────────────────────
$string['table_not_found'] = 'Tabela não encontrada: {$a}';

// ── Set site config ──────────────────────────────────────────────────
$string['config_key_blocked_msg'] = 'A chave está bloqueada por motivos de segurança.';
$string['config_updated_msg'] = 'Configuração \'{$a}\' atualizada com sucesso.';

// ── Run task ─────────────────────────────────────────────────────────
$string['task_not_found_msg'] = 'task_not_found';
$string['task_instantiation_failed_msg'] = 'task_instantiation_failed';

// ── WS execute errors ────────────────────────────────────────────────
$string['ws_method_not_exists'] = 'O método {$a} não existe';
$string['ws_debug_prefix'] = ' | depuração: ';

// ── Agent SQL profiler ───────────────────────────────────────────────
$string['query_profiler'] = 'Analisador de consultas';
$string['query_profiler_heading'] = 'Analisador de consultas SQL';
$string['query_profiler_desc'] = 'Regista todas as consultas SQL executadas pelos agentes, incluindo o tempo de execução e o número de linhas. Utilize o filtro abaixo para encontrar consultas lentas.';
$string['query_profiler_total'] = 'Total de consultas';
$string['query_profiler_slow'] = 'Consultas lentas (>1s)';
$string['query_profiler_avg'] = 'Duração média';
$string['query_profiler_max'] = 'Duração máxima';
$string['query_profiler_sort_duration'] = 'Duração';
$string['query_profiler_sort_time'] = 'Hora';
$string['query_profiler_sort_query'] = 'Consulta';
$string['query_profiler_empty'] = 'Ainda não há consultas registadas. Os agentes registarão aqui as consultas quando utilizarem a ferramenta SQL.';
$string['query_profiler_threshold'] = 'Limite de consulta lenta (ms)';
$string['query_profiler_recent'] = 'Consultas recentes';
$string['query_profiler_slowest'] = 'Consultas mais lentas (média)';

// ── Agent WS allowlist ───────────────────────────────────────────────
$string['agent_ws_allowlist'] = 'Lista de permissões de web services para agentes';
$string['agent_ws_allowlist_help'] = 'Lista separada por vírgulas dos nomes das funções de web service do Moodle que os agentes têm permissão para chamar. Deixe vazio para o conjunto predefinido.';

// ── Agent name ───────────────────────────────────────────────────────
$string['agent_name'] = 'Nome';
$string['agent_name_placeholder'] = 'ex.: Relatório Diário';
$string['agent_unnamed'] = 'Agente';
// ── Document reader ─────────────────────────────────────────────────
$string['tool_document_read_desc'] = 'Read a PDF file from ANY course activity using its cmid (course module ID). Converts PDF pages to base64 images so you see the EXACT original layout, tables, images, and formatting. Use this when the user asks about a document. The cmid is available in the context when viewing an activity page.';
$string['tool_document_read_cmid'] = 'Course module ID (cmid) of the activity. Get it from the page context when viewing an activity, or discover it via ws_search/ws_execute first.';
$string['tool_document_read_dpi'] = 'Image resolution (default: 150). Higher values = better quality but more tokens.';
$string['tool_document_read_fmt'] = 'Image format: JPEG (smaller) or PNG (lossless). Default: JPEG.';
$string['docservice_url'] = 'URL do serviço de conversão de documento para imagem';
$string['docservice_url_help'] = 'URL do serviço de conversão de documentos em imagens que converte PDFs em imagens base64 (ex.: http://localhost:8000).';
