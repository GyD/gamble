// Shows the payout a fixed odds stake would be worth before it is created, using
// the odds currently offered on the selected choice. Those odds are only quoted:
// the contractual ones are captured when the stake is paid, so the figure shown
// is an estimation, not a debt already contracted.
document.querySelectorAll('[data-stake-payout]').forEach((output) => {
    const form = output.closest('form');
    const optionSelect = form?.querySelector('[data-stake-option]');
    const amountInput = form?.querySelector('[data-stake-amount]');

    if (!optionSelect || !amountInput) {
        return;
    }

    const formatter = new Intl.NumberFormat('fr-FR');

    const refresh = () => {
        const selected = optionSelect.selectedOptions[0];
        const odds = Number(selected?.dataset.odds);
        const amount = Number(amountInput.value);

        if (!Number.isFinite(odds) || odds <= 0 || !Number.isFinite(amount) || amount <= 0) {
            output.textContent = 'Choisissez un choix coté et un montant pour voir le gain estimé.';

            return;
        }

        // Same rounding as the server: a won stake never pays less than itself.
        const payout = Math.max(amount, Math.round(amount * odds));
        output.textContent = `Cote annoncée ${formatter.format(odds)} — gain estimé si encaissée à cette cote et gagnante : ${formatter.format(payout)} $.`;
    };

    optionSelect.addEventListener('change', refresh);
    amountInput.addEventListener('input', refresh);
    refresh();
});
