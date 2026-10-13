<?php
// visits.php - ออกบัตรคิวและลงทะเบียนเข้ารับการตรวจ
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$page_title = 'ออกคิวเข้ารับการตรวจ';

$patient_id = (int)($_GET['patient_id'] ?? 0);
$selected_patient = null;

if ($patient_id > 0) {
    $p_stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
    $p_stmt->execute([$patient_id]);
    $selected_patient = $p_stmt->fetch();
}

// รายชื่อแผนก
$departments = $pdo->query("SELECT * FROM departments WHERE is_active = 1 ORDER BY department_name ASC")->fetchAll();

// รายชื่อผู้ป่วยสำหรับ Dropdown หรือ Autocomplete
$all_patients = $pdo->query("SELECT id, hn, national_id, first_name, last_name FROM patients ORDER BY id DESC LIMIT 50")->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patient_id    = (int)($_POST['patient_id'] ?? 0);
    $department_id = (int)($_POST['department_id'] ?? 0);

    if (empty($patient_id)) {
        $errors[] = 'กรุณาเลือกผู้ป่วยที่ต้องการออกคิว';
    }
    if (empty($department_id)) {
        $errors[] = 'กรุณาเลือกแผนกตรวจ';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // ตรวจสอบว่าผู้ป่วยมีคิวรอตรวจในแผนกนี้วันนี้อยู่แล้วหรือไม่ (เพื่อแจ้งเตือนหรือป้องกันคิวซ้ำซ้อน)
            $today = date('Y-m-d');
            $check_stmt = $pdo->prepare("
                SELECT q.queue_number, q.status 
                FROM queues q
                JOIN visits v ON q.visit_id = v.id
                WHERE v.patient_id = ? AND q.department_id = ? AND DATE(q.created_at) = ? AND q.status IN ('waiting', 'called', 'serving')
            ");
            $check_stmt->execute([$patient_id, $department_id, $today]);
            $existing = $check_stmt->fetch();

            if ($existing) {
                // แจ้งเตือนแต่ยังอนุญาตถ้ากดยืนยัน หรือจะสร้างใหม่
                // ออกคิวต่อได้ตามปกติ
            }

            // 1. สร้าง Visit
            $staff_id = $_SESSION['staff_id'];
            $visit_stmt = $pdo->prepare("
                INSERT INTO visits (patient_id, department_id, staff_id, visit_date, status)
                VALUES (?, ?, ?, NOW(), 'registered')
            ");
            $visit_stmt->execute([$patient_id, $department_id, $staff_id]);
            $visit_id = $pdo->lastInsertId();

            // 2. สร้าง Queue
            $queue_info = generate_queue_number($pdo, $department_id);
            $queue_stmt = $pdo->prepare("
                INSERT INTO queues (visit_id, department_id, queue_number, queue_sequence, status)
                VALUES (?, ?, ?, ?, 'waiting')
            ");
            $queue_stmt->execute([
                $visit_id,
                $department_id,
                $queue_info['queue_number'],
                $queue_info['queue_sequence']
            ]);
            $queue_id = $pdo->lastInsertId();

            $pdo->commit();
            set_flash('success', "ออกคิวสำเร็จ: หมายเลขคิว {$queue_info['queue_number']}");
            header("Location: print_queue.php?queue_id={$queue_id}");
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'เกิดข้อผิดพลาดในการออกคิว: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="fa-solid fa-ticket"></i> ออกบัตรคิวตรวจ (Issue Queue)</h1>
        <p class="page-subtitle">ลงทะเบียนการมาตรวจและสร้างลำดับคิวประจำวันสำหรับผู้ป่วย</p>
    </div>
    <div>
        <a href="queues.php" class="btn btn-outline">
            <i class="fa-solid fa-bullhorn"></i> ไปยังหน้าเรียกคิว
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

<div class="card" style="max-width: 800px; margin: 0 auto;">
    <form method="POST" action="visits.php">
        
        <!-- เลือกผู้ป่วย -->
        <div class="form-group">
            <label for="patient_id">
                <i class="fa-solid fa-user"></i> เลือกผู้ป่วย <span class="required">*</span>
            </label>
            
            <?php if ($selected_patient): ?>
                <div style="background: var(--primary-light); border: 1px solid #bae6fd; border-radius: var(--radius-md); padding: 14px 18px; display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <input type="hidden" name="patient_id" value="<?= $selected_patient['id'] ?>">
                        <div style="font-weight: 700; font-size: 1.05rem; color: var(--primary-dark);">
                            <?= htmlspecialchars($selected_patient['first_name'] . ' ' . $selected_patient['last_name']) ?>
                        </div>
                        <div style="font-size: 0.88rem; color: #0369a1; margin-top: 2px;">
                            HN: <strong><?= htmlspecialchars($selected_patient['hn']) ?></strong> | บัตร ปชช.: <?= htmlspecialchars($selected_patient['national_id']) ?> | โทร: <?= htmlspecialchars($selected_patient['phone_number'] ?: '-') ?>
                        </div>
                    </div>
                    <a href="visits.php" class="btn btn-outline btn-sm" style="background:#fff;">เปลี่ยนคน</a>
                </div>
            <?php else: ?>
                <select id="patient_id" name="patient_id" required style="font-size: 1rem; padding: 12px;">
                    <option value="">-- ค้นหาหรือเลือกผู้ป่วย --</option>
                    <?php foreach ($all_patients as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= ($patient_id === (int)$p['id']) ? 'selected' : '' ?>>
                            [HN: <?= htmlspecialchars($p['hn']) ?>] <?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) ?> (ปชช: <?= htmlspecialchars($p['national_id']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <div style="margin-top: 8px; display: flex; justify-content: space-between; align-items: center;">
                    <small style="color: var(--text-muted);">ไม่พบผู้ป่วยในตัวเลือก?</small>
                    <a href="patient_add.php" class="btn btn-outline btn-sm">
                        <i class="fa-solid fa-user-plus"></i> ลงทะเบียนผู้ป่วยใหม่
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- เลือกแผนกตรวจ -->
        <div class="form-group" style="margin-top: 24px;">
            <label for="department_id">
                <i class="fa-solid fa-stethoscope"></i> เลือกแผนกตรวจ / คลินิก <span class="required">*</span>
            </label>
            <select id="department_id" name="department_id" required style="font-size: 1.05rem; padding: 12px; font-weight: 500;">
                <option value="">-- กรุณาเลือกแผนกที่ต้องการส่งตรวจ --</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>">
                        [<?= htmlspecialchars($d['department_code']) ?>] <?= htmlspecialchars($d['department_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 16px; margin: 24px 0;">
            <div style="display: flex; align-items: center; gap: 10px; color: #475569; font-size: 0.9rem;">
                <i class="fa-solid fa-circle-info" style="color: var(--primary); font-size: 1.2rem;"></i>
                <span>เมื่อกด <strong>"ออกบัตรคิว"</strong> ระบบจะรันหมายเลขคิวประจำวันและนำท่านไปยังหน้าพิมพ์บัตรคิวความร้อนทันที</span>
            </div>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 12px;">
            <a href="index.php" class="btn btn-outline">ยกเลิก</a>
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fa-solid fa-print"></i> ออกบัตรคิวและพิมพ์ (Print Ticket)
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
