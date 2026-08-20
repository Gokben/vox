(() => {
  const toggle = document.querySelector('.nav-toggle');
  const navigation = document.querySelector('.main-nav');
  if (toggle && navigation) {
    toggle.addEventListener('click', () => {
      const expanded = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', String(!expanded));
      navigation.classList.toggle('open', !expanded);
    });
  }

  const calendar = document.getElementById('days');
  if (!calendar) return;

  const months = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
  const times = ['09:30', '10:20', '11:10', '13:30', '14:20', '15:10', '16:00', '16:50'];
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  let view = new Date(today.getFullYear(), today.getMonth(), 1);
  const dateInput = document.getElementById('appointment-date');
  const timeInput = document.getElementById('appointment-time');
  let selectedDate = dateInput.value ? new Date(`${dateInput.value}T00:00:00`) : null;
  let selectedTime = timeInput.value || '';
  const monthLabel = document.getElementById('month');
  const slotArea = document.getElementById('slots');
  const status = document.getElementById('status');
  const form = document.getElementById('form');
  const formatIso = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
  const formatDate = date => new Intl.DateTimeFormat('tr-TR', { day: 'numeric', month: 'long', year: 'numeric' }).format(date);

  const renderSlots = () => {
    slotArea.innerHTML = '';
    if (!selectedDate) {
      slotArea.textContent = 'Önce uygun bir gün seçin.';
      return;
    }
    times.forEach(time => {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = time;
      button.className = time === selectedTime ? 'selected' : '';
      button.addEventListener('click', () => {
        selectedTime = time;
        timeInput.value = time;
        renderSlots();
      });
      slotArea.appendChild(button);
    });
  };

  const renderCalendar = () => {
    monthLabel.textContent = `${months[view.getMonth()]} ${view.getFullYear()}`;
    calendar.innerHTML = '';
    const offset = (new Date(view.getFullYear(), view.getMonth(), 1).getDay() + 6) % 7;
    const dayCount = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();
    for (let index = 0; index < offset; index += 1) {
      const empty = document.createElement('span');
      empty.className = 'empty';
      calendar.appendChild(empty);
    }
    for (let day = 1; day <= dayCount; day += 1) {
      const date = new Date(view.getFullYear(), view.getMonth(), day);
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = String(day);
      button.disabled = date < today || date.getDay() === 0;
      button.setAttribute('aria-label', formatDate(date));
      button.className = selectedDate && formatIso(date) === formatIso(selectedDate) ? 'selected' : '';
      button.addEventListener('click', () => {
        selectedDate = date;
        selectedTime = '';
        dateInput.value = formatIso(date);
        timeInput.value = '';
        renderCalendar();
        renderSlots();
      });
      calendar.appendChild(button);
    }
    document.getElementById('prev').disabled = view.getFullYear() === today.getFullYear() && view.getMonth() === today.getMonth();
  };

  document.getElementById('prev').addEventListener('click', () => { view.setMonth(view.getMonth() - 1); renderCalendar(); });
  document.getElementById('next').addEventListener('click', () => { view.setMonth(view.getMonth() + 1); renderCalendar(); });
  form.addEventListener('submit', event => {
    if (selectedDate && selectedTime) return;
    event.preventDefault();
    status.textContent = 'Lütfen randevu günü ve saatini seçin.';
    status.className = 'full status visible error';
  });

  const updateClock = () => {
    const now = new Date();
    document.getElementById('clock').textContent = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
  };
  updateClock();
  setInterval(updateClock, 30000);
  renderCalendar();
  renderSlots();
})();
