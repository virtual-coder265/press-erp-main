(function (global) {
    'use strict';

    var config = global.PRESS_ERP_ACTION_MODALS || {};
    var currentType = null;
    var actionMeta = {
        'reminder.create': {
            title: 'Reminder',
            subtitle: 'Quickly schedule a personal reminder or TODO item without leaving the current workspace.',
            kicker: 'Calendar action',
            icon: 'alarm_add',
            success: 'Reminder created'
        },
        'task.create': {
            title: 'New task',
            subtitle: 'Create a trackable task and keep your current dashboard context.',
            kicker: 'Workspace action',
            icon: 'playlist_add',
            success: 'Task created'
        },
        'project.create': {
            title: 'New project',
            subtitle: 'Open a delivery workspace with the core project details.',
            kicker: 'Workspace action',
            icon: 'create_new_folder',
            success: 'Project created'
        }
    };

    function qs(selector, root) {
        return (root || document).querySelector(selector);
    }

    function qsa(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function modal() {
        return qs('#globalActionModal');
    }

    function setText(id, value) {
        var el = qs('#' + id);
        if (el) el.textContent = value || '';
    }

    function setIcon(icon) {
        var el = qs('#globalActionModalIcon i');
        if (el) el.textContent = icon || 'bolt';
    }

    function setAlert(message, type) {
        var alert = qs('#globalActionModalAlert');
        if (!alert) return;
        alert.textContent = message || '';
        alert.classList.toggle('hidden', !message);
        alert.classList.toggle('is-error', type === 'error');
        alert.classList.toggle('is-success', type === 'success');
    }

    function setBody(html) {
        var body = qs('#globalActionModalBody');
        if (body) body.innerHTML = html;
    }

    function loadingMarkup() {
        return '<div class="action-modal-loading"><span class="action-modal-spinner" aria-hidden="true"></span><span>Preparing action...</span></div>';
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function absoluteUrl(url) {
        if (!url) return '#';
        try {
            return new URL(url, config.baseUrl || global.location.href).toString();
        } catch (err) {
            return url;
        }
    }

    function openModalElement() {
        var el = modal();
        if (!el) return;
        if (typeof global.openWorkspaceModal === 'function') {
            global.openWorkspaceModal('globalActionModal');
        } else {
            el.classList.add('is-active');
            el.setAttribute('aria-hidden', 'false');
            document.body.classList.add('ws-modal-open');
        }
    }

    function closeModalElement() {
        if (typeof global.closeWorkspaceModal === 'function') {
            global.closeWorkspaceModal('globalActionModal');
            return;
        }
        var el = modal();
        if (!el) return;
        el.classList.remove('is-active');
        el.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ws-modal-open');
    }

    function initEnhancements(root) {
        var scope = root || document;
        if (global.PressErpNativeDateRange && typeof global.PressErpNativeDateRange.init === 'function') {
            global.PressErpNativeDateRange.init(scope);
        }
        if (global.PressErpDateTimePicker && typeof global.PressErpDateTimePicker.init === 'function') {
            global.PressErpDateTimePicker.init(scope);
        }
    }

    function successMarkup(payload, meta) {
        var openUrl = absoluteUrl(payload.open_url);
        var title = payload.title || meta.success || 'Action completed';
        return [
            '<div class="action-modal-success">',
            '<div class="action-modal-success-icon"><i class="material-icons">check_circle</i></div>',
            '<h4>' + escapeHtml(title) + '</h4>',
            '<p>Your work was saved. You can keep working here or open the created item in its full workspace.</p>',
            '<div class="action-modal-success-actions">',
            '<button type="button" class="todo-btn-ghost" data-ws-close>Close</button>',
            '<a class="todo-btn-primary" href="' + escapeHtml(openUrl) + '"><i class="material-icons text-sm">open_in_new</i><span>Open created item</span></a>',
            '</div>',
            '</div>'
        ].join('');
    }

    function parseResponse(response) {
        return response.json().catch(function () {
            return { ok: false, error: 'Unexpected server response.' };
        }).then(function (payload) {
            if (!response.ok || !payload.ok) {
                throw new Error(payload.error || 'Unable to complete this action.');
            }
            return payload;
        });
    }

    function submitForm(form) {
        var submit = qs('[type="submit"]', form);
        var originalText = submit ? submit.innerHTML : '';
        setAlert('', '');
        if (submit) {
            submit.disabled = true;
            submit.innerHTML = '<span class="action-modal-spinner is-small" aria-hidden="true"></span><span>Saving...</span>';
        }

        fetch(form.action, {
            method: form.method || 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(parseResponse)
        .then(function (payload) {
            var meta = actionMeta[currentType] || {};
            setAlert('', '');
            setBody(successMarkup(payload, meta));
            if (typeof global.showToast === 'function') {
                global.showToast(payload.message || meta.success || 'Action completed', 'success');
            }
            if (currentType) {
                document.dispatchEvent(new CustomEvent('ajax:invalidate', {
                    detail: { actions: [currentType], scopes: [] }
                }));
            }
        })
        .catch(function (err) {
            setAlert(err.message || 'Unable to complete this action.', 'error');
        })
        .finally(function () {
            if (submit) {
                submit.disabled = false;
                submit.innerHTML = originalText;
            }
        });
    }

    function openActionModal(type, options) {
        var endpoint = config.endpoints && config.endpoints[type];
        var meta = actionMeta[type] || {};
        currentType = type;
        setText('globalActionModalTitle', type === 'reminder.create' && options && options.id ? 'Reminder details' : (meta.title || 'Quick action'));
        setText('globalActionModalSubtitle', meta.subtitle || 'Complete this action without leaving the page.');
        setText('globalActionModalKicker', meta.kicker || 'Quick action');
        setIcon(meta.icon);
        setAlert('', '');
        setBody(loadingMarkup());
        openModalElement();

        if (!endpoint) {
            setAlert('This quick action is not available yet.', 'error');
            return;
        }

        var url = new URL(endpoint, config.baseUrl || global.location.href);
        Object.keys(options || {}).forEach(function (key) {
            if (options[key] !== undefined && options[key] !== null && options[key] !== '') {
                url.searchParams.set(key, options[key]);
            }
        });

        fetch(url.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'text/html',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('Unable to load this action.');
            }
            return response.text();
        })
        .then(function (html) {
            setBody(html);
            initEnhancements(qs('#globalActionModalBody'));
            var firstField = qs('input:not([type="hidden"]), select, textarea', qs('#globalActionModalBody'));
            if (firstField) {
                setTimeout(function () { firstField.focus(); }, 60);
            }
        })
        .catch(function (err) {
            setAlert(err.message || 'Unable to load this action.', 'error');
            setBody('<div class="action-modal-empty">Try opening the full page instead.</div>');
        });
    }

    function triggerOptions(trigger) {
        var options = {};
        Array.prototype.slice.call(trigger.attributes).forEach(function (attr) {
            if (attr.name.indexOf('data-action-option-') === 0) {
                options[attr.name.replace('data-action-option-', '').replace(/-/g, '_')] = attr.value;
            }
        });
        return options;
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-action-modal]');
        if (!trigger) return;
        var type = trigger.getAttribute('data-action-modal');
        if (!type) return;
        event.preventDefault();
        openActionModal(type, triggerOptions(trigger));
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.matches('[data-action-modal-form]')) return;
        event.preventDefault();
        submitForm(form);
    });

    global.openActionModal = openActionModal;
    global.closeActionModal = closeModalElement;
})(window);
