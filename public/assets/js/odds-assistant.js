// Optional pricing assistant: it only prefills the odds inputs of its own form.
// Probabilities are a typing tool, never persisted, and the target margin is not
// a business parameter of the bet: only the generated odds are submitted.
document.querySelectorAll('[data-odds-assistant]').forEach((assistant) => {
    const form = assistant.closest('form');
    const probabilityFields = assistant.querySelector('[data-assistant-probabilities]');
    const marginInput = assistant.querySelector('[data-assistant-margin]');
    const applyButton = assistant.querySelector('[data-assistant-apply]');
    const result = assistant.querySelector('[data-assistant-result]');

    if (!form || !marginInput || !applyButton) {
        return;
    }

    const formatter = new Intl.NumberFormat('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    const oddsInputs = () => Array.from(form.querySelectorAll('[data-odds-input]')).filter((input) => !input.disabled);

    // The label of a choice lives next to its odds input: a text field on the bet
    // form, a row header on the pricing page.
    const labelOf = (input, index) => {
        const typed = input.closest('.form-field')?.querySelector('input[name="options[]"]')?.value.trim();
        const header = input.closest('tr')?.querySelector('th')?.textContent.trim();

        return typed || header || `Choix ${index + 1}`;
    };

    // One probability field per choice, whatever their number: the assistant
    // follows the choices of the form, which may still be typed.
    const refreshChoices = () => {
        if (probabilityFields === null) {
            return;
        }

        const labels = oddsInputs().map(labelOf);
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
    };

    // The probabilities are typed by the bookmaker, one per choice, and are only
    // normalised at computation time: no predefined distribution is assumed.
    const probabilities = (count) => Array.from(assistant.querySelectorAll('[data-assistant-probability]'))
        .slice(0, count)
        .map((input) => Number(input.value));

    // The bounds are carried by the odds inputs themselves, whose `min` is
    // server-rendered from the configured `minimum_odds`: the assistant never
    // hardcodes a value the form would not accept.
    const boundsOf = (inputs) => inputs.reduce((bounds, input) => {
        const min = Number(input.min);
        const max = Number(input.max);

        return {
            floor: Number.isFinite(min) && min > bounds.floor ? min : bounds.floor,
            ceiling: Number.isFinite(max) && max < bounds.ceiling ? max : bounds.ceiling,
        };
    }, {floor: 0, ceiling: Infinity});

    // cote = 1 / (probabilite_normalisee x (1 + marge_cible))
    const oddsFrom = (values, margin, bounds) => {
        const total = values.reduce((sum, value) => sum + value, 0);

        // Generated odds obey exactly the same constraints as typed ones: a
        // near-certain or a near-impossible choice would otherwise be priced
        // outside the range the form accepts.
        return values.map((value) => Math.min(
            bounds.ceiling,
            Math.max(
                bounds.floor,
                Math.round((1 / ((value / total) * (1 + margin))) * 100) / 100,
            ),
        ));
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

        const odds = oddsFrom(values, margin, boundsOf(inputs));
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

    applyButton.addEventListener('click', apply);
    assistant.addEventListener('toggle', refreshChoices);
    // The bet form adds and removes choices on the fly: follow them.
    form.addEventListener('bet-options-changed', refreshChoices);
    form.addEventListener('input', (event) => {
        if (event.target.matches('input[name="options[]"]')) {
            refreshChoices();
        }
    });

    refreshChoices();
});
