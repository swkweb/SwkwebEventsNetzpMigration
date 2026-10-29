-- NetzpEvents6 4.0.9 tables as its migrations create them, without foreign keys to Shopware tables.
CREATE TABLE IF NOT EXISTS `s_plugin_netzp_events` (
    `id` binary(16) NOT NULL,
    `productid` binary(16) DEFAULT NULL,
    `bookable` tinyint(1) NOT NULL DEFAULT 1,
    `title` varchar(255),
    `imageid` binary(16) DEFAULT NULL,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    `categoryid` binary(16) DEFAULT NULL,
    `timezone` varchar(255) DEFAULT 'UTC',
    `archived` tinyint(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `s_plugin_netzp_events_translation` (
    `s_plugin_netzp_events_id` BINARY(16) NOT NULL,
    `language_id` BINARY(16) NOT NULL,
    `title` varchar(255),
    `teaser` longtext DEFAULT NULL,
    `description` longtext DEFAULT NULL,
    `url` varchar(255),
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3),
    `additionalfields` varchar(255) DEFAULT NULL,
    `additional_fields` JSON NULL,
    PRIMARY KEY (`s_plugin_netzp_events_id`, `language_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `s_plugin_netzp_events_participants` (
    `id` binary(16) NOT NULL,
    `event_id` BINARY(16) NOT NULL,
    `slot_id` BINARY(16) NOT NULL,
    `name` varchar(255) DEFAULT NULL,
    `comments` varchar(255) DEFAULT NULL,
    `ticket_number` varchar(255) DEFAULT NULL,
    `order_id` binary(16) NULL,
    `status` varchar(3) NOT NULL DEFAULT "0",
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    `additionalfields` varchar(255) DEFAULT NULL,
    `additional_fields` JSON NULL,
    PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `s_plugin_netzp_events_categories` (
    `id` binary(16) NOT NULL,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `s_plugin_netzp_events_categories_translation` (
    `s_plugin_netzp_events_categories_id` BINARY(16) NOT NULL,
    `language_id` BINARY(16) NOT NULL,
    `title` varchar(255),
    `teaser` longtext DEFAULT NULL,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3),
    PRIMARY KEY (`s_plugin_netzp_events_categories_id`, `language_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `s_plugin_netzp_events_slots` (
    `id` binary(16) NOT NULL,
    `event_id` BINARY(16) NOT NULL,
    `from` DATETIME DEFAULT NULL,
    `until` DATETIME DEFAULT NULL,
    `active` tinyint(1) NOT NULL DEFAULT 1,
    `tickets_available` int(11) NOT NULL DEFAULT 0,
    `tickets_booked` int(11) NOT NULL DEFAULT 0,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `s_plugin_netzp_events_slots_translation` (
    `s_plugin_netzp_events_slots_id` BINARY(16) NOT NULL,
    `language_id` BINARY(16) NOT NULL,
    `location` varchar(255),
    `address` varchar(255),
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3),
    PRIMARY KEY (`s_plugin_netzp_events_slots_id`, `language_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `s_plugin_netzp_events_tag` (
    `event_id` BINARY(16) NOT NULL,
    `tag_id` BINARY(16) NOT NULL,
    PRIMARY KEY (`event_id`, `tag_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
