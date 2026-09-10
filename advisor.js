// Диалог хранится только на время страницы; цены получает PHP из базы.
(() => {
    const toggle = document.querySelector('#advisor-toggle');
    const panel = document.querySelector('#advisor-panel');
    const messages = document.querySelector('#advisor-messages');
    const choices = document.querySelector('#advisor-choices');
    const form = document.querySelector('#advisor-form');
    const input = document.querySelector('#advisor-input');
    let busy = false;
    function say(text, customer = false) {
        const p = document.createElement('p'); p.textContent = text;
        p.className = customer ? 'advisor-customer' : 'advisor-answer';
        messages.append(p); messages.scrollTop = messages.scrollHeight;
    }
    function close() { panel.hidden = true; toggle.setAttribute('aria-expanded', 'false'); toggle.focus(); }
    toggle.addEventListener('click', () => {
        panel.hidden = !panel.hidden;
        toggle.setAttribute('aria-expanded', String(!panel.hidden));
        if (!panel.hidden) input.focus();
    });
    document.querySelector('#advisor-close').addEventListener('click', close);
    panel.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
    async function send(message, reset = false) {
        if (busy) return;
        busy = true; form.querySelector('button').disabled = true;
        if (reset) messages.replaceChildren();
        say(message, true); choices.replaceChildren();
        try {
            const response = await fetch(`${apiBasePath}api/advisor`, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({message, reset})});
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Не удалось получить ответ.');
            document.querySelector('#advisor-mode').textContent = result.mode === 'ai' ? 'Локальная нейросеть · рекомендации могут быть неточными' : 'Подбор по каталогу · без нейросети';
            say(result.reply);
            result.products.forEach(product => {
                const button = document.createElement('button'); button.type = 'button';
                button.textContent = `${product.name} · ${formatPrice(product.price_uah)} · ${product.availability === 'preorder' ? 'под заказ' : 'в наличии'}`;
                button.addEventListener('click', () => {
                    const card = Array.from(document.querySelectorAll('.product-card')).find(item => item.dataset.productSlug === product.slug);
                    if (card) { close(); card.querySelector('.details-button').click(); }
                });
                messages.append(button);
            });
            result.choices.forEach(text => {
                const button = document.createElement('button'); button.type = 'button'; button.textContent = text;
                button.addEventListener('click', () => send(text)); choices.append(button);
            });
            messages.scrollTop = messages.scrollHeight;
        } catch (error) { say(error.message + ' Можно отправить вопрос ещё раз.'); }
        finally { busy = false; form.querySelector('button').disabled = false; }
    }
    form.addEventListener('submit', event => { event.preventDefault(); const text = input.value.trim(); if (text && !busy) { input.value = ''; send(text); } });
    document.querySelector('#advisor-reset').addEventListener('click', () => send('Помоги выбрать', true));
    say('Привет! Помогу выбрать товар. Напишите вид игры, опыт и бюджет — или начните с «пул» или «русский бильярд». Не отправляйте сюда реквизиты и личные данные.');
})();
