document.addEventListener('DOMContentLoaded', function () {
    const root = document.getElementById('smartCalculatorRoot');
    if (!root) {
        return;
    }

    const PC = window.PressCalculations;
    if (!PC) {
        return;
    }

    const config = window.PRESS_ERP_SMART_CALCULATOR || {};
    const currencyPrefix = config.currencyPrefix || 'MK';
    const storageKey = 'press_erp_smart_calc';
    const toggle = document.getElementById('smartCalculatorToggle');
    const panel = document.getElementById('smartCalculatorPanel');
    const closeBtn = document.getElementById('smartCalculatorClose');
    const applyMsg = document.getElementById('smartCalculatorApplyMsg');
    const toggleIcon = toggle ? toggle.querySelector('.material-icons') : null;
    const tabButtons = root.querySelectorAll('.smart-calculator-tab');
    const tabPanels = root.querySelectorAll('[data-tab-panel]');
    const calcInputs = root.querySelectorAll('[data-calc]');
    const applyButtons = root.querySelectorAll('[data-apply]');
    const hasEstimationForm = !!document.getElementById('estimationForm');

    let activeTab = 'basic';
    let applyMsgTimer = null;

    const basicDisplay = document.getElementById('smartCalcBasicDisplay');
    const basicExpr = document.getElementById('smartCalcBasicExpr');
    const basicKeypad = root.querySelector('.smart-calculator-basic-keypad');

    const basicCalc = {
        display: '0',
        expr: '',
        storedValue: null,
        operator: null,
        freshEntry: true,
    };

    function toNum(value, fallback) {
        if (typeof value === 'string') {
            value = value.replace(/,/g, '');
        }
        const n = parseFloat(value);
        return Number.isFinite(n) ? n : (fallback !== undefined ? fallback : 0);
    }

    function formatInt(value) {
        const n = Math.round(toNum(value));
        return n.toLocaleString(undefined, { maximumFractionDigits: 0 });
    }

    function formatDecimal(value, digits) {
        return toNum(value).toLocaleString(undefined, {
            minimumFractionDigits: digits,
            maximumFractionDigits: digits,
        });
    }

    function formatMoney(value) {
        return currencyPrefix + formatDecimal(value, 2);
    }

    function setResult(key, text) {
        const el = root.querySelector('[data-result="' + key + '"]');
        if (el) {
            el.textContent = text;
        }
    }

    function plainCopyText(text) {
        const value = String(text || '').trim();
        if (value === '—' || value === 'Error') {
            return '';
        }
        return value.replace(/,/g, '');
    }

    function copyToClipboard(text) {
        if (!text) {
            return Promise.reject(new Error('Nothing to copy'));
        }
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand('copy');
                document.body.removeChild(textarea);
                resolve();
            } catch (err) {
                document.body.removeChild(textarea);
                reject(err);
            }
        });
    }

    function showCopyFeedback(button) {
        if (!button) {
            return;
        }
        const icon = button.querySelector('.material-icons');
        button.classList.add('is-copied');
        if (icon) {
            icon.textContent = 'check';
        }
        window.setTimeout(function () {
            button.classList.remove('is-copied');
            if (icon) {
                icon.textContent = 'content_copy';
            }
        }, 1400);
    }

    function copyAnswer(sourceEl, button) {
        const text = plainCopyText(sourceEl ? sourceEl.textContent : '');
        if (!text) {
            return;
        }
        copyToClipboard(text).then(function () {
            showCopyFeedback(button);
        }).catch(function () {
            // Ignore clipboard failures silently.
        });
    }

    function initCopyButtons() {
        root.querySelectorAll('.smart-calculator-result-row [data-result]').forEach(function (valueEl) {
            if (valueEl.closest('.smart-calculator-result-value')) {
                return;
            }
            const wrap = document.createElement('div');
            wrap.className = 'smart-calculator-result-value';
            valueEl.parentNode.insertBefore(wrap, valueEl);
            wrap.appendChild(valueEl);

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'smart-calculator-copy-btn';
            btn.setAttribute('aria-label', 'Copy to clipboard');
            btn.innerHTML = '<i class="material-icons" aria-hidden="true">content_copy</i>';
            btn.addEventListener('click', function (event) {
                event.stopPropagation();
                copyAnswer(valueEl, btn);
            });
            wrap.appendChild(btn);
        });

        const basicCopyBtn = document.getElementById('smartCalcBasicCopy');
        if (basicCopyBtn && basicDisplay) {
            basicCopyBtn.addEventListener('click', function (event) {
                event.stopPropagation();
                copyAnswer(basicDisplay, basicCopyBtn);
            });
        }
    }

    function getFieldValue(key) {
        const input = root.querySelector('[data-calc="' + key + '"]');
        return input ? input.value : '';
    }

    function setFieldValue(key, value) {
        const input = root.querySelector('[data-calc="' + key + '"]');
        if (input && value !== undefined && value !== null && value !== '') {
            input.value = value;
        }
    }

    function readState() {
        const state = { activeTab: activeTab, values: {}, basicCalc: {
            display: basicCalc.display,
            expr: basicCalc.expr,
            storedValue: basicCalc.storedValue,
            operator: basicCalc.operator,
            freshEntry: basicCalc.freshEntry,
        } };
        calcInputs.forEach(function (input) {
            const key = input.getAttribute('data-calc');
            if (key) {
                state.values[key] = input.value;
            }
        });
        return state;
    }

    function saveState() {
        try {
            sessionStorage.setItem(storageKey, JSON.stringify(readState()));
        } catch (err) {
            // Ignore storage failures.
        }
    }

    function restoreState() {
        try {
            const raw = sessionStorage.getItem(storageKey);
            if (!raw) {
                return;
            }
            const state = JSON.parse(raw);
            if (state && state.values && typeof state.values === 'object') {
                Object.keys(state.values).forEach(function (key) {
                    setFieldValue(key, state.values[key]);
                });
            }
            if (state && state.activeTab) {
                activeTab = state.activeTab;
            }
            if (state && state.basicCalc && typeof state.basicCalc === 'object') {
                basicCalc.display = state.basicCalc.display || '0';
                basicCalc.expr = state.basicCalc.expr || '';
                basicCalc.storedValue = state.basicCalc.storedValue;
                basicCalc.operator = state.basicCalc.operator;
                basicCalc.freshEntry = !!state.basicCalc.freshEntry;
                updateBasicDisplay();
            }
        } catch (err) {
            // Ignore corrupt storage.
        }
    }

    function formatBasicNumber(value) {
        if (!Number.isFinite(value)) {
            return 'Error';
        }
        const str = String(value);
        if (str.includes('e') || str.includes('E')) {
            return value.toPrecision(10).replace(/\.?0+$/, '');
        }
        const negative = value < 0;
        const absStr = String(Math.abs(value));
        const parts = absStr.split('.');
        const intPart = parseInt(parts[0], 10).toLocaleString(undefined, { maximumFractionDigits: 0 });
        const formatted = parts.length === 1 ? intPart : intPart + '.' + parts[1];
        return negative ? '-' + formatted : formatted;
    }

    function operatorLabel(op) {
        if (op === '/') return '÷';
        if (op === '*') return '×';
        if (op === '-') return '−';
        return op;
    }

    function updateBasicDisplay() {
        if (basicDisplay) {
            basicDisplay.textContent = basicCalc.display;
        }
        if (basicExpr) {
            basicExpr.textContent = basicCalc.expr;
            basicExpr.setAttribute('aria-hidden', basicCalc.expr ? 'false' : 'true');
        }
    }

    function computeBasic(a, b, op) {
        switch (op) {
            case '+':
                return a + b;
            case '-':
                return a - b;
            case '*':
                return a * b;
            case '/':
                return b === 0 ? NaN : a / b;
            default:
                return b;
        }
    }

    function basicInputDigit(digit) {
        if (basicCalc.freshEntry) {
            basicCalc.display = digit;
            basicCalc.freshEntry = false;
        } else if (basicCalc.display === '0' && digit !== '.') {
            basicCalc.display = digit;
        } else {
            basicCalc.display += digit;
        }
        updateBasicDisplay();
        saveState();
    }

    function basicInputDecimal() {
        if (basicCalc.freshEntry) {
            basicCalc.display = '0.';
            basicCalc.freshEntry = false;
        } else if (basicCalc.display.indexOf('.') === -1) {
            basicCalc.display += '.';
        }
        updateBasicDisplay();
        saveState();
    }

    function basicClear() {
        basicCalc.display = '0';
        basicCalc.expr = '';
        basicCalc.storedValue = null;
        basicCalc.operator = null;
        basicCalc.freshEntry = true;
        updateBasicDisplay();
        saveState();
    }

    function basicBackspace() {
        if (basicCalc.freshEntry) {
            return;
        }
        if (basicCalc.display.length <= 1 || (basicCalc.display.length === 2 && basicCalc.display.charAt(0) === '-')) {
            basicCalc.display = '0';
            basicCalc.freshEntry = true;
        } else {
            basicCalc.display = basicCalc.display.slice(0, -1);
        }
        updateBasicDisplay();
        saveState();
    }

    function basicPercent() {
        const value = toNum(basicCalc.display);
        basicCalc.display = formatBasicNumber(value / 100);
        basicCalc.freshEntry = true;
        updateBasicDisplay();
        saveState();
    }

    function basicChooseOperator(nextOp) {
        if (basicCalc.display === 'Error') {
            basicClear();
        }
        const inputValue = toNum(basicCalc.display);
        if (basicCalc.operator !== null && !basicCalc.freshEntry) {
            const result = computeBasic(basicCalc.storedValue, inputValue, basicCalc.operator);
            if (!Number.isFinite(result)) {
                basicCalc.display = 'Error';
                basicCalc.expr = '';
                basicCalc.storedValue = null;
                basicCalc.operator = null;
                basicCalc.freshEntry = true;
                updateBasicDisplay();
                saveState();
                return;
            }
            basicCalc.display = formatBasicNumber(result);
            basicCalc.storedValue = result;
        } else {
            basicCalc.storedValue = inputValue;
        }
        basicCalc.expr = formatBasicNumber(basicCalc.storedValue) + ' ' + operatorLabel(nextOp);
        basicCalc.operator = nextOp;
        basicCalc.freshEntry = true;
        updateBasicDisplay();
        saveState();
    }

    function basicEquals() {
        if (basicCalc.display === 'Error') {
            basicClear();
            return;
        }
        if (basicCalc.operator === null) {
            return;
        }
        const inputValue = toNum(basicCalc.display);
        const result = computeBasic(basicCalc.storedValue, inputValue, basicCalc.operator);
        if (!Number.isFinite(result)) {
            basicCalc.display = 'Error';
            basicCalc.expr = '';
            basicCalc.storedValue = null;
            basicCalc.operator = null;
            basicCalc.freshEntry = true;
            updateBasicDisplay();
            saveState();
            return;
        }
        basicCalc.expr = formatBasicNumber(basicCalc.storedValue) + ' ' + operatorLabel(basicCalc.operator) + ' ' + formatBasicNumber(inputValue) + ' =';
        basicCalc.display = formatBasicNumber(result);
        basicCalc.storedValue = result;
        basicCalc.operator = null;
        basicCalc.freshEntry = true;
        updateBasicDisplay();
        saveState();
    }

    function handleBasicAction(action, btn) {
        if (action === 'clear') {
            basicClear();
            return;
        }
        if (action === 'backspace') {
            basicBackspace();
            return;
        }
        if (action === 'percent') {
            basicPercent();
            return;
        }
        if (action === 'decimal') {
            basicInputDecimal();
            return;
        }
        if (action === 'digit') {
            basicInputDigit(btn.getAttribute('data-basic-digit') || '0');
            return;
        }
        if (action === 'operator') {
            basicChooseOperator(btn.getAttribute('data-basic-op'));
            return;
        }
        if (action === 'equals') {
            basicEquals();
        }
    }

    function recalcBasicUnitQty() {
        const unit = toNum(getFieldValue('basic.unit'));
        const quantity = toNum(getFieldValue('basic.quantity'));
        const total = PC.unitTimesQty(unit, quantity);
        setResult('basic.total', formatDecimal(total, 2));
    }

    function recalcImpressions() {
        const pages = toNum(getFieldValue('impressions.pages'));
        const pagesPerSheet = toNum(getFieldValue('impressions.pagesPerSheet'), 1);
        const sides = toNum(getFieldValue('impressions.sides'), 2);
        const passes = toNum(getFieldValue('impressions.passes'), 1);
        const quantity = toNum(getFieldValue('impressions.quantity'));
        const wastePct = toNum(getFieldValue('impressions.wastePct'));

        const perCopy = PC.impressionsPerCopy({
            pages: pages,
            pagesPerSheet: pagesPerSheet,
            sides: sides,
            passes: passes,
        });
        const total = PC.totalImpressions({
            perCopy: perCopy,
            quantity: quantity,
            wastePct: wastePct,
        });

        setResult('impressions.perCopy', formatInt(perCopy));
        setResult('impressions.total', formatInt(total));
        root.setAttribute('data-last-impressions', String(Math.round(total)));

        const pressImpressions = root.querySelector('[data-calc="press.impressions"]');
        if (pressImpressions && !pressImpressions.dataset.userEdited) {
            pressImpressions.value = total > 0 ? String(Math.round(total)) : '';
            recalcPress();
        }
    }

    function recalcSheets() {
        const pages = toNum(getFieldValue('sheets.pages'));
        const pagesPerSheet = toNum(getFieldValue('sheets.pagesPerSheet'), 1);
        const quantity = toNum(getFieldValue('sheets.quantity'));
        const wastePct = toNum(getFieldValue('sheets.wastePct'));

        const perCopy = PC.sheetsPerCopy({ pages: pages, pagesPerSheet: pagesPerSheet });
        const total = PC.totalSheets({
            perCopy: perCopy,
            quantity: quantity,
            wastePct: wastePct,
        });

        setResult('sheets.perCopy', formatInt(perCopy));
        setResult('sheets.total', formatInt(total));
    }

    function recalcInk() {
        const baseMm = toNum(getFieldValue('ink.baseMm'));
        const heightMm = toNum(getFieldValue('ink.heightMm'));
        const pages = toNum(getFieldValue('ink.pages'));
        const quantity = toNum(getFieldValue('ink.quantity'));
        const rate = toNum(getFieldValue('ink.rate'));

        const totalKg = PC.formulaInkKgs({
            baseMm: baseMm,
            heightMm: heightMm,
            pages: pages,
            quantity: quantity,
        });

        setResult('ink.totalKg', formatDecimal(totalKg, 4));
        setResult('ink.totalCost', rate > 0 ? formatMoney(totalKg * rate) : '—');
    }

    function recalcPress() {
        const impressions = toNum(getFieldValue('press.impressions'));
        const iph = toNum(getFieldValue('press.iph'));
        const quantity = toNum(getFieldValue('press.quantity'));

        const runningHrs = PC.pressRunningHrs({ impressions: impressions, iph: iph });
        setResult('press.runningHrs', formatDecimal(runningHrs, 2));

        if (quantity > 0 && runningHrs > 0) {
            setResult('press.hrsPerCopy', formatDecimal(runningHrs / quantity, 6));
        } else {
            setResult('press.hrsPerCopy', '—');
        }
    }

    function recalcAll() {
        recalcBasicUnitQty();
        recalcImpressions();
        recalcSheets();
        recalcInk();
        recalcPress();
        saveState();
    }

    function setActiveTab(tab) {
        activeTab = tab;
        tabButtons.forEach(function (btn) {
            const isActive = btn.getAttribute('data-tab') === tab;
            btn.classList.toggle('is-active', isActive);
            btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        tabPanels.forEach(function (panelEl) {
            const isActive = panelEl.getAttribute('data-tab-panel') === tab;
            panelEl.classList.toggle('hidden', !isActive);
        });
        saveState();
    }

    function closeOtherFloatingPanels(except) {
        document.dispatchEvent(new CustomEvent('press-erp:close-floating-panels', {
            detail: { except: except || null },
        }));
    }

    function setOpen(open) {
        if (open) {
            closeOtherFloatingPanels('calculator');
        }
        root.classList.toggle('is-open', open);
        panel.classList.toggle('hidden', !open);
        panel.setAttribute('aria-hidden', open ? 'false' : 'true');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Close smart calculator' : 'Open smart calculator');
        if (toggleIcon) {
            toggleIcon.textContent = open ? 'close' : 'calculate';
        }
        if (open) {
            recalcAll();
        }
    }

    function showApplyMessage(text) {
        if (!applyMsg) {
            return;
        }
        applyMsg.textContent = text;
        applyMsg.classList.remove('hidden');
        if (applyMsgTimer) {
            window.clearTimeout(applyMsgTimer);
        }
        applyMsgTimer = window.setTimeout(function () {
            applyMsg.classList.add('hidden');
        }, 2600);
    }

    function syncApplyButtons() {
        applyButtons.forEach(function (btn) {
            btn.classList.toggle('hidden', !hasEstimationForm);
        });
    }

    function handleApply(kind) {
        const apply = window.estimationWizardApply;
        if (!apply) {
            showApplyMessage('Open an estimation form to apply values.');
            return;
        }

        if (kind === 'impressions') {
            const total = toNum(root.getAttribute('data-last-impressions'));
            if (total <= 0) {
                showApplyMessage('Enter values to calculate impressions first.');
                return;
            }
            apply.setImpressions(total);
            showApplyMessage('Applied total impressions to estimation form.');
            return;
        }

        if (kind === 'sheets') {
            const totalText = root.querySelector('[data-result="sheets.total"]');
            const total = toNum(totalText ? totalText.textContent.replace(/,/g, '') : 0);
            if (total <= 0) {
                showApplyMessage('Enter values to calculate sheets first.');
                return;
            }
            apply.setSheets(total);
            showApplyMessage('Applied total sheets to estimation form.');
            return;
        }

        if (kind === 'ink') {
            apply.setInkInputs({
                baseMm: getFieldValue('ink.baseMm'),
                heightMm: getFieldValue('ink.heightMm'),
                pages: getFieldValue('ink.pages'),
                quantity: getFieldValue('ink.quantity'),
            });
            showApplyMessage('Applied ink inputs to estimation form.');
            return;
        }

        if (kind === 'press') {
            apply.setPressHours({
                impressions: getFieldValue('press.impressions'),
                iph: getFieldValue('press.iph'),
                makeReadyHrs: getFieldValue('press.makeReadyHrs'),
            });
            showApplyMessage('Applied press hours to estimation form.');
        }
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            const isOpen = !panel.classList.contains('hidden');
            setOpen(!isOpen);
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            setOpen(false);
        });
    }

    document.addEventListener('click', function (event) {
        if (panel.classList.contains('hidden')) {
            return;
        }
        if (!root.contains(event.target)) {
            setOpen(false);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (panel.classList.contains('hidden')) {
            return;
        }
        if (event.key === 'Escape') {
            setOpen(false);
            return;
        }
        if (activeTab !== 'basic') {
            return;
        }
        const target = event.target;
        if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable)) {
            return;
        }
        if (/^\d$/.test(event.key)) {
            event.preventDefault();
            basicInputDigit(event.key);
            return;
        }
        if (event.key === '.') {
            event.preventDefault();
            basicInputDecimal();
            return;
        }
        if (event.key === '+' || event.key === '-' || event.key === '*' || event.key === '/') {
            event.preventDefault();
            basicChooseOperator(event.key);
            return;
        }
        if (event.key === 'Enter' || event.key === '=') {
            event.preventDefault();
            basicEquals();
            return;
        }
        if (event.key === 'Backspace') {
            event.preventDefault();
            basicBackspace();
            return;
        }
        if (event.key === '%') {
            event.preventDefault();
            basicPercent();
        }
    });

    document.addEventListener('press-erp:close-floating-panels', function (event) {
        const except = event.detail && event.detail.except;
        if (except === 'calculator') {
            return;
        }
        if (!panel.classList.contains('hidden')) {
            setOpen(false);
        }
    });

    tabButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            setActiveTab(btn.getAttribute('data-tab'));
        });
    });

    calcInputs.forEach(function (input) {
        input.addEventListener('input', function () {
            if (input.getAttribute('data-calc') === 'press.impressions') {
                input.dataset.userEdited = '1';
            }
            recalcAll();
        });
    });

    applyButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            handleApply(btn.getAttribute('data-apply'));
        });
    });

    if (basicKeypad) {
        basicKeypad.addEventListener('click', function (event) {
            const btn = event.target.closest('[data-basic-action]');
            if (!btn || !basicKeypad.contains(btn)) {
                return;
            }
            handleBasicAction(btn.getAttribute('data-basic-action'), btn);
        });
    }

    initCopyButtons();
    restoreState();
    setActiveTab(activeTab);
    syncApplyButtons();
    recalcAll();
});
