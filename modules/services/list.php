<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['view_services']);

include '../../includes/header.php';
?>

<div class="flex justify-between items-center mb-6">
    <h1 class="text-3xl font-bold text-gray-800">Services</h1>
    <a href="#" class="bg-green-600 text-white px-4 py-2 rounded shadow hover:bg-green-700 transition">
        <i class="material-icons align-middle">add</i> Add Service
    </a>
</div>

<div class="bg-white shadow rounded-lg p-6">
    <p class="text-gray-600">Service management module comes here.</p>
</div>

<?php include '../../includes/footer.php'; ?>
