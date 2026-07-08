<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../includes/dashboard_partials_helper.php';

// Legacy aliases for callers outside the dashboard module that still expect
// the original camelCase names.
if (!function_exists('calculateMoM')) {
    function calculateMoM($current, $previous)
    {
        return dashboardMoMGrowth($current, $previous);
    }
}
if (!function_exists('getGrowthColor')) {
    function getGrowthColor($growthStr)
    {
        return dashboardGrowthColor($growthStr);
    }
}
if (!function_exists('getGrowthIcon')) {
    function getGrowthIcon($growthStr)
    {
        return dashboardGrowthIcon($growthStr);
    }
}

// Dashboard data + UI context (helpers + queries live in
// includes/dashboard_partials_helper.php so the same arrays power both this
// initial render and modules/dashboard/fragments.php AJAX refreshes).
$dashboardContext = dashboard_collect_context($pdo, $_GET);
extract($dashboardContext, EXTR_SKIP);

// Re-expose the original calculateMoM-style call paths for any inline code
// further down. (Most of these were previously computed locally; the helper
// now owns them.)
$stats = $dashboardContext['stats'];

include '../../includes/header.php';
?>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    .dashboard-executive-grid {
        display: grid;
        gap: 1rem;
        margin-bottom: 1.4rem;
    }

    .dashboard-brief-card,
    .dashboard-focus-card {
        position: relative;
        overflow: hidden;
        border-radius: 1.55rem;
        border: 1px solid rgba(206, 216, 226, 0.92);
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(246, 250, 248, 0.98));
        box-shadow: 0 22px 46px -40px rgba(15, 23, 42, 0.42);
    }

    .dashboard-brief-card {
        padding: 1.35rem;
    }

    .dashboard-brief-card::before {
        content: "";
        position: absolute;
        inset: 0;
        background:
            radial-gradient(circle at top right, rgba(24, 123, 116, 0.12), transparent 34%),
            linear-gradient(90deg, rgba(16, 185, 129, 0.05), transparent 32%);
        pointer-events: none;
    }

    .dashboard-brief-card > * {
        position: relative;
        z-index: 1;
    }

    .dashboard-brief-kicker {
        display: inline-flex;
        align-items: center;
        gap: 0.42rem;
        padding: 0.42rem 0.74rem;
        border-radius: 999px;
        background: rgba(24, 123, 116, 0.1);
        color: #0f766e;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
    }

    .dashboard-brief-title {
        margin: 0.95rem 0 0;
        font-size: clamp(1.45rem, 2.4vw, 1.9rem);
        line-height: 1.05;
        font-weight: 800;
        letter-spacing: -0.04em;
        color: #122033;
    }

    .dashboard-brief-copy {
        max-width: 42rem;
        margin: 0.6rem 0 0;
        font-size: 0.9rem;
        line-height: 1.6;
        color: #5f6f82;
    }

    .dashboard-brief-metric-grid {
        display: grid;
        gap: 0.85rem;
        margin-top: 1.1rem;
    }

    .dashboard-brief-metric {
        padding: 0.95rem 1rem;
        border-radius: 1.15rem;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: rgba(255, 255, 255, 0.82);
    }

    .dashboard-brief-metric span {
        display: block;
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #6c7f78;
    }

    .dashboard-brief-metric strong {
        display: block;
        margin-top: 0.38rem;
        font-size: 1.35rem;
        font-weight: 800;
        letter-spacing: -0.04em;
        color: #14302d;
    }

    .dashboard-brief-metric p {
        margin: 0.38rem 0 0;
        font-size: 0.78rem;
        line-height: 1.5;
        color: #5f6f82;
    }

    .dashboard-focus-card {
        padding: 1.5rem;
    }

    .dashboard-focus-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.9rem;
        flex-wrap: wrap;
    }

    .dashboard-focus-head span {
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #6c7f78;
    }

    .dashboard-focus-head h2 {
        margin: 0;
        font-size: 1.35rem;
        line-height: 1.1;
        font-weight: 800;
        letter-spacing: -0.03em;
        color: #14302d;
    }

    .dashboard-focus-list {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
        margin-top: 1.15rem;
    }

    .dashboard-focus-item {
        display: grid;
        grid-template-columns: auto 1fr;
        grid-template-areas:
            "icon value"
            "copy copy";
        gap: 0.9rem;
        align-items: flex-start;
        min-height: 9.2rem;
        padding: 1.1rem;
        border-radius: 1.25rem;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background:
            radial-gradient(circle at top right, rgba(255, 255, 255, 0.95), transparent 44%),
            linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.94));
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    }

    .dashboard-focus-item:hover {
        transform: translateY(-2px);
        border-color: rgba(24, 123, 116, 0.2);
        box-shadow: 0 24px 50px -38px rgba(15, 23, 42, 0.4);
    }

    .dashboard-focus-icon {
        grid-area: icon;
        width: 3rem;
        height: 3rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: rgba(24, 123, 116, 0.12);
        color: #0f766e;
    }

    .dashboard-focus-item[data-tone="success"] .dashboard-focus-icon {
        background: rgba(16, 185, 129, 0.12);
        color: #059669;
    }

    .dashboard-focus-item[data-tone="warning"] .dashboard-focus-icon {
        background: rgba(245, 158, 11, 0.14);
        color: #b45309;
    }

    .dashboard-focus-item[data-tone="danger"] .dashboard-focus-icon {
        background: rgba(239, 68, 68, 0.12);
        color: #dc2626;
    }

    .dashboard-focus-item[data-tone="neutral"] .dashboard-focus-icon {
        background: rgba(148, 163, 184, 0.14);
        color: #475569;
    }

    .dashboard-focus-copy strong {
        display: block;
        font-size: 0.95rem;
        font-weight: 800;
        color: #14302d;
    }

    .dashboard-focus-copy {
        grid-area: copy;
        min-width: 0;
    }

    .dashboard-focus-copy span {
        display: block;
        margin-top: 0.35rem;
        font-size: 0.8rem;
        line-height: 1.5;
        color: #5f6f82;
    }

    .dashboard-focus-value {
        grid-area: value;
        justify-self: end;
        min-width: 2.4rem;
        min-height: 2.4rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: rgba(15, 118, 110, 0.08);
        font-size: 1.2rem;
        font-weight: 800;
        letter-spacing: -0.03em;
        color: #14302d;
        text-align: right;
    }

    .dashboard-focus-action {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        margin-top: 1rem;
        color: #0f766e;
        font-size: 0.8rem;
        font-weight: 700;
    }

    .dashboard-welcome-card,
    .dashboard-search-results-card {
        position: relative;
        overflow: hidden;
        border-radius: 1.4rem;
        border: 1px solid rgba(206, 216, 226, 0.92);
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(245, 249, 252, 0.98));
        box-shadow: 0 20px 44px -40px rgba(15, 23, 42, 0.4);
    }

    .dashboard-welcome-card {
        padding: 1.35rem 1.45rem;
        margin-bottom: 1.35rem;
    }

    .dashboard-welcome-card::before {
        content: "";
        position: absolute;
        inset: 0;
        background:
            radial-gradient(circle at top right, rgba(24, 123, 116, 0.12), transparent 32%),
            linear-gradient(90deg, rgba(15, 118, 110, 0.04), transparent 26%);
        pointer-events: none;
    }

    .dashboard-welcome-card::after {
        content: "";
        position: absolute;
        bottom: -2.5rem;
        left: -2rem;
        width: 9rem;
        height: 9rem;
        border-radius: 999px;
        background: radial-gradient(circle, rgba(15, 118, 110, 0.12), transparent 70%);
        pointer-events: none;
    }

    .dashboard-welcome-shell {
        position: relative;
        z-index: 1;
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
        align-items: stretch;
    }

    .dashboard-welcome-main {
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .dashboard-welcome-title {
        margin: 0;
        font-size: clamp(1.85rem, 3.4vw, 2.45rem);
        line-height: 1.05;
        font-weight: 800;
        letter-spacing: -0.04em;
        color: #0f172a;
    }

    .dashboard-welcome-subtitle {
        max-width: 38rem;
        margin: 0;
        font-size: 0.98rem;
        line-height: 1.55;
        color: #5f6f82;
    }

    .dashboard-welcome-clock {
        width: 100%;
        max-width: 15rem;
        padding: 0.9rem 1rem;
        border-radius: 1.05rem;
        border: 1px solid rgba(226, 232, 240, 0.94);
        background: rgba(255, 255, 255, 0.9);
        box-shadow: 0 18px 36px -34px rgba(15, 23, 42, 0.4);
        backdrop-filter: blur(10px);
    }

    .dashboard-welcome-clock-label {
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #7a8ea2;
    }

    .dashboard-welcome-clock-time {
        margin-top: 0.45rem;
        font-size: clamp(1.9rem, 3.2vw, 2.4rem);
        line-height: 1;
        font-weight: 800;
        letter-spacing: -0.04em;
        color: #0f172a;
    }

    .dashboard-welcome-clock-date {
        margin-top: 0.4rem;
        font-size: 0.88rem;
        font-weight: 600;
        color: #5f6f82;
    }

    .dashboard-section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .dashboard-section-title {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        min-width: 0;
    }

    .dashboard-help {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 1.8rem;
        height: 1.8rem;
        border-radius: 999px;
        border: 1px solid rgba(197, 209, 222, 0.92);
        background: rgba(255, 255, 255, 0.95);
        color: #5f6f82;
        cursor: help;
        flex-shrink: 0;
    }

    .dashboard-help i {
        font-size: 1rem;
    }

    .dashboard-help-copy {
        position: absolute;
        left: 50%;
        bottom: calc(100% + 0.65rem);
        transform: translateX(-50%) translateY(6px);
        width: min(16rem, 70vw);
        padding: 0.7rem 0.8rem;
        border-radius: 0.85rem;
        background: rgba(15, 23, 42, 0.96);
        color: #ffffff;
        font-size: 0.76rem;
        line-height: 1.45;
        box-shadow: 0 18px 36px -24px rgba(15, 23, 42, 0.58);
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.18s ease, transform 0.18s ease;
        z-index: 20;
    }

    .dashboard-help:hover .dashboard-help-copy,
    .dashboard-help:focus-within .dashboard-help-copy {
        opacity: 1;
        transform: translateX(-50%) translateY(0);
    }

    .dashboard-search-results-card {
        padding: 1.15rem 1.2rem;
        margin-bottom: 1.5rem;
    }

    .dashboard-search-results-list {
        display: grid;
        gap: 0.75rem;
        margin-top: 1rem;
    }

    .dashboard-search-result-link {
        display: block;
        padding: 0.95rem 1rem;
        border-radius: 1rem;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: rgba(248, 250, 252, 0.88);
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    }

    .dashboard-search-result-link:hover {
        transform: translateY(-1px);
        border-color: rgba(15, 118, 110, 0.22);
        box-shadow: 0 18px 38px -32px rgba(15, 23, 42, 0.34);
    }

    .dashboard-overview-rail {
        display: grid;
        gap: 1.25rem;
    }

    .dashboard-overview-shell,
    .dashboard-activity-shell {
        position: relative;
        overflow: hidden;
        border-radius: 1.35rem;
        border: 1px solid rgba(226, 232, 240, 0.96);
        background: rgba(255, 255, 255, 0.97);
        box-shadow: 0 18px 40px -34px rgba(15, 23, 42, 0.28);
    }

    .dashboard-overview-shell {
        padding: 1.2rem;
    }

    .dashboard-activity-shell {
        padding: 1.2rem;
    }

    .dashboard-overview-heading {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.8rem;
        margin-bottom: 1rem;
    }

    .dashboard-overview-kicker {
        display: none;
    }

    .dashboard-overview-title {
        margin: 0;
        font-size: 1.2rem;
        line-height: 1.2;
        font-weight: 800;
        letter-spacing: -0.02em;
        color: #122033;
    }

    .dashboard-overview-subtitle {
        margin-top: 0.2rem;
        font-size: 0.84rem;
        color: #6b7b8f;
    }

    .dashboard-overview-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.5rem 0.85rem;
        border-radius: 999px;
        background: rgba(24, 123, 116, 0.1);
        color: #0f766e;
        font-size: 0.79rem;
        font-weight: 700;
    }

    .dashboard-showcase-grid {
        display: grid;
        gap: 1rem;
    }

    .dashboard-showcase-card {
        position: relative;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        min-height: 14rem;
        padding: 1.25rem 1.3rem;
        border-radius: 1.55rem;
        border: 1px solid rgba(226, 232, 240, 0.86);
        transition: transform 0.18s ease, box-shadow 0.18s ease;
    }

    .dashboard-showcase-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 24px 48px -36px rgba(15, 23, 42, 0.42);
    }

    .dashboard-showcase-card.is-primary {
        border-color: rgba(24, 123, 116, 0.22);
        background: linear-gradient(135deg, #0f766e 0%, #187b74 62%, #34a38f 100%);
        color: #ffffff;
    }

    .dashboard-showcase-card.is-primary::after {
        content: "";
        position: absolute;
        inset: auto -3rem -3rem auto;
        width: 12rem;
        height: 12rem;
        border-radius: 999px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.2), transparent 68%);
        pointer-events: none;
    }

    .dashboard-showcase-card.is-soft {
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(247, 250, 255, 0.96));
        color: #16213c;
    }

    .dashboard-showcase-bottom {
        position: relative;
        z-index: 1;
    }

    .dashboard-showcase-top {
        position: relative;
        z-index: 1;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        min-width: 0;
        width: 100%;
    }

    .dashboard-showcase-label {
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: rgba(255, 255, 255, 0.72);
    }

    .dashboard-showcase-card.is-soft .dashboard-showcase-label {
        color: #7a8ea2;
    }

    .dashboard-showcase-value {
        margin-top: 0.75rem;
        font-size: clamp(1.2rem, 2vw, 1.25rem);
        line-height: 1.05;
        font-weight: 800;
        letter-spacing: -0.04em;
    }

    .dashboard-showcase-icon {
        width: 3rem;
        height: 3rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 1rem;
        background: rgba(255, 255, 255, 0.18);
        color: #ffffff;
        flex-shrink: 0;
        overflow: hidden;
        pointer-events: none;
    }

    .dashboard-showcase-icon i {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 1.4rem;
        height: 1.4rem;
        margin: 0;
        font-size: 1.35rem;
        line-height: 1;
    }

    .dashboard-showcase-card.is-soft .dashboard-showcase-icon {
        background: rgba(24, 123, 116, 0.08);
        color: #0f766e;
    }

    .dashboard-showcase-meta {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.8rem;
        margin-top: 1.1rem;
    }

    .dashboard-showcase-meta-item span {
        display: block;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: rgba(255, 255, 255, 0.68);
    }

    .dashboard-showcase-meta-item strong {
        display: block;
        margin-top: 0.28rem;
        font-size: 1rem;
        font-weight: 700;
    }

    .dashboard-showcase-card.is-soft .dashboard-showcase-meta-item span {
        color: #94a3b8;
    }

    .dashboard-showcase-card.is-soft .dashboard-showcase-meta-item strong {
        color: #16213c;
    }

    .dashboard-showcase-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        margin-top: 1.15rem;
        padding-top: 0.95rem;
        border-top: 1px solid rgba(255, 255, 255, 0.16);
        font-size: 0.8rem;
        font-weight: 700;
    }

    .dashboard-showcase-card.is-soft .dashboard-showcase-footer {
        border-top-color: rgba(226, 232, 240, 0.94);
    }

    .dashboard-showcase-footer span:first-child {
        color: rgba(255, 255, 255, 0.7);
    }

    .dashboard-showcase-card.is-soft .dashboard-showcase-footer span:first-child {
        color: #7a8ea2;
    }

    .dashboard-showcase-action {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        color: inherit;
    }

    .dashboard-metric-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.8rem;
        margin-top: 1rem;
    }

    .dashboard-metric-tile {
        padding: 0.95rem 1rem;
        border-radius: 1.2rem;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: rgba(248, 250, 255, 0.92);
        transition: transform 0.18s ease, box-shadow 0.18s ease;
    }

    .dashboard-metric-tile:hover {
        transform: translateY(-1px);
        box-shadow: 0 18px 36px -32px rgba(15, 23, 42, 0.34);
    }

    .dashboard-metric-tile span {
        display: block;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: #7a8ea2;
    }

    .dashboard-metric-tile strong {
        display: block;
        margin-top: 0.4rem;
        font-size: 1.35rem;
        font-weight: 800;
        letter-spacing: -0.03em;
        color: #16213c;
    }

    .dashboard-activity-list {
        display: grid;
        gap: 0.85rem;
    }

    .dashboard-activity-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.85rem;
        padding: 0.95rem 1rem;
        border-radius: 1.2rem;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: rgba(248, 250, 255, 0.92);
        transition: transform 0.18s ease, box-shadow 0.18s ease;
    }

    .dashboard-activity-item:hover {
        transform: translateY(-1px);
        box-shadow: 0 18px 36px -32px rgba(15, 23, 42, 0.34);
    }

    .dashboard-activity-main {
        display: flex;
        align-items: center;
        gap: 0.8rem;
        min-width: 0;
        flex: 1;
    }

    .dashboard-activity-mark {
        width: 2.7rem;
        height: 2.7rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 1rem;
        flex-shrink: 0;
    }

    .dashboard-activity-mark.is-info {
        background: rgba(24, 123, 116, 0.12);
        color: #0f766e;
    }

    .dashboard-activity-mark.is-success {
        background: rgba(16, 185, 129, 0.12);
        color: #059669;
    }

    .dashboard-activity-mark.is-danger {
        background: rgba(239, 68, 68, 0.12);
        color: #dc2626;
    }

    .dashboard-activity-mark.is-neutral {
        background: rgba(148, 163, 184, 0.16);
        color: #475569;
    }

    .dashboard-activity-copy {
        min-width: 0;
    }

    .dashboard-activity-title {
        font-size: 0.92rem;
        font-weight: 700;
        color: #16213c;
        line-height: 1.35;
    }

    .dashboard-activity-subtitle {
        margin-top: 0.22rem;
        font-size: 0.78rem;
        color: #7a8ea2;
    }

    .dashboard-activity-value {
        min-width: 4.4rem;
        text-align: right;
        font-size: 0.82rem;
        font-weight: 700;
        color: #0f5f59;
    }

    .dashboard-activity-meta {
        display: block;
        margin-top: 0.28rem;
        font-size: 0.72rem;
        color: #94a3b8;
    }

    .dashboard-activity-empty {
        padding: 2rem 1rem;
        text-align: center;
        color: #94a3b8;
    }

    .dashboard-reminder-list {
        display: grid;
        gap: 0.8rem;
    }

    .dashboard-reminder-item {
        display: grid;
        gap: 0.75rem;
        padding: 0.95rem;
        border-radius: 1.15rem;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: rgba(248, 250, 255, 0.94);
        transition: transform 0.18s ease, box-shadow 0.18s ease;
    }

    .dashboard-reminder-item:hover {
        transform: translateY(-1px);
        box-shadow: 0 18px 36px -32px rgba(15, 23, 42, 0.34);
    }

    .dashboard-reminder-head {
        display: flex;
        align-items: flex-start;
        gap: 0.72rem;
        min-width: 0;
    }

    .dashboard-reminder-icon {
        width: 2.55rem;
        height: 2.55rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 0.95rem;
        flex-shrink: 0;
    }

    .dashboard-reminder-icon.is-info {
        background: rgba(24, 123, 116, 0.12);
        color: #0f766e;
    }

    .dashboard-reminder-icon.is-success {
        background: rgba(16, 185, 129, 0.12);
        color: #059669;
    }

    .dashboard-reminder-icon.is-danger {
        background: rgba(239, 68, 68, 0.12);
        color: #dc2626;
    }

    .dashboard-reminder-title {
        font-size: 0.84rem;
        font-weight: 700;
        line-height: 1.45;
        color: #16213c;
        word-break: break-word;
    }

    .dashboard-reminder-subtitle {
        margin-top: 0.18rem;
        font-size: 0.72rem;
        line-height: 1.45;
        color: #7a8ea2;
        word-break: break-word;
    }

    .dashboard-reminder-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.45rem;
    }

    .dashboard-reminder-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        max-width: 100%;
        padding: 0.42rem 0.62rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.96);
        border: 1px solid rgba(226, 232, 240, 0.92);
        font-size: 0.7rem;
        font-weight: 700;
        color: #5b6b82;
        line-height: 1.3;
        word-break: break-word;
    }

    .dashboard-stat-grid,
    .dashboard-chart-grid,
    .dashboard-action-grid {
        display: grid;
        gap: 1rem;
    }

    .dashboard-stat-card,
    .dashboard-chart-card,
    .dashboard-project-shell,
    .dashboard-action-shell {
        position: relative;
        overflow: hidden;
        border-radius: 1.35rem;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: linear-gradient(160deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.95));
        box-shadow: 0 22px 50px -38px rgba(15, 23, 42, 0.42);
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    }

    .dashboard-stat-card:hover,
    .dashboard-chart-card:hover,
    .dashboard-project-shell:hover,
    .dashboard-action-link:hover,
    .dashboard-action-link:focus-visible {
        transform: translateY(-2px);
        box-shadow: 0 28px 58px -36px rgba(15, 23, 42, 0.48);
    }

    .dashboard-stat-card::before,
    .dashboard-chart-card::before,
    .dashboard-action-link::before {
        content: "";
        position: absolute;
        inset: 0 0 auto 0;
        height: 4px;
        background: linear-gradient(90deg, var(--card-accent, #0f766e), transparent);
    }

    .dashboard-stat-card::after,
    .dashboard-chart-card::after,
    .dashboard-action-link::after {
        content: "";
        position: absolute;
        top: -4.5rem;
        right: -3.5rem;
        width: 12rem;
        height: 12rem;
        border-radius: 999px;
        background: radial-gradient(circle, var(--card-surface, rgba(15, 118, 110, 0.12)), transparent 68%);
        pointer-events: none;
    }

    .dashboard-chart-inner {
        position: relative;
        padding: 1.35rem;
    }

    .dashboard-chart-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.6rem;
        margin-top: 1rem;
    }

    .dashboard-chart-meta-item {
        min-width: 7rem;
        padding: 0.72rem 0.85rem;
        border-radius: 1rem;
        background: rgba(248, 250, 252, 0.88);
        border: 1px solid rgba(226, 232, 240, 0.92);
    }

    .dashboard-chart-meta-item span {
        display: block;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: #7a8ea2;
    }

    .dashboard-chart-meta-item strong {
        display: block;
        margin-top: 0.2rem;
        font-size: 1rem;
        color: #122033;
    }

    .dashboard-chart-frame {
        position: relative;
        margin-top: 1rem;
        padding: 1rem;
        border-radius: 1.2rem;
        border: 1px solid rgba(226, 232, 240, 0.9);
        background: linear-gradient(180deg, rgba(248, 250, 252, 0.88), rgba(255, 255, 255, 0.98));
    }

    .dashboard-chart-canvas {
        position: relative;
        height: 320px;
        width: 100%;
    }

    .dashboard-chart-canvas.is-compact {
        max-width: 320px;
        margin: 0 auto;
    }

    .dashboard-stat-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.85rem;
    }

    .dashboard-stat-label {
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #7a8ea2;
    }

    .dashboard-stat-value {
        margin-top: 0.55rem;
        font-size: 2rem;
        line-height: 1.02;
        font-weight: 800;
        letter-spacing: -0.04em;
        color: #122033;
    }

    .dashboard-stat-value.is-money {
        font-size: 1.65rem;
    }

    .dashboard-stat-icon {
        width: 2.45rem;
        height: 2.45rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 0.9rem;
        background: var(--card-surface, rgba(15, 118, 110, 0.12));
        color: var(--card-accent, #0f766e);
        flex-shrink: 0;
    }

    .dashboard-stat-icon i {
        font-size: 1.2rem;
    }

    .dashboard-stat-footer {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        margin-top: 1rem;
        padding-top: 0.9rem;
        border-top: 1px solid rgba(226, 232, 240, 0.82);
    }

    .dashboard-stat-trend {
        display: inline-flex;
        align-items: center;
        gap: 0.34rem;
        padding: 0.42rem 0.68rem;
        border-radius: 999px;
        font-size: 0.74rem;
        font-weight: 700;
        line-height: 1;
    }

    .dashboard-stat-trend i {
        font-size: 0.95rem;
    }

    .dashboard-stat-link {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        color: var(--card-accent, #0f766e);
        font-size: 0.78rem;
        font-weight: 700;
        white-space: nowrap;
    }

    .dashboard-stat-link i {
        font-size: 1rem;
    }

    .dashboard-stat-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 0.45rem;
    }

    .dashboard-stat-tag {
        display: inline-flex;
        align-items: center;
        padding: 0.38rem 0.62rem;
        border-radius: 999px;
        border: 1px solid rgba(226, 232, 240, 0.9);
        font-size: 0.72rem;
        font-weight: 700;
        line-height: 1;
    }

    .dashboard-action-link {
        position: relative;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        gap: 0.85rem;
        min-height: 10.8rem;
        padding: 1.15rem;
        border-radius: 1.25rem;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: linear-gradient(160deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.95));
        box-shadow: 0 22px 50px -38px rgba(15, 23, 42, 0.42);
        color: #122033;
        outline: none;
        isolation: isolate;
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease, background 0.18s ease;
    }

    .dashboard-action-link:hover,
    .dashboard-action-link:focus-visible {
        border-color: color-mix(in srgb, var(--card-accent, #0f766e) 34%, rgba(226, 232, 240, 0.92));
        background: linear-gradient(160deg, #ffffff, color-mix(in srgb, var(--card-surface, rgba(15, 118, 110, 0.12)) 30%, #ffffff));
    }

    .dashboard-action-link:focus-visible {
        box-shadow: 0 0 0 3px rgba(24, 123, 116, 0.14), 0 28px 58px -36px rgba(15, 23, 42, 0.48);
    }

    .dashboard-action-link > * {
        position: relative;
        z-index: 1;
    }

    .dashboard-action-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.9rem;
    }

    .dashboard-action-copy {
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
        min-width: 0;
    }

    .dashboard-action-title {
        color: #122033;
        font-size: 0.98rem;
        line-height: 1.25;
        font-weight: 800;
        letter-spacing: -0.02em;
    }

    .dashboard-action-desc {
        color: #64748b;
        font-size: 0.84rem;
        line-height: 1.45;
    }

    .dashboard-action-pill {
        display: inline-flex;
        align-items: center;
        align-self: flex-start;
        white-space: nowrap;
        padding: 0.34rem 0.58rem;
        border-radius: 999px;
        background: var(--card-surface, rgba(15, 118, 110, 0.12));
        color: var(--card-accent, #0f766e);
        font-size: 0.68rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .dashboard-project-shell .grid > a {
        position: relative;
        overflow: hidden;
        border-radius: 1.15rem;
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: linear-gradient(160deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.95));
        box-shadow: 0 22px 50px -38px rgba(15, 23, 42, 0.34);
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    }

    .dashboard-project-shell .grid > a:hover {
        transform: translateY(-2px);
        border-color: rgba(24, 123, 116, 0.18);
        box-shadow: 0 28px 58px -36px rgba(15, 23, 42, 0.42);
    }

    .dashboard-action-icon {
        width: 3rem;
        height: 3rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 1rem;
        background: var(--card-surface, rgba(15, 118, 110, 0.12));
        color: var(--card-accent, #0f766e);
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.62);
        flex-shrink: 0;
        transition: transform 0.18s ease, background-color 0.18s ease;
    }

    .dashboard-action-link:hover .dashboard-action-icon,
    .dashboard-action-link:focus-visible .dashboard-action-icon {
        transform: scale(1.04);
        background: color-mix(in srgb, var(--card-surface, rgba(15, 118, 110, 0.12)) 78%, #ffffff);
    }

    .dashboard-action-arrow {
        display: inline-flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.35rem;
        margin-top: auto;
        padding-top: 0.9rem;
        border-top: 1px solid rgba(226, 232, 240, 0.82);
        color: var(--card-accent, #0f766e);
        font-size: 0.82rem;
        font-weight: 700;
    }

    .dashboard-action-arrow i {
        transition: transform 0.18s ease;
    }

    .dashboard-action-link:hover .dashboard-action-arrow i,
    .dashboard-action-link:focus-visible .dashboard-action-arrow i {
        transform: translateX(3px);
    }

    .dashboard-action-intro {
        margin: -0.25rem 0 0;
        color: #64748b;
        font-size: 0.82rem;
        line-height: 1.45;
    }

    .dashboard-section-header {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        margin-bottom: 1.25rem;
    }

    .dashboard-inline-link {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.8rem;
        font-weight: 700;
    }

    .dashboard-home-shell {
        gap: 1rem;
        align-items: flex-start;
        padding: 1rem;
        /* `overflow-x: hidden` on .todo-shell breaks `position: sticky` for descendants. */
        overflow-x: visible;
    }

    .dashboard-home-main {
        gap: 1rem;
        margin: 0;
        padding: 0;
        max-width: none;
    }

    .dashboard-hero-card {
        position: relative;
        overflow: hidden;
        display: grid;
        grid-template-columns: minmax(0, 1.05fr) minmax(19rem, 0.85fr);
        align-items: stretch;
        gap: 1.25rem;
        min-height: 16rem;
        padding: clamp(1.1rem, 2vw, 1.65rem);
        border-radius: 1.55rem;
        color: #ffffff;
        --hero-bg-img: none;
        background-color: #26215f;
        background-image:
            linear-gradient(135deg, rgba(38, 33, 95, 0.55) 0%, rgba(109, 63, 184, 0.45) 45%, rgba(217, 70, 151, 0.45) 100%),
            radial-gradient(circle at 82% 16%, rgba(255, 255, 255, 0.18), transparent 32%),
            radial-gradient(circle at 8% 100%, rgba(14, 165, 233, 0.22), transparent 34%),
            var(--hero-bg-img),
            linear-gradient(135deg, #26215f 0%, #6d3fb8 45%, #d94697 100%);
        background-size: cover, auto, auto, cover, cover;
        background-position: center, center, center, center, center;
        background-repeat: no-repeat;
        box-shadow: 0 30px 70px -46px rgba(31, 41, 55, 0.72);
        transition: background-image 0.6s ease;
    }

    .dashboard-hero-card::after {
        content: "";
        position: absolute;
        right: -5rem;
        bottom: -6rem;
        width: 18rem;
        height: 18rem;
        border-radius: 999px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.18), transparent 68%);
        pointer-events: none;
    }

    .dashboard-hero-copy,
    .dashboard-hero-aside {
        position: relative;
        z-index: 1;
    }

    .dashboard-hero-copy {
        max-width: 46rem;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        gap: 0.9rem;
    }

    .dashboard-hero-kicker {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.48rem 0.78rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.16);
        color: rgba(255, 255, 255, 0.88);
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        backdrop-filter: blur(12px);
    }

    .dashboard-hero-kicker i {
        font-size: 1rem;
    }

    .dashboard-hero-title {
        margin: 0.7rem 0 0;
        max-width: 44rem;
        font-size: clamp(1.85rem, 3.4vw, 2.85rem);
        line-height: 1;
        font-weight: 850;
        letter-spacing: -0.06em;
    }

    .dashboard-hero-subtitle {
        max-width: 36rem;
        margin: 0.55rem 0 0;
        color: rgba(255, 255, 255, 0.76);
        font-size: 0.88rem;
        line-height: 1.55;
    }

    .dashboard-hero-cta-row {
        display: flex;
        flex-wrap: wrap;
        gap: 0.65rem;
        margin-top: auto;
        align-items: center;
    }

    .dashboard-hero-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.45rem;
        padding: 0.78rem 1.4rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.18);
        border: 1px solid rgba(255, 255, 255, 0.22);
        color: #ffffff;
        font-size: 0.85rem;
        font-weight: 800;
        backdrop-filter: blur(12px);
        transition: transform 0.18s ease, background-color 0.18s ease;
    }

    .dashboard-hero-button:hover {
        transform: translateY(-1px);
        background: rgba(255, 255, 255, 0.26);
    }

    .dashboard-hero-button.is-primary {
        background: #ffffff;
        color: #6d3fb8;
        border-color: rgba(255, 255, 255, 0.9);
        box-shadow: 0 18px 38px -24px rgba(255, 255, 255, 0.7);
    }

    .dashboard-hero-button.is-primary:hover {
        background: #f8fafc;
    }

    .dashboard-hero-button i {
        font-size: 1.05rem;
    }

    .dashboard-hero-aside {
        display: flex;
        flex-direction: column;
        gap: 0.7rem;
        min-width: 0;
    }

    .weather-card {
        position: relative;
        overflow: hidden;
        padding: 0.85rem 1rem;
        border-radius: 1.1rem;
        border: 1px solid rgba(255, 255, 255, 0.22);
        background: rgba(255, 255, 255, 0.14);
        color: #ffffff;
        backdrop-filter: blur(18px);
        -webkit-backdrop-filter: blur(18px);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.18), 0 22px 40px -34px rgba(15, 23, 42, 0.55);
    }

    .weather-card__kicker {
        display: inline-block;
        font-size: 0.62rem;
        font-weight: 800;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: rgba(255, 255, 255, 0.78);
    }

    .weather-card__big {
        display: block;
        margin-top: 0.2rem;
        font-size: clamp(1.55rem, 2.1vw, 2.05rem);
        line-height: 1;
        font-weight: 850;
        letter-spacing: -0.04em;
        font-variant-numeric: tabular-nums;
        color: #ffffff;
    }

    .weather-card__sub {
        display: block;
        margin-top: 0.25rem;
        color: rgba(255, 255, 255, 0.78);
        font-size: 0.74rem;
        font-weight: 700;
    }

    .weather-card__decor {
        width: 2.25rem;
        height: 2.25rem;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: rgba(255, 255, 255, 0.16);
        color: rgba(255, 255, 255, 0.85);
        flex-shrink: 0;
    }

    .weather-card__decor i {
        font-size: 1.25rem;
        line-height: 1;
    }

    .weather-card--clock {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        padding: 0.7rem 0.95rem;
        max-width: 24rem;
    }

    .weather-card--clock__copy {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    .weather-card--clock .weather-card__kicker {
        font-size: 0.58rem;
        letter-spacing: 0.14em;
    }

    .weather-card--clock .weather-card__big {
        margin-top: 0.15rem;
        font-size: clamp(1.45rem, 2vw, 1.85rem);
    }

    .weather-card--clock .weather-card__sub {
        margin-top: 0.15rem;
        font-size: 0.72rem;
    }

    .weather-card--current {
        padding: 0.85rem 1rem 0.95rem;
    }

    .weather-card__head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
    }

    .weather-card__edit {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 1.7rem;
        height: 1.7rem;
        border-radius: 999px;
        border: 1px solid rgba(255, 255, 255, 0.28);
        background: rgba(255, 255, 255, 0.14);
        color: #ffffff;
        cursor: pointer;
        transition: background-color 0.18s ease, transform 0.18s ease;
        padding: 0;
    }

    .weather-card__edit:hover {
        background: rgba(255, 255, 255, 0.28);
        transform: scale(1.05);
    }

    .weather-card__edit i {
        font-size: 0.95rem;
        line-height: 1;
    }

    .weather-card__city {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        margin-top: 0.4rem;
        color: rgba(255, 255, 255, 0.92);
        font-size: 0.85rem;
        font-weight: 700;
    }

    .weather-card__city i {
        font-size: 1rem;
        line-height: 1;
        color: rgba(255, 255, 255, 0.85);
    }

    .weather-card__body {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 0.6rem;
        margin-top: 0.4rem;
    }

    .weather-card__readings {
        display: flex;
        flex-direction: column;
        gap: 0.1rem;
        min-width: 0;
    }

    .weather-card__temp {
        display: inline-flex;
        align-items: flex-start;
        line-height: 1;
        font-weight: 850;
        letter-spacing: -0.05em;
        color: #ffffff;
    }

    .weather-card__temp strong {
        font-size: clamp(2rem, 2.8vw, 2.6rem);
        line-height: 1;
        font-variant-numeric: tabular-nums;
    }

    .weather-card__temp sup {
        margin-left: 0.18rem;
        margin-top: 0.4rem;
        font-size: 0.9rem;
        font-weight: 800;
        color: rgba(255, 255, 255, 0.85);
    }

    .weather-card__condition {
        font-size: 0.92rem;
        font-weight: 800;
        color: #ffffff;
    }

    .weather-card__description {
        font-size: 0.72rem;
        font-weight: 600;
        color: rgba(255, 255, 255, 0.82);
        line-height: 1.4;
    }

    .weather-card__icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 3.4rem;
        height: 3.4rem;
        border-radius: 0.95rem;
        background: linear-gradient(140deg, rgba(255, 255, 255, 0.32), rgba(255, 255, 255, 0.12));
        color: #ffffff;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.4), 0 18px 30px -22px rgba(15, 23, 42, 0.55);
        flex-shrink: 0;
        overflow: hidden;
    }

    .weather-card__icon i {
        font-size: 2rem;
        line-height: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .weather-card__chips {
        display: flex;
        flex-wrap: wrap;
        gap: 0.3rem;
        margin-top: 0.6rem;
        padding-top: 0.55rem;
        border-top: 1px solid rgba(255, 255, 255, 0.18);
    }

    .weather-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.3rem 0.55rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.16);
        border: 1px solid rgba(255, 255, 255, 0.18);
        color: rgba(255, 255, 255, 0.92);
        font-size: 0.66rem;
        font-weight: 700;
    }

    .weather-chip strong {
        margin-left: 0.1rem;
        color: #ffffff;
        font-weight: 800;
    }

    .weather-chip i {
        font-size: 0.95rem;
        color: rgba(255, 255, 255, 0.85);
    }

    .weather-card__editor {
        margin-top: 0.85rem;
        padding-top: 0.85rem;
        border-top: 1px solid rgba(255, 255, 255, 0.18);
        display: flex;
        flex-direction: column;
        gap: 0.55rem;
    }

    .weather-card__editor[hidden] {
        display: none !important;
    }

    .weather-editor-field {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.55rem 0.75rem;
        border-radius: 0.85rem;
        background: rgba(255, 255, 255, 0.18);
        border: 1px solid rgba(255, 255, 255, 0.28);
    }

    .weather-editor-field i {
        font-size: 1rem;
        color: rgba(255, 255, 255, 0.85);
    }

    .weather-editor-field input {
        flex: 1;
        min-width: 0;
        background: transparent;
        border: 0;
        outline: none;
        color: #ffffff;
        font-size: 0.85rem;
        font-weight: 600;
    }

    .weather-editor-field input::placeholder {
        color: rgba(255, 255, 255, 0.6);
    }

    .weather-editor-results {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        max-height: 11rem;
        overflow-y: auto;
    }

    .weather-editor-results:empty {
        display: none;
    }

    .weather-editor-result {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        padding: 0.5rem 0.7rem;
        border-radius: 0.7rem;
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.16);
        color: rgba(255, 255, 255, 0.95);
        font-size: 0.8rem;
        font-weight: 700;
        cursor: pointer;
        transition: background-color 0.18s ease;
    }

    .weather-editor-result:hover {
        background: rgba(255, 255, 255, 0.22);
    }

    .weather-editor-result small {
        font-weight: 600;
        color: rgba(255, 255, 255, 0.7);
    }

    .weather-editor-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
        align-items: center;
        justify-content: space-between;
    }

    .weather-editor-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.45rem 0.8rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.16);
        border: 1px solid rgba(255, 255, 255, 0.22);
        color: #ffffff;
        font-size: 0.75rem;
        font-weight: 700;
        cursor: pointer;
        transition: background-color 0.18s ease;
    }

    .weather-editor-btn:hover {
        background: rgba(255, 255, 255, 0.26);
    }

    .weather-editor-btn i {
        font-size: 0.95rem;
    }

    .weather-editor-btn--ghost {
        background: transparent;
    }

    .weather-editor-hint {
        margin: 0;
        font-size: 0.72rem;
        color: rgba(255, 255, 255, 0.78);
        font-weight: 600;
        min-height: 1em;
    }

    .weather-card--clock .weather-card__decor svg.lucide {
        width: 1.25rem;
        height: 1.25rem;
        animation: weatherClockTick 60s linear infinite;
    }

    @keyframes weatherClockTick {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    .weather-forecast-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.4rem;
        margin-top: 0.45rem;
    }

    .weather-forecast-slot {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.2rem;
        padding: 0.45rem 0.3rem;
        border-radius: 0.75rem;
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.18);
        color: #ffffff;
        text-align: center;
    }

    .weather-forecast-slot__time {
        font-size: 0.66rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        color: rgba(255, 255, 255, 0.82);
    }

    .weather-forecast-slot__icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 1.95rem;
        height: 1.95rem;
        border-radius: 999px;
        background: linear-gradient(140deg, rgba(255, 255, 255, 0.3), rgba(255, 255, 255, 0.1));
        color: #ffffff;
        overflow: hidden;
    }

    .weather-forecast-slot__icon i {
        font-size: 1.15rem;
        line-height: 1;
    }

    .weather-forecast-slot__temp {
        font-size: 0.82rem;
        font-weight: 800;
        font-variant-numeric: tabular-nums;
        color: #ffffff;
    }

    .sr-only {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }

    .dashboard-hero-metrics {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 1rem;
    }

    .dashboard-hero-metric-card {
        position: relative;
        min-height: 12rem;
        padding: 1.2rem;
        border-radius: 1.25rem;
        border: 1px solid rgba(226, 232, 240, 0.86);
        background: rgba(255, 255, 255, 0.96);
        box-shadow: 0 22px 48px -42px rgba(15, 23, 42, 0.45);
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    }

    .dashboard-hero-metric-card:hover {
        transform: translateY(-2px);
        border-color: rgba(124, 58, 237, 0.22);
        box-shadow: 0 26px 56px -38px rgba(15, 23, 42, 0.5);
    }

    .dashboard-hero-metric-card[data-tone="violet"] { --metric-accent: #7c3aed; --metric-soft: rgba(124, 58, 237, 0.11); }
    .dashboard-hero-metric-card[data-tone="pink"] { --metric-accent: #db2777; --metric-soft: rgba(219, 39, 119, 0.11); }
    .dashboard-hero-metric-card[data-tone="amber"] { --metric-accent: #d97706; --metric-soft: rgba(217, 119, 6, 0.12); }
    .dashboard-hero-metric-card[data-tone="green"] { --metric-accent: #059669; --metric-soft: rgba(5, 150, 105, 0.12); }
    .dashboard-hero-metric-card[data-tone="cyan"] { --metric-accent: #0891b2; --metric-soft: rgba(8, 145, 178, 0.12); }

    .dashboard-hero-metric-icon {
        width: 2.65rem;
        height: 2.65rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: var(--metric-soft, rgba(15, 118, 110, 0.1));
        color: var(--metric-accent, #0f766e);
    }

    .dashboard-hero-metric-icon i {
        font-size: 1.28rem;
    }

    .dashboard-hero-metric-label,
    .dashboard-hero-metric-meta {
        display: block;
        color: #8a94a6;
        font-size: 0.72rem;
        font-weight: 700;
    }

    .dashboard-hero-metric-label {
        margin-top: 1rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }

    .dashboard-hero-metric-value {
        display: block;
        margin-top: 0.45rem;
        color: #293142;
        font-size: clamp(1.45rem, 2vw, 1.9rem);
        line-height: 1.05;
        font-weight: 850;
        letter-spacing: -0.04em;
        word-break: break-word;
    }

    .dashboard-hero-metric-growth {
        display: inline-flex;
        align-items: center;
        gap: 0.15rem;
        margin-top: 0.75rem;
        color: #ef4444;
        font-size: 0.72rem;
        font-weight: 800;
    }

    .dashboard-hero-metric-growth.is-up {
        color: #10b981;
    }

    .dashboard-hero-metric-growth i {
        font-size: 1rem;
    }

    .dashboard-hero-metric-meta {
        margin-top: 0.2rem;
        font-weight: 600;
        letter-spacing: 0;
        text-transform: none;
    }

    .dashboard-priority-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 1.1rem;
    }

    .dashboard-priority-grid .dashboard-focus-card,
    .dashboard-workspace-shortcuts {
        position: relative;
        overflow: hidden;
        padding: 1.5rem;
        border-radius: 1.55rem;
        border: 1px solid rgba(206, 216, 226, 0.92);
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(246, 250, 248, 0.98));
        box-shadow: 0 22px 46px -40px rgba(15, 23, 42, 0.42);
    }

    .dashboard-priority-grid .dashboard-focus-card::before,
    .dashboard-workspace-shortcuts::before {
        content: "";
        position: absolute;
        inset: 0;
        pointer-events: none;
        background:
            radial-gradient(circle at top right, rgba(217, 70, 151, 0.08), transparent 34%),
            linear-gradient(90deg, rgba(109, 63, 184, 0.04), transparent 32%);
    }

    .dashboard-priority-grid .dashboard-focus-card > *,
    .dashboard-workspace-shortcuts > * {
        position: relative;
        z-index: 1;
    }

    .dashboard-workspace-shortcuts .todo-tile-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1.1rem;
        margin-top: 1.15rem;
        margin-bottom: 0;
    }

    .dashboard-workspace-shortcuts .todo-tile {
        min-height: 10.5rem;
        flex-direction: column;
        gap: 1rem;
        padding: 1.25rem;
        border-radius: 1.25rem;
        background:
            radial-gradient(circle at top right, rgba(255, 255, 255, 0.95), transparent 44%),
            linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.94));
        box-shadow: none;
    }

    .dashboard-workspace-shortcuts .todo-tile:hover,
    .dashboard-workspace-shortcuts .todo-tile:focus-visible {
        transform: translateY(-2px);
        box-shadow: 0 24px 50px -38px rgba(15, 23, 42, 0.4);
    }

    .dashboard-workspace-shortcuts .todo-tile-icon {
        width: 3rem;
        height: 3rem;
        border-radius: 999px;
    }

    .dashboard-workspace-shortcuts .todo-tile-body {
        gap: 0.45rem;
    }

    .dashboard-workspace-shortcuts .todo-tile-label {
        color: #7a8ea2;
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.08em;
    }

    .dashboard-workspace-shortcuts .todo-tile-value {
        color: #293142;
        font-size: clamp(1.6rem, 2.4vw, 2.15rem);
        font-weight: 850;
        letter-spacing: -0.05em;
        line-height: 1.05;
    }

    .dashboard-workspace-shortcuts .todo-tile-hint {
        color: #7a8ea2;
        font-size: 0.78rem;
        font-weight: 600;
    }

    .dashboard-calendar-sidebar {
        width: min(330px, 28vw);
        flex: 0 0 min(330px, 28vw);
        display: grid;
        align-content: start;
        gap: 1rem;
        position: sticky;
        top: 1rem;
        z-index: 2;
        align-self: flex-start;
        height: calc(100vh - 2rem);
        max-height: calc(100vh - 2rem);
        overflow-y: auto;
        overscroll-behavior: contain;
        scrollbar-width: thin;
        scrollbar-color: rgba(24, 123, 116, 0.35) transparent;
        padding-right: 6px;
    }

    .dashboard-calendar-sidebar::-webkit-scrollbar {
        width: 8px;
    }

    .dashboard-calendar-sidebar::-webkit-scrollbar-track {
        background: transparent;
    }

    .dashboard-calendar-sidebar::-webkit-scrollbar-thumb {
        background-color: rgba(24, 123, 116, 0.28);
        border-radius: 999px;
        border: 2px solid transparent;
        background-clip: padding-box;
    }

    .dashboard-calendar-sidebar::-webkit-scrollbar-thumb:hover {
        background-color: rgba(24, 123, 116, 0.5);
    }

    .dashboard-calendar-card,
    .dashboard-schedule-card {
        border: 1px solid rgba(226, 232, 240, 0.92);
        background: rgba(255, 255, 255, 0.98);
        box-shadow: 0 24px 54px -44px rgba(15, 23, 42, 0.44);
    }

    .dashboard-debtors-card {
        position: relative;
        overflow: hidden;
        padding: 1rem;
        border: 1px solid rgba(226, 232, 240, 0.92);
        border-radius: 1.35rem;
        background:
            radial-gradient(circle at top right, rgba(16, 185, 129, 0.12), transparent 35%),
            linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(248, 250, 252, 0.98));
        box-shadow: 0 24px 54px -44px rgba(15, 23, 42, 0.44);
    }

    .dashboard-debtors-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem;
    }

    .dashboard-debtors-kicker {
        display: block;
        font-size: 0.66rem;
        font-weight: 800;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #0f766e;
    }

    .dashboard-debtors-title {
        display: block;
        margin-top: 0.18rem;
        font-size: 0.98rem;
        line-height: 1.2;
        font-weight: 850;
        letter-spacing: -0.03em;
        color: #14302d;
    }

    .dashboard-debtors-total {
        flex-shrink: 0;
        max-width: 9rem;
        text-align: right;
        font-size: 0.78rem;
        font-weight: 850;
        line-height: 1.2;
        color: #122033;
    }

    .dashboard-debtors-total span {
        display: block;
        margin-top: 0.22rem;
        color: #7a8ea2;
        font-size: 0.64rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .dashboard-debtors-summary {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.55rem;
        margin-top: 0.8rem;
    }

    .dashboard-debtors-chip {
        padding: 0.55rem 0.65rem;
        border-radius: 0.9rem;
        border: 1px solid rgba(226, 232, 240, 0.9);
        background: rgba(255, 255, 255, 0.72);
    }

    .dashboard-debtors-chip span {
        display: block;
        font-size: 0.62rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #8a94a6;
    }

    .dashboard-debtors-chip strong {
        display: block;
        margin-top: 0.18rem;
        color: #14302d;
        font-size: 0.92rem;
        font-weight: 850;
    }

    .dashboard-debtors-table-wrap {
        margin-top: 0.85rem;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .dashboard-debtors-table {
        width: 100%;
        min-width: 300px;
        border-collapse: separate;
        border-spacing: 0 0.42rem;
    }

    .dashboard-debtors-table th {
        padding: 0 0.35rem 0.1rem;
        color: #94a3b8;
        font-size: 0.62rem;
        font-weight: 850;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .dashboard-debtors-table td {
        padding: 0.55rem 0.35rem;
        background: rgba(255, 255, 255, 0.82);
        border-top: 1px solid rgba(226, 232, 240, 0.86);
        border-bottom: 1px solid rgba(226, 232, 240, 0.86);
        vertical-align: middle;
    }

    .dashboard-debtors-table td:first-child {
        border-left: 1px solid rgba(226, 232, 240, 0.86);
        border-radius: 0.9rem 0 0 0.9rem;
    }

    .dashboard-debtors-table td:last-child {
        border-right: 1px solid rgba(226, 232, 240, 0.86);
        border-radius: 0 0.9rem 0.9rem 0;
    }

    .dashboard-debtor-name {
        display: block;
        max-width: 8rem;
        color: #16213c;
        font-size: 0.76rem;
        font-weight: 800;
        line-height: 1.25;
    }

    .dashboard-debtor-meta {
        display: block;
        margin-top: 0.18rem;
        color: #8a94a6;
        font-size: 0.66rem;
        font-weight: 700;
    }

    .dashboard-debtor-balance {
        color: #14302d;
        font-size: 0.72rem;
        font-weight: 850;
        line-height: 1.25;
        white-space: nowrap;
    }

    .dashboard-debtor-age {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 3.4rem;
        padding: 0.28rem 0.42rem;
        border-radius: 999px;
        font-size: 0.65rem;
        font-weight: 850;
        white-space: nowrap;
    }

    .dashboard-debtor-age[data-tone="success"] {
        background: rgba(16, 185, 129, 0.12);
        color: #047857;
    }

    .dashboard-debtor-age[data-tone="info"] {
        background: rgba(14, 165, 233, 0.12);
        color: #0369a1;
    }

    .dashboard-debtor-age[data-tone="warning"] {
        background: rgba(245, 158, 11, 0.14);
        color: #92400e;
    }

    .dashboard-debtor-age[data-tone="danger"],
    .dashboard-debtor-age[data-tone="critical"] {
        background: rgba(239, 68, 68, 0.12);
        color: #b91c1c;
    }

    .dashboard-debtor-actions {
        display: inline-flex;
        align-items: center;
        justify-content: flex-end;
        gap: 0.28rem;
    }

    .dashboard-debtor-action {
        width: 1.85rem;
        height: 1.85rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: rgba(15, 118, 110, 0.09);
        color: #0f766e;
        transition: transform 0.18s ease, background 0.18s ease, color 0.18s ease;
    }

    .dashboard-debtor-action:hover,
    .dashboard-debtor-action:focus-visible {
        transform: translateY(-1px);
        background: #0f766e;
        color: #ffffff;
    }

    .dashboard-debtor-action i {
        font-size: 1rem;
    }

    .dashboard-debtors-empty {
        margin-top: 0.85rem;
        padding: 1.2rem 0.8rem;
        border-radius: 1rem;
        background: rgba(255, 255, 255, 0.78);
        color: #64748b;
        text-align: center;
        font-size: 0.78rem;
        font-weight: 700;
    }

    .dashboard-debtors-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.7rem;
        margin-top: 0.8rem;
        padding-top: 0.75rem;
        border-top: 1px solid rgba(226, 232, 240, 0.84);
        color: #7a8ea2;
        font-size: 0.68rem;
        font-weight: 700;
    }

    .dashboard-debtors-footer a {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        color: #0f766e;
        font-size: 0.72rem;
        font-weight: 850;
        white-space: nowrap;
    }

    .dashboard-calendar-card {
        padding: 0.95rem;
        border-radius: 1.35rem;
    }

    .dashboard-calendar-nav {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto auto;
        gap: 0.45rem;
        align-items: center;
    }

    .dashboard-calendar-arrow {
        width: 2rem;
        height: 2rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        color: #a0a9b8;
    }

    .dashboard-calendar-arrow:hover {
        background: #f3f6fb;
        color: #d94697;
    }

    .dashboard-calendar-select {
        min-width: 0;
        height: 2.05rem;
        border: 1px solid #edf0f5;
        border-radius: 0.6rem;
        background: #ffffff;
        color: #596275;
        font-size: 0.78rem;
        font-weight: 700;
        padding: 0 0.55rem;
    }

    .dashboard-calendar-year {
        height: 2.05rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 0.65rem;
        border-radius: 0.6rem;
        background: #f8fafc;
        color: #3f4758;
        font-size: 0.8rem;
        font-weight: 800;
    }

    .dashboard-calendar-weekdays,
    .dashboard-calendar-grid {
        display: grid;
        grid-template-columns: repeat(7, minmax(0, 1fr));
        gap: 0.35rem;
    }

    .dashboard-calendar-weekdays {
        margin-top: 1rem;
    }

    .dashboard-calendar-weekdays span {
        color: #777f91;
        font-size: 0.66rem;
        font-weight: 800;
        text-align: center;
    }

    .dashboard-calendar-grid {
        margin-top: 0.55rem;
    }

    .dashboard-calendar-day {
        position: relative;
        min-height: 2.05rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        color: #3d4657;
        font-size: 0.8rem;
        font-weight: 800;
    }

    .dashboard-calendar-day.is-muted {
        color: #c1c8d3;
    }

    .dashboard-calendar-day.is-today {
        color: #d94697;
        background: rgba(217, 70, 151, 0.08);
    }

    .dashboard-calendar-day.is-selected {
        color: #ffffff;
        background: linear-gradient(135deg, #d94697, #c026d3);
        box-shadow: 0 12px 22px -16px rgba(217, 70, 151, 0.8);
    }

    .dashboard-calendar-day i {
        position: absolute;
        bottom: 0.2rem;
        width: 0.28rem;
        height: 0.28rem;
        border-radius: 999px;
        background: currentColor;
    }

    .dashboard-schedule-card {
        padding: 1rem;
        border-radius: 1.35rem;
    }

    .dashboard-schedule-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.8rem;
    }

    .dashboard-schedule-head span {
        display: block;
        color: #1f2937;
        font-size: 0.8rem;
        font-weight: 850;
    }

    .dashboard-schedule-head strong {
        display: block;
        margin-top: 0.18rem;
        color: #8791a4;
        font-size: 0.72rem;
        font-weight: 700;
    }

    .dashboard-schedule-view {
        color: #0f766e;
        font-size: 0.72rem;
        font-weight: 850;
    }

    .dashboard-schedule-list {
        display: grid;
        gap: 0.75rem;
        margin-top: 1rem;
    }

    .dashboard-schedule-item {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        gap: 0.7rem;
        align-items: center;
        color: inherit;
    }

    .dashboard-schedule-icon {
        width: 2rem;
        height: 2rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #d94697;
    }

    .dashboard-schedule-icon.is-task {
        color: #0891b2;
    }

    .dashboard-schedule-icon i {
        font-size: 1.15rem;
    }

    .dashboard-schedule-copy {
        min-width: 0;
    }

    .dashboard-schedule-copy small,
    .dashboard-schedule-copy em {
        display: block;
        color: #8b95a7;
        font-size: 0.68rem;
        font-style: normal;
        font-weight: 700;
    }

    .dashboard-schedule-copy strong {
        display: block;
        margin-top: 0.12rem;
        color: #333b4d;
        font-size: 0.79rem;
        font-weight: 850;
        line-height: 1.35;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .dashboard-schedule-open {
        width: 1.72rem;
        height: 1.72rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 0.45rem;
        border: 1px solid #edf0f5;
        color: #7b8495;
    }

    .dashboard-schedule-open i {
        font-size: 0.95rem;
    }

    .dashboard-schedule-empty {
        display: grid;
        place-items: center;
        gap: 0.35rem;
        padding: 1rem 0.4rem;
        color: #9aa4b5;
        text-align: center;
    }

    .dashboard-schedule-empty i {
        color: #d94697;
    }

    .dashboard-schedule-empty p {
        margin: 0;
        font-size: 0.78rem;
        font-weight: 700;
    }

    .dashboard-schedule-add {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        width: 100%;
        margin-top: 1rem;
        padding: 0.68rem 0.8rem;
        border-radius: 0.75rem;
        background: linear-gradient(135deg, #d94697, #c026d3);
        color: #ffffff;
        font-size: 0.78rem;
        font-weight: 850;
    }

    @media (min-width: 640px) {
        .dashboard-brief-metric-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .dashboard-welcome-shell {
            flex-direction: row;
            justify-content: space-between;
            align-items: center;
        }

        .dashboard-metric-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .dashboard-stat-grid,
        .dashboard-action-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (min-width: 1024px) {
        .dashboard-executive-grid {
            grid-template-columns: minmax(0, 1.55fr) minmax(320px, 0.95fr);
            align-items: stretch;
        }

        .dashboard-overview-rail {
            grid-template-columns: minmax(0, 1.7fr) minmax(0, 1fr);
            align-items: stretch;
        }

        .dashboard-showcase-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .dashboard-chart-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 1180px) {
        .dashboard-home-shell {
            flex-direction: column;
        }

        .dashboard-calendar-sidebar {
            width: 100%;
            flex-basis: auto;
            grid-template-columns: minmax(0, 1fr) minmax(280px, 0.85fr);
            position: static;
            height: auto;
            max-height: none;
            overflow-y: visible;
            padding-right: 0;
        }

        .dashboard-debtors-card {
            grid-column: 1 / -1;
        }

        .dashboard-hero-metrics {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 900px) {
        .dashboard-hero-card,
        .dashboard-priority-grid,
        .dashboard-calendar-sidebar {
            grid-template-columns: 1fr;
        }

        .dashboard-hero-aside {
            min-width: 0;
        }

        .dashboard-hero-cta-row .dashboard-hero-button {
            flex: 1 1 auto;
        }

        .dashboard-hero-metrics {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .dashboard-focus-list {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 560px) {
        .weather-card__body {
            grid-template-columns: 1fr;
        }

        .weather-card__icon {
            justify-self: flex-start;
        }

        .weather-forecast-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 640px) {
        .dashboard-home-shell {
            padding: 0.75rem;
        }

        .dashboard-hero-metrics,
        .dashboard-focus-list,
        .dashboard-workspace-shortcuts .todo-tile-grid {
            grid-template-columns: 1fr;
        }

        .dashboard-calendar-card,
        .dashboard-schedule-card,
        .dashboard-debtors-card,
        .dashboard-focus-card,
        .dashboard-workspace-shortcuts {
            border-radius: 1.1rem;
        }

        .dashboard-debtors-summary {
            grid-template-columns: 1fr;
        }

        .dashboard-action-link {
            min-height: 0;
        }

        .dashboard-action-top {
            align-items: center;
        }

        .dashboard-action-pill {
            display: none;
        }
    }

    @media (min-width: 1280px) {
        .dashboard-chart-grid {
            grid-template-columns: minmax(0, 1.7fr) minmax(320px, 1fr);
        }

        .dashboard-chart-grid > :nth-child(1) {
            grid-column: 1;
            grid-row: 1;
        }

        .dashboard-chart-grid > :nth-child(2) {
            grid-column: 1;
            grid-row: 2;
        }

        .dashboard-chart-grid > :nth-child(3) {
            grid-column: 2;
            grid-row: 1;
        }

        .dashboard-chart-grid > :nth-child(4) {
            grid-column: 2;
            grid-row: 2;
        }

        .dashboard-action-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    .dashboard-panel-card {
        position: relative;
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 1.5rem;
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        box-shadow: 0 24px 60px -42px rgba(15, 23, 42, 0.42);
    }

    .dashboard-panel-card::before {
        content: "";
        position: absolute;
        inset: 0;
        pointer-events: none;
        background: radial-gradient(circle at top right, rgba(255, 255, 255, 0.9), transparent 42%);
    }

    .dashboard-summary-tile,
    .dashboard-task-item {
        position: relative;
        border: 1px solid var(--task-soft, rgba(148, 163, 184, 0.18));
        background: linear-gradient(135deg, var(--task-bg, rgba(148, 163, 184, 0.08)) 0%, #ffffff 72%);
        box-shadow: 0 18px 40px -34px var(--task-soft, rgba(148, 163, 184, 0.18));
    }

    .dashboard-summary-tile {
        border-radius: 1rem;
        padding: 0.9rem 1rem;
        transition: transform 0.18s ease, box-shadow 0.18s ease;
    }

    .dashboard-summary-tile:hover {
        transform: translateY(-1px);
        box-shadow: 0 22px 46px -34px var(--task-soft, rgba(148, 163, 184, 0.18));
    }

    .dashboard-task-list {
        display: grid;
        gap: 0.9rem;
    }

    .dashboard-task-item {
        display: block;
        border-radius: 1.15rem;
        padding: 1rem;
        transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
    }

    .dashboard-task-item:hover {
        transform: translateY(-2px);
        border-color: var(--task-accent, #94a3b8);
        box-shadow: 0 26px 58px -36px var(--task-soft, rgba(148, 163, 184, 0.18));
    }

    .dashboard-task-item.is-completed .dashboard-task-title,
    .dashboard-task-item.is-completed .dashboard-task-project {
        text-decoration: line-through;
        text-decoration-thickness: 2px;
        text-decoration-color: rgba(16, 185, 129, 0.8);
    }

    .dashboard-task-item.is-completed .dashboard-task-title,
    .dashboard-task-item.is-completed .dashboard-task-project,
    .dashboard-task-item.is-completed .dashboard-task-desc {
        color: #6b7280;
    }

    .dashboard-task-item.is-overdue {
        transform: translateY(0);
    }

    .dashboard-status-dot {
        position: relative;
        width: 0.72rem;
        height: 0.72rem;
        flex-shrink: 0;
        border-radius: 9999px;
        background: currentColor;
        color: var(--task-accent, #94a3b8);
        box-shadow: 0 0 0 5px var(--task-bg, rgba(148, 163, 184, 0.08));
    }

    .dashboard-status-dot::after {
        content: "";
        position: absolute;
        inset: 0;
        border-radius: inherit;
        background: currentColor;
        opacity: 0.45;
        animation: dashboardStatusPulse 1.8s ease-out infinite;
    }

    .dashboard-status-dot.is-static::after {
        display: none;
    }

    .dashboard-task-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        border-radius: 9999px;
        padding: 0.38rem 0.7rem;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.01em;
        white-space: nowrap;
    }

    .dashboard-meta-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.42rem;
        border-radius: 9999px;
        padding: 0.3rem 0.65rem;
        font-size: 0.72rem;
        font-weight: 600;
        background: rgba(255, 255, 255, 0.72);
    }

    @keyframes dashboardStatusPulse {
        0% {
            transform: scale(1);
            opacity: 0.42;
        }
        75% {
            transform: scale(2.4);
            opacity: 0;
        }
        100% {
            transform: scale(2.4);
            opacity: 0;
        }
    }

    [data-ajax-component].is-ajax-loading {
        opacity: 0.55;
        pointer-events: none;
        transition: opacity 0.15s ease;
    }

    /* Lucide (matches app shell icon pack) */
    .dashboard-home-shell svg.lucide {
        stroke-width: 1.5;
        flex-shrink: 0;
    }

    .dashboard-lucide-lg {
        width: 2rem;
        height: 2rem;
    }

    .dashboard-hero-kicker svg.lucide {
        width: 1rem;
        height: 1rem;
    }

    .dashboard-hero-button svg.lucide {
        width: 1.05rem;
        height: 1.05rem;
    }

    .dashboard-workspace-shortcuts .todo-tile-icon svg.lucide {
        width: 1.35rem;
        height: 1.35rem;
    }

    .dashboard-hero-metric-icon svg.lucide {
        width: 1.35rem;
        height: 1.35rem;
    }

    .dashboard-hero-metric-growth svg.lucide {
        width: 1rem;
        height: 1rem;
    }

    .dashboard-focus-icon svg.lucide {
        width: 1.22rem;
        height: 1.22rem;
    }

    .dashboard-calendar-arrow svg.lucide {
        width: 1.35rem;
        height: 1.35rem;
    }

    .dashboard-reminder-icon svg.lucide,
    .dashboard-schedule-icon svg.lucide {
        width: 1rem;
        height: 1rem;
    }

    .weather-card__decor svg.lucide {
        width: 1.25rem;
        height: 1.25rem;
    }

    .weather-card__edit svg.lucide {
        width: 0.95rem;
        height: 0.95rem;
    }

    .weather-card__city svg.lucide {
        width: 1rem;
        height: 1rem;
    }

    .weather-card__icon svg.lucide {
        width: 2rem;
        height: 2rem;
    }

    .weather-chip svg.lucide {
        width: 0.95rem;
        height: 0.95rem;
    }

    .weather-editor-field svg.lucide {
        width: 1rem;
        height: 1rem;
    }

    .weather-editor-btn svg.lucide {
        width: 0.95rem;
        height: 0.95rem;
    }

    .dashboard-showcase-icon svg.lucide {
        width: 1.75rem;
        height: 1.75rem;
    }

    .dashboard-activity-mark svg.lucide {
        width: 1.25rem;
        height: 1.25rem;
    }

    .dashboard-debtor-action svg.lucide {
        width: 1.1rem;
        height: 1.1rem;
    }

    .dashboard-action-icon svg.lucide {
        width: 1.35rem;
        height: 1.35rem;
    }

    .todo-btn-primary svg.lucide.inline-icon,
    .todo-btn-ghost svg.lucide.inline-icon {
        width: 1rem;
        height: 1rem;
    }
    .dashboard-ops-shell {
        display: grid;
        gap: 1rem;
    }

    .dashboard-ops-hero,
    .dashboard-ops-panel,
    .dashboard-ops-kpi,
    .dashboard-ops-activity {
        position: relative;
        overflow: hidden;
        border: 1px solid rgba(226, 232, 240, 0.94);
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(247, 250, 252, 0.97));
        box-shadow: 0 22px 46px -38px rgba(15, 23, 42, 0.24);
    }

    .dashboard-ops-hero {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
        padding: 1.2rem 1.35rem;
        border-radius: 1.45rem;
    }

    .dashboard-ops-hero::before {
        content: "";
        position: absolute;
        inset: 0;
        background:
            radial-gradient(circle at top right, rgba(15, 118, 110, 0.12), transparent 30%),
            linear-gradient(120deg, rgba(16, 185, 129, 0.06), transparent 38%);
        pointer-events: none;
    }

    .dashboard-ops-hero > * {
        position: relative;
        z-index: 1;
    }

    .dashboard-ops-hero-copy {
        flex: 1 1 24rem;
        min-width: 0;
    }

    .dashboard-ops-kicker {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.4rem 0.75rem;
        border-radius: 999px;
        background: rgba(15, 118, 110, 0.1);
        color: #0f766e;
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.11em;
        text-transform: uppercase;
    }

    .dashboard-ops-title {
        margin: 0.8rem 0 0;
        font-size: clamp(1.75rem, 3vw, 2.3rem);
        line-height: 1.05;
        font-weight: 850;
        letter-spacing: -0.05em;
        color: #0f172a;
    }

    .dashboard-ops-subtitle {
        max-width: 42rem;
        margin: 0.45rem 0 0;
        color: #64748b;
        font-size: 0.92rem;
        line-height: 1.55;
    }

    .dashboard-ops-hero-side {
        display: grid;
        gap: 0.85rem;
        flex: 0 0 auto;
        min-width: min(100%, 17rem);
    }

    .dashboard-ops-date-card {
        padding: 0.95rem 1rem;
        border-radius: 1.15rem;
        border: 1px solid rgba(226, 232, 240, 0.95);
        background: rgba(255, 255, 255, 0.92);
        text-align: right;
    }

    .dashboard-ops-date-label {
        display: block;
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.11em;
        text-transform: uppercase;
        color: #94a3b8;
    }

    .dashboard-ops-date-value {
        display: block;
        margin-top: 0.35rem;
        font-size: 1rem;
        font-weight: 800;
        color: #0f172a;
    }

    .dashboard-ops-date-meta {
        display: block;
        margin-top: 0.2rem;
        font-size: 0.82rem;
        color: #64748b;
    }

    .dashboard-ops-action-row {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 0.55rem;
    }

    .dashboard-ops-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.45rem;
        padding: 0.72rem 1rem;
        border-radius: 999px;
        border: 1px solid rgba(15, 118, 110, 0.14);
        background: rgba(15, 118, 110, 0.08);
        color: #0f766e;
        font-size: 0.82rem;
        font-weight: 800;
        transition: transform 0.18s ease, background-color 0.18s ease, border-color 0.18s ease;
    }

    .dashboard-ops-action:hover {
        transform: translateY(-1px);
        background: rgba(15, 118, 110, 0.12);
        border-color: rgba(15, 118, 110, 0.24);
    }

    .dashboard-ops-action.is-primary {
        background: #0f766e;
        border-color: #0f766e;
        color: #ffffff;
    }

    .dashboard-ops-kpi-grid,
    .dashboard-ops-panel-grid,
    .dashboard-ops-activity-list {
        display: grid;
        gap: 1rem;
    }

    .dashboard-ops-kpi-grid {
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    }

    .dashboard-ops-panel-grid {
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    }

    .dashboard-ops-kpi {
        display: grid;
        gap: 0.85rem;
        padding: 1rem 1.05rem;
        border-radius: 1.25rem;
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    }

    .dashboard-ops-kpi:hover {
        transform: translateY(-2px);
        border-color: rgba(15, 118, 110, 0.2);
        box-shadow: 0 24px 48px -40px rgba(15, 23, 42, 0.28);
    }

    .dashboard-ops-kpi-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.8rem;
    }

    .dashboard-ops-kpi-label {
        display: block;
        font-size: 0.68rem;
        font-weight: 800;
        letter-spacing: 0.11em;
        text-transform: uppercase;
        color: #94a3b8;
    }

    .dashboard-ops-kpi-value {
        display: block;
        margin-top: 0.5rem;
        font-size: 1.3rem;
        line-height: 1.15;
        font-weight: 850;
        letter-spacing: -0.04em;
        color: #0f172a;
    }

    .dashboard-ops-kpi-icon {
        width: 2.75rem;
        height: 2.75rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 0.95rem;
        background: rgba(15, 118, 110, 0.1);
        color: #0f766e;
        flex-shrink: 0;
    }

    .dashboard-ops-kpi-note {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.8rem;
        font-weight: 700;
        color: #64748b;
    }

    .dashboard-ops-kpi-note[data-tone="success"] {
        color: #059669;
    }

    .dashboard-ops-kpi-note[data-tone="warning"] {
        color: #b45309;
    }

    .dashboard-ops-kpi-note[data-tone="danger"] {
        color: #dc2626;
    }

    .dashboard-ops-panel,
    .dashboard-ops-activity {
        padding: 1.05rem 1.1rem;
        border-radius: 1.35rem;
    }

    .dashboard-ops-panel-head,
    .dashboard-ops-activity-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .dashboard-ops-panel-head h2,
    .dashboard-ops-activity-head h2 {
        margin: 0;
        font-size: 1rem;
        font-weight: 800;
        color: #0f172a;
    }

    .dashboard-ops-panel-head p,
    .dashboard-ops-activity-head p {
        margin: 0.18rem 0 0;
        color: #64748b;
        font-size: 0.8rem;
    }

    .dashboard-ops-link {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        color: #0f766e;
        font-size: 0.78rem;
        font-weight: 800;
        white-space: nowrap;
    }

    .dashboard-ops-queue,
    .dashboard-ops-material-list,
    .dashboard-ops-debtor-list {
        display: grid;
        gap: 0.7rem;
    }

    .dashboard-ops-queue-item,
    .dashboard-ops-material-item,
    .dashboard-ops-debtor-item,
    .dashboard-ops-activity-item {
        display: grid;
        gap: 0.7rem;
        padding: 0.85rem 0.9rem;
        border-radius: 1rem;
        border: 1px solid rgba(226, 232, 240, 0.9);
        background: rgba(255, 255, 255, 0.92);
        transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
    }

    .dashboard-ops-queue-item:hover,
    .dashboard-ops-material-item:hover,
    .dashboard-ops-debtor-item:hover,
    .dashboard-ops-activity-item:hover {
        transform: translateY(-1px);
        border-color: rgba(15, 118, 110, 0.18);
        box-shadow: 0 20px 38px -34px rgba(15, 23, 42, 0.24);
    }

    .dashboard-ops-queue-item {
        grid-template-columns: auto minmax(0, 1fr) auto;
        align-items: center;
    }

    .dashboard-ops-queue-icon,
    .dashboard-ops-activity-icon {
        width: 2.35rem;
        height: 2.35rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 0.85rem;
        background: rgba(15, 118, 110, 0.1);
        color: #0f766e;
        flex-shrink: 0;
    }

    .dashboard-ops-queue-top,
    .dashboard-ops-activity-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.65rem;
    }

    .dashboard-ops-queue-type,
    .dashboard-ops-age {
        font-size: 0.7rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #94a3b8;
    }

    .dashboard-ops-queue-title,
    .dashboard-ops-material-title,
    .dashboard-ops-activity-title,
    .dashboard-ops-debtor-name {
        font-size: 0.92rem;
        font-weight: 800;
        color: #0f172a;
    }

    .dashboard-ops-queue-subtitle,
    .dashboard-ops-material-meta,
    .dashboard-ops-activity-subtitle,
    .dashboard-ops-debtor-meta {
        color: #64748b;
        font-size: 0.78rem;
        line-height: 1.45;
    }

    .dashboard-ops-queue-value,
    .dashboard-ops-debtor-balance {
        font-size: 0.82rem;
        font-weight: 800;
        color: #0f766e;
        text-align: right;
    }

    .dashboard-ops-placeholder {
        display: grid;
        gap: 0.7rem;
        align-content: center;
        min-height: 12rem;
        padding: 1rem;
        border: 1px dashed rgba(148, 163, 184, 0.5);
        border-radius: 1.05rem;
        background: rgba(248, 250, 252, 0.8);
        text-align: center;
    }

    .dashboard-ops-placeholder-icon {
        width: 3.2rem;
        height: 3.2rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto;
        border-radius: 999px;
        background: rgba(15, 118, 110, 0.12);
        color: #0f766e;
    }

    .dashboard-ops-placeholder strong {
        font-size: 1rem;
        color: #0f172a;
    }

    .dashboard-ops-placeholder p {
        margin: 0;
        color: #64748b;
        font-size: 0.84rem;
        line-height: 1.5;
    }

    .dashboard-ops-finance {
        display: grid;
        gap: 0.55rem;
    }

    .dashboard-ops-finance-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto auto;
        gap: 0.7rem;
        align-items: center;
        padding: 0.78rem 0;
        border-bottom: 1px solid rgba(226, 232, 240, 0.82);
    }

    .dashboard-ops-finance-row:last-child {
        border-bottom: none;
        padding-bottom: 0;
    }

    .dashboard-ops-finance-row:first-child {
        padding-top: 0;
    }

    .dashboard-ops-finance-label {
        color: #475569;
        font-size: 0.82rem;
        font-weight: 700;
    }

    .dashboard-ops-finance-value {
        color: #0f172a;
        font-size: 0.85rem;
        font-weight: 800;
        text-align: right;
    }

    .dashboard-ops-finance-change {
        min-width: 4.25rem;
        text-align: right;
        font-size: 0.76rem;
        font-weight: 800;
        color: #64748b;
    }

    .dashboard-ops-finance-change.is-positive {
        color: #059669;
    }

    .dashboard-ops-finance-change.is-negative {
        color: #dc2626;
    }

    .dashboard-ops-chart-meta,
    .dashboard-ops-summary-stats {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.65rem;
        margin-bottom: 0.95rem;
    }

    .dashboard-ops-summary-stats {
        margin-bottom: 0.85rem;
    }

    .dashboard-ops-chart-stat,
    .dashboard-ops-summary-stat {
        padding: 0.78rem 0.82rem;
        border-radius: 0.95rem;
        background: rgba(248, 250, 252, 0.92);
        border: 1px solid rgba(226, 232, 240, 0.9);
    }

    .dashboard-ops-chart-stat span,
    .dashboard-ops-summary-stat span {
        display: block;
        font-size: 0.66rem;
        font-weight: 800;
        letter-spacing: 0.09em;
        text-transform: uppercase;
        color: #94a3b8;
    }

    .dashboard-ops-chart-stat strong,
    .dashboard-ops-summary-stat strong {
        display: block;
        margin-top: 0.35rem;
        font-size: 1rem;
        font-weight: 850;
        color: #0f172a;
    }

    .dashboard-ops-inline-chart {
        position: relative;
        height: 15rem;
    }

    .dashboard-ops-material-item {
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
    }

    .dashboard-ops-material-copy {
        min-width: 0;
    }

    .dashboard-ops-material-rate {
        display: grid;
        justify-items: end;
        gap: 0.35rem;
        text-align: right;
    }

    .dashboard-ops-status {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.28rem 0.55rem;
        border-radius: 999px;
        font-size: 0.68rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .dashboard-ops-status.is-success {
        background: rgba(16, 185, 129, 0.12);
        color: #059669;
    }

    .dashboard-ops-status.is-warning {
        background: rgba(245, 158, 11, 0.14);
        color: #b45309;
    }

    .dashboard-ops-status.is-danger {
        background: rgba(239, 68, 68, 0.12);
        color: #dc2626;
    }

    .dashboard-ops-debtor-item {
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
    }

    .dashboard-ops-activity {
        padding-bottom: 1.1rem;
    }

    .dashboard-ops-activity-list {
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    }

    .dashboard-ops-activity-item {
        min-height: 100%;
    }

    .dashboard-ops-empty {
        display: grid;
        place-items: center;
        min-height: 12rem;
        padding: 1rem;
        border-radius: 1rem;
        border: 1px dashed rgba(148, 163, 184, 0.45);
        background: rgba(248, 250, 252, 0.82);
        color: #64748b;
        text-align: center;
        font-size: 0.84rem;
    }

    @media (max-width: 900px) {
        .dashboard-ops-action-row {
            justify-content: flex-start;
        }

        .dashboard-ops-date-card {
            text-align: left;
        }
    }

    @media (max-width: 640px) {
        .dashboard-ops-panel-head,
        .dashboard-ops-activity-head {
            flex-direction: column;
        }

        .dashboard-ops-finance-row,
        .dashboard-ops-queue-item,
        .dashboard-ops-material-item,
        .dashboard-ops-debtor-item {
            grid-template-columns: 1fr;
        }

        .dashboard-ops-queue-value,
        .dashboard-ops-debtor-balance,
        .dashboard-ops-material-rate {
            text-align: left;
            justify-items: start;
        }

        .dashboard-ops-chart-meta,
        .dashboard-ops-summary-stats {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    /* Debtors Panel — full-width section */
    .dashboard-ops-debtors-section {
        display: grid;
        gap: 1rem;
    }

    .dashboard-ops-panel-debtors {
        width: 100%;
    }

    .dashboard-ops-debtors-head {
        align-items: center;
    }

    .dashboard-ops-debtors-head-title {
        display: flex;
        align-items: flex-start;
        gap: 0.85rem;
        min-width: 0;
    }

    .dashboard-ops-debtors-head-icon {
        width: 2.5rem;
        height: 2.5rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 0.85rem;
        background: rgba(15, 118, 110, 0.1);
        color: #0f766e;
        flex-shrink: 0;
    }

    .dashboard-ops-debtors-head-icon svg {
        width: 1.15rem;
        height: 1.15rem;
    }

    .dashboard-ops-debtors-head .dashboard-ops-link {
        padding: 0.5rem 0.9rem;
        border-radius: 999px;
        border: 1px solid rgba(15, 118, 110, 0.22);
        background: rgba(255, 255, 255, 0.95);
        text-decoration: none;
        transition: background-color 0.18s ease, border-color 0.18s ease, transform 0.18s ease;
    }

    .dashboard-ops-debtors-head .dashboard-ops-link:hover {
        background: rgba(15, 118, 110, 0.06);
        border-color: rgba(15, 118, 110, 0.35);
        transform: translateY(-1px);
    }

    .dashboard-ops-debtors-kpis {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.75rem;
        margin-bottom: 0.85rem;
    }

    .dashboard-ops-debtors-kpi {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        padding: 0.9rem 0.95rem;
        border-radius: 1rem;
        background: rgba(255, 255, 255, 0.96);
        border: 1px solid rgba(226, 232, 240, 0.92);
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }

    .dashboard-ops-debtors-kpi-icon {
        width: 2.35rem;
        height: 2.35rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        flex-shrink: 0;
    }

    .dashboard-ops-debtors-kpi-icon svg {
        width: 1.05rem;
        height: 1.05rem;
    }

    .dashboard-ops-debtors-kpi-copy {
        min-width: 0;
    }

    .dashboard-ops-debtors-kpi-copy > span {
        display: block;
        font-size: 0.64rem;
        font-weight: 800;
        letter-spacing: 0.09em;
        text-transform: uppercase;
        color: #94a3b8;
    }

    .dashboard-ops-debtors-kpi-copy > strong {
        display: block;
        margin-top: 0.3rem;
        font-size: 1.05rem;
        line-height: 1.15;
        font-weight: 850;
        letter-spacing: -0.02em;
        color: #0f172a;
    }

    .dashboard-ops-debtors-kpi-sub {
        display: block;
        margin-top: 0.18rem;
        font-size: 0.68rem;
        font-weight: 700;
        color: #94a3b8;
    }

    .dashboard-ops-debtors-kpi.is-total .dashboard-ops-debtors-kpi-icon {
        background: rgba(16, 185, 129, 0.12);
        color: #059669;
    }

    .dashboard-ops-debtors-kpi.is-total .dashboard-ops-debtors-kpi-copy > strong {
        color: #059669;
    }

    .dashboard-ops-debtors-kpi.is-overdue .dashboard-ops-debtors-kpi-icon {
        background: rgba(239, 68, 68, 0.12);
        color: #dc2626;
    }

    .dashboard-ops-debtors-kpi.is-overdue.has-count .dashboard-ops-debtors-kpi-copy > strong {
        color: #dc2626;
    }

    .dashboard-ops-debtors-kpi.is-critical .dashboard-ops-debtors-kpi-icon {
        background: rgba(217, 70, 151, 0.12);
        color: #db2777;
    }

    .dashboard-ops-debtors-kpi.is-critical.has-count .dashboard-ops-debtors-kpi-copy > strong {
        color: #db2777;
    }

    .dashboard-ops-debtors-kpi.is-avg-age .dashboard-ops-debtors-kpi-icon {
        background: rgba(99, 102, 241, 0.12);
        color: #6366f1;
    }

    .dashboard-ops-debtors-aging-bar {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.75rem;
        margin-bottom: 1.1rem;
    }

    .dashboard-ops-debtors-aging-segment {
        display: grid;
        gap: 0.35rem;
        padding: 0.9rem 0.95rem 0.75rem;
        border-radius: 1rem;
        background: rgba(255, 255, 255, 0.96);
        border: 1px solid rgba(226, 232, 240, 0.92);
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }

    .dashboard-ops-debtors-aging-top {
        display: flex;
        align-items: center;
        gap: 0.45rem;
    }

    .dashboard-ops-debtors-aging-icon {
        width: 1.65rem;
        height: 1.65rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        flex-shrink: 0;
    }

    .dashboard-ops-debtors-aging-icon svg {
        width: 0.85rem;
        height: 0.85rem;
    }

    .dashboard-ops-debtors-aging-label {
        font-size: 0.68rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .dashboard-ops-debtors-aging-segment strong {
        font-size: 0.95rem;
        font-weight: 850;
        color: #0f172a;
    }

    .dashboard-ops-debtors-aging-segment em {
        font-style: normal;
        font-size: 0.72rem;
        font-weight: 700;
        color: #94a3b8;
    }

    .dashboard-ops-debtors-aging-progress {
        height: 4px;
        margin-top: 0.35rem;
        border-radius: 999px;
        background: rgba(226, 232, 240, 0.85);
        overflow: hidden;
    }

    .dashboard-ops-debtors-aging-fill {
        height: 100%;
        border-radius: inherit;
        transition: width 0.35s ease;
    }

    .dashboard-ops-debtors-aging-segment.is-0-30 .dashboard-ops-debtors-aging-icon { background: rgba(14, 165, 233, 0.12); color: #0284c7; }
    .dashboard-ops-debtors-aging-segment.is-0-30 .dashboard-ops-debtors-aging-label { color: #0284c7; }
    .dashboard-ops-debtors-aging-segment.is-0-30 .dashboard-ops-debtors-aging-fill { background: #0ea5e9; }

    .dashboard-ops-debtors-aging-segment.is-31-60 .dashboard-ops-debtors-aging-icon { background: rgba(245, 158, 11, 0.14); color: #d97706; }
    .dashboard-ops-debtors-aging-segment.is-31-60 .dashboard-ops-debtors-aging-label { color: #d97706; }
    .dashboard-ops-debtors-aging-segment.is-31-60 .dashboard-ops-debtors-aging-fill { background: #f59e0b; }

    .dashboard-ops-debtors-aging-segment.is-61-plus .dashboard-ops-debtors-aging-icon { background: rgba(217, 70, 151, 0.12); color: #db2777; }
    .dashboard-ops-debtors-aging-segment.is-61-plus .dashboard-ops-debtors-aging-label { color: #db2777; }
    .dashboard-ops-debtors-aging-segment.is-61-plus .dashboard-ops-debtors-aging-fill { background: #d94697; }

    .dashboard-ops-debtors-table-head {
        display: grid;
        grid-template-columns: minmax(0, 1.35fr) 90px 130px auto;
        gap: 0.8rem;
        padding: 0 0.15rem 0.55rem;
        font-size: 0.66rem;
        font-weight: 800;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: #94a3b8;
    }

    .dashboard-ops-debtors-table-head span:last-child {
        text-align: right;
    }

    .dashboard-ops-debtors-footer-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.65rem;
        margin-top: 1rem;
        padding-top: 0.85rem;
        border-top: 1px dashed rgba(226, 232, 240, 0.85);
    }

    .dashboard-ops-debtors-footer-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.55rem 0.85rem;
        border-radius: 999px;
        border: 1px solid rgba(15, 118, 110, 0.16);
        background: rgba(15, 118, 110, 0.06);
        color: #0f766e;
        font-size: 0.76rem;
        font-weight: 800;
        text-decoration: none;
        transition: transform 0.18s ease, background-color 0.18s ease;
    }

    .dashboard-ops-debtors-footer-btn:hover {
        transform: translateY(-1px);
        background: rgba(15, 118, 110, 0.12);
    }

    .dashboard-ops-debtors-footer-btn.is-primary {
        background: #0f766e;
        border-color: #0f766e;
        color: #ffffff;
    }

    .dashboard-ops-debtors-footer-btn.is-primary:hover {
        background: #0d6b64;
    }

    @media (max-width: 1100px) {
        .dashboard-ops-debtors-kpis {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 900px) {
        .dashboard-ops-debtors-table-head {
            display: none;
        }

        .dashboard-ops-debtors-aging-bar {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 640px) {
        .dashboard-ops-debtors-kpis {
            grid-template-columns: 1fr;
        }
    }

    /* Interactive debtor row styling */
    .dashboard-ops-debtor-item--interactive {
        display: grid;
        grid-template-columns: minmax(0, 1.35fr) 90px 130px auto;
        align-items: center;
        gap: 0.8rem;
        padding: 0.85rem 0.95rem !important;
        border-radius: 1rem;
        border: 1px solid rgba(226, 232, 240, 0.9);
        background: rgba(255, 255, 255, 0.96);
        transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .dashboard-ops-debtor-item--interactive:hover {
        transform: translateY(-1px);
        border-color: rgba(15, 118, 110, 0.18);
        box-shadow: 0 10px 22px -14px rgba(15, 23, 42, 0.14);
    }

    .dashboard-ops-debtor-info {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        min-width: 0;
    }

    .dashboard-ops-debtor-copy {
        min-width: 0;
    }

    .dashboard-ops-debtor-copy .dashboard-ops-debtor-name {
        display: block;
    }

    .dashboard-ops-debtor-copy .dashboard-ops-debtor-meta {
        display: block;
        margin-top: 0.12rem;
    }

    .dashboard-ops-debtor-avatar {
        width: 2.35rem;
        height: 2.35rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 850;
        letter-spacing: 0.02em;
        flex-shrink: 0;
    }

    .dashboard-ops-debtor-avatar.is-success { background: rgba(16, 185, 129, 0.14); color: #059669; }
    .dashboard-ops-debtor-avatar.is-info { background: rgba(14, 165, 233, 0.14); color: #0284c7; }
    .dashboard-ops-debtor-avatar.is-warning { background: rgba(245, 158, 11, 0.16); color: #d97706; }
    .dashboard-ops-debtor-avatar.is-danger { background: rgba(239, 68, 68, 0.14); color: #dc2626; }
    .dashboard-ops-debtor-avatar.is-critical { background: rgba(217, 70, 151, 0.14); color: #db2777; }

    /* Age badges styling */
    .dashboard-ops-debtor-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.28rem 0.65rem;
        border-radius: 999px;
        font-size: 0.68rem;
        font-weight: 850;
        white-space: nowrap;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }
    .dashboard-ops-debtor-badge.is-success { background: rgba(16, 185, 129, 0.1); color: #059669; border: 1px solid rgba(16, 185, 129, 0.15); }
    .dashboard-ops-debtor-badge.is-info { background: rgba(14, 165, 233, 0.1); color: #0284c7; border: 1px solid rgba(14, 165, 233, 0.15); }
    .dashboard-ops-debtor-badge.is-warning { background: rgba(245, 158, 11, 0.12); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.18); }
    .dashboard-ops-debtor-badge.is-danger { background: rgba(239, 68, 68, 0.1); color: #dc2626; border: 1px solid rgba(239, 68, 68, 0.15); }
    .dashboard-ops-debtor-badge.is-critical { background: rgba(217, 70, 151, 0.11); color: #db2777; border: 1px solid rgba(217, 70, 151, 0.18); }

    /* Balance figures styled with tone colors */
    .dashboard-ops-debtor-balance {
        font-size: 0.88rem !important;
        font-weight: 850 !important;
        text-align: right;
    }
    .dashboard-ops-debtor-balance.is-success { color: #059669 !important; }
    .dashboard-ops-debtor-balance.is-info { color: #0284c7 !important; }
    .dashboard-ops-debtor-balance.is-warning { color: #d97706 !important; }
    .dashboard-ops-debtor-balance.is-danger { color: #dc2626 !important; }
    .dashboard-ops-debtor-balance.is-critical { color: #db2777 !important; }

    /* Action buttons in debtor card */
    .dashboard-ops-debtor-actions {
        display: flex;
        align-items: center;
        gap: 0.45rem;
    }

    .dashboard-ops-debtor-btn {
        width: 2.15rem;
        height: 2.15rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: rgba(248, 250, 252, 0.95);
        border: 1px solid rgba(226, 232, 240, 0.95);
        color: #64748b;
        cursor: pointer;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        padding: 0;
        text-decoration: none;
    }
    
    .dashboard-ops-debtor-btn svg {
        width: 0.95rem;
        height: 0.95rem;
        stroke-width: 2.2px;
    }
    
    /* Hover adjustments */
    .dashboard-ops-debtor-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(15, 23, 42, 0.08);
    }
    
    .dashboard-ops-debtor-btn.text-teal:hover { background: #0f766e; border-color: #0f766e; color: #ffffff !important; }
    .dashboard-ops-debtor-btn.text-blue:hover { background: #0284c7; border-color: #0284c7; color: #ffffff !important; }
    .dashboard-ops-debtor-btn.text-amber:hover { background: #d97706; border-color: #d97706; color: #ffffff !important; }
    .dashboard-ops-debtor-btn.text-emerald:hover { background: #059669; border-color: #059669; color: #ffffff !important; }
    .dashboard-ops-debtor-btn.text-indigo:hover { background: #4f46e5; border-color: #4f46e5; color: #ffffff !important; }
    .dashboard-ops-debtor-btn.text-violet:hover { background: #7c3aed; border-color: #7c3aed; color: #ffffff !important; }

    @media (max-width: 900px) {
        .dashboard-ops-debtor-item--interactive {
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 0.6rem;
            padding: 0.85rem 0.9rem !important;
        }
        
        .dashboard-ops-debtor-badge-wrap {
            grid-column: 2;
            grid-row: 1;
            display: flex;
            justify-content: flex-end;
        }
        
        .dashboard-ops-debtor-value-wrap {
            grid-column: 1;
            grid-row: 2;
            text-align: left;
        }
        .dashboard-ops-debtor-value-wrap .dashboard-ops-debtor-balance {
            text-align: left;
        }
        
        .dashboard-ops-debtor-actions {
            grid-column: 1 / span 2;
            grid-row: 3;
            margin-top: 0.45rem;
            justify-content: flex-start;
            border-top: 1px dashed rgba(226, 232, 240, 0.7);
            padding-top: 0.55rem;
        }
    }
</style>

<?php
// Workspace tile / preview / hero card / calendar values come from
// dashboard_collect_context() above. The legacy locals below are no longer
// required because extract() already populated $wsDashboardTiles, $wsSidebar,
// $dashboardHeroCards, $dashboardCalendar*, $dashboardBuildCalendarUrl, etc.
$dashboardFinanceHref = hasPermission('view_dashboard_revenue')
    ? BASE_URL . 'modules/sales/index'
    : (hasPermission('view_invoices') ? BASE_URL . 'modules/invoices/list' : '#');
$dashboardApprovalsHref = hasPermission('view_projects')
    ? BASE_URL . 'modules/projects/list'
    : (hasPermission('view_estimations')
        ? BASE_URL . 'modules/estimations/list'
        : (hasPermission('view_tasks') ? BASE_URL . 'modules/tasks/list' : '#'));
$dashboardReceivablesHref = hasPermission('view_dashboard_revenue')
    ? BASE_URL . 'modules/sales/index'
    : (hasPermission('view_invoices') ? BASE_URL . 'modules/invoices/list' : '#');
$useLegacyDashboardShell = false;
?>

<div class="todo-shell dashboard-home-shell">
    <main class="todo-main todo-main--wide dashboard-home-main">
        <div class="dashboard-ops-shell">
            <section class="dashboard-ops-hero" aria-label="Operational dashboard overview">
                <div class="dashboard-ops-hero-copy">
                    <span class="dashboard-ops-kicker">
                        <i data-lucide="layout-dashboard" aria-hidden="true"></i>
                        Main Dashboard
                    </span>
                    <h1 class="dashboard-ops-title">
                        <?php echo htmlspecialchars($dashboardGreeting); ?>, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'there'); ?>
                    </h1>
                    <p class="dashboard-ops-subtitle">Here's what's happening with your operations today.</p>
                </div>

                <div class="dashboard-ops-hero-side">
                    <div class="dashboard-ops-date-card">
                        <span class="dashboard-ops-date-label">Today</span>
                        <strong class="dashboard-ops-date-value"><?php echo htmlspecialchars($dashboardTodayDateLabel); ?></strong>
                        <span class="dashboard-ops-date-meta"><?php echo htmlspecialchars($dashboardTodayWeekday); ?></span>
                    </div>

                    <div class="dashboard-ops-action-row">
                        <a href="#"
                           class="dashboard-ops-action is-primary"
                           data-ws-open="wsModalQuickActions"
                           onclick="event.preventDefault(); openWorkspaceModal('wsModalQuickActions');">
                            <i data-lucide="zap" aria-hidden="true"></i>
                            Quick Actions
                        </a>
                        <a href="#"
                           class="dashboard-ops-action"
                           data-ws-open="wsModalReports"
                           onclick="event.preventDefault(); openWorkspaceModal('wsModalReports');">
                            <i data-lucide="bar-chart-3" aria-hidden="true"></i>
                            Reports
                        </a>
                        <a href="#"
                           class="dashboard-ops-action"
                           data-ws-open="wsModalActivity"
                           onclick="event.preventDefault(); openWorkspaceModal('wsModalActivity');">
                            <i data-lucide="history" aria-hidden="true"></i>
                            Activity
                        </a>
                    </div>
                </div>
            </section>

            <?php if (!empty($dashboardPrimaryCards)): ?>
                <section class="dashboard-ops-kpi-grid" aria-label="Critical ERP metrics">
                    <?php foreach ($dashboardPrimaryCards as $card): ?>
                        <?php if (!empty($card['target'])): ?>
                            <a href="#"
                               class="dashboard-ops-kpi"
                               onclick="event.preventDefault(); openWorkspaceModal('<?php echo htmlspecialchars($card['target']); ?>');">
                        <?php elseif (!empty($card['href'])): ?>
                            <a href="<?php echo htmlspecialchars($card['href']); ?>" class="dashboard-ops-kpi">
                        <?php else: ?>
                            <div class="dashboard-ops-kpi">
                        <?php endif; ?>
                                <div class="dashboard-ops-kpi-head">
                                    <div>
                                        <span class="dashboard-ops-kpi-label"><?php echo htmlspecialchars($card['label']); ?></span>
                                        <strong class="dashboard-ops-kpi-value"><?php echo htmlspecialchars($card['value']); ?></strong>
                                    </div>
                                    <span class="dashboard-ops-kpi-icon">
                                        <i data-lucide="<?php echo htmlspecialchars($card['icon']); ?>" aria-hidden="true"></i>
                                    </span>
                                </div>
                                <span class="dashboard-ops-kpi-note" data-tone="<?php echo htmlspecialchars($card['tone']); ?>">
                                    <?php echo htmlspecialchars($card['note']); ?>
                                </span>
                        <?php if (!empty($card['target']) || !empty($card['href'])): ?>
                            </a>
                        <?php else: ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <?php if (!empty($dashboardWorkOrdersPanel['available'])): ?>
            <section class="dashboard-ops-panel-grid" aria-label="Work order overview">
                    <div class="dashboard-ops-panel">
                        <div class="dashboard-ops-panel-head">
                            <div>
                                <h2>Work Orders</h2>
                                <p>View and manage production job workflows.</p>
                            </div>
                            <?php if (!empty($dashboardWorkOrdersPanel['href'])): ?>
                                <a href="<?php echo htmlspecialchars($dashboardWorkOrdersPanel['href']); ?>" class="dashboard-ops-link">
                                    Open Work Orders
                                    <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="dashboard-ops-placeholder">
                            <span class="dashboard-ops-placeholder-icon">
                                <i data-lucide="briefcase" aria-hidden="true"></i>
                            </span>
                            <strong>Worrk Oders can be viewed here</strong>
                            <p><?php echo htmlspecialchars($dashboardWorkOrdersPanel['summary']); ?></p>
                        </div>
                    </div>
            </section>
            <?php endif; ?>

            <section class="dashboard-ops-panel-grid" aria-label="Financial and operational summaries">
                <?php if (!empty($dashboardFinanceRows)): ?>
                    <div class="dashboard-ops-panel">
                        <div class="dashboard-ops-panel-head">
                            <div>
                                <h2>Financial Summary (MTD)</h2>
                                <p>Financial summary for the current month.</p>
                            </div>
                            <?php if ($dashboardFinanceHref !== '#'): ?>
                                <a href="<?php echo htmlspecialchars($dashboardFinanceHref); ?>" class="dashboard-ops-link">
                                    View report
                                    <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="dashboard-ops-finance">
                            <?php foreach ($dashboardFinanceRows as $row): ?>
                                <?php
                                $changeValue = strtolower((string) $row['change']);
                                $changeClass = 'dashboard-ops-finance-change';
                                if (strpos($changeValue, '-') !== false || strpos($changeValue, 'overdue') !== false) {
                                    $changeClass .= ' is-negative';
                                } elseif (strpos($changeValue, '+') !== false || strpos($changeValue, 'on track') !== false) {
                                    $changeClass .= ' is-positive';
                                }
                                ?>
                                <div class="dashboard-ops-finance-row">
                                    <span class="dashboard-ops-finance-label"><?php echo htmlspecialchars($row['label']); ?></span>
                                    <strong class="dashboard-ops-finance-value"><?php echo htmlspecialchars($row['value']); ?></strong>
                                    <span class="<?php echo htmlspecialchars($changeClass); ?>"><?php echo htmlspecialchars($row['change']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="dashboard-ops-panel">
                    <div class="dashboard-ops-panel-head">
                        <div>
                            <h2>Revenue Trend</h2>
                            <p>Last 6 months of invoiced value.</p>
                        </div>
                        <a href="#"
                           class="dashboard-ops-link"
                           data-ws-open="wsModalReports"
                           onclick="event.preventDefault(); openWorkspaceModal('wsModalReports');">
                            Open reports
                            <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                        </a>
                    </div>
                    <div class="dashboard-ops-chart-meta">
                        <div class="dashboard-ops-chart-stat">
                            <span>Latest Month</span>
                            <strong><?php echo htmlspecialchars($latestTrendLabel); ?></strong>
                        </div>
                        <div class="dashboard-ops-chart-stat">
                            <span>Revenue</span>
                            <strong>MK <?php echo htmlspecialchars(dashboardCurrency($latestRevenueTrend)); ?></strong>
                        </div>
                        <div class="dashboard-ops-chart-stat">
                            <span>Collected</span>
                            <strong>MK <?php echo htmlspecialchars(dashboardCurrency($latestCollectedTrend)); ?></strong>
                        </div>
                    </div>
                    <div class="dashboard-ops-inline-chart">
                        <canvas id="dashboardInlineRevenueTrend" aria-label="Revenue trend chart"></canvas>
                    </div>
                </div>

                <?php if (!empty($dashboardMaterialsSnapshot)): ?>
                    <div class="dashboard-ops-panel">
                        <div class="dashboard-ops-panel-head">
                            <div>
                                <h2>Materials Snapshot</h2>
                                <p>Latest costing rates for core production items.</p>
                            </div>
                            <a href="<?php echo BASE_URL; ?>modules/materials/list" class="dashboard-ops-link">
                                View all
                                <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                            </a>
                        </div>
                        <div class="dashboard-ops-material-list">
                            <?php foreach ($dashboardMaterialsSnapshot as $item): ?>
                                <a href="<?php echo htmlspecialchars($item['href']); ?>" class="dashboard-ops-material-item">
                                    <span class="dashboard-ops-material-copy">
                                        <strong class="dashboard-ops-material-title"><?php echo htmlspecialchars($item['name']); ?></strong>
                                        <span class="dashboard-ops-material-meta">
                                            <?php echo htmlspecialchars($item['unit']); ?>
                                            <?php if (!empty($item['description_excerpt'])): ?>
                                                - <?php echo htmlspecialchars($item['description_excerpt']); ?>
                                            <?php else: ?>
                                                - <?php echo htmlspecialchars($item['effective_copy']); ?>
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                    <span class="dashboard-ops-material-rate">
                                        <strong class="dashboard-ops-queue-title"><?php echo htmlspecialchars($item['rate_label']); ?></strong>
                                        <span class="dashboard-ops-status is-<?php echo htmlspecialchars($item['status_tone']); ?>">
                                            <?php echo htmlspecialchars($item['status_label']); ?>
                                        </span>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($dashboardCanViewDebtorsPanel): ?>
            <?php
            $debtorsPanelTotalBalance = max(0, (float) ($dashboardReceivablesSummary['total_balance'] ?? 0));
            $debtorsAgingPercent = static function (float $bucketBalance) use ($debtorsPanelTotalBalance): int {
                if ($debtorsPanelTotalBalance <= 0) {
                    return 0;
                }

                return (int) min(100, round(($bucketBalance / $debtorsPanelTotalBalance) * 100));
            };
            $debtorsBalance030 = (float) ($dashboardReceivablesSummary['balance_0_30'] ?? 0);
            $debtorsBalance3160 = (float) ($dashboardReceivablesSummary['balance_31_60'] ?? 0);
            $debtorsBalance61Plus = (float) ($dashboardReceivablesSummary['balance_61_plus'] ?? 0);
            ?>
            <section class="dashboard-ops-debtors-section" aria-label="Debtors follow-up">
                    <div class="dashboard-ops-panel dashboard-ops-panel-debtors">
                        <div class="dashboard-ops-panel-head dashboard-ops-debtors-head">
                            <div class="dashboard-ops-debtors-head-title">
                                <span class="dashboard-ops-debtors-head-icon" aria-hidden="true">
                                    <i data-lucide="file-text"></i>
                                </span>
                                <div>
                                    <h2>Quick Debtors Summary</h2>
                                    <p>Snapshot of outstanding receivables, aging breakdown, and key accounts needing follow-up.</p>
                                </div>
                            </div>
                            <?php if ($dashboardReceivablesHref !== '#'): ?>
                                <a href="<?php echo htmlspecialchars($dashboardReceivablesHref); ?>" class="dashboard-ops-link">
                                    View all debtors
                                    <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                                </a>
                            <?php endif; ?>
                        </div>

                        <div class="dashboard-ops-debtors-kpis" aria-label="Debtors key metrics">
                            <div class="dashboard-ops-debtors-kpi is-total">
                                <span class="dashboard-ops-debtors-kpi-icon" aria-hidden="true">
                                    <i data-lucide="wallet"></i>
                                </span>
                                <div class="dashboard-ops-debtors-kpi-copy">
                                    <span>Total outstanding</span>
                                    <strong>MK <?php echo htmlspecialchars(dashboardCurrency($dashboardReceivablesSummary['total_balance'] ?? 0)); ?></strong>
                                </div>
                            </div>
                            <div class="dashboard-ops-debtors-kpi is-overdue<?php echo ((int) ($dashboardReceivablesSummary['overdue'] ?? 0)) > 0 ? ' has-count' : ''; ?>">
                                <span class="dashboard-ops-debtors-kpi-icon" aria-hidden="true">
                                    <i data-lucide="file-warning"></i>
                                </span>
                                <div class="dashboard-ops-debtors-kpi-copy">
                                    <span>Overdue invoices</span>
                                    <strong><?php echo number_format((int) ($dashboardReceivablesSummary['overdue'] ?? 0)); ?></strong>
                                    <span class="dashboard-ops-debtors-kpi-sub">MK <?php echo htmlspecialchars(dashboardCurrency($dashboardReceivablesSummary['overdue_balance'] ?? 0)); ?></span>
                                </div>
                            </div>
                            <div class="dashboard-ops-debtors-kpi is-critical<?php echo $dashboardDebtorsCriticalCount > 0 ? ' has-count' : ''; ?>">
                                <span class="dashboard-ops-debtors-kpi-icon" aria-hidden="true">
                                    <i data-lucide="alert-triangle"></i>
                                </span>
                                <div class="dashboard-ops-debtors-kpi-copy">
                                    <span>Critical (61+ days)</span>
                                    <strong><?php echo number_format($dashboardDebtorsCriticalCount); ?></strong>
                                    <span class="dashboard-ops-debtors-kpi-sub">61+ days outstanding</span>
                                </div>
                            </div>
                            <div class="dashboard-ops-debtors-kpi is-avg-age">
                                <span class="dashboard-ops-debtors-kpi-icon" aria-hidden="true">
                                    <i data-lucide="calendar-clock"></i>
                                </span>
                                <div class="dashboard-ops-debtors-kpi-copy">
                                    <span>Avg. debt age</span>
                                    <strong><?php echo number_format((int) ($dashboardReceivablesSummary['avg_age_days'] ?? 0)); ?> days</strong>
                                </div>
                            </div>
                        </div>

                        <div class="dashboard-ops-debtors-aging-bar" aria-label="Aging breakdown by balance">
                            <div class="dashboard-ops-debtors-aging-segment is-0-30">
                                <div class="dashboard-ops-debtors-aging-top">
                                    <span class="dashboard-ops-debtors-aging-icon" aria-hidden="true">
                                        <i data-lucide="clock"></i>
                                    </span>
                                    <span class="dashboard-ops-debtors-aging-label">0-30 days</span>
                                </div>
                                <strong>MK <?php echo htmlspecialchars(dashboardCurrency($debtorsBalance030)); ?></strong>
                                <em><?php echo number_format((int) ($dashboardReceivablesSummary['age_0_30'] ?? 0)); ?> invoice(s)</em>
                                <div class="dashboard-ops-debtors-aging-progress" aria-hidden="true">
                                    <div class="dashboard-ops-debtors-aging-fill" style="width: <?php echo $debtorsAgingPercent($debtorsBalance030); ?>%;"></div>
                                </div>
                            </div>
                            <div class="dashboard-ops-debtors-aging-segment is-31-60">
                                <div class="dashboard-ops-debtors-aging-top">
                                    <span class="dashboard-ops-debtors-aging-icon" aria-hidden="true">
                                        <i data-lucide="clock"></i>
                                    </span>
                                    <span class="dashboard-ops-debtors-aging-label">31-60 days</span>
                                </div>
                                <strong>MK <?php echo htmlspecialchars(dashboardCurrency($debtorsBalance3160)); ?></strong>
                                <em><?php echo number_format((int) ($dashboardReceivablesSummary['age_31_60'] ?? 0)); ?> invoice(s)</em>
                                <div class="dashboard-ops-debtors-aging-progress" aria-hidden="true">
                                    <div class="dashboard-ops-debtors-aging-fill" style="width: <?php echo $debtorsAgingPercent($debtorsBalance3160); ?>%;"></div>
                                </div>
                            </div>
                            <div class="dashboard-ops-debtors-aging-segment is-61-plus">
                                <div class="dashboard-ops-debtors-aging-top">
                                    <span class="dashboard-ops-debtors-aging-icon" aria-hidden="true">
                                        <i data-lucide="clock"></i>
                                    </span>
                                    <span class="dashboard-ops-debtors-aging-label">61+ days</span>
                                </div>
                                <strong>MK <?php echo htmlspecialchars(dashboardCurrency($debtorsBalance61Plus)); ?></strong>
                                <em><?php echo number_format((int) ($dashboardReceivablesSummary['age_61_plus'] ?? 0)); ?> invoice(s)</em>
                                <div class="dashboard-ops-debtors-aging-progress" aria-hidden="true">
                                    <div class="dashboard-ops-debtors-aging-fill" style="width: <?php echo $debtorsAgingPercent($debtorsBalance61Plus); ?>%;"></div>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($dashboardDebtors)): ?>
                            <div class="dashboard-ops-debtors-table-head" aria-hidden="true">
                                <span>Debtor</span>
                                <span>Aging</span>
                                <span>Balance</span>
                                <span>Actions</span>
                            </div>
                            <div class="dashboard-ops-debtor-list">
                                <?php foreach ($dashboardDebtors as $debtor): ?>
                                    <?php
                                    $debtorName = (string) ($debtor['debtor_name'] ?? 'Unknown debtor');
                                    $days = (int) ($debtor['max_age_days'] ?? 0);
                                    $balanceVal = (float) ($debtor['balance'] ?? 0);
                                    $ageMeta = dashboardDebtAgeMeta($days);
                                    $debtorInitials = dashboardDebtorInitials($debtorName);
                                    $balanceTone = in_array($ageMeta['tone'], ['danger', 'critical'], true)
                                        ? 'danger'
                                        : ($ageMeta['tone'] === 'warning' ? 'warning' : 'success');
                                    $invoiceLookupUrl = BASE_URL . 'modules/invoices/list?' . http_build_query(['search' => $debtorName]);
                                    $latestInvoiceUrl = !empty($debtor['latest_invoice_id'])
                                        ? BASE_URL . 'modules/invoices/view?id=' . (int) $debtor['latest_invoice_id']
                                        : $invoiceLookupUrl;
                                    $recordPaymentUrl = !empty($debtor['latest_invoice_id'])
                                        ? BASE_URL . 'modules/invoices/record_payment?id=' . (int) $debtor['latest_invoice_id']
                                        : BASE_URL . 'modules/invoices/list';

                                    $reminderTitle = 'Follow up debtor: ' . $debtorName;
                                    $reminderNote = 'Outstanding balance MK ' . dashboardCurrency($balanceVal)
                                        . ' across ' . $debtor['invoice_count'] . ' invoice(s). Debt age: ' . $ageMeta['label'] . '.';
                                    ?>
                                    <div class="dashboard-ops-debtor-item dashboard-ops-debtor-item--interactive">
                                        <div class="dashboard-ops-debtor-info">
                                            <span class="dashboard-ops-debtor-avatar is-<?php echo htmlspecialchars($ageMeta['tone']); ?>" aria-hidden="true">
                                                <?php echo htmlspecialchars($debtorInitials); ?>
                                            </span>
                                            <div class="dashboard-ops-debtor-copy">
                                                <strong class="dashboard-ops-debtor-name"><?php echo htmlspecialchars($debtorName); ?></strong>
                                                <span class="dashboard-ops-debtor-meta">
                                                    <?php echo htmlspecialchars((string) ($debtor['invoice_count'] ?? 0)); ?> invoice(s)
                                                </span>
                                            </div>
                                        </div>
                                        <div class="dashboard-ops-debtor-badge-wrap">
                                            <span class="dashboard-ops-debtor-badge is-<?php echo htmlspecialchars($ageMeta['tone']); ?>">
                                                <?php echo htmlspecialchars($ageMeta['label']); ?>
                                            </span>
                                        </div>
                                        <div class="dashboard-ops-debtor-value-wrap">
                                            <strong class="dashboard-ops-debtor-balance is-<?php echo htmlspecialchars($balanceTone); ?>">
                                                MK <?php echo htmlspecialchars(dashboardCurrency($balanceVal)); ?>
                                            </strong>
                                        </div>
                                        <div class="dashboard-ops-debtor-actions">
                                            <a href="<?php echo htmlspecialchars($latestInvoiceUrl); ?>" class="dashboard-ops-debtor-btn text-teal" title="Open latest invoice">
                                                <i data-lucide="eye" aria-hidden="true"></i>
                                            </a>
                                            <a href="<?php echo htmlspecialchars($invoiceLookupUrl); ?>" class="dashboard-ops-debtor-btn text-blue" title="View all invoices">
                                                <i data-lucide="list" aria-hidden="true"></i>
                                            </a>
                                            <button class="dashboard-ops-debtor-btn text-amber"
                                                    data-action-modal="reminder.create"
                                                    data-action-option-title="<?php echo htmlspecialchars($reminderTitle); ?>"
                                                    data-action-option-remind-at="<?php echo htmlspecialchars($dashboardDebtorsReminderAt); ?>"
                                                    data-action-option-note="<?php echo htmlspecialchars($reminderNote); ?>"
                                                    title="Create follow-up reminder">
                                                <i data-lucide="bell" aria-hidden="true"></i>
                                            </button>
                                            <a href="<?php echo htmlspecialchars($recordPaymentUrl); ?>" class="dashboard-ops-debtor-btn text-emerald" title="Record payment">
                                                <i data-lucide="wallet" aria-hidden="true"></i>
                                            </a>
                                            <?php if (!empty($debtor['customer_email'])): ?>
                                                <a href="mailto:<?php echo htmlspecialchars($debtor['customer_email']); ?>?subject=Outstanding%20Payment%20Follow-up&body=Dear%20Customer,%0D%0A%0D%0AThis%20is%20a%20friendly%20follow-up%20regarding%20outstanding%20invoices%20with%20a%20total%20outstanding%20balance%20of%20MK%20<?php echo urlencode(dashboardCurrency($balanceVal)); ?>.%20Please%20find%20details%20in%20the%20system%20or%20contact%20us%20for%20assistance.%0D%0A%0D%0ABest%20regards,"
                                                   class="dashboard-ops-debtor-btn text-indigo"
                                                   title="Email: <?php echo htmlspecialchars($debtor['customer_email']); ?>">
                                                    <i data-lucide="mail" aria-hidden="true"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if (!empty($debtor['customer_phone'])): ?>
                                                <a href="tel:<?php echo htmlspecialchars($debtor['customer_phone']); ?>"
                                                   class="dashboard-ops-debtor-btn text-violet"
                                                   title="Call: <?php echo htmlspecialchars($debtor['customer_phone']); ?>">
                                                    <i data-lucide="phone" aria-hidden="true"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="dashboard-ops-empty">No outstanding debtors are currently on the board.</div>
                        <?php endif; ?>

                        <div class="dashboard-ops-debtors-footer-actions">
                            <a href="<?php echo BASE_URL; ?>modules/invoices/list?status=Overdue" class="dashboard-ops-debtors-footer-btn">
                                <i data-lucide="alert-circle" aria-hidden="true"></i>
                                Overdue invoices
                            </a>
                            <a href="<?php echo BASE_URL; ?>modules/invoices/list" class="dashboard-ops-debtors-footer-btn">
                                <i data-lucide="receipt" aria-hidden="true"></i>
                                All open invoices
                            </a>
                            <?php if ($dashboardReceivablesHref !== '#'): ?>
                                <a href="<?php echo htmlspecialchars($dashboardReceivablesHref); ?>" class="dashboard-ops-debtors-footer-btn is-primary">
                                    <i data-lucide="bar-chart-3" aria-hidden="true"></i>
                                    Receivables report
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
            </section>
            <?php endif; ?>

            <section class="dashboard-ops-panel" aria-label="Pending approvals">
                <div class="dashboard-ops-panel-head">
                    <div>
                        <h2>Pending Approvals</h2>
                        <p>Queues that still need attention before work can move forward.</p>
                    </div>
                    <?php if ($dashboardApprovalsHref !== '#'): ?>
                        <a href="<?php echo htmlspecialchars($dashboardApprovalsHref); ?>" class="dashboard-ops-link">
                            View all
                            <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (!empty($dashboardPendingApprovals)): ?>
                    <div class="dashboard-ops-queue">
                        <?php foreach ($dashboardPendingApprovals as $item): ?>
                            <a href="<?php echo htmlspecialchars($item['href']); ?>" class="dashboard-ops-queue-item">
                                <span class="dashboard-ops-queue-icon">
                                    <i data-lucide="<?php echo htmlspecialchars($item['icon']); ?>" aria-hidden="true"></i>
                                </span>
                                <span>
                                    <span class="dashboard-ops-queue-top">
                                        <span class="dashboard-ops-queue-type"><?php echo htmlspecialchars($item['type']); ?></span>
                                        <span class="dashboard-ops-age"><?php echo htmlspecialchars($item['age_label']); ?></span>
                                    </span>
                                    <strong class="dashboard-ops-queue-title"><?php echo htmlspecialchars($item['title']); ?></strong>
                                    <span class="dashboard-ops-queue-subtitle"><?php echo htmlspecialchars($item['subtitle']); ?></span>
                                </span>
                                <span class="dashboard-ops-queue-value"><?php echo htmlspecialchars($item['value']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="dashboard-ops-empty">No approvals are waiting in the currently visible queues.</div>
                <?php endif; ?>
            </section>

            <section class="dashboard-ops-activity" aria-label="Recent activity">
                <div class="dashboard-ops-activity-head">
                    <div>
                        <h2>Recent Activity</h2>
                        <p>Recent work movement across your visible tasks and projects.</p>
                    </div>
                    <a href="#"
                       class="dashboard-ops-link"
                       data-ws-open="wsModalActivity"
                       onclick="event.preventDefault(); openWorkspaceModal('wsModalActivity');">
                        View all
                        <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                    </a>
                </div>

                <?php if (!empty($dashboardActivityItems)): ?>
                    <div class="dashboard-ops-activity-list">
                        <?php foreach ($dashboardActivityItems as $item): ?>
                            <a href="<?php echo htmlspecialchars($item['href']); ?>" class="dashboard-ops-activity-item">
                                <span class="dashboard-ops-activity-top">
                                    <span class="dashboard-ops-activity-icon">
                                        <i data-lucide="<?php echo htmlspecialchars($item['icon']); ?>" aria-hidden="true"></i>
                                    </span>
                                    <span class="dashboard-ops-age"><?php echo htmlspecialchars($item['value']); ?></span>
                                </span>
                                <strong class="dashboard-ops-activity-title"><?php echo htmlspecialchars($item['title']); ?></strong>
                                <span class="dashboard-ops-activity-subtitle"><?php echo htmlspecialchars($item['subtitle']); ?></span>
                                <span class="dashboard-ops-activity-subtitle"><?php echo htmlspecialchars($item['meta']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="dashboard-ops-empty">Recent activity will appear here once new operational updates are logged.</div>
                <?php endif; ?>
            </section>
        </div>

        <?php if ($useLegacyDashboardShell): ?>
        <section class="dashboard-hero-card" id="dashboardHeroCard" aria-label="Dashboard hero">
            <?php include __DIR__ . '/partials/hero_greeting.php'; ?>

            <aside class="dashboard-hero-aside" aria-label="Current weather and forecast">
                <div class="weather-card weather-card--current" id="weatherCurrentCard" data-state="loading">
                    <div class="weather-card__head">
                        <span class="weather-card__kicker">Current Weather</span>
                        <button type="button"
                                class="weather-card__edit"
                                id="weatherCityEditBtn"
                                aria-label="Change city"
                                aria-expanded="false">
                            <i data-lucide="map-pinned" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="weather-card__city">
                        <i data-lucide="map-pin" aria-hidden="true"></i>
                        <span id="weatherCity">Locating…</span>
                    </div>
                    <div class="weather-card__body">
                        <div class="weather-card__readings">
                            <div class="weather-card__temp">
                                <strong id="weatherTemp">--</strong><sup>°C</sup>
                            </div>
                            <span class="weather-card__condition" id="weatherCondition">--</span>
                            <span class="weather-card__description" id="weatherDescription">Fetching live conditions…</span>
                        </div>
                        <span class="weather-card__icon" id="weatherIcon" aria-hidden="true">
                            <i data-lucide="cloud" aria-hidden="true"></i>
                        </span>
                    </div>
                    <div class="weather-card__chips">
                        <span class="weather-chip" title="Humidity">
                            <i data-lucide="droplet" aria-hidden="true"></i>
                            Humidity: <strong id="weatherHumidity">--</strong>
                        </span>
                        <span class="weather-chip" title="Wind">
                            <i data-lucide="wind" aria-hidden="true"></i>
                            Wind: <strong id="weatherWind">--</strong>
                        </span>
                        <span class="weather-chip" title="Rain chance">
                            <i data-lucide="umbrella" aria-hidden="true"></i>
                            Rain chance: <strong id="weatherRain">--</strong>
                        </span>
                    </div>
                    <form class="weather-card__editor" id="weatherCityForm" hidden autocomplete="off">
                        <label for="weatherCityInput" class="sr-only">Search city</label>
                        <div class="weather-editor-field">
                            <i data-lucide="search" aria-hidden="true"></i>
                            <input type="text"
                                   id="weatherCityInput"
                                   name="city"
                                   placeholder="Search city (e.g. Zomba)"
                                   maxlength="80"
                                   autocomplete="off">
                        </div>
                        <ul class="weather-editor-results" id="weatherCityResults" role="listbox"></ul>
                        <div class="weather-editor-actions">
                            <button type="button" class="weather-editor-btn weather-editor-btn--ghost" id="weatherUseLocation">
                                <i data-lucide="locate-fixed" aria-hidden="true"></i>
                                Use my location
                            </button>
                            <button type="button" class="weather-editor-btn" id="weatherCityCancel">Cancel</button>
                        </div>
                        <p class="weather-editor-hint" id="weatherEditorHint" aria-live="polite"></p>
                    </form>
                </div>

                <div class="weather-card weather-card--forecast" id="weatherForecastCard" data-state="loading">
                    <span class="weather-card__kicker">Next Hours Forecast</span>
                    <div class="weather-forecast-grid" id="weatherForecastGrid">
                        <?php for ($fi = 0; $fi < 4; $fi++): ?>
                            <div class="weather-forecast-slot" data-slot="<?php echo $fi; ?>">
                                <span class="weather-forecast-slot__time">--:--</span>
                                <span class="weather-forecast-slot__icon" aria-hidden="true">
                                    <i data-lucide="cloud" aria-hidden="true"></i>
                                </span>
                                <span class="weather-forecast-slot__temp">--°</span>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </aside>
        </section>

        <?php include __DIR__ . '/partials/hero_metrics.php'; ?>

        <section class="dashboard-priority-grid" aria-label="Dashboard priorities">
            <?php include __DIR__ . '/partials/focus_list.php'; ?>
            <?php include __DIR__ . '/partials/workspace_tiles.php'; ?>
        </section>
        <?php endif; ?>

        <?php if ($search_query): ?>
            <div class="todo-modal" style="max-width:none;max-height:none;margin-bottom:16px;">
                <div class="todo-modal-header">
                    <h3 class="todo-modal-title">Search results for "<?php echo htmlspecialchars($search_query); ?>"</h3>
                    <a href="<?php echo BASE_URL; ?>modules/dashboard/index" class="todo-btn-ghost">
                        <i data-lucide="x" class="inline-icon" aria-hidden="true"></i> Clear
                    </a>
                </div>
                <div class="todo-modal-body">
                    <?php if (!empty($search_results)): ?>
                        <?php foreach ($search_results as $result): ?>
                            <?php
                            $typeMeta = [
                                'estimation' => ['label' => 'Estimation', 'href' => BASE_URL . 'modules/estimations/view?id=' . $result['id']],
                                'invoice' => ['label' => 'Invoice', 'href' => BASE_URL . 'modules/invoices/list'],
                                'user' => ['label' => 'User', 'href' => BASE_URL . 'modules/hr/users/edit?id=' . $result['id']],
                            ][$result['type']] ?? ['label' => 'Result', 'href' => '#'];
                            ?>
                            <a href="<?php echo $typeMeta['href']; ?>" class="todo-row">
                                <span class="todo-row-leading"><i data-lucide="search" aria-hidden="true"></i></span>
                                <div class="todo-row-content">
                                    <div class="todo-row-title"><?php echo htmlspecialchars($result['title']); ?></div>
                                    <div class="todo-row-meta">
                                        <span class="meta-item"><i data-lucide="tag" aria-hidden="true"></i> <?php echo $typeMeta['label']; ?></span>
                                        <span class="meta-item"><i data-lucide="calendar" aria-hidden="true"></i> <?php echo date('M j, Y', strtotime($result['created_at'])); ?></span>
                                        <span class="meta-item"><?php echo htmlspecialchars($result['subtitle']); ?></span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="todo-empty">
                            <i data-lucide="search-x" class="dashboard-lucide-lg" aria-hidden="true"></i>
                            <p>No matches found.</p>
                            <p style="font-size:12px;margin-top:4px;">Try a different invoice number, customer or job description.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <?php if ($useLegacyDashboardShell): ?>
    <aside class="dashboard-calendar-sidebar" aria-label="Dashboard calendar utility">
        <?php include __DIR__ . '/partials/calendar.php'; ?>
        <?php include __DIR__ . '/partials/schedule.php'; ?>
        <?php include __DIR__ . '/partials/debtors_panel.php'; ?>
    </aside>
    <?php endif; ?>
</div>

<?php /* -----------------------------------------------------------------
   Workspace modals
   Each modal houses content that used to be rendered inline.
   ----------------------------------------------------------------- */ ?>

<!-- Performance modal -->
<div class="todo-modal-overlay" id="wsModalPerformance" role="dialog" aria-labelledby="wsModalPerformanceTitle" aria-hidden="true">
    <div class="todo-modal todo-modal--lg">
        <div class="todo-modal-header">
            <h3 class="todo-modal-title" id="wsModalPerformanceTitle">Performance snapshot</h3>
            <button class="todo-modal-close" data-ws-close aria-label="Close">&times;</button>
        </div>
        <?php include __DIR__ . '/partials/modal_performance.php'; ?>
    </div>
</div>

<!-- Activity modal -->
<div class="todo-modal-overlay" id="wsModalActivity" role="dialog" aria-labelledby="wsModalActivityTitle" aria-hidden="true">
    <div class="todo-modal todo-modal--lg">
        <div class="todo-modal-header">
            <h3 class="todo-modal-title" id="wsModalActivityTitle">Recent activity</h3>
            <button class="todo-modal-close" data-ws-close aria-label="Close">&times;</button>
        </div>
        <?php include __DIR__ . '/partials/modal_activity.php'; ?>
        <div class="todo-modal-footer">
            <?php if (hasPermission('view_tasks')): ?>
                <a href="<?php echo BASE_URL; ?>modules/tasks/list?my_tasks=1" class="todo-btn-ghost">Assigned Tasks</a>
            <?php endif; ?>
            <?php if (hasPermission('view_projects')): ?>
                <a href="<?php echo BASE_URL; ?>modules/projects/list" class="todo-btn-ghost">All Projects</a>
            <?php endif; ?>
            <button type="button" class="todo-btn-primary" data-ws-close>Close</button>
        </div>
    </div>
</div>

<!-- Reports modal -->
<div class="todo-modal-overlay" id="wsModalReports" role="dialog" aria-labelledby="wsModalReportsTitle" aria-hidden="true">
    <div class="todo-modal todo-modal--xl">
        <div class="todo-modal-header">
            <h3 class="todo-modal-title" id="wsModalReportsTitle">Reports</h3>
            <button class="todo-modal-close" data-ws-close aria-label="Close">&times;</button>
        </div>
        <?php include __DIR__ . '/partials/modal_reports.php'; ?>
        <div class="todo-modal-footer">
            <button type="button" class="todo-btn-primary" data-ws-close>Close</button>
        </div>
    </div>
</div>

<!-- Projects modal -->
<div class="todo-modal-overlay" id="wsModalProjects" role="dialog" aria-labelledby="wsModalProjectsTitle" aria-hidden="true">
    <div class="todo-modal todo-modal--lg">
        <div class="todo-modal-header">
            <h3 class="todo-modal-title" id="wsModalProjectsTitle">Projects</h3>
            <button class="todo-modal-close" data-ws-close aria-label="Close">&times;</button>
        </div>
        <?php include __DIR__ . '/partials/modal_projects.php'; ?>
        <div class="todo-modal-footer">
            <a href="<?php echo BASE_URL; ?>modules/projects/list" class="todo-btn-ghost">Open Projects workspace</a>
            <?php if (hasPermission('manage_projects')): ?>
                <a href="<?php echo BASE_URL; ?>modules/projects/create" class="todo-btn-primary">
                    <i data-lucide="plus" class="inline-icon" aria-hidden="true"></i> New Project
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Tasks modal -->
<div class="todo-modal-overlay" id="wsModalTasks" role="dialog" aria-labelledby="wsModalTasksTitle" aria-hidden="true">
    <div class="todo-modal todo-modal--xl">
        <div class="todo-modal-header">
            <h3 class="todo-modal-title" id="wsModalTasksTitle">Tasks</h3>
            <button class="todo-modal-close" data-ws-close aria-label="Close">&times;</button>
        </div>
        <?php include __DIR__ . '/partials/modal_tasks.php'; ?>
        <div class="todo-modal-footer">
            <a href="<?php echo BASE_URL; ?>modules/tasks/list" class="todo-btn-ghost">Assigned Tasks</a>
            <?php if (hasPermission('manage_tasks')): ?>
                <a href="<?php echo BASE_URL; ?>modules/tasks/create" class="todo-btn-primary">
                    <i data-lucide="plus" class="inline-icon" aria-hidden="true"></i> New Task
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Reminders modal -->
<div class="todo-modal-overlay" id="wsModalReminders" role="dialog" aria-labelledby="wsModalRemindersTitle" aria-hidden="true">
    <div class="todo-modal todo-modal--lg">
        <div class="todo-modal-header">
            <h3 class="todo-modal-title" id="wsModalRemindersTitle">Reminder board</h3>
            <button class="todo-modal-close" data-ws-close aria-label="Close">&times;</button>
        </div>
        <?php include __DIR__ . '/partials/modal_reminders.php'; ?>
        <div class="todo-modal-footer">
            <a href="<?php echo BASE_URL; ?>modules/reminders/index?scope=personal" class="todo-btn-primary">
                <i data-lucide="external-link" class="inline-icon" aria-hidden="true"></i> Open Reminder Hub
            </a>
        </div>
    </div>
</div>

<!-- Quick Actions modal -->
<div class="todo-modal-overlay" id="wsModalQuickActions" role="dialog" aria-labelledby="wsModalQuickActionsTitle" aria-hidden="true">
    <div class="todo-modal todo-modal--lg">
        <div class="todo-modal-header">
            <h3 class="todo-modal-title" id="wsModalQuickActionsTitle">Quick actions</h3>
            <button class="todo-modal-close" data-ws-close aria-label="Close">&times;</button>
        </div>
        <?php include __DIR__ . '/partials/modal_quick_actions.php'; ?>
        <div class="todo-modal-footer">
            <button type="button" class="todo-btn-primary" data-ws-close>Close</button>
        </div>
    </div>
</div>

<!-- Initialize Charts Script -->
<script>
    document.addEventListener("DOMContentLoaded", function () {
        if (typeof window.refreshAppShellIcons === 'function') {
            window.refreshAppShellIcons();
        }
        const chartData = <?php echo json_encode($chartData); ?>;
        const currencyFormat = new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });

        const compactCurrencyFormat = new Intl.NumberFormat('en-US', {
            notation: 'compact',
            maximumFractionDigits: 1
        });

        function updateDashboardClock() {
            const clockTime = document.getElementById('dashboardClockTime');
            const clockDate = document.getElementById('dashboardClockDate');
            if (!clockTime || !clockDate) {
                return;
            }

            const now = new Date();
            const liveTime = new Intl.DateTimeFormat(undefined, {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            }).format(now);
            clockTime.textContent = liveTime;

            clockDate.textContent = new Intl.DateTimeFormat(undefined, {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
                year: 'numeric'
            }).format(now);
        }

        updateDashboardClock();
        setInterval(updateDashboardClock, 1000);

        Chart.defaults.color = '#5f6f82';
        Chart.defaults.font.family = '"Plus Jakarta Sans", "Segoe UI", sans-serif';
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.boxWidth = 10;
        Chart.defaults.plugins.legend.labels.boxHeight = 10;

        const centerTextPlugin = {
            id: 'dashboardCenterText',
            afterDraw(chart, args, options) {
                const cfg = chart?.options?.plugins?.dashboardCenterText;
                if (!cfg || chart.config.type !== 'doughnut') {
                    return;
                }

                const meta = chart.getDatasetMeta(0);
                if (!meta?.data?.length) {
                    return;
                }

                const ctx = chart.ctx;
                const x = meta.data[0].x;
                const y = meta.data[0].y;

                ctx.save();
                ctx.textAlign = 'center';
                ctx.fillStyle = '#122033';
                ctx.font = '700 24px "Plus Jakarta Sans", "Segoe UI", sans-serif';
                ctx.fillText(cfg.text || '', x, y - 4);
                ctx.fillStyle = '#7a8ea2';
                ctx.font = '600 11px "Plus Jakarta Sans", "Segoe UI", sans-serif';
                ctx.fillText(cfg.subtext || '', x, y + 16);
                ctx.restore();
            }
        };

        Chart.register(centerTextPlugin);

        function withOpacity(hex, opacity) {
            const value = hex.replace('#', '');
            const bigint = parseInt(value, 16);
            const r = (bigint >> 16) & 255;
            const g = (bigint >> 8) & 255;
            const b = bigint & 255;
            return `rgba(${r}, ${g}, ${b}, ${opacity})`;
        }

        function makeVerticalGradient(canvas, color) {
            const ctx = canvas.getContext('2d');
            const gradient = ctx.createLinearGradient(0, 0, 0, canvas.height || 320);
            gradient.addColorStop(0, withOpacity(color, 0.28));
            gradient.addColorStop(1, withOpacity(color, 0.02));
            return gradient;
        }

        function chartGrid() {
            return {
                color: 'rgba(148, 163, 184, 0.14)',
                drawBorder: false
            };
        }

        function axisText() {
            return {
                color: '#7a8ea2',
                font: {
                    size: 11,
                    weight: '600'
                }
            };
        }

        function destroyDashboardCharts(scopeRoot) {
            if (typeof Chart === 'undefined' || typeof Chart.getChart !== 'function' || !scopeRoot) {
                return;
            }
            scopeRoot.querySelectorAll('canvas').forEach(function (canvas) {
                var ch = Chart.getChart(canvas);
                if (ch) {
                    ch.destroy();
                }
            });
        }

        function bootstrapDashboardCharts(scopeRoot) {
            scopeRoot = scopeRoot || document;
            destroyDashboardCharts(scopeRoot);

        const trendCanvas = scopeRoot.querySelector('#trendChart');
        if (trendCanvas) {
            const greenGradient = makeVerticalGradient(trendCanvas, '#22c55e');
            const accentGradient = makeVerticalGradient(trendCanvas, '#0f766e');

            new Chart(trendCanvas, {
                type: 'line',
                data: {
                    labels: chartData.months,
                    datasets: [
                        {
                            label: 'Estimations Created',
                            data: chartData.estimations_trend,
                            borderColor: '#22c55e',
                            backgroundColor: greenGradient,
                            pointBackgroundColor: '#22c55e',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 5,
                            borderWidth: 3,
                            tension: 0.36,
                            fill: true
                        },
                        {
                            label: 'Invoices Generated',
                            data: chartData.invoices_trend,
                            borderColor: '#0f766e',
                            backgroundColor: accentGradient,
                            pointBackgroundColor: '#0f766e',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 5,
                            borderWidth: 3,
                            tension: 0.36,
                            fill: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                            align: 'start'
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.92)',
                            padding: 12,
                            titleFont: { weight: '700' },
                            bodyFont: { weight: '600' }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: axisText()
                        },
                        y: {
                            beginAtZero: true,
                            grid: chartGrid(),
                            ticks: {
                                ...axisText(),
                                precision: 0
                            }
                        }
                    }
                }
            });
        }

        const revenueCanvas = scopeRoot.querySelector('#revenueChart');
        if (revenueCanvas) {
            new Chart(revenueCanvas, {
                type: 'bar',
                data: {
                    labels: chartData.months,
                    datasets: [
                        {
                            label: 'Invoiced (MK)',
                            data: chartData.revenue_trend,
                            backgroundColor: 'rgba(16, 185, 129, 0.72)',
                            borderColor: '#10b981',
                            borderWidth: 1,
                            borderRadius: 12,
                            maxBarThickness: 24,
                        },
                        {
                            label: 'Collected (MK)',
                            data: chartData.collected_trend,
                            backgroundColor: 'rgba(13, 148, 136, 0.72)',
                            borderColor: '#0d9488',
                            borderWidth: 1,
                            borderRadius: 12,
                            maxBarThickness: 24,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            align: 'start'
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.92)',
                            padding: 12,
                            callbacks: {
                                label: function (ctx) {
                                    return ctx.dataset.label + ': MK ' + currencyFormat.format(ctx.parsed.y || 0);
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: axisText()
                        },
                        y: {
                            beginAtZero: true,
                            grid: chartGrid(),
                            ticks: {
                                ...axisText(),
                                callback: function (val) {
                                    return 'MK ' + compactCurrencyFormat.format(val);
                                }
                            }
                        }
                    }
                }
            });
        }

        const invoiceCanvas = scopeRoot.querySelector('#invoiceChart');
        if (invoiceCanvas) {
            const invoiceValues = Object.values(chartData.invoice_status);
            new Chart(invoiceCanvas, {
                type: 'doughnut',
                data: {
                    labels: Object.keys(chartData.invoice_status),
                    datasets: [{
                        data: invoiceValues,
                        backgroundColor: [
                            '#22c55e',
                            '#ef4444',
                            '#eab308',
                            '#0f766e',
                            '#94a3b8'
                        ],
                        borderWidth: 0,
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '72%',
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.92)',
                            padding: 12
                        },
                        dashboardCenterText: {
                            text: String(invoiceValues.reduce((sum, value) => sum + value, 0)),
                            subtext: 'Invoices'
                        }
                    }
                }
            });
        }

        const projectCanvas = scopeRoot.querySelector('#projectChart');
        if (projectCanvas) {
            const projectValues = Object.values(chartData.project_status);
            new Chart(projectCanvas, {
                type: 'doughnut',
                data: {
                    labels: Object.keys(chartData.project_status),
                    datasets: [{
                        data: projectValues,
                        backgroundColor: [
                            '#0f766e',
                            '#22c55e',
                            '#6b7280',
                            '#f59e0b',
                            '#ef4444'
                        ],
                        borderWidth: 0,
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '72%',
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.92)',
                            padding: 12
                        },
                        dashboardCenterText: {
                            text: String(projectValues.reduce((sum, value) => sum + value, 0)),
                            subtext: 'Projects'
                        }
                    }
                }
            });
        }
        }

        function bootstrapInlineRevenueTrend() {
            const inlineCanvas = document.getElementById('dashboardInlineRevenueTrend');
            if (!inlineCanvas || typeof Chart === 'undefined') {
                return;
            }

            if (typeof Chart.getChart === 'function') {
                const existingChart = Chart.getChart(inlineCanvas);
                if (existingChart) {
                    existingChart.destroy();
                }
            }

            const revenueGradient = makeVerticalGradient(inlineCanvas, '#0f766e');
            const trendLabels = (chartData.months || []).map(function (label) {
                return String(label || '').split(' ')[0] || label;
            });

            new Chart(inlineCanvas, {
                type: 'line',
                data: {
                    labels: trendLabels,
                    datasets: [{
                        label: 'Revenue',
                        data: chartData.revenue_trend,
                        borderColor: '#0f766e',
                        backgroundColor: revenueGradient,
                        pointBackgroundColor: '#0f766e',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 5,
                        borderWidth: 3,
                        tension: 0.35,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.92)',
                            padding: 12,
                            callbacks: {
                                label: function (ctx) {
                                    return 'MK ' + currencyFormat.format(ctx.parsed.y || 0);
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: axisText()
                        },
                        y: {
                            beginAtZero: true,
                            grid: chartGrid(),
                            ticks: {
                                ...axisText(),
                                callback: function (val) {
                                    return 'MK ' + compactCurrencyFormat.format(val);
                                }
                            }
                        }
                    }
                }
            });
        }

        document.addEventListener('ajax:component:rendered', function (ev) {
            var detail = ev && ev.detail;
            if (!detail || detail.id !== 'dashboard.modal.reports' || !detail.root) {
                return;
            }
            bootstrapDashboardCharts(detail.root);
        });

        if (typeof window.registerWorkspaceModal === 'function') {
            window.registerWorkspaceModal('wsModalReports', function (modalEl) {
                bootstrapDashboardCharts(modalEl || document);
            });
        } else {
            bootstrapDashboardCharts(document);
        }

        bootstrapInlineRevenueTrend();
    });
</script>

<script>
(function () {
    var statIds = {
        estimations:     'stat-estimations',
        invoices:        'stat-invoices',
        unpaid_invoices: 'stat-unpaid-invoices',
        active_projects: 'stat-active-projects',
        dispatched:      'stat-dispatched',
        users:           'stat-users',
        total_revenue:   'stat-total-revenue',
        collected:       'stat-collected',
        outstanding:     'stat-outstanding',
    };

    function fmt(val) {
        return parseFloat(val).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function refreshStats() {
        $.getJSON('<?php echo BASE_URL; ?>modules/dashboard/stats', function (d) {
            if (!d || !d.success) { return; }

            var currency = ['total_revenue', 'collected', 'outstanding'];

            Object.keys(statIds).forEach(function (key) {
                var el = document.getElementById(statIds[key]);
                if (!el) { return; }
                el.textContent = currency.indexOf(key) !== -1 ? fmt(d[key]) : d[key];
            });

            // Update the two badge spans inside the Outstanding card
            var ppEl = document.getElementById('stat-partially-paid');
            if (ppEl) { ppEl.textContent = d.partially_paid + ' Partially Paid'; }

            var ubEl = document.getElementById('stat-unpaid-badge');
            if (ubEl) { ubEl.textContent = d.unpaid_invoices + ' Unpaid'; }
        });
    }

    setInterval(function () {
        if (!document.hidden) {
            refreshStats();
        }
    }, 30000);
})();
</script>

<script>
    // ===== Dashboard hero weather widget =====
    (function () {
        const BASE_URL = '<?php echo BASE_URL; ?>';
        const WEATHER_ENDPOINT = BASE_URL + 'modules/dashboard/weather';
        const HERO_BG_BASE = BASE_URL + 'assets/images/weather-illustrations/';
        const STORAGE_KEY = 'dashboardWeatherCity';
        const REFRESH_MS = 15 * 60 * 1000;

        const HERO_DEFAULTS = {
            name: 'Lilongwe, Malawi',
            latitude: -13.9626,
            longitude: 33.7741,
            timezone: 'Africa/Blantyre',
        };

        // Open-Meteo WMO weather codes mapped to label, Lucide icon name, and a "group"
        // used to choose hero backgrounds. `nightIcon` (when defined) replaces `icon`
        // after sunset so we don't show a sun glyph in a 22:00 forecast slot.
        const WMO = {
            0: { label: 'Clear sky', icon: 'sun', nightIcon: 'moon', group: 'clear', desc: 'Bright skies and steady visibility.' },
            1: { label: 'Mainly Clear', icon: 'sun', nightIcon: 'moon', group: 'clear', desc: 'Mostly sunny with light cloud movement.' },
            2: { label: 'Partly Cloudy', icon: 'cloud-sun', nightIcon: 'cloud-moon', group: 'cloud-partial', desc: 'Calm conditions with soft cloud cover.' },
            3: { label: 'Overcast', icon: 'cloud', group: 'cloud-overcast', desc: 'Grey, even cloud cover throughout the day.' },
            45: { label: 'Foggy', icon: 'cloud-fog', group: 'fog', desc: 'Reduced visibility, gentle drift of fog.' },
            48: { label: 'Rime Fog', icon: 'cloud-fog', group: 'fog', desc: 'Dense, frosty fog hugging the area.' },
            51: { label: 'Light Drizzle', icon: 'cloud-drizzle', group: 'rain', desc: 'Light, intermittent drizzle.' },
            53: { label: 'Drizzle', icon: 'cloud-rain', group: 'rain', desc: 'Steady moderate drizzle.' },
            55: { label: 'Heavy Drizzle', icon: 'cloud-rain', group: 'rain', desc: 'Persistent heavy drizzle.' },
            56: { label: 'Freezing Drizzle', icon: 'thermometer-snowflake', group: 'rain', desc: 'Freezing drizzle, careful out there.' },
            57: { label: 'Freezing Drizzle', icon: 'thermometer-snowflake', group: 'rain', desc: 'Heavy freezing drizzle.' },
            61: { label: 'Light Rain', icon: 'cloud-drizzle', group: 'rain', desc: 'Soft, steady rainfall.' },
            63: { label: 'Rain', icon: 'cloud-rain', group: 'rain', desc: 'Moderate, consistent rainfall.' },
            65: { label: 'Heavy Rain', icon: 'cloud-rain', group: 'rain', desc: 'Heavy rain, expect surface water.' },
            66: { label: 'Freezing Rain', icon: 'cloud-hail', group: 'rain', desc: 'Freezing rain — watch for ice.' },
            67: { label: 'Freezing Rain', icon: 'cloud-hail', group: 'rain', desc: 'Heavy freezing rain — caution outdoors.' },
            71: { label: 'Light Snow', icon: 'snowflake', group: 'snow', desc: 'Gentle snowfall.' },
            73: { label: 'Snow', icon: 'snowflake', group: 'snow', desc: 'Steady snowfall.' },
            75: { label: 'Heavy Snow', icon: 'snowflake', group: 'snow', desc: 'Heavy snow accumulation likely.' },
            77: { label: 'Snow Grains', icon: 'snowflake', group: 'snow', desc: 'Fine snow grains.' },
            80: { label: 'Rain Showers', icon: 'cloud-rain', group: 'rain', desc: 'Passing rain showers.' },
            81: { label: 'Rain Showers', icon: 'cloud-rain', group: 'rain', desc: 'Heavier passing showers.' },
            82: { label: 'Violent Showers', icon: 'cloud-lightning', group: 'storm', desc: 'Intense, heavy showers.' },
            85: { label: 'Snow Showers', icon: 'snowflake', group: 'snow', desc: 'Brief bursts of snow.' },
            86: { label: 'Heavy Snow Showers', icon: 'snowflake', group: 'snow', desc: 'Heavy bursts of snow.' },
            95: { label: 'Thunderstorm', icon: 'cloud-lightning', group: 'storm', desc: 'Thunderstorm activity, take cover.' },
            96: { label: 'Storm with Hail', icon: 'cloud-lightning', group: 'storm', desc: 'Thunderstorm with hail.' },
            99: { label: 'Severe Storm', icon: 'cloud-lightning', group: 'storm', desc: 'Severe thunderstorm with hail.' },
        };

        function getWmo(code) {
            if (code === null || code === undefined) {
                return WMO[0];
            }
            return WMO[code] || { label: 'Weather', icon: 'cloud', group: 'cloud-partial', desc: 'Live weather snapshot.' };
        }

        function iconFor(wmo, isDay) {
            if (!wmo) return 'cloud';
            return isDay === false && wmo.nightIcon ? wmo.nightIcon : wmo.icon;
        }

        function pickBackgroundFile(hour, isDay, group) {
            const h = hour;
            if (!isDay) {
                if (group === 'storm') return 'lighting-and-thurnder.png';
                if (group === 'clear') return 'clear-night.png';
                return 'partially-cloud-night.png';
            }
            if (group === 'storm') {
                if (h >= 17 && h <= 20) return 'lighting-and-thurnder.png';
                return 'thundery.png';
            }
            if (group === 'snow') {
                if (h >= 17 && h <= 19) return 'sunset.png';
                return 'cloudy-day.png';
            }
            if (group === 'fog') {
                if (h <= 10) return 'morning-calm.png';
                if (h >= 17 && h <= 19) return 'sunset.png';
                return 'cloudy-day.png';
            }
            if (group === 'rain') {
                if (h >= 14 && h <= 18) return '3pm-cloudy-afternoon.jpg';
                if (h >= 17 && h <= 19) return 'sunset.png';
                return 'cloudy-day.png';
            }
            if (group === 'cloud-overcast') {
                if (h >= 14 && h <= 18) return '3pm-cloudy-afternoon.jpg';
                return 'cloudy-day.png';
            }
            if (group === 'cloud-partial') {
                if (h <= 10) return '10m-partially-cloudy.jpg';
                if (h >= 11 && h <= 13) return 'partialy-cloud-noon.png';
                if (h >= 14 && h <= 16) return 'partially-cloudy-afternoon.png';
                if (h >= 17 && h <= 19) return 'sunset.png';
                return 'partially-cloud-day.png';
            }
            if (group === 'clear') {
                if (h <= 11) return 'morning-calm.png';
                if (h >= 12 && h <= 13) return 'clyde-rs-4XbZCfU2Uoo-unsplash.jpg';
                if (h >= 14 && h <= 16) return 'yellow-late-afternoon.jpg';
                if (h >= 17 && h <= 19) return 'sunset.png';
                return 'clear-night.png';
            }
            return 'cloudy-day.png';
        }

        // Admin-curated hero backgrounds. `heroConfig.backgrounds` maps
        // "group:daypart" -> absolute URL, while the global toggle decides
        // whether any background swap happens at all. Defaults assume the
        // feature is on with no overrides, so the bundled illustrations show
        // until the live config arrives (or if the request fails).
        const HERO_DAYPARTS_ORDER = ['morning', 'noon', 'afternoon', 'sunset', 'night'];
        let heroConfig = { enabled: true, backgrounds: {} };
        let heroConfigLoaded = false;

        function computeDaypart(hour, isDay) {
            if (!isDay) return 'night';
            if (hour <= 10) return 'morning';
            if (hour <= 13) return 'noon';
            if (hour <= 16) return 'afternoon';
            if (hour <= 19) return 'sunset';
            return 'night';
        }

        async function fetchHeroConfig() {
            try {
                const res = await fetch(WEATHER_ENDPOINT + '?action=hero_config', { credentials: 'same-origin' });
                if (!res.ok) return;
                const payload = await res.json();
                heroConfig = {
                    enabled: payload && payload.enabled !== false,
                    backgrounds: payload && payload.backgrounds && typeof payload.backgrounds === 'object'
                        ? payload.backgrounds : {}
                };
                heroConfigLoaded = true;
            } catch (e) {
                // Fail open: keep using the bundled defaults.
            }
        }

        function applyHeroBackground(hero, hour, isDay, group) {
            if (!hero) return;

            if (heroConfigLoaded && heroConfig.enabled === false) {
                hero.style.setProperty('--hero-bg-img', 'none');
                return;
            }

            const daypart = computeDaypart(hour, isDay);
            const slotKey = group + ':' + daypart;
            const override = heroConfig.backgrounds ? heroConfig.backgrounds[slotKey] : null;

            let url;
            if (override) {
                url = override;
            } else {
                url = HERO_BG_BASE + encodeURIComponent(pickBackgroundFile(hour, isDay, group));
            }

            hero.style.setProperty(
                '--hero-bg-img',
                "linear-gradient(135deg, rgba(15, 23, 42, 0.45), rgba(31, 41, 55, 0.45)), url('" + url + "')"
            );
        }

        function readStoredCity() {
            try {
                const raw = localStorage.getItem(STORAGE_KEY);
                if (!raw) return null;
                const parsed = JSON.parse(raw);
                if (parsed && typeof parsed.latitude === 'number' && typeof parsed.longitude === 'number') {
                    return parsed;
                }
            } catch (e) { /* ignore */ }
            return null;
        }

        function writeStoredCity(city) {
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(city));
            } catch (e) { /* ignore quota */ }
        }

        function clearStoredCity() {
            try { localStorage.removeItem(STORAGE_KEY); } catch (e) { /* ignore */ }
        }

        function formatHour(date) {
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
        }

        function detectViaGeolocation(timeoutMs) {
            return new Promise(function (resolve) {
                if (!('geolocation' in navigator)) {
                    resolve(null);
                    return;
                }
                let settled = false;
                const timer = setTimeout(function () {
                    if (!settled) { settled = true; resolve(null); }
                }, timeoutMs);
                navigator.geolocation.getCurrentPosition(
                    function (pos) {
                        if (settled) return;
                        settled = true;
                        clearTimeout(timer);
                        resolve({
                            name: 'Your location',
                            latitude: pos.coords.latitude,
                            longitude: pos.coords.longitude,
                            timezone: 'auto',
                            isAuto: true,
                        });
                    },
                    function () {
                        if (settled) return;
                        settled = true;
                        clearTimeout(timer);
                        resolve(null);
                    },
                    { enableHighAccuracy: false, timeout: timeoutMs, maximumAge: 10 * 60 * 1000 }
                );
            });
        }

        async function fetchForecast(city) {
            const params = new URLSearchParams({
                action: 'forecast',
                lat: String(city.latitude),
                lon: String(city.longitude),
                tz: city.timezone || 'auto',
            });
            const res = await fetch(WEATHER_ENDPOINT + '?' + params.toString(), { credentials: 'same-origin' });
            if (!res.ok) {
                throw new Error('Weather request failed (' + res.status + ').');
            }
            return res.json();
        }

        async function fetchGeocode(query) {
            const params = new URLSearchParams({ action: 'geocode', q: query });
            const res = await fetch(WEATHER_ENDPOINT + '?' + params.toString(), { credentials: 'same-origin' });
            if (!res.ok) {
                throw new Error('Geocoding failed (' + res.status + ').');
            }
            return res.json();
        }

        async function fetchReverse(lat, lon) {
            const params = new URLSearchParams({
                action: 'reverse',
                lat: String(lat),
                lon: String(lon),
            });
            const res = await fetch(WEATHER_ENDPOINT + '?' + params.toString(), { credentials: 'same-origin' });
            if (!res.ok) {
                throw new Error('Reverse lookup failed (' + res.status + ').');
            }
            return res.json();
        }

        async function enrichWithReverse(city) {
            // Auto-detected positions only carry lat/lon. Fetch the actual locality
            // name so the UI can show "Lilongwe, Malawi" instead of "Your location".
            if (!city || !city.isAuto) return city;
            try {
                const result = await fetchReverse(city.latitude, city.longitude);
                if (result && result.name) {
                    return Object.assign({}, city, {
                        name: result.name,
                        admin1: result.admin1 || '',
                        country: result.country || '',
                        country_code: result.country_code || '',
                        isAuto: true,
                    });
                }
            } catch (err) {
                console.warn('[weather] reverse geocoding failed', err);
            }
            return city;
        }

        function setText(id, value) {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        }

        function setIcon(host, iconName) {
            if (!host) return;
            host.innerHTML = '<i data-lucide="' + iconName + '" aria-hidden="true"></i>';
            if (typeof window.refreshAppShellIcons === 'function') {
                window.refreshAppShellIcons();
            }
        }

        function describeCity(city) {
            const parts = [];
            if (city.name) parts.push(city.name);
            if (city.admin1 && city.admin1 !== city.name) parts.push(city.admin1);
            if (city.country) parts.push(city.country);
            return parts.join(', ');
        }

        function renderForecast(payload, hero) {
            const current = payload.current || {};
            const wmo = getWmo(current.weather_code);
            const currentIsDay = current.is_day === undefined ? true : !!current.is_day;

            // Current card
            const currentCard = document.getElementById('weatherCurrentCard');
            if (currentCard) currentCard.dataset.state = 'ready';

            setText('weatherTemp', current.temperature !== null && current.temperature !== undefined
                ? Math.round(current.temperature) : '--');
            setText('weatherCondition', wmo.label);
            setText('weatherDescription', wmo.desc);
            setText('weatherHumidity', current.humidity !== null && current.humidity !== undefined
                ? current.humidity + '%' : '--');
            setText('weatherWind', current.wind_speed !== null && current.wind_speed !== undefined
                ? Math.round(current.wind_speed) + ' km/h' : '--');

            // Rain chance: use the upcoming hour's precipitation_probability if available,
            // else fall back to the precipitation reading.
            let rainChance = null;
            const next = (payload.hourly_next4 || [])[0];
            if (next && next.precipitation_probability !== null && next.precipitation_probability !== undefined) {
                rainChance = next.precipitation_probability + '%';
            } else if (current.precipitation !== null && current.precipitation !== undefined) {
                rainChance = current.precipitation > 0 ? Math.round(current.precipitation) + ' mm' : '0%';
            }
            setText('weatherRain', rainChance || '--');

            setIcon(document.getElementById('weatherIcon'), iconFor(wmo, currentIsDay));

            // Forecast slots
            const slots = document.querySelectorAll('#weatherForecastGrid .weather-forecast-slot');
            (payload.hourly_next4 || []).slice(0, 4).forEach(function (slot, idx) {
                const node = slots[idx];
                if (!node) return;
                const slotWmo = getWmo(slot.weather_code);
                const slotIsDay = slot.is_day === undefined ? true : !!slot.is_day;
                const t = node.querySelector('.weather-forecast-slot__time');
                const ic = node.querySelector('.weather-forecast-slot__icon');
                const tp = node.querySelector('.weather-forecast-slot__temp');
                if (t) {
                    const stamp = String(slot.time || '');
                    t.textContent = stamp.length >= 16 ? stamp.slice(11, 16) : '--:--';
                }
                if (ic) setIcon(ic, iconFor(slotWmo, slotIsDay));
                if (tp) tp.textContent = slot.temperature !== null && slot.temperature !== undefined
                    ? Math.round(slot.temperature) + '°C' : '--°';
            });

            const forecastCard = document.getElementById('weatherForecastCard');
            if (forecastCard) forecastCard.dataset.state = 'ready';

            // Background mapping based on the timezone-aware current time.
            let hour = new Date().getHours();
            const stamp = String(current.time || '');
            if (stamp.length >= 13) {
                const parsed = parseInt(stamp.slice(11, 13), 10);
                if (!Number.isNaN(parsed)) hour = parsed;
            }
            applyHeroBackground(hero, hour, currentIsDay, wmo.group);
        }

        function renderError(message) {
            setText('weatherCondition', 'Weather unavailable');
            setText('weatherDescription', message || 'Unable to reach the live weather service.');
            const currentCard = document.getElementById('weatherCurrentCard');
            if (currentCard) currentCard.dataset.state = 'error';
        }

        function setCityLabel(city) {
            const label = describeCity(city) || city.name || 'Lilongwe, Malawi';
            setText('weatherCity', label);
        }

        async function loadAndRender(city, hero) {
            try {
                setCityLabel(city);
                const payload = await fetchForecast(city);
                renderForecast(payload, hero);
            } catch (err) {
                console.warn('[weather] forecast fetch failed', err);
                renderError(err && err.message ? err.message : '');
            }
        }

        function debounce(fn, wait) {
            let t = null;
            return function () {
                const args = arguments;
                clearTimeout(t);
                t = setTimeout(function () { fn.apply(null, args); }, wait);
            };
        }

        function initEditor(state) {
            const editBtn = document.getElementById('weatherCityEditBtn');
            const form = document.getElementById('weatherCityForm');
            const input = document.getElementById('weatherCityInput');
            const list = document.getElementById('weatherCityResults');
            const cancel = document.getElementById('weatherCityCancel');
            const useLoc = document.getElementById('weatherUseLocation');
            const hint = document.getElementById('weatherEditorHint');

            if (!editBtn || !form || !input || !list) return;

            function open() {
                form.hidden = false;
                editBtn.setAttribute('aria-expanded', 'true');
                if (hint) hint.textContent = '';
                list.innerHTML = '';
                input.value = '';
                setTimeout(function () { input.focus(); }, 30);
            }

            function close() {
                form.hidden = true;
                editBtn.setAttribute('aria-expanded', 'false');
                list.innerHTML = '';
            }

            editBtn.addEventListener('click', function () {
                if (form.hidden) open(); else close();
            });

            if (cancel) cancel.addEventListener('click', close);

            const runSearch = debounce(async function (query) {
                if (!query || query.length < 2) {
                    list.innerHTML = '';
                    if (hint) hint.textContent = '';
                    return;
                }
                if (hint) hint.textContent = 'Searching…';
                try {
                    const data = await fetchGeocode(query);
                    list.innerHTML = '';
                    const results = (data && data.results) || [];
                    if (!results.length) {
                        if (hint) hint.textContent = 'No matches found.';
                        return;
                    }
                    if (hint) hint.textContent = '';
                    results.forEach(function (row) {
                        const li = document.createElement('li');
                        li.className = 'weather-editor-result';
                        li.tabIndex = 0;
                        li.setAttribute('role', 'option');
                        const region = [row.admin1, row.country].filter(Boolean).join(', ');
                        li.innerHTML = '<span>' + escapeHtml(row.name) + '</span>'
                            + (region ? '<small>' + escapeHtml(region) + '</small>' : '');
                        li.addEventListener('click', function () {
                            const city = {
                                name: row.name,
                                admin1: row.admin1 || '',
                                country: row.country || '',
                                latitude: row.latitude,
                                longitude: row.longitude,
                                timezone: row.timezone || 'auto',
                            };
                            writeStoredCity(city);
                            state.city = city;
                            close();
                            loadAndRender(city, state.hero);
                        });
                        list.appendChild(li);
                    });
                } catch (err) {
                    if (hint) hint.textContent = 'Search failed. Try again.';
                }
            }, 280);

            input.addEventListener('input', function () {
                runSearch(input.value.trim());
            });

            if (useLoc) {
                useLoc.addEventListener('click', async function () {
                    if (hint) hint.textContent = 'Requesting your location…';
                    const detected = await detectViaGeolocation(8000);
                    if (!detected) {
                        if (hint) hint.textContent = 'Could not detect your location.';
                        return;
                    }
                    if (hint) hint.textContent = 'Resolving city name…';
                    const enriched = await enrichWithReverse(detected);
                    clearStoredCity();
                    writeStoredCity(enriched);
                    state.city = enriched;
                    close();
                    loadAndRender(enriched, state.hero);
                });
            }
        }

        function escapeHtml(value) {
            return String(value).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        async function resolveStartingCity() {
            const stored = readStoredCity();
            if (stored) {
                // Backfill the name lazily for older auto-detected entries that
                // were saved before reverse lookup was wired up.
                if (stored.isAuto && (!stored.name || stored.name === 'Your location')) {
                    const enriched = await enrichWithReverse(stored);
                    if (enriched !== stored) {
                        writeStoredCity(enriched);
                        return enriched;
                    }
                }
                return stored;
            }

            const detected = await detectViaGeolocation(6000);
            if (detected) {
                const enriched = await enrichWithReverse(detected);
                writeStoredCity(enriched);
                return enriched;
            }
            return Object.assign({}, HERO_DEFAULTS);
        }

        window.initDashboardWeatherWidget = async function () {
            const hero = document.getElementById('dashboardHeroCard');
            const state = { hero: hero, city: null };

            initEditor(state);

            // Hero config first so the very first paint already honours the
            // admin's curated images and the global enable/disable toggle.
            await fetchHeroConfig();

            const city = await resolveStartingCity();
            state.city = city;
            await loadAndRender(city, hero);

            setInterval(function () {
                if (document.hidden) return;
                if (!state.city) return;
                loadAndRender(state.city, hero);
            }, REFRESH_MS);
        };
    })();
</script>

<?php include '../../includes/footer.php'; ?>
