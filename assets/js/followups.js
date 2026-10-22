(function () {
  const BASE_URL = document.body.dataset.baseUrl || '';
  const fuForm = document.getElementById('fuForm');
  const fuModalEl = document.getElementById('fuModal');
  const fuModal = fuModalEl ? new bootstrap.Modal(fuModalEl) : null;
  const csrf = () => fuForm.querySelector('[name=csrf_token]').value;

  if (fuForm) {
    fuForm.addEventListener('submit', function (e) {
      e.preventDefault();
      const fd = new FormData(fuForm);
      fetch(`${BASE_URL}/api/meetings/save.php`, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
          if (!res.success) { alert(res.message); return; }
          location.reload();
        });
    });
  }

  function postFollowupUpdate(id, action, extra) {
    const fd = new FormData();
    fd.append('id', id);
    fd.append('action', action);
    if (extra) Object.keys(extra).forEach(k => fd.append(k, extra[k]));
    fd.append('csrf_token', csrf());
    return fetch(`${BASE_URL}/api/meetings/update.php`, { method: 'POST', body: fd }).then(r => r.json());
  }

  function updateFollowup(id, action) {
    if (!confirm('Confirm this action?')) return;
    postFollowupUpdate(id, action).then(res => { alert(res.message); if (res.success) location.reload(); });
  }

  // ---- Mark Done: a description is required before the meeting can be saved as Done.
  // Saving with the box left blank instead records it as Not Attended (no_response). ----
  const fuDoneModalEl = document.getElementById('fuDoneModal');
  const fuDoneModal = fuDoneModalEl ? new bootstrap.Modal(fuDoneModalEl) : null;
  const fuDoneIdEl = document.getElementById('fuDoneId');
  const fuDoneTitleEl = document.getElementById('fuDoneModalTitle');
  const fuDoneTextEl = document.getElementById('fuDoneDescription');
  const fuDoneSaveBtn = document.getElementById('fuDoneSaveBtn');

  window.markDone = function (id, clientName) {
    if (!fuDoneModal) { updateFollowup(id, 'done'); return; } // fallback if modal markup missing
    fuDoneIdEl.value = id;
    fuDoneTextEl.value = '';
    fuDoneTitleEl.innerHTML = clientName
      ? `Meeting Outcome — ${clientName}`
      : 'Meeting Outcome';
    fuDoneModal.show();
    setTimeout(() => fuDoneTextEl.focus(), 300);
  };

  if (fuDoneSaveBtn) {
    fuDoneSaveBtn.addEventListener('click', function () {
      const id = fuDoneIdEl.value;
      const desc = fuDoneTextEl.value.trim();
      if (!id) return;

      fuDoneSaveBtn.disabled = true;
      fuDoneSaveBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Saving...';

      if (desc === '') {
        // No description entered -> not saved as Done, counted as Not Attended instead.
        postFollowupUpdate(id, 'no_response')
          .then(res => {
            fuDoneModal.hide();
            alert(res.message || 'No description entered — marked as Not Attended.');
            if (res.success) location.reload();
          })
          .finally(() => {
            fuDoneSaveBtn.disabled = false;
            fuDoneSaveBtn.innerHTML = '<i class="bi bi-check2 me-1"></i>Save';
          });
        return;
      }

      // Description present -> log it, then mark the meeting Done.
      const fd = new FormData();
      fd.append('entity_type', 'followup');
      fd.append('entity_id', id);
      fd.append('description', desc);
      fd.append('csrf_token', csrf());

      fetch(`${BASE_URL}/api/common/description.php`, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
          if (!res.success) { alert(res.message || 'Could not save description.'); return; }
          return postFollowupUpdate(id, 'done').then(res2 => {
            fuDoneModal.hide();
            alert(res2.message);
            if (res2.success) location.reload();
          });
        })
        .catch(() => alert('Could not save. Please try again.'))
        .finally(() => {
          fuDoneSaveBtn.disabled = false;
          fuDoneSaveBtn.innerHTML = '<i class="bi bi-check2 me-1"></i>Save';
        });
    });
  }

  window.markOutcome = function (id, action) { updateFollowup(id, action); };

  window.reschedule = function (id, currentDateTime) {
    const newVal = prompt('Enter new date & time (YYYY-MM-DD HH:MM):', currentDateTime.slice(0, 16));
    if (!newVal) return;
    postFollowupUpdate(id, 'reschedule', { scheduled_at: newVal.replace(' ', 'T') })
      .then(res => { alert(res.message); if (res.success) location.reload(); });
  };
})();
