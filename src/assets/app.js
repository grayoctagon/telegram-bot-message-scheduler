(function(){
  const PAGE = window.PAGE || 'messages';

  function $(id){ return document.getElementById(id); }
  function escapeHtml(str){
    return (str || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  }

  function pad2(n){ return String(n).padStart(2,'0'); }

  function formatRFC3339Local(d){
    const yyyy = d.getFullYear();
    const mm = pad2(d.getMonth()+1);
    const dd = pad2(d.getDate());
    const hh = pad2(d.getHours());
    const mi = pad2(d.getMinutes());
    const ss = pad2(d.getSeconds());
    const off = -d.getTimezoneOffset(); // minutes
    const sign = off >= 0 ? '+' : '-';
    const offAbs = Math.abs(off);
    const oh = pad2(Math.floor(offAbs/60));
    const om = pad2(offAbs%60);
    return `${yyyy}-${mm}-${dd}T${hh}:${mi}:${ss}${sign}${oh}:${om}`;
  }

  function toDatetimeLocalValue(rfc3339){
    const d = new Date(rfc3339);
    // datetime-local expects "YYYY-MM-DDTHH:MM"
    const yyyy = d.getFullYear();
    const mm = pad2(d.getMonth()+1);
    const dd = pad2(d.getDate());
    const hh = pad2(d.getHours());
    const mi = pad2(d.getMinutes());
    return `${yyyy}-${mm}-${dd}T${hh}:${mi}`;
  }

  function fromDatetimeLocalValue(v){
    // v is "YYYY-MM-DDTHH:MM"
    const d = new Date(v);
    if (isNaN(d.getTime())) return null;
    return formatRFC3339Local(d);
  }

  function humanizeMs(ms){
    const abs = Math.abs(ms);
    const sec = Math.floor(abs/1000);
    const min = Math.floor(sec/60);
    const hr = Math.floor(min/60);
    const day = Math.floor(hr/24);
    const week = Math.floor(day/7);

    const parts = [];
    if (week) parts.push(`${week} Woche${week===1?'':'n'}`);
    const dayR = day % 7;
    if (dayR) parts.push(`${dayR} Tag${dayR===1?'':'e'}`);
    const hrR = hr % 24;
    if (hrR) parts.push(`${hrR} Stunde${hrR===1?'':'n'}`);
    const minR = min % 60;
    if (minR && parts.length < 3) parts.push(`${minR} Minute${minR===1?'':'n'}`);
    if (parts.length === 0) parts.push('weniger als 1 Minute');
    return parts.join(', ');
  }

  async function getJson(url){
    const r = await fetch(url);
    return await r.json();
  }
  async function postJson(url, obj){
    const r = await fetch(url, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(obj)});
    return await r.json();
  }

  function buildGroupOptions(select, groups){
    select.innerHTML = '';
    (groups || []).forEach(g => {
      const opt = document.createElement('option');
      opt.value = String(g.id);
      opt.textContent = g.label ? `${g.label}` : String(g.id);
      select.appendChild(opt);
    });
  }

  // HISTORY
  async function initHistory(){
    const res = await getJson('api/history_get.php');
    $('historyLoading').classList.add('hidden');
    if (!res.ok) {
      $('historyList').textContent = 'Fehler: ' + (res.error || 'unknown');
      return;
    }
    const wrap = $('historyList');
    wrap.innerHTML = '';
    res.entries.forEach(e => {
      const div = document.createElement('div');
      div.className = 'msg';
      div.innerHTML = `
        <div class="msgHeader">
          <div><strong>${escapeHtml(e.ts || '')}</strong> <span class="small">${escapeHtml(e.user || '')}</span></div>
          <div class="badge">${escapeHtml(e.action || '')}</div>
        </div>
        <pre class="small" style="white-space:pre-wrap; margin:8px 0 0;">${escapeHtml(JSON.stringify(e.payload || {}, null, 2))}</pre>
      `;
      wrap.appendChild(div);
    });
  }

  // USER
  async function initUser(){
    $('btnLogoutAll').addEventListener('click', async () => {
      $('userStatus').textContent = 'lädt';
      const res = await postJson('api/logout_all.php', {});
      if (res.ok) {
        $('userStatus').textContent = 'Abgemeldet (überall).';
        window.location.href = '/login.php';
        return;
      }
      $('userStatus').textContent = 'Fehler: ' + (res.error || 'unknown');
    });
  }

  // MESSAGES
  let messages = [];
  let showOld = false;

  function isSentOlderThanDays(m, days){
    if ((m.status || '') !== 'sent') return false;
    const sentAt = m.sent_at || m.send_at;
    if (!sentAt) return false;
    const d = new Date(sentAt);
    if (isNaN(d.getTime())) return false;
    const cutoff = Date.now() - days*24*3600*1000;
    return d.getTime() < cutoff;
  }

  function renderMessages(){
    const list = $('msgList');
    list.innerHTML = '';

    const sorted = [...messages].sort((a,b) => String(a.send_at||'').localeCompare(String(b.send_at||'')));

    let prevDate = null;
    sorted.forEach((m, idx) => {
      if (!showOld && isSentOlderThanDays(m, 8)) return;

      const sendDate = new Date(m.send_at);
      const now = new Date();
      const msLeft = sendDate.getTime() - now.getTime();
      const leftStr = isNaN(sendDate.getTime()) ? '' : (msLeft >= 0 ? ('in ' + humanizeMs(msLeft)) : ('fällig seit ' + humanizeMs(msLeft)));

      if (prevDate) {
        const diff = sendDate.getTime() - prevDate.getTime();
        if (!isNaN(diff)) {
          const sep = document.createElement('div');
          sep.className = 'sep';
          sep.textContent = 'Abstand: ' + humanizeMs(diff);
          list.appendChild(sep);
        }
      }
      prevDate = sendDate;

      const locked = (!isNaN(sendDate.getTime()) && sendDate.getTime() < Date.now() && (m.status || '') !== 'sent');
      const badgeCls = locked ? 'locked' : (m.status || 'pending');

      const card = document.createElement('div');
      card.className = 'msg';
      card.dataset.id = m.id;

      const pre = !!m.preannounce_enabled;
      card.innerHTML = `
        <div class="msgHeader">
          <div>
            <span class="badge ${badgeCls}">${escapeHtml(locked ? 'locked' : (m.status || 'pending'))}</span>
            <span class="small">(${escapeHtml(leftStr)})</span>
          </div>
          <div class="row" style="margin:0;">
            <select class="input selTarget"></select>
            <input class="input dtSend" type="datetime-local">
          </div>
        </div>

        <textarea class="textarea txt" rows="3" placeholder="Text">${escapeHtml(m.text || '')}</textarea>

        <div class="row">
          <label class="chk"><input type="checkbox" class="chkPre" ${pre?'checked':''}> Vorankündigung</label>
          <input class="input preHours" type="number" min="1" step="1" style="width:120px;" placeholder="Stunden" value="${pre?Number(m.preannounce_hours_before||0):''}">
          <select class="input selPre"></select>
          <span class="small">${escapeHtml((m.last_error||m.preannounce_last_error||'') ? ('Fehler: ' + (m.last_error||m.preannounce_last_error)) : '')}</span>
        </div>
      `;

      const selTarget = card.querySelector('.selTarget');
      const selPre = card.querySelector('.selPre');
      const dtSend = card.querySelector('.dtSend');
      const txt = card.querySelector('.txt');
      const chkPre = card.querySelector('.chkPre');
      const preHours = card.querySelector('.preHours');

      const groups = (window.PUBLIC_CFG && window.PUBLIC_CFG.telegram && window.PUBLIC_CFG.telegram.groups) || [];
      const preGroups = (window.PUBLIC_CFG && window.PUBLIC_CFG.telegram && window.PUBLIC_CFG.telegram.preannounce_groups) || [];

      buildGroupOptions(selTarget, groups);
      buildGroupOptions(selPre, preGroups);

      selTarget.value = String(m.target_chat_id || (window.PUBLIC_CFG.telegram.default_group_id || ''));
      selPre.value = String(m.preannounce_chat_id || '');

      dtSend.value = toDatetimeLocalValue(m.send_at);

      if (locked) {
        selTarget.disabled = true;
        selPre.disabled = true;
        dtSend.disabled = true;
        txt.disabled = true;
        chkPre.disabled = true;
        preHours.disabled = true;
      }

      list.appendChild(card);
    });
  }

  function collectMessagesFromDom(){
    const list = $('msgList');
    const cards = Array.from(list.querySelectorAll('.msg'));
    const out = [];
    cards.forEach(card => {
      const id = card.dataset.id || '';
      const text = card.querySelector('.txt').value || '';
      const dtLocal = card.querySelector('.dtSend').value || '';
      const send_at = fromDatetimeLocalValue(dtLocal);
      const target_chat_id = card.querySelector('.selTarget').value || '';
      const preannounce_enabled = card.querySelector('.chkPre').checked;
      const preannounce_hours_before = Number(card.querySelector('.preHours').value || 0);
      const preannounce_chat_id = card.querySelector('.selPre').value || '';

      out.push({
        id,
        text,
        send_at,
        target_chat_id,
        preannounce_enabled,
        preannounce_hours_before,
        preannounce_chat_id
      });
    });
    return out;
  }

  async function loadMessages(){
    $('loading').classList.remove('hidden');
    const res = await getJson('api/messages_get.php');
    $('loading').classList.add('hidden');
    if (!res.ok) {
      $('saveStatus').textContent = 'Fehler beim Laden: ' + (res.error || 'unknown');
      return;
    }
    messages = res.messages || [];
    renderMessages();
  }

  async function saveMessages(){
    $('loading').classList.remove('hidden');
    $('saveStatus').textContent = '';
    const payload = {messages: collectMessagesFromDom()};
    const res = await postJson('api/messages_save.php', payload);
    $('loading').classList.add('hidden');
    if (!res.ok) {
      const errs = res.errors ? JSON.stringify(res.errors) : (res.error || 'unknown');
      $('saveStatus').textContent = 'Fehler: ' + errs;
      return;
    }
    $('saveStatus').textContent = 'gespeichert (' + (res.changes || 0) + ' Änderungen)';
    await loadMessages();
  }

  function addNewMessage(){
    const d = new Date();
    d.setMinutes(d.getMinutes() + 10);
    const msg = {
      id: '',
      text: '',
      send_at: formatRFC3339Local(d),
      target_chat_id: String(window.PUBLIC_CFG.telegram.default_group_id || ''),
      preannounce_enabled: false,
      preannounce_hours_before: 0,
      preannounce_chat_id: '',
      status: 'pending'
    };
    messages.push(msg);
    renderMessages();
  }

  async function initMessages(){
    showOld = false;
    $('showOld').addEventListener('change', (e) => {
      showOld = !!e.target.checked;
      renderMessages();
    });

    $('btnSave').addEventListener('click', saveMessages);
    $('btnAdd').addEventListener('click', addNewMessage);

    // Quick send
    const groups = (window.PUBLIC_CFG && window.PUBLIC_CFG.telegram && window.PUBLIC_CFG.telegram.groups) || [];
    buildGroupOptions($('quickTarget'), groups);

    $('btnQuickSend').addEventListener('click', async () => {
      const text = $('quickText').value || '';
      const target_chat_id = $('quickTarget').value || '';
      $('quickStatus').textContent = 'lädt';
      const res = await postJson('api/quick_send.php', {text, target_chat_id});
      if (res.ok) {
        $('quickStatus').textContent = 'OK (' + (res.status || '') + ')';
        $('quickText').value = '';
        await loadMessages();
      } else {
        $('quickStatus').textContent = 'Fehler: ' + (res.error || 'unknown');
      }
    });

    await loadMessages();
    // Update countdown every 30s
    setInterval(renderMessages, 30000);
  }

  if (PAGE === 'history') initHistory();
  if (PAGE === 'user') initUser();
  if (PAGE === 'messages') initMessages();
})();