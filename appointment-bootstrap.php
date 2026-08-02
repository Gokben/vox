<?php
declare(strict_types=1);

function ensure_appointment_schema(PDO $pdo): void {
    static $ready = false;
    if ($ready) return;
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $pdo->exec($sqlite
        ? 'CREATE TABLE IF NOT EXISTS appointments (id INTEGER PRIMARY KEY AUTOINCREMENT,event_type TEXT NOT NULL DEFAULT "appointment",full_name TEXT NOT NULL,phone TEXT NULL,appointment_date TEXT NOT NULL,appointment_time TEXT NOT NULL,branch_id INTEGER NULL,contact_person TEXT NULL,communication_method TEXT NULL,result TEXT NULL,note TEXT NULL,created_by INTEGER NULL,created_at TEXT DEFAULT CURRENT_TIMESTAMP)'
        : 'CREATE TABLE IF NOT EXISTS appointments (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,event_type VARCHAR(30) NOT NULL DEFAULT "appointment",full_name VARCHAR(190) NOT NULL,phone VARCHAR(50) NULL,appointment_date DATE NOT NULL,appointment_time TIME NOT NULL,branch_id INT UNSIGNED NULL,contact_person VARCHAR(190) NULL,communication_method VARCHAR(30) NULL,result VARCHAR(50) NULL,note TEXT NULL,created_by INT UNSIGNED NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,INDEX appointments_date_idx(appointment_date),INDEX appointments_branch_idx(branch_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $columns = $sqlite
        ? $pdo->query('PRAGMA table_info(appointments)')->fetchAll(PDO::FETCH_COLUMN, 1)
        : $pdo->query('SHOW COLUMNS FROM appointments')->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('result', $columns, true)) $pdo->exec($sqlite ? 'ALTER TABLE appointments ADD COLUMN result TEXT NULL' : 'ALTER TABLE appointments ADD COLUMN result VARCHAR(50) NULL AFTER contact_person');
    if (!in_array('event_type', $columns, true)) $pdo->exec($sqlite ? 'ALTER TABLE appointments ADD COLUMN event_type TEXT NOT NULL DEFAULT "appointment"' : 'ALTER TABLE appointments ADD COLUMN event_type VARCHAR(30) NOT NULL DEFAULT "appointment" AFTER id');
    if (!in_array('communication_method', $columns, true)) $pdo->exec($sqlite ? 'ALTER TABLE appointments ADD COLUMN communication_method TEXT NULL' : 'ALTER TABLE appointments ADD COLUMN communication_method VARCHAR(30) NULL AFTER contact_person');
    $ready = true;
}
