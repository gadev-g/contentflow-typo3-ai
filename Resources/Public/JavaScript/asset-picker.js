import Modal from '@typo3/backend/modal.js';
import { MessageUtility } from '@typo3/backend/utility/message-utility.js';

const fileUids = document.querySelector('#fileUids');
const folderIdentifier = document.querySelector('#folderIdentifier');
const assetPicker = document.querySelector('#contentflow-asset-picker');
const folderPicker = document.querySelector('#contentflow-folder-picker');
const selection = document.querySelector('#contentflow-asset-selection');
const submit = document.querySelector('#contentflow-analyze-submit');
const form = document.querySelector('.cf-asset-form');
const selectedAssets = new Map();
let selectedFolder = '';

const update = () => {
  if (!selection || !submit || !fileUids || !folderIdentifier) return;
  fileUids.value = [...selectedAssets.keys()].join(',');
  folderIdentifier.value = selectedFolder;
  selection.replaceChildren();

  if (selectedFolder) {
    const chip = document.createElement('button');
    chip.type = 'button';
    chip.dataset.folder = selectedFolder;
    const text = document.createElement('span');
    text.textContent = `Folder: ${selectedFolder}`;
    const remove = document.createElement('b');
    remove.textContent = '×';
    remove.setAttribute('aria-label', 'Remove folder');
    chip.append(text, remove);
    selection.append(chip);
  } else if (selectedAssets.size) {
    selectedAssets.forEach((label, uid) => {
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.dataset.uid = uid;
      const text = document.createElement('span');
      text.textContent = label || `Asset #${uid}`;
      const remove = document.createElement('b');
      remove.textContent = '×';
      remove.setAttribute('aria-label', `Remove ${label || `asset ${uid}`}`);
      chip.append(text, remove);
      selection.append(chip);
    });
  } else {
    const empty = document.createElement('span');
    empty.textContent = 'No images selected yet';
    selection.append(empty);
  }

  submit.disabled = !selectedFolder && selectedAssets.size === 0;
  assetPicker?.classList.toggle('is-selected', selectedAssets.size > 0);
  folderPicker?.classList.toggle('is-selected', Boolean(selectedFolder));
};

const openBrowser = (mode, target, handler) => {
  if (!target) return;
  const modal = Modal.advanced({
    type: Modal.types.iframe,
    content: `${top.TYPO3.settings.Wizards.elementBrowserUrl}&mode=${mode}&bparams=${encodeURIComponent(`${target.name}|||`)}`,
    size: Modal.sizes.large,
  });
  window.addEventListener('message', handler);
  modal.addEventListener('typo3-modal-hide', () => window.removeEventListener('message', handler), { once: true });
};

const handleAssetSelection = (event) => {
  if (!MessageUtility.verifyOrigin(event.origin) || event.data?.actionName !== 'typo3:elementBrowser:elementAdded') return;
  const matches = String(event.data.value ?? '').match(/(\d+)(?!.*\d)/);
  if (!matches) return;
  selectedFolder = '';
  selectedAssets.set(matches[1], String(event.data.label ?? `Asset #${matches[1]}`));
  update();
  if (event.source) {
    MessageUtility.send({ actionName: 'typo3:foreignRelation:inserted' }, event.source);
  }
};

const handleFolderSelection = (event) => {
  if (!MessageUtility.verifyOrigin(event.origin) || event.data?.actionName !== 'typo3:elementBrowser:elementAdded') return;
  const identifier = String(event.data.value ?? '').trim();
  if (!identifier) return;
  selectedAssets.clear();
  selectedFolder = identifier;
  update();
};

assetPicker?.addEventListener('click', () => openBrowser('file', fileUids, handleAssetSelection));
folderPicker?.addEventListener('click', () => openBrowser('folder', folderIdentifier, handleFolderSelection));

selection?.addEventListener('click', (event) => {
  const chip = event.target.closest('button');
  if (!chip) return;
  if (chip.dataset.uid) selectedAssets.delete(chip.dataset.uid);
  if (chip.dataset.folder) selectedFolder = '';
  update();
});

form?.addEventListener('submit', (event) => {
  if ((!fileUids?.value && !folderIdentifier?.value) || !submit || submit.dataset.loading === 'true') {
    event.preventDefault();
    return;
  }
  submit.dataset.loading = 'true';
  submit.disabled = true;
  submit.setAttribute('aria-busy', 'true');
  submit.innerHTML = '<span class="cf-spinner" aria-hidden="true"></span><span>Analyzing images …</span>';
});

update();
