const THEME_KEY = 'ems-theme';
const THEMES = ['dark', 'light'];
const NOTIF_FETCH_URL = '/api/actions/notifications_popup.php';
const NOTIF_MARK_URL = '/api/actions/notifications_popup_mark.php';

let notifPopupRoot = null;
let notifPopupPanel = null;
let notifPopupList = null;
let notifPopupStatus = null;
let notifPopupCount = null;
let notifPopupTrigger = null;
let notifActiveFilter = 'all';
let auditModalRoot = null;
let auditModalCloseButton = null;
let auditLastFocusedRow = null;

const notifTypeIcons = {
  request_submitted: 'fa-clipboard-list',
  request_approved: 'fa-circle-check',
  request_rejected: 'fa-circle-xmark',
  request_return_notify: 'fa-box-open',
  maintenance_scheduled: 'fa-wrench',
  maintenance_completed: 'fa-check',
  maintenance_cancelled: 'fa-ban',
  maintenance_due: 'fa-clock',
  maintenance_overdue: 'fa-triangle-exclamation',
  equipment_due_return: 'fa-clock',
  equipment_overdue_return: 'fa-triangle-exclamation',
};

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function formatNotificationDate(rawDate) {
  if (!rawDate) return '';
  const date = new Date(rawDate);
  if (Number.isNaN(date.getTime())) return rawDate;

  try {
    return new Intl.DateTimeFormat(undefined, {
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
    }).format(date);
  } catch {
    return rawDate;
  }
}

function ensureNotificationPopup() {
  if (notifPopupRoot !== null) return;

  const root = document.createElement('div');
  root.className = 'notification-popup';
  root.setAttribute('aria-hidden', 'true');
  root.innerHTML = `
    <div class="notification-popup__backdrop" data-notif-close></div>
    <section class="notification-popup__panel" role="dialog" aria-modal="true" aria-label="Notifications">
      <header class="notification-popup__header">
        <div>
          <h3 class="notification-popup__title">Notifications</h3>
          <p class="notification-popup__count" data-notif-count></p>
        </div>
        <button type="button" class="notification-popup__close" data-notif-close aria-label="Close notifications">
          <i class="fas fa-xmark"></i>
        </button>
      </header>

      <div class="notification-popup__filters" role="tablist" aria-label="Notification filters">
        <button type="button" class="notification-popup__filter is-active" data-notif-filter="all">All</button>
        <button type="button" class="notification-popup__filter" data-notif-filter="unread">Unread</button>
        <button type="button" class="notification-popup__mark-all" data-notif-mark-all>Mark all as read</button>
      </div>

      <p class="notification-popup__status" data-notif-status aria-live="polite"></p>
      <div class="notification-popup__list" data-notif-list></div>
    </section>
  `;

  document.body.appendChild(root);

  notifPopupRoot = root;
  notifPopupPanel = root.querySelector('.notification-popup__panel');
  notifPopupList = root.querySelector('[data-notif-list]');
  notifPopupStatus = root.querySelector('[data-notif-status]');
  notifPopupCount = root.querySelector('[data-notif-count]');
}

function updateNotificationBadgeUI(unreadCount) {
  const count = Number.isFinite(unreadCount) ? Math.max(0, unreadCount) : 0;

  document.querySelectorAll('.bell-btn').forEach((button) => {
    if (!(button instanceof HTMLElement)) return;

    const label = count > 0 ? `Notifications (${count} unread)` : 'Notifications';
    button.setAttribute('aria-label', label);

    let badge = button.querySelector('.bell-badge');
    if (count > 0) {
      if (!(badge instanceof HTMLElement)) {
        badge = document.createElement('span');
        badge.className = 'bell-badge';
        button.appendChild(badge);
      }
      badge.textContent = count > 99 ? '99+' : String(count);
    } else if (badge instanceof HTMLElement) {
      badge.remove();
    }
  });

  document.querySelectorAll('a[href="#"][data-notif-popup-trigger]').forEach((link) => {
    if (!(link instanceof HTMLElement)) return;
    const badge = link.querySelector('span');
    if (!(badge instanceof HTMLElement)) return;
    if (count > 0) {
      badge.textContent = String(count);
    } else {
      badge.remove();
    }
  });

  document.querySelectorAll('.metric-card-sm .mc-label').forEach((labelNode) => {
    if (!(labelNode instanceof HTMLElement)) return;
    if (labelNode.textContent?.trim().toLowerCase() !== 'unread notifications') return;
    const card = labelNode.closest('.metric-card-sm');
    if (!(card instanceof HTMLElement)) return;
    const valueNode = card.querySelector('.mc-value');
    if (valueNode instanceof HTMLElement) {
      valueNode.textContent = String(count);
    }
  });

  if (notifPopupCount instanceof HTMLElement) {
    notifPopupCount.textContent = count > 0 ? `${count} unread` : 'All caught up';
  }
}

function setNotificationPopupStatus(message) {
  if (!(notifPopupStatus instanceof HTMLElement)) return;
  notifPopupStatus.textContent = message;
}

function renderNotificationList(items) {
  if (!(notifPopupList instanceof HTMLElement)) return;

  if (!Array.isArray(items) || items.length === 0) {
    notifPopupList.innerHTML = `
      <div class="notification-popup__empty">
        <i class="fas fa-bell-slash"></i>
        <p>No notifications found.</p>
      </div>
    `;
    return;
  }

  notifPopupList.innerHTML = items.map((item) => {
    const icon = notifTypeIcons[item.type] ?? 'fa-bell';
    const unreadClass = item.is_read ? '' : ' is-unread';
    const persistentTag = item.is_persistent ? '<span class="notification-popup__pill">Persistent</span>' : '';
    const markButton = item.is_read || item.is_persistent
      ? ''
      : `<button type="button" class="notification-popup__item-action" data-notif-mark="${item.id}">Mark read</button>`;

    return `
      <article class="notification-popup__item${unreadClass}">
        <div class="notification-popup__item-icon"><i class="fas ${icon}"></i></div>
        <div class="notification-popup__item-body">
          <p class="notification-popup__item-message">${escapeHtml(item.message ?? '')}</p>
          <div class="notification-popup__item-meta">
            <span>${escapeHtml(formatNotificationDate(item.created_at ?? ''))}</span>
            ${persistentTag}
          </div>
        </div>
        ${markButton}
      </article>
    `;
  }).join('');
}

async function markNotification(payload) {
  const body = new URLSearchParams(payload);
  const response = await fetch(NOTIF_MARK_URL, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
    },
    body: body.toString(),
  });

  if (!response.ok) {
    throw new Error('Unable to update notifications.');
  }

  const result = await response.json();
  if (!result?.ok) {
    throw new Error(result?.error ?? 'Unable to update notifications.');
  }

  updateNotificationBadgeUI(Number(result.unread_count ?? 0));
}

async function loadNotifications(filter) {
  if (!(notifPopupList instanceof HTMLElement)) return;

  setNotificationPopupStatus('Loading notifications...');
  notifPopupList.innerHTML = '';

  const query = new URLSearchParams({
    filter: filter,
    limit: '12',
  });

  const response = await fetch(`${NOTIF_FETCH_URL}?${query.toString()}`, {
    method: 'GET',
    credentials: 'same-origin',
    headers: {
      'Accept': 'application/json',
    },
  });

  if (!response.ok) {
    throw new Error('Unable to load notifications.');
  }

  const result = await response.json();
  if (!result?.ok) {
    throw new Error(result?.error ?? 'Unable to load notifications.');
  }

  renderNotificationList(result.notifications ?? []);
  updateNotificationBadgeUI(Number(result.unread_count ?? 0));
  setNotificationPopupStatus('');
}

function setNotificationFilterButtons(filter) {
  document.querySelectorAll('[data-notif-filter]').forEach((button) => {
    if (!(button instanceof HTMLButtonElement)) return;
    button.classList.toggle('is-active', button.getAttribute('data-notif-filter') === filter);
  });
}

function closeNotificationPopup() {
  if (!(notifPopupRoot instanceof HTMLElement)) return;
  notifPopupRoot.classList.remove('is-open');
  notifPopupRoot.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('notification-popup-open');

  if (notifPopupTrigger instanceof HTMLElement) {
    notifPopupTrigger.focus();
  }
}

function closeAuditModal() {
  if (!(auditModalRoot instanceof HTMLElement)) return;
  document.body.classList.remove('audit-modal-open');
  if (auditModalRoot.hasAttribute('hidden')) return;

  auditModalRoot.setAttribute('hidden', '');
  auditModalRoot.setAttribute('aria-hidden', 'true');

  if (auditLastFocusedRow instanceof HTMLElement) {
    auditLastFocusedRow.focus();
  }
}

function openAuditModal(row) {
  if (!(auditModalRoot instanceof HTMLElement)) return;
  if (!(row instanceof HTMLElement)) return;

  const fieldEntries = {
    id: row.getAttribute('data-audit-id') ?? '',
    action: row.getAttribute('data-audit-action') ?? '',
    user: row.getAttribute('data-audit-user') ?? '',
    email: row.getAttribute('data-audit-email') ?? '',
    role: row.getAttribute('data-audit-role') ?? '',
    table: row.getAttribute('data-audit-table') ?? '',
    record: row.getAttribute('data-audit-record') ?? '',
    when: row.getAttribute('data-audit-when') ?? '',
    'old-details': row.getAttribute('data-audit-old-details') ?? 'No tracked details.',
    'new-details': row.getAttribute('data-audit-new-details') ?? 'No tracked details.',
  };

  Object.entries(fieldEntries).forEach(([key, value]) => {
    const target = auditModalRoot.querySelector(`[data-audit-modal-field="${key}"]`);
    if (!(target instanceof HTMLElement)) return;
    target.textContent = value;
  });

  auditLastFocusedRow = row;
  document.body.classList.add('audit-modal-open');
  auditModalRoot.removeAttribute('hidden');
  auditModalRoot.setAttribute('aria-hidden', 'false');

  if (auditModalCloseButton instanceof HTMLElement) {
    auditModalCloseButton.focus();
  }
}

function markAuditRowSelected(row) {
  document.querySelectorAll('.audit-row.is-selected').forEach((current) => {
    if (!(current instanceof HTMLElement)) return;
    current.classList.remove('is-selected');
  });

  if (row instanceof HTMLElement) {
    row.classList.add('is-selected');
  }
}

function initAuditTrailModal() {
  const root = document.querySelector('[data-audit-modal]');
  if (!(root instanceof HTMLElement)) return;

  auditModalRoot = root;
  auditModalCloseButton = root.querySelector('[data-audit-modal-close]');

  const rows = document.querySelectorAll('.audit-row');
  rows.forEach((row) => {
    if (!(row instanceof HTMLElement)) return;

    row.addEventListener('click', () => {
      markAuditRowSelected(row);
    });

    row.addEventListener('dblclick', () => {
      markAuditRowSelected(row);
      openAuditModal(row);
    });

    row.addEventListener('keydown', (event) => {
      if (!(event instanceof KeyboardEvent)) return;
      if (event.key !== 'Enter' && event.key !== ' ') return;
      event.preventDefault();
      markAuditRowSelected(row);
      openAuditModal(row);
    });
  });

  root.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;

    if (target.closest('[data-audit-modal-close]')) {
      closeAuditModal();
      return;
    }

    if (target === root) {
      closeAuditModal();
    }
  });
}

async function openNotificationPopup(trigger) {
  ensureNotificationPopup();
  if (!(notifPopupRoot instanceof HTMLElement)) return;

  notifPopupTrigger = trigger instanceof HTMLElement ? trigger : null;

  notifPopupRoot.classList.add('is-open');
  notifPopupRoot.setAttribute('aria-hidden', 'false');
  document.body.classList.add('notification-popup-open');
  notifActiveFilter = 'all';
  setNotificationFilterButtons(notifActiveFilter);

  try {
    await loadNotifications(notifActiveFilter);
  } catch (error) {
    setNotificationPopupStatus(error instanceof Error ? error.message : 'Unable to load notifications.');
  }

  const closeBtn = notifPopupRoot.querySelector('[data-notif-close]');
  if (closeBtn instanceof HTMLElement) {
    closeBtn.focus();
  }
}

function initNotificationPopupTriggers() {
  const links = document.querySelectorAll('a[href^="/api/my_notifications.php"]');
  links.forEach((link) => {
    if (!(link instanceof HTMLAnchorElement)) return;
    link.setAttribute('data-notif-popup-trigger', '1');
    link.setAttribute('href', '#');
  });
}

function initProgressBars() {
  document.querySelectorAll('.progress-bar-fill[data-progress]').forEach((bar) => {
    if (!(bar instanceof HTMLElement)) return;

    const rawValue = Number(bar.getAttribute('data-progress') ?? '0');
    const safeValue = Number.isFinite(rawValue) ? Math.max(0, Math.min(100, rawValue)) : 0;
    bar.style.setProperty('--progress-pct', `${safeValue}%`);
  });
}

function getPreferredTheme() {
  const stored = window.localStorage.getItem(THEME_KEY);
  if (stored !== null && THEMES.includes(stored)) {
    return stored;
  }

  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function applyTheme(theme) {
  const nextTheme = THEMES.includes(theme) ? theme : 'dark';
  document.documentElement.setAttribute('data-theme', nextTheme);
  window.localStorage.setItem(THEME_KEY, nextTheme);

  const icon = nextTheme === 'dark' ? '🌙' : '☀';
  document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    if (!(button instanceof HTMLButtonElement)) return;
    button.textContent = icon;
    button.setAttribute('aria-pressed', nextTheme === 'light' ? 'true' : 'false');
    button.setAttribute('aria-label', 'Switch to ' + (nextTheme === 'dark' ? 'light' : 'dark') + ' mode');
  });
}

document.addEventListener('DOMContentLoaded', () => {
  applyTheme(getPreferredTheme());
  initNotificationPopupTriggers();
  initProgressBars();
  ensureNotificationPopup();
  initAuditTrailModal();
});

document.addEventListener('click', (event) => {
  const rawTarget = event.target;
  if (!(rawTarget instanceof Element)) return;

  const notifTrigger = rawTarget.closest('[data-notif-popup-trigger]');
  if (notifTrigger instanceof HTMLElement) {
    event.preventDefault();
    openNotificationPopup(notifTrigger);
    return;
  }

  const notifClose = rawTarget.closest('[data-notif-close]');
  if (notifClose instanceof HTMLElement) {
    event.preventDefault();
    closeNotificationPopup();
    return;
  }

  const notifFilterButton = rawTarget.closest('[data-notif-filter]');
  if (notifFilterButton instanceof HTMLButtonElement) {
    event.preventDefault();
    const nextFilter = notifFilterButton.getAttribute('data-notif-filter') === 'unread' ? 'unread' : 'all';
    notifActiveFilter = nextFilter;
    setNotificationFilterButtons(nextFilter);
    loadNotifications(nextFilter).catch((error) => {
      setNotificationPopupStatus(error instanceof Error ? error.message : 'Unable to load notifications.');
    });
    return;
  }

  const notifMarkAllButton = rawTarget.closest('[data-notif-mark-all]');
  if (notifMarkAllButton instanceof HTMLButtonElement) {
    event.preventDefault();
    markNotification({ mark_all: '1' })
      .then(() => loadNotifications(notifActiveFilter))
      .catch((error) => {
        setNotificationPopupStatus(error instanceof Error ? error.message : 'Unable to update notifications.');
      });
    return;
  }

  const notifMarkButton = rawTarget.closest('[data-notif-mark]');
  if (notifMarkButton instanceof HTMLButtonElement) {
    event.preventDefault();
    const notifId = notifMarkButton.getAttribute('data-notif-mark') ?? '';
    markNotification({ notification_id: notifId })
      .then(() => loadNotifications(notifActiveFilter))
      .catch((error) => {
        setNotificationPopupStatus(error instanceof Error ? error.message : 'Unable to update notifications.');
      });
    return;
  }

  const themeButton = rawTarget.closest('[data-theme-toggle]');
  if (themeButton instanceof HTMLButtonElement) {
    const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
    applyTheme(isDark ? 'light' : 'dark');
    return;
  }

  const toggleButton = rawTarget.closest('.toggle-password');
  if (toggleButton instanceof HTMLElement) {
    const inputId = toggleButton.getAttribute('data-target');
    if (!inputId) return;
    const input = document.getElementById(inputId);
    if (!(input instanceof HTMLInputElement)) return;
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    toggleButton.classList.toggle('is-visible', show);
    toggleButton.setAttribute('aria-pressed', show ? 'true' : 'false');
    toggleButton.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    return;
  }

  const seedButton = rawTarget.closest('[data-login-seed]');
  if (seedButton instanceof HTMLElement) {
    const email = seedButton.getAttribute('data-email') ?? '';
    const password = seedButton.getAttribute('data-password') ?? '';
    const emailInput = document.getElementById('email');
    const passwordInput = document.getElementById('password');
    if (!(emailInput instanceof HTMLInputElement) || !(passwordInput instanceof HTMLInputElement)) {
      return;
    }

    emailInput.value = email;
    passwordInput.value = password;
    passwordInput.type = 'password';

    const passwordToggle = document.querySelector('.toggle-password');
    if (passwordToggle instanceof HTMLElement) {
      passwordToggle.classList.remove('is-visible');
      passwordToggle.setAttribute('aria-pressed', 'false');
      passwordToggle.setAttribute('aria-label', 'Show password');
    }

    emailInput.focus();
    return;
  }

  const confirmTarget = rawTarget.closest('[data-confirm]');
  if (!(confirmTarget instanceof HTMLElement)) return;

  const confirmMessage = confirmTarget.getAttribute('data-confirm');
  if (!confirmMessage) return;
  if (!window.confirm(confirmMessage)) {
    event.preventDefault();
  }
});

document.addEventListener('keydown', (event) => {
  if (!(event instanceof KeyboardEvent)) return;
  if (event.key !== 'Escape') return;

  if (auditModalRoot instanceof HTMLElement && !auditModalRoot.hasAttribute('hidden')) {
    closeAuditModal();
    return;
  }

  if (!(notifPopupRoot instanceof HTMLElement)) return;
  if (!notifPopupRoot.classList.contains('is-open')) return;
  closeNotificationPopup();
});
