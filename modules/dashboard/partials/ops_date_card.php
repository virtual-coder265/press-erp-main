<?php
/** @var string $dashboardTodayDateLabel */
/** @var string $dashboardTodayWeekday */
?>
<div class="dashboard-ops-date-card" id="dashboardOpsDateCard" data-state="loading">
    <span class="dashboard-ops-date-card__wave" aria-hidden="true"></span>
    <div class="dashboard-ops-date-card__main">
        <span class="dashboard-ops-date-card__icon" aria-hidden="true">
            <i data-lucide="calendar-days"></i>
        </span>
        <div class="dashboard-ops-date-card__copy">
            <span class="dashboard-ops-date-label">Today</span>
            <strong class="dashboard-ops-date-value"><?php echo htmlspecialchars($dashboardTodayDateLabel); ?></strong>
            <span class="dashboard-ops-date-meta"><?php echo htmlspecialchars($dashboardTodayWeekday); ?></span>
        </div>
    </div>
    <div class="dashboard-ops-date-card__weather" aria-label="Current weather">
        <span class="dashboard-ops-date-card__weather-icon" id="dashboardOpsWeatherIcon" aria-hidden="true">
            <i data-lucide="cloud-sun"></i>
        </span>
        <span class="dashboard-ops-date-card__weather-temp" id="dashboardOpsWeatherTemp">--°</span>
    </div>
</div>
