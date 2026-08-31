document.querySelectorAll('[data-add-option]').forEach((button) => {
    button.addEventListener('click', () => {
        // Looked up from the fieldset so the button can be wrapped for layout.
        const fields = button.closest('fieldset')?.querySelector('[data-option-fields]');

        if (!fields) {
            return;
        }

        const position = fields.children.length + 1;

        if (position > 20) {
            return;
        }

        const field = document.createElement('div');
        field.className = 'form-field';

        const label = document.createElement('label');
        label.htmlFor = `bet-option-${position}`;
        label.textContent = `Choix ${position}`;

        const input = document.createElement('input');
        input.id = label.htmlFor;
        input.name = 'options[]';
        input.type = 'text';
        input.maxLength = 120;
        input.required = true;

        field.append(label, input);

        if (fields.querySelector('[data-odds-input]') !== null) {
            const odds = document.createElement('input');
            odds.className = 'bet-option-odds';
            odds.name = 'odds[]';
            odds.type = 'number';
            odds.min = '1.01';
            odds.max = '1000';
            odds.step = '0.01';
            odds.setAttribute('aria-label', `Cote du choix ${position}`);
            odds.dataset.fixedOddsOnly = '';
            odds.dataset.oddsInput = '';
            field.append(odds);
        }

        fields.append(field);
        input.focus();
        fields.closest('form')?.dispatchEvent(new CustomEvent('bet-options-changed'));

        if (position === 20) {
            button.disabled = true;
        }
    });
});