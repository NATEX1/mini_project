<?php
// staff.php - จัดการข้อมูลเจ้าหน้าที่ในระบบ (เฉพาะผู้ดูแลระบบ)
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_admin();

$page_title = 'จัดการข้อมูลเจ้าหน้าที่';

$errors = [];

// จัดการเพิ่มเจ้าหน้าที่ใหม่
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $username   = trim($_POST['username'] ?? '');
    $password   = trim($_POST['password'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $role       = trim($_POST['role'] ?? 'เจ้าหน้าที่ลงทะเบียน');
    $phone      = trim($_POST['phone_number'] ?? '');

    if (empty($username)) {
        $errors[] = 'กรุณาระบุชื่อผู้ใช้ (Username)';
    } else {
        $check = $pdo->prepare("SELECT id FROM staff WHERE username = ?");
        $check->execute([$username]);
        if ($check->fetch()) {
            $errors[] = "ชื่อผู้ใช้ '{$username}' มีอยู่แล้วในระบบ";
        }
    }

    if (empty($password) || strlen($password) < 4) {
        $errors[] = 'รหัสผ่านต้องมีความยาวอย่างน้อย 4 ตัวอักษร';
    }

    if (empty($first_name) || empty($last_name)) {
        $errors[] = 'กรุณาระบุชื่อและนามสกุลจริง';
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $insert = $pdo->prepare("
            INSERT INTO staff (username, password_hash, first_name, last_name, role, phone_number, is_active)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        ");
        $insert->execute([$username, $hash, $first_name, $last_name, $role, $phone]);

        set_flash('success', "เพิ่มเจ้าหน้าที่ '{$username}' เรียบร้อยแล้ว");
        header('Location: staff.php');
        exit;
    }
}

// จัดการเปิด/ปิดการใช้งานเจ้าหน้าที่
if (isset($_GET['toggle_id'])) {
    $toggle_id = (int)$_GET['toggle_id'];
    // ห้ามปิดบัญชีตัวเอง
    if ($toggle_id === (int)$_SESSION['staff_id']) {
        set_flash('danger', 'ไม่สามารถปิดการใช้งานบัญชีที่คุณกำลังใช้งานอยู่ได้');
    } else {
        $stmt = $pdo->prepare("UPDATE staff SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?");
        $stmt->execute([$toggle_id]);
        set_flash('info', 'อัปเดตสถานะเจ้าหน้าที่เรียบร้อย');
    }
    header('Location: staff.php');
    exit;
}

$staff_list = $pdo->query("SELECT * FROM staff ORDER BY id ASC")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="fa-solid fa-user-shield"></i> จัดการข้อมูลเจ้าหน้าที่</h1>
        <p class="page-subtitle">จัดการผู้ใช้งาน กำหนดบทบาท และดูแลความปลอดภัยของระบบ</p>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <div>
            <i class="fa-solid fa-circle-exclamation"></i>
            <ul style="margin-left: 20px; margin-top: 5px;">
                <?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 2fr; gap: 24px;">
    
    <!-- ฟอร์มเพิ่มเจ้าหน้าที่ใหม่ -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-user-plus"></i> เพิ่มเจ้าหน้าที่ใหม่</h2>
        </div>

        <form method="POST" action="staff.php">
            <input type="hidden" name="action" value="add">

            <div class="form-group">
                <label for="username">ชื่อผู้ใช้ (Username) <span class="required">*</span></label>
                <input type="text" id="username" name="username" placeholder="เช่น somchai" required>
            </div>

            <div class="form-group">
                <label for="password">รหัสผ่าน <span class="required">*</span></label>
                <input type="password" id="password" name="password" placeholder="กำหนดรหัสผ่าน..." required>
            </div>

            <div class="form-group">
                <label for="first_name">ชื่อจริง <span class="required">*</span></label>
                <input type="text" id="first_name" name="first_name" placeholder="สมชาย" required>
            </div>

            <div class="form-group">
                <label for="last_name">นามสกุล <span class="required">*</span></label>
                <input type="text" id="last_name" name="last_name" placeholder="ใจดี" required>
            </div>

            <div class="form-group">
                <label for="role">บทบาทหน้าที่ (Role)</label>
                <select id="role" name="role">
                    <option value="เจ้าหน้าที่ลงทะเบียน">เจ้าหน้าที่ลงทะเบียน</option>
                    <option value="ผู้ดูแลระบบ">ผู้ดูแลระบบ</option>
                </select>
            </div>

            <div class="form-group">
                <label for="phone_number">เบอร์โทรศัพท์</label>
                <input type="tel" id="phone_number" name="phone_number" placeholder="08xxxxxxxx">
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%;">
                <i class="fa-solid fa-floppy-disk"></i> บันทึกเจ้าหน้าที่ใหม่
            </button>
        </form>
    </div>

    <!-- ตารางรายชื่อเจ้าหน้าที่ -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-users-gear"></i> รายชื่อเจ้าหน้าที่ทั้งหมด</h2>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>ชื่อ - นามสกุล</th>
                        <th>บทบาท</th>
                        <th>เบอร์โทร</th>
                        <th>สถานะ</th>
                        <th style="text-align: right;">การจัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staff_list as $s): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($s['username']) ?></code></td>
                        <td><strong><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></strong></td>
                        <td>
                            <span class="badge <?= $s['role'] === 'ผู้ดูแลระบบ' ? 'badge-called' : 'badge-secondary' ?>">
                                <?= htmlspecialchars($s['role']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($s['phone_number'] ?: '-') ?></td>
                        <td>
                            <?php if ($s['is_active']): ?>
                                <span class="badge badge-completed">เปิดใช้งาน</span>
                            <?php else: ?>
                                <span class="badge badge-cancelled">ปิดใช้งาน</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right; white-space: nowrap;">
                            <?php if ((int)$s['id'] !== (int)$_SESSION['staff_id']): ?>
                                <a href="staff.php?toggle_id=<?= $s['id'] ?>" class="btn btn-secondary btn-sm" onclick="return confirm('ยืนยันเปลี่ยนสถานะผู้ใช้นี้?');">
                                    <?= $s['is_active'] ? 'ระงับ' : 'เปิดใช้' ?>
                                </a>
                            <?php else: ?>
                                <small style="color: var(--text-muted);">(คุณ)</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
