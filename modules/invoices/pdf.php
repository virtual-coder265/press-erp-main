<?php
/**
 * Backwards-compatibility shim.
 *
 * The dedicated download endpoint is `modules/invoices/download`. This
 * file is kept so any external bookmarks or saved links that still call
 * `.../invoices/pdf?id=X` continue to work — it forwards straight to the
 * download endpoint.
 */

require_once __DIR__ . '/download.php';
