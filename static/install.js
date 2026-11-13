/* Browser installer: small guard rails, no dependencies. */
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const input = button.parentElement.querySelector('input');
      if (!input) return;
      const showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      button.textContent = showing ? '◉' : '◌';
      button.setAttribute('aria-label', showing ? 'Mostrar senha' : 'Ocultar senha');
    });
  });

  document.querySelectorAll('[data-no-autofill]').forEach((input) => {
    // Keep secrets out of browser autofill/back-forward cache when possible.
    input.setAttribute('autocomplete', 'new-password');
    input.setAttribute('data-lpignore', 'true');
  });

  document.querySelectorAll('form[data-installer-form]').forEach((form) => {
    form.addEventListener('submit', () => {
      const submit = form.querySelector('button[type="submit"]');
      if (!submit) return;
      submit.disabled = true;
      submit.dataset.label = submit.innerHTML;
      submit.innerHTML = 'Processando…';
    });
  });

  const password = document.querySelector('input[name="admin_password"]');
  const confirm = document.querySelector('input[name="admin_password_confirm"]');
  if (password && confirm) {
    const check = () => {
      confirm.setCustomValidity(
        confirm.value && password.value !== confirm.value
          ? 'A confirmação precisa ser igual à senha.'
          : ''
      );
    };
    password.addEventListener('input', check);
    confirm.addEventListener('input', check);
  }
});
