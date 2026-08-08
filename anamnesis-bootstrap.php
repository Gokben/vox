<?php
declare(strict_types=1);

function anamnesis_question_definitions(): array
{
    $pdo = db();
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $pdo->exec($sqlite
        ? 'CREATE TABLE IF NOT EXISTS anamnesis_question_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(512) NOT NULL UNIQUE, active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0)'
        : 'CREATE TABLE IF NOT EXISTS anamnesis_question_definitions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(512) NOT NULL UNIQUE, active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $defaults = [
        'Gürültülü ortamlarda çalıştınız mı?', 'Yüksek ses eşiği maruz kalır mısınız?', 'Çocukken ateşli hastalık geçirdiniz mi?',
        'Ailede işitme kaybı olan birisi var mı?', 'Daha önce kulak ameliyatı oldunuz mu?', 'Teşhisi konmuş kronik bir kulak hastalığınız var mı?',
        'Kronik başka bir hastalığınız var mı?', 'Ellerinizde titremesi veya görme bozukluğunuz var mı?', 'Günlük işlerinizde size yardımcı olan birisi var mı?',
        'İşitme cihazı için danıştınız mı?', 'Daha önce işitme cihazı kullandınız mı?', 'Çevrenizde işitme cihazı kullanan birisi var mı?',
        'İşitme cihazı ile ilgili ön yargılarınız veya endişeleriniz var mı?'
    ];
    $insert = $sqlite
        ? $pdo->prepare('INSERT OR IGNORE INTO anamnesis_question_definitions(name,active,sort_order) VALUES(?,?,?)')
        : $pdo->prepare('INSERT IGNORE INTO anamnesis_question_definitions(name,active,sort_order) VALUES(?,?,?)');
    foreach ($defaults as $index => $name) $insert->execute([$name, 1, $index + 1]);
    return $pdo->query('SELECT * FROM anamnesis_question_definitions ORDER BY sort_order,name')->fetchAll();
}
