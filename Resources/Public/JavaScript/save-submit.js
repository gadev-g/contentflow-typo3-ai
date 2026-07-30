document.querySelectorAll('.cf-approve-form, .cf-migration-edit-form').forEach((form) => {
  form.addEventListener('submit', (event) => {
    const button = form.querySelector('.cf-approve-submit, [data-contentflow-save]');

    if (!button || button.dataset.loading === 'true') {
      event.preventDefault();

      return;
    }

    button.dataset.loading = 'true';
    button.setAttribute('aria-busy', 'true');

    if (!button.querySelector('.cf-submit-loading')) {
      button.innerHTML = '<span class="cf-spinner" aria-hidden="true"></span><span>Saving …</span>';
    }

    window.setTimeout(() => {
      button.disabled = true;
    }, 0);
  });
});
