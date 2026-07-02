<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/reminder_helper.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0 || !reminder_module_ready($pdo)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Reminder module not available.';
    exit;
}

$start = trim((string) ($_GET['start'] ?? ''));
$end = trim((string) ($_GET['end'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
    $start = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    $end = date('Y-m-t', strtotime($start));
}

if (strtotime($start) > strtotime($end)) {
    $tmp = $start;
    $start = $end;
    $end = $tmp;
}

$maxEnd = date('Y-m-d', strtotime($start . ' +366 days'));
if (strtotime($end) > strtotime($maxEnd)) {
    $end = $maxEnd;
}

$source = trim((string) ($_GET['source'] ?? 'all'));
if (!in_array($source, ['all', 'self', 'task_assignment'], true)) {
    $source = 'all';
}

$search = trim((string) ($_GET['search'] ?? ''));

$rows = fetch_reminders_for_calendar_range($pdo, $userId, $start, $end, [
    'status' => 'active',
    'source' => $source,
    'search' => $search,
]);

$host = parse_url(BASE_URL, PHP_URL_HOST);
if ($host === false || $host === null || $host === '') {
    $host = 'localhost';
}

/**
 * @return list<string>
 */
function reminder_ics_fold(string $line): array
{
    $line = str_replace(["\r\n", "\r", "\n"], [' ', ' ', ' '], $line);
    if (strlen($line) <= 75) {
        return [$line];
    }
    $out = [];
    $out[] = substr($line, 0, 75);
    $rest = substr($line, 75);
    while ($rest !== '') {
        $out[] = ' ' . substr($rest, 0, 74);
        $rest = substr($rest, 74);
    }

    return $out;
}

function reminder_ics_escape_text(string $s): string
{
    $s = str_replace('\\', '\\\\', $s);
    $s = str_replace(["\r", "\n"], ['', '\\n'], $s);
    $s = str_replace(';', '\\;', $s);
    $s = str_replace(',', '\\,', $s);

    return $s;
}

$lines = [];
$lines[] = 'BEGIN:VCALENDAR';
$lines[] = 'VERSION:2.0';
$lines[] = 'PRODID:-//Press ERP//Reminder Hub//EN';
$lines[] = 'CALSCALE:GREGORIAN';
$lines[] = 'METHOD:PUBLISH';
$lines[] = 'X-WR-CALNAME:Reminder Hub';
$lines[] = 'X-WR-TIMEZONE:' . reminder_ics_escape_text(date_default_timezone_get());

$dtstamp = gmdate('Ymd\THis\Z');

foreach ($rows as $r) {
    $id = (int) ($r['id'] ?? 0);
    if ($id <= 0) {
        continue;
    }

    $title = (string) ($r['title'] ?? 'Reminder');
    $note = trim((string) ($r['note'] ?? ''));
    $remindAt = $r['remind_at'] ?? null;
    $dueOn = $r['due_on'] ?? null;

    $descParts = [];
    if ($note !== '') {
        $descParts[] = $note;
    }
    if (!empty($r['project_name'])) {
        $descParts[] = 'Project: ' . $r['project_name'];
    }
    $descParts[] = (($r['source'] ?? '') === 'task_assignment') ? 'Source: Task-linked' : 'Source: Personal';
    $description = implode("\n", $descParts);

    $href = !empty($r['task_id'])
        ? BASE_URL . 'modules/tasks/view?id=' . (int) $r['task_id']
        : BASE_URL . 'modules/reminders/index?detail=' . $id;

    $dtLines = [];
    if (!empty($remindAt)) {
        $ts = strtotime((string) $remindAt);
        if ($ts === false) {
            continue;
        }
        $dtLines[] = 'DTSTART:' . date('Ymd\THis', $ts);
    } elseif (!empty($dueOn)) {
        $parsed = strtotime((string) $dueOn);
        if ($parsed === false) {
            continue;
        }
        $day = date('Y-m-d', $parsed);
        $dtLines[] = 'DTSTART;VALUE=DATE:' . date('Ymd', strtotime($day));
        $dtLines[] = 'DTEND;VALUE=DATE:' . date('Ymd', strtotime($day . ' +1 day'));
    } else {
        continue;
    }

    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:press-erp-reminder-' . $id . '@' . $host;
    $lines[] = 'DTSTAMP:' . $dtstamp;
    foreach ($dtLines as $dl) {
        $lines[] = $dl;
    }

    foreach (reminder_ics_fold('SUMMARY:' . reminder_ics_escape_text($title)) as $folded) {
        $lines[] = $folded;
    }
    if ($description !== '') {
        foreach (reminder_ics_fold('DESCRIPTION:' . reminder_ics_escape_text($description)) as $folded) {
            $lines[] = $folded;
        }
    }
    foreach (reminder_ics_fold('URL:' . reminder_ics_escape_text($href)) as $folded) {
        $lines[] = $folded;
    }

    $lines[] = 'END:VEVENT';
}

$lines[] = 'END:VCALENDAR';

$body = implode("\r\n", $lines) . "\r\n";

$filename = 'reminder-hub-' . $start . '-to-' . $end . '.ics';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

echo $body;
