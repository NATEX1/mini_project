<?php
// departments.php - จัดการแผนกตรวจ / คลินิก
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$page_title = 'จัดการแผนกตรวจ';

$errors = [];

// จัดการเพิ่มแผนกใหม่
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $code = strtoupper(trim($_POST['department_code'] ?? ''));
    $name = trim($_POST['department_name'] ?? '');
    $desc = trim($_POST['description'] ?? '');

    if (empty($code)) {
        $errors[] = 'กรุณากรอกรหัสแผนก';
    } else {
        $check = $pdo->prepare("SELECT id FROM departments WHERE department_code = ?");
        $check->execute([$code]);
        if ($check->fetch()) {
            $errors[] = "รหัสแผนก '{$code}' มีอยู่แล้วในระบบ";
        }
    }

    if (empty($name)) {
        $errors[] = 'กรุณากรอกชื่อแผนก';
    }

    if (empty($errors)) {
        $insert = $pdo->prepare("INSERT INTO departments (department_code, department_name, description, is_active) VALUES (?, ?, ?, 1)");
        $insert->execute([$code, $name, $desc]);
        set_flash('success', "เพิ่มแผนก '{$name}' เรียบร้อยแล้ว");
        header('Location: departments.php');
        exit;
    }
}

// จัดการเปิด/ปิดการใช้งานแผนก (Toggle status)
if (isset($_GET['toggle_id'])) {
    $toggle_id = (int)$_GET['toggle_id'];
    $stmt = $pdo->prepare("UPDATE departments SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?");
    $stmt->execute([$toggle_id]);
    set_flash('info', 'อัปเดตสถานะการใช้งานแผนกเรียบร้อย');
    header('Location: departments.php');
    exit;
}

$departments = $pdo->query("SELECT * FROM departments ORDER BY is_active DESC, id ASC")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="fa-solid fa-stethoscope"></i> แผนกตรวจและคลินิก</h1>
        <p class="page-subtitle">จัดการข้อมูลแผนก กำหนดรหัสแผนกสำหรับนำหน้าหมายเลขคิว</p>
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
    
    <!-- ฟอร์มเพิ่มแผนกใหม่ -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-plus-circle"></i> เพิ่มแผนกใหม่</h2>
        </div>

        <form method="POST" action="departments.php">
            <input type="hidden" name="action" value="add">

            <div class="form-group">
                <label for="department_code">รหัสแผนก (Code) <span class="required">*</span></label>
                <input type="text" id="department_code" name="department_code" placeholder="เช่น MED, SUR, EYE, ER" required style="text-transform: uppercase;">
                <small style="color: var(--text-muted);">ใช้เป็นตัวอักษรนำหน้าบัตรคิว เช่น MED-001</small>
            </div>

            <div class="form-group">
                <label for="department_name">ชื่อแผนก / คลินิก <span class="required">*</span></label>
                <input type="text" id="department_name" name="department_name" placeholder="เช่น แผนกจักษุ (Eye Clinic)" required>
            </div>

            <div class="form-group">
                <label for="description">รายละเอียด / อาคาร / ชั้น</label>
                <textarea id="description" name="description" rows="3" placeholder="ระบุรายละเอียดหรือตำแหน่งห้องตรวจ..."></textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%;">
                <i class="fa-solid fa-floppy-disk"></i> บันทึกแผนกใหม่
            </button>
        </form>
    </div>

    <!-- ตารางรายชื่อแผนก -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-list"></i> รายชื่อแผนกทั้งหมด</h2>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>รหัส</th>
                        <th>ชื่อแผนก</th>
                        <th>รายละเอียด</th>
                        <th>สถานะ</th>
                        <th style="text-align: right;">การจัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($departments)): ?>
                        <tr><td colspan="5" style="text-align:center;">ยังไม่มีข้อมูลแผนก</td></tr>
                    <?php else: ?>
                        <?php foreach ($departments as $d): ?>
                        <tr>
                            <td><strong style="color: var(--primary); font-size: 1.05rem;">[<?= htmlspecialchars($d['department_code']) ?>]</strong></td>
                            <td><strong><?= htmlspecialchars($d['department_name']) ?></strong></td>
                            <td><small style="color: var(--text-muted);"><?= htmlspecialchars($d['description'] ?: '-') ?></small></td>
                            <td>
                                <?php if ($d['is_active']): ?>
                                    <span class="badge badge-completed"><i class="fa-solid fa-check"></i> เปิดใช้งาน</span>
                                <?php else: ?>
                                    <span class="badge badge-cancelled"><i class="fa-solid fa-ban"></i> ปิดใช้งาน</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="queues.php?dept=<?= $d['department_code'] ?>" class="btn btn-outline btn-sm" title="ดูคิวแผนกนี้">
                                    <i class="fa-solid fa-bullhorn"></i> คิว
                                </a>
                                <a href="departments.php?toggle_id=<?= $d['id'] ?>" class="btn btn-secondary btn-sm" onclick="return confirm('ยืนยันการเปลี่ยนสถานะแผนกนี้?');">
                                    <?= $d['is_active'] ? 'ปิดการใช้' : 'เปิดการใช้' ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
