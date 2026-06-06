-- Adminer 5.4.2 MariaDB 12.3.2-MariaDB-deb12 dump

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

USE `user183320_msgql`;

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `asana_import_log`;
CREATE TABLE `asana_import_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asana_gid` varchar(50) NOT NULL COMMENT 'Asana объект GID',
  `type` enum('project','task','message','file','user') NOT NULL COMMENT 'Тип объекта',
  `project_id` char(36) DEFAULT NULL COMMENT 'ЗадаЧат project.uuid',
  `task_id` char(36) DEFAULT NULL COMMENT 'ЗадаЧат task.uuid',
  `message_id` char(36) DEFAULT NULL COMMENT 'ЗадаЧат message.uuid',
  `file_id` char(36) DEFAULT NULL COMMENT 'ЗадаЧат file.uuid',
  `title` varchar(255) DEFAULT NULL COMMENT 'Название/заголовок',
  `status` enum('pending','success','error') DEFAULT 'pending',
  `error_msg` text DEFAULT NULL,
  `retry_count` int(11) DEFAULT 0,
  `imported_at` bigint(20) NOT NULL COMMENT 'Время импорта, мс',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_asana_gid_type` (`asana_gid`,`type`),
  KEY `idx_project_id` (`project_id`),
  KEY `idx_task_id` (`task_id`),
  KEY `idx_imported_at` (`imported_at`),
  KEY `idx_status` (`status`),
  KEY `idx_type_status` (`type`,`status`),
  KEY `idx_asana_gid_type_status` (`asana_gid`,`type`,`status`),
  KEY `idx_asana_import_log_type_status` (`type`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=4418 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Лог импорта из Asana';


DROP TABLE IF EXISTS `asana_user_mapping`;
CREATE TABLE `asana_user_mapping` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asana_gid` varchar(50) NOT NULL COMMENT 'Asana user GID',
  `asana_email` varchar(255) DEFAULT NULL COMMENT 'Email в Asana',
  `asana_name` varchar(255) DEFAULT NULL COMMENT 'Имя в Asana',
  `user_uuid` char(36) NOT NULL COMMENT 'Пользователь в ЗадаЧат',
  `mapping_type` enum('auto','manual','email','login','system') DEFAULT 'auto',
  `matched_by` enum('email','login','name','manual','system') DEFAULT NULL,
  `confidence` float DEFAULT 1,
  `mapped_by_uuid` char(36) DEFAULT NULL COMMENT 'Кто создал сопоставление',
  `created_at` bigint(20) NOT NULL,
  `updated_at` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_asana_gid` (`asana_gid`),
  KEY `idx_user_uuid` (`user_uuid`),
  KEY `idx_asana_email` (`asana_email`),
  KEY `idx_asana_name` (`asana_name`),
  KEY `idx_mapping_type` (`mapping_type`),
  KEY `idx_asana_gid` (`asana_gid`),
  CONSTRAINT `1` FOREIGN KEY (`user_uuid`) REFERENCES `users` (`uuid`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `files`;
CREATE TABLE `files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL COMMENT 'уникальный UUID файла для экспорта/импорта',
  `orig_name` varchar(255) NOT NULL COMMENT 'оригинальное имя файла при загрузке',
  `storage_name` varchar(255) NOT NULL COMMENT 'имя файла в хранилище (на диске)',
  `mime` varchar(120) DEFAULT NULL COMMENT 'MIME-тип файла',
  `size_bytes` bigint(20) NOT NULL DEFAULT 0 COMMENT 'размер файла в байтах',
  `uploaded_by_uuid` char(36) NOT NULL COMMENT 'кто загрузил файл (users.uuid)',
  `time` bigint(20) NOT NULL COMMENT 'время загрузки файла, мс',
  `stamp` varchar(60) NOT NULL COMMENT 'дата/время загрузки файла с часовым поясом',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_files_uuid` (`uuid`),
  KEY `idx_files_uploaded_by_uuid` (`uploaded_by_uuid`),
  CONSTRAINT `fk_files_uploaded_by_uuid` FOREIGN KEY (`uploaded_by_uuid`) REFERENCES `users` (`uuid`)
) ENGINE=InnoDB AUTO_INCREMENT=3412 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Таблица хранения информации о загруженных файлах';


DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL COMMENT 'IP-адрес (IPv4 или IPv6)',
  `login` varchar(80) NOT NULL COMMENT 'Логин, который пытались использовать',
  `attempt_time` bigint(20) NOT NULL COMMENT 'Время попытки в миллисекундах',
  `success` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1-успешный вход, 0-неудачный',
  PRIMARY KEY (`id`),
  KEY `idx_ip_login` (`ip_address`,`login`),
  KEY `idx_attempt_time` (`attempt_time`),
  KEY `idx_success` (`success`)
) ENGINE=InnoDB AUTO_INCREMENT=147 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Лог попыток входа для защиты от брутфорса';


DROP TABLE IF EXISTS `messages`;
CREATE TABLE `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL COMMENT 'уникальный UUID сообщения для экспорта/импорта',
  `task_uuid` char(36) NOT NULL COMMENT 'задача/подзадача, к которой относится сообщение (tasks.uuid)',
  `user_uuid` char(36) NOT NULL COMMENT 'автор сообщения (users.uuid)',
  `text` text NOT NULL COMMENT 'текст сообщения',
  `is_read` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0-не прочитано, 1-прочитано',
  `time` bigint(20) NOT NULL COMMENT 'время создания сообщения, мс',
  `stamp` varchar(60) NOT NULL COMMENT 'дата/время создания сообщения с часовым поясом',
  `reply_to_uuid` char(36) DEFAULT NULL COMMENT 'UUID сообщения, на которое дан ответ',
  `asana_gid` varchar(50) DEFAULT NULL COMMENT 'Asana GID исходного сообщения',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_messages_uuid` (`uuid`),
  UNIQUE KEY `uniq_asana_gid` (`asana_gid`),
  KEY `idx_messages_task_uuid_time` (`task_uuid`,`time`),
  KEY `idx_messages_user_uuid` (`user_uuid`),
  KEY `idx_messages_is_read` (`is_read`),
  KEY `idx_task_time_read` (`task_uuid`,`time`,`is_read`,`user_uuid`),
  KEY `idx_reply_to_uuid` (`reply_to_uuid`),
  CONSTRAINT `fk_messages_reply_to_uuid` FOREIGN KEY (`reply_to_uuid`) REFERENCES `messages` (`uuid`) ON DELETE SET NULL,
  CONSTRAINT `fk_messages_task_uuid` FOREIGN KEY (`task_uuid`) REFERENCES `tasks` (`uuid`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_user_uuid` FOREIGN KEY (`user_uuid`) REFERENCES `users` (`uuid`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8306 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Сообщения в чатах задач';


DROP TABLE IF EXISTS `message_files`;
CREATE TABLE `message_files` (
  `message_uuid` char(36) NOT NULL COMMENT 'сообщение (messages.uuid)',
  `file_uuid` char(36) NOT NULL COMMENT 'файл (files.uuid)',
  PRIMARY KEY (`message_uuid`,`file_uuid`),
  KEY `fk_message_files_file_uuid` (`file_uuid`),
  CONSTRAINT `fk_message_files_file_uuid` FOREIGN KEY (`file_uuid`) REFERENCES `files` (`uuid`) ON DELETE CASCADE,
  CONSTRAINT `fk_message_files_message_uuid` FOREIGN KEY (`message_uuid`) REFERENCES `messages` (`uuid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Связь файлов с сообщениями (многие-ко-многим)';


DROP TABLE IF EXISTS `projects`;
CREATE TABLE `projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL COMMENT 'уникальный UUID проекта для экспорта/импорта',
  `asana_gid` varchar(50) DEFAULT NULL,
  `title` varchar(190) NOT NULL COMMENT 'название проекта',
  `descr` mediumtext DEFAULT NULL COMMENT 'описание проекта',
  `created_by_uuid` char(36) NOT NULL COMMENT 'создатель проекта (users.uuid)',
  `time` bigint(20) NOT NULL COMMENT 'время создания записи, мс',
  `stamp` varchar(60) NOT NULL COMMENT 'дата/время создания записи с часовым поясом',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_projects_uuid` (`uuid`),
  UNIQUE KEY `uniq_asana_gid` (`asana_gid`),
  KEY `idx_projects_created_by_uuid` (`created_by_uuid`),
  CONSTRAINT `fk_projects_created_by_uuid` FOREIGN KEY (`created_by_uuid`) REFERENCES `users` (`uuid`)
) ENGINE=InnoDB AUTO_INCREMENT=54 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Проекты (контейнеры для задач)';


DROP TABLE IF EXISTS `push_sent_log`;
CREATE TABLE `push_sent_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_uuid` char(36) NOT NULL,
  `type` varchar(50) NOT NULL,
  `task_uuid` char(36) DEFAULT NULL,
  `message_uuid` char(36) DEFAULT NULL,
  `created_at` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_uuid` (`user_uuid`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `push_subscriptions`;
CREATE TABLE `push_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) NOT NULL,
  `user_uuid` varchar(36) NOT NULL,
  `endpoint` text NOT NULL,
  `p256dh` varchar(255) NOT NULL,
  `auth` varchar(255) NOT NULL,
  `expiration_time` varchar(20) DEFAULT NULL,
  `created_at` varchar(20) NOT NULL,
  `updated_at` varchar(20) NOT NULL,
  `stamp` varchar(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_uuid` (`uuid`),
  KEY `idx_user_uuid` (`user_uuid`),
  KEY `idx_endpoint` (`endpoint`(255))
) ENGINE=InnoDB AUTO_INCREMENT=89 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `sse_queue`;
CREATE TABLE `sse_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL COMMENT 'UUID события',
  `task_uuid` char(36) NOT NULL COMMENT 'Задача, к которой относится событие',
  `event_type` varchar(50) NOT NULL COMMENT 'Тип события (message_edited, message_deleted и т.д.)',
  `event_data` text NOT NULL COMMENT 'JSON данные события',
  `created_at` bigint(20) NOT NULL COMMENT 'Время создания, мс',
  `processed` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0-не обработано, 1-обработано',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_uuid` (`uuid`),
  KEY `idx_task_processed` (`task_uuid`,`processed`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=1131 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Очередь Server-Sent Events для real-time уведомлений';


DROP TABLE IF EXISTS `tasks`;
CREATE TABLE `tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL COMMENT 'уникальный UUID задачи/подзадачи для экспорта/импорта',
  `asana_gid` varchar(50) DEFAULT NULL,
  `project_uuid` char(36) NOT NULL COMMENT 'проект, которому принадлежит задача (projects.uuid)',
  `parent_task_uuid` char(36) DEFAULT NULL COMMENT 'для подзадач (иерархия)',
  `title` varchar(190) NOT NULL COMMENT 'заголовок задачи/подзадачи',
  `descr` mediumtext DEFAULT NULL COMMENT 'описание задачи/подзадачи',
  `assigned_to_uuid` char(36) DEFAULT NULL COMMENT 'назначенный пользователь (users.uuid)',
  `time_start` bigint(20) DEFAULT NULL COMMENT 'плановое начало, мс',
  `time_end_plan` bigint(20) DEFAULT NULL COMMENT 'плановое окончание, мс',
  `status` int(11) NOT NULL DEFAULT 0,
  `time` bigint(20) NOT NULL COMMENT 'время создания записи, мс',
  `stamp` varchar(60) NOT NULL COMMENT 'дата/время создания записи с часовым поясом',
  `user_uuid` char(36) DEFAULT NULL COMMENT 'УСТАРЕВШЕЕ: не используется в коде. Создатель задачи (дублирует projects.created_by_uuid)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tasks_uuid` (`uuid`),
  UNIQUE KEY `uniq_asana_gid` (`asana_gid`),
  KEY `idx_tasks_project_uuid` (`project_uuid`),
  KEY `idx_tasks_parent_task_uuid` (`parent_task_uuid`),
  KEY `idx_tasks_assigned_to_uuid` (`assigned_to_uuid`),
  KEY `idx_project_assigned` (`project_uuid`,`assigned_to_uuid`,`status`),
  KEY `idx_project_parent` (`project_uuid`,`parent_task_uuid`),
  KEY `idx_project_time` (`project_uuid`,`time` DESC),
  KEY `idx_parent_uuid` (`parent_task_uuid`),
  KEY `idx_assigned_to` (`assigned_to_uuid`),
  KEY `idx_status_deadline` (`status`,`time_end_plan`),
  CONSTRAINT `fk_tasks_assigned_to_uuid` FOREIGN KEY (`assigned_to_uuid`) REFERENCES `users` (`uuid`) ON DELETE SET NULL,
  CONSTRAINT `fk_tasks_parent_task_uuid` FOREIGN KEY (`parent_task_uuid`) REFERENCES `tasks` (`uuid`) ON DELETE SET NULL,
  CONSTRAINT `fk_tasks_project_uuid` FOREIGN KEY (`project_uuid`) REFERENCES `projects` (`uuid`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1558 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Задачи и подзадачи в проектах';


DROP TABLE IF EXISTS `task_change_history`;
CREATE TABLE `task_change_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `task_uuid` char(36) NOT NULL COMMENT 'UUID задачи',
  `changed_by_uuid` char(36) NOT NULL COMMENT 'UUID пользователя, который изменил',
  `changes` text NOT NULL COMMENT 'JSON с изменениями',
  `created_at` bigint(20) NOT NULL COMMENT 'время изменения, мс',
  PRIMARY KEY (`id`),
  KEY `idx_task_uuid` (`task_uuid`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='История изменений задач (аудит)';


DROP TABLE IF EXISTS `task_files`;
CREATE TABLE `task_files` (
  `task_uuid` char(36) NOT NULL COMMENT 'задача/подзадача (tasks.uuid)',
  `file_uuid` char(36) NOT NULL COMMENT 'файл (files.uuid)',
  PRIMARY KEY (`task_uuid`,`file_uuid`),
  KEY `fk_task_files_file_uuid` (`file_uuid`),
  CONSTRAINT `fk_task_files_file_uuid` FOREIGN KEY (`file_uuid`) REFERENCES `files` (`uuid`) ON DELETE CASCADE,
  CONSTRAINT `fk_task_files_task_uuid` FOREIGN KEY (`task_uuid`) REFERENCES `tasks` (`uuid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Связь файлов с задачами (многие-ко-многим)';


DROP TABLE IF EXISTS `task_subscribers`;
CREATE TABLE `task_subscribers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_uuid` char(36) NOT NULL COMMENT 'Задача (tasks.uuid)',
  `user_uuid` char(36) NOT NULL COMMENT 'Подписанный пользователь (users.uuid)',
  `subscribed_at` bigint(20) NOT NULL COMMENT 'Время подписки, мс',
  `subscribed_by_uuid` char(36) NOT NULL COMMENT 'Кто подписал (users.uuid)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1-активна, 0-отписан',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_task_user` (`task_uuid`,`user_uuid`),
  KEY `idx_subscribers_task` (`task_uuid`),
  KEY `idx_subscribers_user` (`user_uuid`),
  CONSTRAINT `fk_subscribers_task` FOREIGN KEY (`task_uuid`) REFERENCES `tasks` (`uuid`) ON DELETE CASCADE,
  CONSTRAINT `fk_subscribers_user` FOREIGN KEY (`user_uuid`) REFERENCES `users` (`uuid`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Подписчики задачи для уведомлений и упоминаний';


DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL COMMENT 'уникальный UUID пользователя для экспорта/импорта',
  `role` int(11) NOT NULL DEFAULT 2 COMMENT '0-админ, 1-менеджер, 2-контролёр',
  `status` int(11) NOT NULL DEFAULT 0 COMMENT '0-активный, 2-заблокированный',
  `login` varchar(80) NOT NULL COMMENT 'логин для входа',
  `email` varchar(190) DEFAULT NULL COMMENT 'email пользователя',
  `name` varchar(190) DEFAULT NULL COMMENT 'имя пользователя',
  `tel` varchar(32) DEFAULT NULL COMMENT 'телефон пользователя (мобильный формат)',
  `pass` varchar(255) NOT NULL COMMENT 'хэш пароля',
  `salt` varchar(40) NOT NULL COMMENT 'индивидуальная соль пользователя',
  `time_lastalert` bigint(20) NOT NULL DEFAULT 0 COMMENT 'время последнего алерта, мс',
  `time_last_dashboard_view` bigint(20) NOT NULL DEFAULT 0 COMMENT 'время последнего просмотра дашборда, мс',
  `alert_interval_min` int(11) NOT NULL DEFAULT 30 COMMENT 'интервал между алертами, минуты',
  `alert_days` varchar(20) NOT NULL DEFAULT '1,2,3,4,5' COMMENT 'Дни недели для уведомлений (1-пн,7-вс)',
  `time` bigint(20) NOT NULL COMMENT 'время создания, мс',
  `stamp` varchar(60) NOT NULL COMMENT 'дата/время с часовым поясом',
  `sound_enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Включены ли звуковые уведомления',
  `sound_interval_sec` int(11) NOT NULL DEFAULT 600 COMMENT 'Интервал между звуками в секундах',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_uuid` (`uuid`),
  UNIQUE KEY `uniq_users_login` (`login`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Пользователи системы (администраторы, менеджеры, контролёры)';


DROP TABLE IF EXISTS `user_notifications`;
CREATE TABLE `user_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `user_uuid` char(36) NOT NULL,
  `task_uuid` char(36) DEFAULT NULL COMMENT 'UUID задачи, к которой относится уведомление',
  `type` varchar(50) NOT NULL COMMENT 'тип уведомления (task_changed, task_created и т.д.)',
  `data` text NOT NULL COMMENT 'JSON данные уведомления',
  `is_read` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0-не прочитано, 1-прочитано',
  `created_at` bigint(20) NOT NULL COMMENT 'время создания уведомления, мс',
  PRIMARY KEY (`id`),
  KEY `idx_user_uuid` (`user_uuid`),
  KEY `idx_is_read` (`is_read`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Уведомления пользователей (централизованная система)';


DROP TABLE IF EXISTS `user_project_permissions`;
CREATE TABLE `user_project_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL COMMENT 'уникальный UUID записи прав',
  `user_uuid` char(36) NOT NULL COMMENT 'пользователь (users.uuid)',
  `project_uuid` char(36) NOT NULL COMMENT 'проект (projects.uuid)',
  `can_view` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'может просматривать проект',
  `can_edit_tasks` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'может создавать/редактировать задачи',
  `can_edit_messages` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'может писать сообщения',
  `can_upload_files` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'может загружать файлы',
  `can_create_projects` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'может создавать проекты',
  `can_edit_own_projects` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'может редактировать свои проекты',
  `granted_by_uuid` char(36) NOT NULL COMMENT 'кто выдал права (users.uuid)',
  `time` bigint(20) NOT NULL COMMENT 'время выдачи прав, мс',
  `stamp` varchar(60) NOT NULL COMMENT 'дата/время выдачи прав с часовым поясом',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_project_permissions_uuid` (`uuid`),
  UNIQUE KEY `uniq_user_project` (`user_uuid`,`project_uuid`),
  KEY `idx_permissions_user_uuid` (`user_uuid`),
  KEY `idx_permissions_project_uuid` (`project_uuid`),
  KEY `fk_permissions_granted_by_uuid` (`granted_by_uuid`),
  KEY `idx_user_project_view` (`user_uuid`,`project_uuid`,`can_view`),
  CONSTRAINT `fk_permissions_granted_by_uuid` FOREIGN KEY (`granted_by_uuid`) REFERENCES `users` (`uuid`) ON DELETE CASCADE,
  CONSTRAINT `fk_permissions_project_uuid` FOREIGN KEY (`project_uuid`) REFERENCES `projects` (`uuid`) ON DELETE CASCADE,
  CONSTRAINT `fk_permissions_user_uuid` FOREIGN KEY (`user_uuid`) REFERENCES `users` (`uuid`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Права доступа пользователей к проектам';


-- 2026-06-06 12:16:15 UTC
