// Optional pricing assistant: it only prefills the odds inputs of its own form.
// Probabilities are a typing tool, never persisted, and the target margin is not
// a business parameter of the bet: only the generated odds are submitted.
document.querySelectorAll('[data-odds-assistant]').forEach((assistant) => {
    const form = assistant.closest('form');
    const favouriteSelect = assistant.querySelector('[data-assistant-favourite]');
    const levelSelect = assistant.querySelector('[data-assistant-level]');
    const customField = assistant.querySelector('[data-assistant-custom]');
    const probabilityFields = assistant.querySelector('[data-assistant-probabilities]');
    const marginInput = assistant.querySelector('[data-assistant-margin]');
    const applyButton = assistant.querySelector('[data-assistant-apply]');
    const result = assistant.querySelector('[data-assistant-result]');

    if (!form || !levelSelect || !marginInput || !applyButton) {
        return;
    }

    const formatter = new Intl.NumberFormat('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    const oddsInputs = () => Array.from(form.querySelectorAll('[data-odds-input]')).filter((input) => !input.disabled);
    const isCustom = () => levelSelect.value === 'custom';

    // The label of a choice lives next to its odds input: a text field on the bet
    // form, a row header on the pricing page.
    const labelOf = (input, index) => {
        const typed = input.closest('.form-field')?.querySelector('input[name="options[]"]')?.value.trim();
        const header = input.closest('tr')?.querySelector('th')?.textContent.trim();

        return typed || header || `Choix ${index + 1}`;
    };

    // Both lists follow the choices of the form, which may still be typed.
    const refreshChoices = () => {
        const labels = oddsInputs().map(labelOf);

        if (favouriteSelect !== null) {
            const selected = favouriteSelect.value;
            favouriteSelect.replaceChildren(...labels.map((label, index) => {
                const option = document.createElement('option');
                option.value = String(index);
                option.textContent = label;

                return option;
            }));
            favouriteSelect.value = selected;
            favouriteSelect.disabled = labels.length !== 2;
        }

        if (probabilityFields !== null) {
            const typed = Array.from(probabilityFields.querySelectorAll('[data-assistant-probability]'))
                .map((input) => input.value);
            probabilityFields.replaceChildren(...labels.map((label, index) => {
                const field = document.createElement('label');
                field.className = 'odds-assistant-probability';

                const name = document.createElement('span');
                name.textContent = label;

                const input = document.createElement('input');
                input.type = 'number';
                input.min = '0.01';
                input.max = '100';
                input.step = '0.01';
                input.value = typed[index] ?? '';
                input.dataset.assistantProbability = '';
                input.setAttribute('aria-label', `Probabilité du choix ${label}`);

                field.append(name, input);

                return field;
            }));
        }
    };

    // Only a two-choice book can be described by a favourite and an advantage
    // level. Beyond that, choices are equally likely unless typed by hand.
    const probabilities = (count) => {
        if (isCustom()) {
            return Array.from(assistant.querySelectorAll('[data-assistant-probability]'))
                .slice(0, count)
                .map((input) => Number(input.value));
        }

        if (count !== 2) {
            return Array.from({length: count}, () => 1);
        }

        const favourite = Number(levelSelect.value);
        const favouriteIndex = Number(favouriteSelect?.value || 0);

        return favouriteIndex === 0 ? [favourite, 100 - favourite] : [100 - favourite, favourite];
    };

    // cote = 1 / (probabilite_normalisee x (1 + marge_cible))
    const oddsFrom = (values, margin) => {
        const total = values.reduce((sum, value) => sum + value, 0);

        return values.map((value) => Math.round((1 / ((value / total) * (1 + margin))) * 100) / 100);
    };

    const toggleCustom = () => {
        if (customField !== null) {
            customField.hidden = !isCustom();
        }
    };

    const apply = () => {
        const inputs = oddsInputs();
        const margin = Number(marginInput.value) / 100;

        if (inputs.length < 2 || !Number.isFinite(margin) || margin < 0) {
            return;
        }

        const values = probabilities(inputs.length);
        if (values.some((value) => !Number.isFinite(value) || value <= 0)) {
            if (result !== null) {
                result.textContent = 'Renseignez une probabilité strictement positive pour chaque choix.';
            }

            return;
        }

        const odds = oddsFrom(values, margin);
        inputs.forEach((input, index) => {
            input.value = odds[index].toFixed(2);
            input.setCustomValidity('');
        });

        if (result !== null) {
            // The margin actually carried by the generated odds: rounding makes
            // it differ slightly from the target one.
            const realMargin = odds.reduce((sum, value) => sum + (1 / value), 0) - 1;
            result.textContent = `Marge réellement portée par les cotes générées : ${formatter.format(realMargin * 100)} %.`;
        }
    };

    levelSelect.addEventListener('change', toggleCustom);
    applyButton.addEventListener('click', apply);
    assistant.addEventListener('toggle', refreshChoices);
    // The bet form adds and removes choices on the fly: follow them.
    form.addEventListener('bet-options-changed', refreshChoices);
    form.addEventListener('input', (event) => {
        if (event.target.matches('input[name="options[]"]')) {
            refreshChoices();
        }
    });

    toggleCustom();
    refreshChoices();
});
