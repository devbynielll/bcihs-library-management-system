(function () {
  'use strict';
  const section = document.getElementById('smsCredits');
  if (!section) return;
  const button = document.getElementById('smsCreditsRefresh');
  const status = document.getElementById('smsCreditsStatus');
  let csrfToken = '';
  let busy = false;
  let loaded = false;
  async function refresh() {
    if (busy || !csrfToken) return;
    busy = true;
    button.disabled = true;
    status.textContent = 'Checking Semaphore credits…';
    try {
      const response = await fetch('backend/semaphore-credits.php', {
        credentials: 'same-origin', cache: 'no-store', headers: { 'X-CSRF-Token': csrfToken }
      });
      if (!response.ok) throw new Error('unavailable');
      const data = await response.json();
      document.getElementById('smsCreditsBalance').textContent = data.balance ?? 'Unavailable';
      document.getElementById('smsCreditsUpdated').textContent = data.updated_at
        ? new Date(data.updated_at * 1000).toLocaleString('en-PH', { timeZone: 'Asia/Manila' }) + ' PHT' : 'Not checked';
      status.textContent = data.message === 'Balance loaded from Semaphore. No SMS was sent.'
        ? 'Balance up to date. Refresh is available once a minute.' : data.message;
      loaded = true;
    } catch (error) {
      status.textContent = 'Credit balance is unavailable. Reload the page or try again later.';
    } finally { busy = false; button.disabled = false; }
  }
  section.addEventListener('toggle', function () { if (section.open && !loaded) refresh(); });
  button.addEventListener('click', refresh);
  fetch('backend/sms-settings.php', { credentials: 'same-origin', cache: 'no-store' })
    .then(function (response) { if (!response.ok) throw new Error('denied'); return response.json(); })
    .then(function (data) {
      if (data.can_manage === true && data.csrfToken) { csrfToken = data.csrfToken; section.hidden = false; }
    }).catch(function () { section.hidden = true; });
}());
