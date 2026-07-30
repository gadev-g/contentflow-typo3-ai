import Modal from '@typo3/backend/modal.js';
import { MessageUtility } from '@typo3/backend/utility/message-utility.js';

const buttons = document.querySelectorAll('[data-contentflow-page-picker]');

const pageUid = (value) => {
  const match = String(value ?? '').match(/(?:uid=|pages_)?(\d+)(?!.*\d)/);

  return match?.[1] ?? '';
};

buttons.forEach((button) => {
  button.addEventListener('click', () => {
    const input = document.querySelector(`#${button.dataset.contentflowPagePicker}`);

    if (!input) {
      return;
    }

    const handleSelection = (event) => {
      if (
        !MessageUtility.verifyOrigin(event.origin)
        || event.data?.actionName !== 'typo3:elementBrowser:elementAdded'
      ) {
        return;
      }

      const selectedUid = pageUid(event.data.value);

      if (!selectedUid) {
        return;
      }

      input.value = selectedUid;
      input.dispatchEvent(new Event('change', { bubbles: true }));
      Modal.dismiss();
    };

    const browserParameters = `${input.name}|||pages`;
    const modal = Modal.advanced({
      type: Modal.types.iframe,
      content: `${top.TYPO3.settings.Wizards.elementBrowserUrl}&mode=db&bparams=${encodeURIComponent(browserParameters)}`,
      size: Modal.sizes.large,
    });

    window.addEventListener('message', handleSelection);
    modal.addEventListener(
      'typo3-modal-hide',
      () => window.removeEventListener('message', handleSelection),
      { once: true },
    );
  });
});
