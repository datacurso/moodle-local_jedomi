<?php
// ── Plugin identity ───────────────────────────────────────────────────────────
$string['pluginname']      = 'Barre latérale Jedomi AI';

// ── Sidebar UI ────────────────────────────────────────────────────────────────
$string['sidebar_title']   = 'Jedomi AI';
$string['open_sidebar']    = 'Ouvrir Jedomi AI';
$string['close']           = 'Fermer (Échap)';
$string['new_conversation']= 'Nouvelle conversation';
$string['export_conversation'] = 'Exporter la conversation';
$string['toggle_thinking'] = 'Activer/désactiver l’animation de réflexion';
$string['half_width']      = 'Demi-largeur';
$string['full_width']      = 'Pleine largeur';

// ── Input area ────────────────────────────────────────────────────────────────
$string['attach']           = 'Joindre';
$string['attach_file']      = 'Joindre un PDF/CSV';
$string['attach_image']     = 'Joindre une image';
$string['chat_placeholder'] = 'Demandez à Jedomi...';
$string['file']             = 'Fichier';
$string['image']            = 'Image';
$string['message_input']    = 'Message à Jedomi';
$string['send']             = 'Envoyer';
$string['voice_input']      = 'Saisie vocale';

// ── Greeting ──────────────────────────────────────────────────────────────────
$string['greeting']        = 'Bonjour, {name} !';
$string['hello']           = "Je suis Jedomi, votre assistant IA Moodle. Posez-moi des questions sur les cours, les utilisateurs, les notes ou les paramètres.";
$string['thinking']        = 'Réflexion en cours...';

// ── New conversation ──────────────────────────────────────────────────────────
$string['new_conversation_msg'] = 'Nouvelle conversation démarrée. Comment puis-je vous aider ?';

// ── Image / file attachment ───────────────────────────────────────────────────
$string['analyze_image']       = 'Analysez cette image.';
$string['attached_file_info']  = '[Fichier joint : {$a}]';
$string['attached_image']      = 'Image jointe';
$string['csv_download']        = '📥 Télécharger le CSV';
$string['file_csv']            = 'CSV : {rows} lignes, {columns} colonnes';
$string['file_loaded']         = 'chargé';
$string['file_loaded_hint']    = 'Vous pouvez poser des questions sur son contenu.';
$string['file_pdf']            = 'PDF : {pages} pages';
$string['file_process_error']  = 'Impossible de traiter';
$string['file_text']           = 'Texte : {chars} caractères';
$string['processing_file']     = 'Traitement de {$a}...';

// ── Tool execution status ─────────────────────────────────────────────────────
$string['tool_sql_read']          = 'Interrogation de la base de données...';
$string['tool_api_call']          = 'Exécution d’une action Moodle...';
$string['tool_schema_lookup']     = 'Recherche de la table...';
$string['tool_webservice_lookup'] = 'Recherche de la fonction API...';
$string['tool_get_site_config']    = 'Récupération de la configuration du site...';
$string['tool_execute_sql']       = 'Interrogation de la base de données...';
$string['tool_search_tables']     = 'Recherche dans les tables...';
$string['tool_describe_table']    = 'Analyse de la table...';
$string['tool_ws_search']         = 'Recherche des fonctions de services web...';
$string['tool_ws_describe']       = 'Récupération des détails de la fonction de service web...';
$string['tool_ws_execute']        = 'Exécution du service web...';
$string['tool_unknown']           = 'Exécution de l’outil...';

// ── Message actions ───────────────────────────────────────────────────────────
$string['action_copy']         = 'Copier';
$string['action_helpful']      = 'Utile';
$string['action_improve']      = 'Améliorer';
$string['action_improve_title']= 'À améliorer';
$string['action_edit']         = 'Modifier';
$string['action_edit_title']   = 'Modifier le message';

// ── Bulk message confirmation ─────────────────────────────────────────────────
$string['confirm_send_bulk']       = 'Envoyer ce message à {count} étudiant(s) ?';
$string['yes_send']                = 'Oui, envoyer';
$string['cancel']                  = 'Annuler';
$string['sending']                 = 'Envoi en cours...';
$string['cancelled']               = 'Annulé.';
$string['messages_sent']           = '{sent} message(s) envoyé(s) sous forme de messages internes Moodle.';
$string['messages_sent_fail']      = '({failed} échoué(s))';
$string['messages_recipients_note']= 'Les destinataires les verront dans leur boîte de messages Moodle.';
$string['no_messages_sent']        = 'Aucun message envoyé.';
$string['unknown_error']           = 'Erreur inconnue';
$string['connection_error']        = 'Erreur de connexion :';

// ── Gateway error ─────────────────────────────────────────────────────────────
$string['gateway_error_title'] = 'Impossible de se connecter à la passerelle Jedomi.';
$string['gateway_error_hint']  = 'Assurez-vous que le service est en cours d’exécution :';

// ── Session expired ───────────────────────────────────────────────────────────
$string['session_expired_title'] = 'Session expirée.';
$string['session_expired_hint']  = 'Votre session Moodle a expiré. Veuillez recharger la page pour continuer.';

// ── Microphone ────────────────────────────────────────────────────────────────
$string['listening']        = 'Écoute en cours...';
$string['mic_error']        = 'Impossible d’accéder au microphone. Veuillez vérifier les autorisations de votre navigateur.';
$string['silence_detected'] = 'Silence...';
$string['transcribing']     = 'Transcription en cours...';
$string['waiting_voice']    = 'En attente de la voix...';

// ── Confirmation system (destructive actions) ────────────────────────────────
$string['confirm_type_to_proceed'] = 'Tapez CONFIRMER pour continuer :';
$string['confirm_btn_yes']         = 'Confirmer';
$string['confirm_btn_no']          = 'Annuler';
$string['confirm_executing']       = 'Exécution en cours...';
$string['confirm_done']            = 'Action exécutée avec succès.';
$string['confirm_cancelled']       = 'Action annulée.';

// ── Context labels (sidebar badge) ───────────────────────────────────────────
$string['ctx_activity']  = 'Activité';
$string['ctx_admin']     = 'Administration';
$string['ctx_calendar']  = 'Calendrier';
$string['ctx_course']    = 'Cours';
$string['ctx_dashboard'] = 'Tableau de bord';
$string['ctx_files']     = 'Fichiers';
$string['ctx_grades']    = 'Notes';
$string['ctx_messages']  = 'Messages';
$string['ctx_profile']   = 'Profil';

// ── Quick actions: Admin ──────────────────────────────────────────────────────
$string['qa_admin_active_users_label']    = 'Utilisateurs actifs';
$string['qa_admin_active_users_msg']      = 'Combien d’utilisateurs actifs y a-t-il dans le système ?';
$string['qa_admin_alerts_label']          = 'Alertes';
$string['qa_admin_alerts_msg']            = 'Qu’est-ce qui nécessite une attention immédiate aujourd’hui ?';
$string['qa_admin_at_risk_label']         = 'À risque';
$string['qa_admin_at_risk_msg']           = 'Quels étudiants risquent d’échouer dans le cours {$a} ?';
$string['qa_admin_empty_cohorts_label']   = 'Cohortes vides';
$string['qa_admin_empty_cohorts_msg']     = 'Y a-t-il des cohortes sans membres assignés ?';
$string['qa_admin_external_emails_label'] = 'E-mails externes';
$string['qa_admin_external_emails_msg']   = 'Y a-t-il des utilisateurs avec des e-mails non institutionnels ?';
$string['qa_admin_gdpr_pending_label']    = 'RGPD en attente';
$string['qa_admin_gdpr_pending_msg']      = 'Y a-t-il des demandes de suppression RGPD en attente ?';
$string['qa_admin_inactive_label']        = 'Inactifs';
$string['qa_admin_inactive_msg']          = 'Quels étudiants n’ont pas accédé au cours {$a} au cours des 30 derniers jours ?';
$string['qa_admin_missing_work_label']    = 'Travaux manquants';
$string['qa_admin_missing_work_msg']      = 'Quels étudiants n’ont pas soumis d’activités dans le cours {$a} ?';
$string['qa_admin_overview_label']        = 'Vue d’ensemble';
$string['qa_admin_overview_msg']          = 'Donnez-moi un résumé de l’état général de la plateforme';
$string['qa_admin_performance_label']     = 'Performance';
$string['qa_admin_performance_msg']       = 'Montrez-moi le classement des notes du cours {$a}';
$string['qa_admin_security_label']        = 'Sécurité';
$string['qa_admin_security_msg']          = 'Y a-t-il des utilisateurs avec des caractères suspects dans leur nom ?';
$string['qa_admin_storage_label']         = 'Stockage';
$string['qa_admin_storage_msg']           = 'Quel cours contient les fichiers téléchargés les plus lourds ?';
$string['qa_admin_top_courses_label']     = 'Meilleurs cours';
$string['qa_admin_top_courses_msg']       = 'Quels cours ont les meilleures performances ?';
$string['qa_admin_top_student_label']     = 'Meilleur étudiant';
$string['qa_admin_top_student_msg']       = 'Qui est le meilleur étudiant du cours {$a} ?';

// ── Quick actions: Manager ────────────────────────────────────────────────────
$string['qa_manager_at_risk_label']       = 'À risque';
$string['qa_manager_at_risk_msg']         = 'Quels étudiants sont à risque dans le cours {$a} ?';
$string['qa_manager_enrolled_label']      = 'Inscrits';
$string['qa_manager_enrolled_msg']        = 'Combien d’étudiants sont inscrits dans le cours {$a} ?';
$string['qa_manager_global_risk_label']   = 'Risque global';
$string['qa_manager_global_risk_msg']     = 'Y a-t-il des étudiants à risque d’échouer dans un cours quelconque ?';
$string['qa_manager_inactive_label']      = 'Inactifs';
$string['qa_manager_inactive_msg']        = 'Quels étudiants n’ont pas accédé au cours {$a} au cours des 14 derniers jours ?';
$string['qa_manager_inactive_users_label'] = 'Utilisateurs inactifs';
$string['qa_manager_inactive_users_msg']  = 'Quels utilisateurs n’ont pas accédé au système au cours des 30 derniers jours ?';
$string['qa_manager_no_teacher_label']    = 'Sans enseignant';
$string['qa_manager_no_teacher_msg']      = 'Y a-t-il des cours sans enseignant assigné ?';
$string['qa_manager_overview_label']      = 'Vue d’ensemble';
$string['qa_manager_overview_msg']        = 'Donnez-moi un résumé du cours {$a}';
$string['qa_manager_top_courses_label']   = 'Meilleurs cours';
$string['qa_manager_top_courses_msg']     = 'Quels cours ont le plus d’activité cette semaine ?';

// ── Quick actions: Teacher ────────────────────────────────────────────────────
$string['qa_teacher_at_risk_label']       = 'À risque';
$string['qa_teacher_at_risk_msg']         = 'Quels étudiants sont à risque dans le cours {$a} ?';
$string['qa_teacher_my_alerts_label']     = 'Mes alertes';
$string['qa_teacher_my_alerts_msg']       = 'Y a-t-il des étudiants à risque dans l’un de mes cours ?';
$string['qa_teacher_my_courses_label']    = 'Mes cours';
$string['qa_teacher_my_courses_msg']      = 'Dans quels cours suis-je actuellement enseignant ?';
$string['qa_teacher_missing_work_label']  = 'Travaux manquants';
$string['qa_teacher_missing_work_msg']    = 'Qui n’a pas soumis le dernier devoir dans le cours {$a} ?';
$string['qa_teacher_never_logged_label']  = 'Jamais connecté';
$string['qa_teacher_never_logged_msg']    = 'Quels étudiants n’ont jamais accédé au cours {$a} ?';
$string['qa_teacher_notify_group_label']  = 'Notifier le groupe';
$string['qa_teacher_notify_group_msg']    = 'Rédigez un message pour les étudiants du cours {$a} leur rappelant les activités en attente';
$string['qa_teacher_performance_label']   = 'Performance';
$string['qa_teacher_performance_msg']     = 'Quelle est la note moyenne dans le cours {$a} ?';
$string['qa_teacher_top_students_label']  = 'Meilleurs étudiants';
$string['qa_teacher_top_students_msg']    = 'Qui a plus de 90 points dans le cours {$a} ?';

// ── Quick actions: Student ────────────────────────────────────────────────────
$string['qa_student_help_label']          = 'Aide';
$string['qa_student_help_msg']            = 'Je ne comprends pas le contenu du cours {$a}. Pouvez-vous m’expliquer ?';
$string['qa_student_my_courses_label']    = 'Mes cours';
$string['qa_student_my_courses_msg']      = 'Dans quels cours suis-je actuellement inscrit ?';
$string['qa_student_my_grade_label']      = 'Ma note';
$string['qa_student_my_grade_msg']        = 'Quelle est ma moyenne dans le cours {$a} ?';
$string['qa_student_my_grades_label']     = 'Mes notes';
$string['qa_student_my_grades_msg']       = 'Quelle est ma moyenne générale sur tous mes cours ?';
$string['qa_student_my_progress_label']   = 'Ma progression';
$string['qa_student_my_progress_msg']     = 'Combien d’activités ai-je terminées dans le cours {$a} ?';
$string['qa_student_my_tasks_label']      = 'Mes tâches';
$string['qa_student_my_tasks_msg']        = 'Quels devoirs ai-je en attente dans le cours {$a} ?';
$string['qa_student_this_week_label']     = 'Cette semaine';
$string['qa_student_this_week_msg']       = 'Quelles activités et échéances ai-je à venir ?';

// ── Admin settings ────────────────────────────────────────────────────────────
$string['settings']        = 'Paramètres Jedomi';
// ── Admin tabs ────────────────────────────────────────────────────────────────
$string['settings_redirect_help']     = 'Les paramètres de Jedomi sont gérés dans une page dédiée avec une interface à onglets.';
$string['open_admin_settings']        = 'Ouvrir les paramètres Jedomi';
$string['tab_general']                = 'Général';
$string['tab_privacy']                = 'Confidentialité';
// ── General tab headings ──────────────────────────────────────────────────────
$string['gateway_heading']            = 'Connexion à la passerelle';
$string['gateway_heading_help']       = 'Paramètres de connexion pour le backend Jedomi AI.';
$string['security_heading']           = 'Sécurité';
$string['security_heading_help']      = 'Jetons et secrets partagés entre Moodle et la passerelle.';
$string['improvement_heading']        = 'Amélioration continue';
$string['improvement_heading_help']   = 'Collecte de données optionnelle pour améliorer Jedomi au fil du temps.';
$string['enabled_help']               = 'Afficher ou masquer la barre latérale Jedomi sur l’ensemble du site.';
// ── Privacy tab headings ──────────────────────────────────────────────────────
$string['privacy_access_heading']     = 'Contrôle d’accès';
$string['privacy_access_heading_help']= 'Qui peut voir et utiliser la barre latérale Jedomi.';
$string['role_visibility_label']      = 'Afficher la barre latérale par rôle';
$string['role_visibility_help']       = 'Désactivez pour masquer la barre latérale Jedomi aux utilisateurs ayant ce rôle. Les administrateurs du site la voient toujours.';
$string['privacy_anon_heading']       = 'Anonymisation des données';
$string['privacy_anon_heading_help']  = 'Contrôlez la manière dont les données de conversation sont anonymisées avant leur stockage.';
$string['hide_from_guests_help']      = 'Lorsque cette option est activée, les invités et les utilisateurs non authentifiés ne verront pas la barre latérale.';
// ── Anonymization descriptions ────────────────────────────────────────────────
$string['anon_basic_desc']            = 'Remplace les noms et les e-mails. Conserve la majeure partie du contexte intacte.';
$string['anon_strict_desc']           = 'Remplace les noms, les e-mails, les identifiants et le texte libre. Confidentialité maximale.';
$string['anon_feedback_only_desc']    = 'Ne stocke que les évaluations et les boutons de retour. Aucun texte de conversation.';
$string['gateway_url']     = 'URL de la passerelle';
$string['gateway_url_help']= 'URL de la passerelle Jedomi. Exemple : http://localhost:8765';
$string['api_key']         = 'Clé API';
$string['model']                     = 'Modèle';
$string['max_tokens']                = 'Jetons maximum';
$string['max_tokens_help']           = 'Nombre maximum de jetons de sortie par réponse (par défaut : 16384). Augmentez pour des réponses plus longues, diminuez pour la vitesse et le coût.';
$string['enabled']         = 'Activer la barre latérale Jedomi';
$string['spt_secret']      = 'Secret du jeton de permission';
$string['spt_secret_help'] = 'Secret partagé entre Moodle et la passerelle Jedomi pour signer les jetons de permission. Doit correspondre à JEDOMI_SPT_SECRET dans votre fichier .env.';
$string['collect_training_data']     = 'Collecter des conversations anonymisées pour l’entraînement';
$string['collect_training_data_help']= 'Lorsque cette option est activée, les conversations sont stockées de manière anonyme pour améliorer les futures versions du modèle. Aucune donnée personnelle n’est conservée.';
$string['anonymization_level']       = 'Niveau d’anonymisation';
$string['anonymization_level_help']  = 'Degré d’agressivité de l’anonymisation des conversations stockées.';
$string['anon_basic']                = 'Basique (noms et e-mails)';
$string['anon_strict']               = 'Strict (noms, e-mails, identifiants, texte libre)';
$string['anon_feedback_only']        = 'Retour uniquement (aucun texte de conversation stocké)';
$string['training_data_title']       = 'Données d’entraînement Jedomi';
$string['course_availability_heading']     = 'Disponibilité par cours';
$string['course_availability_heading_help']= 'Contrôlez quels cours affichent la barre latérale Jedomi.';
$string['course_mode']               = 'Mode de disponibilité';
$string['course_mode_help']          = 'Sélectionnez comment contrôler la disponibilité par cours.';
$string['course_mode_all']           = 'Tous les cours (par défaut)';
$string['course_mode_whitelist']     = 'Uniquement les cours sélectionnés (liste blanche)';
$string['course_mode_blacklist']     = 'Tous les cours sauf ceux sélectionnés (liste noire)';
$string['course_list']               = 'Identifiants de cours';
$string['course_list_help']          = 'Saisissez les identifiants de cours séparés par des virgules ou des retours à la ligne. Exemple : 5, 10, 23. Laissez vide pour aucune restriction.';
$string['hide_from_guests']          = 'Masquer aux utilisateurs invités';
$string['category_list']             = 'Identifiants de catégories';
$string['category_list_help']        = 'Saisissez les identifiants de catégories séparés par des virgules. Tous les cours de ces catégories (y compris les sous-catégories) seront inclus. Exemple : 2, 5, 8';

// ── WS / security errors ──────────────────────────────────────────────────────
$string['non_select_rejected']    = 'Seules les requêtes SELECT sont autorisées.';
$string['query_timeout']          = 'La requête a expiré.';
$string['execute_sql_error']      = 'Erreur de base de données lors de l’exécution de la requête.';
$string['unauthorized']           = 'Non autorisé.';
$string['read_file_blocked']      = 'L’accès à ce fichier n’est pas autorisé.';
$string['read_file_not_allowed']  = 'Ce chemin ne figure pas dans la liste autorisée.';
$string['file_not_found']         = 'Fichier introuvable ou illisible.';
$string['config_key_blocked']     = 'Cette clé de configuration est bloquée pour des raisons de sécurité.';
$string['path_not_allowed']       = 'Ce chemin n’est pas autorisé.';
$string['drop_files_here']            = 'Déposez les fichiers ici';
$string['manage_course_availability'] = 'Gérer la disponibilité par cours';
$string['course_mode_include']        = 'Afficher uniquement dans les cours sélectionnés';
$string['course_mode_include_desc']   = 'La barre latérale n’apparaîtra que dans les cours que vous choisissez ci-dessous.';
$string['course_mode_exclude']        = 'Masquer dans les cours sélectionnés';
$string['course_mode_exclude_desc']   = 'La barre latérale apparaîtra partout, sauf dans les cours que vous choisissez ci-dessous.';
$string['course_mode_all_desc']       = 'La barre latérale apparaîtra sur tous les cours du site.';
$string['course_availability_courses'] = 'Cours sélectionnés';
$string['course_search_placeholder']  = 'Rechercher par nom ou nom abrégé...';
$string['course_search_hint']         = 'Saisissez au moins 2 caractères pour lancer la recherche.';
$string['course_none_selected']       = 'Aucun cours sélectionné pour l’instant.';
$string['course_search_noresults']    = 'Aucun cours trouvé.';
$string['course_remove']              = 'Retirer le cours';
$string['ai_disclaimer']          = 'Jedomi est une IA et peut faire des erreurs.';

// ── Telegram integration ──────────────────────────────────────────────────────
$string['tab_integrations']                = 'Intégrations';
$string['telegram_heading']                = 'Telegram';
$string['telegram_heading_help']           = 'Connectez Jedomi à un bot Telegram afin que les utilisateurs puissent discuter via Telegram.';
$string['telegram_webhook_status']         = 'Statut du webhook';
$string['check_webhook']                   = 'Vérifier le webhook';
$string['webhook_error']                   = 'Dernière erreur de webhook :';
$string['webhook_ok']                      = 'Le webhook est configuré et fonctionne correctement.';
$string['webhook_error_alert_title']       = 'Problème d’enregistrement du webhook';
$string['webhook_error_alert_desc']        = 'Le jeton du bot a été enregistré et validé, mais le webhook n’a pas pu être enregistré auprès de Telegram.';
$string['webhook_error_detail']            = 'Détails de l’erreur :';
$string['webhook_not_checked']             = 'Pas encore vérifié. Cliquez sur « Vérifier le webhook » pour valider.';
$string['drop_pending_updates']            = 'Purger les mises à jour en attente';
$string['drop_pending_updates_help']       = 'Vide la file d’attente des mises à jour Telegram afin que les messages obsolètes ne soient pas traités lors de la reconnexion du webhook. Utile pendant les tests.';
$string['drop_pending_success']            = '✅ Mises à jour en attente purgées avec succès.';
$string['drop_pending_fail']               = '❌ Échec de la purge des mises à jour en attente — vérifiez la réponse de l’API Telegram.';
$string['telegram_enabled']                = 'Activer l’intégration Telegram';
$string['telegram_bot_token']              = 'Jeton du bot';
$string['telegram_bot_token_help']         = 'Collez le jeton fourni par @BotFather. Il est chiffré avant stockage.';
$string['telegram_webhook_url']            = 'URL du webhook';
$string['telegram_webhook_url_help']      = 'Copiez cette URL dans @BotFather avec /setwebhook.';
$string['telegram_webhook_secret']         = 'Secret du webhook';
$string['telegram_webhook_secret_help']    = 'Secret aléatoire ajouté à l’URL du webhook pour la sécurité. Régénérez-le en cas de compromission.';
$string['telegram_connection_status']     = 'Statut de connexion';
$string['telegram_status_connected']      = 'Connecté';
$string['telegram_status_disconnected']    = 'Déconnecté';
$string['telegram_status_pending']         = 'En attente — collez l’URL du webhook dans @BotFather';
$string['telegram_rate_limit']             = 'Limite de débit';
$string['telegram_rate_limit_help']        = 'Nombre maximum de messages par minute qu’un utilisateur peut envoyer. 0 = illimité.';
$string['telegram_cooldown']              = 'Délai de récupération';
$string['telegram_cooldown_help']           = 'Minutes de blocage d’un utilisateur après dépassement de la limite de débit.';
$string['telegram_role_visible']          = 'Autoriser Telegram pour le rôle : {role}';
$string['telegram_role_visibility_help']   = 'Désactivez pour empêcher les utilisateurs ayant ce rôle de lier leur compte ou de recevoir des messages Telegram. Les administrateurs ont toujours accès.';
$string['telegram_link_title']            = 'Lier Telegram';
$string['telegram_link_generate']         = 'Générer un code';
$string['telegram_link_code_expiry']      = 'Ce code expire dans 10 minutes.';
$string['telegram_linked_as']              = 'Lié en tant que @{username}';
$string['telegram_not_linked']            = 'Non lié';
$string['telegram_link_instructions']      = 'Ouvrez Telegram, trouvez votre bot, puis envoyez /start suivi du code.';
$string['telegram_msg_link_first']       = 'Liez d’abord votre compte : allez dans votre profil Moodle et cliquez sur « Lier Telegram ».';
$string['telegram_msg_rate_limited']     = 'Trop de messages. Veuillez attendre {minutes} minutes.';
$string['telegram_msg_role_blocked']     = 'Votre rôle n’a pas accès à Jedomi Telegram.';
$string['telegram_msg_confirm']          = 'Confirmer';
$string['telegram_msg_cancel']            = 'Annuler';
$string['telegram_msg_executing']         = 'Exécution en cours...';
$string['telegram_msg_done']              = 'Terminé.';
$string['telegram_msg_expired_code']      = 'Code invalide ou expiré. Générez-en un nouveau depuis votre profil Moodle.';
$string['telegram_msg_unlinked']          = 'Compte délié. Envoyez /start pour le relier.';
$string['telegram_msg_linked_success']    = '✅ Lié à Moodle en tant que <b>{$a->fullname}</b> ({$a->username})';
$string['telegram_msg_provide_code']      = '🔗 Veuillez fournir votre code de liaison.\n\nExemple : /start ABC123';
$string['telegram_msg_token_invalid']      = 'Jeton du bot invalide. Vérifiez le jeton fourni par @BotFather et réessayez.';
$string['telegram_msg_max_iterations']     = '⚠️ Trop d’étapes. Veuillez simplifier votre demande.';
$string['telegram_still_working']          = '🔄 Toujours en train de travailler dessus, patientez…';
$string['telegram_msg_timeout']            = '⏳ Cette tâche est trop complexe pour le délai imparti. Essayez de la diviser en étapes plus petites ou renvoyez-la et je reprendrai où j’en étais.';
$string['telegram_msg_new_conversation']   = '🗑️ Conversation effacée. Repartez à zéro !';

// ── Telegram help / commands ──────────────────────────────────
$string['telegram_help_heading']          = '🤖 <b>Bot Jedomi — Commandes disponibles</b>';
$string['telegram_help_start']            = '🔗 <b>/start CODE</b> — Lier votre compte Moodle à Telegram';
$string['telegram_help_help']             = '❓ <b>/help</b> — Afficher ce message d’aide';
$string['telegram_help_new']              = '🗑️ <b>/new</b> ou <b>/nuevo</b> — Effacer la conversation et repartir à zéro';
$string['telegram_help_unlink']           = '🚫 <b>/desvincular</b> ou <b>/unlink</b> — Délier votre Telegram de Moodle';
$string['telegram_help_footer']           = 'Une fois la liaison effectuée, envoyez n’importe quel message et je le transmettrai à Jedomi AI pour obtenir une réponse.';

// ── Telegram inline keyboard labels ───────────────────────────
$string['telegram_keyboard_new']          = '📝 Nouvelle conversation';
$string['telegram_keyboard_help']         = '❓ Aide';
$string['telegram_keyboard_unlink']       = '🚫 Délier';
$string['telegram_keyboard_howto_link']   = '🔗 Comment lier ?';

// ── Telegram error codes ──────────────────────────────────────
$string['error_telegram_e001']            = '❌ Chatbot non configuré (E001). Contactez votre administrateur.';
$string['error_telegram_e002']            = '❌ Échec de l’envoi du message à Telegram (E002). Veuillez réessayer.';
$string['error_telegram_e003']            = '❌ Réponse inattendue de Telegram (E003). Veuillez réessayer.';
$string['error_telegram_e004']            = '❌ La connexion à Jedomi a échoué (E004). Veuillez réessayer plus tard.';
$string['error_telegram_e005']            = '❌ Le service Jedomi a renvoyé une erreur (E005). Veuillez réessayer.';
$string['error_telegram_e006']            = '❌ Réponse invalide du service Jedomi (E006). Veuillez réessayer.';
$string['error_telegram_e007']            = '❌ Secret de webhook invalide (E007). Contactez votre administrateur.';
$string['error_telegram_e008']            = '❌ Vous n’avez pas la permission d’interroger le cours d’identifiant {$a}. Seuls les cours où vous disposez de droits de modification sont autorisés.';

// ── Telegram config ───────────────────────────────────────────
$string['telegram_progress']              = '🤔 Jedomi réfléchit…';
$string['telegram_max_turns']             = 'Nombre maximum de tours de conversation';
$string['telegram_max_turns_help']        = 'Nombre de messages conservés dans l’historique de conversation Telegram (1-100). Des valeurs plus basses économisent des jetons.';
$string['telegram_gateway_timeout']       = 'Délai d’expiration de la passerelle (secondes)';
$string['telegram_gateway_timeout_help']  = 'Nombre maximum de secondes à attendre une réponse de la passerelle Jedomi (5-300).';
$string['unlink']                        = 'Délier';
$string['regenerate']                    = 'Régénérer le secret';
$string['cleanup_telegram_sessions']     = 'Jedomi — Nettoyer les sessions Telegram expirées';
$string['encryption_failed']             = 'Échec du chiffrement. Vérifiez la configuration OpenSSL de votre serveur.';
$string['decryption_failed']             = 'Échec du déchiffrement. Le jeton a peut-être été corrompu ou le sel du site a changé.';

// ── Telegram setup guide ──────────────────────────────────────────────────────
// ── Agent Scheduled Tasks ──────────────────────────────────────────────────────
$string['tab_profiler']              = 'Profileur de requêtes';
$string['tab_agents']                = 'Agents';
$string['agent_heading']             = 'Agents IA planifiés';
$string['agent_heading_help']        = 'Configurez des agents IA planifiés qui s’exécutent automatiquement et envoient les résultats via Telegram.';
$string['agent_summary']             = 'Résumé';
$string['agent_by_role']             = 'Agents par rôle';
$string['agent_by_status']           = 'Agents par statut';
$string['agent_executions_30d']      = 'Exécutions (30 jours)';
$string['agent_filters']             = 'Filtres';
$string['agent_filter_role']         = 'Rôle';
$string['agent_filter_status']       = 'Statut';
$string['agent_filter_context']      = 'Contexte';
$string['agent_search']              = 'Rechercher une consigne...';
$string['agent_apply_filters']       = 'Appliquer les filtres';
$string['agent_table']               = 'Tous les agents';
$string['agent_creator']             = 'Créateur';
$string['agent_prompt']              = 'Consigne';
$string['agent_context']             = 'Contexte';
$string['agent_schedule']            = 'Planification';
$string['agent_status']              = 'Statut';
$string['agent_lastrun']             = 'Dernière exécution';
$string['agent_nextrun']             = 'Prochaine exécution';
$string['agent_actions']             = 'Actions';
$string['agent_edit']                = 'Modifier';
$string['agent_disable']             = 'Désactiver';
$string['agent_delete']              = 'Supprimer';
$string['agent_create']              = 'Créer un agent';
$string['agent_purge']               = 'Purger les agents';
$string['agent_purge_confirm']       = 'Cette action supprimera tous les agents correspondants et leurs résultats en cache. Continuer ?';
$string['agent_no_results']          = 'Aucun agent trouvé correspondant à vos filtres.';
$string['agent_create_success']      = 'Agent créé avec succès.';
$string['agent_created_with_name']   = 'Agent &quot;{name}&quot; créé avec succès.';
$string['agent_created_no_name']     = 'Agent créé mais aucun nom n’a été défini. Modifiez l’agent pour ajouter un nom.';
$string['agent_preview']             = 'Aperçu';
$string['agent_preview_result']      = 'Résultat de l’aperçu de l’agent';
$string['agent_preview_error']       = 'L’aperçu a échoué. Vérifiez la configuration de la passerelle et la consigne.';
$string['agent_update_success']      = 'Agent mis à jour avec succès.';
$string['agent_delete_confirm']      = 'Supprimer cet agent ? Cette action est irréversible.';
$string['agent_disabled']            = 'Désactivé';
$string['agent_enabled']             = 'Activé';
$string['agent_failing']             = 'En échec';
$string['agent_run_now']             = 'Exécuter maintenant';
$string['agent_schedule_presets']    = 'Préréglages de planification';
$string['agent_every_5min']          = 'Toutes les 5 minutes';
$string['agent_every_15min']         = 'Toutes les 15 minutes';
$string['agent_every_30min']         = 'Toutes les 30 minutes';
$string['agent_every_1h']            = 'Toutes les heures';
$string['agent_every_6h']            = 'Toutes les 6 heures';
$string['agent_daily']               = 'Quotidien';
$string['agent_weekly']              = 'Hebdomadaire';
$string['agent_custom_cron']         = 'Personnalisé (expression cron)';
$string['agent_context_type_site']   = 'Site';
$string['agent_context_type_course'] = 'Cours';
$string['agent_context_type_category'] = 'Catégorie';
$string['agent_context_type_user']   = 'Utilisateur';
$string['agent_prompt_placeholder']  = 'ex. « Résumer la nouvelle activité du site et me notifier »';
$string['agent_status_all']          = 'Tous';
$string['agent_filter_apply']        = 'Appliquer';
$string['agent_filter_reset']        = 'Réinitialiser';
$string['agent_purge_context']       = 'Type de contexte';
$string['agent_purge_context_help']  = 'Supprimer tous les agents appartenant à un contexte spécifique.';
$string['agent_purge_btn']           = 'Purger';
$string['agent_purge_success']       = '{$a} agent(s) supprimé(s) avec succès.';
$string['agent_error_load']          = 'Erreur lors du chargement des agents.';
$string['agent_error_create']        = 'Erreur lors de la création de l’agent : {$a}';
$string['agent_error_update']        = 'Erreur lors de la mise à jour de l’agent : {$a}';
$string['agent_error_delete']        = 'Erreur lors de la suppression de l’agent : {$a}';
$string['agent_not_found']           = 'Agent introuvable.';
$string['agent_run_queued']          = 'Agent mis en file d’attente pour exécution (prochain cycle cron).';
$string['agent_runner_task']         = 'Jedomi — Exécuter les agents IA planifiés';
$string['agent_enable']              = 'Activer';

$string['copytoclipboard']               = 'Copier dans le presse-papiers';
$string['telegram_setup_guide']          = 'Guide de configuration';
$string['telegram_setup_guide_help']     = 'Suivez ces étapes pour connecter Jedomi à Telegram. Une fois configuré, les utilisateurs peuvent lier leur compte depuis leur profil.';
$string['telegram_setup_done']           = 'Intégration configurée';
$string['telegram_setup_done_help']      = 'Telegram est actif. Les utilisateurs ayant les rôles autorisés peuvent lier leur compte depuis leur profil Moodle.';
$string['telegram_setup_show']           = 'Afficher le guide de configuration';
$string['telegram_setup_hide']           = 'Masquer le guide';

$string['telegram_step_admin_title']     = 'Configuration administrateur';
$string['telegram_step_admin_help']      = 'À effectuer une seule fois. Nécessite un accès à Telegram.';
$string['telegram_step1_title']          = 'Créer un bot dans @BotFather';
$string['telegram_step1_desc']           = 'Ouvrez Telegram, recherchez <strong>@BotFather</strong>, envoyez <code>/newbot</code>, choisissez un nom et un nom d’utilisateur. Copiez le jeton fourni (format : <code>123456:ABC-DEF...</code>).';
$string['telegram_step2_title']          = 'Coller le jeton et enregistrer';
$string['telegram_step2_desc']           = 'Collez le jeton dans le champ <strong>Jeton du bot</strong> ci-dessous, activez le commutateur d’intégration, puis cliquez sur <strong>Enregistrer les modifications</strong>.';
$string['telegram_step3_title']          = 'Enregistrer le webhook dans Telegram';
$string['telegram_step3_desc']           = 'Copiez l’<strong>URL du webhook</strong> affichée après l’enregistrement. Allez ensuite dans @BotFather et exécutez : <code>/setwebhook</code>. Ou utilisez cette commande curl :';
$string['telegram_step3_note']           = 'Pour le développement local (DDEV), utilisez d’abord ngrok pour obtenir une URL HTTPS publique : <code>ngrok http https://moodle2.ddev.site --host-header=moodle2.ddev.site</code>';
$string['telegram_step4_title']          = 'Configurer l’accès par rôle (facultatif)';
$string['telegram_step4_desc']           = 'Utilisez les commutateurs de rôle ci-dessous pour contrôler quels rôles Moodle peuvent utiliser Telegram. Les administrateurs du site ont toujours accès, quel que soit ce paramètre.';

$string['telegram_step_user_title']      = 'Configuration utilisateur (chaque utilisateur le fait une fois)';
$string['telegram_step_user_help']       = 'Une fois le bot configuré par l’administrateur, les utilisateurs lient leur propre compte Telegram.';
$string['telegram_stepU1_title']         = 'Accédez à votre profil Moodle';
$string['telegram_stepU1_desc']          = 'Cliquez sur votre nom dans la barre supérieure → <strong>Profil</strong>. Vous verrez une section <strong>Jedomi AI</strong> avec le lien <em>Lier Telegram</em>.';
$string['telegram_stepU2_title']         = 'Générer un code de liaison';
$string['telegram_stepU2_desc']          = 'Cliquez sur <strong>Générer un code</strong>. Un code de 6 caractères apparaît, valide pendant 10 minutes.';
$string['telegram_stepU3_title']         = 'Envoyer /start au bot';
$string['telegram_stepU3_desc']          = 'Dans Telegram, ouvrez le bot de votre établissement et envoyez : <code>/start VOTRE_CODE</code>. Le bot confirme la liaison instantanément.';
$string['telegram_stepU4_title']         = 'Commencer à discuter';
$string['telegram_stepU4_desc']          = 'Envoyez n’importe quel message au bot. Jedomi répond avec des données réelles de votre Moodle. Pour délier, envoyez <code>/desvincular</code> ou utilisez votre page de profil.';

// ── WS Discovery / Execution ────────────────────────────────────────────────
$string['tab_apis']                = 'API';
$string['tab_apis_heading']        = 'Listes blanches de services web';
$string['tab_apis_heading_help']   = 'Configurez les services web Moodle auxquels chaque rôle peut accéder via Jedomi.';
$string['api_role_admin']          = 'Administrateur';
$string['api_role_manager']        = 'Gestionnaire';
$string['api_role_teacher']        = 'Enseignant';
$string['api_role_student']        = 'Étudiant';
$string['filter_all']              = 'Tous';
$string['filter_read']             = 'Lecture uniquement';
$string['filter_write']            = 'Écriture uniquement';
$string['filter_type']             = 'Type :';
$string['component_all']           = 'Tous les composants';
$string['ws_name']                 = 'Nom';
$string['ws_description']          = 'Description';
$string['ws_component']            = 'Composant';
$string['ws_type']                 = 'Type';
$string['ws_type_read']            = 'Lecture';
$string['ws_type_write']           = 'Écriture';
$string['ws_type_all']             = 'Tous les types';
$string['search_ws_placeholder']   = 'Rechercher des services web...';
$string['ws_not_in_allowlist']     = 'Ce service web ne figure pas dans la liste blanche de votre rôle.';
$string['ws_not_found']            = 'Fonction de service web introuvable.';
$string['ws_execute_error']        = 'Erreur lors de l’exécution du service web : {$a}';
$string['ws_no_services']          = 'Aucun service web configuré pour ce rôle.';
$string['ws_pagination']           = 'Page {$a->page} sur {$a->pages} ({$a->total} services)';
$string['ws_none_found']           = 'Aucun service web ne correspond à vos filtres.';
$string['tool_ws_execute_desc']    = 'Execute a Moodle web service function on behalf of the current user. Use AFTER ws_search to get the exact function name and parameters. If the first attempt fails with "Missing required key" or "invalid parameter", retry with default values for optional fields. For batch operations (same action on multiple items), call this function MULTIPLE times — once per item.';

// ── Tool execution errors ────────────────────────────────────────────
$string['error_tool_loop_detected'] = 'Appel d’outil identique répété bloqué. Utilisez un autre outil ou exécutez avec de nouveaux paramètres.';
$string['error_fallback_iterations'] = 'Désolé, je n’ai pas pu terminer l’opération après plusieurs tentatives.';
$string['error_unknown_tool'] = 'Outil inconnu : {$a}';
$string['error_permission_denied'] = 'permission_denied';
$string['error_query_required'] = 'le paramètre query est requis';
$string['error_search_tables_failed'] = 'échec de search_tables : {$a}';
$string['error_ws_search_failed'] = 'échec de ws_search : {$a}';
$string['error_ws_describe_failed'] = 'échec de ws_describe : {$a}';
$string['error_ws_execute_failed'] = 'échec de ws_execute : {$a}';
$string['error_wsname_required'] = 'le paramètre wsname est requis';
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
$string['summary_invalid_json'] = 'erreur : réponse JSON invalide';
$string['summary_error_prefix'] = 'erreur : ';
$string['summary_rows_returned'] = ' lignes retournées';
$string['summary_table_structure'] = 'structure de la table récupérée';
$string['summary_matching_tables'] = ' tables correspondantes';
$string['summary_config_data'] = 'données de configuration récupérées';
$string['summary_functions_found'] = ' fonctions trouvées';
$string['summary_function_details'] = 'détails de la fonction récupérés';
$string['summary_ws_executed'] = 'service web exécuté avec succès';
$string['summary_done'] = 'terminé';

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
$string['ws_function_not_found'] = 'Fonction introuvable : {$a}';
$string['ws_cannot_load_info'] = 'Impossible de charger les informations de la fonction : {$a}';

// ── Table descriptor errors ──────────────────────────────────────────
$string['table_not_found'] = 'Table introuvable : {$a}';

// ── Set site config ──────────────────────────────────────────────────
$string['config_key_blocked_msg'] = 'La clé est bloquée pour des raisons de sécurité.';
$string['config_updated_msg'] = 'Configuration « {$a} » mise à jour avec succès.';

// ── Run task ─────────────────────────────────────────────────────────
$string['task_not_found_msg'] = 'task_not_found';
$string['task_instantiation_failed_msg'] = 'task_instantiation_failed';

// ── WS execute errors ────────────────────────────────────────────────
$string['ws_method_not_exists'] = 'La méthode {$a} n’existe pas';
$string['ws_debug_prefix'] = ' | débogage : ';

// ── Agent SQL profiler ───────────────────────────────────────────────
$string['query_profiler'] = 'Profileur de requêtes';
$string['query_profiler_heading'] = 'Profileur de requêtes SQL';
$string['query_profiler_desc'] = 'Enregistre toutes les requêtes SQL exécutées par les agents, y compris le temps d’exécution et le nombre de lignes. Utilisez le filtre ci-dessous pour trouver les requêtes lentes.';
$string['query_profiler_total'] = 'Total des requêtes';
$string['query_profiler_slow'] = 'Requêtes lentes (>1s)';
$string['query_profiler_avg'] = 'Durée moyenne';
$string['query_profiler_max'] = 'Durée maximale';
$string['query_profiler_sort_duration'] = 'Durée';
$string['query_profiler_sort_time'] = 'Heure';
$string['query_profiler_sort_query'] = 'Requête';
$string['query_profiler_empty'] = 'Aucune requête enregistrée pour l’instant. Les agents enregistreront les requêtes ici lorsqu’ils utiliseront l’outil SQL.';
$string['query_profiler_threshold'] = 'Seuil de requête lente (ms)';
$string['query_profiler_recent'] = 'Requêtes récentes';
$string['query_profiler_slowest'] = 'Requêtes les plus lentes (moyenne)';

// ── Agent WS allowlist ───────────────────────────────────────────────
$string['agent_ws_allowlist'] = 'Liste blanche de services web pour les agents';
$string['agent_ws_allowlist_help'] = 'Liste séparée par des virgules des noms de fonctions de services web Moodle que les agents sont autorisés à appeler. Laissez vide pour utiliser l’ensemble par défaut.';

// ── Agent name ───────────────────────────────────────────────────────
$string['agent_name'] = 'Nom';
$string['agent_name_placeholder'] = 'ex. Rapport quotidien';
$string['agent_unnamed'] = 'Agent';
// ── Document reader ─────────────────────────────────────────────────
$string['tool_document_read_desc'] = 'Read a PDF file from ANY course activity using its cmid (course module ID). Converts PDF pages to base64 images so you see the EXACT original layout, tables, images, and formatting. Use this when the user asks about a document. The cmid is available in the context when viewing an activity page.';
$string['tool_document_read_cmid'] = 'Course module ID (cmid) of the activity. Get it from the page context when viewing an activity, or discover it via ws_search/ws_execute first.';
$string['tool_document_read_dpi'] = 'Image resolution (default: 150). Higher values = better quality but more tokens.';
$string['tool_document_read_fmt'] = 'Image format: JPEG (smaller) or PNG (lossless). Default: JPEG.';
$string['docservice_url'] = 'URL du service de conversion document-image';
$string['docservice_url_help'] = 'URL du service de conversion document-image qui convertit les PDF en images base64 (ex. http://localhost:8000).';
