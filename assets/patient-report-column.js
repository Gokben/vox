(() => {
  document.addEventListener('DOMContentLoaded', async () => {
    const table = document.querySelector('.patient-table');
    if (!table || table.dataset.reportColumnReady) return;
    table.dataset.reportColumnReady = '1';

    const headerCells = [...table.querySelectorAll('thead th')];
    const reportInfoIndex = headerCells.findIndex(cell => cell.textContent.trim() === 'Rapor');
    if (reportInfoIndex < 0) return;
    const reportHeader = document.createElement('th');
    reportHeader.textContent = 'Rapor';
    headerCells[reportInfoIndex].textContent = 'Rapor Bilgisi';
    headerCells[reportInfoIndex].after(reportHeader);

    const rows = [...table.querySelectorAll('tbody tr')];
    const ids = [];
    const cellsById = new Map();
    rows.forEach(row => {
      const edit = row.querySelector('a[href*="patient-form.php?id="]');
      if (!edit) return;
      const id = new URL(edit.href, window.location.href).searchParams.get('id');
      if (!id) return;
      const cells = row.querySelectorAll('td');
      const cell = document.createElement('td');
      cell.className = 'patient-report-status';
      cell.textContent = '—';
      cells[reportInfoIndex].after(cell);
      ids.push(id);
      cellsById.set(id, cell);
    });
    if (!ids.length) return;

    const response = await fetch(`patient-report-status.php?ids=${encodeURIComponent(ids.join(','))}`, { credentials: 'same-origin' });
    if (!response.ok) return;
    const statuses = await response.json();
    Object.entries(statuses).forEach(([id, value]) => {
      const cell = cellsById.get(id);
      if (cell) cell.textContent = value || '—';
    });
  });
})();
