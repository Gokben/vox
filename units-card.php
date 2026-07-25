<?php
foreach (['record_date DATE NULL','identity_no VARCHAR(32) NULL','birth_date DATE NULL','phone1 VARCHAR(50) NULL','phone2 VARCHAR(50) NULL','address TEXT NULL','rating TINYINT NULL','comment TEXT NULL'] as $definition) {
    $column = explode(' ', $definition)[0];
    try {
        if ($sqlite) {
            $columns = $pdo->query('PRAGMA table_info(units)')->fetchAll();
            if (!in_array($column, array_column($columns, 'name'), true)) $pdo->exec('ALTER TABLE units ADD COLUMN ' . $definition);
        } else {
            $check = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='units' AND column_name=?");
            $check->execute([$column]);
            if (!$check->fetchColumn()) $pdo->exec('ALTER TABLE units ADD COLUMN ' . $definition);
        }
    } catch (Throwable $exception) {}
}
$message=''; $error=''; $editId=(int)($_GET['edit']??0); $unit=null;
if($editId){$s=$pdo->prepare('SELECT * FROM units WHERE id=?');$s->execute([$editId]);$unit=$s->fetch()?:null;}
if($_SERVER['REQUEST_METHOD']==='POST'){verify_csrf();$action=(string)($_POST['action']??'save');$id=(int)($_POST['id']??0);try{if($action==='delete'){$pdo->prepare('DELETE FROM units WHERE id=?')->execute([$id]);$message='Ünite silindi.';}else{$data=[trim((string)($_POST['full_name']??'')),trim((string)($_POST['identity_no']??'')),trim((string)($_POST['record_date']??'')),trim((string)($_POST['birth_date']??'')),trim((string)($_POST['phone1']??'')),trim((string)($_POST['phone2']??'')),trim((string)($_POST['address']??'')),max(0,min(5,(int)($_POST['rating']??0))),trim((string)($_POST['comment']??''))];if($data[0]==='')throw new RuntimeException('Ad Soyad zorunludur.');if($action==='update'){$data[]=$id;$pdo->prepare('UPDATE units SET name=?,identity_no=?,record_date=?,birth_date=?,phone1=?,phone2=?,address=?,rating=?,comment=? WHERE id=?')->execute($data);$message='Ünite güncellendi.';}else{$code='UNIT-'.strtoupper(bin2hex(random_bytes(4)));array_unshift($data,$code);$pdo->prepare('INSERT INTO units(code,name,identity_no,record_date,birth_date,phone1,phone2,address,rating,comment) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute($data);$message='Ünite kaydedildi.';}}}catch(Throwable $exception){$error=$exception instanceof RuntimeException?$exception->getMessage():'Kayıt işlemi tamamlanamadı.';}}
$units=$pdo->query('SELECT * FROM units ORDER BY name')->fetchAll(); patient_header('Üniteler','cash');
?>
<style>
.uc-card details[open] form{display:grid!important;grid-template-columns:1fr!important;gap:14px!important}
.uc-card details[open] form label{position:relative;display:grid!important;grid-column:1/-1!important;grid-template-columns:150px minmax(0,1fr)!important;align-items:center!important;gap:0 14px!important}
.uc-card details[open] form label>b{position:absolute;left:72px;top:0;color:#e04f55}
.uc-card details[open] form label input,.uc-card details[open] form label select,.uc-card details[open] form label textarea{grid-column:2!important;width:100%!important;box-sizing:border-box}
.uc-card details[open] form>.save{grid-column:1!important;justify-self:start!important;margin-left:164px!important}
.uc-card details[open] .rating-select{display:none}.uc-card details[open] .rating-stars{grid-column:2;display:flex;gap:8px}.uc-card details[open] .rating-stars button{padding:0;border:0;background:transparent;color:#d9dbe5;font-size:25px;line-height:1;cursor:pointer}.uc-card details[open] .rating-stars button.selected{color:#f4c343}
</style>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const select = document.querySelector('.uc-card details[open] select[name="rating"]');
  if (!select || select.dataset.starsReady) return;
  select.dataset.starsReady = 'true';
  const stars = document.createElement('div');
  stars.className = 'rating-stars';
  const render = () => stars.querySelectorAll('button').forEach(button => button.classList.toggle('selected', Number(button.value) <= Number(select.value)));
  for (let value = 1; value <= 5; value++) {
    const button = document.createElement('button');
    button.type = 'button'; button.value = String(value); button.textContent = '★';
    button.setAttribute('aria-label', `${value} yıldız`);
    button.addEventListener('click', () => { select.value = String(value); render(); });
    stars.appendChild(button);
  }
  select.classList.add('rating-select');
  select.after(stars); render();
});
</script>
<script>
(() => {
  const setupRating = details => {
    const select = details.querySelector('select[name="rating"]');
    if (!select || select.dataset.starsReady) return;
    select.dataset.starsReady = 'true';
    const stars = document.createElement('div');
    stars.className = 'rating-stars';
    const render = () => stars.querySelectorAll('button').forEach(button => button.classList.toggle('selected', Number(button.value) <= Number(select.value)));
    for (let value = 1; value <= 5; value++) {
      const button = document.createElement('button');
      button.type = 'button'; button.value = String(value); button.textContent = '★';
      button.setAttribute('aria-label', `${value} yıldız`);
      button.addEventListener('click', () => { select.value = String(value); render(); });
      stars.appendChild(button);
    }
    select.classList.add('rating-select'); select.after(stars); render();
  };
  document.querySelectorAll('.uc-card details[open]').forEach(setupRating);
  document.addEventListener('toggle', event => { if (event.target.matches('.uc-card details') && event.target.open) setupRating(event.target); }, true);
})();
</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const formatPhone = value => {
    let digits = String(value).replace(/\D/g, '');
    if (digits.length === 13 && digits.startsWith('90') && digits.charAt(2) === '0') digits = digits.slice(2);
    else if (digits.length === 12 && digits.startsWith('90')) digits = '0' + digits.slice(2);
    else if (digits.length === 10 && digits.startsWith('5')) digits = '0' + digits;
    digits = digits.slice(0, 11);
    return [digits.slice(0,4), digits.slice(4,7), digits.slice(7,9), digits.slice(9,11)].filter(Boolean).join(' ');
  };
  document.querySelectorAll('.uc-card input[name="phone1"],.uc-card input[name="phone2"]').forEach(input => {
    input.inputMode = 'tel'; input.maxLength = 14; input.placeholder = '0546 638 67 75';
    input.value = formatPhone(input.value);
    input.addEventListener('input', () => { input.value = formatPhone(input.value); });
  });
});
</script>
<main class="patient-container uc-page"><div class="uc-head"><h1>Üniteler</h1><p>Ünite kartlarını yönetin.</p></div><?php if($message):?><div class="uc-notice ok"><?=e($message)?></div><?php endif?><?php if($error):?><div class="uc-notice bad"><?=e($error)?></div><?php endif?><section class="uc-card"><header><h2>Ünite Listesi</h2><details <?=$unit?'open':''?>><summary><?=$unit?'Üniteyi Düzenle':'Yeni Ünite'?> <i class="ti tabler-plus"></i></summary><form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="<?=$unit?'update':'save'?>"><?php if($unit):?><input type="hidden" name="id" value="<?=(int)$unit['id']?>"><?php endif?><label>Kayıt Tarihi<input type="date" name="record_date" value="<?=e($unit['record_date']??date('Y-m-d'))?>"></label><label>Ad Soyad <b>*</b><input name="full_name" required value="<?=e($unit['name']??'')?>"></label><label>T.C. Kimlik No<input name="identity_no" value="<?=e($unit['identity_no']??'')?>"></label><label>Doğum Tarihi<input type="date" name="birth_date" value="<?=e($unit['birth_date']??'')?>"></label><label>Telefon 1<input name="phone1" value="<?=e($unit['phone1']??'')?>"></label><label>Telefon 2<input name="phone2" value="<?=e($unit['phone2']??'')?>"></label><label>Adres<textarea name="address"><?=e($unit['address']??'')?></textarea></label><label>Değerlendirme<select name="rating"><option value="0">Değerlendirme yok</option><?php for($i=1;$i<=5;$i++):?><option value="<?=$i?>" <?=($unit['rating']??0)===$i?'selected':''?>><?=str_repeat('★',$i)?></option><?php endfor?></select></label><label>Yorum<textarea name="comment"><?=e($unit['comment']??'')?></textarea></label><button class="save" title="Kaydet" aria-label="Kaydet"><i class="ti tabler-device-floppy"></i></button></form></details></header><table><thead><tr><th>AD SOYAD</th><th>TELEFON</th><th>KAYIT TARİHİ</th><th>İŞLEMLER</th></tr></thead><tbody><?php foreach($units as $item):?><tr><td><?=e($item['name'])?></td><td><?=e($item['phone1']??'')?></td><td><?=e(format_date_tr($item['record_date']??''))?></td><td class="uc-actions"><a href="<?=url('units.php?edit='.(int)$item['id'])?>" title="Düzenle"><i class="ti tabler-edit"></i></a><form method="post" onsubmit="return confirm('Bu ünite silinsin mi?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$item['id']?>"><button title="Sil"><i class="ti tabler-trash"></i></button></form></td></tr><?php endforeach?></tbody></table></section></main>
<style>.uc-page{max-width:1180px!important;margin:auto;padding:96px 32px 48px!important}.uc-head{margin-bottom:22px}.uc-head h1,.uc-card h2{margin:0 0 6px}.uc-head p{margin:0;color:var(--muted)}.uc-card{padding:24px;border:1px solid var(--line);border-radius:10px;background:var(--card)}.uc-card header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}.uc-card summary{display:flex;gap:8px;padding:12px 16px;border-radius:7px;background:#19a94b;color:#fff;font-weight:700;cursor:pointer;list-style:none}.uc-card details[open]{position:fixed;inset:64px 0 0 260px;z-index:100;padding:36px max(32px,calc((100vw - 1360px)/2));background:#f7f7fb;overflow:auto}.uc-card details[open] summary{width:max-content;margin:0 auto 20px;background:transparent;color:inherit;font-size:30px}.uc-card details form{max-width:900px;margin:auto;padding:24px;border:1px solid var(--line);border-radius:10px;background:var(--card);display:grid;grid-template-columns:160px 1fr;gap:14px 20px}.uc-card label{display:contents}.uc-card label>b{color:#e04f55}.uc-card input,.uc-card select,.uc-card textarea{padding:10px 12px;border:1px solid #d2d2dc;border-radius:7px;background:transparent;color:inherit;font:inherit}.uc-card textarea{min-height:76px}.uc-card form>.save{grid-column:2;width:42px;height:42px;border:0;border-radius:7px;background:#19a94b;color:#fff}.uc-card table{width:100%;border-collapse:collapse}.uc-card th,.uc-card td{padding:14px;border-bottom:1px solid var(--line);text-align:left}.uc-actions{display:flex;gap:8px}.uc-actions a,.uc-actions button{display:inline-flex;align-items:center;justify-content:center;width:40px;height:42px;border:0;border-radius:7px;background:#19a94b;color:#fff}.uc-actions form{margin:0}.uc-actions button{background:#e04f55}.uc-notice{margin-bottom:18px;padding:13px;border-radius:8px}.ok{background:#daf5e3;color:#0d7130}.bad{background:#ffe3e3;color:#a21d1d}@media(max-width:700px){.uc-page{padding:92px 14px 30px!important}.uc-card header{flex-direction:column;align-items:flex-start}.uc-card details[open]{left:0;padding:28px 14px}.uc-card details form{grid-template-columns:1fr}.uc-card form>.save{grid-column:auto}}</style><?php patient_footer(); ?>
