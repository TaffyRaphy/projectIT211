document.addEventListener('click', (event) => {
  const target = event.target;
  if (!(target instanceof HTMLElement)) return;

  if (target.classList.contains('toggle-password')) {
    const inputId = target.getAttribute('data-target');
    if (!inputId) return;
    const input = document.getElementById(inputId);
    if (!(input instanceof HTMLInputElement)) return;
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    target.textContent = show ? 'Hide' : 'Show';
    target.setAttribute('aria-pressed', show ? 'true' : 'false');
    return;
  }

  const confirmMessage = target.getAttribute('data-confirm');
  if (!confirmMessage) return;
  if (!window.confirm(confirmMessage)) {
    event.preventDefault();
  }
});
