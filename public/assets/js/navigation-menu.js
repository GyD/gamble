const navigationMenu = document.querySelector('.navigation-menu');
const navigationToggle = navigationMenu?.querySelector('.navigation-toggle');

if (navigationMenu && navigationToggle) {
    navigationMenu.classList.add('is-enabled');

    navigationToggle.addEventListener('click', () => {
        const isOpen = navigationMenu.classList.toggle('is-open');

        navigationToggle.setAttribute('aria-expanded', String(isOpen));
    });

    navigationMenu.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !navigationMenu.classList.contains('is-open')) {
            return;
        }

        navigationMenu.classList.remove('is-open');
        navigationToggle.setAttribute('aria-expanded', 'false');
        navigationToggle.focus();
    });
}