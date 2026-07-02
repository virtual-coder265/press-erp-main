<?php
require_once dirname(dirname(__DIR__)) . '/config/app.php';
checkAuth();
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/upload_helper.php';

$user_id = intval($_SESSION['user_id']);
$success_message = '';
$error_message = '';

// Fetch user data
try {
    $stmt = $pdo->prepare("SELECT id, name, email, phone, whatsapp_phone, address, photo FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = "Error fetching user data.";
    $user = [];
}

// Handle Skip
if (isset($_POST['skip_profile'])) {
    $_SESSION['profile_skip_warning'] = true;
    $_SESSION['profile_enforced_valid'] = true;
    redirect('modules/dashboard/index');
}

// Handle avatar upload/remove (separate form submission)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_photo') {
    try {
        $currentPhoto = $user['photo'] ?? null;

        if (isset($_POST['remove_photo']) && $_POST['remove_photo'] === '1') {
            if ($currentPhoto && $currentPhoto !== 'default.png' && str_starts_with($currentPhoto, '/assets/uploads/avatars/')) {
                $oldFile = ROOT_PATH . ltrim($currentPhoto, '/');
                if (is_file($oldFile)) {
                    @unlink($oldFile);
                }
            }
            $pdo->prepare("UPDATE users SET photo = NULL WHERE id = ?")->execute([$user_id]);
            $_SESSION['user_photo'] = null;
            $user['photo'] = null;
            $success_message = 'Profile photo removed.';
        } elseif (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $newPath = store_validated_uploaded_file(
                $_FILES['photo'],
                'user_avatar',
                ROOT_PATH . 'assets/uploads/avatars/',
                '/assets/uploads/avatars',
                'avatar-'
            );
            if ($currentPhoto && $currentPhoto !== 'default.png' && str_starts_with($currentPhoto, '/assets/uploads/avatars/')) {
                $oldFile = ROOT_PATH . ltrim($currentPhoto, '/');
                if (is_file($oldFile)) {
                    @unlink($oldFile);
                }
            }
            $pdo->prepare("UPDATE users SET photo = ? WHERE id = ?")->execute([$newPath, $user_id]);
            $_SESSION['user_photo'] = $newPath;
            $user['photo'] = $newPath;
            $success_message = 'Profile photo updated successfully!';
        }
    } catch (RuntimeException $e) {
        $error_message = $e->getMessage();
    } catch (Exception $e) {
        $error_message = 'Error updating profile photo. Please try again.';
    }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['skip_profile']) && !isset($_POST['action'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $whatsapp_phone = trim($_POST['whatsapp_phone'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if (empty($name) || empty($email)) {
        $error_message = "Name and email are required.";
    } else {
        $phone_valid = empty($phone) || preg_match('/^\+265(99|88|98|89)\d{5,}$/', $phone);
        $whatsapp_valid = empty($whatsapp_phone) || preg_match('/^\+265(99|88|98|89)\d{5,}$/', $whatsapp_phone);
        
        if (!$phone_valid || (!$whatsapp_valid && !empty($whatsapp_phone))) {
            $error_message = "Phone numbers must start with +265 and be followed by 99, 88, 98, or 89, with at least 10 digits total.";
        } elseif (empty($phone) && empty($whatsapp_phone)) {
            $error_message = "You must provide at least one valid phone number (SMS or WhatsApp) to receive notifications.";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ?, whatsapp_phone = ?, address = ? WHERE id = ?");
                $stmt->execute([$name, $email, $phone, $whatsapp_phone, $address, $user_id]);
                $_SESSION['user_name'] = $name;
                $_SESSION['profile_enforced_valid'] = true;
                $success_message = "Profile updated successfully!";
            
            // Notify user of profile change
            require_once ROOT_PATH . 'libs/NotificationManager.php';
            $notifManager = new NotificationManager($pdo);
            $notifManager->notify(
                $user_id,
                'security',
                'Profile Updated',
                'Your profile information has been successfully updated.',
                'modules/user-account/profile'
            );

            $user = compact('name', 'email', 'phone', 'whatsapp_phone', 'address');
            $user['id'] = $user_id;
        } catch (Exception $e) {
            $error_message = "Error updating profile. Please try again.";
        }
        } // close phone validation else
    }
}

include ROOT_PATH . 'includes/header.php';

$is_force_update = isset($_GET['action']) && $_GET['action'] === 'force_update';
?>

<div class="max-w-4xl mx-auto">
    <div class="mb-8">
        <h1 class="text-3xl font-extrabold text-gray-900 tracking-tight">Account Settings</h1>
        <p class="text-gray-500 mt-1">Manage your profile information and contact details.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
        <!-- Sidebar Navigation -->
        <div class="space-y-2 <?php echo $is_force_update ? 'opacity-50 pointer-events-none' : ''; ?>">
            <a href="profile" class="flex items-center space-x-3 px-4 py-3 bg-blue-600 text-white rounded-xl shadow-md transition-all">
                <i class="material-icons">person</i>
                <span class="font-medium">Public Profile</span>
            </a>
            <a href="security" class="flex items-center space-x-3 px-4 py-3 text-gray-600 hover:bg-white hover:shadow-sm rounded-xl transition-all">
                <i class="material-icons">security</i>
                <span class="font-medium">Security</span>
            </a>
            <a href="notifications" class="flex items-center space-x-3 px-4 py-3 text-gray-600 hover:bg-white hover:shadow-sm rounded-xl transition-all">
                <i class="material-icons">notifications</i>
                <span class="font-medium">Notifications</span>
            </a>
            <a href="tasks" class="flex items-center space-x-3 px-4 py-3 text-gray-600 hover:bg-white hover:shadow-sm rounded-xl transition-all">
                <i class="material-icons">assignment</i>
                <span class="font-medium">My Tasks</span>
            </a>
            <a href="../reminders/index" class="flex items-center space-x-3 px-4 py-3 text-gray-600 hover:bg-white hover:shadow-sm rounded-xl transition-all">
                <i class="material-icons">alarm</i>
                <span class="font-medium">Reminders</span>
            </a>
        </div>

        <!-- Main Form -->
        <div class="md:col-span-2 space-y-6">

            <!-- Profile Photo Card -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8">
                <h2 class="text-xl font-bold text-gray-800 mb-6">Profile Photo</h2>
                <?php
                $currentPhoto = $user['photo'] ?? null;
                $hasPhoto = !empty($currentPhoto) && $currentPhoto !== 'default.png';
                $userInitialProfile = strtoupper(substr($user['name'] ?? 'U', 0, 1));
                ?>
                <div class="flex items-center gap-6">
                    <div class="w-24 h-24 rounded-full overflow-hidden flex-shrink-0 bg-gradient-to-br from-teal-600 to-blue-600 flex items-center justify-center" id="profile-avatar-wrap">
                        <?php if ($hasPhoto): ?>
                            <img id="profile-avatar-img" src="<?php echo htmlspecialchars(BASE_URL . ltrim($currentPhoto, '/')); ?>" alt="Profile photo" class="w-full h-full object-cover">
                        <?php else: ?>
                            <span id="profile-avatar-initial" class="text-white font-bold text-2xl"><?php echo htmlspecialchars($userInitialProfile); ?></span>
                            <img id="profile-avatar-img" src="" alt="Preview" class="hidden w-full h-full object-cover">
                        <?php endif; ?>
                    </div>
                    <div class="flex-1">
                        <form method="POST" action="profile" enctype="multipart/form-data" id="photo-form">
                            <input type="hidden" name="action" value="update_photo">
                            <input type="hidden" name="remove_photo" id="remove-photo-flag" value="0">
                            <input type="file" name="photo" id="profile-photo-input" class="hidden" accept=".jpg,.jpeg,.png,.gif,.webp">
                            <div class="flex flex-wrap gap-3">
                                <label for="profile-photo-input" class="cursor-pointer inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl transition text-sm font-medium">
                                    <i class="material-icons text-sm">upload</i> <?php echo $hasPhoto ? 'Change Photo' : 'Upload Photo'; ?>
                                </label>
                                <?php if ($hasPhoto): ?>
                                <button type="button" onclick="document.getElementById('remove-photo-flag').value='1'; document.getElementById('photo-form').submit();" class="inline-flex items-center gap-2 bg-red-50 hover:bg-red-100 text-red-600 px-4 py-2 rounded-xl transition text-sm font-medium">
                                    <i class="material-icons text-sm">delete</i> Remove
                                </button>
                                <?php endif; ?>
                            </div>
                            <p class="text-xs text-gray-400 mt-2">JPG, PNG, GIF or WEBP — max 2 MB</p>
                        </form>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8">
                <h2 class="text-xl font-bold text-gray-800 mb-6">Personal Information</h2>
                
                <?php if ($is_force_update && !$success_message): ?>
                    <div class="mb-6 p-4 bg-yellow-50 border-l-4 border-yellow-500 text-yellow-800">
                        <h3 class="font-bold flex items-center mb-2"><i class="material-icons mr-2">warning</i> Contact Update Required</h3>
                        <p class="text-sm mb-2">To continue using Gov Press ERP safely and ensure you receive urgent notifications, you must update your profile with a valid <strong>SMS Phone</strong> or <strong>WhatsApp Number</strong> in the format <code class="bg-yellow-200 px-1 rounded">+26599...</code>.</p>
                        <form method="POST" action="profile<?php echo $is_force_update ? '?action=force_update' : ''; ?>" class="mt-3">
                            <button type="submit" name="skip_profile" class="text-sm font-semibold text-yellow-900 underline hover:text-yellow-700">Skip for now</button>
                            <span class="text-xs text-yellow-700 ml-2">(You may miss urgent task assignments and critical system notifications)</span>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if ($success_message): ?>
                    <div class="mb-6 p-4 bg-green-50 border-l-4 border-green-500 text-green-700 flex items-center">
                        <i class="material-icons mr-2">check_circle</i>
                        <span class="text-sm font-medium"><?php echo htmlspecialchars($success_message); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="mb-6 p-4 bg-red-50 border-l-4 border-red-500 text-red-700 flex items-center">
                        <i class="material-icons mr-2">error</i>
                        <span class="text-sm font-medium"><?php echo htmlspecialchars($error_message); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="profile<?php echo $is_force_update ? '?action=force_update' : ''; ?>" class="space-y-6" id="profileUpdateForm">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-bold text-gray-700 mb-2 uppercase tracking-wide">Full Name</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                                    <i class="material-icons text-sm">badge</i>
                                </span>
                                <input type="text" name="name" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required 
                                       class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:bg-white transition-all outline-none">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2 uppercase tracking-wide">Email Address</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                                    <i class="material-icons text-sm">email</i>
                                </span>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required 
                                       class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:bg-white transition-all outline-none">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2 uppercase tracking-wide">SMS Phone Number</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                                    <i class="material-icons text-sm">phone</i>
                                </span>
                                <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="+265..."
                                       class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:bg-white transition-all outline-none">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2 uppercase tracking-wide">WhatsApp Number</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                                    <i class="material-icons text-sm">forum</i>
                                </span>
                                <input type="tel" name="whatsapp_phone" value="<?php echo htmlspecialchars($user['whatsapp_phone'] ?? ''); ?>" placeholder="+265..."
                                       class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:bg-white transition-all outline-none">
                            </div>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-bold text-gray-700 mb-2 uppercase tracking-wide">Residential Address</label>
                            <div class="relative">
                                <span class="absolute top-3 left-3 flex items-center text-gray-400">
                                    <i class="material-icons text-sm">place</i>
                                </span>
                                <textarea name="address" rows="4" 
                                          class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:bg-white transition-all outline-none"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="pt-4 flex items-center justify-end space-x-4">
                        <a href="<?php echo BASE_URL; ?>modules/dashboard/index" class="text-gray-500 hover:text-gray-700 font-bold px-6 py-3 transition">Discard</a>
                        <button
                            type="submit"
                            id="profileUpdateButton"
                            class="bg-blue-600 text-white font-bold px-10 py-3 rounded-xl hover:bg-blue-700 transition shadow-lg shadow-blue-200 opacity-60 cursor-not-allowed"
                            data-default-label="Update Profile"
                            data-locked-label="Update After Changes"
                            disabled
                            aria-disabled="true"
                        >
                            Update Profile
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('profile-photo-input').addEventListener('change', function () {
    var file = this.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function (e) {
        var img = document.getElementById('profile-avatar-img');
        var initial = document.getElementById('profile-avatar-initial');
        img.src = e.target.result;
        img.classList.remove('hidden');
        if (initial) initial.classList.add('hidden');
        document.getElementById('remove-photo-flag').value = '0';
    };
    reader.readAsDataURL(file);
    document.getElementById('photo-form').submit();
});

document.addEventListener('DOMContentLoaded', function () {
    var profileForm = document.getElementById('profileUpdateForm');
    var updateButton = document.getElementById('profileUpdateButton');

    if (!profileForm || !updateButton) {
        return;
    }

    function captureFormState(form) {
        var parts = [];
        Array.prototype.forEach.call(form.elements, function (element) {
            if (!element.name || element.disabled || element.type === 'submit' || element.type === 'button') {
                return;
            }

            if ((element.type === 'checkbox' || element.type === 'radio') && !element.checked) {
                return;
            }

            parts.push(element.name + '=' + element.value);
        });

        return parts.join('||');
    }

    var initialProfileFormState = captureFormState(profileForm);

    function syncProfileUpdateButton() {
        var isDirty = captureFormState(profileForm) !== initialProfileFormState;
        updateButton.disabled = !isDirty;
        updateButton.setAttribute('aria-disabled', isDirty ? 'false' : 'true');
        updateButton.classList.toggle('opacity-60', !isDirty);
        updateButton.classList.toggle('cursor-not-allowed', !isDirty);
        updateButton.textContent = isDirty
            ? (updateButton.dataset.defaultLabel || 'Update Profile')
            : (updateButton.dataset.lockedLabel || 'Update After Changes');
    }

    profileForm.addEventListener('input', syncProfileUpdateButton);
    profileForm.addEventListener('change', syncProfileUpdateButton);
    syncProfileUpdateButton();
});
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
