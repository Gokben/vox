<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/patient-layout.php';

$pdo = db();
$sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
$pdo->exec($sqlite
    ? 'CREATE TABLE IF NOT EXISTS companies (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, record_date TEXT, company_name TEXT NOT NULL, short_name TEXT, department TEXT, company_type TEXT, phone1 TEXT, phone2 TEXT, email TEXT, tax_no TEXT, tax_office TEXT, billing_address TEXT, city TEXT, district TEXT, address TEXT, related_cards TEXT, note TEXT)'
    : 'CREATE TABLE IF NOT EXISTS companies (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(50) NOT NULL UNIQUE, record_date DATE NULL, company_name VARCHAR(190) NOT NULL, short_name VARCHAR(190) NULL, department VARCHAR(150) NULL, company_type VARCHAR(150) NULL, phone1 VARCHAR(50) NULL, phone2 VARCHAR(50) NULL, email VARCHAR(190) NULL, tax_no VARCHAR(50) NULL, tax_office VARCHAR(150) NULL, billing_address TEXT NULL, city VARCHAR(100) NULL, district VARCHAR(100) NULL, address TEXT NULL, related_cards TEXT NULL, note TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$fields = ['record_date','company_name','short_name','department','company_type','phone1','phone2','email','tax_no','tax_office','billing_address','city','district','address','related_cards','note'];
$editId = (int)($_GET['edit'] ?? 0);
$showForm = $editId > 0 || isset($_GET['new']);
$company = array_fill_keys($fields, '');
$company['record_date'] = date('Y-m-d');
$error = '';

if ($editId) {
    $statement = $pdo->prepare('SELECT * FROM companies WHERE id=?');
    $statement->execute([$editId]);
    $found = $statement->fetch();
    if (!$found) { http_response_code(404); exit('Kurum/Firma kaydı bulunamadı.'); }
    $company = array_merge($company, $found);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'save');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($action === 'delete') { $pdo->prepare('DELETE FROM companies WHERE id=?')->execute([$id]); redirect('companies.php'); }
        foreach ($fields as $field) $company[$field] = trim((string)($_POST[$field] ?? ''));
        if ($company['company_name'] === '') throw new RuntimeException('Firma adı zorunludur.');
        if ($action === 'update') {
            $set = implode(',', array_map(static fn(string $field): string => $field . '=?', $fields));
            $pdo->prepare('UPDATE companies SET ' . $set . ' WHERE id=?')->execute([...array_map(static fn(string $field): mixed => $company[$field], $fields), $id]);
            redirect('companies.php?edit=' . $id . '&saved=1');
        }
        do { $code = 'F' . date('ymd') . random_int(100, 999); $check=$pdo->prepare('SELECT 1 FROM companies WHERE code=?'); $check->execute([$code]); } while ($check->fetchColumn());
        $columns = array_merge(['code'], $fields);
        $pdo->prepare('INSERT INTO companies (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')')->execute([$code, ...array_map(static fn(string $field): mixed => $company[$field], $fields)]);
        redirect('companies.php?saved=1');
    } catch (RuntimeException $exception) { $error = $exception->getMessage(); }
    catch (Throwable $exception) { error_log('companies.php: ' . $exception->getMessage()); $error = 'Kayıt işlemi tamamlanamadı.'; }
}
$message = isset($_GET['saved']) ? 'Kayıt kaydedildi.' : '';
$companies = $pdo->query('SELECT * FROM companies ORDER BY company_name')->fetchAll();
patient_header($editId ? 'Kurum/Firma Düzenle' : ($showForm ? 'Yeni Kurum/Firma' : 'Kurumlar & Firmalar'), 'cash');
?>
<style>
.company-page{width:100%!important;max-width:1100px!important;margin-left:auto!important;margin-right:auto!important;padding:96px 20px 48px!important}.company-card{background:var(--card);border:1px solid var(--line);border-radius:8px;box-shadow:0 .25rem 1.125rem rgba(47,43,61,.1);overflow:hidden}.company-head,.company-list-head{display:flex;align-items:center;justify-content:space-between;min-height:70px;padding:0 24px;border-bottom:1px solid var(--line)}.company-head h2,.company-list-head h2{margin:0;font-size:20px;font-weight:500}.company-list{width:calc(100% - 64px);margin:28px 32px 48px;border:1px solid var(--line);border-radius:8px;background:var(--card);overflow:hidden}.company-list table{width:100%;border-collapse:collapse}.company-list th,.company-list td{padding:13px 18px;border-bottom:1px solid var(--line);text-align:left}.company-list th{font-size:12px;color:var(--muted)}.company-actions{display:flex;gap:8px}.company-actions a,.company-actions button{display:inline-grid;place-items:center;width:36px;height:36px;border:0;border-radius:6px;background:#20a447;color:#fff;cursor:pointer}.company-actions button{background:#e04f55}.company-form{padding:24px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 24px}.company-field{display:flex;flex-direction:column;gap:7px;color:var(--text);font-size:14px}.company-field input,.company-field textarea{width:100%;min-height:40px;padding:9px 12px;border:1px solid #d5d3de;border-radius:6px;background:var(--card);color:var(--text);font:inherit}.company-field textarea{min-height:82px;resize:vertical}.company-wide{grid-column:1/-1}.company-actions-row{grid-column:1/-1;display:flex;gap:12px;margin-top:4px}.company-alert{margin:18px 24px 0;padding:12px;border-radius:6px;background:#fde8e8;color:#a62c2c}.company-empty{text-align:center;color:var(--muted)}@media(max-width:720px){.company-page{padding:92px 12px 30px!important}.company-list{width:auto;margin:20px 12px 30px;overflow:auto}.company-list table{min-width:600px}.company-form{grid-template-columns:1fr;padding:18px}.company-wide,.company-actions-row{grid-column:auto}.company-head,.company-list-head{padding:0 16px}}
.company-form{display:block!important;padding:10px 24px 24px!important}.company-field{display:grid!important;grid-template-columns:150px minmax(0,1fr)!important;align-items:start!important;gap:0!important;margin:14px 0!important}.company-field input,.company-field textarea{grid-column:2!important;box-sizing:border-box!important;width:100%!important;min-height:40px!important;margin:0!important;padding:9px 12px!important;border:1px solid #d5d3de!important;border-radius:6px!important;background:var(--card)!important}.company-field textarea{min-height:82px!important}.company-wide{grid-column:auto!important}.company-actions-row{display:flex!important;margin:22px 0 0 150px!important}.company-form::before{content:'Temel Bilgiler';display:block;margin:16px 0 8px;padding-bottom:10px;border-bottom:1px solid var(--line);font-size:14px;font-weight:600;color:#20a447}@media(max-width:720px){.company-form{padding:10px 16px 22px!important}.company-field{grid-template-columns:1fr!important;gap:7px!important}.company-field input,.company-field textarea{grid-column:1!important}.company-actions-row{margin-left:0!important}}
</style>
<?php if ($showForm): ?><main class="patient-container company-page"><section class="company-card"><div class="company-head"><h2><?=$editId ? 'Kurum/Firma Düzenle' : 'Yeni Kurum/Firma'?></h2><a class="cancel-link" href="<?=e(url('companies.php'))?>">Listeye dön</a></div><?php if($error):?><div class="company-alert"><?=e($error)?></div><?php endif?><form method="post" class="company-form"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="<?=$editId?'update':'save'?>"><?php if($editId):?><input type="hidden" name="id" value="<?=$editId?>"><?php endif?><?php function company_field(string $label,string $name,array $company,string $type='text',bool $wide=false,bool $required=false):void {?><label class="company-field <?=$wide?'company-wide':''?>"><?=e($label)?><?=$required?' *':''?><?php if($type==='textarea'):?><textarea name="<?=e($name)?>"><?=e((string)$company[$name])?></textarea><?php else:?><input type="<?=e($type)?>" name="<?=e($name)?>" value="<?=e((string)$company[$name])?>" <?=$required?'required':''?>><?php endif?></label><?php } ?><?php company_field('Kayıt Tarihi','record_date',$company,'date');company_field('Firma Adı','company_name',$company,'text',false,true);company_field('Kısa Ad','short_name',$company);company_field('Departman','department',$company);company_field('Firma Türü','company_type',$company);company_field('Telefon 1','phone1',$company,'tel');company_field('Telefon 2','phone2',$company,'tel');company_field('E-posta','email',$company,'email');company_field('Vergi / T.C. No','tax_no',$company);company_field('Vergi Dairesi','tax_office',$company);company_field('İl','city',$company);company_field('İlçe','district',$company);company_field('Fatura Adresi','billing_address',$company,'textarea',true);company_field('Adres','address',$company,'textarea',true);company_field('İlişkili Kartlar','related_cards',$company,'text',true);company_field('Not','note',$company,'textarea',true);?><div class="company-actions-row"><button class="button">Kaydet</button><a class="cancel-link" href="<?=e(url('companies.php'))?>">İptal</a></div></form></section></main><?php endif; ?>
<?php if (!$showForm): ?><main class="patient-container company-page"><?php if($message):?><p style="color:#16883d"><?=e($message)?></p><?php endif?><section class="company-list"><div class="company-list-head"><h2>Firma Listesi</h2><a class="button" href="<?=e(url('companies.php?new=1'))?>">+ Yeni Kurum/Firma</a></div><table><thead><tr><th>KAYIT NO</th><th>FİRMA</th><th>TELEFON</th><th>İŞLEMLER</th></tr></thead><tbody><?php foreach($companies as $row):?><tr><td><?=e($row['code'])?></td><td><?=e($row['company_name'])?></td><td><?=e($row['phone1'])?></td><td><div class="company-actions"><a href="<?=e(url('companies.php?edit='.(int)$row['id']))?>" title="Düzenle"><i class="icon-base ti tabler-edit"></i></a><form method="post" onsubmit="return confirm('Bu kurum/firma silinsin mi?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$row['id']?>"><button title="Sil"><i class="icon-base ti tabler-trash"></i></button></form></div></td></tr><?php endforeach;if(!$companies):?><tr><td colspan="4" class="company-empty">Henüz kurum/firma bulunmuyor.</td></tr><?php endif?></tbody></table></section></main><?php endif; ?>
<script>
(()=>{
  const format=value=>{
    let digits=String(value).replace(/\D/g,'');
    if(digits.length===13&&digits.startsWith('90')&&digits.charAt(2)==='0')digits=digits.slice(2);
    else if(digits.length===12&&digits.startsWith('90'))digits='0'+digits.slice(2);
    else if(digits.length===10&&digits.startsWith('5'))digits='0'+digits;
    digits=digits.slice(0,11);
    return [digits.slice(0,4),digits.slice(4,7),digits.slice(7,9),digits.slice(9,11)].filter(Boolean).join(' ');
  };
  document.querySelectorAll('input[name="phone1"],input[name="phone2"]').forEach(input=>{
    input.inputMode='tel'; input.maxLength=14; input.placeholder='0546 638 67 75'; input.value=format(input.value);
    input.addEventListener('input',()=>{ input.value=format(input.value); });
  });
})();
</script>
<?php patient_footer(); ?>
