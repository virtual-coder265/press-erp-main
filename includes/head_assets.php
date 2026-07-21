<?php
$includeAppShellCss = isset($includeAppShellCss) ? (bool) $includeAppShellCss : false;
$includeJquery = isset($includeJquery) ? (bool) $includeJquery : true;
$preloadMaterialIcons = isset($preloadMaterialIcons) ? (bool) $preloadMaterialIcons : true;
?>
<?php if ($preloadMaterialIcons): ?>
<link rel="preload" href="<?php echo asset('vendor/fonts/MaterialIcons-Regular.ttf'); ?>" as="font" type="font/ttf" crossorigin>
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="<?php echo asset('vendor/css/tailwind-2.2.19.min.css'); ?>" rel="stylesheet">
<link href="<?php echo asset('css/vendor-fonts.css'); ?>" rel="stylesheet">
<?php
$pressErpSkipGlobalDateTimePicker = !empty($pressErpSkipGlobalDateTimePicker);
?>
<?php $nativeDtCssPath = ROOT_PATH . 'assets/css/native-datetime-fields.css'; ?>
<?php $nativeDtCssV = file_exists($nativeDtCssPath) ? (string) filemtime($nativeDtCssPath) : (string) time(); ?>
<link href="<?php echo asset('css/native-datetime-fields.css') . '?v=' . rawurlencode($nativeDtCssV); ?>" rel="stylesheet">
<?php if (!$pressErpSkipGlobalDateTimePicker): ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
<?php $pressDtCssPath = ROOT_PATH . 'assets/css/press-datetime-picker.css'; ?>
<?php $pressDtCssV = file_exists($pressDtCssPath) ? (string) filemtime($pressDtCssPath) : (string) time(); ?>
<link href="<?php echo asset('css/press-datetime-picker.css') . '?v=' . rawurlencode($pressDtCssV); ?>" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<?php endif; ?>
<link href="<?php echo asset('css/workspace-shell.css'); ?>" rel="stylesheet">
<?php
$globalLoaderCssPath = ROOT_PATH . 'assets/css/global-request-loader.css';
$globalLoaderCssV = file_exists($globalLoaderCssPath) ? (string) filemtime($globalLoaderCssPath) : (string) time();
?>
<link href="<?php echo asset('css/global-request-loader.css') . '?v=' . rawurlencode($globalLoaderCssV); ?>" rel="stylesheet">
<?php if ($includeAppShellCss): ?>
<?php $appShellCssVersion = file_exists(ROOT_PATH . 'assets/css/app-shell.css') ? (string) filemtime(ROOT_PATH . 'assets/css/app-shell.css') : (string) time(); ?>
<link href="<?php echo asset('css/app-shell.css') . '?v=' . rawurlencode($appShellCssVersion); ?>" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/lucide@0.460.0/dist/umd/lucide.min.js"></script>
<?php $appIconsJsVersion = file_exists(ROOT_PATH . 'assets/js/app-icons.js') ? (string) filemtime(ROOT_PATH . 'assets/js/app-icons.js') : (string) time(); ?>
<script src="<?php echo asset('js/app-icons.js') . '?v=' . rawurlencode($appIconsJsVersion); ?>"></script>
<?php endif; ?>
<?php if ($includeJquery): ?>
<script src="<?php echo asset('vendor/js/jquery-3.6.0.min.js'); ?>"></script>
<?php endif; ?>
<?php
$globalLoaderJsPath = ROOT_PATH . 'assets/js/global-request-loader.js';
$globalLoaderJsV = file_exists($globalLoaderJsPath) ? (string) filemtime($globalLoaderJsPath) : (string) time();
?>
<script src="<?php echo asset('js/global-request-loader.js') . '?v=' . rawurlencode($globalLoaderJsV); ?>"></script>
<script defer src="<?php echo asset('js/workspace-shell.js'); ?>"></script>
<?php
$voiceNoteJsPath = ROOT_PATH . 'assets/js/voice-note.js';
$voiceNoteJsV = file_exists($voiceNoteJsPath) ? (string) filemtime($voiceNoteJsPath) : (string) time();
?>
<script defer src="<?php echo asset('js/voice-note.js') . '?v=' . rawurlencode($voiceNoteJsV); ?>"></script>
