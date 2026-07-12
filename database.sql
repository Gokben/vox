CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('Admin','User') NOT NULL DEFAULT 'User',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE location_definitions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL UNIQUE,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO location_definitions (name,sort_order) VALUES
('Lobby',1),('Oven Restoran',2),('Kış Bahçesi',3),('Lobby WC',4),('Teras',5),('Spa alanları',6),
('Kat Ofisleri',7),('Teras Havuz',8),('Toplantı Salonları',9),('Mescit',10),('La Table',11),('Club Millésime',12);

CREATE TABLE department_definitions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL UNIQUE,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO department_definitions (name,sort_order) VALUES
('Front Office',1),('F&B',2),('Güvenlik',3),('Housekeeping',4),('Diğer',5);

CREATE TABLE storage_definitions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL UNIQUE,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO storage_definitions (name,sort_order) VALUES
('Nurcan Hanım Kasa',1),('Nurcan Hanım Ofis',2),('Güvenlik Kasa',3),('HK Buzdolabı',4),('HK Depo',5);

CREATE TABLE item_definitions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(100) NOT NULL,
  name VARCHAR(180) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_item_definition(category,name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO item_definitions (category,name,sort_order) VALUES
('Altın','Altın Bilezik',1),('Altın','Altın Kolye',2),('Altın','Altın Küpe',3),('Altın','Altın Yüzük',4),
('Altın','Çeyrek Altın',5),('Altın','Diğer Altın',6),('Altın','Gram Altın',7),('Altın','Tam Altın',8),('Altın','Yarım Altın',9),
('Bagaj','Alışveriş Çantası',10),('Bagaj','Bagaj Etiketi',11),('Bagaj','Bavul',12),('Bagaj','Bay El Çantası',13),
('Bebek ve Çocuk','Araba Koltuğu',14);

CREATE TABLE items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_no VARCHAR(30) NOT NULL UNIQUE,
  found_at DATE NOT NULL,
  location VARCHAR(150) NOT NULL,
  category VARCHAR(100) NOT NULL,
  name VARCHAR(180) NOT NULL,
  brand VARCHAR(100) DEFAULT '', color VARCHAR(80) DEFAULT '',
  details TEXT, storage_location VARCHAR(120) DEFAULT '', found_department VARCHAR(120) DEFAULT '',
  found_by VARCHAR(150) DEFAULT '', quantity INT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('Depoda','Eşleşme bekliyor','Teslim edildi','Kargolandı','Tasfiye edildi') NOT NULL DEFAULT 'Depoda',
  recorded_by VARCHAR(100) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_status(status), INDEX idx_found_at(found_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- İlk yöneticiyi oluşturmak için password.php dosyasının ürettiği özeti buraya yazın:
-- INSERT INTO users (name,email,password_hash,role) VALUES ('Sofitel Yönetici','admin@sofitel.com','OZET','Admin');
