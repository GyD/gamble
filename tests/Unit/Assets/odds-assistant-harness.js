// Minimal DOM harness running the real pricing assistant outside a browser.
//
// It builds the smallest structure the assistant looks for, clicks its button
// and prints the odds it prefilled, so the generation rules can be asserted
// from PHPUnit without any front-end tooling.
//
// Usage: node odds-assistant-harness.js '{"probabilities":[50,50],"margin":10,"min":"1.01"}'

const input = JSON.parse(process.argv[2]);

const listeners = (node) => {
    node.handlers = {};
    node.addEventListener = (event, handler) => {
        node.handlers[event] = handler;
    };

    return node;
};

const oddsInputs = input.probabilities.map(() => listeners({
    min: input.min,
    max: '1000',
    value: '',
    disabled: false,
    dataset: {},
    setCustomValidity: () => {},
    closest: () => null,
}));

const probabilityInputs = input.probabilities.map((value) => ({value: String(value)}));

const marginInput = {value: String(input.margin)};
const applyButton = listeners({});
const result = {textContent: ''};

const assistant = listeners({
    querySelector: (selector) => ({
        // The probability fields are rebuilt from the choices of the form; the
        // harness supplies them directly, so the container is left out.
        '[data-assistant-probabilities]': null,
        '[data-assistant-margin]': marginInput,
        '[data-assistant-apply]': applyButton,
        '[data-assistant-result]': result,
    }[selector] ?? null),
    querySelectorAll: (selector) => (selector === '[data-assistant-probability]' ? probabilityInputs : []),
    closest: () => form,
});

const form = listeners({
    querySelectorAll: (selector) => (selector === '[data-odds-input]' ? oddsInputs : []),
});

globalThis.document = {
    querySelectorAll: (selector) => (selector === '[data-odds-assistant]' ? [assistant] : []),
};

require(input.script);

applyButton.handlers.click();

process.stdout.write(JSON.stringify({
    odds: oddsInputs.map((odds) => odds.value),
    message: result.textContent,
}));
