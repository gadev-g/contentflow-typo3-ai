import Modal from '@typo3/backend/modal.js';
import { MessageUtility } from '@typo3/backend/utility/message-utility.js';

const fileUid = document.querySelector('#fileUid');
const picker = document.querySelector('#contentflow-asset-picker');
const selection = document.querySelector('#contentflow-asset-selection');
const submit = document.querySelector('.cf-primary');
const form = document.querySelector('.cf-asset-form');

const update = () => {
  if (!fileUid || !selection || !submit) return;
  selection.textContent = fileUid.value ? `Selected asset #${fileUid.value}` : 'No image selected yet';
  submit.disabled = !fileUid.value;
  picker?.classList.toggle('is-selected', Boolean(fileUid.value));
};

const handleSelection = (event) => {
  if (!MessageUtility.verifyOrigin(event.origin) || event.data?.actionName !== 'typo3:elementBrowser:elementAdded') return;
  const matches = String(event.data.value ?? '').match(/(\d+)(?!.*\d)/);
  if (!matches || !fileUid) return;
  fileUid.value = matches[1];
  update();
  Modal.dismiss();
};

picker?.addEventListener('click', () => {
  const modal = Modal.advanced({
    type: Modal.types.iframe,
    content: `${top.TYPO3.settings.Wizards.elementBrowserUrl}&mode=file&bparams=${encodeURIComponent(`${fileUid.name}|||`)}`,
    size: Modal.sizes.large,
  });
  window.addEventListener('message', handleSelection);
  modal.addEventListener('typo3-modal-hide', () => window.removeEventListener('message', handleSelection), { once: true });
});

form?.addEventListener('submit', (event) => {
  if (!fileUid?.value || !submit) {
    event.preventDefault();
    return;
  }
  if (submit.dataset.loading === 'true') {
    event.preventDefault();
    return;
  }
  submit.dataset.loading = 'true';
  submit.disabled = true;
  submit.setAttribute('aria-busy', 'true');
  submit.innerHTML = '<span class="cf-spinner" aria-hidden="true"></span><span>Analyzing image …</span>';
});

update();
