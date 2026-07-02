<?php
/**
 * Billing document layout (invoice / quote / receipt) — JSON in business_settings.billing_layout_json.
 */

if (!defined('BILLING_LAYOUT_VARIANT_IDS')) {
    define('BILLING_LAYOUT_VARIANT_IDS', ['executive', 'professional', 'minimal']);
}

if (!function_exists('billing_layout_default_variant_row')) {
    function billing_layout_default_variant_row() {
        return [
            'logo_position' => 'left',
            'show_payment_details' => true,
            'show_terms' => true,
            'show_signatures' => true,
            'show_footer' => true,
            'show_notes' => true,
            'show_tax_breakdown' => true,
            'header_style' => 'band',
        ];
    }
}

if (!function_exists('get_billing_layout_defaults')) {
    /**
     * @return array{v: int, invoice: array, quote: array, receipt: array}
     */
    function get_billing_layout_defaults() {
        $variants = [];
        foreach (BILLING_LAYOUT_VARIANT_IDS as $id) {
            $variants[$id] = billing_layout_default_variant_row();
        }
        $variants['executive']['header_style'] = 'band';
        $variants['professional']['header_style'] = 'classic';
        $variants['minimal']['header_style'] = 'minimal';

        $invoiceVariants = $variants;
        foreach (BILLING_LAYOUT_VARIANT_IDS as $id) {
            $invoiceVariants[$id]['show_signatures'] = false;
            $invoiceVariants[$id]['show_notes'] = false;
        }

        $receiptVariants = $variants;
        foreach (BILLING_LAYOUT_VARIANT_IDS as $id) {
            $receiptVariants[$id]['show_signatures'] = false;
            $receiptVariants[$id]['show_terms'] = false;
            $receiptVariants[$id]['show_notes'] = false;
            $receiptVariants[$id]['show_tax_breakdown'] = true;
        }

        return [
            'v' => 1,
            'invoice' => [
                'active_variant' => 'executive',
                'variants' => $invoiceVariants,
            ],
            'quote' => [
                'active_variant' => 'executive',
                'variants' => $variants,
            ],
            'receipt' => [
                'active_variant' => 'executive',
                'variants' => $receiptVariants,
            ],
        ];
    }
}

if (!function_exists('billing_layout_merge_variant')) {
    function billing_layout_merge_variant(array $base, $incoming) {
        if (!is_array($incoming)) {
            return $base;
        }
        $logo = (string) ($incoming['logo_position'] ?? $base['logo_position']);
        if (!in_array($logo, ['left', 'right', 'center', 'hidden'], true)) {
            $logo = $base['logo_position'];
        }
        $style = (string) ($incoming['header_style'] ?? $base['header_style']);
        if (!in_array($style, ['band', 'classic', 'minimal'], true)) {
            $style = $base['header_style'];
        }
        $bool = function ($key) use ($incoming, $base) {
            if (!array_key_exists($key, $incoming)) {
                return (bool) $base[$key];
            }
            $v = $incoming[$key];
            if (is_bool($v)) {
                return $v;
            }
            $s = strtolower(trim((string) $v));
            return $s === '1' || $s === 'true' || $s === 'on' || $s === 'yes';
        };

        return [
            'logo_position' => $logo,
            'show_payment_details' => $bool('show_payment_details'),
            'show_terms' => $bool('show_terms'),
            'show_signatures' => $bool('show_signatures'),
            'show_footer' => $bool('show_footer'),
            'show_notes' => $bool('show_notes'),
            'show_tax_breakdown' => $bool('show_tax_breakdown'),
            'header_style' => $style,
        ];
    }
}

if (!function_exists('get_billing_layout_config')) {
    function get_billing_layout_config() {
        $defaults = get_billing_layout_defaults();
        if (!function_exists('get_setting')) {
            return $defaults;
        }
        $raw = (string) get_setting('billing_layout_json', '');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || ($decoded['v'] ?? 0) < 1) {
            return $defaults;
        }
        foreach (['invoice', 'quote', 'receipt'] as $doc) {
            if (!isset($decoded[$doc]) || !is_array($decoded[$doc])) {
                continue;
            }
            $active = (string) ($decoded[$doc]['active_variant'] ?? $defaults[$doc]['active_variant']);
            if (!in_array($active, BILLING_LAYOUT_VARIANT_IDS, true)) {
                $active = $defaults[$doc]['active_variant'];
            }
            $defaults[$doc]['active_variant'] = $active;
            $incomingVars = $decoded[$doc]['variants'] ?? [];
            if (!is_array($incomingVars)) {
                $incomingVars = [];
            }
            foreach (BILLING_LAYOUT_VARIANT_IDS as $vid) {
                $defaults[$doc]['variants'][$vid] = billing_layout_merge_variant(
                    $defaults[$doc]['variants'][$vid],
                    $incomingVars[$vid] ?? []
                );
            }
        }
        return $defaults;
    }
}

if (!function_exists('get_merged_billing_layout')) {
    /**
     * Flat layout flags for the active variant of a document type.
     *
     * @param string $docType invoice|quote|receipt
     * @param string|null $variantOverride When set (e.g. billing PDF previews), use this variant id instead of the saved active variant.
     * @return array<string, mixed>
     */
    function get_merged_billing_layout($docType, $variantOverride = null) {
        $docType = in_array($docType, ['invoice', 'quote', 'receipt'], true) ? $docType : 'invoice';
        $cfg = get_billing_layout_config();
        $block = $cfg[$docType];
        $active = (string) ($block['active_variant'] ?? 'executive');
        if ($variantOverride !== null && $variantOverride !== '') {
            $ov = (string) $variantOverride;
            if (in_array($ov, BILLING_LAYOUT_VARIANT_IDS, true)) {
                $active = $ov;
            }
        }
        if (!in_array($active, BILLING_LAYOUT_VARIANT_IDS, true)) {
            $active = 'executive';
        }
        $row = $block['variants'][$active] ?? billing_layout_default_variant_row();
        return array_merge($row, ['_active_variant' => $active, '_doc' => $docType]);
    }
}

if (!function_exists('normalize_billing_layout_from_post')) {
    /**
     * @param array $postSlice $_POST['billing_layout'] or equivalent
     */
    function normalize_billing_layout_from_post($postSlice) {
        $out = get_billing_layout_defaults();
        if (!is_array($postSlice)) {
            return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        foreach (['invoice', 'quote', 'receipt'] as $doc) {
            if (!isset($postSlice[$doc]) || !is_array($postSlice[$doc])) {
                continue;
            }
            $active = (string) ($postSlice[$doc]['active_variant'] ?? $out[$doc]['active_variant']);
            if (!in_array($active, BILLING_LAYOUT_VARIANT_IDS, true)) {
                $active = 'executive';
            }
            $out[$doc]['active_variant'] = $active;
            foreach (BILLING_LAYOUT_VARIANT_IDS as $vid) {
                $row = $postSlice[$doc]['variants'][$vid] ?? [];
                if (!is_array($row)) {
                    $row = [];
                }
                $out[$doc]['variants'][$vid] = billing_layout_merge_variant(
                    $out[$doc]['variants'][$vid],
                    $row
                );
            }
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
