import Modal from '@typo3/backend/modal.js';
import { MessageUtility } from '@typo3/backend/utility/message-utility.js';

const recordUid = document.querySelector('#uid');
const recordUids = document.querySelector('#uids');
const recordTable = document.querySelector('#table');
const scope = document.querySelector('#scope');
const pickerButton = document.querySelector('#contentflow-record-picker');
const selectionText = document.querySelector('#contentflow-record-selection');
const selectionList = document.querySelector('#contentflow-record-list');
const targetLanguage = document.querySelector('#targetLanguage');
const submitButton = document.querySelector('#contentflow-translation-submit');
const form = document.querySelector('.cf-translation-form');
const selectedUids = new Set((recordUids?.value ?? '').split(',').filter(Boolean));

const currentScope = () => scope?.value ?? 'single';

const renderSelection = () => {
  if (!recordUid || !recordUids || !recordTable || !selectionText || !submitButton) return;
  const mode = currentScope();
  const isMultiple = mode === 'multiple';
  recordUids.value = [...selectedUids].join(',');
  const hasSelection = isMultiple ? selectedUids.size > 0 : Boolean(recordUid.value);
  selectionText.textContent = isMultiple
    ? selectedUids.size > 0
      ? `${selectedUids.size} content element(s) selected`
      : 'No content elements selected yet'
    : recordUid.value
      ? `Selected: ${recordTable.options[recordTable.selectedIndex].text} #${recordUid.value}`
      : mode === 'page'
        ? 'No page selected yet'
        : 'No record selected yet';
  submitButton.disabled = !hasSelection;
  if (selectionList) {
    selectionList.innerHTML = isMultiple
      ? [...selectedUids].map((uid) => `<button type="button" data-remove-uid="${uid}"><span>Content #${uid}</span><b aria-label="Remove">×</b></button>`).join('')
      : '';
  }
};

const resetSelection = () => {
  if (recordUid) recordUid.value = '';
  selectedUids.clear();
  renderSelection();
};

const applyScope = () => {
  if (!recordTable || !pickerButton) return;
  const mode = currentScope();
  if (mode === 'multiple') {
    recordTable.value = 'tt_content';
    pickerButton.textContent = 'Add content element';
  } else if (mode === 'page') {
    recordTable.value = 'pages';
    pickerButton.textContent = 'Select page';
  } else {
    pickerButton.textContent = recordTable.value === 'sys_file_metadata' ? 'Select asset' : 'Select record';
  }
  recordTable.disabled = mode !== 'single';
  renderSelection();
};

const handleSelection = (event) => {
  if (!MessageUtility.verifyOrigin(event.origin) || event.data?.actionName !== 'typo3:elementBrowser:elementAdded') return;
  const matches = String(event.data.value ?? '').match(/(\d+)(?!.*\d)/);
  if (!matches || !recordUid) return;
  const uid = matches[1];
  recordUid.value = uid;
  if (currentScope() === 'multiple') selectedUids.add(uid);
  renderSelection();
  Modal.dismiss();
};

pickerButton?.addEventListener('click', () => {
  if (!recordTable || !recordUid) return;
  const table = currentScope() === 'multiple' ? 'tt_content' : currentScope() === 'page' ? 'pages' : recordTable.value;
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

selectionList?.addEventListener('click', (event) => {
  const button = event.target.closest('[data-remove-uid]');
  if (!button) return;
  selectedUids.delete(button.dataset.removeUid);
  renderSelection();
});

scope?.addEventListener('change', () => {
  resetSelection();
  applyScope();
});
recordTable?.addEventListener('change', () => {
  if (currentScope() !== 'single') return;
  resetSelection();
  applyScope();
});

const preventSameLanguage = () => {
  const source = document.querySelector('#sourceLanguage');
  if (!source || !targetLanguage) return;
  for (const option of targetLanguage.options) option.disabled = option.value === source.value;
  if (targetLanguage.selectedOptions[0]?.disabled) {
    targetLanguage.value = [...targetLanguage.options].find((option) => !option.disabled)?.value ?? '';
  }
};
document.querySelector('#sourceLanguage')?.addEventListener('change', preventSameLanguage);

form?.addEventListener('submit', (event) => {
  if (!submitButton) {
    event.preventDefault();
    return;
  }
  recordTable.disabled = false;
  submitButton.dataset.loading = 'true';
  submitButton.disabled = true;
  submitButton.setAttribute('aria-busy', 'true');
});

preventSameLanguage();
applyScope();
