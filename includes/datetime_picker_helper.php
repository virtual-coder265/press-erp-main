<?php
declare(strict_types=1);

/**
 * Normalizes stored or partial date strings for the shared Flatpickr field (assets/js/datetime-picker.js).
 */
function press_datetime_picker_normalize_value(?string $value, string $mode): string
{
    if ($value === null) {
        return '';
    }
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if ($mode === 'datetime') {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value)) {
            return substr($value, 0, 16);
        }
    } else {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return '';
    }

    if ($mode === 'datetime') {
        return date('Y-m-d\TH:i', $ts);
    }

    return date('Y-m-d', $ts);
}

/**
 * Renders a text input wired to PressErpDateTimePicker (Flatpickr). The visible field uses a friendly format;
 * the posted value stays ISO-style: Y-m-d or Y-m-d\TH:i.
 *
 * @param array{
 *     name: string,
 *     id?: ?string,
 *     value?: ?string,
 *     mode?: 'date'|'datetime',
 *     required?: bool,
 *     readonly?: bool,
 *     disabled?: bool,
 *     placeholder?: ?string,
 *     class?: string,
 *     min_date?: ?string,
 *     max_date?: ?string,
 *     disable_past?: bool,
 *     autocomplete?: string
 * } $opts
 */
function press_datetime_picker_field(array $opts): string
{
    $name = trim((string) ($opts['name'] ?? ''));
    if ($name === '') {
        return '';
    }

    $mode = (($opts['mode'] ?? 'date') === 'datetime') ? 'datetime' : 'date';
    $rawValue = isset($opts['value']) ? (string) $opts['value'] : '';
    $value = press_datetime_picker_normalize_value($rawValue, $mode);

    $id = isset($opts['id']) && is_string($opts['id']) ? trim($opts['id']) : '';
    $required = !empty($opts['required']);
    $readonly = !empty($opts['readonly']);
    $disabled = !empty($opts['disabled']);
    $enablePicker = !$readonly && !$disabled;

    $placeholder = isset($opts['placeholder']) ? trim((string) $opts['placeholder']) : '';
    if ($placeholder === '') {
        $placeholder = $mode === 'datetime' ? 'Select date and time' : 'Select date';
    }

    $extraClass = trim((string) ($opts['class'] ?? ''));
    $classes = trim('press-dt-input ' . $extraClass);

    $autocomplete = isset($opts['autocomplete']) ? trim((string) $opts['autocomplete']) : 'off';
    if ($autocomplete === '') {
        $autocomplete = 'off';
    }

    $parts = [];
    $parts[] = 'type="text"';
    $parts[] = 'name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"';
    if ($id !== '') {
        $parts[] = 'id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"';
    }
    $parts[] = 'value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
    $parts[] = 'placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '"';
    $parts[] = 'class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '"';
    $parts[] = 'autocomplete="' . htmlspecialchars($autocomplete, ENT_QUOTES, 'UTF-8') . '"';

    if ($required) {
        $parts[] = 'required';
    }
    if ($readonly) {
        $parts[] = 'readonly';
    }
    if ($disabled) {
        $parts[] = 'disabled';
    }

    if ($enablePicker) {
        $parts[] = 'data-press-datepicker="1"';
        $parts[] = 'data-press-mode="' . htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') . '"';
        if (!empty($opts['disable_past'])) {
            $parts[] = 'data-press-disable-past="1"';
        }

        $minDate = trim((string) ($opts['min_date'] ?? ''));
        if ($minDate !== '') {
            $norm = press_datetime_picker_normalize_value($minDate, 'date');
            if ($norm !== '') {
                $parts[] = 'data-min-date="' . htmlspecialchars($norm, ENT_QUOTES, 'UTF-8') . '"';
            }
        }
        $maxDate = trim((string) ($opts['max_date'] ?? ''));
        if ($maxDate !== '') {
            $norm = press_datetime_picker_normalize_value($maxDate, 'date');
            if ($norm !== '') {
                $parts[] = 'data-max-date="' . htmlspecialchars($norm, ENT_QUOTES, 'UTF-8') . '"';
            }
        }
    }

    return '<input ' . implode(' ', $parts) . '>';
}

/**
 * Native browser date/time input (no Flatpickr). Posts Y-m-d or Y-m-d\TH:i.
 *
 * @param array{
 *     name: string,
 *     id?: ?string,
 *     value?: ?string,
 *     mode?: 'date'|'datetime',
 *     required?: bool,
 *     readonly?: bool,
 *     disabled?: bool,
 *     class?: string,
 *     min_date?: ?string,
 *     max_date?: ?string,
 *     disable_past?: bool,
 *     autocomplete?: string,
 *     native_range?: 'start'|'end'|null
 * } $opts
 */
function press_native_datetime_field(array $opts): string
{
    $name = trim((string) ($opts['name'] ?? ''));
    if ($name === '') {
        return '';
    }

    $mode = (($opts['mode'] ?? 'date') === 'datetime') ? 'datetime' : 'date';
    $inputType = $mode === 'datetime' ? 'datetime-local' : 'date';
    $rawValue = isset($opts['value']) ? (string) $opts['value'] : '';
    $value = press_datetime_picker_normalize_value($rawValue, $mode);

    $id = isset($opts['id']) && is_string($opts['id']) ? trim($opts['id']) : '';
    $required = !empty($opts['required']);
    $readonly = !empty($opts['readonly']);
    $disabled = !empty($opts['disabled']);
    $enableConstraints = !$readonly && !$disabled;

    $extraClass = trim((string) ($opts['class'] ?? ''));
    $classes = trim('press-native-dt-input ' . $extraClass);

    $autocomplete = isset($opts['autocomplete']) ? trim((string) $opts['autocomplete']) : 'off';
    if ($autocomplete === '') {
        $autocomplete = 'off';
    }

    $parts = [];
    $parts[] = 'type="' . htmlspecialchars($inputType, ENT_QUOTES, 'UTF-8') . '"';
    $parts[] = 'name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"';
    if ($id !== '') {
        $parts[] = 'id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"';
    }
    if ($value !== '') {
        $parts[] = 'value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
    }
    $parts[] = 'class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '"';
    $parts[] = 'autocomplete="' . htmlspecialchars($autocomplete, ENT_QUOTES, 'UTF-8') . '"';

    if ($required) {
        $parts[] = 'required';
    }
    if ($readonly) {
        $parts[] = 'readonly';
    }
    if ($disabled) {
        $parts[] = 'disabled';
    }

    $rangeRole = $opts['native_range'] ?? null;
    if ($rangeRole === 'start') {
        $parts[] = 'data-native-date-start';
    } elseif ($rangeRole === 'end') {
        $parts[] = 'data-native-date-end';
    }

    if ($enableConstraints) {
        $minAttr = '';
        $maxAttr = '';

        if (!empty($opts['disable_past'])) {
            $minAttr = $mode === 'datetime' ? date('Y-m-d\TH:i') : date('Y-m-d');
        }

        $minDate = trim((string) ($opts['min_date'] ?? ''));
        if ($minDate !== '') {
            $norm = press_datetime_picker_normalize_value($minDate, $mode);
            if ($norm !== '') {
                $minAttr = $norm;
            }
        }
        $maxDate = trim((string) ($opts['max_date'] ?? ''));
        if ($maxDate !== '') {
            $norm = press_datetime_picker_normalize_value($maxDate, $mode);
            if ($norm !== '') {
                $maxAttr = $norm;
            }
        }

        if ($minAttr !== '') {
            $parts[] = 'min="' . htmlspecialchars($minAttr, ENT_QUOTES, 'UTF-8') . '"';
        }
        if ($maxAttr !== '') {
            $parts[] = 'max="' . htmlspecialchars($maxAttr, ENT_QUOTES, 'UTF-8') . '"';
        }
    }

    return '<input ' . implode(' ', $parts) . '>';
}
