// ===== Sidebar toggle (mobile) =====
document.addEventListener('DOMContentLoaded', function () {
  const burgers = document.querySelectorAll('.burger');
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('overlay');
  function toggleSidebar() {
    sidebar.classList.toggle('open');
    overlay.classList.toggle('show');
  }
  burgers.forEach(b => b.addEventListener('click', toggleSidebar));
  if (overlay) overlay.addEventListener('click', toggleSidebar);

  // Page data only loads once the admin session is confirmed.
  loadAdminSession(function (user) {
    if (typeof initPage === 'function') initPage(user);
  });
});

// ===== Session =====
function loadAdminSession(onReady) {
  fetch('../api/auth/me.php')
    .then(r => {
      if (r.status === 401) {
        window.location.href = '../login.html';
        return null;
      }
      return r.json();
    })
    .then(user => {
      if (!user || user.error) return;
      if (user.role !== 'admin') {
        window.location.href = '../index.html';
        return;
      }
      document.querySelectorAll('.admin-name').forEach(el => el.textContent = user.full_name);
      // Admin bell counts operational alerts only, never the admin personal inbox.
      updateBellBadge(user.unread_admin_notifications);
      if (onReady) onReady(user);
    })
    .catch(() => {});
}

// Shared response handler: bounces expired sessions instead of showing a load error.
function readJson(res) {
  if (res.status === 401) {
    window.location.href = '../login.html';
    return Promise.reject(new Error('Session expired'));
  }
  return res.json().then(data => {
    if (!res.ok) {
      // Carry per-field messages through so forms can show them under the
      // matching input instead of only in the banner.
      const err = new Error(data.error || 'Request failed');
      err.fields = data.fields || null;
      throw err;
    }
    return data;
  });
}

function updateBellBadge(count) {
  document.querySelectorAll('.badge-dot').forEach(el => {
    if (count > 0) {
      el.textContent = count;
      el.style.display = 'flex';
    } else {
      el.style.display = 'none';
    }
  });
}

// ===== Modal helpers =====
function openModal(id) {
  document.getElementById(id).classList.add('open');
}
function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}

// ===== Toast/alert helper =====
function showAlert(id, message, type) {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = message;
  el.className = 'alert show alert-' + (type || 'success');
  setTimeout(() => el.classList.remove('show'), 3500);
}

// ===== Delete confirm helper =====
function confirmDelete(message) {
  return window.confirm(message || 'Are you sure you want to delete this record?');
}

// ===== Formatting helpers =====
function formatDate(value) {
  if (!value) return '—';
  const d = new Date(value.replace(' ', 'T'));
  if (isNaN(d)) return '—';
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function timeAgo(value) {
  const d = new Date(value.replace(' ', 'T'));
  const mins = Math.floor((Date.now() - d.getTime()) / 60000);
  if (mins < 1) return 'Just now';
  if (mins < 60) return mins + (mins === 1 ? ' minute ago' : ' minutes ago');
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return hrs + (hrs === 1 ? ' hour ago' : ' hours ago');
  const days = Math.floor(hrs / 24);
  if (days < 30) return days + (days === 1 ? ' day ago' : ' days ago');
  return formatDate(value);
}

function escapeHtml(value) {
  return String(value == null ? '' : value).replace(/[&<>"']/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[c]);
}
