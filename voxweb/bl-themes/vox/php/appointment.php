<div class="container">
    <div class="intro">
        <div class="kicker">Online randevu</div>
        <h1>Size uygun gün ve saati seçin.</h1>
        <p>Ücretsiz işitme testi için randevu talebinizi birkaç adımda bırakın.</p>
    </div>
    <div class="shell">
        <div class="top">
            <div class="branch"><label class="field-label" for="branch">Şube</label><select id="branch" name="branch" form="form" class="control" required><option <?php echo ($appointmentValues['branch'] ?? '') === 'Sefaköy Şubesi (Merkez)' ? 'selected' : ''; ?>>Sefaköy Şubesi (Merkez)</option><option <?php echo ($appointmentValues['branch'] ?? '') === 'Bahçeşehir Şubesi' ? 'selected' : ''; ?>>Bahçeşehir Şubesi</option></select></div>
            <div class="clock">Şu an saat: <span id="clock">--:--</span></div>
        </div>
        <div class="grid">
            <div class="calendar">
                <div class="calendar-head"><button class="calendar-nav" id="prev" type="button" aria-label="Önceki ay">‹</button><strong id="month" aria-live="polite"></strong><button class="calendar-nav" id="next" type="button" aria-label="Sonraki ay">›</button></div>
                <div class="weekdays" aria-hidden="true"><span>PZT</span><span>SAL</span><span>ÇAR</span><span>PER</span><span>CUM</span><span>CMT</span><span>PAZ</span></div>
                <div id="days" class="days" aria-label="Randevu tarihi seçin"></div>
                <div class="time-heading">Uygun saatler</div>
                <div id="slots" class="slots" aria-live="polite"></div>
            </div>
            <form id="form" class="form" method="post" action="<?php echo htmlspecialchars($appointmentUrl, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="vox_appointment" value="1">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars((string)$_SESSION['vox_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" id="appointment-date" name="date" value="<?php echo htmlspecialchars($appointmentValues['date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" id="appointment-time" name="time" value="<?php echo htmlspecialchars($appointmentValues['time'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <label class="honeypot" aria-hidden="true">Web sitesi<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                    <div class="full"><label class="field-label" for="name">Ad Soyad</label><input class="control" id="name" name="name" autocomplete="name" maxlength="100" placeholder="Adınız ve soyadınız" value="<?php echo htmlspecialchars($appointmentValues['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required></div>
                    <div><label class="field-label" for="phone">Telefon</label><input class="control" id="phone" name="phone" autocomplete="tel" inputmode="tel" maxlength="24" placeholder="05XX XXX XX XX" value="<?php echo htmlspecialchars($appointmentValues['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required></div>
                    <div><label class="field-label" for="email">E-posta</label><input class="control" id="email" name="email" type="email" autocomplete="email" maxlength="160" placeholder="ornek@email.com" value="<?php echo htmlspecialchars($appointmentValues['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required></div>
                    <div class="full"><label class="field-label" for="note">Not <span style="font-weight:400">(isteğe bağlı)</span></label><textarea class="control" id="note" name="note" maxlength="1000" placeholder="Randevu ile ilgili eklemek istediğiniz bir şey varsa..."><?php echo htmlspecialchars($appointmentValues['note'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea></div>
                    <label class="full consent"><input type="checkbox" name="consent" value="1" required><span>Kişisel verilerimin randevu talebimin değerlendirilmesi amacıyla işlenmesini kabul ediyorum.</span></label>
                    <div id="status" class="full status<?php echo $appointmentState['message'] !== '' ? ' visible ' . $appointmentState['type'] : ''; ?>" role="status"><?php echo htmlspecialchars($appointmentState['message'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <button class="full button submit" type="submit">Randevu Talebi Oluştur <b class="arrow">→</b></button>
            </form>
        </div>
    </div>
</div>
