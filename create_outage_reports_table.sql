CREATE TABLE IF NOT EXISTS `outage_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `location` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `status` enum('pending','investigating','fixing','resolved') NOT NULL DEFAULT 'pending',
  `report_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_date` timestamp NULL DEFAULT NULL,
  `notes` text,
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `outage_reports_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `client_list` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4; 