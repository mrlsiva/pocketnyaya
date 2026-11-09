import './bootstrap';

// Small behaviours kept out of inline attributes, so the Content-Security-Policy can forbid inline scripts.
document.addEventListener('click', (event) => {
    if (event.target instanceof Element && event.target.closest('[data-print]')) {
        window.print();
    }
});

document.addEventListener('change', (event) => {
    const field = event.target;
    if (field instanceof HTMLSelectElement && field.hasAttribute('data-autosubmit') && field.form) {
        field.form.submit();
    }
});
