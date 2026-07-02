<?php
/**
 * Dashboard partial - hero greeting + clock + CTA row.
 *
 * Component id: dashboard.hero.greeting
 * Required context (from dashboard_collect_context):
 *   - $dashboardGreeting
 *
 * The weather aside lives in its own block; this partial only owns the copy
 * column so it can refresh its greeting/CTA links without touching the
 * weather widget.
 */
?>
<div class="dashboard-hero-copy"
     data-ajax-component="dashboard.hero.greeting"
     data-ajax-refresh-on="focus"
     data-ajax-stale="60000">
    <span class="dashboard-hero-kicker">
        <i data-lucide="sun" aria-hidden="true"></i>
        Main Dashboard
    </span>
    <h1 class="dashboard-hero-title">
        <?php echo htmlspecialchars($dashboardGreeting); ?>, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'there'); ?>
    </h1>
    <p class="dashboard-hero-subtitle">
        We have collected all essential data you may need and summarised it here. Click the cards to view more details.
    </p>

    <div class="weather-card weather-card--clock" id="weatherClockCard">
        <span class="weather-card__decor" aria-hidden="true">
            <i data-lucide="clock" aria-hidden="true"></i>
        </span>
        <div class="weather-card--clock__copy">
            <span class="weather-card__kicker">Today:</span>
            <strong class="weather-card__big" id="dashboardClockTime">--:--:--</strong>
            <small class="weather-card__sub" id="dashboardClockDate">--</small>
        </div>
    </div>

    <div class="dashboard-hero-cta-row">
        <a href="#"
           class="dashboard-hero-button is-primary"
           data-ws-open="wsModalQuickActions"
           onclick="event.preventDefault(); openWorkspaceModal('wsModalQuickActions');">
            Quick Actions
            <i data-lucide="arrow-right" aria-hidden="true"></i>
        </a>
        <a href="<?php echo BASE_URL; ?>modules/reminders/index?hub=calendar" class="dashboard-hero-button">
            Reminder Calendar
            <i data-lucide="calendar-days" aria-hidden="true"></i>
        </a>
    </div>
</div>
