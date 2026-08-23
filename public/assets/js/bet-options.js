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
        fields.append(field);
        input.focus();

        if (position === 20) {
            button.disabled = true;
        }
    });
});