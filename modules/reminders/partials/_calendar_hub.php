<?php
/**
 * Reminder Hub — Calendar & Alarm Center (expects variables from index.php).
 *
 * @var string $calMonth Y-m
 * @var string $calendarStart Y-m-d
 * @var string $calendarEnd Y-m-d
 * @var string $selectedDay Y-m-d
 * @var array $calendarRows
 * @var array $calendarByDay
 * @var array $weekByDay
 * @var string $weekStart
 * @var string $weekEnd
 * @var array $upcomingAlarms
 * @var int $alarmActiveCount
 * @var string $sourceFilter
 * @var string $search
 * @var string $view
 */

$calendarByDay = $calendarByDay ?? [];
$upcomingAlarms = $upcomingAlarms ?? [];
$alarmActiveCount = (int) ($alarmActiveCount ?? 0);
$calMonth = $calMonth ?? date('Y-m');
$calendarStart = $calendarStart ?? date('Y-m-01');
$selectedDay = $selectedDay ?? date('Y-m-d');
$view = $view ?? 'active';
$search = $search ?? '';
$sourceFilter = $sourceFilter ?? 'all';

$calMonthLabel = date('F Y', strtotime($calendarStart));
$prevMonth = date('Y-m', strtotime($calendarStart . ' -1 month'));
$nextMonth = date('Y-m', strtotime($calendarStart . ' +1 month'));

$firstOfMonth = strtotime($calendarStart);
$dow = (int) date('N', $firstOfMonth);
$gridStartTs = strtotime('-' . ($dow - 1) . ' days', $firstOfMonth);

$weekdayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

$buildHubUrl = static function (array $extra) use ($view, $sourceFilter, $search): string {
    $q = array_filter(array_merge([
        'hub' => 'calendar',
        'view' => $view !== 'active' ? $view : null,
        'source' => $sourceFilter !== 'all' ? $sourceFilter : null,
        'search' => $search !== '' ? $search : null,
    ], $extra));

    return BASE_URL . 'modules/reminders/index' . (!empty($q) ? '?' . http_build_query($q) : '');
};

$editBoardUrl = static function (int $reminderId) use ($view, $sourceFilter, $search): string {
    $q = array_filter([
        'detail' => $reminderId > 0 ? $reminderId : null,
        'source' => $sourceFilter !== 'all' ? $sourceFilter : null,
        'search' => $search !== '' ? $search : null,
    ]);

    return BASE_URL . 'modules/reminders/index' . (!empty($q) ? '?' . http_build_query($q) : '');
};

$dayItems = $calendarByDay[$selectedDay] ?? [];
$todayYmd = date('Y-m-d');
$selectedDayReminderAt = date('Y-m-d\T09:00', strtotime($selectedDay));
?>

<section class="rh-cal-layout">
    <div class="rh-cal-main">
        <form method="GET" action="" class="rh-cal-mini-filter">
            <input type="hidden" name="hub" value="calendar">
            <input type="hidden" name="cal_month" value="<?php echo htmlspecialchars($calMonth); ?>">
            <input type="hidden" name="cal_day" value="<?php echo htmlspecialchars($selectedDay); ?>">
            <div class="rh-cal-mini-filter-field">
                <label class="rh-cal-mini-label" for="rh-cal-source">Source</label>
                <select id="rh-cal-source" name="source" class="rh-cal-mini-select">
                    <option value="all" <?php echo $sourceFilter === 'all' ? 'selected' : ''; ?>>All sources</option>
                    <option value="self" <?php echo $sourceFilter === 'self' ? 'selected' : ''; ?>>Personal only</option>
                    <option value="task_assignment" <?php echo $sourceFilter === 'task_assignment' ? 'selected' : ''; ?>>Assigned tasks</option>
                </select>
            </div>
            <div class="rh-cal-mini-filter-field rh-cal-mini-grow">
                <label class="rh-cal-mini-label" for="rh-cal-search">Search</label>
                <input id="rh-cal-search" type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" class="rh-cal-mini-input" placeholder="Title, note, project">
            </div>
            <button type="submit" class="rh-cal-mini-submit">Apply</button>
            <?php
            $exportQs = http_build_query(array_filter([
                'start' => $calendarStart,
                'end' => $calendarEnd,
                'source' => $sourceFilter !== 'all' ? $sourceFilter : null,
                'search' => $search !== '' ? $search : null,
            ]));
            $exportUrl = BASE_URL . 'modules/reminders/calendar_export' . ($exportQs !== '' ? '?' . $exportQs : '');
            ?>
            <a href="<?php echo htmlspecialchars($exportUrl); ?>" class="rh-cal-export-link">Export .ics</a>
        </form>

        <div class="rh-cal-toolbar">
            <div class="rh-cal-toolbar-title">
                <h2 class="rh-cal-heading">Calendar</h2>
                <p class="rh-cal-sub">Personal reminders and assigned task cards share one schedule timeline, so your planning stays in a single view.</p>
            </div>
            <div class="rh-cal-nav">
                <a href="<?php echo htmlspecialchars($buildHubUrl(['cal_month' => $prevMonth, 'cal_day' => null])); ?>" class="rh-cal-nav-btn" title="Previous month">
                    <i class="material-icons text-base">chevron_left</i>
                </a>
                <span class="rh-cal-nav-label"><?php echo htmlspecialchars($calMonthLabel); ?></span>
                <a href="<?php echo htmlspecialchars($buildHubUrl(['cal_month' => $nextMonth, 'cal_day' => null])); ?>" class="rh-cal-nav-btn" title="Next month">
                    <i class="material-icons text-base">chevron_right</i>
                </a>
                <a href="<?php echo htmlspecialchars($buildHubUrl(['cal_month' => date('Y-m'), 'cal_day' => date('Y-m-d')])); ?>" class="rh-cal-today">Today</a>
                <a href="<?php echo htmlspecialchars(BASE_URL . 'modules/reminders/index?hub=calendar&cal_day=' . urlencode($selectedDay)); ?>"
                   class="rh-cal-add-schedule"
                   data-action-modal="reminder.create"
                   data-action-option-remind-at="<?php echo htmlspecialchars($selectedDayReminderAt); ?>">
                    <i class="material-icons text-sm">alarm_add</i>
                    <span>Add schedule</span>
                </a>
            </div>
        </div>

        <div class="rh-cal-weekdays">
            <?php foreach ($weekdayLabels as $wd): ?>
            <span><?php echo htmlspecialchars($wd); ?></span>
            <?php endforeach; ?>
        </div>

        <div class="rh-cal-grid">
            <?php
            for ($i = 0; $i < 42; $i++) {
                $cellTs = strtotime('+' . $i . ' days', $gridStartTs);
                $ymd = date('Y-m-d', $cellTs);
                $inMonth = (date('Y-m', $cellTs) === $calMonth);
                $isToday = ($ymd === $todayYmd);
                $isSelected = ($ymd === $selectedDay);
                $dayRem = $calendarByDay[$ymd] ?? [];
                $n = count($dayRem);
                $more = min(3, $n);
                $url = $buildHubUrl(['cal_month' => $calMonth, 'cal_day' => $ymd]);
                $cellClass = 'rh-cal-cell';
                if (!$inMonth) {
                    $cellClass .= ' is-muted';
                }
                if ($isToday) {
                    $cellClass .= ' is-today';
                }
                if ($isSelected) {
                    $cellClass .= ' is-selected';
                }
                ?>
            <a href="<?php echo htmlspecialchars($url); ?>" class="<?php echo htmlspecialchars($cellClass); ?>">
                <span class="rh-cal-cell-num"><?php echo (int) date('j', $cellTs); ?></span>
                <?php if ($n > 0): ?>
                <span class="rh-cal-dots" aria-hidden="true">
                    <?php for ($d = 0; $d < $more; $d++):
                        $r = $dayRem[$d];
                        $isTask = !empty($r['is_task_linked']);
                    ?>
                    <i class="rh-cal-dot <?php echo $isTask ? 'is-task' : 'is-self'; ?>"></i>
                    <?php endfor; ?>
                    <?php if ($n > 3): ?>
                    <span class="rh-cal-more">+<?php echo (int) ($n - 3); ?></span>
                    <?php endif; ?>
                </span>
                <?php endif; ?>
            </a>
                <?php
            }
            ?>
        </div>

        <div class="rh-cal-day-panel">
            <div class="rh-cal-day-head">
                <h3 class="rh-cal-day-title"><?php echo htmlspecialchars(date('l, M j', strtotime($selectedDay))); ?></h3>
                <span class="rh-cal-day-badge"><?php echo count($dayItems); ?> item<?php echo count($dayItems) === 1 ? '' : 's'; ?></span>
            </div>
            <?php if (empty($dayItems)): ?>
            <p class="rh-cal-empty">Nothing scheduled for this day. Add a personal reminder from the form above, or open a task to adjust its due date.</p>
            <?php else: ?>
            <ul class="rh-cal-day-list">
                <?php foreach ($dayItems as $dr): ?>
                <li class="rh-cal-day-item">
                    <span class="rh-cal-day-item-type <?php echo !empty($dr['is_task_linked']) ? 'is-task' : 'is-self'; ?>">
                        <?php echo !empty($dr['is_task_linked']) ? 'Task' : 'Personal'; ?>
                    </span>
                    <div class="rh-cal-day-item-body">
                        <div class="rh-cal-day-item-title"><?php echo htmlspecialchars($dr['title'] ?? 'Reminder'); ?></div>
                        <div class="rh-cal-day-item-meta">
                            <?php if (!empty($dr['remind_at_display'])): ?>
                            <span><i class="material-icons text-xs align-middle">schedule</i> <?php echo htmlspecialchars($dr['remind_at_display']); ?></span>
                            <?php elseif (!empty($dr['due_on'])): ?>
                            <span><i class="material-icons text-xs align-middle">event</i> <?php echo htmlspecialchars($dr['due_meta']['compact_label'] ?? date('M j', strtotime($dr['due_on']))); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($dr['project_name'])): ?>
                            <span><i class="material-icons text-xs align-middle">folder</i> <?php echo htmlspecialchars($dr['project_name']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($dr['is_task_linked']) && !empty($dr['task_id'])): ?>
                    <a class="rh-cal-day-link" href="<?php echo htmlspecialchars(BASE_URL . 'modules/tasks/view?id=' . (int) $dr['task_id']); ?>" data-action-modal="reminder.create" data-action-option-id="<?php echo (int) ($dr['id'] ?? 0); ?>">Details</a>
                    <?php else: ?>
                    <a class="rh-cal-day-link" href="<?php echo htmlspecialchars($editBoardUrl((int) ($dr['id'] ?? 0))); ?>" data-action-modal="reminder.create" data-action-option-id="<?php echo (int) ($dr['id'] ?? 0); ?>">Edit</a>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>

    <aside class="rh-cal-aside">
        <div class="rh-cal-card">
            <div class="rh-cal-card-head">
                <span class="rh-cal-card-kicker">This week</span>
                <h3 class="rh-cal-card-title">At a glance</h3>
            </div>
            <p class="rh-cal-card-copy"><?php echo htmlspecialchars(date('M j', strtotime($weekStart)) . ' – ' . date('M j, Y', strtotime($weekEnd))); ?></p>
            <div class="rh-week-strip">
                <?php
                for ($w = 0; $w < 7; $w++) {
                    $d = date('Y-m-d', strtotime($weekStart . ' +' . $w . ' days'));
                    $items = $weekByDay[$d] ?? [];
                    $cnt = count($items);
                    $isTodayW = ($d === $todayYmd);
                    ?>
                <div class="rh-week-col <?php echo $isTodayW ? 'is-today' : ''; ?>">
                    <span class="rh-week-dow"><?php echo htmlspecialchars($weekdayLabels[$w]); ?></span>
                    <span class="rh-week-num"><?php echo (int) date('j', strtotime($d)); ?></span>
                    <span class="rh-week-count"><?php echo (int) $cnt; ?></span>
                </div>
                <?php } ?>
            </div>
            <ul class="rh-week-list">
                <?php
                $weekFlat = [];
                foreach ($weekByDay as $d => $list) {
                    foreach ($list as $wr) {
                        $weekFlat[] = ['date' => $d, 'row' => $wr];
                    }
                }
                usort($weekFlat, static function ($a, $b) {
                    return strcmp($a['date'], $b['date']);
                });
                $weekFlat = array_slice($weekFlat, 0, 8);
                ?>
                <?php if (empty($weekFlat)): ?>
                <li class="rh-week-li rh-week-li-empty">No items this week.</li>
                <?php else: ?>
                    <?php foreach ($weekFlat as $pack):
                        $wr = $pack['row'];
                        $d = $pack['date'];
                    ?>
                <li class="rh-week-li">
                    <span class="rh-week-li-date"><?php echo htmlspecialchars(date('D j', strtotime($d))); ?></span>
                    <span class="rh-week-li-title"><?php echo htmlspecialchars($wr['title'] ?? ''); ?></span>
                    <span class="rh-week-li-src <?php echo !empty($wr['is_task_linked']) ? 'is-task' : 'is-self'; ?>"><?php echo !empty($wr['is_task_linked']) ? 'Task' : 'You'; ?></span>
                </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
            <a href="<?php echo htmlspecialchars($buildHubUrl(['cal_month' => date('Y-m', strtotime($weekStart)), 'cal_day' => $todayYmd])); ?>" class="rh-cal-ghost-link">View this week in calendar <i class="material-icons text-sm">arrow_forward</i></a>
        </div>

        <div class="rh-cal-card rh-alarm-card">
            <div class="rh-cal-card-head">
                <span class="rh-cal-card-kicker"><i class="material-icons text-sm align-middle text-amber-600">notifications_active</i> Alarm center</span>
                <h3 class="rh-cal-card-title">Scheduled alarms</h3>
            </div>
            <p class="rh-alarm-stat">
                <strong><?php echo number_format($alarmActiveCount); ?></strong>
                <span>active alarm<?php echo $alarmActiveCount === 1 ? '' : 's'; ?> (enabled + dated)</span>
            </p>
            <?php if (empty($upcomingAlarms)): ?>
            <p class="rh-cal-empty">No upcoming rings. Enable alarms on reminder cards and set due times.</p>
            <?php else: ?>
            <ul class="rh-alarm-list">
                <?php foreach (array_slice($upcomingAlarms, 0, 8) as $al): ?>
                <li class="rh-alarm-li">
                    <a href="<?php echo htmlspecialchars($al['href']); ?>" class="rh-alarm-li-link">
                        <span class="rh-alarm-li-title"><?php echo htmlspecialchars($al['title']); ?></span>
                        <span class="rh-alarm-li-time"><?php echo htmlspecialchars($al['trigger_display']); ?></span>
                        <span class="rh-alarm-li-sub"><?php echo htmlspecialchars($al['offset_label']); ?> · <?php echo htmlspecialchars($al['source_label']); ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <p class="rh-alarm-hint text-xs text-slate-500 mt-3">Alarms fire from the browser when this app is open; background delivery uses your existing notification settings.</p>
        </div>
    </aside>
</section>

<style>
.rh-cal-mini-filter {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 0.65rem;
    margin-bottom: 1rem;
    padding: 0.85rem 1rem;
    border-radius: 1rem;
    border: 1px solid rgba(213, 219, 238, 0.92);
    background: rgba(255, 255, 255, 0.92);
}
.rh-cal-mini-filter-field { min-width: 140px; }
.rh-cal-mini-grow { flex: 1; min-width: 200px; }
.rh-cal-mini-label {
    display: block;
    font-size: 0.68rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #8c97b2;
    margin-bottom: 0.25rem;
}
.rh-cal-mini-select,
.rh-cal-mini-input {
    width: 100%;
    padding: 0.55rem 0.75rem;
    border-radius: 0.75rem;
    border: 1px solid rgba(213, 219, 238, 0.92);
    font-size: 0.84rem;
    color: #1f2433;
    background: #fff;
}
.rh-cal-mini-submit {
    padding: 0.58rem 1rem;
    border-radius: 999px;
    border: 0;
    font-size: 0.8rem;
    font-weight: 800;
    background: linear-gradient(135deg, #6d4aff 0%, #5835f5 100%);
    color: #fff;
    cursor: pointer;
}
.rh-cal-mini-submit:hover { opacity: 0.95; }

.rh-cal-export-link {
    display: inline-flex;
    align-items: center;
    padding: 0.58rem 0.95rem;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 800;
    color: #5a3ff6;
    border: 1px solid rgba(109, 74, 255, 0.32);
    background: rgba(109, 74, 255, 0.09);
    text-decoration: none;
    white-space: nowrap;
}
.rh-cal-export-link:hover {
    background: rgba(109, 74, 255, 0.16);
}

.rh-cal-layout {
    display: grid;
    gap: 1.25rem;
}
@media (min-width: 1100px) {
    .rh-cal-layout {
        grid-template-columns: minmax(0, 1fr) 320px;
        align-items: start;
    }
}

@media (min-width: 1280px) {
    .rh-cal-layout {
        grid-template-columns: minmax(0, 1fr) 360px;
        gap: 1.4rem;
    }

    .rh-cal-mini-filter {
        padding: 1rem 1.1rem;
        gap: 0.75rem;
    }

    .rh-cal-cell {
        min-height: 5rem;
        padding: 0.55rem 0.45rem;
    }

    .rh-cal-day-panel {
        padding: 1.2rem 1.25rem;
    }

    .rh-cal-card {
        padding: 1.2rem 1.25rem;
    }
}

@media (min-width: 1560px) {
    .rh-cal-layout {
        grid-template-columns: minmax(0, 1fr) 390px;
    }

    .rh-cal-grid {
        gap: 0.45rem;
    }

    .rh-cal-cell {
        min-height: 5.35rem;
    }
}
.rh-cal-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
}
.rh-cal-heading {
    font-size: 1.15rem;
    font-weight: 800;
    color: #1f2433;
    letter-spacing: -0.03em;
}
.rh-cal-sub {
    margin-top: 0.35rem;
    font-size: 0.82rem;
    color: #63708a;
    max-width: 40rem;
}
.rh-cal-nav {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
}
.rh-cal-nav-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 999px;
    border: 1px solid rgba(213, 219, 238, 0.92);
    background: #fff;
    color: #58637d;
}
.rh-cal-nav-btn:hover {
    border-color: rgba(109, 74, 255, 0.35);
    color: #5835f5;
}
.rh-cal-nav-label {
    font-weight: 800;
    color: #1f2433;
    min-width: 9rem;
    text-align: center;
    font-size: 0.95rem;
}
.rh-cal-today {
    margin-left: 0.25rem;
    padding: 0.5rem 0.9rem;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 800;
    background: rgba(109, 74, 255, 0.14);
    color: #5835f5;
}
.rh-cal-today:hover {
    background: rgba(109, 74, 255, 0.22);
}
.rh-cal-add-schedule {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.5rem 0.9rem;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 800;
    color: #fff;
    background: linear-gradient(135deg, #187b74 0%, #0f766e 100%);
    box-shadow: 0 14px 30px -22px rgba(15, 118, 110, 0.75);
    text-decoration: none;
    white-space: nowrap;
}
.rh-cal-add-schedule:hover {
    color: #fff;
    background: linear-gradient(135deg, #166b64 0%, #0f5c56 100%);
}
.rh-cal-weekdays {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 0.35rem;
    margin-bottom: 0.35rem;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #8c97b2;
    text-align: center;
}
.rh-cal-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 0.35rem;
}
.rh-cal-cell {
    position: relative;
    min-height: 4.5rem;
    padding: 0.45rem 0.35rem;
    border-radius: 1rem;
    border: 1px solid rgba(213, 219, 238, 0.92);
    background: rgba(255, 255, 255, 0.92);
    text-decoration: none;
    color: inherit;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.25rem;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.rh-cal-cell:hover {
    border-color: rgba(109, 74, 255, 0.35);
    box-shadow: 0 8px 24px -18px rgba(15, 23, 42, 0.25);
}
.rh-cal-cell.is-muted {
    opacity: 0.38;
}
.rh-cal-cell.is-today {
    border-color: rgba(63, 99, 255, 0.44);
    background: rgba(63, 99, 255, 0.08);
}
.rh-cal-cell.is-selected {
    border-color: rgba(109, 74, 255, 0.58);
    box-shadow: 0 0 0 2px rgba(109, 74, 255, 0.2);
}
.rh-cal-cell-num {
    font-size: 0.88rem;
    font-weight: 800;
    color: #1f2433;
}
.rh-cal-dots {
    display: flex;
    flex-wrap: wrap;
    gap: 2px;
    align-items: center;
}
.rh-cal-dot {
    width: 6px;
    height: 6px;
    border-radius: 999px;
    display: inline-block;
}
.rh-cal-dot.is-task { background: #3f63ff; }
.rh-cal-dot.is-self { background: #6d4aff; }
.rh-cal-more {
    font-size: 0.62rem;
    font-weight: 800;
    color: #64748b;
    margin-left: 2px;
}
.rh-cal-day-panel {
    margin-top: 1.25rem;
    padding: 1.1rem 1.15rem;
    border-radius: 1.25rem;
    border: 1px solid rgba(213, 219, 238, 0.92);
    background: rgba(248, 250, 252, 0.96);
}
.rh-cal-day-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}
.rh-cal-day-title {
    font-size: 1rem;
    font-weight: 800;
    color: #1f2433;
}
.rh-cal-day-badge {
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #63708a;
}
.rh-cal-empty {
    font-size: 0.88rem;
    color: #64748b;
}
.rh-cal-day-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
}
.rh-cal-day-item {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: 0.65rem;
    padding: 0.65rem 0.75rem;
    border-radius: 1rem;
    background: #fff;
    border: 1px solid rgba(213, 219, 238, 0.92);
}
.rh-cal-day-item-type {
    font-size: 0.65rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    padding: 0.25rem 0.5rem;
    border-radius: 999px;
}
.rh-cal-day-item-type.is-task { background: rgba(63, 99, 255, 0.13); color: #3050cf; }
.rh-cal-day-item-type.is-self { background: rgba(109, 74, 255, 0.14); color: #5a3ff6; }
.rh-cal-day-item-body { flex: 1; min-width: 0; }
.rh-cal-day-item-title { font-weight: 700; color: #1f2433; font-size: 0.9rem; }
.rh-cal-day-item-meta {
    margin-top: 0.25rem;
    font-size: 0.78rem;
    color: #64748b;
    display: flex;
    flex-wrap: wrap;
    gap: 0.65rem;
}
.rh-cal-day-link {
    font-size: 0.78rem;
    font-weight: 800;
    color: #5835f5;
    white-space: nowrap;
}
.rh-cal-aside { display: flex; flex-direction: column; gap: 1rem; }
.rh-cal-card {
    border-radius: 1.25rem;
    border: 1px solid rgba(213, 219, 238, 0.92);
    background: linear-gradient(180deg, rgba(255,255,255,0.99), rgba(247,250,255,0.98));
    padding: 1.1rem 1.15rem;
    box-shadow: 0 18px 40px -32px rgba(15, 23, 42, 0.35);
}
.rh-cal-card-kicker {
    font-size: 0.68rem;
    font-weight: 800;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: #8c97b2;
}
.rh-cal-card-title {
    margin-top: 0.25rem;
    font-size: 1.02rem;
    font-weight: 800;
    color: #1f2433;
}
.rh-cal-card-copy {
    margin-top: 0.35rem;
    font-size: 0.82rem;
    color: #63708a;
}
.rh-week-strip {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 0.35rem;
    margin-top: 0.75rem;
}
.rh-week-col {
    text-align: center;
    padding: 0.45rem 0.2rem;
    border-radius: 0.75rem;
    background: rgba(248, 250, 252, 0.96);
    border: 1px solid rgba(213, 219, 238, 0.92);
}
.rh-week-col.is-today {
    border-color: rgba(63, 99, 255, 0.44);
    background: rgba(63, 99, 255, 0.08);
}
.rh-week-dow { display: block; font-size: 0.62rem; font-weight: 800; color: #8c97b2; text-transform: uppercase; }
.rh-week-num { display: block; font-size: 1rem; font-weight: 800; color: #1f2433; }
.rh-week-count { display: block; font-size: 0.68rem; font-weight: 700; color: #63708a; }
.rh-week-list { list-style: none; margin: 0.85rem 0 0; padding: 0; }
.rh-week-li {
    display: grid;
    grid-template-columns: 4rem minmax(0,1fr) auto;
    gap: 0.35rem;
    align-items: baseline;
    font-size: 0.8rem;
    padding: 0.4rem 0;
    border-top: 1px solid rgba(226, 232, 240, 0.85);
}
.rh-week-li-empty { border: 0; color: #94a3b8; font-size: 0.85rem; }
.rh-week-li-date { font-weight: 700; color: #64748b; }
.rh-week-li-title { color: #1f2433; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rh-week-li-src { font-size: 0.65rem; font-weight: 800; text-transform: uppercase; }
.rh-week-li-src.is-task { color: #3f63ff; }
.rh-week-li-src.is-self { color: #5a3ff6; }
.rh-cal-ghost-link {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    margin-top: 0.75rem;
    font-size: 0.8rem;
    font-weight: 800;
    color: #5835f5;
}
.rh-alarm-card { background: linear-gradient(180deg, rgba(109,74,255,0.08), rgba(255,255,255,0.98)); }
.rh-alarm-stat {
    margin-top: 0.5rem;
    font-size: 0.88rem;
    color: #475569;
}
.rh-alarm-stat strong {
    font-size: 1.45rem;
    font-weight: 800;
    color: #1f2433;
    margin-right: 0.35rem;
}
.rh-alarm-list { list-style: none; margin: 0.65rem 0 0; padding: 0; }
.rh-alarm-li { border-top: 1px solid rgba(226, 232, 240, 0.9); }
.rh-alarm-li-link {
    display: block;
    padding: 0.65rem 0;
    text-decoration: none;
    color: inherit;
}
.rh-alarm-li-link:hover .rh-alarm-li-title { color: #5835f5; }
.rh-alarm-li-title { font-weight: 700; font-size: 0.88rem; color: #1f2433; }
.rh-alarm-li-time { display: block; font-size: 0.78rem; color: #64748b; margin-top: 0.2rem; }
.rh-alarm-li-sub { display: block; font-size: 0.72rem; color: #94a3b8; margin-top: 0.15rem; }
</style>
