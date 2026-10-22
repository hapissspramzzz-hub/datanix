(()=>{
'use strict';
const $=(selector,root=document)=>root.querySelector(selector);
const $$=(selector,root=document)=>[...root.querySelectorAll(selector)];
const base=document.body?.dataset.baseUrl||'';
const api=path=>`${base}/api/${String(path).replace(/^\//,'')}`;
const token=$('meta[name="csrf-token"]')?.content||'';
function toast(text){const holder=$('#toast-holder');if(!holder)return;const el=document.createElement('div');el.className='toast-datanix';el.textContent=text;holder.append(el);setTimeout(()=>el.remove(),3800)}
async function parseResponse(response){const type=response.headers.get('content-type')||'';if(!type.includes('application/json'))throw new Error('Server mengembalikan respons yang tidak dapat dibaca.');return response.json()}

const nav=$('.marketing-nav');$('[data-nav-toggle]')?.addEventListener('click',()=>nav?.classList.toggle('is-open'));
const sidebar=$('[data-sidebar]'),scrim=$('.sidebar-scrim');
$('[data-sidebar-open]')?.addEventListener('click',()=>{sidebar?.classList.add('is-open');scrim?.classList.add('is-visible')});
$$('[data-sidebar-close]').forEach(node=>node.addEventListener('click',()=>{sidebar?.classList.remove('is-open');scrim?.classList.remove('is-visible')}));

document.addEventListener('submit',event=>{const form=event.target.closest('[data-confirm]');if(form&&!confirm(form.dataset.confirm||'Lanjutkan tindakan ini?'))event.preventDefault()});
$$('[data-consent-form]').forEach(form=>form.addEventListener('submit',event=>{const box=$('[name="ai_consent"]',form);if(box&&!box.checked){event.preventDefault();toast('Setujui informasi pemrosesan AI sebelum melanjutkan.')}}));

$$('[data-suggest-text]').forEach(button=>button.addEventListener('click',()=>{const input=$('[name="question"]');if(!input)return;input.value=button.dataset.suggestText||'';input.focus()}));

$$('[data-query-form]').forEach(form=>form.addEventListener('submit',async event=>{
  event.preventDefault();const input=$('[name="question"]',form),question=input?.value.trim()||'';if(!question)return;
  const submit=$('button[type="submit"]',form),messages=$('[data-chat-messages]');if(!messages)return;
  submit&&(submit.disabled=true);submit?.classList.add('loading');
  const userBubble=document.createElement('div');userBubble.className='chat-bubble user';userBubble.textContent=question;messages.append(userBubble);input.value='';messages.scrollTo({top:messages.scrollHeight,behavior:'smooth'});
  try{
    const consentBox=$('[name="ai_consent"]',form);const consent=form.dataset.aiConsent==='1'||!!consentBox?.checked;
    const response=await fetch(api('query'),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token,'Accept':'application/json'},body:JSON.stringify({dataset_id:form.dataset.datasetId,conversation_id:form.dataset.conversationId||null,question,ai_consent:consent})});
    const data=await parseResponse(response);
    if(!response.ok||!data.ok){if(data.consent_required){toast('Sebelum AI dipakai, baca dan setujui informasi privasi.');location.href=`${base}/profile/privacy.php?return=${encodeURIComponent(location.pathname+location.search)}`;return}throw new Error(data.error||'Analisis gagal.')}
    form.dataset.aiConsent=consent?'1':'0';if(consentBox)consentBox.closest('.ai-disclosure')?.remove();
    const bubble=document.createElement('div');bubble.className='chat-bubble assistant';bubble.innerHTML=data.html;messages.append(bubble);
    $$('[data-chart]',bubble).forEach(node=>window.DatanixCharts?.draw(node));messages.scrollTo({top:messages.scrollHeight,behavior:'smooth'});
    if(data.conversation_id){form.dataset.conversationId=data.conversation_id;const params=new URLSearchParams(location.search);params.set('conversation_id',data.conversation_id);params.set('dataset_id',form.dataset.datasetId);history.replaceState({},'',`${location.pathname}?${params.toString()}`)}
    toast(data.usage?`Analisis selesai · ${data.usage.used} / ${data.usage.limit} query bulan ini`:'Analisis selesai');
  }catch(error){toast(error.message||'Koneksi gagal.')}finally{submit&&(submit.disabled=false);submit?.classList.remove('loading')}
}));

document.addEventListener('click',async event=>{
  const save=event.target.closest('[data-save-insight]');
  if(save){const title=prompt('Nama insight',save.dataset.title||'Insight baru');if(title===null||!title.trim())return;try{const response=await fetch(api('insights'),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token,'Accept':'application/json'},body:JSON.stringify({query_id:save.dataset.queryId,title:title.trim()})});const result=await parseResponse(response);if(!response.ok||!result.ok)throw new Error(result.error||'Tidak dapat menyimpan.');toast('Insight tersimpan.')}catch(error){toast(error.message||'Tidak dapat menyimpan insight.')}}
  const columnToggle=event.target.closest('[data-column-toggle]');if(columnToggle){const table=document.getElementById(columnToggle.dataset.table);if(!table)return;const col=columnToggle.dataset.column;const hidden=!columnToggle.checked;$$(`[data-col="${CSS.escape(col)}"]`,table).forEach(cell=>cell.hidden=hidden);try{const key=`datanix:columns:${table.dataset.datasetId}`;const state=JSON.parse(localStorage.getItem(key)||'{}');state[col]=!hidden;localStorage.setItem(key,JSON.stringify(state))}catch{}}
  const download=event.target.closest('[data-download-csv]');if(download){const table=document.getElementById(download.dataset.downloadCsv);if(!table)return;const rows=$$('tr',table).map(row=>$$('th,td',row).filter(cell=>!cell.hidden).map(cell=>`"${cell.innerText.replace(/"/g,'""')}"`).join(','));const blob=new Blob(['\ufeff'+rows.join('\r\n')],{type:'text/csv;charset=utf-8'});const link=document.createElement('a');link.href=URL.createObjectURL(blob);link.download=download.dataset.filename||'datanix-export.csv';link.click();URL.revokeObjectURL(link.href)}
});

$$('[data-sample-load]').forEach(button=>button.addEventListener('click',async()=>{if(!confirm('Impor 5.000 baris historis UCI Online Retail ke workspace aktif? Data ini berasal dari 2010–2011, bukan penjualan saat ini.'))return;button.disabled=true;try{const response=await fetch(`${base}/datasets/sample.php`,{method:'POST',headers:{'X-CSRF-Token':token,'Accept':'application/json'}});const data=await parseResponse(response);if(!response.ok||!data.ok)throw new Error(data.error||'Impor gagal.');location.href=`${base}/datasets/explorer.php?id=${data.dataset_id}`}catch(error){toast(error.message||'Impor gagal.');button.disabled=false}}));

$$('[data-sort-table]').forEach(table=>$$('th[data-sort]',table).forEach(header=>header.addEventListener('click',()=>{const index=[...header.parentElement.children].indexOf(header),rows=$$('tbody tr',table),direction=header.dataset.direction==='asc'?'desc':'asc';header.dataset.direction=direction;rows.sort((a,b)=>{const left=a.children[index]?.dataset.sort||a.children[index]?.textContent.trim()||'',right=b.children[index]?.dataset.sort||b.children[index]?.textContent.trim()||'';const numberA=Number(String(left).replace(/[^0-9,.-]/g,'').replace(/\./g,'').replace(',','.')),numberB=Number(String(right).replace(/[^0-9,.-]/g,'').replace(/\./g,'').replace(',','.'));const comparison=Number.isFinite(numberA)&&Number.isFinite(numberB)?numberA-numberB:String(left).localeCompare(String(right),'id');return direction==='asc'?comparison:-comparison});rows.forEach(row=>table.tBodies[0].append(row))})));
$$('[data-filter-table]').forEach(input=>input.addEventListener('input',()=>{const table=$(`#${CSS.escape(input.dataset.filterTable)}`);if(!table)return;const query=input.value.toLocaleLowerCase('id');$$('tbody tr',table).forEach(row=>row.hidden=!row.textContent.toLocaleLowerCase('id').includes(query))}));
$$('[data-type-toggle]').forEach(select=>select.addEventListener('change',()=>{const type=select.value;$$('[data-connector-field]').forEach(field=>field.hidden=!field.dataset.connectorField.split(',').includes(type));const port=$('[name="port"]',select.form);if(port){if(type==='pgsql'&&port.value==='3306')port.value='5432';if(type==='mysql'&&port.value==='5432')port.value='3306'}}));
$$('[data-select-all]').forEach(input=>input.addEventListener('change',()=>$$(`[name="${CSS.escape(input.dataset.selectAll)}"]`).forEach(box=>box.checked=input.checked)));
$$('[data-inline-edit]').forEach(button=>button.addEventListener('click',()=>{const target=document.getElementById(button.dataset.inlineEdit);if(target)target.hidden=!target.hidden}));
$$('[data-copy]').forEach(button=>button.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(button.dataset.copy);toast('Disalin.')}catch{toast('Tidak dapat menyalin di browser ini.')}}));

// Restore per-dataset visible-column preferences in the raw explorer.
$$('table[data-dataset-id]').forEach(table=>{try{const saved=JSON.parse(localStorage.getItem(`datanix:columns:${table.dataset.datasetId}`)||'{}');for(const [column,visible] of Object.entries(saved)){if(visible===false)$$(`[data-col="${CSS.escape(column)}"]`,table).forEach(cell=>cell.hidden=true);const box=$(`[data-column-toggle][data-table="${table.id}"][data-column="${CSS.escape(column)}"]`);if(box)box.checked=visible!==false}}catch{}});
})();
