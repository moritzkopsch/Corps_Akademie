const menuButton = document.querySelector('.menu-toggle');
const navigation = document.querySelector('#hauptnavigation');
if (menuButton && navigation) {
  menuButton.addEventListener('click', () => {
    const open = menuButton.getAttribute('aria-expanded') !== 'true';
    menuButton.setAttribute('aria-expanded', String(open));
    navigation.toggleAttribute('data-open', open);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && navigation.hasAttribute('data-open')) {
      menuButton.setAttribute('aria-expanded', 'false');
      navigation.removeAttribute('data-open');
      menuButton.focus();
    }
  });
}
const mode = document.querySelector('#referent_mode');
const options = document.querySelector('[data-referent-options]');
if (mode && options) {
  const synchronize = () => {
    const recommend = mode.value === 'recommend';
    options.hidden = recommend;
    options.querySelectorAll('input').forEach((input) => { input.disabled = recommend; });
    const chosen = [...options.querySelectorAll('input')].some((input) => input.checked);
    mode.setCustomValidity(!recommend && !chosen ? 'Bitte mindestens einen gewünschten Referenten auswählen.' : '');
  };
  mode.addEventListener('change', synchronize);
  options.addEventListener('change', synchronize);
  synchronize();
}
document.querySelectorAll('[data-wizard]').forEach((form) => {
  const steps = [...form.querySelectorAll('[data-step]')];
  const labels = [...form.querySelectorAll('[data-step-label]')];
  const back = form.querySelector('[data-back]');
  const next = form.querySelector('[data-next]');
  const send = form.querySelector('[data-send]');
  let current = 0;
  const show = (index, focus = false) => {
    current = index;
    steps.forEach((step, i) => { step.hidden = i !== index; });
    labels.forEach((label, i) => { if (i === index) label.setAttribute('aria-current', 'step'); else label.removeAttribute('aria-current'); });
    back.hidden = index === 0;
    next.hidden = index === steps.length - 1;
    send.hidden = index !== steps.length - 1;
    if (focus) {
      const legend = steps[index].querySelector('legend');
      legend.tabIndex = -1;
      legend.focus();
    }
  };
  const validate = (step) => {
    const invalid = [...step.querySelectorAll('input,select,textarea')].find((field) => !field.disabled && !field.checkValidity());
    if (invalid) { invalid.reportValidity(); return false; }
    return true;
  };
  // Validation is handled here so an invalid field can first be made visible.
  // The server always performs the same substantive validation.
  form.noValidate = true;
  next.addEventListener('click', () => { if (validate(steps[current])) show(current + 1, true); });
  back.addEventListener('click', () => show(current - 1, true));
  form.addEventListener('submit', (event) => {
    if (current < steps.length - 1) {
      event.preventDefault();
      if (validate(steps[current])) show(current + 1, true);
      return;
    }
    for (let i = 0; i < steps.length; i++) {
      const invalid = [...steps[i].querySelectorAll('input,select,textarea')].find((field) => !field.disabled && !field.checkValidity());
      if (invalid) { event.preventDefault(); show(i); invalid.reportValidity(); return; }
    }
  });
  show(0);
});
