/**
 * EOWMS Offline Queue
 * ---------------------------------------------------------------
 * Any <form data-offline-queue="unique-name"> on the page gets this
 * behaviour: if the device is offline when the form is submitted, the
 * form's data (including any files) is saved into IndexedDB instead of
 * failing, and a small badge shows "X नोंदी पाठवायच्या बाकी". As soon as
 * the browser reports it's back online (or the app is reopened), every
 * queued submission is replayed against its original action URL.
 *
 * NOTE ON LIMITATIONS: true silent background-sync while the app is
 * fully closed only works on Chrome/Android (Background Sync API).
 * Safari/iOS does not support that API at all, so on iPhone the queue
 * instead flushes automatically the moment the app/tab is reopened or
 * regains a connection while open — the data is never lost, but an
 * iPhone user may need to open the app once for it to actually upload.
 */
(function () {
  const DB_NAME = 'eowms-offline';
  const STORE = 'queued-submissions';

  function openDb() {
    return new Promise((resolve, reject) => {
      const req = indexedDB.open(DB_NAME, 1);
      req.onupgradeneeded = () => {
        const db = req.result;
        if (!db.objectStoreNames.contains(STORE)) {
          db.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
        }
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
  }

  async function queueSubmission(record) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, 'readwrite');
      tx.objectStore(STORE).add(record);
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    });
  }

  async function getAllQueued() {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, 'readonly');
      const req = tx.objectStore(STORE).getAll();
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
  }

  async function deleteQueued(id) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, 'readwrite');
      tx.objectStore(STORE).delete(id);
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    });
  }

  function formDataToRecord(form) {
    // FormData isn't directly structured-clone-safe with files in every
    // browser's IndexedDB implementation, so store fields explicitly.
    const fields = [];
    for (const [name, value] of new FormData(form).entries()) {
      fields.push({ name, value }); // value may be a File — IndexedDB stores Blobs/Files natively
    }
    return {
      action: form.action,
      queueName: form.dataset.offlineQueue,
      label: form.dataset.offlineLabel || 'नोंद',
      fields,
      queuedAt: Date.now(),
    };
  }

  function recordToFormData(record) {
    const fd = new FormData();
    record.fields.forEach((f) => fd.append(f.name, f.value));
    return fd;
  }

  let badgeEl = null;
  function ensureBadge() {
    if (badgeEl) return badgeEl;
    badgeEl = document.createElement('div');
    badgeEl.id = 'offlineQueueBadge';
    badgeEl.style.cssText = 'display:none;position:fixed;bottom:18px;left:50%;transform:translateX(-50%);' +
      'background:#C97F1D;color:#fff;padding:10px 18px;border-radius:24px;font-size:13px;font-weight:600;' +
      'font-family:\'Noto Sans Devanagari\',sans-serif;box-shadow:0 6px 20px rgba(0,0,0,.25);z-index:9998;cursor:pointer;';
    badgeEl.addEventListener('click', () => trySync(true));
    document.body.appendChild(badgeEl);
    return badgeEl;
  }

  async function refreshBadge() {
    const items = await getAllQueued().catch(() => []);
    const badge = ensureBadge();
    if (items.length === 0) {
      badge.style.display = 'none';
    } else {
      const isOnline = navigator.onLine;
      badge.style.display = 'block';
      badge.textContent = isOnline
        ? `\u21bb ${items.length} \u0928\u094b\u0902\u0926\u0940 \u092a\u093e\u0920\u0935\u0924\u094b \u0906\u0939\u0947...`
        : `\ud83d\udcf4 ${items.length} \u0928\u094b\u0902\u0926\u0940 \u0907\u0902\u091f\u0930\u0928\u0947\u091f \u0906\u0932\u094d\u092f\u093e\u0935\u0930 \u092a\u093e\u0920\u0935\u0932\u094d\u092f\u093e \u091c\u093e\u0924\u0940\u0932`;
    }
  }

  let syncing = false;
  async function trySync(showToastOnDone) {
    if (syncing || !navigator.onLine) { refreshBadge(); return; }
    syncing = true;
    const items = await getAllQueued().catch(() => []);
    let succeeded = 0;
    let sessionExpired = false;
    for (const item of items) {
      try {
        const res = await fetch(item.action, { method: 'POST', body: recordToFormData(item) });
        // A redirect that lands on login.php means the session expired while
        // offline — don't delete the queued item (would silently lose the
        // person's work), just stop and ask them to log back in first.
        if (res.redirected && res.url.indexOf('login.php') !== -1) {
          sessionExpired = true;
          break;
        }
        if (res.ok) {
          await deleteQueued(item.id);
          succeeded++;
        }
      } catch (e) {
        break; // still offline or a transient error — leave it queued, try again later
      }
    }
    syncing = false;
    await refreshBadge();
    if (sessionExpired) {
      const badge = ensureBadge();
      badge.style.display = 'block';
      badge.style.background = '#B23A48';
      badge.textContent = '⚠️ Session संपली — नोंदी पाठवण्यासाठी परत Login करा';
    } else if (showToastOnDone && succeeded > 0) {
      alert(`${succeeded} नोंदी यशस्वीरित्या पाठवल्या गेल्या.`);
    }
  }

  function attachToForms() {
    document.querySelectorAll('form[data-offline-queue]').forEach((form) => {
      if (form.dataset.offlineBound) return;
      form.dataset.offlineBound = '1';
      form.addEventListener('submit', async (e) => {
        if (navigator.onLine) return; // let it submit normally when online
        e.preventDefault();
        const record = formDataToRecord(form);
        await queueSubmission(record);
        await refreshBadge();
        alert((form.dataset.offlineLabel || 'ही नोंद') + ' Internet नसल्यामुळे फोनवर सुरक्षित ठेवली आहे — Internet आल्यावर आपोआप पाठवली जाईल.');
        // Return to a safe list page instead of hanging on the form
        if (form.dataset.offlineRedirect) { window.location.href = form.dataset.offlineRedirect; }
      });
    });
  }

  window.addEventListener('online', () => trySync(false));
  window.addEventListener('DOMContentLoaded', () => {
    attachToForms();
    refreshBadge();
    trySync(false); // flush any backlog the moment the app is opened (covers iOS)
  });
})();
