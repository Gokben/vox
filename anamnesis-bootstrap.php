<?php
declare(strict_types=1);

function anamnesis_question_definitions(): array
{
    $pdo = db();
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $pdo->exec($sqlite
        ? 'CREATE TABLE IF NOT EXISTS anamnesis_question_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(512) NOT NULL UNIQUE, active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0)'
        : 'CREATE TABLE IF NOT EXISTS anamnesis_question_definitions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(512) NOT NULL UNIQUE, active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $columns = $sqlite ? array_column($pdo->query('PRAGMA table_info(anamnesis_question_definitions)')->fetchAll(), 'name') : array_column($pdo->query('SHOW COLUMNS FROM anamnesis_question_definitions')->fetchAll(), 'Field');
    if (!in_array('detail_label', $columns, true)) $pdo->exec('ALTER TABLE anamnesis_question_definitions ADD COLUMN detail_label VARCHAR(190) NULL');
    $defaults = [
        'Gürültülü ortamlarda çalıştınız mı?', 'Yüksek ses eşiği maruz kalır mısınız?', 'Çocukken ateşli hastalık geçirdiniz mi?',
        'Ailede işitme kaybı olan birisi var mı?', 'Daha önce kulak ameliyatı oldunuz mu?', 'Teşhisi konmuş kronik bir kulak hastalığınız var mı?',
        'Kronik başka bir hastalığınız var mı?', 'Ellerinizde titremesi veya görme bozukluğunuz var mı?', 'Günlük işlerinizde size yardımcı olan birisi var mı?',
        'İşitme cihazı için danıştınız mı?', 'Daha önce işitme cihazı kullandınız mı?', 'Çevrenizde işitme cihazı kullanan birisi var mı?',
        'İşitme cihazı ile ilgili ön yargılarınız veya endişeleriniz var mı?'
    ];
    // Başlangıç soruları yalnızca boş bir kurulumda eklenir. Daha sonra yapılan
    // düzenleme ve silme işlemlerinde varsayılan kayıtlar geri oluşturulmaz.
    $detailLabels = ['Ne kadar süre', 'Hangi ortamlarda', 'Hangi hastalık', 'Yakınlık derecesi', 'Kaç yıl önce', 'Hangi hastalık', 'Hangi hastalık', 'Hangisi var', 'Yakınlık derecesi', 'Hangi doktor', 'Hangi marka / kaç yıldır', 'Yakınlık derecesi', ''];
    if ((int)$pdo->query('SELECT COUNT(*) FROM anamnesis_question_definitions')->fetchColumn() === 0) {
        $insert = $pdo->prepare('INSERT INTO anamnesis_question_definitions(name,detail_label,active,sort_order) VALUES(?,?,?,?)');
        foreach ($defaults as $index => $name) $insert->execute([$name, $detailLabels[$index] ?? '', 1, $index + 1]);
    }
    $fillDetail = $pdo->prepare("UPDATE anamnesis_question_definitions SET detail_label=? WHERE sort_order=? AND COALESCE(detail_label,'')=''");
    foreach ($detailLabels as $index => $detailLabel) if ($detailLabel !== '') $fillDetail->execute([$detailLabel, $index + 1]);
    return $pdo->query('SELECT * FROM anamnesis_question_definitions ORDER BY sort_order,name')->fetchAll();
}

function anamnesis_print_settings(): array
{
    $pdo = db();
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $pdo->exec($sqlite
        ? 'CREATE TABLE IF NOT EXISTS anamnesis_print_settings (setting_key VARCHAR(80) PRIMARY KEY, setting_value TEXT NULL)'
        : 'CREATE TABLE IF NOT EXISTS anamnesis_print_settings (setting_key VARCHAR(80) PRIMARY KEY, setting_value TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $defaults = ['title' => 'VOX İ.M. - HASTA KARTI', 'header_color' => '#14843c', 'font_size' => '11', 'question_font_size' => '11', 'layout' => '{"header":{"x":8,"y":7},"meta":{"x":8,"y":17},"questions":{"x":8,"y":27},"footer":{"x":8,"y":91}}'];
    $statement = $pdo->query('SELECT setting_key,setting_value FROM anamnesis_print_settings');
    foreach ($statement->fetchAll(PDO::FETCH_KEY_PAIR) as $key => $value) if (array_key_exists($key, $defaults)) $defaults[$key] = (string)$value;
    return $defaults;
}

function save_anamnesis_print_settings(array $settings): void
{
    $pdo = db();
    anamnesis_print_settings();
    $update = $pdo->prepare('UPDATE anamnesis_print_settings SET setting_value=? WHERE setting_key=?');
    $insert = $pdo->prepare('INSERT INTO anamnesis_print_settings(setting_key,setting_value) VALUES(?,?)');
    $exists = $pdo->prepare('SELECT 1 FROM anamnesis_print_settings WHERE setting_key=?');
    foreach ($settings as $key => $value) {
        $exists->execute([(string)$key]);
        if ($exists->fetchColumn()) $update->execute([(string)$value, (string)$key]);
        else $insert->execute([(string)$key, (string)$value]);
    }
}
