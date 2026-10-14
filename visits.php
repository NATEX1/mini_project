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

// รายชื่อห้องตรวจทั้งหมด
$rooms = $pdo->query("
    SELECT r.*, d.department_name, d.department_code, s.first_name as doc_fname, s.last_name as doc_lname 
    FROM rooms r 
    JOIN departments d ON r.department_id = d.id 
    LEFT JOIN staff s ON r.doctor_id = s.id 
    WHERE r.is_active = 1 
    ORDER BY r.room_number ASC
")->fetchAll();

$rooms_by_dept = [];
foreach ($rooms as $r) {
    $rooms_by_dept[$r['department_id']][] = [
        'id' => $r['id'],
        'room_number' => $r['room_number'],
        'room_name' => $r['room_name'],
        'doctor' => $r['doc_fname'] ? ($r['doc_fname'] . ' ' . $r['doc_lname']) : ''
    ];
}

// รายชื่อผู้ป่วยสำหรับ Dropdown หรือ Autocomplete
$all_patients = $pdo->query("SELECT id, hn, national_id, first_name, last_name FROM patients ORDER BY id DESC LIMIT 50")->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patient_id    = (int)($_POST['patient_id'] ?? 0);
    $department_id = (int)($_POST['department_id'] ?? 0);
    $room_id       = !empty($_POST['room_id']) ? (int)$_POST['room_id'] : null;

    if (empty($patient_id)) {
        $errors[] = 'กรุณาเลือกผู้ป่วยที่ต้องการออกคิว';
    }
    if (empty($department_id)) {
        $errors[] = 'กรุณาเลือกแผนกตรวจ';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // หาห้องแรกอัตโนมัติถ้าไม่ได้เลือกห้อง
            if (!$room_id && $department_id > 0) {
                $find_room = $pdo->prepare("SELECT id FROM rooms WHERE department_id = ? AND is_active = 1 ORDER BY id ASC LIMIT 1");
                $find_room->execute([$department_id]);
                $room_id = $find_room->fetchColumn() ?: null;
            }

            // 1. สร้าง Visit
            $staff_id = $_SESSION['staff_id'];
            $visit_stmt = $pdo->prepare("
                INSERT INTO visits (patient_id, department_id, room_id, staff_id, visit_date, status)
                VALUES (?, ?, ?, ?, NOW(), 'registered')
            ");
            $visit_stmt->execute([$patient_id, $department_id, $room_id, $staff_id]);
            $visit_id = $pdo->lastInsertId();

            // 2. สร้าง Queue
            $queue_info = generate_queue_number($pdo, $department_id);
            $queue_stmt = $pdo->prepare("
                INSERT INTO queues (visit_id, department_id, room_id, queue_number, queue_sequence, status)
                VALUES (?, ?, ?, ?, ?, 'waiting')
            ");
            $queue_stmt->execute([
                $visit_id,
                $department_id,
                $room_id,
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

<div class="card card-narrow">
    <form method="POST" action="visits.php">
        
        <!-- เลือกผู้ป่วย -->
        <div class="form-group">
            <label for="patient_id">
                <i class="fa-solid fa-user"></i> เลือกผู้ป่วย <span class="required">*</span>
            </label>
            
            <?php if ($selected_patient): ?>
                <div class="selected-patient">
                    <div>
                        <input type="hidden" name="patient_id" value="<?= $selected_patient['id'] ?>">
                        <div class="selected-patient-name">
                            <?= htmlspecialchars($selected_patient['first_name'] . ' ' . $selected_patient['last_name']) ?>
                        </div>
                        <div class="selected-patient-meta">
                            HN: <strong><?= htmlspecialchars($selected_patient['hn']) ?></strong> | บัตร ปชช.: <?= htmlspecialchars($selected_patient['national_id']) ?> | โทร: <?= htmlspecialchars($selected_patient['phone_number'] ?: '-') ?>
                        </div>
                    </div>
                    <a href="visits.php" class="btn btn-outline btn-sm">เปลี่ยนคน</a>
                </div>
            <?php else: ?>
                <select id="patient_id" name="patient_id" required class="select-lg">
                    <option value="">-- ค้นหาหรือเลือกผู้ป่วย --</option>
                    <?php foreach ($all_patients as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= ($patient_id === (int)$p['id']) ? 'selected' : '' ?>>
                            [HN: <?= htmlspecialchars($p['hn']) ?>] <?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) ?> (ปชช: <?= htmlspecialchars($p['national_id']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-foot">
                    <small class="muted-sm">ไม่พบผู้ป่วยในตัวเลือก?</small>
                    <a href="patient_add.php" class="btn btn-outline btn-sm">
                        <i class="fa-solid fa-user-plus"></i> ลงทะเบียนผู้ป่วยใหม่
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- เลือกแผนกตรวจ และห้องตรวจ -->
        <div class="grid-2 visit-grid">
            <div class="form-group form-group-flush">
                <label for="department_id">
                    <i class="fa-solid fa-stethoscope"></i> เลือกแผนกตรวจ / คลินิก <span class="required">*</span>
                </label>
                <select id="department_id" name="department_id" required class="select-lg" onchange="updateRoomOptions(this.value)">
                    <option value="">-- กรุณาเลือกแผนก --</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>">
                            [<?= htmlspecialchars($d['department_code']) ?>] <?= htmlspecialchars($d['department_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group form-group-flush">
                <label for="room_id">
                    <i class="fa-solid fa-door-open"></i> ห้องตรวจที่ระบุในบัตรคิว
                </label>
                <select id="room_id" name="room_id" class="select-lg">
                    <option value="">-- เลือกห้องตรวจ (หรือจัดห้องแรกอัตโนมัติ) --</option>
                </select>
            </div>
        </div>

        <div class="info-note">
            <i class="fa-solid fa-circle-info"></i>
            <span>เมื่อกด <strong>"ออกบัตรคิว"</strong> ระบบจะรันหมายเลขคิว ระบุห้องตรวจที่คนไข้ต้องไป และพิมพ์บัตรคิวความร้อนทันที</span>
        </div>

        <div class="form-actions-end">
            <a href="index.php" class="btn btn-outline">ยกเลิก</a>
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fa-solid fa-print"></i> ออกบัตรคิวและพิมพ์
            </button>
        </div>
    </form>
</div>

<script>
    const roomsByDept = <?= json_encode($rooms_by_dept) ?>;
    const roomSelect = document.getElementById('room_id');

    function updateRoomOptions(deptId) {
        roomSelect.innerHTML = '<option value="">-- เลือกห้องตรวจ (หรือจัดห้องแรกอัตโนมัติ) --</option>';
        if (!deptId || !roomsByDept[deptId]) return;

        roomsByDept[deptId].forEach((r, idx) => {
            const opt = document.createElement('option');
            opt.value = r.id;
            opt.textContent = `${r.room_name} (ห้อง ${r.room_number})${r.doctor ? ' - ' + r.doctor : ''}`;
            if (idx === 0) opt.selected = true;
            roomSelect.appendChild(opt);
        });
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
