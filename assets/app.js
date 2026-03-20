document.addEventListener('click', (event) => {
  const target = event.target;
  if (!(target instanceof HTMLElement)) return;
  const confirmMessage = target.getAttribute('data-confirm');
  if (!confirmMessage) return;
  if (!window.confirm(confirmMessage)) {
    event.preventDefault();
  }
});
