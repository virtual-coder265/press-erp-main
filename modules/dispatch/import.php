<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['manage_dispatch']);

include '../../includes/header.php';
?>

<div class="mb-6">
    <a href="list" class="text-green-600 hover:underline flex items-center mb-4">
        <i class="material-icons text-sm mr-1">arrow_back</i> Back to Dispatch Register
    </a>
    <h1 class="text-3xl font-bold text-gray-800">Import Dispatch Register</h1>
    <p class="text-gray-600">Import dispatch data from Excel or CSV files.</p>
</div>

<?php
// Check if PhpSpreadsheet is available
$phpspreadsheet_available = file_exists(__DIR__ . '/../../vendor/autoload.php');
?>

<!-- Instructions Card -->
<div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-6">
    <h2 class="text-lg font-bold text-blue-800 mb-3 flex items-center">
        <i class="material-icons mr-2">info</i> Import Instructions
    </h2>
    <?php if (!$phpspreadsheet_available): ?>
    <div class="bg-yellow-100 border border-yellow-300 rounded p-3 mb-4">
        <p class="text-sm text-yellow-800">
            <strong>Note:</strong> PhpSpreadsheet is not installed. Excel files (.xlsx, .xls) will not be supported. 
            CSV files will work fine. To enable Excel support, install PhpSpreadsheet: 
            <code class="bg-yellow-200 px-2 py-1 rounded">composer require phpoffice/phpspreadsheet</code>
        </p>
    </div>
    <?php endif; ?>
    <div class="text-blue-700 space-y-2 text-sm">
        <p><strong>Supported Formats:</strong> CSV (.csv), Excel (.xlsx, .xls)</p>
        <p><strong>Required Columns:</strong> Your file should include the following columns (column names can vary):</p>
        <ul class="list-disc list-inside ml-4 space-y-1">
            <li>Work Order Number (optional)</li>
            <li>Date In (required) - Format: YYYY-MM-DD or DD/MM/YYYY</li>
            <li>Ministry/Department (required)</li>
            <li>Job Description (optional)</li>
            <li>Remarks (optional - supports template text or any custom remark)</li>
            <li>Quantity (optional, defaults to 0)</li>
            <li>Date Out (optional) - Format: YYYY-MM-DD or DD/MM/YYYY</li>
            <li>Delivery Note Number (optional)</li>
            <li>Authorised Dispatcher (optional - name or email)</li>
        </ul>
        <p class="mt-3"><strong>Note:</strong> The first row should contain column headers. Date formats are automatically detected.</p>
        <p class="mt-2"><strong>PDF Files:</strong> PDF files with tables can be imported, but for best results, convert PDF tables to CSV or Excel format first using Adobe Acrobat or online converters.</p>
    </div>
</div>

<!-- Import Form -->
<div class="bg-white shadow rounded-lg p-8 max-w-3xl">
    <form method="POST" action="import_process" enctype="multipart/form-data" id="importForm">
        <div class="space-y-6">
            <div>
                <label class="block text-gray-700 font-bold mb-2">Select File *</label>
                <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center hover:border-green-500 transition">
                    <input type="file" name="import_file" id="import_file" accept=".csv,.xlsx,.xls,.pdf" required
                           class="hidden" onchange="updateFileName(this)">
                    <label for="import_file" class="cursor-pointer">
                        <i class="material-icons text-4xl text-gray-400 mb-2">cloud_upload</i>
                        <p class="text-gray-600 mb-1">Click to select file or drag and drop</p>
                        <p class="text-xs text-gray-500">CSV, XLSX, XLS, or PDF files</p>
                    </label>
                    <div id="file_name" class="mt-2 text-sm text-green-600 font-semibold hidden"></div>
                </div>
            </div>
            
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                <div class="flex items-start">
                    <i class="material-icons text-yellow-600 mr-2">warning</i>
                    <div class="text-sm text-yellow-800">
                        <strong>Important:</strong> Make sure your file has a header row with column names. 
                        The import will attempt to match columns automatically. Duplicate entries will be skipped.
                    </div>
                </div>
            </div>
            
            <div class="flex items-center">
                <input type="checkbox" name="skip_duplicates" id="skip_duplicates" value="1" checked
                       class="w-4 h-4 text-green-600 border-gray-300 rounded focus:ring-green-500">
                <label for="skip_duplicates" class="ml-2 text-sm text-gray-700">
                    Skip duplicate entries (based on Work Order Number and Date In)
                </label>
            </div>
        </div>
        
        <div class="mt-8 flex gap-4">
            <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded font-bold hover:bg-green-700 transition">
                <i class="material-icons align-middle mr-2">upload</i> Import Data
            </button>
            <a href="list" class="bg-gray-300 text-gray-700 px-6 py-2 rounded font-bold hover:bg-gray-400 transition">
                Cancel
            </a>
        </div>
    </form>
</div>

<!-- Sample Template Download -->
<div class="bg-white shadow rounded-lg p-6 mt-6 max-w-3xl">
    <h3 class="text-lg font-bold text-gray-800 mb-3">Download Sample Template</h3>
    <p class="text-gray-600 text-sm mb-4">Use this template to ensure your data is in the correct format.</p>
    <a href="import_template" class="inline-flex items-center bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 transition">
        <i class="material-icons mr-2">download</i> Download CSV Template
    </a>
</div>

<script>
function updateFileName(input) {
    if (input.files && input.files[0]) {
        const fileName = input.files[0].name;
        const fileSize = (input.files[0].size / 1024 / 1024).toFixed(2);
        document.getElementById('file_name').textContent = `Selected: ${fileName} (${fileSize} MB)`;
        document.getElementById('file_name').classList.remove('hidden');
    }
}

// Drag and drop functionality
const dropZone = document.querySelector('.border-dashed');
const fileInput = document.getElementById('import_file');

['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
    dropZone.addEventListener(eventName, preventDefaults, false);
});

function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
}

['dragenter', 'dragover'].forEach(eventName => {
    dropZone.addEventListener(eventName, highlight, false);
});

['dragleave', 'drop'].forEach(eventName => {
    dropZone.addEventListener(eventName, unhighlight, false);
});

function highlight(e) {
    dropZone.classList.add('border-green-500', 'bg-green-50');
}

function unhighlight(e) {
    dropZone.classList.remove('border-green-500', 'bg-green-50');
}

dropZone.addEventListener('drop', handleDrop, false);

function handleDrop(e) {
    const dt = e.dataTransfer;
    const files = dt.files;
    fileInput.files = files;
    updateFileName(fileInput);
}
</script>

<?php include '../../includes/footer.php'; ?>

