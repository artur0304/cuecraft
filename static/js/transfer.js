// Копируем только IBAN. На обычном HTTP оставляем ручное выделение для телефона.
document.querySelector('#copy-iban').addEventListener('click', async () => {
    const input = document.querySelector('#transfer-iban');
    const status = document.querySelector('#copy-status');
    try {
        await navigator.clipboard.writeText(input.value);
        status.textContent = 'IBAN скопирован.';
    } catch {
        input.focus(); input.select(); input.setSelectionRange(0, input.value.length);
        status.textContent = 'IBAN выделен. Нажмите «Копировать» или Ctrl+C.';
    }
});
