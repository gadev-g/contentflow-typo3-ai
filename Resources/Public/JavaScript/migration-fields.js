const initializeMigrationFields = () => {
    const schemaElement = document.getElementById('contentflow-migration-target-schema');

    if (!schemaElement) {
        return;
    }

    let targetTypes = [];

    try {
        targetTypes = JSON.parse(schemaElement.textContent || '[]');
    } catch {
        return;
    }

    const schemas = new Map(targetTypes.map((targetType) => [targetType.type, targetType]));

    document.querySelectorAll('[data-contentflow-migration-item]').forEach((item) => {
        const itemIndex = item.dataset.contentflowMigrationItem;
        const typeSelect = item.querySelector('.cf-migration-target-type');
        const fieldsContainer = item.querySelector('[data-contentflow-migration-fields]');
        const valuesByType = new Map();
        let activeType = '';

        if (!itemIndex || !(typeSelect instanceof HTMLSelectElement) || !fieldsContainer) {
            return;
        }

        activeType = typeSelect.value;

        const collectValues = (type) => {
            const values = {};

            fieldsContainer.querySelectorAll('[name]').forEach((field) => {
                if (!(field instanceof HTMLInputElement)
                    && !(field instanceof HTMLTextAreaElement)
                    && !(field instanceof HTMLSelectElement)) {
                    return;
                }

                const match = field.name.match(/\[fields]\[([^\]]+)]$/);

                if (match) {
                    values[match[1]] = field.value;
                }
            });

            valuesByType.set(type, values);
        };

        const renderFields = () => {
            const schema = schemas.get(typeSelect.value);
            const values = valuesByType.get(typeSelect.value) || {};
            fieldsContainer.replaceChildren();

            if (!schema || !Array.isArray(schema.fields)) {
                return;
            }

            schema.fields.forEach((fieldName) => {
                const label = document.createElement('label');
                const title = document.createElement('span');
                const technicalName = document.createElement('small');
                const options = schema.field_options?.[fieldName];
                const inputName = typeSelect.name.replace(
                    /\[target_type]$/,
                    `[fields][${fieldName}]`,
                );

                title.textContent = schema.field_labels?.[fieldName] || fieldName;
                technicalName.textContent = fieldName;
                title.append(technicalName);
                label.append(title);

                if (options && Object.keys(options).length > 0) {
                    const select = document.createElement('select');
                    const defaultOption = document.createElement('option');
                    defaultOption.value = '';
                    defaultOption.textContent = 'Use TYPO3 default';
                    select.name = inputName;
                    select.className = 'form-select';
                    select.append(defaultOption);

                    Object.entries(options).forEach(([value, optionLabel]) => {
                        const option = document.createElement('option');
                        option.value = value;
                        option.textContent = optionLabel;
                        option.selected = String(values[fieldName] ?? '') === value;
                        select.append(option);
                    });

                    label.append(select);
                } else {
                    const textarea = document.createElement('textarea');
                    textarea.name = inputName;
                    textarea.className = 'form-control';
                    textarea.rows = 3;
                    textarea.value = String(values[fieldName] ?? '');
                    label.append(textarea);
                }

                fieldsContainer.append(label);
            });
        };

        collectValues(activeType);
        typeSelect.addEventListener('change', () => {
            collectValues(activeType);
            activeType = typeSelect.value;
            renderFields();
        });
    });
};

if ('loading' === document.readyState) {
    document.addEventListener('DOMContentLoaded', initializeMigrationFields, {once: true});
} else {
    initializeMigrationFields();
}
