<?php
// staff.php - จัดการข้อมูลแพทย์และเจ้าหน้าที่ในระบบ (เฉพาะผู้ดูแลระบบ)
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_admin();

$page_title = 'จัดการแพทย์และเจ้าหน้าที่';

$errors = [];

// จัดการเพิ่ม หรือ แก้ไขเจ้าหน้าที่
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action        = $_POST['action'];
    $staff_id      = (int)($_POST['staff_id'] ?? 0);
    $username      = trim($_POST['username'] ?? '');
    $password      = trim($_POST['password'] ?? '');
    $first_name    = trim($_POST['first_name'] ?? '');
    $last_name     = trim($_POST['last_name'] ?? '');
    $role          = trim($_POST['role'] ?? 'เจ้าหน้าที่ลงทะเบียน');
    $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
    $room_id       = !empty($_POST['room_id']) ? (int)$_POST['room_id'] : null;
    $phone         = trim($_POST['phone_number'] ?? '');

    if ($action === 'add') {
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
                INSERT INTO staff (username, password_hash, first_name, last_name, role, department_id, room_id, phone_number, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $insert->execute([$username, $hash, $first_name, $last_name, $role, $department_id, $room_id, $phone]);

            // ถ้ามีการกำหนดห้องให้แพทย์ อัปเดต doctor_id ในห้องด้วย
            if ($role === 'แพทย์' && $room_id) {
                $new_staff_id = $pdo->lastInsertId();
                $pdo->prepare("UPDATE rooms SET doctor_id = ? WHERE id = ?")->execute([$new_staff_id, $room_id]);
            }

            set_flash('success', "เพิ่มผู้ใช้งาน '{$username}' ({$role}) เรียบร้อยแล้ว");
            header('Location: staff.php');
            exit;
        }

    } elseif ($action === 'edit' && $staff_id > 0) {
        if (empty($first_name) || empty($last_name)) {
            $errors[] = 'กรุณาระบุชื่อและนามสกุลจริง';
        }

        if (empty($errors)) {
            if (!empty($password)) {
                if (strlen($password) < 4) {
                    $errors[] = 'รหัสผ่านต้องมีความยาวอย่างน้อย 4 ตัวอักษร';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $update = $pdo->prepare("
                        UPDATE staff 
                        SET password_hash = ?, first_name = ?, last_name = ?, role = ?, department_id = ?, room_id = ?, phone_number = ?
                        WHERE id = ?
                    ");
                    $update->execute([$hash, $first_name, $last_name, $role, $department_id, $room_id, $phone, $staff_id]);
                }
            } else {
                $update = $pdo->prepare("
                    UPDATE staff 
                    SET first_name = ?, last_name = ?, role = ?, department_id = ?, room_id = ?, phone_number = ?
                    WHERE id = ?
                ");
                $update->execute([$first_name, $last_name, $role, $department_id, $room_id, $phone, $staff_id]);
            }

            // จัดการ doctor_id ในห้อง
            if ($role === 'แพทย์' && $room_id) {
                $pdo->prepare("UPDATE rooms SET doctor_id = ? WHERE id = ?")->execute([$staff_id, $room_id]);
            }

            if (empty($errors)) {
                set_flash('success', "บันทึกการแก้ไขข้อมูลเจ้าหน้าที่เรียบร้อยแล้ว");
                header('Location: staff.php');
                exit;
            }
        }
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

// ดึงข้อมูลรายชื่อแผนกและห้องตรวจทั้งหมดสำหรับ Select
$departments = $pdo->query("SELECT * FROM departments WHERE is_active = 1 ORDER BY department_name ASC")->fetchAll();
$rooms = $pdo->query("
    SELECT r.*, d.department_name, d.department_code 
    FROM rooms r 
    JOIN departments d ON r.department_id = d.id 
    WHERE r.is_active = 1 
    ORDER BY d.department_name ASC, r.room_number ASC
")->fetchAll();

// จัดกลุ่มห้องตาม department_id เพื่อให้ JS ดึงง่าย
$rooms_by_dept = [];
foreach ($rooms as $r) {
    $rooms_by_dept[$r['department_id']][] = [
        'id' => $r['id'],
        'room_number' => $r['room_number'],
        'room_name' => $r['room_name']
    ];
}

// โหมดแก้ไข
$edit_staff = null;
if (isset($_GET['edit_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM staff WHERE id = ?");
    $stmt->execute([(int)$_GET['edit_id']]);
    $edit_staff = $stmt->fetch();
}

$staff_list = $pdo->query("
    SELECT 
        s.*,
        d.department_name,
        d.department_code,
        r.room_number,
        r.room_name
    FROM staff s
    LEFT JOIN departments d ON s.department_id = d.id
    LEFT JOIN rooms r ON s.room_id = r.id
    ORDER BY FIELD(s.role, 'ผู้ดูแลระบบ', 'แพทย์', 'เจ้าหน้าที่ลงทะเบียน', 'เภสัชกร'), s.id ASC
")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="fa-solid fa-user-doctor"></i> จัดการแพทย์และเจ้าหน้าที่</h1>
        <p class="page-subtitle">จัดการบุคลากร กำหนดบทบาทแพทย์ (Doctor) เจ้าหน้าที่ และระบุห้องตรวจประจำ</p>
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

<div class="split-layout">

    <!-- ฟอร์มเพิ่ม / แก้ไขเจ้าหน้าที่ -->
    <div class="card card-flush">
        <div class="card-header">
            <h2 class="card-title">
                <?php if ($edit_staff): ?>
                    <i class="fa-solid fa-user-pen"></i> แก้ไขข้อมูลผู้ใช้
                <?php else: ?>
                    <i class="fa-solid fa-user-plus"></i> เพิ่มบุคลากร / แพทย์ใหม่
                <?php endif; ?>
            </h2>
            <?php if ($edit_staff): ?>
                <a href="staff.php" class="btn btn-outline btn-sm">ยกเลิก</a>
            <?php endif; ?>
        </div>

        <form method="POST" action="staff.php">
            <input type="hidden" name="action" value="<?= $edit_staff ? 'edit' : 'add' ?>">
            <?php if ($edit_staff): ?>
                <input type="hidden" name="staff_id" value="<?= $edit_staff['id'] ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="username">ชื่อผู้ใช้ (Username) <?= $edit_staff ? '' : '<span class="required">*</span>' ?></label>
                <?php if ($edit_staff): ?>
                    <input type="text" id="username" value="<?= htmlspecialchars($edit_staff['username']) ?>" disabled class="input-disabled">
                    <small class="form-hint">ชื่อผู้ใช้ไม่สามารถเปลี่ยนแปลงได้</small>
                <?php else: ?>
                    <input type="text" id="username" name="username" placeholder="เช่น doctor_somchai" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="password">รหัสผ่าน <?= $edit_staff ? '<small class="form-hint-inline">(เว้นว่างหากไม่เปลี่ยน)</small>' : '<span class="required">*</span>' ?></label>
                <input type="password" id="password" name="password" placeholder="<?= $edit_staff ? 'ระบุเพื่อเปลี่ยนรหัสใหม่...' : 'กำหนดรหัสผ่าน...' ?>" <?= $edit_staff ? '' : 'required' ?>>
            </div>

            <div class="grid-2 form-name-row">
                <div class="form-group">
                    <label for="first_name">ชื่อจริง <span class="required">*</span></label>
                    <input type="text" id="first_name" name="first_name" placeholder="เช่น นพ.สมเกียรติ" required value="<?= htmlspecialchars($edit_staff['first_name'] ?? $_POST['first_name'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="last_name">นามสกุล <span class="required">*</span></label>
                    <input type="text" id="last_name" name="last_name" placeholder="ยินดีรักษา" required value="<?= htmlspecialchars($edit_staff['last_name'] ?? $_POST['last_name'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="role"><i class="fa-solid fa-id-badge"></i> บทบาทหน้าที่ (Role) <span class="required">*</span></label>
                <?php $current_role = $edit_staff['role'] ?? $_POST['role'] ?? 'เจ้าหน้าที่ลงทะเบียน'; ?>
                <select id="role" name="role" onchange="toggleDoctorFields(this.value)" required>
                    <option value="แพทย์" <?= $current_role === 'แพทย์' ? 'selected' : '' ?>>แพทย์ (Doctor)</option>
                    <option value="เจ้าหน้าที่ลงทะเบียน" <?= $current_role === 'เจ้าหน้าที่ลงทะเบียน' ? 'selected' : '' ?>>เจ้าหน้าที่ลงทะเบียน (Staff)</option>
                    <option value="เภสัชกร" <?= $current_role === 'เภสัชกร' ? 'selected' : '' ?>>เภสัชกร (Pharmacist)</option>
                    <option value="ผู้ดูแลระบบ" <?= $current_role === 'ผู้ดูแลระบบ' ? 'selected' : '' ?>>ผู้ดูแลระบบ (Admin)</option>
                </select>
            </div>

            <!-- ส่วนกำหนดแผนกและห้องตรวจ (สำหรับแพทย์ หรือเจ้าหน้าที่ประจำจุด) -->
            <div id="doctor-fields" class="assignment-box">
                <div class="assignment-box-title">
                    <i class="fa-solid fa-hospital"></i> ประจำแผนกและห้องตรวจ
                </div>

                <div class="form-group">
                    <label for="department_id" style="font-size: 0.85rem;">แผนกที่สังกัด</label>
                    <?php $selected_dept = $edit_staff['department_id'] ?? $_POST['department_id'] ?? ''; ?>
                    <select id="department_id" name="department_id" onchange="updateRoomDropdown(this.value)">
                        <option value="">-- ไม่ระบุแผนก --</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= ((string)$selected_dept === (string)$d['id']) ? 'selected' : '' ?>>
                                [<?= htmlspecialchars($d['department_code']) ?>] <?= htmlspecialchars($d['department_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label for="room_id" style="font-size: 0.85rem;">ห้องตรวจประจำ</label>
                    <?php $selected_room = $edit_staff['room_id'] ?? $_POST['room_id'] ?? ''; ?>
                    <select id="room_id" name="room_id">
                        <option value="">-- ไม่ระบุห้องตรวจ --</option>
                        <?php foreach ($rooms as $r): ?>
                            <option value="<?= $r['id'] ?>" data-dept="<?= $r['department_id'] ?>" <?= ((string)$selected_room === (string)$r['id']) ? 'selected' : '' ?>>
                                [<?= htmlspecialchars($r['department_code']) ?>] <?= htmlspecialchars($r['room_name']) ?> (ห้อง <?= htmlspecialchars($r['room_number']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="phone_number">เบอร์โทรศัพท์</label>
                <input type="tel" id="phone_number" name="phone_number" placeholder="08xxxxxxxx" value="<?= htmlspecialchars($edit_staff['phone_number'] ?? $_POST['phone_number'] ?? '') ?>">
            </div>

            <button type="submit" class="btn btn-primary btn-block">
                <i class="fa-solid fa-floppy-disk"></i> <?= $edit_staff ? 'บันทึกการแก้ไข' : 'บันทึกบุคลากรใหม่' ?>
            </button>
        </form>
    </div>

    <!-- ตารางรายชื่อเจ้าหน้าที่ -->
    <div class="card card-flush">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-users-gear"></i> รายชื่อแพทย์และเจ้าหน้าที่ (<?= count($staff_list) ?> ท่าน)</h2>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>ผู้ใช้งาน</th>
                        <th>ชื่อ - นามสกุล</th>
                        <th>บทบาท (Role)</th>
                        <th>แผนก / ห้องตรวจ</th>
                        <th>เบอร์โทร</th>
                        <th>สถานะ</th>
                        <th style="text-align: right;">การจัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staff_list as $s): ?>
                    <tr>
                        <td>
                            <code><?= htmlspecialchars($s['username']) ?></code>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></strong>
                        </td>
                        <td>
                            <?php if ($s['role'] === 'แพทย์'): ?>
                                <span class="badge badge-room">
                                    <i class="fa-solid fa-user-doctor"></i> แพทย์
                                </span>
                            <?php elseif ($s['role'] === 'ผู้ดูแลระบบ'): ?>
                                <span class="badge badge-called">
                                    <i class="fa-solid fa-shield-halved"></i> ผู้ดูแลระบบ
                                </span>
                            <?php elseif ($s['role'] === 'เภสัชกร'): ?>
                                <span class="badge badge-rx">
                                    <i class="fa-solid fa-pills"></i> เภสัชกร
                                </span>
                            <?php else: ?>
                                <span class="badge badge-secondary">
                                    <i class="fa-regular fa-id-badge"></i> เจ้าหน้าที่
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($s['department_name']): ?>
                                <div class="dept-line">
                                    [<?= htmlspecialchars($s['department_code']) ?>] <?= htmlspecialchars($s['department_name']) ?>
                                </div>
                                <?php if ($s['room_name']): ?>
                                    <div class="room-line">
                                        <i class="fa-solid fa-door-open"></i> <?= htmlspecialchars($s['room_name']) ?> (<?= htmlspecialchars($s['room_number']) ?>)
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="muted-sm">-</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($s['phone_number'] ?: '-') ?></td>
                        <td>
                            <?php if ($s['is_active']): ?>
                                <span class="badge badge-completed">ใช้งาน</span>
                            <?php else: ?>
                                <span class="badge badge-cancelled">ระงับ</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right; white-space: nowrap;">
                            <a href="staff.php?edit_id=<?= $s['id'] ?>" class="btn btn-outline btn-sm" title="แก้ไข">
                                <i class="fa-solid fa-pen-to-square"></i> แก้ไข
                            </a>

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

<script>
const roomsByDept = <?= json_encode($rooms_by_dept) ?>;

function updateRoomDropdown(deptId) {
    const roomSelect = document.getElementById('room_id');
    const currentValue = roomSelect.value;
    roomSelect.innerHTML = '<option value="">-- ไม่ระบุห้องตรวจ --</option>';

    if (!deptId || !roomsByDept[deptId]) {
        return;
    }

    roomsByDept[deptId].forEach(r => {
        const opt = document.createElement('option');
        opt.value = r.id;
        opt.textContent = `${r.room_name} (ห้อง ${r.room_number})`;
        if (String(r.id) === String(currentValue)) {
            opt.selected = true;
        }
        roomSelect.appendChild(opt);
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
