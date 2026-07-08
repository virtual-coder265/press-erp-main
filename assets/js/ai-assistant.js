document.addEventListener('DOMContentLoaded', function () {
    const config = window.PRESS_ERP_AI_ASSISTANT || null;
    if (!config || !config.endpoint || !config.csrfToken) {
        return;
    }

    const root = document.getElementById('aiAssistantRoot');
    const toggle = document.getElementById('aiAssistantToggle');
    const panel = document.getElementById('aiAssistantPanel');
    const closeBtn = document.getElementById('aiAssistantClose');
    const form = document.getElementById('aiAssistantForm');
    const input = document.getElementById('aiAssistantInput');
    const sendBtn = document.getElementById('aiAssistantSend');
    const messages = document.getElementById('aiAssistantMessages');
    const toggleIcon = toggle ? toggle.querySelector('.material-icons') : null;
    let isSubmitting = false;

    if (!root || !toggle || !panel || !closeBtn || !form || !input || !sendBtn || !messages) {
        return;
    }

    function appendMessage(role, text) {
        const item = document.createElement('div');
        item.className = role === 'assistant' ? 'ai-msg ai-msg-assistant' : 'ai-msg ai-msg-user';
        item.textContent = text;
        messages.appendChild(item);
        messages.scrollTop = messages.scrollHeight;
    }

    function setOpen(open) {
        root.classList.toggle('is-open', open);
        panel.classList.toggle('hidden', !open);
        panel.classList.toggle('is-open', open);
        panel.setAttribute('aria-hidden', open ? 'false' : 'true');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Close AI assistant' : 'Open AI assistant');
        if (toggleIcon) {
            toggleIcon.textContent = open ? 'close' : 'auto_awesome';
        }
        if (open) {
            window.setTimeout(function () {
                input.focus();
            }, 50);
        }
    }

    toggle.addEventListener('click', function () {
        const isOpen = !panel.classList.contains('hidden');
        setOpen(!isOpen);
    });

    closeBtn.addEventListener('click', function () {
        setOpen(false);
    });

    document.addEventListener('click', function (event) {
        if (panel.classList.contains('hidden')) {
            return;
        }

        if (!root.contains(event.target)) {
            setOpen(false);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !panel.classList.contains('hidden')) {
            setOpen(false);
        }
    });

    input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.dispatchEvent(new Event('submit', { cancelable: true }));
            }
        }
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (isSubmitting) {
            return;
        }

        const message = String(input.value || '').trim();

        if (!message) {
            return;
        }

        appendMessage('user', message);
        input.value = '';
        isSubmitting = true;
        sendBtn.disabled = true;
        sendBtn.innerHTML = '<i class="material-icons">hourglass_top</i><span>Sending</span>';

        fetch(config.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': config.csrfToken
            },
            body: JSON.stringify({
                message: message
            })
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return { success: false, message: 'Invalid AI response.' };
                });
            })
            .then(function (payload) {
                if (!payload || !payload.success) {
                    appendMessage('assistant', (payload && payload.message) ? payload.message : 'Unable to process request right now.');
                    if (typeof window.showToast === 'function') {
                        window.showToast('AI assistant request failed.', 'error');
                    }
                    return;
                }

                appendMessage('assistant', payload.answer || 'No response.');
            })
            .catch(function () {
                appendMessage('assistant', 'Network error while contacting AI assistant.');
            })
            .finally(function () {
                isSubmitting = false;
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="material-icons">send</i><span>Send</span>';
                input.focus();
            });
    });

    appendMessage('assistant', 'Ask about tasks, projects, reminders, invoices, estimations, sales, or general operations and I will choose the right context.');
});
