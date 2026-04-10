const THEME_KEY = 'ems-theme';
const THEMES = ['dark', 'light'];

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

  const nextLabel = nextTheme === 'dark' ? 'Light mode' : 'Dark mode';
  document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    if (!(button instanceof HTMLButtonElement)) return;
    button.textContent = nextLabel;
    button.setAttribute('aria-pressed', nextTheme === 'light' ? 'true' : 'false');
    button.setAttribute('aria-label', 'Switch to ' + (nextTheme === 'dark' ? 'light' : 'dark') + ' mode');
  });
}

document.addEventListener('DOMContentLoaded', () => {
  applyTheme(getPreferredTheme());
});

document.addEventListener('click', (event) => {
  const rawTarget = event.target;
  if (!(rawTarget instanceof Element)) return;

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
    toggleButton.textContent = show ? 'Hide' : 'Show';
    toggleButton.setAttribute('aria-pressed', show ? 'true' : 'false');
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
      passwordToggle.textContent = 'Show';
      passwordToggle.setAttribute('aria-pressed', 'false');
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
