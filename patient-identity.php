<?php
declare(strict_types=1);
function ensure_patient_passport_schema(PDO $pdo): void {
    foreach (['patients','external_technical_patients'] as $table) {
        $columns=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
            ? array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC),'name')
            : $pdo->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('passport_no',$columns,true)) $pdo->exec("ALTER TABLE $table ADD COLUMN passport_no VARCHAR(64) NULL");
    }
}
function patient_passport_error(string $number): string {
    return mb_strlen($number,'UTF-8')>64 ? 'Pasaport numarası en fazla 64 karakter olabilir.' : '';
}
function patient_identity_error(string $number): string {
    return $number === '' || preg_match('/^[0-9]{11}$/D', $number) ? '' : 'T.C. kimlik numarası tam 11 rakam olmalıdır.';
}
function patient_identity_rows(PDO $pdo): array {
    ensure_patient_passport_schema($pdo);
    $rows=[];
    foreach (['patients','external_technical_patients'] as $table) {
        foreach ($pdo->query("SELECT id,full_name,national_id,passport_no FROM $table ORDER BY full_name,id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['source']=$table; $rows[]=$row;
        }
    }
    return $rows;
}
function patient_identity_audit(array $rows): array {
    $counts=[];
    foreach ($rows as $row) { if (trim((string)($row['passport_no']??''))!=='') continue; $key=trim((string)$row['national_id']); if ($key!=='' && patient_identity_error($key)==='') $counts[$key]=($counts[$key]??0)+1; }
    $issues=[];
    foreach ($rows as $row) {
        if (trim((string)($row['passport_no']??''))!=='') continue;
        $raw=(string)$row['national_id']; $key=trim($raw); $reasons=[];
        if ($key==='') continue;
        if (patient_identity_error($raw)!=='') $reasons[]='Tam 11 rakam değil';
        if ($key!=='' && ($counts[$key]??0)>1) $reasons[]='Mükerrer ('.$counts[$key].' kayıt)';
        if ($reasons) { $row['reasons']=$reasons; $issues[]=$row; }
    }
    return $issues;
}
function patient_identity_validate(PDO $pdo, string $number, string $table, int $id, string $passport = ''): string {
    if (trim($passport)!=='') return '';
    $error=patient_identity_error($number);
    if ($error!=='' || $number==='') return $error;
    // Keep the lock until the request ends, including the subsequent INSERT/UPDATE.
    // Existing duplicate records can therefore be reviewed without deleting them.
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') {
        $lock='vox-patient-id-'.hash('sha256',$number);
        $lock=substr($lock,0,64);
        $stmt=$pdo->prepare('SELECT GET_LOCK(?,10)'); $stmt->execute([$lock]);
        if ((int)$stmt->fetchColumn()!==1) return 'Kimlik kontrolü tamamlanamadı. Lütfen tekrar deneyin.';
        register_shutdown_function(static function() use($pdo,$lock) { try{$s=$pdo->prepare('SELECT RELEASE_LOCK(?)');$s->execute([$lock]);}catch(Throwable $e){} });
    }
    foreach (['patients','external_technical_patients'] as $source) {
        $stmt=$pdo->prepare("SELECT id FROM $source WHERE TRIM(national_id)=? AND COALESCE(TRIM(passport_no),'')='' AND id<>? LIMIT 1");
        $stmt->execute([$number,$source===$table?$id:0]);
        if ($stmt->fetchColumn()!==false) return 'Bu T.C. kimlik numarası başka bir hasta kaydında kullanılıyor. Mevcut hasta kartını kullanın.';
    }
    return '';
}
