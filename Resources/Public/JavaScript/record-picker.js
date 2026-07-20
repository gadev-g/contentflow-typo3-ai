import Modal from '@typo3/backend/modal.js';
import { MessageUtility } from '@typo3/backend/utility/message-utility.js';

const recordUid = document.querySelector('#uid');
const recordTable = document.querySelector('#table');
const pickerButton = document.querySelector('#contentflow-record-picker');
const selectionText = document.querySelector('#contentflow-record-selection');
const targetLanguage = document.querySelector('#targetLanguage');
const submitButton = document.querySelector('.cf-primary');

const updateSelection = () => {
  if (!recordUid || !recordTable || !selectionText || !submitButton) return;
  selectionText.textContent = recordUid.value
    ? `Selected: ${recordTable.options[recordTable.selectedIndex].text} #${recordUid.value}`
    : 'No record selected yet';
  submitButton.disabled = !recordUid.value;
};

const handleSelection = (event) => {
  if (!MessageUtility.verifyOrigin(event.origin) || event.data?.actionName !== 'typo3:elementBrowser:elementAdded') {
    return;
  }
  const matches = String(event.data.value ?? '').match(/(\d+)(?!.*\d)/);
  if (matches) {
    recordUid.value = matches[1];
    recordUid.dispatchEvent(new Event('change', { bubbles: true }));
    updateSelection();
    Modal.dismiss();
  }
};

pickerButton?.addEventListener('click', () => {
  const table = recordTable.value;
  const isAsset = table === 'sys_file_metadata';
  const mode = isAsset ? 'file' : 'db';
  const allowed = isAsset ? '' : table;
  const bparams = `${recordUid.name}|||${allowed}`;
  const modal = Modal.advanced({
    type: Modal.types.iframe,
    content: `${top.TYPO3.settings.Wizards.elementBrowserUrl}&mode=${mode}&bparams=${encodeURIComponent(bparams)}`,
    size: Modal.sizes.large,
  });
  window.addEventListener('message', handleSelection);
  modal.addEventListener('typo3-modal-hide', () => window.removeEventListener('message', handleSelection), { once: true });
});

recordTable?.addEventListener('change', () => {
  recordUid.value = '';
  pickerButton.textContent = recordTable.value === 'sys_file_metadata' ? 'Select asset' : 'Select record';
  updateSelection();
});
recordUid?.addEventListener('change', updateSelection);

const preventSameLanguage = () => {
  const source = document.querySelector('#sourceLanguage');
  if (!source || !targetLanguage) return;
  for (const option of targetLanguage.options) {
    option.disabled = option.value === source.value;
  }
  if (targetLanguage.selectedOptions[0]?.disabled) {
    targetLanguage.value = [...targetLanguage.options].find((option) => !option.disabled)?.value ?? '';
  }
};
document.querySelector('#sourceLanguage')?.addEventListener('change', preventSameLanguage);
preventSameLanguage();
updateSelection();
