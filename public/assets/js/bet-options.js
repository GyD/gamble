document.querySelectorAll('[data-add-option]').forEach((button) => {
    button.addEventListener('click', () => {
        const fields = button.parentElement.querySelector('[data-option-fields]');
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

        if (fields.querySelector('[data-probability-input]') !== null) {
            const probability = document.createElement('input');
            probability.className = 'bet-option-probability';
            probability.name = 'probabilities[]';
            probability.type = 'number';
            probability.min = '0.01';
            probability.max = '99.99';
            probability.step = '0.01';
            probability.setAttribute('aria-label', `Probabilité du choix ${position} en %`);
            probability.dataset.fixedOddsOnly = '';
            probability.dataset.probabilityInput = '';
            field.append(probability);
        }

        fields.append(field);
        input.focus();
        fields.closest('form')?.dispatchEvent(new CustomEvent('bet-options-changed'));

        if (position === 20) {
            button.disabled = true;
        }
    });
});