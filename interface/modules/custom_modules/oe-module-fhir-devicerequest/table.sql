-- This table definition is loaded and then executed when the OpenEMR interface's install button is clicked.
CREATE TABLE IF NOT EXISTS `device_request` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY NOT NULL,
    `uuid` BINARY(16),
    `pid` BINARY(16) DEFAULT NULL,   
    `encounter` BINARY(16) DEFAULT NULL, 
    `date` DATETIME DEFAULT NULL, 
    `event_date` DATETIME DEFAULT NULL, 
    `pruuid` BINARY(16) DEFAULT NULL, 
    `location_uuid` BINARY(16) DEFAULT NULL, 
    `device_uuid` BINARY(16) DEFAULT NULL,
    `organization_uuid` BINARY(16) DEFAULT NULL,
    `status` VARCHAR(50) DEFAULT NULL,
    `intent` VARCHAR(50) NOT NULL,
    `priority` VARCHAR(50) DEFAULT NULL,
    `insurance_uuid` BINARY(16) DEFAULT NULL,
    `supporting_info` TEXT DEFAULT NULL,
    `note` TEXT DEFAULT NULL,
    `relevant_history_uuid` BINARY(16) DEFAULT NULL,
    `last_updated` DATETIME,
    UNIQUE KEY `uuid` (`uuid`),
    UNIQUE KEY `pruuid` (`pruuid`),
    KEY `pid_encounter` (`pid`,`encounter`)
);
