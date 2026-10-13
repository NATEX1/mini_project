<?php
// patient_edit.php - แก้ไขข้อมูลเวชระเบียนผู้ป่วย
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: patients.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
$stmt->execute([$id]);
$patient = $stmt->fetch();

if (!$patient) {
    set_flash('danger', 'ไม่พบข้อมูลผู้ป่วย');
    header('Location: patients.php');
    exit;
}

$page_title = 'แก้ไขข้อมูลผู้ป่วย: ' . $patient['first_name'] . ' ' . $patient['last_name'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hn            = trim($_POST['hn'] ?? '');
    $national_id   = trim($_POST['national_id'] ?? '');
    $first_name    = trim($_POST['first_name'] ?? '');
    $last_name     = trim($_POST['last_name'] ?? '');
    $gender        = trim($_POST['gender'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '') ?: null;
    $phone_number  = trim($_POST['phone_number'] ?? '');
    $address       = trim($_POST['address'] ?? '');

    // Validation
    if (empty($hn)) {
        $errors[] = 'กรุณาระบุเลข HN';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM patients WHERE hn = ? AND id != ?");
        $stmt->execute([$hn, $id]);
        if ($stmt->fetch()) {
            $errors[] = "หมายเลข HN '{$hn}' ซ้ำกับผู้ป่วยรายอื่น";
        }
    }

    if (empty($national_id)) {
        $errors[] = 'กรุณาระบุเลขประจำตัวประชาชน';
    } elseif (!preg_match('/^[0-9]{13}$/', $national_id)) {
        $errors[] = 'เลขประจำตัวประชาชนต้องเป็นตัวเลข 13 หลัก';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM patients WHERE national_id = ? AND id != ?");
        $stmt->execute([$national_id, $id]);
        if ($stmt->fetch()) {
            $errors[] = "เลขประจำตัวประชาชน '{$national_id}' ซ้ำกับผู้ป่วยรายอื่น";
        }
    }

    if (empty($first_name)) {
        $errors[] = 'กรุณาระบุชื่อจริง';
    }
    if (empty($last_name)) {
        $errors[] = 'กรุณาระบุนามสกุล';
    }

    if (empty($errors)) {
        try {
            $update_stmt = $pdo->prepare("
                UPDATE patients 
                SET hn = ?, national_id = ?, first_name = ?, last_name = ?, 
                    gender = ?, date_of_birth = ?, phone_number = ?, address = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $update_stmt->execute([$hn, $national_id, $first_name, $last_name, $gender, $date_of_birth, $phone_number, $address, $id]);

            set_flash('success', 'บันทึกการแก้ไขข้อมูลผู้ป่วยเรียบร้อยแล้ว');
            header("Location: patient_detail.php?id={$id}");
            exit;
        } catch (Exception $e) {
            $errors[] = 'เกิดข้อผิดพลาดในการบันทึก: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="fa-solid fa-pen-to-square"></i> แก้ไขข้อมูลผู้ป่วย</h1>
        <p class="page-subtitle">HN: <?= htmlspecialchars($patient['hn']) ?> - <?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?></p>
    </div>
    <div>
        <a href="patient_detail.php?id=<?= $id ?>" class="btn btn-outline">
            <i class="fa-solid fa-arrow-left"></i> กลับหน้ารายละเอียด
        </a>
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

<div class="card" style="max-width: 900px; margin: 0 auto;">
    <form method="POST" action="patient_edit.php?id=<?= $id ?>">
        
        <div class="form-row">
            <div class="form-group">
                <label for="hn">หมายเลข HN <span class="required">*</span></label>
                <input type="text" id="hn" name="hn" value="<?= htmlspecialchars($_POST['hn'] ?? $patient['hn']) ?>" required>
            </div>

            <div class="form-group">
                <label for="national_id">เลขบัตรประจำตัวประชาชน (13 หลัก) <span class="required">*</span></label>
                <input type="text" id="national_id" name="national_id" maxlength="13" pattern="[0-9]{13}" value="<?= htmlspecialchars($_POST['national_id'] ?? $patient['national_id']) ?>" required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="first_name">ชื่อจริง <span class="required">*</span></label>
                <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($_POST['first_name'] ?? $patient['first_name']) ?>" required>
            </div>

            <div class="form-group">
                <label for="last_name">นามสกุล <span class="required">*</span></label>
                <input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($_POST['last_name'] ?? $patient['last_name']) ?>" required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="gender">เพศ</label>
                <select id="gender" name="gender">
                    <option value="">-- เลือกเพศ --</option>
                    <option value="ชาย" <?= (($_POST['gender'] ?? $patient['gender']) === 'ชาย') ? 'selected' : '' ?>>ชาย</option>
                    <option value="หญิง" <?= (($_POST['gender'] ?? $patient['gender']) === 'หญิง') ? 'selected' : '' ?>>หญิง</option>
                    <option value="อื่นๆ" <?= (($_POST['gender'] ?? $patient['gender']) === 'อื่นๆ') ? 'selected' : '' ?>>อื่นๆ</option>
                </select>
            </div>

            <div class="form-group">
                <label for="date_of_birth">วัน/เดือน/ปี เกิด</label>
                <input type="date" id="date_of_birth" name="date_of_birth" value="<?= htmlspecialchars($_POST['date_of_birth'] ?? $patient['date_of_birth']) ?>">
            </div>

            <div class="form-group">
                <label for="phone_number">เบอร์โทรศัพท์ติดต่อ</label>
                <input type="tel" id="phone_number" name="phone_number" value="<?= htmlspecialchars($_POST['phone_number'] ?? $patient['phone_number']) ?>">
            </div>
        </div>

        <div class="form-group">
            <label for="address">ที่อยู่ปัจจุบัน</label>
            <textarea id="address" name="address" rows="3"><?= htmlspecialchars($_POST['address'] ?? $patient['address']) ?></textarea>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px;">
            <a href="patient_detail.php?id=<?= $id ?>" class="btn btn-outline">ยกเลิก</a>
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fa-solid fa-floppy-disk"></i> บันทึกการเปลี่ยนแปลง
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
