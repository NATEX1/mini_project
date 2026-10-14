<?php
// patients.php - หน้าจัดการและค้นหาทะเบียนผู้ป่วย
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$page_title = 'ทะเบียนผู้ป่วย';

$search = trim($_GET['search'] ?? '');
$gender_filter = trim($_GET['gender'] ?? '');

$query = "SELECT * FROM patients WHERE 1=1";
$params = [];

if ($search !== '') {
    $query .= " AND (hn LIKE ? OR national_id LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR phone_number LIKE ?)";
    $term = "%{$search}%";
    $params = array_merge($params, [$term, $term, $term, $term, $term]);
}

if ($gender_filter !== '') {
    $query .= " AND gender = ?";
    $params[] = $gender_filter;
}

$query .= " ORDER BY id DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$patients = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="fa-solid fa-hospital-user"></i> ทะเบียนประวัติผู้ป่วย</h1>
        <p class="page-subtitle">ค้นหา ตรวจสอบ และจัดการข้อมูลเวชระเบียนผู้ป่วย</p>
    </div>
    <div>
        <a href="patient_add.php" class="btn btn-primary">
            <i class="fa-solid fa-user-plus"></i> ลงทะเบียนผู้ป่วยใหม่
        </a>
    </div>
</div>

<div class="card">
    <form method="GET" action="patients.php" class="filter-form filter-form-tight">
        <div class="filter-field">
            <label for="search">ค้นหาด้วย HN, เลขบัตร ปชช., ชื่อ-นามสกุล, หรือเบอร์โทร</label>
            <input type="search" id="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="พิมพ์คำค้นหา...">
        </div>
        <div class="filter-field slim">
            <label for="gender">เพศ</label>
            <select id="gender" name="gender">
                <option value="">ทั้งหมด</option>
                <option value="ชาย" <?= $gender_filter === 'ชาย' ? 'selected' : '' ?>>ชาย</option>
                <option value="หญิง" <?= $gender_filter === 'หญิง' ? 'selected' : '' ?>>หญิง</option>
                <option value="อื่นๆ" <?= $gender_filter === 'อื่นๆ' ? 'selected' : '' ?>>อื่นๆ</option>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-magnifying-glass"></i> ค้นหา
            </button>
            <?php if ($search !== '' || $gender_filter !== ''): ?>
                <a href="patients.php" class="btn btn-outline" title="ล้างการค้นหา">
                    <i class="fa-solid fa-rotate-left"></i> ล้างค่า
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="fa-solid fa-users"></i> รายชื่อผู้ป่วย
            <span class="card-title-count">(พบ <?= count($patients) ?> รายการ)</span>
        </h2>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>HN</th>
                    <th>เลขบัตรประชาชน</th>
                    <th>ชื่อ - นามสกุล</th>
                    <th>เพศ</th>
                    <th>วันเกิด / อายุ</th>
                    <th>เบอร์โทรศัพท์</th>
                    <th>วันที่ลงทะเบียน</th>
                    <th style="text-align: right;">การจัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($patients)): ?>
                    <tr>
                        <td colspan="8" class="table-empty">
                            ไม่พบข้อมูลผู้ป่วยตามเงื่อนไขที่ค้นหา
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($patients as $p): ?>
                        <?php 
                        $age = '-';
                        if ($p['date_of_birth']) {
                            $dob = new DateTime($p['date_of_birth']);
                            $now = new DateTime();
                            $age = $now->diff($dob)->y . ' ปี';
                        }
                        ?>
                        <tr>
                            <td><strong class="hn-strong"><?= htmlspecialchars($p['hn']) ?></strong></td>
                            <td><code><?= htmlspecialchars($p['national_id']) ?></code></td>
                            <td>
                                <a href="patient_detail.php?id=<?= $p['id'] ?>" class="patient-link">
                                    <?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($p['gender'] ?: '-') ?></td>
                            <td>
                                <?= $p['date_of_birth'] ? thai_datetime($p['date_of_birth'], false) : '-' ?>
                                <small class="muted-block">(<?= $age ?>)</small>
                            </td>
                            <td><?= htmlspecialchars($p['phone_number'] ?: '-') ?></td>
                            <td><small><?= thai_datetime($p['created_at']) ?></small></td>
                            <td class="cell-actions">
                                <a href="visits.php?patient_id=<?= $p['id'] ?>" class="btn btn-success btn-sm" title="ออกคิวตรวจ">
                                    <i class="fa-solid fa-ticket"></i> ออกคิว
                                </a>
                                <a href="patient_detail.php?id=<?= $p['id'] ?>" class="btn btn-outline btn-sm" title="ประวัติการรักษา">
                                    <i class="fa-solid fa-eye"></i> ดูข้อมูล
                                </a>
                                <a href="patient_edit.php?id=<?= $p['id'] ?>" class="btn btn-secondary btn-sm" title="แก้ไข">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
