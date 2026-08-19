document.addEventListener('DOMContentLoaded', function () {
  // Mobile Navigation Toggle
  const toggle = document.getElementById('menuToggle');
  const mobileNav = document.getElementById('mobileNav');
  if (toggle && mobileNav) {
    toggle.addEventListener('click', function () {
      mobileNav.classList.toggle('open');
    });
  }

  // Profile Dropdown Toggle
  const profileTrigger = document.getElementById('profileTrigger');
  const profileDropdown = document.getElementById('profileDropdown');

  if (profileTrigger && profileDropdown) {
    profileTrigger.addEventListener('click', function (e) {
      e.stopPropagation();
      profileDropdown.classList.toggle('open');
      profileTrigger.classList.toggle('open');
    });

    document.addEventListener('click', function () {
      profileDropdown.classList.remove('open');
      profileTrigger.classList.remove('open');
    });
  }

  // Every logged-in page carries the profile menu, so guard those only.
  if (profileTrigger) {
    loadSession(function (user) {
      if (typeof initPage === 'function') initPage(user);
    });
  } else if (typeof initPage === 'function') {
    initPage(null);
  }
});

// ===== Session =====
function loadSession(onReady) {
  fetch('api/auth/me.php')
    .then(r => {
      if (r.status === 401) {
        window.location.href = 'login.html';
        return null;
      }
      return r.json();
    })
    .then(user => {
      if (!user || user.error) return;
      localStorage.setItem('bbms_user', JSON.stringify(user));
      document.querySelectorAll('.name-text').forEach(el => el.textContent = user.full_name);
      updateBellBadge(user.unread_notifications);
      if (onReady) onReady(user);
    })
    .catch(() => {});
}

// Shared response handler: bounces expired sessions instead of showing a load error.
function readJson(res) {
  if (res.status === 401) {
    window.location.href = 'login.html';
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
  document.querySelectorAll('.bell-badge').forEach(el => {
    if (count > 0) {
      el.textContent = count;
      el.style.display = 'flex';
    } else {
      el.style.display = 'none';
    }
  });
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
