(function () {
    'use strict';
    if (window.Shopkeeper4Bound) return;
    window.Shopkeeper4Bound = true;
    var busy = false;
    function payload(form) {
        var data = {};
        new FormData(form).forEach(function (value, name) {
            var match = name.match(/^selected\[([^\]]+)\]$/);
            if (match) { data.selected = data.selected || {}; data.selected[match[1]] = value; }
            else data[name] = value;
        });
        return data;
    }
    async function send(data, form) {
        if (busy) return;
        busy = true;
        var buttons = form.querySelectorAll('button');
        buttons.forEach(function (button) { button.disabled = true; });
        try {
            var response = await fetch(window.Shopkeeper4Web.url, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data)});
            var result = await response.json();
            if (!result.success) throw new Error(result.message || 'Не удалось выполнить запрос.');
            document.querySelectorAll('[data-sk4-cart]').forEach(function (node) { node.innerHTML = result.html; });
            document.querySelectorAll('[data-sk4-compact]').forEach(function (node) { node.innerHTML = result.summary; });
            document.querySelectorAll('[name=csrf]').forEach(function (node) { node.value = node.defaultValue = result.csrf; });
            document.querySelectorAll('[name=checkout_token]').forEach(function (node) { node.value = node.defaultValue = result.checkout_token; });
            document.querySelectorAll('[data-sk4-message]').forEach(function (node) { node.textContent = result.object.id ? 'Заказ №' + result.object.id + ' принят.' : 'Корзина обновлена.'; });
            if (result.object.id) form.querySelectorAll('input:not([type=hidden]),textarea').forEach(function(node){node.value='';});
            document.dispatchEvent(new CustomEvent('shopkeeper4:updated', {detail:result}));
        } catch (error) {
            var message = form.querySelector('[data-sk4-message]') || document.querySelector('[data-sk4-message]');
            if (message) message.textContent = error.message; else window.alert(error.message);
        } finally { busy = false; buttons.forEach(function (button) { button.disabled = false; }); }
    }
    document.addEventListener('submit', function (event) {
        if (!event.target.matches('[data-sk4-form]') || !window.Shopkeeper4Web) return;
        event.preventDefault();send(payload(event.target),event.target);
    });
    document.addEventListener('change', function (event) {
        if (!event.target.matches('[data-sk4-preference]') || !window.Shopkeeper4Web || event.target.name === 'payment_id') return;
        var form=event.target.closest('form'); if(!form) return;
        var data=payload(form);data.sk4_action='preferences';send(data,form);
    });
}());
