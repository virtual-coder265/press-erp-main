<?php
/**
 * Dashboard partial - sidebar calendar (month grid + nav).
 *
 * Component id: dashboard.calendar
 * Required context:
 *   - $dashboardCalMonth, $dashboardSelectedDay, $dashboardTodayYmd
 *   - $dashboardCalendarStart, $dashboardCalendarLabel, $dashboardCalendarYear
 *   - $dashboardPrevMonth, $dashboardNextMonth, $dashboardCalendarGridStart
 *   - $dashboardCalendarByDay, $dashboardBuildCalendarUrl
 */
?>
<div class="dashboard-calendar-card"
     data-ajax-component="dashboard.calendar"
     data-ajax-refresh-on="action:reminder.create"
     data-ajax-params='<?php echo htmlspecialchars(json_encode([
        'cal_month' => $dashboardCalMonth,
        'cal_day' => $dashboardSelectedDay,
     ]), ENT_QUOTES, 'UTF-8'); ?>'>
    <div class="dashboard-calendar-nav">
        <a href="<?php echo htmlspecialchars($dashboardBuildCalendarUrl(['cal_month' => $dashboardPrevMonth])); ?>" class="dashboard-calendar-arrow" aria-label="Previous month">
            <i data-lucide="chevron-left" aria-hidden="true"></i>
        </a>
        <select class="dashboard-calendar-select" aria-label="Calendar month" onchange="if (this.value) window.location.href=this.value;">
            <option value="<?php echo htmlspecialchars($dashboardBuildCalendarUrl(['cal_month' => $dashboardPrevMonth])); ?>">
                <?php echo htmlspecialchars(date('F Y', strtotime($dashboardCalendarStart . ' -1 month'))); ?>
            </option>
            <option value="<?php echo htmlspecialchars($dashboardBuildCalendarUrl(['cal_month' => $dashboardCalMonth, 'cal_day' => $dashboardSelectedDay])); ?>" selected>
                <?php echo htmlspecialchars($dashboardCalendarLabel); ?>
            </option>
            <option value="<?php echo htmlspecialchars($dashboardBuildCalendarUrl(['cal_month' => $dashboardNextMonth])); ?>">
                <?php echo htmlspecialchars(date('F Y', strtotime($dashboardCalendarStart . ' +1 month'))); ?>
            </option>
        </select>
        <span class="dashboard-calendar-year"><?php echo htmlspecialchars($dashboardCalendarYear); ?></span>
        <a href="<?php echo htmlspecialchars($dashboardBuildCalendarUrl(['cal_month' => $dashboardNextMonth])); ?>" class="dashboard-calendar-arrow" aria-label="Next month">
            <i data-lucide="chevron-right" aria-hidden="true"></i>
        </a>
    </div>

    <div class="dashboard-calendar-weekdays" aria-hidden="true">
        <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday): ?>
            <span><?php echo htmlspecialchars($weekday); ?></span>
        <?php endforeach; ?>
    </div>

    <div class="dashboard-calendar-grid">
        <?php for ($i = 0; $i < 42; $i++): ?>
            <?php
            $cellTs = strtotime('+' . $i . ' days', $dashboardCalendarGridStart);
            $cellYmd = date('Y-m-d', $cellTs);
            $isCurrentMonth = date('Y-m', $cellTs) === $dashboardCalMonth;
            $isToday = $cellYmd === $dashboardTodayYmd;
            $isSelected = $cellYmd === $dashboardSelectedDay;
            $cellItems = $dashboardCalendarByDay[$cellYmd] ?? [];
            $cellClass = 'dashboard-calendar-day';
            $cellClass .= !$isCurrentMonth ? ' is-muted' : '';
            $cellClass .= $isToday ? ' is-today' : '';
            $cellClass .= $isSelected ? ' is-selected' : '';
            ?>
            <a href="<?php echo htmlspecialchars($dashboardBuildCalendarUrl(['cal_month' => date('Y-m', $cellTs), 'cal_day' => $cellYmd])); ?>" class="<?php echo htmlspecialchars($cellClass); ?>">
                <span><?php echo (int) date('j', $cellTs); ?></span>
                <?php if (!empty($cellItems)): ?>
                    <i aria-hidden="true"></i>
                <?php endif; ?>
            </a>
        <?php endfor; ?>
    </div>
</div>
