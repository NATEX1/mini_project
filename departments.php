<?php
// departments.php - จัดการแผนกตรวจ และห้องตรวจประจำแผนก (Rooms)
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$page_title = 'จัดการแผนกและห้องตรวจ';

$tab = $_GET['tab'] ?? 'rooms'; // default to rooms to highlight room management
$errors = [];

// จัดการ POST สำหรับแผนกและห้องตรวจ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. เพิ่มแผนกใหม่
    if ($action === 'add_department') {
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
            header('Location: departments.php?tab=departments');
            exit;
        }
    }

    // 2. เพิ่มห้องตรวจใหม่
    elseif ($action === 'add_room') {
        $department_id = (int)($_POST['department_id'] ?? 0);
        $room_number   = trim($_POST['room_number'] ?? '');
        $room_name     = trim($_POST['room_name'] ?? '');
        $doctor_id     = !empty($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : null;

        if (empty($department_id)) {
            $errors[] = 'กรุณาเลือกแผนกตรวจสำหรับห้องนี้';
        }
        if (empty($room_number)) {
            $errors[] = 'กรุณาระบุหมายเลขห้องตรวจ (เช่น 1, 101, 201)';
        }
        if (empty($room_name)) {
            $room_name = "ห้องตรวจ {$room_number}";
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("
                INSERT INTO rooms (department_id, room_number, room_name, doctor_id, is_active)
                VALUES (?, ?, ?, ?, 1)
            ");
            $stmt->execute([$department_id, $room_number, $room_name, $doctor_id]);
            set_flash('success', "เพิ่มห้อง '{$room_name}' ในแผนกเรียบร้อยแล้ว");
            header('Location: departments.php?tab=rooms');
            exit;
        }
    }

    // 3. แก้ไขห้องตรวจ
    elseif ($action === 'edit_room') {
        $room_id       = (int)($_POST['room_id'] ?? 0);
        $department_id = (int)($_POST['department_id'] ?? 0);
        $room_number   = trim($_POST['room_number'] ?? '');
        $room_name     = trim($_POST['room_name'] ?? '');
        $doctor_id     = !empty($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : null;

        if (empty($department_id) || empty($room_number)) {
            $errors[] = 'กรุณากรอกข้อมูลห้องให้ครบถ้วน';
        }

        if (empty($errors) && $room_id > 0) {
            $stmt = $pdo->prepare("
                UPDATE rooms 
                SET department_id = ?, room_number = ?, room_name = ?, doctor_id = ?
                WHERE id = ?
            ");
            $stmt->execute([$department_id, $room_number, $room_name, $doctor_id, $room_id]);
            set_flash('success', "อัปเดตข้อมูลห้องตรวจเรียบร้อยแล้ว");
            header('Location: departments.php?tab=rooms');
            exit;
        }
    }
}

// จัดการเปิด/ปิดการใช้งานแผนก
if (isset($_GET['toggle_dept'])) {
    $toggle_id = (int)$_GET['toggle_dept'];
    $stmt = $pdo->prepare("UPDATE departments SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?");
    $stmt->execute([$toggle_id]);
    set_flash('info', 'อัปเดตสถานะการใช้งานแผนกเรียบร้อย');
    header('Location: departments.php?tab=departments');
    exit;
}

// จัดการเปิด/ปิดการใช้งานห้องตรวจ
if (isset($_GET['toggle_room'])) {
    $toggle_id = (int)$_GET['toggle_room'];
    $stmt = $pdo->prepare("UPDATE rooms SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?");
    $stmt->execute([$toggle_id]);
    set_flash('info', 'อัปเดตสถานะการใช้งานห้องตรวจเรียบร้อย');
    header('Location: departments.php?tab=rooms');
    exit;
}

// ดึงข้อมูลสำหรับแสดงผล
$departments = $pdo->query("
    SELECT d.*, COUNT(r.id) as room_count
    FROM departments d
    LEFT JOIN rooms r ON d.id = r.department_id AND r.is_active = 1
    GROUP BY d.id
    ORDER BY d.is_active DESC, d.id ASC
")->fetchAll();

$doctors = $pdo->query("
    SELECT id, first_name, last_name, username 
    FROM staff 
    WHERE role = 'แพทย์' AND is_active = 1 
    ORDER BY first_name ASC
")->fetchAll();

$rooms = $pdo->query("
    SELECT 
        r.*, 
        d.department_name, 
        d.department_code,
        s.first_name as doc_fname,
        s.last_name as doc_lname
    FROM rooms r
    JOIN departments d ON r.department_id = d.id
    LEFT JOIN staff s ON r.doctor_id = s.id
    ORDER BY d.department_name ASC, r.room_number ASC
")->fetchAll();

// โหมดแก้ไขห้อง
$edit_room = null;
if (isset($_GET['edit_room_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
    $stmt->execute([(int)$_GET['edit_room_id']]);
    $edit_room = $stmt->fetch();
    $tab = 'rooms';
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="fa-solid fa-hospital"></i> แผนกตรวจและห้องตรวจ</h1>
        <p class="page-subtitle">จัดการข้อมูลแผนก กำหนดและแยกห้องตรวจของแต่ละแผนก พร้อมระบุแพทย์ประจำห้อง</p>
    </div>
    
    <div style="display: flex; gap: 8px;">
        <a href="departments.php?tab=rooms" class="btn <?= $tab === 'rooms' ? 'btn-primary' : 'btn-outline' ?>">
            <i class="fa-solid fa-door-open"></i> ห้องตรวจ (Rooms)
        </a>
        <a href="departments.php?tab=departments" class="btn <?= $tab === 'departments' ? 'btn-primary' : 'btn-outline' ?>">
            <i class="fa-solid fa-stethoscope"></i> แผนกคลินิก (Departments)
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

<?php if ($tab === 'rooms'): ?>
    <!-- แท็บจัดการห้องตรวจ (Rooms) -->
    <div style="display: grid; grid-template-columns: 360px 1fr; gap: 24px;">
        
        <!-- ฟอร์มเพิ่ม/แก้ไขห้องตรวจ -->
        <div class="card" style="margin-bottom: 0;">
            <div class="card-header">
                <h2 class="card-title">
                    <?php if ($edit_room): ?>
                        <i class="fa-solid fa-pen-to-square"></i> แก้ไขห้องตรวจ
                    <?php else: ?>
                        <i class="fa-solid fa-plus-circle"></i> เพิ่มห้องตรวจใหม่
                    <?php endif; ?>
                </h2>
                <?php if ($edit_room): ?>
                    <a href="departments.php?tab=rooms" class="btn btn-outline btn-sm">ยกเลิก</a>
                <?php endif; ?>
            </div>

            <form method="POST" action="departments.php?tab=rooms">
                <input type="hidden" name="action" value="<?= $edit_room ? 'edit_room' : 'add_room' ?>">
                <?php if ($edit_room): ?>
                    <input type="hidden" name="room_id" value="<?= $edit_room['id'] ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="department_id">แผนกตรวจ <span class="required">*</span></label>
                    <select id="department_id" name="department_id" required>
                        <option value="">-- เลือกแผนก --</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= (($edit_room['department_id'] ?? '') == $d['id']) ? 'selected' : '' ?>>
                                [<?= htmlspecialchars($d['department_code']) ?>] <?= htmlspecialchars($d['department_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="room_number">หมายเลขห้อง (Room No.) <span class="required">*</span></label>
                    <input type="text" id="room_number" name="room_number" placeholder="เช่น 1, 101, 202, ER-1" required value="<?= htmlspecialchars($edit_room['room_number'] ?? '') ?>">
                    <small style="color: var(--text-muted);">ใช้แสดงบนบัตรคิวและจอเรียก เช่น ห้อง 101</small>
                </div>

                <div class="form-group">
                    <label for="room_name">ชื่อห้องตรวจ <span class="required">*</span></label>
                    <input type="text" id="room_name" name="room_name" placeholder="เช่น ห้องตรวจอายุรกรรม 1" required value="<?= htmlspecialchars($edit_room['room_name'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="doctor_id"><i class="fa-solid fa-user-doctor"></i> แพทย์ประจำห้อง</label>
                    <select id="doctor_id" name="doctor_id">
                        <option value="">-- ไม่ระบุ / หมุนเวียน --</option>
                        <?php foreach ($doctors as $doc): ?>
                            <option value="<?= $doc['id'] ?>" <?= (($edit_room['doctor_id'] ?? '') == $doc['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']) ?> (<?= htmlspecialchars($doc['username']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fa-solid fa-floppy-disk"></i> <?= $edit_room ? 'บันทึกการแก้ไขห้อง' : 'บันทึกห้องตรวจใหม่' ?>
                </button>
            </form>
        </div>

        <!-- รายการห้องตรวจทั้งหมด -->
        <div class="card" style="margin-bottom: 0;">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fa-solid fa-door-open"></i> รายการห้องตรวจทั้งหมด (<?= count($rooms) ?> ห้อง)
                </h2>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ห้อง</th>
                            <th>ชื่อห้องตรวจ</th>
                            <th>แผนก</th>
                            <th>แพทย์ประจำห้อง</th>
                            <th>สถานะ</th>
                            <th style="text-align: right;">การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rooms)): ?>
                            <tr><td colspan="6" style="text-align: center; color: var(--text-muted);">ยังไม่มีห้องตรวจ</td></tr>
                        <?php else: ?>
                            <?php foreach ($rooms as $r): ?>
                            <tr>
                                <td>
                                    <span class="badge badge-room badge-room-lg">
                                        ห้อง <?= htmlspecialchars($r['room_number']) ?>
                                    </span>
                                </td>
                                <td><strong><?= htmlspecialchars($r['room_name']) ?></strong></td>
                                <td>
                                    <span class="dept-code">[<?= htmlspecialchars($r['department_code']) ?>]</span>
                                    <?= htmlspecialchars($r['department_name']) ?>
                                </td>
                                <td>
                                    <?php if ($r['doc_fname']): ?>
                                        <span class="dept-line">
                                            <i class="fa-solid fa-user-doctor"></i> <?= htmlspecialchars($r['doc_fname'] . ' ' . $r['doc_lname']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="muted-sm">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($r['is_active']): ?>
                                        <span class="badge badge-completed">เปิดตรวจ</span>
                                    <?php else: ?>
                                        <span class="badge badge-cancelled">ปิดชั่วคราว</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <a href="departments.php?tab=rooms&edit_room_id=<?= $r['id'] ?>" class="btn btn-outline btn-sm">
                                        <i class="fa-solid fa-pen-to-square"></i> แก้ไข
                                    </a>
                                    <a href="departments.php?toggle_room=<?= $r['id'] ?>" class="btn btn-secondary btn-sm" onclick="return confirm('เปลี่ยนสถานะห้องนี้?');">
                                        <?= $r['is_active'] ? 'ปิด' : 'เปิด' ?>
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

<?php else: ?>
    <!-- แท็บจัดการแผนกตรวจ (Departments) -->
    <div class="split-layout">

        <!-- ฟอร์มเพิ่มแผนกใหม่ -->
        <div class="card card-flush">
            <div class="card-header">
                <h2 class="card-title"><i class="fa-solid fa-plus-circle"></i> เพิ่มแผนกใหม่</h2>
            </div>

            <form method="POST" action="departments.php?tab=departments">
                <input type="hidden" name="action" value="add_department">

                <div class="form-group">
                    <label for="department_code">รหัสแผนก (Code) <span class="required">*</span></label>
                    <input type="text" id="department_code" name="department_code" placeholder="เช่น MED, SUR, EYE, ER" required class="input-uppercase">
                    <small class="form-hint">ใช้เป็นตัวอักษรนำหน้าบัตรคิว เช่น MED-001</small>
                </div>

                <div class="form-group">
                    <label for="department_name">ชื่อแผนก / คลินิก <span class="required">*</span></label>
                    <input type="text" id="department_name" name="department_name" placeholder="เช่น แผนกจักษุ (Eye Clinic)" required>
                </div>

                <div class="form-group">
                    <label for="description">รายละเอียด / อาคาร / ชั้น</label>
                    <textarea id="description" name="description" rows="3" placeholder="ระบุรายละเอียดหรือตำแหน่งห้องตรวจ..."></textarea>
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fa-solid fa-floppy-disk"></i> บันทึกแผนกใหม่
                </button>
            </form>
        </div>

        <!-- ตารางรายชื่อแผนก -->
        <div class="card card-flush">
            <div class="card-header">
                <h2 class="card-title"><i class="fa-solid fa-list"></i> รายชื่อแผนกทั้งหมด (<?= count($departments) ?> แผนก)</h2>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>รหัส</th>
                            <th>ชื่อแผนก</th>
                            <th>จำนวนห้องตรวจ</th>
                            <th>รายละเอียด</th>
                            <th>สถานะ</th>
                            <th style="text-align: right;">การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($departments as $d): ?>
                        <tr>
                            <td><strong style="color: var(--primary); font-size: 1.05rem;">[<?= htmlspecialchars($d['department_code']) ?>]</strong></td>
                            <td><strong><?= htmlspecialchars($d['department_name']) ?></strong></td>
                            <td>
                                <a href="departments.php?tab=rooms" class="badge badge-room" style="text-decoration: none;">
                                    <i class="fa-solid fa-door-open"></i> <?= $d['room_count'] ?> ห้อง
                                </a>
                            </td>
                            <td><small style="color: var(--text-muted);"><?= htmlspecialchars($d['description'] ?: '-') ?></small></td>
                            <td>
                                <?php if ($d['is_active']): ?>
                                    <span class="badge badge-completed"><i class="fa-solid fa-check"></i> เปิดใช้งาน</span>
                                <?php else: ?>
                                    <span class="badge badge-cancelled"><i class="fa-solid fa-ban"></i> ปิดใช้งาน</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <a href="queues.php?dept_id=<?= $d['id'] ?>" class="btn btn-outline btn-sm" title="ดูคิวแผนกนี้">
                                    <i class="fa-solid fa-bullhorn"></i> ดูคิว
                                </a>
                                <a href="departments.php?tab=departments&toggle_dept=<?= $d['id'] ?>" class="btn btn-secondary btn-sm" onclick="return confirm('ยืนยันการเปลี่ยนสถานะแผนกนี้?');">
                                    <?= $d['is_active'] ? 'ปิดการใช้' : 'เปิดการใช้' ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
