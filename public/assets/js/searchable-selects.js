document.querySelectorAll('[data-searchable-select]').forEach((select) => {
    if (typeof window.TomSelect === 'undefined') {
        return;
    }

    new window.TomSelect(select, {
        create: false,
        maxOptions: null,
        placeholder: select.dataset.placeholder,
        render: {
            no_results() {
                const message = document.createElement('div');
                message.className = 'no-results';
                message.textContent = 'Aucun contact trouvé';

                return message;
            },
        },
    });
});