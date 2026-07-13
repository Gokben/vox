(function(){
  try{document.documentElement.dataset.theme=localStorage.getItem('vox-theme')||localStorage.getItem('lf-theme')||'light'}catch(error){document.documentElement.dataset.theme='light'}

  function trDate(value){
    var match=String(value||'').trim().match(/^(\d{4})-(\d{2})-(\d{2})(?:[T\s](\d{2}):(\d{2})(?::\d{2})?)?$/);
    if(!match)return value;
    return match[3]+'.'+match[2]+'.'+match[1]+(match[4]?' '+match[4]+':'+match[5]:'');
  }
  function formatTableDates(){
    document.querySelectorAll('table').forEach(function(table){
      var headers=Array.from(table.querySelectorAll('thead th'));
      headers.forEach(function(header,index){
        var title=(header.textContent||'').trim().toLocaleUpperCase('tr-TR');
        if(title.indexOf('TARİH')===-1&&title.indexOf('ZAMAN')===-1)return;
        table.querySelectorAll('tbody tr').forEach(function(row){
          var cell=row.children[index];
          if(!cell)return;
          var original=cell.textContent.trim(),formatted=trDate(original);
          if(formatted!==original){cell.textContent=formatted;cell.dataset.isoDate=original;}
        });
      });
    });
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',formatTableDates);else formatTableDates();
  window.voxFormatDate=trDate;
})();
