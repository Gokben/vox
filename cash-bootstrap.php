<?php
declare(strict_types=1);

function ensure_cash_schema(PDO $pdo): void
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cash_settings (id INTEGER PRIMARY KEY, opening_balance NUMERIC NOT NULL DEFAULT 0, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS cash_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, parent_id INTEGER NULL, active INTEGER NOT NULL DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(parent_id) REFERENCES cash_categories(id) ON DELETE RESTRICT)");
        $columns = $pdo->query('PRAGMA table_info(cash_categories)')->fetchAll();
        if (!in_array('parent_id', array_column($columns, 'name'), true)) $pdo->exec('ALTER TABLE cash_categories ADD COLUMN parent_id INTEGER NULL');
        $pdo->exec("CREATE TABLE IF NOT EXISTS cash_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_date TEXT NOT NULL, description TEXT NOT NULL, transaction_type TEXT NOT NULL, amount NUMERIC NOT NULL, payment_type TEXT NOT NULL, installment_count INTEGER NOT NULL DEFAULT 1, category_id INTEGER NULL, source_url TEXT NULL, created_by INTEGER NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(category_id) REFERENCES cash_categories(id) ON DELETE RESTRICT)");
        $columns = $pdo->query('PRAGMA table_info(cash_transactions)')->fetchAll();
        if (!in_array('source_url', array_column($columns, 'name'), true)) $pdo->exec('ALTER TABLE cash_transactions ADD COLUMN source_url TEXT NULL');
        if (!in_array('installment_count', array_column($columns, 'name'), true)) $pdo->exec('ALTER TABLE cash_transactions ADD COLUMN installment_count INTEGER NOT NULL DEFAULT 1');
        if (!in_array('bank_name', array_column($columns, 'name'), true)) $pdo->exec('ALTER TABLE cash_transactions ADD COLUMN bank_name TEXT NULL');
        if (!in_array('commission_rate', array_column($columns, 'name'), true)) $pdo->exec('ALTER TABLE cash_transactions ADD COLUMN commission_rate NUMERIC NULL');
        if (!in_array('current_account_id', array_column($columns, 'name'), true)) $pdo->exec('ALTER TABLE cash_transactions ADD COLUMN current_account_id INTEGER NULL');
        if (!in_array('term_schedule', array_column($columns, 'name'), true)) $pdo->exec('ALTER TABLE cash_transactions ADD COLUMN term_schedule TEXT NULL');
        $pdo->exec("CREATE TABLE IF NOT EXISTS cash_closings (id INTEGER PRIMARY KEY AUTOINCREMENT, closing_date TEXT NOT NULL UNIQUE, expected_balance NUMERIC NOT NULL, counted_balance NUMERIC NOT NULL, difference NUMERIC NOT NULL, note TEXT, created_by INTEGER NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec('INSERT OR IGNORE INTO cash_settings(id,opening_balance) VALUES(1,0)');
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cash_settings (id TINYINT UNSIGNED PRIMARY KEY, opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS cash_categories (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL UNIQUE, parent_id INT UNSIGNED NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, CONSTRAINT cash_category_parent_fk FOREIGN KEY(parent_id) REFERENCES cash_categories(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $column = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cash_categories' AND column_name='parent_id'");
        $column->execute();
        if (!$column->fetchColumn()) $pdo->exec('ALTER TABLE cash_categories ADD COLUMN parent_id INT UNSIGNED NULL AFTER name');
        $pdo->exec("CREATE TABLE IF NOT EXISTS cash_transactions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, transaction_date DATE NOT NULL, description VARCHAR(255) NOT NULL, transaction_type ENUM('income','expense') NOT NULL, amount DECIMAL(14,2) NOT NULL, payment_type ENUM('cash','credit_card','mail_order','term') NOT NULL, installment_count INT UNSIGNED NOT NULL DEFAULT 1, category_id INT UNSIGNED NULL, source_url VARCHAR(255) NULL, created_by INT UNSIGNED NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX cash_transaction_date_idx(transaction_date), CONSTRAINT cash_transaction_category_fk FOREIGN KEY(category_id) REFERENCES cash_categories(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $sourceColumn = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cash_transactions' AND column_name='source_url'");
        $sourceColumn->execute();
        if (!$sourceColumn->fetchColumn()) $pdo->exec('ALTER TABLE cash_transactions ADD COLUMN source_url VARCHAR(255) NULL AFTER category_id');
        $installmentColumn = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cash_transactions' AND column_name='installment_count'");
        $installmentColumn->execute();
        if (!$installmentColumn->fetchColumn()) $pdo->exec('ALTER TABLE cash_transactions ADD COLUMN installment_count INT UNSIGNED NOT NULL DEFAULT 1 AFTER payment_type');
        $bankColumn = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cash_transactions' AND column_name='bank_name'");
        $bankColumn->execute();
        if (!$bankColumn->fetchColumn()) $pdo->exec('ALTER TABLE cash_transactions ADD COLUMN bank_name VARCHAR(150) NULL AFTER installment_count');
        $rateColumn = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cash_transactions' AND column_name='commission_rate'");
        $rateColumn->execute();
        if (!$rateColumn->fetchColumn()) $pdo->exec('ALTER TABLE cash_transactions ADD COLUMN commission_rate DECIMAL(7,2) NULL AFTER bank_name');
        $accountColumn = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cash_transactions' AND column_name='current_account_id'");
        $accountColumn->execute();
        if (!$accountColumn->fetchColumn()) $pdo->exec('ALTER TABLE cash_transactions ADD COLUMN current_account_id INT UNSIGNED NULL AFTER commission_rate');
        $termScheduleColumn = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cash_transactions' AND column_name='term_schedule'");
        $termScheduleColumn->execute();
        if (!$termScheduleColumn->fetchColumn()) $pdo->exec('ALTER TABLE cash_transactions ADD COLUMN term_schedule TEXT NULL AFTER current_account_id');
        $pdo->exec("ALTER TABLE cash_transactions MODIFY payment_type ENUM('cash','credit_card','mail_order','term') NOT NULL");
        $pdo->exec("CREATE TABLE IF NOT EXISTS cash_closings (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, closing_date DATE NOT NULL UNIQUE, expected_balance DECIMAL(14,2) NOT NULL, counted_balance DECIMAL(14,2) NOT NULL, difference DECIMAL(14,2) NOT NULL, note VARCHAR(255) NULL, created_by INT UNSIGNED NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec('INSERT IGNORE INTO cash_settings(id,opening_balance) VALUES(1,0)');
    }

    if ((int)$pdo->query('SELECT COUNT(*) FROM cash_categories')->fetchColumn() === 0) {
        $insert = $pdo->prepare('INSERT INTO cash_categories(name,parent_id,active) VALUES(?,NULL,1)');
        foreach (['Maaş', 'Fatura', 'Satış', 'Kira'] as $name) $insert->execute([$name]);
    }
}
