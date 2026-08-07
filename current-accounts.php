<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/patient-layout.php';

function format_phone_tr(?string $phone): string
{
    $digits = preg_replace('/\D+/', '', (string)$phone);
    if (str_starts_with($digits, '90') && strlen($digits) === 12) $digits = '0' . substr($digits, 2);
    if (strlen($digits) === 10 && str_starts_with($digits, '5')) $digits = '0' . $digits;
    if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        return sprintf('%s %s %s %s', substr($digits, 0, 4), substr($digits, 4, 3), substr($digits, 7, 2), substr($digits, 9, 2));
    }
    return trim((string)$phone);
}

function next_current_account_code(PDO $pdo): string
{
    $lastCode = (string)$pdo->query('SELECT code FROM current_accounts ORDER BY id DESC LIMIT 1')->fetchColumn();
    if (!preg_match('/^(.*?)(\d+)$/u', $lastCode, $matches)) return 'CR-01';

    return $matches[1] . str_pad((string)((int)$matches[2] + 1), max(2, strlen($matches[2])), '0', STR_PAD_LEFT);
}

$pdo = db();
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $pdo->exec('CREATE TABLE IF NOT EXISTS current_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, title TEXT NOT NULL, tax_office TEXT, tax_number TEXT, account_type TEXT NOT NULL, currency TEXT NOT NULL DEFAULT "TRY", phone TEXT, email TEXT, contact_person TEXT, billing_address TEXT, shipping_address TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
} else {
    $pdo->exec('CREATE TABLE IF NOT EXISTS current_accounts (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(50) NOT NULL UNIQUE, title VARCHAR(190) NOT NULL, tax_office VARCHAR(150) NULL, tax_number VARCHAR(32) NULL, account_type ENUM("customer","supplier","both") NOT NULL, currency VARCHAR(3) NOT NULL DEFAULT "TRY", phone VARCHAR(50) NULL, email VARCHAR(190) NULL, contact_person VARCHAR(150) NULL, billing_address TEXT NULL, shipping_address TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
$hasWebsite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
    ? (bool)array_filter($pdo->query('PRAGMA table_info(current_accounts)')->fetchAll(), static fn($column) => $column['name'] === 'website')
    : (function () use ($pdo): bool { $statement = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="current_accounts" AND column_name="website"'); $statement->execute(); return (bool)$statement->fetchColumn(); })();
if (!$hasWebsite) $pdo->exec('ALTER TABLE current_accounts ADD COLUMN website VARCHAR(190) NULL');
$hasTechnicalService = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
    ? (bool)array_filter($pdo->query('PRAGMA table_info(current_accounts)')->fetchAll(), static fn($column) => $column['name'] === 'technical_service')
    : (function () use ($pdo): bool { $statement = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="current_accounts" AND column_name="technical_service"'); $statement->execute(); return (bool)$statement->fetchColumn(); })();
if (!$hasTechnicalService) $pdo->exec('ALTER TABLE current_accounts ADD COLUMN technical_service TINYINT(1) NOT NULL DEFAULT 0');
$hasTechnicalServiceType = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
    ? (bool)array_filter($pdo->query('PRAGMA table_info(current_accounts)')->fetchAll(), static fn($column) => $column['name'] === 'technical_service_type')
    : (function () use ($pdo): bool { $statement = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="current_accounts" AND column_name="technical_service_type"'); $statement->execute(); return (bool)$statement->fetchColumn(); })();
if (!$hasTechnicalServiceType) $pdo->exec('ALTER TABLE current_accounts ADD COLUMN technical_service_type VARCHAR(30) NULL');
elseif ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
    $lengthStatement = $pdo->prepare('SELECT character_maximum_length FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="current_accounts" AND column_name="technical_service_type"');
    $lengthStatement->execute();
    if ((int)$lengthStatement->fetchColumn() < 30) $pdo->exec('ALTER TABLE current_accounts MODIFY technical_service_type VARCHAR(30) NULL');
}
$hasShortName = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
    ? (bool)array_filter($pdo->query('PRAGMA table_info(current_accounts)')->fetchAll(), static fn($column) => $column['name'] === 'short_name')
    : (function () use ($pdo): bool { $statement = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="current_accounts" AND column_name="short_name"'); $statement->execute(); return (bool)$statement->fetchColumn(); })();
if (!$hasShortName) $pdo->exec('ALTER TABLE current_accounts ADD COLUMN short_name VARCHAR(190) NULL');
$message = '';
$error = '';
$editId = (int)($_GET['edit'] ?? 0);
$editingAccount = null;
if ($editId > 0) {
    $statement = $pdo->prepare('SELECT * FROM current_accounts WHERE id=?');
    $statement->execute([$editId]);
    $editingAccount = $statement->fetch() ?: null;
}
$displayAccountCode = $editingAccount['code'] ?? next_current_account_code($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM current_accounts WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
        header('Location: ' . url('current-accounts.php?deleted=1'));
        exit;
    }
    $data = [
        'code' => trim((string)($_POST['code'] ?? '')), 'title' => trim((string)($_POST['title'] ?? '')), 'short_name' => trim((string)($_POST['short_name'] ?? '')),
        'tax_office' => trim((string)($_POST['tax_office'] ?? '')), 'tax_number' => trim((string)($_POST['tax_number'] ?? '')),
        'account_type' => (string)($_POST['account_type'] ?? ''), 'currency' => (string)($_POST['currency'] ?? 'TRY'),
        'phone' => trim((string)($_POST['phone'] ?? '')), 'email' => trim((string)($_POST['email'] ?? '')), 'technical_service_type' => '', 'technical_service' => 0,
        'contact_person' => trim((string)($_POST['contact_person'] ?? '')), 'billing_address' => trim((string)($_POST['billing_address'] ?? '')),
        'shipping_address' => trim((string)($_POST['shipping_address'] ?? '')),
    ];
    $technicalServiceTypes = array_values(array_unique(array_filter(array_map('strval', (array)($_POST['technical_service_types'] ?? [])), static fn(string $type): bool => in_array($type, ['inside', 'outside'], true))));
    $data['technical_service_type'] = implode(',', $technicalServiceTypes);
    $data['technical_service'] = $data['technical_service_type'] === '' ? 0 : 1;
    $data['phone'] = format_phone_tr($data['phone']);
    if ($action === 'update') {
        $accountId = (int)($_POST['id'] ?? 0);
        $codeStatement = $pdo->prepare('SELECT code FROM current_accounts WHERE id=?');
        $codeStatement->execute([$accountId]);
        $data['code'] = (string)$codeStatement->fetchColumn();
        if ($data['code'] === '' || $data['title'] === '' || !in_array($data['account_type'], ['customer','supplier','both'], true)) {
            header('Location: ' . url('current-accounts.php?edit=' . (int)($_POST['id'] ?? 0)));
            exit;
        }
        $data[] = $accountId;
        $pdo->prepare('UPDATE current_accounts SET code=?,title=?,short_name=?,tax_office=?,tax_number=?,account_type=?,currency=?,phone=?,email=?,technical_service_type=?,technical_service=?,contact_person=?,billing_address=?,shipping_address=? WHERE id=?')->execute(array_values($data));
        header('Location: ' . url('current-accounts.php?updated=1'));
        exit;
    }
    try {
        $data['code'] = next_current_account_code($pdo);
        if ($data['code'] === '' || $data['title'] === '' || !in_array($data['account_type'], ['customer','supplier','both'], true)) throw new RuntimeException('Cari kodu, cari unvanı ve cari tipi zorunludur.');
        if (!in_array($data['currency'], ['TRY','USD','EUR'], true)) throw new RuntimeException('Geçerli bir para birimi seçin.');
        $pdo->prepare('INSERT INTO current_accounts(code,title,short_name,tax_office,tax_number,account_type,currency,phone,email,technical_service_type,technical_service,contact_person,billing_address,shipping_address) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_values($data));
        $message = 'Cari kart kaydedildi.';
    } catch (PDOException $exception) { $error = 'Bu cari kodu zaten kullanılıyor.'; }
      catch (RuntimeException $exception) { $error = $exception->getMessage(); }
}
$accounts = $pdo->query('SELECT * FROM current_accounts ORDER BY id ASC')->fetchAll();
if (isset($_GET['updated'])) $message = 'Cari kart güncellendi.';
if (isset($_GET['deleted'])) $message = 'Cari kart silindi.';
patient_header('Cari Kartlar', 'cash');
?>
<main class="patient-container account-page"><div class="account-head"><h1>Cari Kartlar</h1><p>Müşteri ve tedarikçi bilgilerini yönetin.</p></div>
<?php if ($message): ?><div class="notice success"><?=e($message)?></div><?php endif ?><?php if ($error): ?><div class="notice error"><?=e($error)?></div><?php endif ?>
<section class="account-card"><header class="list-head"><h2>Cari Kart Listesi</h2><details class="new-account-card" <?=$editingAccount ? 'open' : ''?>><summary><?=$editingAccount ? 'Cari Kartı Düzenle' : 'Yeni Cari Kart'?> <i class="ti tabler-plus"></i></summary><form class="account-form" method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="<?=$editingAccount ? 'update' : 'save'?>"><?php if ($editingAccount): ?><input type="hidden" name="id" value="<?=(int)$editingAccount['id']?>"><?php endif ?><section><h2>Temel ve Kimlik Bilgileri</h2><div class="grid"><label>Cari Kodu<input name="code" maxlength="50" required placeholder="Örn. CR-0001" value="<?=e($editingAccount['code'] ?? '')?>"></label><label>Cari Unvanı / Adı<input name="title" maxlength="190" required value="<?=e($editingAccount['title'] ?? '')?>"></label><label>Vergi Dairesi<input name="tax_office" maxlength="150" value="<?=e($editingAccount['tax_office'] ?? '')?>"></label><label>Vergi No / T.C. Kimlik No<input name="tax_number" maxlength="32" value="<?=e($editingAccount['tax_number'] ?? '')?>"></label><label>Cari Tipi<select name="account_type" required><option value="customer" <?=($editingAccount['account_type'] ?? 'customer') === 'customer' ? 'selected' : ''?>>Müşteri</option><option value="supplier" <?=($editingAccount['account_type'] ?? '') === 'supplier' ? 'selected' : ''?>>Tedarikçi</option><option value="both" <?=($editingAccount['account_type'] ?? '') === 'both' ? 'selected' : ''?>>Her İkisi</option></select></label></div></section><section><h2>İletişim ve Adres Bilgileri</h2><div class="grid"><label>Telefon<input name="phone" maxlength="50" value="<?=e($editingAccount['phone'] ?? '')?>"></label><label>E-posta<input type="email" name="email" maxlength="190" value="<?=e($editingAccount['email'] ?? '')?>"></label><label>Yetkili Kişi<input name="contact_person" maxlength="150" value="<?=e($editingAccount['contact_person'] ?? '')?>"></label><label>Fatura Adresi<textarea name="billing_address"><?=e($editingAccount['billing_address'] ?? '')?></textarea></label><label>Sevk Adresi<textarea name="shipping_address"><?=e($editingAccount['shipping_address'] ?? '')?></textarea></label></div></section><button class="save" title="Kaydet" aria-label="Kaydet"><i class="ti tabler-device-floppy"></i></button></form></details></header><div class="table-wrap"><table><thead><tr><th>KOD</th><th>UNVAN</th><th>TİP</th><th>TELEFON</th><th>İŞLEMLER</th></tr></thead><tbody><?php foreach ($accounts as $account): ?><tr><td><?=e($account['code'])?></td><td><?=e($account['title'])?></td><td><?=e(['customer'=>'Müşteri','supplier'=>'Tedarikçi','both'=>'Her İkisi'][$account['account_type']] ?? '')?></td><td><?=e($account['phone'])?></td><td class="account-actions"><a class="edit" href="<?=url('current-accounts.php?edit=' . (int)$account['id'])?>" title="Düzenle" aria-label="Düzenle"><i class="ti tabler-edit"></i></a><form method="post" onsubmit="return confirm('Bu cari kart silinsin mi?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$account['id']?>"><button class="delete" title="Sil" aria-label="Sil"><i class="ti tabler-trash"></i></button></form></td></tr><?php endforeach ?><?php if (!$accounts): ?><tr><td colspan="5" class="empty">Henüz cari kart bulunmuyor.</td></tr><?php endif ?></tbody></table></div></section></main>
<style>.account-page{max-width:1180px!important;margin:auto;padding:96px 32px 48px!important}.account-head{margin-bottom:22px}.account-head h1,.account-card h2{margin:0 0 6px}.account-head p{margin:0;color:var(--muted)}.account-card{margin-bottom:24px;padding:24px;border:1px solid var(--line);border-radius:10px;background:var(--card);box-shadow:0 3px 12px #1e283c0f}.list-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:20px}.new-account-card summary{display:flex;align-items:center;gap:8px;height:43px;padding:0 16px;border-radius:7px;background:#19a94b;color:#fff;font-weight:700;cursor:pointer;list-style:none}.new-account-card summary::-webkit-details-marker{display:none}.new-account-card[open]{position:fixed;z-index:100;top:64px;right:0;bottom:0;left:260px;overflow:auto;padding:36px max(32px,calc((100vw - 1360px)/2));border:0;border-radius:0;background:#f7f7fb}.new-account-card[open] summary{max-width:1036px;height:auto;margin:0 auto 20px;padding:0;background:transparent;color:inherit;font-size:30px}.new-account-card[open] summary i{font-size:18px;color:#19a94b}.account-form{max-width:1036px;margin:0 auto;padding:24px;border:1px solid var(--line);border-radius:10px;background:var(--card);box-shadow:0 3px 12px #1e283c0f}.account-form section+section{margin-top:26px;padding-top:24px;border-top:1px solid var(--line)}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px 20px}.grid label{display:flex;flex-direction:column;gap:7px}.grid input,.grid select,.grid textarea{width:100%;min-height:43px;padding:10px 12px;border:1px solid #d2d2dc;border-radius:7px;background:transparent;color:inherit;font:inherit}.grid textarea{height:86px;resize:vertical}.save{display:inline-flex;align-items:center;justify-content:center;width:43px;margin-top:24px;height:43px;padding:0;border:0;border-radius:7px;background:#19a94b;color:#fff;font-weight:700}.notice{margin-bottom:18px;padding:13px 16px;border-radius:8px}.success{background:#daf5e3;color:#0d7130}.error{background:#ffe3e3;color:#a21d1d}.table-wrap{overflow:auto}.table-wrap table{width:100%;border-collapse:collapse}.table-wrap th,.table-wrap td{padding:14px;border-bottom:1px solid var(--line);text-align:left}.table-wrap th{font-size:12px}.account-actions{display:flex;gap:8px}.account-actions .edit,.account-actions .delete{display:inline-flex;align-items:center;justify-content:center;width:40px;height:42px;border:0;border-radius:7px;color:#fff}.account-actions .edit{background:#19a94b}.account-actions .delete{background:#e04f55}.empty{text-align:center;color:var(--muted)}[data-theme=dark] .account-card,[data-theme=dark] .new-account-card[open], [data-theme=dark] .account-form{background:#2f3349;border-color:#454a63}[data-theme=dark] .new-account-card[open]{background:#24273a}[data-theme=dark] .grid input,[data-theme=dark] .grid select,[data-theme=dark] .grid textarea{border-color:#5a607b;color:#fff}@media(max-width:700px){.account-page{padding:92px 14px 30px!important}.grid{grid-template-columns:1fr}.list-head{align-items:flex-start;flex-direction:column}.new-account-card[open]{top:64px;left:0;padding:28px 14px}.new-account-card[open] summary{font-size:25px}}</style>
<style>.grid label.technical-service-field{flex-direction:row;align-items:center;align-self:end;min-height:43px;gap:9px;padding:10px 12px;border:1px solid #d2d2dc;border-radius:7px;cursor:pointer}.grid label.technical-service-field input{width:17px;min-height:17px;height:17px;margin:0;padding:0;accent-color:#19a94b}</style>
<script>document.querySelectorAll('.account-actions .edit').forEach(edit=>{const link=document.createElement('a');link.className='edit';link.href=edit.href.replace('current-accounts.php?edit=','current-account-movements.php?id=');link.title='Cari Hareketleri';link.setAttribute('aria-label','Cari Hareketleri');link.style.background='#f5a33b';link.innerHTML='<i class="ti tabler-history"></i>';edit.before(link)});(()=>{const formatPhone=value=>{let digits=String(value||'').replace(/\D/g,'');if(digits.startsWith('90')&&digits.length===12)digits='0'+digits.slice(2);if(digits.length===10&&digits.startsWith('5'))digits='0'+digits;if(digits.length===11&&digits.startsWith('0'))return `${digits.slice(0,4)} ${digits.slice(4,7)} ${digits.slice(7,9)} ${digits.slice(9,11)}`;return value||''};const input=document.querySelector('input[name="phone"]');if(input){input.type='tel';input.inputMode='numeric';input.maxLength=14;input.placeholder='0532 656 95 58';input.value=formatPhone(input.value);input.addEventListener('input',()=>{const position=input.selectionStart;const formatted=formatPhone(input.value);input.value=formatted;input.setSelectionRange(Math.min(position,formatted.length),Math.min(position,formatted.length))});input.addEventListener('blur',()=>input.value=formatPhone(input.value))}document.querySelectorAll('.table-wrap tbody tr td:nth-child(4)').forEach(cell=>{cell.textContent=formatPhone(cell.textContent.trim())})})();</script>
<script>(()=>{const note=document.querySelector('textarea[name="shipping_address"]')?.closest('label');if(note&&note.firstChild)note.firstChild.nodeValue='Firma Detayı';const title=document.querySelector('input[name="title"]');if(title&&!document.querySelector('input[name="short_name"]')){const label=document.createElement('label'),input=document.createElement('input');label.append('Kısa Ad');input.name='short_name';input.maxLength=190;input.value=<?=json_encode($editingAccount['short_name'] ?? '')?>;label.append(input);title.closest('label')?.after(label)}const email=document.querySelector('input[name="email"]');if(email&&!document.querySelector('input[name="technical_service"]')){const label=document.createElement('label'),input=document.createElement('input');label.className='technical-service-field';input.type='checkbox';input.name='technical_service';input.value='1';input.checked=<?=json_encode((bool)($editingAccount['technical_service'] ?? false))?>;label.append(input,' Teknik Servis');email.closest('label')?.after(label)}})();</script>
<script>(()=>{const oldField=document.querySelector('input[name="technical_service"]')?.closest('label');if(!oldField)return;const label=document.createElement('label'),select=document.createElement('select');label.append('Teknik Servis');select.name='technical_service_type';select.innerHTML='<option value="">Seçiniz</option><option value="inside">İç Servis</option><option value="outside">Dış Servis</option>';select.value=<?=json_encode((string)($editingAccount['technical_service_type'] ?? ''))?>;label.append(select);oldField.replaceWith(label)})();</script>
<style>.grid .technical-service-options{display:flex;flex-direction:column;gap:7px}.technical-service-options>span{font-size:14px}.technical-service-options>div{display:flex;align-items:center;gap:18px;min-height:43px;padding:10px 12px;border:1px solid #d2d2dc;border-radius:7px}.technical-service-options label{display:flex;flex-direction:row;align-items:center;gap:7px}.technical-service-options input{width:17px!important;min-height:17px!important;height:17px!important;margin:0!important;padding:0!important;accent-color:#19a94b}</style>
<script>(()=>{const select=document.querySelector('select[name="technical_service_type"]'),oldField=select?.closest('label');if(!select||!oldField)return;const selected=new Set(<?=json_encode(array_filter(explode(',', (string)($editingAccount['technical_service_type'] ?? ''))))?>),field=document.createElement('div');field.className='technical-service-options';field.innerHTML='<span>Teknik Servis</span><div><label><input type="checkbox" name="technical_service_types[]" value="inside"> İç Servis</label><label><input type="checkbox" name="technical_service_types[]" value="outside"> Dış Servis</label></div>';field.querySelectorAll('input').forEach(input=>input.checked=selected.has(input.value));oldField.replaceWith(field)})();</script>
<script>(()=>{const shortNames=<?=json_encode(array_values(array_map(static fn($account) => (string)($account['short_name'] ?? ''), $accounts)))?>;const header=document.querySelector('.table-wrap thead th:nth-child(2)');if(header)header.textContent='KISA AD';document.querySelectorAll('.table-wrap tbody tr').forEach((row,index)=>{const cell=row.children[1];if(cell&&shortNames[index]!==undefined)cell.textContent=shortNames[index]})})();</script>
<script>(()=>{const contacts=<?=json_encode(array_values(array_map(static fn($account) => (string)($account['contact_person'] ?? ''), $accounts)))?>;const shortNameHeader=document.querySelector('.table-wrap thead th:nth-child(2)');if(shortNameHeader){const header=document.createElement('th');header.textContent='İLGİLİ KİŞİ';shortNameHeader.after(header)}document.querySelectorAll('.table-wrap tbody tr').forEach((row,index)=>{if(contacts[index]===undefined)return;const cell=document.createElement('td');cell.textContent=contacts[index];row.children[1]?.after(cell)})})();</script>
<script>(()=>{const code=document.querySelector('input[name="code"]');if(!code)return;code.value=<?=json_encode($displayAccountCode)?>;code.readOnly=true;code.required=false;code.title='Cari kodu sistem tarafından otomatik oluşturulur.';code.setAttribute('aria-readonly','true');})();</script>
<?php patient_footer(); ?>
