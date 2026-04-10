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
