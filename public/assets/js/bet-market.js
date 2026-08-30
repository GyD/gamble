document.querySelectorAll('form').forEach((form) => {
    const modeSelect = form.querySelector('[data-betting-mode]');

    if (modeSelect === null) {
        return;
    }

    const presetSelect = form.querySelector('[data-probability-preset]');
    const presetField = form.querySelector('[data-probability-presets]');

    const probabilityInputs = () => Array.from(form.querySelectorAll('[data-probability-input]'));

    const toggleFixedOddsFields = () => {
        const isFixedOdds = modeSelect.value === 'fixed_odds';

        form.querySelectorAll('[data-fixed-odds-only]').forEach((field) => {
            field.hidden = !isFixedOdds;
        });

        form.querySelectorAll('[data-mutuel-only]').forEach((field) => {
            field.hidden = isFixedOdds;
        });

        probabilityInputs().forEach((input) => {
            input.disabled = !isFixedOdds;
        });

        if (presetField !== null) {
            presetField.hidden = !isFixedOdds || probabilityInputs().length !== 2;
        }
    };

    const applyPreset = () => {
        if (presetSelect === null || presetSelect.value === '') {
            return;
        }

        const values = presetSelect.value.split('/');
        const inputs = probabilityInputs();

        if (inputs.length !== values.length) {
            return;
        }

        inputs.forEach((input, index) => {
            input.value = values[index];
        });
    };

    modeSelect.addEventListener('change', toggleFixedOddsFields);
    form.addEventListener('bet-options-changed', toggleFixedOddsFields);

    if (presetSelect !== null) {
        presetSelect.addEventListener('change', applyPreset);
    }

    probabilityInputs().forEach((input) => {
        input.addEventListener('input', () => {
            if (presetSelect !== null) {
                presetSelect.value = '';
            }
        });
    });

    toggleFixedOddsFields();
});
