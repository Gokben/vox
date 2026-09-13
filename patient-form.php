<?php
require __DIR__ . '/config.php';
require __DIR__ . '/patient-report-schema.php';
require_once __DIR__ . '/patient-identity.php';
require __DIR__ . '/social-security-bootstrap.php';
require __DIR__ . '/service-type-bootstrap.php';
require __DIR__ . '/source-bootstrap.php';
require_login();
$patientRatingReady = true;
try {
    $ratingPdo = db();
    $ratingColumns = $ratingPdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? array_column($ratingPdo->query('PRAGMA table_info(patients)')->fetchAll(), 'name')
        : $ratingPdo->query('SHOW COLUMNS FROM patients')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('patient_rating', $ratingColumns, true)) {
        $ratingPdo->exec($ratingPdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'ALTER TABLE patients ADD COLUMN patient_rating INTEGER NOT NULL DEFAULT 0'
            : 'ALTER TABLE patients ADD COLUMN patient_rating TINYINT UNSIGNED NOT NULL DEFAULT 0');
    }
    if (!in_array('patient_rating_comment', $ratingColumns, true)) {
        $ratingPdo->exec($ratingPdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'ALTER TABLE patients ADD COLUMN patient_rating_comment TEXT NULL'
            : 'ALTER TABLE patients ADD COLUMN patient_rating_comment TEXT NULL');
    }
    if (!in_array('patient_status', $ratingColumns, true)) {
        $ratingPdo->exec($ratingPdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "ALTER TABLE patients ADD COLUMN patient_status TEXT NOT NULL DEFAULT 'active'"
            : "ALTER TABLE patients ADD COLUMN patient_status VARCHAR(12) NOT NULL DEFAULT 'active'");
    }
    if (!in_array('proximity_relation', $ratingColumns, true)) {
        $ratingPdo->exec($ratingPdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'ALTER TABLE patients ADD COLUMN proximity_relation TEXT NULL'
            : 'ALTER TABLE patients ADD COLUMN proximity_relation VARCHAR(255) NULL');
    }
    if (!in_array('proximity_relation_secondary', $ratingColumns, true)) {
        $ratingPdo->exec($ratingPdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'ALTER TABLE patients ADD COLUMN proximity_relation_secondary TEXT NULL'
            : 'ALTER TABLE patients ADD COLUMN proximity_relation_secondary VARCHAR(255) NULL');
    }
} catch (Throwable $exception) {
    $patientRatingReady = false;
    error_log('patient-form.php rating schema: ' . $exception->getMessage());
}
$formSetupErrors = [];
try {
    if (function_exists('ensure_branch_schema')) ensure_branch_schema();
} catch (Throwable $exception) {
    $formSetupErrors[] = 'branch';
    error_log('patient-form.php branch schema: ' . $exception->getMessage());
}
try {
    ensure_patient_report_schema();
} catch (Throwable $exception) {
    $formSetupErrors[] = 'report';
    error_log('patient-form.php report schema: ' . $exception->getMessage());
}
try {
    ensure_patient_service_type_schema();
} catch (Throwable $exception) {
    $formSetupErrors[] = 'service';
    error_log('patient-form.php service schema: ' . $exception->getMessage());
}
try {
    ensure_patient_source_schema();
} catch (Throwable $exception) {
    $formSetupErrors[] = 'source';
    error_log('patient-form.php source schema: ' . $exception->getMessage());
}
require __DIR__ . '/patient-layout.php';
require __DIR__ . '/employee-patient-link.php';
$staffNames = ['staff_cansu'=>'Cansu','staff_busra'=>'Büşra','staff_belma'=>'Belma Baysan'];
try {
    ensure_patient_staff_yeliz_schema();
    $staffNames = patient_staff_names();
} catch (Throwable $exception) {
    $formSetupErrors[] = 'staff';
    error_log('patient-form.php staff schema: ' . $exception->getMessage());
}

ensure_patient_passport_schema(db());
ensure_employee_active_schema();
ensure_patient_opening_employee_schema(db());
$openingEmployees = db()->query('SELECT id,full_name FROM employees WHERE active=1 ORDER BY full_name,id')->fetchAll();
$id = (int)($_GET['id'] ?? 0);
$returnTo = trim((string)($_POST['return'] ?? $_GET['return'] ?? 'patients.php'));
if (!preg_match('/^(patients|patient-results)\.php(?:\?.*)?$/', $returnTo)) $returnTo = 'patients.php';
$isEmbeddedWindow = (string)($_POST['_vox_window'] ?? $_GET['_vox_window'] ?? '') === '1';
$fields = ['branch_id','record_date','full_name','national_id','passport_no','phone_primary','proximity_relation','phone_secondary','proximity_relation_secondary','birth_date','address','patient_rating','patient_rating_comment','patient_status','social_security','report_status','source_id','source_unit_id','source_account_id','source_detail','source_referral_detail','notes','opening_employee_id'];
$patient = array_fill_keys($fields, '');
$patient['patient_status'] = 'active';
$defaultRecordDate = (string)($_GET['date'] ?? '');
if (preg_match('/^20\d{2}-\d{2}-\d{2}$/', $defaultRecordDate)) $patient['record_date'] = $defaultRecordDate;
$patient['report_info'] = '';
$patient += ['approval'=>0,'considering'=>0,'rejected'=>0,'staff_cansu'=>0,'staff_busra'=>0,'staff_belma'=>0];
$error = '';
$patient['staff_yeliz'] = (int)($patient['staff_yeliz'] ?? 0);
$patient['staff_gunes'] = (int)($patient['staff_gunes'] ?? 0);
$patient['staff_erva'] = (int)($patient['staff_erva'] ?? 0);
$patient['staff_merve'] = (int)($patient['staff_merve'] ?? 0);
$patient['staff_seyma'] = (int)($patient['staff_seyma'] ?? 0);
$branches = [];
try {
    $branches=db()->query('SELECT id,name FROM branches WHERE active=1 ORDER BY name')->fetchAll();
} catch (Throwable $exception) {
    $formSetupErrors[] = 'branch-options';
    error_log('patient-form.php branch options: ' . $exception->getMessage());
}
$socialSecurityOptions = [];
try {
    $socialSecurityOptions=social_security_definitions();
} catch (Throwable $exception) {
    $formSetupErrors[] = 'social-security';
    error_log('patient-form.php social security options: ' . $exception->getMessage());
}
$serviceTypeDefinitions = [];
try {
    $serviceTypeDefinitions=service_type_definitions();
} catch (Throwable $exception) {
    $formSetupErrors[] = 'service-options';
    error_log('patient-form.php service options: ' . $exception->getMessage());
}
$serviceTypeOptions=array_filter($serviceTypeDefinitions, static fn(array $row): bool => (int)$row['active'] === 1);
$sourceDefinitions = [];
$sourceUnits = [];
$sourceAccounts = [];
try {
    $sourceDefinitions=source_definitions();
    $hasAccounts = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='current_accounts'")->fetchColumn()
        : db()->query("SHOW TABLES LIKE 'current_accounts'")->fetchColumn();
    if ($hasAccounts) $sourceAccounts = db()->query("SELECT id,title,short_name,account_type FROM current_accounts WHERE account_type IN ('institution','customer') ORDER BY title,id")->fetchAll();
    $sourceUnits=db()->query('SELECT id,name,last_name FROM units ORDER BY name,last_name,id')->fetchAll();
} catch (Throwable $exception) {
    $formSetupErrors[] = 'source-options';
    error_log('patient-form.php source options: ' . $exception->getMessage());
}
if ($id) {
    $stmt=db()->prepare('SELECT * FROM patients WHERE id=?'); $stmt->execute([$id]); $found=$stmt->fetch();
    if (!$found) { http_response_code(404); exit('Hasta kaydı bulunamadı.'); }
    $patient=array_merge($patient,$found);
}
$savedOpeningEmployeeId = (int)($patient['opening_employee_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    foreach($fields as $field) {
        $patient[$field]=trim((string)($_POST[$field]??''));
    }
    $patient['patient_rating_comment'] = function_exists('mb_substr')
        ? mb_substr($patient['patient_rating_comment'], 0, 256, 'UTF-8')
        : substr($patient['patient_rating_comment'], 0, 256);
    $patient['patient_rating'] = max(0, min(5, (int)$patient['patient_rating']));
    if (!in_array($patient['patient_status'], ['active', 'deceased'], true)) $patient['patient_status'] = 'active';
    if (!in_array($patient['report_status'], array_merge([''], REPORT_STATUSES), true)) {
        $error = 'Rapor alanı geçerli bir seçenek olmalıdır.';
    }
    $sourceName = '';
    $patient['source_id'] = (int)$patient['source_id'];
    if ($patient['source_id']) {
        $sourceStatement = db()->prepare('SELECT name FROM source_definitions WHERE id=?');
        $sourceStatement->execute([$patient['source_id']]);
        $sourceName = (string)($sourceStatement->fetchColumn() ?: '');
        if ($sourceName === '') $error = 'Seçilen kaynak bulunamadı.';
        elseif (!in_array($sourceName, ['Kaynak Ünite', 'Ünite'], true)) $patient['source_unit_id'] = 0;
    } else {
        $patient['source_unit_id'] = 0;
    }
    if (!in_array($sourceName, ['Kurumlar', 'Firmalar'], true)) $patient['source_account_id'] = '';
    if ($error === '') $error=patient_source_account_error(db(), $sourceName, $patient['source_account_id']);
    if ($error === '') $error=patient_opening_employee_error(db(), $patient['opening_employee_id'], $savedOpeningEmployeeId);
    if ($error === '') $error=patient_passport_error($patient['passport_no']);
    if ($error === '') $error=patient_identity_validate(db(),$patient['national_id'],'patients',$id,$patient['passport_no']);
    if ($patient['full_name']==='') $error='Ad soyad alanı zorunludur.';
    elseif ($error === '') {
        $values=[]; foreach($fields as $field) $values[$field]=$patient[$field];
        $values['source_account_id'] = $patient['source_account_id'] === '' ? null : (int)$patient['source_account_id'];
        $values['opening_employee_id'] = $patient['opening_employee_id'] === '' ? null : (int)$patient['opening_employee_id'];
        if ($id) {
            $set=implode(',',array_map(fn($field)=>$field.'=?',array_keys($values)));
            $stmt=db()->prepare("UPDATE patients SET $set,updated_at=CURRENT_TIMESTAMP WHERE id=?"); $stmt->execute([...array_values($values),$id]);
        } else {
            $values['import_order']=(int)db()->query('SELECT COALESCE(MAX(import_order),0)+1 FROM patients')->fetchColumn();
            $values['created_by']=(int)($_SESSION['user']['id'] ?? 0) ?: null;
            $columns=array_keys($values); $stmt=db()->prepare('INSERT INTO patients ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')'); $stmt->execute(array_values($values));
        }
        if ($isEmbeddedWindow) {
            ?><!doctype html><html lang="tr"><head><meta charset="utf-8"><title>Kaydedildi</title></head><body><script>window.parent.postMessage({type:'vox-patient-saved'},location.origin);</script></body></html><?php
            exit;
        }
        redirect($returnTo);
    }
}
$patientFullName = (string)$patient['full_name'];
$patientFullNameHtml = e($patientFullName);
start_patient_staff_ui_link($staffNames, ['staff_yeliz'=>!empty($patient['staff_yeliz']),'staff_gunes'=>!empty($patient['staff_gunes']),'staff_erva'=>!empty($patient['staff_erva']),'staff_merve'=>!empty($patient['staff_merve']),'staff_seyma'=>!empty($patient['staff_seyma'])]);
patient_header($id?'Hasta Düzenle':'Yeni Hasta', 'patients');
?>
<style>
.patient-form-page{padding-top:28px!important}.vuexy-form-card{background:var(--card);border:1px solid var(--line);border-radius:8px;box-shadow:0 .25rem 1.125rem rgba(47,43,61,.1);overflow:hidden}.vuexy-form-header{display:flex;align-items:center;justify-content:space-between;min-height:70px;padding:0 24px;border-bottom:1px solid var(--line)}.vuexy-form-header h2{margin:0;font-size:20px;font-weight:500}.vuexy-icon-form{padding:10px 24px 24px}.form-section-title{margin:16px 0 8px;padding-bottom:10px;border-bottom:1px solid var(--line);font-size:14px;color:#20a447}.icon-form-row{display:grid;grid-template-columns:150px minmax(0,1fr);align-items:start;gap:0 0;margin:14px 0}.icon-form-label{padding:11px 15px 0 0;color:var(--text);font-size:14px}.required-mark{color:#e44747}.merged-input{display:flex;align-items:stretch;min-height:40px;border:1px solid #d5d3de;border-radius:6px;background:var(--card);overflow:hidden;transition:border-color .18s,box-shadow .18s}.merged-input:focus-within{border-color:#20a447;box-shadow:0 0 0 3px rgba(32,164,71,.12)}.merged-icon{display:grid;place-items:center;flex:0 0 46px;color:#686574;font-size:18px}.merged-input input,.merged-input select,.merged-input textarea{width:100%!important;height:38px!important;min-height:38px!important;margin:0!important;padding:8px 12px 8px 0!important;border:0!important;border-radius:0!important;outline:0!important;background:transparent!important;color:var(--text)!important;font:inherit!important;box-shadow:none!important}.merged-input textarea{height:76px!important;resize:vertical!important;padding-top:10px!important}.phone-input input{flex:1;min-width:0;padding-left:8px!important}.proximity-toggle{display:grid;place-items:center;flex:0 0 42px;margin:0;border:0;border-left:1px solid var(--line);background:transparent;color:#20a447;cursor:pointer}.proximity-toggle:hover{background:rgba(32,164,71,.08)}.proximity-toggle:disabled{opacity:.4;cursor:not-allowed}.proximity-toggle .icon-base{font-size:19px}.proximity-row.is-hidden{display:none}.check-row{display:flex;flex-wrap:wrap;gap:10px 24px;padding:8px 0}.check-row label{display:flex!important;flex-direction:row!important;align-items:center;gap:8px;color:var(--text);font-weight:400!important}.check-row input{width:17px!important;height:17px!important;margin:0!important;accent-color:#20a447}.vuexy-form-actions{display:flex;align-items:center;gap:12px;margin:22px 0 0 150px;padding-left:0}.vuexy-form-actions .button{min-width:100px}.cancel-link{color:var(--muted);text-decoration:none}.form-alert{margin:18px 24px 0;padding:12px 14px;border-radius:6px;background:#fde8e8;color:#a62c2c}[data-theme=dark] .merged-input{background:#30334d;border-color:#565a78}[data-theme=dark] .merged-icon,[data-theme=dark] .icon-form-label{color:#fff}@media(max-width:720px){.vuexy-icon-form{padding:10px 16px 22px}.icon-form-row{grid-template-columns:1fr;gap:7px}.icon-form-label{padding:0}.vuexy-form-actions{margin-left:0}.vuexy-form-header{padding:0 16px}}
.patient-container.patient-form-page{width:100%!important;max-width:1100px!important;margin-left:auto!important;margin-right:auto!important;padding:28px 20px 48px!important}.patient-form-page .vuexy-form-card{width:100%!important}.source-unit-row[hidden],.source-company-row[hidden]{display:none!important}.icon-form-row:has(input[name="report_info"]),.icon-form-row:has(input[name="source_marketing"]){display:none!important}
.patient-form-page .vuexy-form-header{justify-content:flex-start!important;gap:12px}.patient-form-home{display:grid;place-items:center;flex:0 0 36px;width:36px;height:36px;border-radius:7px;background:#19a94b;color:#fff;text-decoration:none}.patient-form-home:hover{background:#148d3e;color:#fff}.patient-form-home i{font-size:18px}
</style>
<style>.patient-name-input{position:relative}.patient-name-display{position:absolute;z-index:2;left:46px;right:12px;top:50%;transform:translateY(-50%);color:var(--text);font:14px/24px Tahoma,'Segoe UI',sans-serif;white-space:nowrap;cursor:text;font-variant:normal;text-transform:none}.patient-name-input:not(:focus-within) input[name="full_name"]{color:transparent!important}.patient-name-input:focus-within .patient-name-display{display:none}</style>
<style>.merged-input input[name="proximity_relation"],.merged-input input[name="proximity_relation_secondary"]{font-family:Tahoma,'Segoe UI',sans-serif!important;font-variant:normal!important;text-transform:none!important}</style>
<style>
.patient-rating{display:flex;align-items:center;gap:5px;min-height:40px}.patient-rating input{position:absolute;opacity:0}.patient-rating label{font-size:29px;line-height:1;color:#d7d6de;cursor:pointer;transition:color .15s,transform .15s}.patient-rating label.is-selected,.patient-rating label:hover{color:#f3a64a}.patient-rating label:hover{transform:scale(1.08)}.patient-rating:focus-within{outline:none;box-shadow:none}.patient-rating input:focus-visible+label{outline:2px solid #2c72b5;outline-offset:1px}.merged-input textarea[name="patient_rating_comment"]{height:38px!important;min-height:38px!important;padding-top:8px!important;resize:none!important}
</style>
<style>
/* Referans klasik Windows Yeni Hasta formu */
.patient-form-page{padding:4px!important;background:#d8e9f8!important;font-family:Tahoma,"Segoe UI",sans-serif!important}
html:has(body#vox-app.vox-embedded-window>main.patient-form-page),
body#vox-app.vox-embedded-window:has(>main.patient-form-page){background:#dcebf8!important}
body#vox-app>main.patient-form-page,
body#vox-app.vox-embedded-window>main.patient-form-page{padding:0!important;overflow:hidden!important}
body#vox-app.vox-embedded-window>main.patient-form-page{height:auto!important;min-height:0!important}
body#vox-app>main.patient-form-page .vuexy-form-card,
body#vox-app.vox-embedded-window>main.patient-form-page .vuexy-form-card{width:100%!important;height:100%!important;margin:0!important;border:0!important}
body#vox-app.vox-embedded-window>main.patient-form-page .vuexy-form-card{height:auto!important;min-height:0!important;overflow:visible!important}
.patient-form-page .vuexy-form-card{height:100%!important;min-height:0!important;border:1px solid #6796c5!important;border-radius:0!important;background:#dcebf8!important;box-shadow:none!important;overflow:auto!important}
.patient-form-page .vuexy-form-header{display:none!important}
.patient-form-page .vuexy-icon-form.classic-patient-form{display:flex!important;flex-direction:column!important;gap:4px!important;min-height:100%!important;padding:4px!important;background:#dcebf8!important}
body#vox-app.vox-embedded-window .patient-form-page .vuexy-icon-form.classic-patient-form{height:auto!important;min-height:0!important}
.classic-patient-section{margin:0!important;padding:0 7px 7px!important;border:1px solid #79a5d0!important;border-radius:2px!important;background:#edf5fd!important;box-shadow:inset 0 1px #fff!important}
.classic-patient-section-title{height:23px!important;margin:0 -7px 7px!important;padding:3px 9px!important;border-bottom:1px solid #79a5d0!important;background:linear-gradient(#f8fcff,#cfe4f8)!important;color:#124c82!important;font:700 13px/17px Tahoma,"Segoe UI",sans-serif!important}
.classic-patient-section-title i{display:inline-block;width:17px;margin-right:4px;color:#1769a8!important;text-align:center;font-style:normal}
.classic-patient-grid{display:grid!important;gap:5px 8px!important;align-items:start!important}
.classic-basic-grid{grid-template-columns:repeat(4,minmax(0,1fr))!important}
.classic-service-grid,.classic-application-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}
.classic-patient-form .icon-form-row.classic-field{display:block!important;min-width:0!important;margin:0!important}
.classic-patient-form .icon-form-label{display:block!important;height:17px!important;margin:0!important;padding:0!important;color:#26384a!important;font:700 11px/15px Tahoma,"Segoe UI",sans-serif!important}
.classic-patient-form .merged-input{min-height:23px!important;height:auto!important;border:1px solid #8daece!important;border-radius:0!important;background:#fff!important;box-shadow:inset 1px 1px 2px rgba(25,70,115,.08)!important;overflow:visible!important}
.classic-patient-form .merged-input:focus-within{border-color:#2c72b5!important;box-shadow:0 0 0 1px #85b8e8!important}
.classic-patient-form .merged-icon{display:none!important}
.classic-patient-form .merged-input input,.classic-patient-form .merged-input select,.classic-patient-form .merged-input textarea{height:21px!important;min-height:21px!important;padding:2px 5px!important;background:#fff!important;color:#26384a!important;font:11px/15px Tahoma,"Segoe UI",sans-serif!important}
.classic-patient-form .merged-input textarea{height:38px!important;min-height:38px!important;padding-top:4px!important;resize:vertical!important}
.classic-patient-form .patient-name-display{left:5px!important;right:5px!important;color:#26384a!important;font:11px/15px Tahoma,"Segoe UI",sans-serif!important}
.classic-patient-form .phone-input input{padding-left:5px!important}
.classic-patient-form .proximity-toggle{display:none!important}
.classic-patient-form #proximity-row.is-hidden{display:block!important}
.classic-patient-form #proximity-secondary-row{display:block!important}
.classic-patient-form .field-address{grid-column:span 2!important}
.classic-patient-form .field-comment{grid-column:span 2!important}
.classic-patient-form .field-notes{grid-column:1 / -1!important}
.classic-patient-form .field-notes .merged-input,.classic-patient-form .field-notes textarea{width:100%!important;box-sizing:border-box!important}
.classic-patient-form .field-address .merged-input textarea{height:39px!important;min-height:39px!important}
.classic-patient-form .field-comment .merged-input textarea{width:100%!important;box-sizing:border-box!important;height:90px!important;min-height:90px!important;padding-top:5px!important;resize:vertical!important}
.classic-patient-form .patient-rating{height:29px!important;min-height:29px!important;gap:2px!important;padding:0!important}
.classic-patient-form .patient-rating label{display:grid!important;place-items:center!important;width:18px!important;height:28px!important;border:1px solid #a5bdd4!important;background:linear-gradient(#fff,#dbe8f3)!important;color:#8fa5ba!important;font-size:20px!important;line-height:1!important}
.classic-patient-form .patient-rating label.is-selected,.classic-patient-form .patient-rating label:hover{color:#f2a22d!important}
.classic-patient-form .check-row{height:29px!important;padding:5px 4px!important;gap:18px!important;font-size:11px!important}
.classic-patient-form .check-row input{width:13px!important;height:13px!important;accent-color:#149447!important}
.classic-patient-form .source-unit-row[hidden],body#vox-app .classic-patient-form .source-referral-row[hidden]{display:none!important}
body#vox-app .classic-patient-form .source-account-row[hidden]{display:none!important}
.classic-patient-form .field-source-account{grid-column:1 / 2!important}
.classic-patient-form .field-source-referral{grid-column:1 / 2!important}
.classic-patient-form .vuexy-form-actions{order:99!important;min-height:40px!important;margin:0!important;padding:4px 6px!important;display:flex!important;align-items:center!important;gap:7px!important;border:1px solid #79a5d0!important;border-radius:2px!important;background:linear-gradient(#eef7ff,#d1e4f5)!important}
.classic-form-note{margin-right:auto;color:#5b6875;font:11px Tahoma,"Segoe UI",sans-serif}
.classic-form-note .required-mark{font-weight:700}
.classic-patient-form .vuexy-form-actions .button{order:10!important;min-width:114px!important;height:29px!important;min-height:29px!important;margin-left:0!important;padding:0 10px!important;border:1px solid #19782b!important;border-radius:3px!important;background:linear-gradient(#66d36f,#1d9b35 55%,#12842b)!important;color:#fff!important;box-shadow:inset 1px 1px rgba(255,255,255,.55),1px 1px 2px rgba(0,0,0,.18)!important;font:700 12px Tahoma,"Segoe UI",sans-serif!important;text-shadow:1px 1px #176726!important}
.classic-patient-form .vuexy-form-actions .button:hover{background:linear-gradient(#78df80,#27ac40 55%,#168d30)!important}
.classic-patient-form .patient-services-button{order:9!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:5px!important;min-width:114px!important;height:29px!important;min-height:29px!important;margin:0!important;padding:0 10px!important;border:1px solid #b96b00!important;border-radius:3px!important;background:linear-gradient(#ffd45c,#f7aa24 55%,#e88c00)!important;color:#111!important;box-shadow:inset 1px 1px rgba(255,255,255,.62),1px 1px 2px rgba(0,0,0,.2)!important;text-decoration:none!important;font:700 12px Tahoma,"Segoe UI",sans-serif!important;text-shadow:0 1px rgba(255,255,255,.45)!important}
.classic-patient-form .patient-services-button:hover{background:linear-gradient(#ffe07a,#ffb936 55%,#ef9708)!important;color:#111!important}
.classic-patient-form .patient-services-button i{color:#111!important;font-size:15px!important}
.classic-patient-form .cancel-link{display:none!important}
.classic-patient-form .form-section-title{display:none!important}
.patient-form-page .form-alert{margin:6px!important;border-radius:2px!important;font-size:11px!important}
@media(max-width:900px){.classic-basic-grid,.classic-service-grid,.classic-application-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}}
@media(max-width:600px){.classic-basic-grid,.classic-service-grid,.classic-application-grid{grid-template-columns:1fr!important}.classic-patient-form .field-address,.classic-patient-form .field-comment{grid-column:auto!important}}
</style>
<main class="patient-container patient-form-page"><section class="vuexy-form-card" data-static-form><header class="vuexy-form-header"><a class="patient-form-home" href="<?=e(url($returnTo))?>" title="Hasta listesine dön" aria-label="Hasta listesine dön"><i class="icon-base ti tabler-home" aria-hidden="true"></i></a><h2><?=$id?'Hasta Düzenle':'Yeni Hasta Kaydı'?></h2></header><?php if($error):?><div class="form-alert"><?=e($error)?></div><?php endif?><form class="vuexy-icon-form" method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="return" value="<?=e($returnTo)?>">
<h3 class="form-section-title">Temel Bilgiler</h3>
<div class="icon-form-row"><label class="icon-form-label">Şube <span class="required-mark">*</span></label><div class="merged-input"><span class="merged-icon">⌂</span><select name="branch_id" required><option value="">Şube seçin</option><?php foreach($branches as $branch):?><option value="<?=(int)$branch['id']?>" <?=(int)$patient['branch_id']===(int)$branch['id']?'selected':''?>><?=e($branch['name'])?></option><?php endforeach?></select></div></div>
<div class="icon-form-row"><label class="icon-form-label">Kayıt Tarihi</label><div class="merged-input"><span class="merged-icon">▣</span><input type="date" name="record_date" value="<?=e($patient['record_date'])?>"></div></div>
<div class="icon-form-row"><label class="icon-form-label">Ad Soyad <span class="required-mark">*</span></label><div class="merged-input patient-name-input"><span class="merged-icon">♙</span><input id="patient-full-name" name="full_name" lang="tr" style="font-family:Tahoma,'Segoe UI',sans-serif!important;font-size:14px!important;line-height:24px!important;padding-top:6px!important;padding-bottom:6px!important;text-transform:none" value="<?=$patientFullNameHtml?>" required><span class="patient-name-display" role="button" tabindex="0" aria-label="Ad soyadı düzenle"><?=$patientFullNameHtml?></span></div></div>
<div class="icon-form-row"><label class="icon-form-label">T.C. Kimlik No</label><div class="merged-input"><span class="merged-icon">▤</span><input name="national_id" maxlength="11" minlength="11" pattern="[0-9]{11}" inputmode="numeric" value="<?=e($patient['national_id'])?>"><input name="passport_no" aria-label="Pasaport No" maxlength="64" value="<?=e($patient['passport_no'])?>" hidden></div></div>
<div class="icon-form-row"><label class="icon-form-label">Doğum Tarihi</label><div class="merged-input"><span class="merged-icon">◷</span><input type="date" name="birth_date" value="<?=e($patient['birth_date'])?>"></div></div>
<div class="icon-form-row"><label class="icon-form-label">Telefon 1</label><div class="merged-input phone-input"><span class="merged-icon">⌕</span><input id="phone_primary" name="phone_primary" inputmode="tel" maxlength="14" placeholder="0546 638 67 75" value="<?=e($patient['phone_primary'])?>"><button id="proximity-toggle" class="proximity-toggle" type="button" title="Yakınlık derecesini aç/kapat" aria-label="Yakınlık derecesini aç/kapat" aria-controls="proximity-row" aria-expanded="false" <?=trim($patient['phone_primary'])===''?'disabled':''?>><i class="icon-base ti tabler-users"></i></button></div></div>
<div id="proximity-row" class="icon-form-row proximity-row is-hidden"><label class="icon-form-label">Yakınlık Derecesi</label><div class="merged-input"><span class="merged-icon">♧</span><input id="proximity_relation" name="proximity_relation" value="<?=e($patient['proximity_relation'])?>"></div></div>
<div class="icon-form-row"><label class="icon-form-label">Telefon 2</label><div class="merged-input phone-input"><span class="merged-icon">⌕</span><input id="phone_secondary" name="phone_secondary" placeholder="Telefon veya kişi bilgisi" value="<?=e($patient['phone_secondary'])?>"><button id="proximity-secondary-toggle" class="proximity-toggle" type="button" title="Yakınlık derecesini aç/kapat" aria-label="Yakınlık derecesini aç/kapat" aria-controls="proximity-secondary-row" aria-expanded="false" <?=trim($patient['phone_secondary'])===''?'disabled':''?>><i class="icon-base ti tabler-users"></i></button></div></div>
<div id="proximity-secondary-row" class="icon-form-row proximity-row is-hidden"><label class="icon-form-label">Yakınlık Derecesi</label><div class="merged-input"><span class="merged-icon">♧</span><input id="proximity_relation_secondary" name="proximity_relation_secondary" value="<?=e($patient['proximity_relation_secondary'])?>"></div></div>
<div class="icon-form-row"><label class="icon-form-label">Adres</label><div class="merged-input"><span class="merged-icon">⌂</span><textarea name="address"><?=e($patient['address'])?></textarea></div></div>
<div class="icon-form-row"><span class="icon-form-label">Değerlendirme</span><div class="patient-rating" role="radiogroup" aria-label="Değerlendirme"><?php for($star=1;$star<=5;$star++):?><input id="patient_rating_<?=$star?>" type="radio" name="patient_rating" value="<?=$star?>" <?=$patient['patient_rating']==$star?'checked':''?>><label class="<?=$patient['patient_rating']>=$star?'is-selected':''?>" for="patient_rating_<?=$star?>" title="<?=$star?> yıldız">★</label><?php endfor?></div></div>
<div class="icon-form-row"><label class="icon-form-label">Yorum</label><div class="merged-input"><span class="merged-icon">▱</span><textarea name="patient_rating_comment" maxlength="256"><?=e($patient['patient_rating_comment'])?></textarea></div></div>
<div class="icon-form-row"><span class="icon-form-label">Hasta</span><div class="check-row"><label><input type="radio" name="patient_status" value="active" <?=$patient['patient_status']==='active'?'checked':''?>> Aktif</label><label><input type="radio" name="patient_status" value="deceased" <?=$patient['patient_status']==='deceased'?'checked':''?>> Vefat</label></div></div>
<h3 class="form-section-title">Hizmet Bilgileri</h3>
<div class="icon-form-row"><label class="icon-form-label">Sosyal Güvence</label><div class="merged-input"><span class="merged-icon">◇</span><input name="social_security" value="<?=e($patient['social_security'])?>"></div></div>
<div class="icon-form-row"><label class="icon-form-label">Rapor</label><div class="merged-input"><span class="merged-icon">✓</span><select name="report_status"><option value="">Seçiniz</option><?php foreach(REPORT_STATUSES as $reportStatus):?><option value="<?=e($reportStatus)?>" <?=$patient['report_status']===$reportStatus?'selected':''?>><?=e($reportStatus)?></option><?php endforeach?></select></div></div>
<h3 class="form-section-title">Başvuru ve Açıklamalar</h3>
<div class="icon-form-row"><label class="icon-form-label">Kaynak</label><div class="merged-input"><span class="merged-icon">◉</span><select name="source_id"><option value="">Seçiniz</option><?php foreach(patient_source_options($sourceDefinitions, (int)($patient['source_id'] ?? 0)) as $source):if(mb_strtolower(trim((string)$source['name']),'UTF-8')==='pazarlama')continue;$isCurrent=(int)$patient['source_id']===(int)$source['id'];$legacySource=in_array(trim($source['name']),['Kurumlar & Firmalar','Kurumlar ve Firmalar'],true);if($legacySource&&!$isCurrent)continue;if(!(int)$source['active']&&!$isCurrent)continue;?><option value="<?=(int)$source['id']?>" <?=$legacySource?'hidden':''?> <?=$isCurrent?'selected':''?>><?=e($source['name'])?><?=!(int)$source['active']?' (Pasif)':''?></option><?php endforeach?></select></div></div>
<div class="icon-form-row source-account-row" hidden><label class="icon-form-label" for="source-account">Kurum</label><div class="merged-input"><span class="merged-icon">◉</span><select id="source-account" name="source_account_id"><option value="">Seçiniz</option><?php foreach($sourceAccounts as $account): ?><option data-account-type="<?=e($account['account_type'])?>" value="<?=(int)$account['id']?>" <?=((int)($patient['source_account_id']??0)===(int)$account['id'])?'selected':''?>><?=e(trim((string)($account['short_name']??''))!=='' ? trim((string)$account['short_name']) : $account['title'])?></option><?php endforeach; ?></select></div></div>
<div class="icon-form-row source-referral-row" hidden><label class="icon-form-label" for="source-referral-detail">Tanıdık / Tavsiye Detayı</label><div class="merged-input"><span class="merged-icon">⋯</span><input type="text" id="source-referral-detail" name="source_referral_detail" placeholder="Tanıdık veya tavsiye detayını yazınız" value="<?=e($patient['source_referral_detail']??'')?>"></div></div>
<div class="icon-form-row source-unit-row" hidden><label class="icon-form-label">Kaynak Ünitesi</label><div class="merged-input"><span class="merged-icon">◉</span><select name="source_unit_id"><option value="">Ad Soyad seçiniz</option><?php foreach($sourceUnits as $unit):?><option value="<?=(int)$unit['id']?>" <?=((int)($patient['source_unit_id']??0)===(int)$unit['id'])?'selected':''?>><?=e(trim((string)$unit['name'].' '.(string)($unit['last_name']??'')))?></option><?php endforeach?></select></div></div>
<div class="icon-form-row"><label class="icon-form-label" for="opening-employee">İlgilenen kişi</label><div class="merged-input"><span class="merged-icon">♙</span><select id="opening-employee" name="opening_employee_id" title="Hasta kartı açılırken hastayla ilgilenen çalışan"><option value="">Çalışan seçiniz</option><?php if (!empty($patient['opening_employee_id']) && !in_array((int)$patient['opening_employee_id'], array_map('intval', array_column($openingEmployees, 'id')), true)): ?><option hidden selected value="<?=(int)$patient['opening_employee_id']?>">Mevcut atama korunuyor</option><?php endif; ?><?php foreach ($openingEmployees as $employee): ?><option value="<?=(int)$employee['id']?>" <?=((int)($patient['opening_employee_id']??0)===(int)$employee['id'])?'selected':''?>><?=e($employee['full_name'])?></option><?php endforeach; ?></select></div></div>
<div class="icon-form-row"><label class="icon-form-label">Başvuru Detayı</label><div class="merged-input"><span class="merged-icon">⋯</span><select name="source_detail">
<option value="">Seçiniz</option>
<?php $sourceDetailOptions = ['Deneme', 'Test', 'Bilgi', 'Servis']; $currentSourceDetail = (string)($patient['source_detail'] ?? ''); ?>
<?php if ($currentSourceDetail !== '' && !in_array($currentSourceDetail, $sourceDetailOptions, true)): ?>
<option value="<?=e($currentSourceDetail)?>" selected><?=e($currentSourceDetail)?></option>
<?php endif; ?>
<?php foreach ($sourceDetailOptions as $sourceDetailOption): ?>
<option value="<?=e($sourceDetailOption)?>" <?=$currentSourceDetail === $sourceDetailOption ? 'selected' : ''?>><?=e($sourceDetailOption)?></option>
<?php endforeach; ?>
</select></div></div>
<div class="icon-form-row"><label class="icon-form-label">Açıklama</label><div class="merged-input"><span class="merged-icon">▱</span><textarea name="notes"><?=e($patient['notes'])?></textarea></div></div>
<div class="vuexy-form-actions"><?php if($id):?><a class="patient-services-button" href="<?=e(url('patient-followup.php?id='.$id.'&from_patient_card=1'))?>" title="Hizmetler" aria-label="Hizmetler"><i class="icon-base ti tabler-heart-handshake" aria-hidden="true"></i><span>Hizmetler</span></a><?php endif?><button class="button">Kaydet</button><a class="cancel-link" href="<?=e(url($returnTo))?>">İptal</a></div></form></section></main>
<script>
(()=>{
  const form=document.querySelector('.vuexy-icon-form');
  if(!form||form.dataset.classicReady==='1')return;
  form.dataset.classicReady='1';
  form.classList.add('classic-patient-form');
  form.querySelectorAll(':scope > .form-section-title').forEach(title=>title.remove());
  const actions=form.querySelector('.vuexy-form-actions');
  const rowFor=name=>form.querySelector(`[name="${name}"]`)?.closest('.icon-form-row');
  const makeSection=(title,icon,className,fields)=>{
    const section=document.createElement('section');
    section.className='classic-patient-section';
    const heading=document.createElement('h3');
    heading.className='classic-patient-section-title';
    heading.innerHTML=`<i aria-hidden="true">${icon}</i>${title}`;
    const grid=document.createElement('div');
    grid.className=`classic-patient-grid ${className}`;
    fields.forEach(([name,fieldClass])=>{
      const row=rowFor(name);
      if(!row)return;
      row.classList.add('classic-field',fieldClass||`field-${name.replaceAll('_','-')}`);
      grid.append(row);
    });
    section.append(heading,grid);
    form.insertBefore(section,actions);
  };
  makeSection('Temel Bilgiler','♟','classic-basic-grid',[
    ['full_name','field-name'],['national_id','field-national'],['record_date','field-record-date'],['opening_employee_id','field-opening-employee'],['branch_id','field-branch'],
    ['birth_date','field-birth-date'],['phone_primary','field-phone-primary'],['proximity_relation','field-proximity'],['phone_secondary','field-phone-secondary'],['proximity_relation_secondary','field-secondary-proximity'],
    ['address','field-address'],['patient_rating','field-rating'],['patient_rating_comment','field-comment'],['patient_status','field-status'],

  ]);
  makeSection('Hizmet Bilgileri','▪','classic-service-grid',[
    ['social_security','field-social-security'],['report_status','field-report-status']
  ]);
  makeSection('Başvuru ve Açıklamalar','▦','classic-application-grid',[
    ['source_id','field-source'],['source_detail','field-source-detail'],['source_account_id','field-source-account'],['source_referral_detail','field-source-referral'],['source_unit_id','field-source-unit'],['notes','field-notes']
  ]);
  const statusLabel=rowFor('patient_status')?.querySelector('.icon-form-label');
  if(statusLabel)statusLabel.textContent='Hasta Durumu';
  const national=document.querySelector('input[name="national_id"]');
  if(national)national.placeholder='11 haneli T.C. Kimlik No';
  const primary=document.querySelector('input[name="phone_primary"]');
  if(primary)primary.placeholder='05xx xxx xx xx';
  const address=document.querySelector('textarea[name="address"]');
  if(address)address.placeholder='Adres bilgilerini giriniz';
  const comment=document.querySelector('textarea[name="patient_rating_comment"]');
  if(comment)comment.placeholder='Yorum giriniz';
  const notes=document.querySelector('textarea[name="notes"]');
  if(notes)notes.placeholder='Açıklama giriniz';
  const save=actions?.querySelector('button.button');
  if(save){save.type='submit';save.textContent='▣ Kaydet (F2)';save.setAttribute('aria-label','Kaydet');}
  if(actions){const note=document.createElement('span');note.className='classic-form-note';note.innerHTML='Zorunlu alanlar <span class="required-mark">*</span> ile gösterilmiştir.';actions.prepend(note);}
  document.addEventListener('keydown',event=>{if(event.key==='F2'){event.preventDefault();save?.click();}});
})();
(()=>{
  const servicesLink=document.querySelector('.patient-services-button');
  if(!servicesLink)return;
  const windowTitle=<?=json_encode('Hizmetler - ' . (string)($patient['full_name'] ?? ''),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  servicesLink.addEventListener('click',event=>{
    const targetUrl=servicesLink.href;
    if(window.parent!==window){
      event.preventDefault();
      event.stopPropagation();
      window.parent.postMessage({type:'vox-open-window',url:targetUrl,title:windowTitle},location.origin);
      return;
    }
    if(typeof window.voxOpenWindow==='function'){
      event.preventDefault();
      event.stopPropagation();
      window.voxOpenWindow(targetUrl,windowTitle);
    }
  });
})();
(()=>{
  const icons={
    branch_id:'tabler-building',record_date:'tabler-calendar',full_name:'tabler-user',national_id:'tabler-id-badge',
    birth_date:'tabler-cake',phone_primary:'tabler-phone',phone_secondary:'tabler-phone',address:'tabler-map-pin',
    patient_rating_comment:'tabler-message',social_security:'tabler-shield-check',report_info:'tabler-file-description',
    report_status:'tabler-file-check',
    source_id:'tabler-speakerphone',source_unit_id:'tabler-building',source_detail:'tabler-list-details',
    notes:'tabler-note'
  };
  Object.entries(icons).forEach(([name,icon])=>{
    const field=document.querySelector(`[name="${name}"]`);
    const holder=field?.closest('.merged-input');
    const iconSlot=holder?.querySelector('.merged-icon');
    if(iconSlot) iconSlot.innerHTML=`<i class="icon-base ti ${icon}" aria-hidden="true"></i>`;
  });
})();
(()=>{const input=document.querySelector('input[name="social_security"]');if(!input)return;const select=document.createElement('select');select.name='social_security';select.innerHTML='<option value="">Seçiniz</option>'+<?=json_encode(array_map(fn($item)=>['name'=>$item['name']],$socialSecurityOptions),JSON_UNESCAPED_UNICODE)?>.map(item=>'<option value="'+item.name.replace(/"/g,'&quot;')+'">'+item.name+'</option>').join('');select.value=input.value;input.replaceWith(select)})();
(()=>{
  const stars=[...document.querySelectorAll('.patient-rating input')];
  if(!stars.length)return;
  const paint=value=>document.querySelectorAll('.patient-rating label').forEach((star,index)=>star.classList.toggle('is-selected',index<value));
  stars.forEach(star=>{
    star.addEventListener('change',()=>paint(Number(star.value)));
    document.querySelector('label[for="'+star.id+'"]').addEventListener('click',()=>{
      star.checked=true;
      paint(Number(star.value));
    });
  });
})();
(()=>{
  const format=value=>{
    let digits=value.replace(/\D/g,'');
    if(digits.length>11)return value.trim();
    if(digits.length===13&&digits.startsWith('90')&&digits.charAt(2)==='0')digits=digits.slice(2);
    else if(digits.length===12&&digits.startsWith('90'))digits='0'+digits.slice(2);
    else if(digits.length===10&&digits.startsWith('5'))digits='0'+digits;
    digits=digits.slice(0,11);
    return [digits.slice(0,4),digits.slice(4,7),digits.slice(7,9),digits.slice(9,11)].filter(Boolean).join(' ');
  };
  const validate=input=>{
    const digitCount=input.value.replace(/\D/g,'').length;
    const valid=input.value===''||digitCount>11||/^0\d{3} \d{3} \d{2} \d{2}$/.test(input.value);
    input.setCustomValidity(valid?'':'Telefon numarasını 0546 638 67 75 biçiminde girin.');
    return valid;
  };
  document.querySelectorAll('#phone_primary').forEach(input=>{
    input.value=format(input.value);
    input.addEventListener('input',()=>{input.value=format(input.value);validate(input);});
    input.addEventListener('blur',()=>{if(!validate(input))input.reportValidity();});
  });
  document.querySelector('.vuexy-icon-form').addEventListener('submit',event=>{
    const invalid=[...document.querySelectorAll('#phone_primary')].some(input=>!validate(input));
    if(invalid){event.preventDefault();document.querySelector('#phone_primary:invalid').reportValidity();}
  });
})();
(()=>{const phone=document.getElementById('phone_primary'),toggle=document.getElementById('proximity-toggle'),row=document.getElementById('proximity-row'),relation=document.getElementById('proximity_relation');if(!phone||!toggle||!row||!relation)return;const refresh=()=>{const available=phone.value.trim()!=='';toggle.disabled=!available;if(!available){row.classList.add('is-hidden');relation.value='';toggle.setAttribute('aria-expanded','false');}else{row.classList.remove('is-hidden');toggle.setAttribute('aria-expanded','true');}};toggle.addEventListener('click',()=>{const hidden=row.classList.toggle('is-hidden');toggle.setAttribute('aria-expanded',String(!hidden));if(!hidden)relation.focus()});phone.addEventListener('input',refresh);refresh()})();
(()=>{const phone=document.getElementById('phone_secondary'),toggle=document.getElementById('proximity-secondary-toggle'),row=document.getElementById('proximity-secondary-row'),relation=document.getElementById('proximity_relation_secondary');if(!phone||!toggle||!row||!relation)return;const refresh=()=>{const available=phone.value.trim()!=='';toggle.disabled=!available;if(!available){row.classList.add('is-hidden');relation.value='';toggle.setAttribute('aria-expanded','false');}else{row.classList.remove('is-hidden');toggle.setAttribute('aria-expanded','true');}};toggle.addEventListener('click',()=>{const hidden=row.classList.toggle('is-hidden');toggle.setAttribute('aria-expanded',String(!hidden));if(!hidden)relation.focus()});phone.addEventListener('input',refresh);refresh()})();
(() => {
  const source = document.querySelector('select[name="source_id"]');
  const row = document.querySelector('.source-referral-row');
  if (!source || !row) return;
  const refresh = () => {
    const name = (source.options[source.selectedIndex]?.textContent || '').replace(/\s*\(Pasif\)$/, '').trim().toLocaleLowerCase('tr-TR');
    row.hidden = !['tanıdık', 'tavsiye'].includes(name);
    const detailLabel = name === 'tanıdık' ? 'Tanıdık detayı' : 'Tavsiye detayı';
    row.querySelector('label').textContent = detailLabel;
    row.querySelector('input').placeholder = detailLabel + ' yazınız';
  };
  source.addEventListener('change', refresh);
  refresh();
})();
(() => {
  const source = document.querySelector('select[name="source_id"]');
  const row = document.querySelector('.source-account-row');
  const select = row?.querySelector('select');
  if (!source || !select) return;
  const options = [...select.options].slice(1).map(option => option.cloneNode(true));
  const refresh = () => {
    const name = (source.selectedOptions[0]?.textContent || '').replace(/\s*\(Pasif\)$/, '').trim();
    const type = {Kurumlar:'institution', Firmalar:'customer'}[name];
    const value = select.value;
    row.hidden = !type;
    row.querySelector('label').textContent = name === 'Kurumlar' ? 'Kurum' : 'Firma';
    select.replaceChildren(new Option('Seçiniz', ''), ...options.filter(option => option.dataset.accountType === type).map(option => option.cloneNode(true)));
    select.value = [...select.options].some(option => option.value === value) ? value : '';
  };
  source.addEventListener('change', refresh);
  refresh();
})();
(()=>{const source=document.querySelector('select[name="source_id"]'),row=document.querySelector('.source-unit-row'),units=document.querySelector('select[name="source_unit_id"]');if(!source||!row||!units)return;const refresh=()=>{const label=(source.options[source.selectedIndex]?.textContent||'').trim();const show=/^(kaynak\s*)?ünite$/i.test(label);row.hidden=!show;if(!show)units.value='';};source.addEventListener('change',refresh);refresh()})();
(()=>{const input=document.getElementById('patient-full-name'),display=document.querySelector('.patient-name-display');if(!input||!display)return;const edit=()=>{input.focus();input.setSelectionRange(input.value.length,input.value.length)};display.addEventListener('click',edit);display.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();edit()}})})();
</script>
<script src="<?=url('assets/patient-fields.js?v=1')?>" defer></script>
<script src="<?=url('assets/patient-identity.js?v=5')?>" defer></script>
<?php patient_footer();
