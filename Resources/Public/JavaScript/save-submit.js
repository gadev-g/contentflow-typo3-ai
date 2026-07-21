document.querySelectorAll('.cf-approve-form').forEach((form) => {
  form.addEventListener('submit', (event) => {
    const button = form.querySelector('.cf-approve-submit');
    if (!button || button.dataset.loading === 'true') {
      event.preventDefault();
      return;
    }
    button.dataset.loading = 'true';
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.innerHTML = '<span class="cf-spinner" aria-hidden="true"></span><span>Saving …</span>';
  });
});
