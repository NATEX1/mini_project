<?php
// patient_add.php - ลงทะเบียนผู้ป่วยใหม่
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$page_title = 'ลงทะเบียนผู้ป่วยใหม่';

$departments = $pdo->query("SELECT * FROM departments WHERE is_active = 1 ORDER BY department_name ASC")->fetchAll();

$errors = [];
$default_hn = generate_hn($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hn            = trim($_POST['hn'] ?? '');
    $national_id   = trim($_POST['national_id'] ?? '');
    $first_name    = trim($_POST['first_name'] ?? '');
    $last_name     = trim($_POST['last_name'] ?? '');
    $gender        = trim($_POST['gender'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '') ?: null;
    $phone_number  = trim($_POST['phone_number'] ?? '');
    $address       = trim($_POST['address'] ?? '');
    
    $issue_queue   = isset($_POST['issue_queue']) && $_POST['issue_queue'] === '1';
    $department_id = (int)($_POST['department_id'] ?? 0);

    // Validation
    if (empty($hn)) {
        $errors[] = 'กรุณาระบุเลข HN';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM patients WHERE hn = ?");
        $stmt->execute([$hn]);
        if ($stmt->fetch()) {
            $errors[] = "หมายเลข HN '{$hn}' มีอยู่ในระบบแล้ว";
        }
    }

    if (empty($national_id)) {
        $errors[] = 'กรุณาระบุเลขประจำตัวประชาชน';
    } elseif (!preg_match('/^[0-9]{13}$/', $national_id)) {
        $errors[] = 'เลขประจำตัวประชาชนต้องเป็นตัวเลข 13 หลัก';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM patients WHERE national_id = ?");
        $stmt->execute([$national_id]);
        if ($stmt->fetch()) {
            $errors[] = "เลขประจำตัวประชาชน '{$national_id}' มีอยู่ในระบบแล้ว";
        }
    }

    if (empty($first_name)) {
        $errors[] = 'กรุณาระบุชื่อจริง';
    }
    if (empty($last_name)) {
        $errors[] = 'กรุณาระบุนามสกุล';
    }

    if ($issue_queue && empty($department_id)) {
        $errors[] = 'กรุณาเลือกแผนกตรวจที่ต้องการออกคิว';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // 1. เพิ่มผู้ป่วย
            $stmt = $pdo->prepare("
                INSERT INTO patients (hn, national_id, first_name, last_name, gender, date_of_birth, phone_number, address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$hn, $national_id, $first_name, $last_name, $gender, $date_of_birth, $phone_number, $address]);
            $patient_id = $pdo->lastInsertId();

            // 2. ถ้าเลือกออกคิวตรวจทันที
            if ($issue_queue && $department_id > 0) {
                $staff_id = $_SESSION['staff_id'];

                // สร้าง Visit
                $visit_stmt = $pdo->prepare("
                    INSERT INTO visits (patient_id, department_id, staff_id, visit_date, status)
                    VALUES (?, ?, ?, NOW(), 'registered')
                ");
                $visit_stmt->execute([$patient_id, $department_id, $staff_id]);
                $visit_id = $pdo->lastInsertId();

                // สร้าง Queue
                $queue_info = generate_queue_number($pdo, $department_id);
                $queue_stmt = $pdo->prepare("
                    INSERT INTO queues (visit_id, department_id, queue_number, queue_sequence, status)
                    VALUES (?, ?, ?, ?, 'waiting')
                ");
                $queue_stmt->execute([$visit_id, $department_id, $queue_info['queue_number'], $queue_info['queue_sequence']]);
                $queue_id = $pdo->lastInsertId();

                $pdo->commit();
                set_flash('success', "ลงทะเบียนผู้ป่วยและออกคิว {$queue_info['queue_number']} เรียบร้อยแล้ว");
                header("Location: print_queue.php?queue_id={$queue_id}");
                exit;
            } else {
                $pdo->commit();
                set_flash('success', "ลงทะเบียนผู้ป่วย HN: {$hn} เรียบร้อยแล้ว");
                header("Location: patient_detail.php?id={$patient_id}");
                exit;
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'เกิดข้อผิดพลาดในการบันทึก: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="fa-solid fa-user-plus"></i> ลงทะเบียนผู้ป่วยใหม่</h1>
        <p class="page-subtitle">กรอกข้อมูลผู้ป่วยเข้าสู่ระบบเวชระเบียนโรงพยาบาล</p>
    </div>
    <div>
        <a href="patients.php" class="btn btn-outline">
            <i class="fa-solid fa-arrow-left"></i> กลับหน้ารายชื่อ
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
    <form method="POST" action="patient_add.php">
        
        <div style="border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 20px;">
            <h3 style="font-size: 1.1rem; color: var(--primary-dark);"><i class="fa-solid fa-id-card"></i> ข้อมูลประจำตัว</h3>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="hn">หมายเลข HN <span class="required">*</span></label>
                <input type="text" id="hn" name="hn" value="<?= htmlspecialchars($_POST['hn'] ?? $default_hn) ?>" required>
                <small style="color: var(--text-muted);">ระบบสร้างเลขอัตโนมัติ สามารถแก้ไขได้</small>
            </div>

            <div class="form-group">
                <label for="national_id">เลขบัตรประจำตัวประชาชน (13 หลัก) <span class="required">*</span></label>
                <input type="text" id="national_id" name="national_id" maxlength="13" pattern="[0-9]{13}" value="<?= htmlspecialchars($_POST['national_id'] ?? '') ?>" placeholder="1100200300405" required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="first_name">ชื่อจริง <span class="required">*</span></label>
                <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" placeholder="สมชาย" required>
            </div>

            <div class="form-group">
                <label for="last_name">นามสกุล <span class="required">*</span></label>
                <input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" placeholder="สุขใจ" required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="gender">เพศ</label>
                <select id="gender" name="gender">
                    <option value="">-- เลือกเพศ --</option>
                    <option value="ชาย" <?= (($_POST['gender'] ?? '') === 'ชาย') ? 'selected' : '' ?>>ชาย</option>
                    <option value="หญิง" <?= (($_POST['gender'] ?? '') === 'หญิง') ? 'selected' : '' ?>>หญิง</option>
                    <option value="อื่นๆ" <?= (($_POST['gender'] ?? '') === 'อื่นๆ') ? 'selected' : '' ?>>อื่นๆ</option>
                </select>
            </div>

            <div class="form-group">
                <label for="date_of_birth">วัน/เดือน/ปี เกิด</label>
                <input type="date" id="date_of_birth" name="date_of_birth" value="<?= htmlspecialchars($_POST['date_of_birth'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="phone_number">เบอร์โทรศัพท์ติดต่อ</label>
                <input type="tel" id="phone_number" name="phone_number" value="<?= htmlspecialchars($_POST['phone_number'] ?? '') ?>" placeholder="0812345678">
            </div>
        </div>

        <div class="form-group">
            <label for="address">ที่อยู่ปัจจุบัน</label>
            <textarea id="address" name="address" rows="3" placeholder="เลขที่ หมู่บ้าน ถนน แขวง/ตำบล เขต/อำเภอ จังหวัด รหัสไปรษณีย์"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
        </div>

        <!-- ตัวเลือกออกบัตรคิวตรวจทันที -->
        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: var(--radius-md); padding: 18px; margin: 24px 0;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                <input type="checkbox" id="issue_queue" name="issue_queue" value="1" style="width: 18px; height: 18px; cursor: pointer;" <?= isset($_POST['issue_queue']) ? 'checked' : 'checked' ?>>
                <label for="issue_queue" style="margin: 0; font-weight: 600; color: #166534; cursor: pointer; font-size: 1rem;">
                    <i class="fa-solid fa-ticket"></i> ลงทะเบียนเสร็จแล้ว ออกบัตรคิวตรวจทันที (One-Stop Service)
                </label>
            </div>
            
            <div id="dept_select_box" style="padding-left: 28px;">
                <label for="department_id" style="color: #166534;">เลือกแผนกที่ต้องการส่งตรวจ:</label>
                <select id="department_id" name="department_id" style="max-width: 400px; border-color: #86efac;">
                    <option value="">-- เลือกแผนก / คลินิก --</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= ((int)($_POST['department_id'] ?? 0) === (int)$d['id']) ? 'selected' : '' ?>>
                            [<?= htmlspecialchars($d['department_code']) ?>] <?= htmlspecialchars($d['department_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px;">
            <a href="patients.php" class="btn btn-outline">ยกเลิก</a>
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fa-solid fa-floppy-disk"></i> บันทึกข้อมูลผู้ป่วย
            </button>
        </div>

    </form>
</div>

<script>
    const chkIssueQueue = document.getElementById('issue_queue');
    const deptSelectBox = document.getElementById('dept_select_box');
    
    function toggleDeptBox() {
        if (chkIssueQueue.checked) {
            deptSelectBox.style.display = 'block';
        } else {
            deptSelectBox.style.display = 'none';
        }
    }
    chkIssueQueue.addEventListener('change', toggleDeptBox);
    toggleDeptBox();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
