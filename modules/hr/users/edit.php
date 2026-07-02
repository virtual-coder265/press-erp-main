<?php
require_once __DIR__ . '/../../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/_user_guards.php';
require_once __DIR__ . '/../../../includes/upload_helper.php';

if (!hasPermission('manage_users')) {
    die("Access Denied.");
}

$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    redirect('modules/hr/users/list?error=not_found');
}

$roles = $pdo->query("SELECT * FROM roles")->fetchAll();
$depts = $pdo->query("SELECT * FROM departments")->fetchAll();
$branches = $pdo->query("SELECT * FROM branches")->fetchAll();
$error_message = '';
$form_user = $user;
$tpl_section_head = !empty($user['is_section_head']);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $form_user['name'] = trim($_POST['name'] ?? '');
    $form_user['email'] = trim($_POST['email'] ?? '');
    $form_user['role_id'] = $_POST['role_id'] ?? '';
    $form_user['department_id'] = $_POST['dept_id'] ?? '';
    $form_user['branch_id'] = $_POST['branch_id'] ?? '';
    $is_section_head = isset($_POST['is_section_head']) && (string) $_POST['is_section_head'] === '1' ? 1 : 0;
    $tpl_section_head = $is_section_head === 1;
    $password_input = $_POST['password'] ?? '';

    if ($form_user['name'] === '' || $form_user['email'] === '') {
        $error_message = 'Name and email are required.';
    } elseif (!filter_var($form_user['email'], FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
    } else {
        $duplicate = findPotentialDuplicateUser($pdo, $form_user['name'], $form_user['email'], (int) $id);

        if ($duplicate !== null) {
            $error_message = buildDuplicateUserMessage($duplicate);
        } else {
            try {
                // Handle photo removal
                if (isset($_POST['remove_photo']) && $_POST['remove_photo'] === '1') {
                    $oldPhoto = $user['photo'] ?? null;
                    if ($oldPhoto && $oldPhoto !== 'default.png' && str_starts_with($oldPhoto, '/assets/uploads/avatars/')) {
                        $oldFile = __DIR__ . '/../../../' . ltrim($oldPhoto, '/');
                        if (is_file($oldFile)) {
                            @unlink($oldFile);
                        }
                    }
                    $form_user['photo'] = null;
                } elseif (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                    // Delete old photo if it exists
                    $oldPhoto = $user['photo'] ?? null;
                    if ($oldPhoto && $oldPhoto !== 'default.png' && str_starts_with($oldPhoto, '/assets/uploads/avatars/')) {
                        $oldFile = __DIR__ . '/../../../' . ltrim($oldPhoto, '/');
                        if (is_file($oldFile)) {
                            @unlink($oldFile);
                        }
                    }
                    $form_user['photo'] = store_validated_uploaded_file(
                        $_FILES['photo'],
                        'user_avatar',
                        __DIR__ . '/../../../assets/uploads/avatars/',
                        '/assets/uploads/avatars',
                        'avatar-'
                    );
                } else {
                    $form_user['photo'] = $user['photo'] ?? null;
                }

                $newPhoto = $form_user['photo'] ?? null;

                if ($password_input !== '') {
                    $password = password_hash($password_input, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET name=?, email=?, password=?, role_id=?, department_id=?, branch_id=?, photo=?, is_section_head=? WHERE id=?");
                    $stmt->execute([
                        $form_user['name'],
                        $form_user['email'],
                        $password,
                        $form_user['role_id'],
                        $form_user['department_id'],
                        $form_user['branch_id'],
                        $newPhoto,
                        $is_section_head,
                        $id,
                    ]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET name=?, email=?, role_id=?, department_id=?, branch_id=?, photo=?, is_section_head=? WHERE id=?");
                    $stmt->execute([
                        $form_user['name'],
                        $form_user['email'],
                        $form_user['role_id'],
                        $form_user['department_id'],
                        $form_user['branch_id'],
                        $newPhoto,
                        $is_section_head,
                        $id,
                    ]);
                }

                if ($is_section_head && $form_user['department_id'] !== '' && $form_user['department_id'] !== null) {
                    $clr = $pdo->prepare('UPDATE users SET is_section_head = 0 WHERE department_id = ? AND id != ?');
                    $clr->execute([(int) $form_user['department_id'], (int) $id]);
                }

                // Refresh session photo if editing self
                if ((int) $id === (int) ($_SESSION['user_id'] ?? 0)) {
                    $_SESSION['user_photo'] = $newPhoto;
                    if ($form_user['department_id'] !== '' && $form_user['department_id'] !== null) {
                        $_SESSION['department_id'] = (int) $form_user['department_id'];
                    }
                    $_SESSION['is_section_head'] = $is_section_head;
                }

                redirect('modules/hr/users/list?success=user_updated');
            } catch (RuntimeException $e) {
                $error_message = $e->getMessage();
            } catch (PDOException $e) {
                $error_message = 'Unable to update the user right now. Please review the details and try again.';
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
    <h1 class="text-3xl font-bold text-gray-800">Edit User: <?php echo htmlspecialchars($user['name']); ?></h1>
</div>

<div class="bg-white shadow rounded-lg p-8 max-w-2xl">
    <?php if ($error_message): ?>
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>
    <form method="POST" action="edit?id=<?php echo (int) $id; ?>" enctype="multipart/form-data">
        <div class="grid grid-cols-1 gap-6">
            <!-- Profile Photo -->
            <div>
                <label class="block text-gray-700 font-bold mb-2">Profile Photo</label>
                <div class="flex items-center gap-4">
                    <?php
                    $currentPhoto = $user['photo'] ?? null;
                    $hasPhoto = !empty($currentPhoto) && $currentPhoto !== 'default.png';
                    ?>
                    <div id="avatar-preview-wrap" class="w-20 h-20 rounded-full bg-gray-100 border-2 border-dashed border-gray-300 flex items-center justify-center overflow-hidden flex-shrink-0 relative">
                        <?php if ($hasPhoto): ?>
                            <img id="avatar-preview-img" src="<?php echo htmlspecialchars(BASE_URL . ltrim($currentPhoto, '/')); ?>" alt="Photo" class="w-full h-full object-cover">
                            <i class="material-icons text-gray-400 text-3xl hidden" id="avatar-placeholder-icon">person</i>
                        <?php else: ?>
                            <i class="material-icons text-gray-400 text-3xl" id="avatar-placeholder-icon">person</i>
                            <img id="avatar-preview-img" src="" alt="Preview" class="hidden w-full h-full object-cover">
                        <?php endif; ?>
                    </div>
                    <div class="flex-1 space-y-2">
                        <label class="cursor-pointer inline-flex items-center gap-2 bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg transition text-sm font-medium">
                            <i class="material-icons text-sm">upload</i> <?php echo $hasPhoto ? 'Change Photo' : 'Upload Photo'; ?>
                            <input type="file" name="photo" id="photo-input" class="hidden" accept=".jpg,.jpeg,.png,.gif,.webp">
                        </label>
                        <p class="text-xs text-gray-400">JPG, PNG, GIF or WEBP — max 2 MB</p>
                        <?php if ($hasPhoto): ?>
                        <div>
                            <label class="flex items-center gap-2 text-sm text-red-600 cursor-pointer">
                                <input type="checkbox" name="remove_photo" value="1" id="remove-photo-checkbox" class="rounded">
                                Remove current photo
                            </label>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div>
                <label class="block text-gray-700 font-bold mb-2">Full Name</label>
                <input type="text" name="name" class="w-full border rounded p-2 focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_user['name']); ?>" required>
            </div>
            <div>
                <label class="block text-gray-700 font-bold mb-2">Email Address</label>
                <input type="email" name="email" class="w-full border rounded p-2 focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_user['email']); ?>" required>
            </div>
            <div>
                <label class="block text-gray-700 font-bold mb-2">Password (Leave blank to keep current)</label>
                <input type="password" name="password" class="w-full border rounded p-2 focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-gray-700 font-bold mb-2">Role</label>
                    <select name="role_id" class="w-full border rounded p-2 focus:ring-2 focus:ring-blue-500" required>
                        <?php foreach($roles as $r): ?>
                            <option value="<?php echo $r['id']; ?>" <?php echo (string) $form_user['role_id'] === (string) $r['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($r['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 font-bold mb-2">Section (Dept)</label>
                    <select name="dept_id" class="w-full border rounded p-2 focus:ring-2 focus:ring-blue-500" required>
                        <?php foreach($depts as $d): ?>
                            <option value="<?php echo $d['id']; ?>" <?php echo (string) $form_user['department_id'] === (string) $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 font-bold mb-2">Branch</label>
                    <select name="branch_id" class="w-full border rounded p-2 focus:ring-2 focus:ring-blue-500" required>
                        <?php foreach($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo (string) $form_user['branch_id'] === (string) $b['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <label class="flex items-start gap-2 mt-2">
                <input type="checkbox" name="is_section_head" value="1" class="mt-1 rounded border-gray-300"
                    <?php echo $tpl_section_head ? 'checked' : ''; ?>>
                <span class="text-sm text-gray-700">
                    <span class="font-bold">Section head</span>
                    <span class="block text-gray-500">Only one active head per department is recommended.</span>
                </span>
            </label>
        </div>
        <div class="mt-8">
            <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded font-bold hover:bg-blue-700 transition">
                Update User
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
    var cb = document.getElementById('remove-photo-checkbox');
    if (cb) cb.checked = false;
});
</script>

<?php include '../../../includes/footer.php'; ?>
