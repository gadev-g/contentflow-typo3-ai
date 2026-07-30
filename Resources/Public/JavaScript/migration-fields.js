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
    const patterns = new Map();

    targetTypes.forEach((targetType) => {
        (targetType.catalog_patterns || []).forEach((pattern) => {
            patterns.set(pattern.id, {
                ...pattern,
                targetType: targetType.type,
            });
        });
    });
    const quickFields = new Set([
        'header_layout',
        'header_size',
        'header_position',
        'frame_class',
        'space_before_class',
        'space_after_class',
    ]);

    document.querySelectorAll('[data-contentflow-migration-item]').forEach((item) => {
        const itemIndex = item.dataset.contentflowMigrationItem;
        const layoutSelect = item.querySelector('.cf-migration-layout-choice');
        const typeInput = item.querySelector('.cf-migration-target-type');
        const quickFieldsContainer = item.querySelector('[data-contentflow-migration-quick-fields]');
        const advancedFieldsContainer = item.querySelector('[data-contentflow-migration-advanced-fields]');
        const valuesByLayout = new Map();
        let activeLayout = '';
        let activeType = '';

        if (undefined === itemIndex
            || !(layoutSelect instanceof HTMLSelectElement)
            || !(typeInput instanceof HTMLInputElement)
            || !quickFieldsContainer
            || !advancedFieldsContainer) {
            return;
        }

        activeLayout = layoutSelect.value;
        activeType = typeInput.value;

        const collectValues = (layout) => {
            const values = {};

            item.querySelectorAll('[name]').forEach((field) => {
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

            valuesByLayout.set(layout, values);
        };

        const renderFields = () => {
            const pattern = patterns.get(layoutSelect.value);
            const schema = schemas.get(typeInput.value);
            const patternValues = {
                ...(pattern?.field_values || {}),
                ...(pattern?.option_values || {}),
            };
            const values = valuesByLayout.get(layoutSelect.value) || patternValues;
            quickFieldsContainer.replaceChildren();
            advancedFieldsContainer.replaceChildren();

            if (!schema || !Array.isArray(schema.fields)) {
                return;
            }

            schema.fields.forEach((fieldName) => {
                const label = document.createElement('label');
                const title = document.createElement('span');
                const technicalName = document.createElement('small');
                const options = schema.field_options?.[fieldName];
                const defaultValue = schema.field_defaults?.[fieldName];
                const inputName = typeInput.name.replace(
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
                        option.selected = String(values[fieldName] ?? defaultValue ?? '') === value;
                        select.append(option);
                    });

                    label.append(select);
                } else {
                    const textarea = document.createElement('textarea');
                    textarea.name = inputName;
                    textarea.className = 'form-control';
                    textarea.rows = 3;
                    textarea.value = String(values[fieldName] ?? defaultValue ?? '');
                    label.append(textarea);
                }

                (quickFields.has(fieldName) ? quickFieldsContainer : advancedFieldsContainer).append(label);
            });
        };

        collectValues(activeLayout);
        layoutSelect.addEventListener('change', () => {
            collectValues(activeLayout);
            activeLayout = layoutSelect.value;
            activeType = activeLayout.startsWith('type:')
                ? activeLayout.substring(5)
                : (patterns.get(activeLayout)?.targetType || activeType);
            typeInput.value = activeType;
            renderFields();
        });
    });
};

if ('loading' === document.readyState) {
    document.addEventListener('DOMContentLoaded', initializeMigrationFields, {once: true});
} else {
    initializeMigrationFields();
}
