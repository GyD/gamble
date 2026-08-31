document.querySelectorAll('form').forEach((form) => {
    const modeSelect = form.querySelector('[data-betting-mode]');

    if (modeSelect === null) {
        return;
    }

    const evolutionSelect = form.querySelector('[data-odds-evolution-mode]');

    const oddsInputs = () => Array.from(form.querySelectorAll('[data-odds-input]'));
    const isFilled = (input) => input.value.trim() !== '';

    // Only the explanation of the selected evolution mode stays visible.
    const toggleOddsEvolutionHelp = () => {
        if (evolutionSelect === null) {
            return;
        }

        form.querySelectorAll('[data-odds-evolution-help]').forEach((help) => {
            help.hidden = help.dataset.oddsEvolutionHelp !== evolutionSelect.value;
        });
    };

    // Odds are typed by the bookmaker and only exist in fixed odds mode; the
    // mutuel commission is the other way around.
    const toggleFixedOddsFields = () => {
        const isFixedOdds = modeSelect.value === 'fixed_odds';

        form.querySelectorAll('[data-fixed-odds-only]').forEach((field) => {
            field.hidden = !isFixedOdds;
        });

        form.querySelectorAll('[data-mutuel-only]').forEach((field) => {
            field.hidden = isFixedOdds;
            field.querySelectorAll('input, select').forEach((input) => {
                input.disabled = isFixedOdds;
            });
        });

        oddsInputs().forEach((input) => {
            input.disabled = !isFixedOdds;
            input.setCustomValidity('');
        });
    };

    // The server accepts either no odds at all (the book stays unpriced) or one
    // per choice, but rejects a partial submission.
    const validateOdds = (event) => {
        const inputs = oddsInputs().filter((input) => !input.disabled);
        inputs.forEach((input) => input.setCustomValidity(''));

        const filled = inputs.filter(isFilled);
        if (filled.length === 0 || filled.length === inputs.length) {
            return;
        }

        const firstEmpty = inputs.find((input) => !isFilled(input));
        firstEmpty.setCustomValidity('Renseignez la cote de chaque choix, ou laissez-les toutes vides pour coter plus tard.');
        firstEmpty.reportValidity();
        event.preventDefault();
    };

    modeSelect.addEventListener('change', toggleFixedOddsFields);
    form.addEventListener('bet-options-changed', toggleFixedOddsFields);
    form.addEventListener('submit', validateOdds);

    if (evolutionSelect !== null) {
        evolutionSelect.addEventListener('change', toggleOddsEvolutionHelp);
    }

    // Delegated so dynamically added options are covered too.
    form.addEventListener('input', (event) => {
        if (event.target.matches('[data-odds-input]')) {
            event.target.setCustomValidity('');
        }
    });

    toggleFixedOddsFields();
    toggleOddsEvolutionHelp();
});
