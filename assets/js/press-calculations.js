/**
 * Shared print calculation formulas for estimation wizard and smart calculator.
 */
(function (global) {
    'use strict';

    function toNum(value, fallback) {
        const n = parseFloat(value);
        return Number.isFinite(n) ? n : (fallback !== undefined ? fallback : 0);
    }

    function ceilDiv(numerator, denominator) {
        const d = toNum(denominator, 1);
        if (d <= 0) {
            return 0;
        }
        return Math.ceil(toNum(numerator) / d);
    }

    function unitTimesQty(unit, qty) {
        return toNum(unit) * toNum(qty);
    }

    function impressionsPerCopy(opts) {
        opts = opts || {};
        const pages = toNum(opts.pages);
        const pagesPerSheet = toNum(opts.pagesPerSheet, 1);
        const sides = toNum(opts.sides, 2);
        const passes = toNum(opts.passes, 1);
        if (pages <= 0) {
            return 0;
        }
        return ceilDiv(pages, pagesPerSheet) * sides * passes;
    }

    function totalImpressions(opts) {
        opts = opts || {};
        const perCopy = opts.perCopy !== undefined ? toNum(opts.perCopy) : impressionsPerCopy(opts);
        const quantity = toNum(opts.quantity);
        const wastePct = toNum(opts.wastePct);
        return perCopy * quantity * (1 + wastePct / 100);
    }

    function sheetsPerCopy(opts) {
        opts = opts || {};
        return ceilDiv(toNum(opts.pages), toNum(opts.pagesPerSheet, 1));
    }

    function totalSheets(opts) {
        opts = opts || {};
        const perCopy = opts.perCopy !== undefined ? toNum(opts.perCopy) : sheetsPerCopy(opts);
        const quantity = toNum(opts.quantity);
        const wastePct = toNum(opts.wastePct);
        return perCopy * quantity * (1 + wastePct / 100);
    }

    function formulaInkKgs(opts) {
        opts = opts || {};
        const base = toNum(opts.baseMm);
        const height = toNum(opts.heightMm);
        const pages = toNum(opts.pages);
        const qty = toNum(opts.quantity);
        return (base / 1000 * height / 1000) * pages * qty * 0.5 / 0.886 / 1000;
    }

    function pressRunningHrs(opts) {
        opts = opts || {};
        const impressions = toNum(opts.impressions);
        const iph = toNum(opts.iph);
        if (iph <= 0) {
            return toNum(opts.fallbackHrs);
        }
        return impressions / iph;
    }

    global.PressCalculations = {
        unitTimesQty: unitTimesQty,
        impressionsPerCopy: impressionsPerCopy,
        totalImpressions: totalImpressions,
        sheetsPerCopy: sheetsPerCopy,
        totalSheets: totalSheets,
        formulaInkKgs: formulaInkKgs,
        pressRunningHrs: pressRunningHrs,
    };
})(typeof window !== 'undefined' ? window : this);
