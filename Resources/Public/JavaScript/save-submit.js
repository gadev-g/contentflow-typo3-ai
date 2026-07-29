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

    /*
     * Keep the submit control enabled while the browser and TYPO3 collect the
     * successful form controls. Disabling it synchronously from the submit
     * event can cancel the request in TYPO3 backend module contexts.
     */
    window.setTimeout(() => {
      button.disabled = true;
    }, 0);
  });
});
