<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/settings_helper.php';
require_once __DIR__ . '/../../includes/billing_layout_helper.php';
require_once __DIR__ . '/../../includes/upload_helper.php';

if (($_SESSION['role'] ?? '') !== 'System Admin' && !hasPermission('manage_settings')) {
    die('Access Denied.');
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_FILES['business_logo']) && $_FILES['business_logo']['error'] === UPLOAD_ERR_OK) {
            $logoPath = store_validated_uploaded_file(
                $_FILES['business_logo'],
                'business_logo',
                __DIR__ . '/../../assets/uploads/logos/',
                '/assets/uploads/logos',
                'logo-'
            );

            update_setting('business_logo', $logoPath, 'business');
        }

        save_application_settings([
            'business_name' => $_POST['business_name'] ?? '',
            'business_address' => $_POST['business_address'] ?? '',
            'business_tax_id' => $_POST['business_tax_id'] ?? '',
            'business_registration_number' => $_POST['business_registration_number'] ?? '',
            'business_phone' => $_POST['business_phone'] ?? '',
            'business_email' => $_POST['business_email'] ?? '',
            'business_website' => $_POST['business_website'] ?? '',
            'invoice_terms' => $_POST['invoice_terms'] ?? '',
            'invoice_footer' => $_POST['invoice_footer'] ?? '',
            'invoice_prefix' => $_POST['invoice_prefix'] ?? 'INV',
            'invoice_due_days' => $_POST['invoice_due_days'] ?? 30,
            'signature1_title' => $_POST['signature1_title'] ?? 'Authorized Signature',
            'signature2_title' => $_POST['signature2_title'] ?? 'Customer Signature',
            'signature3_title' => $_POST['signature3_title'] ?? 'Date',
            'bank_name' => $_POST['bank_name'] ?? '',
            'account_number' => $_POST['account_number'] ?? '',
            'bank_branch' => $_POST['bank_branch'] ?? '',
            'swift_code' => $_POST['swift_code'] ?? '',
            'iban' => $_POST['iban'] ?? '',
            'billing_layout_json' => normalize_billing_layout_from_post($_POST['billing_layout'] ?? []),
        ]);

        $success = 'Business settings updated successfully.';
    } catch (Exception $e) {
        $error = 'Error updating settings: ' . $e->getMessage();
    }
}

$flatSettings = array_merge(
    get_settings_by_group('business'),
    get_settings_by_group('contact'),
    get_settings_by_group('invoice'),
    get_settings_by_group('signature'),
    get_settings_by_group('bank')
);

$billingLayout = get_billing_layout_config();
$variantMeta = [
    'executive' => 'Executive',
    'professional' => 'Professional',
    'minimal' => 'Minimal',
];

include '../../includes/header.php';
?>

<div class="container mx-auto px-4 py-8 max-w-6xl">
    <div class="flex flex-col gap-2 mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Business Profile</h1>
        <p class="text-sm text-gray-500">Control the organisation identity, invoice defaults, and banking details used across documents and customer communications.</p>
    </div>

    <?php if ($success): ?>
        <div class="bg-green-100 border border-green-300 text-green-800 px-4 py-3 rounded-lg mb-4">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="bg-red-100 border border-red-300 text-red-800 px-4 py-3 rounded-lg mb-4">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form action="business" method="POST" enctype="multipart/form-data" class="space-y-8">
        <div class="bg-white shadow-md rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-4 text-gray-800 border-b pb-2">Organisation Identity</h2>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Business Logo</label>
                    <div class="flex items-center gap-4">
                        <span class="inline-flex h-24 w-24 rounded-2xl overflow-hidden bg-gray-100 border border-gray-200 items-center justify-center">
                            <?php if (!empty($flatSettings['business_logo'])): ?>
                                <img src="<?php echo htmlspecialchars($flatSettings['business_logo']); ?>" alt="Business Logo" class="h-full w-full object-contain">
                            <?php else: ?>
                                <i class="material-icons text-4xl text-gray-300">apartment</i>
                            <?php endif; ?>
                        </span>
                        <label class="inline-flex">
                            <span class="px-4 py-2 bg-white text-sm font-medium text-gray-700 border border-gray-300 rounded-lg shadow-sm hover:bg-gray-50 cursor-pointer">
                                Upload Logo
                                <input type="file" name="business_logo" class="hidden" accept=".jpg,.jpeg,.png,.gif,.webp">
                            </span>
                        </label>
                    </div>
                </div>

                <div class="lg:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="business_name" class="block text-sm font-medium text-gray-700">Business Name</label>
                        <input type="text" id="business_name" name="business_name" value="<?php echo htmlspecialchars((string) ($flatSettings['business_name'] ?? '')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                    </div>

                    <div>
                        <label for="business_registration_number" class="block text-sm font-medium text-gray-700">Registration Number</label>
                        <input type="text" id="business_registration_number" name="business_registration_number" value="<?php echo htmlspecialchars((string) ($flatSettings['business_registration_number'] ?? '')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                    </div>

                    <div>
                        <label for="business_tax_id" class="block text-sm font-medium text-gray-700">Tax ID</label>
                        <input type="text" id="business_tax_id" name="business_tax_id" value="<?php echo htmlspecialchars((string) ($flatSettings['business_tax_id'] ?? '')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                    </div>

                    <div>
                        <label for="business_website" class="block text-sm font-medium text-gray-700">Website</label>
                        <input type="url" id="business_website" name="business_website" value="<?php echo htmlspecialchars((string) ($flatSettings['business_website'] ?? '')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                    </div>
                </div>

                <div class="lg:col-span-3">
                    <label for="business_address" class="block text-sm font-medium text-gray-700">Business Address</label>
                    <textarea id="business_address" name="business_address" rows="3" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2"><?php echo htmlspecialchars((string) ($flatSettings['business_address'] ?? '')); ?></textarea>
                </div>
            </div>
        </div>

        <div class="bg-white shadow-md rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-4 text-gray-800 border-b pb-2">Contact Channels</h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="business_phone" class="block text-sm font-medium text-gray-700">Primary Phone</label>
                    <input type="text" id="business_phone" name="business_phone" value="<?php echo htmlspecialchars((string) ($flatSettings['business_phone'] ?? '')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>

                <div>
                    <label for="business_email" class="block text-sm font-medium text-gray-700">Primary Email</label>
                    <input type="email" id="business_email" name="business_email" value="<?php echo htmlspecialchars((string) ($flatSettings['business_email'] ?? '')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>

                <div>
                    <label for="invoice_prefix" class="block text-sm font-medium text-gray-700">Invoice Prefix</label>
                    <input type="text" id="invoice_prefix" name="invoice_prefix" value="<?php echo htmlspecialchars((string) ($flatSettings['invoice_prefix'] ?? 'INV')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>
            </div>
        </div>

        <div class="bg-white shadow-md rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-4 text-gray-800 border-b pb-2">Invoice Defaults</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="invoice_due_days" class="block text-sm font-medium text-gray-700">Default Due Days</label>
                    <input type="number" min="0" id="invoice_due_days" name="invoice_due_days" value="<?php echo htmlspecialchars((string) ($flatSettings['invoice_due_days'] ?? 30)); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>

                <div>
                    <label for="invoice_footer" class="block text-sm font-medium text-gray-700">Invoice Footer</label>
                    <input type="text" id="invoice_footer" name="invoice_footer" value="<?php echo htmlspecialchars((string) ($flatSettings['invoice_footer'] ?? 'Thank you for your business!')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>

                <div class="md:col-span-2">
                    <label for="invoice_terms" class="block text-sm font-medium text-gray-700">Default Terms and Conditions</label>
                    <textarea id="invoice_terms" name="invoice_terms" rows="4" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2"><?php echo htmlspecialchars((string) ($flatSettings['invoice_terms'] ?? '')); ?></textarea>
                </div>
            </div>

            <h3 class="text-sm font-semibold text-gray-700 mt-6 mb-3">Signature Labels</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="signature1_title" class="block text-sm font-medium text-gray-700">Signature 1</label>
                    <input type="text" id="signature1_title" name="signature1_title" value="<?php echo htmlspecialchars((string) ($flatSettings['signature1_title'] ?? 'Authorized Signature')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>

                <div>
                    <label for="signature2_title" class="block text-sm font-medium text-gray-700">Signature 2</label>
                    <input type="text" id="signature2_title" name="signature2_title" value="<?php echo htmlspecialchars((string) ($flatSettings['signature2_title'] ?? 'Customer Signature')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>

                <div>
                    <label for="signature3_title" class="block text-sm font-medium text-gray-700">Signature 3</label>
                    <input type="text" id="signature3_title" name="signature3_title" value="<?php echo htmlspecialchars((string) ($flatSettings['signature3_title'] ?? 'Date')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>
            </div>
        </div>

        <div class="bg-white shadow-md rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-4 text-gray-800 border-b pb-2">Banking Details</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="bank_name" class="block text-sm font-medium text-gray-700">Bank Name</label>
                    <input type="text" id="bank_name" name="bank_name" value="<?php echo htmlspecialchars((string) ($flatSettings['bank_name'] ?? '')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>

                <div>
                    <label for="account_number" class="block text-sm font-medium text-gray-700">Account Number</label>
                    <input type="text" id="account_number" name="account_number" value="<?php echo htmlspecialchars((string) ($flatSettings['account_number'] ?? '')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>

                <div>
                    <label for="bank_branch" class="block text-sm font-medium text-gray-700">Branch</label>
                    <input type="text" id="bank_branch" name="bank_branch" value="<?php echo htmlspecialchars((string) ($flatSettings['bank_branch'] ?? '')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>

                <div>
                    <label for="swift_code" class="block text-sm font-medium text-gray-700">SWIFT Code</label>
                    <input type="text" id="swift_code" name="swift_code" value="<?php echo htmlspecialchars((string) ($flatSettings['swift_code'] ?? '')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>

                <div class="md:col-span-2">
                    <label for="iban" class="block text-sm font-medium text-gray-700">IBAN</label>
                    <input type="text" id="iban" name="iban" value="<?php echo htmlspecialchars((string) ($flatSettings['iban'] ?? '')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>
            </div>
        </div>

        <div class="bg-white shadow-md rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-2 text-gray-800 border-b pb-2">Billing customization</h2>
            <p class="text-sm text-gray-500 mb-6">Choose which PDF template variant is active for invoices, quotes (estimates), and sales receipts. Each variant can use a different header style, logo placement, and which sections appear (notes, bank details, terms, signatures, tax lines, footer). Use <strong>Preview PDF</strong> to open sample documents in a new tab (inline PDF) using your saved branding and layout settings.</p>

            <?php
            $blToggle = function ($doc, $vid, $key) use ($billingLayout) {
                $on = !empty($billingLayout[$doc]['variants'][$vid][$key]);
                return $on ? ' checked' : '';
            };
            $blSelectLogo = function ($doc, $vid) use ($billingLayout) {
                $v = (string) ($billingLayout[$doc]['variants'][$vid]['logo_position'] ?? 'left');
                return htmlspecialchars($v);
            };
            $blSelectStyle = function ($doc, $vid) use ($billingLayout) {
                $v = (string) ($billingLayout[$doc]['variants'][$vid]['header_style'] ?? 'band');
                return htmlspecialchars($v);
            };
            ?>

            <?php foreach (['invoice' => 'Invoices', 'quote' => 'Quotes (estimates)', 'receipt' => 'Sales receipts'] as $docKey => $docLabel): ?>
                <div class="mb-10 last:mb-0 border border-gray-100 rounded-lg p-4 bg-gray-50/50">
                    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-3">
                        <div>
                            <h3 class="text-base font-semibold text-gray-800"><?php echo htmlspecialchars($docLabel); ?></h3>
                            <p class="text-xs text-gray-500 mt-1">Active template for PDF downloads</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5 sm:justify-end shrink-0">
                            <span class="text-xs font-medium text-gray-500">Preview PDF <span class="font-normal text-gray-400">(saved settings)</span></span>
                            <a href="billing_preview?doc=<?php echo htmlspecialchars($docKey); ?>"
                                target="_blank" rel="noopener noreferrer"
                                class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium border border-blue-300 bg-blue-50 text-blue-800 hover:bg-blue-100">
                                Active
                            </a>
                            <?php foreach ($variantMeta as $vid => $vlabel): ?>
                                <a href="billing_preview?doc=<?php echo htmlspecialchars($docKey); ?>&amp;variant=<?php echo htmlspecialchars($vid); ?>"
                                    target="_blank" rel="noopener noreferrer"
                                    class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium border border-gray-300 bg-white text-gray-700 hover:bg-gray-100">
                                    <?php echo htmlspecialchars($vlabel); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-4 mb-6">
                        <?php foreach ($variantMeta as $vid => $vlabel): ?>
                            <label class="inline-flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="billing_layout[<?php echo htmlspecialchars($docKey); ?>][active_variant]" value="<?php echo htmlspecialchars($vid); ?>"
                                    <?php echo (($billingLayout[$docKey]['active_variant'] ?? 'executive') === $vid) ? 'checked' : ''; ?>>
                                <span class="text-sm font-medium text-gray-700"><?php echo htmlspecialchars($vlabel); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                        <?php foreach ($variantMeta as $vid => $vlabel): ?>
                            <div class="border border-gray-200 rounded-lg p-4 bg-white">
                                <h4 class="text-sm font-bold text-gray-700 mb-3 pb-2 border-b"><?php echo htmlspecialchars($vlabel); ?> variant</h4>
                                <div class="space-y-3 text-sm">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Logo position</label>
                                        <select name="billing_layout[<?php echo htmlspecialchars($docKey); ?>][variants][<?php echo htmlspecialchars($vid); ?>][logo_position]" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm">
                                            <?php foreach (['left' => 'Left', 'right' => 'Right', 'center' => 'Center', 'hidden' => 'Hidden'] as $opt => $olab): ?>
                                                <option value="<?php echo htmlspecialchars($opt); ?>"<?php echo $blSelectLogo($docKey, $vid) === $opt ? ' selected' : ''; ?>><?php echo htmlspecialchars($olab); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Header style</label>
                                        <select name="billing_layout[<?php echo htmlspecialchars($docKey); ?>][variants][<?php echo htmlspecialchars($vid); ?>][header_style]" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm">
                                            <?php foreach (['band' => 'Bold band', 'classic' => 'Classic', 'minimal' => 'Minimal'] as $opt => $olab): ?>
                                                <option value="<?php echo htmlspecialchars($opt); ?>"<?php echo $blSelectStyle($docKey, $vid) === $opt ? ' selected' : ''; ?>><?php echo htmlspecialchars($olab); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="space-y-2 pt-1">
                                        <input type="hidden" name="billing_layout[<?php echo htmlspecialchars($docKey); ?>][variants][<?php echo htmlspecialchars($vid); ?>][show_payment_details]" value="0">
                                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="billing_layout[<?php echo htmlspecialchars($docKey); ?>][variants][<?php echo htmlspecialchars($vid); ?>][show_payment_details]" value="1"<?php echo $blToggle($docKey, $vid, 'show_payment_details'); ?>> <span>Payment / bank details</span></label>
                                        <input type="hidden" name="billing_layout[<?php echo htmlspecialchars($docKey); ?>][variants][<?php echo htmlspecialchars($vid); ?>][show_terms]" value="0">
                                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="billing_layout[<?php echo htmlspecialchars($docKey); ?>][variants][<?php echo htmlspecialchars($vid); ?>][show_terms]" value="1"<?php echo $blToggle($docKey, $vid, 'show_terms'); ?>> <span>Terms / disclaimer</span></label>
                                        <input type="hidden" name="billing_layout[<?php echo htmlspecialchars($docKey); ?>][variants][<?php echo htmlspecialchars($vid); ?>][show_signatures]" value="0">
                                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="billing_layout[<?php echo htmlspecialchars($docKey); ?>][variants][<?php echo htmlspecialchars($vid); ?>][show_signatures]" value="1"<?php echo $blToggle($docKey, $vid, 'show_signatures'); ?>> <span>Signature lines</span></label>
                                        <input type="hidden" name="billing_layout[<?php echo htmlspecialchars($docKey); ?>][variants][<?php echo htmlspecialchars($vid); ?>][show_footer]" value="0">
                                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="billing_layout[<?php echo htmlspecialchars($docKey); ?>][variants][<?php echo htmlspecialchars($vid); ?>][show_footer]" value="1"<?php echo $blToggle($docKey, $vid, 'show_footer'); ?>> <span>Footer / contact band</span></label>
                                        <input type="hidden" name="billing_layout[<?php echo htmlspecialchars($docKey); ?>][variants][<?php echo htmlspecialchars($vid); ?>][show_notes]" value="0">
                                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="billing_layout[<?php echo htmlspecialchars($docKey); ?>][variants][<?php echo htmlspecialchars($vid); ?>][show_notes]" value="1"<?php echo $blToggle($docKey, $vid, 'show_notes'); ?>> <span>Job notes / description</span></label>
                                        <input type="hidden" name="billing_layout[<?php echo htmlspecialchars($docKey); ?>][variants][<?php echo htmlspecialchars($vid); ?>][show_tax_breakdown]" value="0">
                                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="billing_layout[<?php echo htmlspecialchars($docKey); ?>][variants][<?php echo htmlspecialchars($vid); ?>][show_tax_breakdown]" value="1"<?php echo $blToggle($docKey, $vid, 'show_tax_breakdown'); ?>> <span>Tax / discount breakdown</span></label>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg shadow-sm hover:bg-blue-700">
                Save Business Settings
            </button>
        </div>
    </form>
</div>

<?php include '../../includes/footer.php'; ?>
