document.querySelectorAll('form[data-token-form]').forEach((form) => {
  const field = form.querySelector('input[name="token"]');
  const button = form.querySelector('button[type="submit"]');
  const fragment = new URLSearchParams(window.location.hash.slice(1));
  const token = fragment.get('token');
  if (token && /^[A-Za-z0-9_-]{43}$/.test(token)) field.value = token;
  if (window.location.hash) history.replaceState(null, '', window.location.pathname + window.location.search);
  button.disabled = !/^[A-Za-z0-9_-]{43}$/.test(field.value);
  const notice = form.querySelector('[data-token-notice]');
  if (notice) notice.hidden = !button.disabled;
});
