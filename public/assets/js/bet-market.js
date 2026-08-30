document.querySelectorAll('form').forEach((form) => {
    const modeSelect = form.querySelector('[data-betting-mode]');

    if (modeSelect === null) {
        return;
    }

    const distributeButton = form.querySelector('[data-distribute-probabilities]');

    const probabilityInputs = () => Array.from(form.querySelectorAll('[data-probability-input]'));
    const isFilled = (input) => input.value.trim() !== '';

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
            input.setCustomValidity('');
        });
    };

    // Fills only the empty inputs, sharing whatever percentage is left by the
    // ones already filled in. Works in hundredths so the total stays exactly 100.
    const distributeProbabilities = () => {
        const inputs = probabilityInputs();
        if (inputs.length === 0) {
            return;
        }

        const empty = inputs.filter((input) => !isFilled(input));
        if (empty.length === 0) {
            return;
        }

        const used = inputs
            .filter(isFilled)
            .reduce((total, input) => total + Math.round(Number(input.value.replace(',', '.')) * 100), 0);
        const available = 10000 - used;

        // Every option needs at least 0.01% to stay a valid probability.
        if (!Number.isFinite(available) || available < empty.length) {
            empty[0].setCustomValidity('Les probabilités déjà saisies atteignent 100 %. Réduisez-les avant de répartir le reste.');
            empty[0].reportValidity();

            return;
        }

        const share = Math.floor(available / empty.length);
        let remainder = available - (share * empty.length);

        empty.forEach((input) => {
            const value = share + (remainder > 0 ? 1 : 0);
            remainder -= remainder > 0 ? 1 : 0;
            input.value = (value / 100).toFixed(2);
            input.setCustomValidity('');
        });
    };

    // The server accepts either no probability at all (options are then
    // equiprobable) or one per option, but rejects a partial submission.
    const validateProbabilities = (event) => {
        const inputs = probabilityInputs().filter((input) => !input.disabled);
        inputs.forEach((input) => input.setCustomValidity(''));

        const filled = inputs.filter(isFilled);
        if (filled.length === 0 || filled.length === inputs.length) {
            return;
        }

        const firstEmpty = inputs.find((input) => !isFilled(input));
        firstEmpty.setCustomValidity('Renseignez la probabilité de chaque choix, ou laissez-les toutes vides pour des choix équiprobables.');
        firstEmpty.reportValidity();
        event.preventDefault();
    };

    modeSelect.addEventListener('change', toggleFixedOddsFields);
    form.addEventListener('bet-options-changed', toggleFixedOddsFields);
    form.addEventListener('submit', validateProbabilities);

    if (distributeButton !== null) {
        distributeButton.addEventListener('click', distributeProbabilities);
    }

    // Delegated so dynamically added options are covered too.
    form.addEventListener('input', (event) => {
        if (event.target.matches('[data-probability-input]')) {
            event.target.setCustomValidity('');
        }
    });

    toggleFixedOddsFields();
});
