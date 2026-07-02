<?php
require_once __DIR__ . '/../../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/_user_guards.php';
require_once __DIR__ . '/../../../includes/upload_helper.php';

if (!hasPermission('manage_users')) {
    die("Access Denied.");
}

$roles = $pdo->query("SELECT * FROM roles")->fetchAll();
$depts = $pdo->query("SELECT * FROM departments")->fetchAll();
$branches = $pdo->query("SELECT * FROM branches")->fetchAll();
$error_message = '';
$name = '';
$email = '';
$role_id = '';
$dept_id = '';
$branch_id = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password_input = $_POST['password'] ?? '';
    $role_id = $_POST['role_id'] ?? '';
    $dept_id = $_POST['dept_id'] ?? '';
    $branch_id = $_POST['branch_id'] ?? '';
    $is_section_head = isset($_POST['is_section_head']) && (string) $_POST['is_section_head'] === '1' ? 1 : 0;

    if ($name === '' || $email === '' || $password_input === '') {
        $error_message = 'Name, email, and password are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
    } else {
        $duplicate = findPotentialDuplicateUser($pdo, $name, $email);

        if ($duplicate !== null) {
            $error_message = buildDuplicateUserMessage($duplicate);
        } else {
            try {
                $photo_path = null;
                if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                    $photo_path = store_validated_uploaded_file(
                        $_FILES['photo'],
                        'user_avatar',
                        __DIR__ . '/../../../assets/uploads/avatars/',
                        '/assets/uploads/avatars',
                        'avatar-'
                    );
                }

                $password = password_hash($password_input, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare(
                    'INSERT INTO users (name, email, password, role_id, department_id, branch_id, photo, is_section_head) VALUES (?,?,?,?,?,?,?,?)'
                );

                if ($stmt->execute([$name, $email, $password, $role_id, $dept_id, $branch_id, $photo_path, $is_section_head])) {
                    $newId = (int) $pdo->lastInsertId();
                    if ($newId > 0 && $is_section_head && $dept_id !== '' && $dept_id !== null) {
                        $clear = $pdo->prepare(
                            'UPDATE users SET is_section_head = 0 WHERE department_id = ? AND id != ?'
                        );
                        $clear->execute([(int) $dept_id, $newId]);
                    }
                    redirect('modules/hr/users/list?success=user_added');
                }
            } catch (RuntimeException $e) {
                $error_message = $e->getMessage();
            } catch (PDOException $e) {
                $error_message = 'Unable to create the user right now. Please review the details and try again.';
            }
        }
    }
}

include '../../../includes/header.php';
?>

<div class="mb-6">
    <a href="list" class="text-blue-600 hover:underline flex items-center mb-4">
        <i class="material-icons text-sm mr-1">arrow_back</i> Back to List
    </a>
    <h1 class="text-3xl font-bold text-gray-800">Add New User</h1>
</div>

<div class="bg-white shadow rounded-lg p-8 max-w-2xl">
    <?php if ($error_message): ?>
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>
    <form method="POST" action="create" enctype="multipart/form-data">
        <div class="grid grid-cols-1 gap-6">
            <!-- Profile Photo -->
            <div>
                <label class="block text-gray-700 font-bold mb-2">Profile Photo <span class="font-normal text-gray-400">(optional)</span></label>
                <div class="flex items-center gap-4">
                    <div id="avatar-preview-wrap" class="w-20 h-20 rounded-full bg-gray-100 border-2 border-dashed border-gray-300 flex items-center justify-center overflow-hidden flex-shrink-0">
                        <i class="material-icons text-gray-400 text-3xl" id="avatar-placeholder-icon">person</i>
                        <img id="avatar-preview-img" src="" alt="Preview" class="hidden w-full h-full object-cover">
                    </div>
                    <div class="flex-1">
                        <label class="cursor-pointer inline-flex items-center gap-2 bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg transition text-sm font-medium">
                            <i class="material-icons text-sm">upload</i> Choose Photo
                            <input type="file" name="photo" id="photo-input" class="hidden" accept=".jpg,.jpeg,.png,.gif,.webp">
                        </label>
                        <p class="text-xs text-gray-400 mt-1">JPG, PNG, GIF or WEBP — max 2 MB</p>
                    </div>
                </div>
            </div>
            <div>
                <label class="block text-gray-700 font-bold mb-2">Full Name</label>
                <input type="text" name="name" class="w-full border rounded p-2 focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($name); ?>" required>
            </div>
            <div>
                <label class="block text-gray-700 font-bold mb-2">Email Address</label>
                <input type="email" name="email" class="w-full border rounded p-2 focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($email); ?>" required>
            </div>
            <div>
                <label class="block text-gray-700 font-bold mb-2">Password</label>
                <input type="password" name="password" class="w-full border rounded p-2 focus:ring-2 focus:ring-blue-500" required>
            </div>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-gray-700 font-bold mb-2">Role</label>
                    <select name="role_id" class="w-full border rounded p-2 focus:ring-2 focus:ring-blue-500" required>
                        <?php foreach($roles as $r): ?>
                            <option value="<?php echo $r['id']; ?>" <?php echo (string) $role_id === (string) $r['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($r['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 font-bold mb-2">Section (Dept)</label>
                    <select name="dept_id" class="w-full border rounded p-2 focus:ring-2 focus:ring-blue-500" required>
                        <?php foreach($depts as $d): ?>
                            <option value="<?php echo $d['id']; ?>" <?php echo (string) $dept_id === (string) $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 font-bold mb-2">Branch</label>
                    <select name="branch_id" class="w-full border rounded p-2 focus:ring-2 focus:ring-blue-500" required>
                        <?php foreach($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo (string) $branch_id === (string) $b['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <label class="flex items-start gap-2 mt-2">
                <input type="checkbox" name="is_section_head" value="1" class="mt-1 rounded border-gray-300">
                <span class="text-sm text-gray-700">
                    <span class="font-bold">Section head</span>
                    <span class="block text-gray-500">Normally one per department. Section heads can steward departmental projects.</span>
                </span>
            </label>
        </div>
        <div class="mt-8">
            <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded font-bold hover:bg-blue-700 transition">
                Create User
            </button>
        </div>
    </form>
</div>

<script>
document.getElementById('photo-input').addEventListener('change', function () {
    var file = this.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function (e) {
        var img = document.getElementById('avatar-preview-img');
        var icon = document.getElementById('avatar-placeholder-icon');
        img.src = e.target.result;
        img.classList.remove('hidden');
        icon.classList.add('hidden');
    };
    reader.readAsDataURL(file);
});
</script>

<?php include '../../../includes/footer.php'; ?>
