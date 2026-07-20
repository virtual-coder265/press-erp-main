<?php if (!empty($_SESSION['user_id'])): ?>
<div id="smartCalculatorRoot" class="smart-calculator-root" data-enabled="1">
    <button
        type="button"
        id="smartCalculatorToggle"
        class="smart-calculator-fab"
        aria-controls="smartCalculatorPanel"
        aria-expanded="false"
        aria-label="Open smart calculator"
    >
        <i class="material-icons">calculate</i>
    </button>

    <section id="smartCalculatorPanel" class="smart-calculator-panel hidden" aria-hidden="true">
        <header class="smart-calculator-header">
            <div>
                <h3 class="smart-calculator-title">Smart Calculator</h3>
                <p class="smart-calculator-subtitle">Basic arithmetic and print estimation math.</p>
            </div>
            <button type="button" id="smartCalculatorClose" class="smart-calculator-close" aria-label="Close calculator">
                <i class="material-icons">close</i>
            </button>
        </header>

        <div class="smart-calculator-tabs" role="tablist" aria-label="Calculator types">
            <button type="button" class="smart-calculator-tab is-active" data-tab="basic" role="tab" aria-selected="true">Basic</button>
            <button type="button" class="smart-calculator-tab" data-tab="impressions" role="tab" aria-selected="false">Impressions</button>
            <button type="button" class="smart-calculator-tab" data-tab="sheets" role="tab" aria-selected="false">Sheets</button>
            <button type="button" class="smart-calculator-tab" data-tab="ink" role="tab" aria-selected="false">Ink</button>
            <button type="button" class="smart-calculator-tab" data-tab="press" role="tab" aria-selected="false">Press hrs</button>
        </div>

        <div class="smart-calculator-body">
            <div id="smartCalcTab-basic" class="smart-calculator-tab-panel" data-tab-panel="basic">
                <div class="smart-calculator-basic">
                    <div class="smart-calculator-basic-display-wrap">
                        <div id="smartCalcBasicDisplay" class="smart-calculator-basic-display" aria-live="polite" aria-label="Calculator result">0</div>
                        <button type="button" id="smartCalcBasicCopy" class="smart-calculator-copy-btn smart-calculator-copy-btn-display" aria-label="Copy result">
                            <i class="material-icons" aria-hidden="true">content_copy</i>
                        </button>
                    </div>
                    <div id="smartCalcBasicExpr" class="smart-calculator-basic-expr" aria-hidden="true"></div>
                    <div class="smart-calculator-basic-keypad" role="group" aria-label="Basic calculator keypad">
                        <button type="button" class="smart-calculator-key smart-calculator-key-fn" data-basic-action="clear">C</button>
                        <button type="button" class="smart-calculator-key smart-calculator-key-fn" data-basic-action="backspace" aria-label="Backspace">⌫</button>
                        <button type="button" class="smart-calculator-key smart-calculator-key-fn" data-basic-action="percent">%</button>
                        <button type="button" class="smart-calculator-key smart-calculator-key-op" data-basic-action="operator" data-basic-op="/">÷</button>

                        <button type="button" class="smart-calculator-key" data-basic-action="digit" data-basic-digit="7">7</button>
                        <button type="button" class="smart-calculator-key" data-basic-action="digit" data-basic-digit="8">8</button>
                        <button type="button" class="smart-calculator-key" data-basic-action="digit" data-basic-digit="9">9</button>
                        <button type="button" class="smart-calculator-key smart-calculator-key-op" data-basic-action="operator" data-basic-op="*">×</button>

                        <button type="button" class="smart-calculator-key" data-basic-action="digit" data-basic-digit="4">4</button>
                        <button type="button" class="smart-calculator-key" data-basic-action="digit" data-basic-digit="5">5</button>
                        <button type="button" class="smart-calculator-key" data-basic-action="digit" data-basic-digit="6">6</button>
                        <button type="button" class="smart-calculator-key smart-calculator-key-op" data-basic-action="operator" data-basic-op="-">−</button>

                        <button type="button" class="smart-calculator-key" data-basic-action="digit" data-basic-digit="1">1</button>
                        <button type="button" class="smart-calculator-key" data-basic-action="digit" data-basic-digit="2">2</button>
                        <button type="button" class="smart-calculator-key" data-basic-action="digit" data-basic-digit="3">3</button>
                        <button type="button" class="smart-calculator-key smart-calculator-key-op" data-basic-action="operator" data-basic-op="+">+</button>

                        <button type="button" class="smart-calculator-key smart-calculator-key-zero" data-basic-action="digit" data-basic-digit="0">0</button>
                        <button type="button" class="smart-calculator-key" data-basic-action="decimal">.</button>
                        <button type="button" class="smart-calculator-key smart-calculator-key-equals" data-basic-action="equals">=</button>
                    </div>
                </div>
                <div class="smart-calculator-unit-qty">
                    <p class="smart-calculator-unit-qty-title">Unit × quantity</p>
                    <div class="smart-calculator-grid">
                        <label class="smart-calculator-field">
                            <span>Unit value</span>
                            <input type="number" min="0" step="any" data-calc="basic.unit" placeholder="0">
                        </label>
                        <label class="smart-calculator-field">
                            <span>Quantity</span>
                            <input type="number" min="0" step="any" data-calc="basic.quantity" placeholder="0">
                        </label>
                    </div>
                    <div class="smart-calculator-results">
                        <div class="smart-calculator-result-row smart-calculator-result-total">
                            <span>Total</span>
                            <strong data-result="basic.total">0</strong>
                        </div>
                    </div>
                </div>
            </div>

            <div id="smartCalcTab-impressions" class="smart-calculator-tab-panel hidden" data-tab-panel="impressions">
                <div class="smart-calculator-grid">
                    <label class="smart-calculator-field">
                        <span>Pages per copy</span>
                        <input type="number" min="0" step="1" data-calc="impressions.pages" placeholder="16">
                    </label>
                    <label class="smart-calculator-field">
                        <span>Pages per sheet</span>
                        <input type="number" min="1" step="1" data-calc="impressions.pagesPerSheet" value="1">
                    </label>
                    <label class="smart-calculator-field">
                        <span>Sides</span>
                        <input type="number" min="1" step="1" data-calc="impressions.sides" value="2">
                    </label>
                    <label class="smart-calculator-field">
                        <span>Colour passes</span>
                        <input type="number" min="1" step="1" data-calc="impressions.passes" value="1">
                    </label>
                    <label class="smart-calculator-field">
                        <span>Quantity (copies)</span>
                        <input type="number" min="0" step="1" data-calc="impressions.quantity" placeholder="10000">
                    </label>
                    <label class="smart-calculator-field">
                        <span>Waste %</span>
                        <input type="number" min="0" step="0.1" data-calc="impressions.wastePct" value="0">
                    </label>
                </div>
                <div class="smart-calculator-results">
                    <div class="smart-calculator-result-row">
                        <span>Per copy</span>
                        <strong data-result="impressions.perCopy">0</strong>
                    </div>
                    <div class="smart-calculator-result-row smart-calculator-result-total">
                        <span>Total impressions</span>
                        <strong data-result="impressions.total">0</strong>
                    </div>
                </div>
                <button type="button" class="smart-calculator-apply hidden" data-apply="impressions">Apply to estimation</button>
            </div>

            <div id="smartCalcTab-sheets" class="smart-calculator-tab-panel hidden" data-tab-panel="sheets">
                <div class="smart-calculator-grid">
                    <label class="smart-calculator-field">
                        <span>Pages per copy</span>
                        <input type="number" min="0" step="1" data-calc="sheets.pages" placeholder="16">
                    </label>
                    <label class="smart-calculator-field">
                        <span>Pages per sheet</span>
                        <input type="number" min="1" step="1" data-calc="sheets.pagesPerSheet" value="8">
                    </label>
                    <label class="smart-calculator-field">
                        <span>Quantity (copies)</span>
                        <input type="number" min="0" step="1" data-calc="sheets.quantity" placeholder="10000">
                    </label>
                    <label class="smart-calculator-field">
                        <span>Waste %</span>
                        <input type="number" min="0" step="0.1" data-calc="sheets.wastePct" value="0">
                    </label>
                </div>
                <div class="smart-calculator-results">
                    <div class="smart-calculator-result-row">
                        <span>Sheets per copy</span>
                        <strong data-result="sheets.perCopy">0</strong>
                    </div>
                    <div class="smart-calculator-result-row smart-calculator-result-total">
                        <span>Total sheets</span>
                        <strong data-result="sheets.total">0</strong>
                    </div>
                </div>
                <button type="button" class="smart-calculator-apply hidden" data-apply="sheets">Apply to estimation</button>
            </div>

            <div id="smartCalcTab-ink" class="smart-calculator-tab-panel hidden" data-tab-panel="ink">
                <div class="smart-calculator-grid">
                    <label class="smart-calculator-field">
                        <span>Base (mm)</span>
                        <input type="number" min="0" step="0.01" data-calc="ink.baseMm" placeholder="210">
                    </label>
                    <label class="smart-calculator-field">
                        <span>Height (mm)</span>
                        <input type="number" min="0" step="0.01" data-calc="ink.heightMm" placeholder="297">
                    </label>
                    <label class="smart-calculator-field">
                        <span>Pages</span>
                        <input type="number" min="0" step="1" data-calc="ink.pages" placeholder="16">
                    </label>
                    <label class="smart-calculator-field">
                        <span>Quantity (copies)</span>
                        <input type="number" min="0" step="1" data-calc="ink.quantity" placeholder="10000">
                    </label>
                    <label class="smart-calculator-field">
                        <span>Rate / kg (MK)</span>
                        <input type="number" min="0" step="0.01" data-calc="ink.rate" placeholder="Optional">
                    </label>
                </div>
                <div class="smart-calculator-results">
                    <div class="smart-calculator-result-row">
                        <span>Total ink (kg)</span>
                        <strong data-result="ink.totalKg">0</strong>
                    </div>
                    <div class="smart-calculator-result-row smart-calculator-result-total">
                        <span>Ink cost (optional)</span>
                        <strong data-result="ink.totalCost">—</strong>
                    </div>
                </div>
                <button type="button" class="smart-calculator-apply hidden" data-apply="ink">Apply to estimation</button>
            </div>

            <div id="smartCalcTab-press" class="smart-calculator-tab-panel hidden" data-tab-panel="press">
                <div class="smart-calculator-grid">
                    <label class="smart-calculator-field">
                        <span>Impressions</span>
                        <input type="number" min="0" step="1" data-calc="press.impressions" placeholder="41200">
                    </label>
                    <label class="smart-calculator-field">
                        <span>IPH</span>
                        <input type="number" min="0" step="1" data-calc="press.iph" placeholder="5000">
                    </label>
                    <label class="smart-calculator-field">
                        <span>Make-ready hrs</span>
                        <input type="number" min="0" step="0.01" data-calc="press.makeReadyHrs" placeholder="0">
                    </label>
                    <label class="smart-calculator-field">
                        <span>Quantity (copies)</span>
                        <input type="number" min="0" step="1" data-calc="press.quantity" placeholder="Optional">
                    </label>
                </div>
                <div class="smart-calculator-results">
                    <div class="smart-calculator-result-row">
                        <span>Running hrs</span>
                        <strong data-result="press.runningHrs">0</strong>
                    </div>
                    <div class="smart-calculator-result-row">
                        <span>Hrs per copy</span>
                        <strong data-result="press.hrsPerCopy">—</strong>
                    </div>
                </div>
                <button type="button" class="smart-calculator-apply hidden" data-apply="press">Apply to estimation</button>
            </div>

            <p id="smartCalculatorApplyMsg" class="smart-calculator-apply-msg hidden" role="status" aria-live="polite"></p>
        </div>
    </section>
</div>
<?php endif; ?>
