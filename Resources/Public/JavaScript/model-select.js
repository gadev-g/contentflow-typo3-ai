const providerSelect = document.querySelector('select[name="provider"]');
const modelSelect = document.querySelector('select[name="model"].contentflow-model-select');

if (providerSelect && modelSelect) {
    const modelOptions = Array.from(modelSelect.options).map((option) => ({
        value: option.value,
        label: option.textContent,
        provider: option.dataset.provider || '',
    }));

    const refreshModels = () => {
        const previousValue = modelSelect.value;
        const available = modelOptions.filter(
            (option) => option.value === '' || option.provider === providerSelect.value,
        );

        modelSelect.replaceChildren(...available.map((model) => {
            const option = document.createElement('option');
            option.value = model.value;
            option.textContent = model.label;
            if (model.provider) {
                option.dataset.provider = model.provider;
            }

            return option;
        }));
        modelSelect.value = available.some((option) => option.value === previousValue)
            ? previousValue
            : '';
        modelSelect.disabled = available.length <= 1;
    };

    providerSelect.addEventListener('change', refreshModels);
    refreshModels();
}
