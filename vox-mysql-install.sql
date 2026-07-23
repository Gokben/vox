SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS patients;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS branches;

CREATE TABLE branches (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(150) NOT NULL UNIQUE,
 code VARCHAR(50) NULL,
 phone VARCHAR(50) NULL,
 address TEXT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(150) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('Admin','User') NOT NULL DEFAULT 'User',
 active TINYINT(1) NOT NULL DEFAULT 1,
 branch_id INT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_users_branch(branch_id),
 CONSTRAINT fk_users_branch FOREIGN KEY(branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 setting_key VARCHAR(190) NOT NULL UNIQUE,
 setting_value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE patients (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 import_order INT UNSIGNED NOT NULL,
 branch_id INT UNSIGNED NULL,
 record_date DATE NULL,
 full_name VARCHAR(190) NOT NULL,
 national_id VARCHAR(30) NULL,
 phone_primary VARCHAR(50) NULL,
 phone_secondary VARCHAR(50) NULL,
 birth_date DATE NULL,
 address TEXT NULL,
 social_security VARCHAR(100) NULL,
 report_info VARCHAR(150) NULL,
 service_type VARCHAR(150) NULL,
 source_primary VARCHAR(150) NULL,
 source_marketing VARCHAR(150) NULL,
 source_detail VARCHAR(255) NULL,
 anamnesis TEXT NULL,
 notes TEXT NULL,
 approval TINYINT(1) NOT NULL DEFAULT 0,
 considering TINYINT(1) NOT NULL DEFAULT 0,
 rejected TINYINT(1) NOT NULL DEFAULT 0,
 staff_cansu TINYINT(1) NOT NULL DEFAULT 0,
 staff_busra TINYINT(1) NOT NULL DEFAULT 0,
 staff_belma TINYINT(1) NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_patients_import_order(import_order),
 KEY idx_patients_branch(branch_id),
 KEY idx_patients_name(full_name),
 CONSTRAINT fk_patients_branch FOREIGN KEY(branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO branches(name,code,active) VALUES('Merkez Şube','MRK',1);
INSERT INTO users(name,email,password_hash,role,active,branch_id)
VALUES('Vox Yöneticisi','admin@sofitel.com','$2y$10$DQ9Gn97/jvfdbw7809DHKOnRjnKCk27ey/WKRb2RLmh3h3Bn3Yrey','Admin',1,1);
SET FOREIGN_KEY_CHECKS=1;
