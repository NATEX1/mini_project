<?php
// patient_detail.php - ประวัติผู้ป่วยและประวัติการออกคิวเข้ารับการตรวจ
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

$page_title = 'เวชระเบียน: ' . $patient['first_name'] . ' ' . $patient['last_name'];

// คำนวณอายุ
$age = '-';
if ($patient['date_of_birth']) {
    $dob = new DateTime($patient['date_of_birth']);
    $now = new DateTime();
    $age = $now->diff($dob)->y . ' ปี';
}

// ดึงประวัติ Visits และ Queues ของผู้ป่วยรายนี้
$visits_stmt = $pdo->prepare("
    SELECT 
        v.id as visit_id,
        v.visit_date,
        v.status as visit_status,
        d.department_name,
        d.department_code,
        r.room_number,
        r.room_name,
        s.first_name as staff_fname,
        s.last_name as staff_lname,
        q.id as queue_id,
        q.queue_number,
        q.queue_sequence,
        q.status as queue_status,
        q.called_at,
        q.completed_at,
        pq.id as rx_id,
        pq.pharmacy_queue_number,
        pq.status as rx_status
    FROM visits v
    JOIN departments d ON v.department_id = d.id
    LEFT JOIN rooms r ON v.room_id = r.id
    LEFT JOIN staff s ON v.staff_id = s.id
    LEFT JOIN queues q ON q.visit_id = v.id
    LEFT JOIN pharmacy_queues pq ON pq.visit_id = v.id
    WHERE v.patient_id = ?
    ORDER BY v.id DESC
");
$visits_stmt->execute([$id]);
$visits = $visits_stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="fa-solid fa-address-card"></i> เวชระเบียนผู้ป่วย</h1>
        <p class="page-subtitle">HN: <strong><?= htmlspecialchars($patient['hn']) ?></strong> - <?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?></p>
    </div>
    <div class="page-actions">
        <a href="patients.php" class="btn btn-outline">
            <i class="fa-solid fa-arrow-left"></i> กลับหน้ารายชื่อ
        </a>
        <a href="patient_edit.php?id=<?= $id ?>" class="btn btn-secondary">
            <i class="fa-solid fa-pen-to-square"></i> แก้ไขข้อมูล
        </a>
        <a href="visits.php?patient_id=<?= $id ?>" class="btn btn-success">
            <i class="fa-solid fa-ticket"></i> ออกคิวตรวจใหม่
        </a>
    </div>
</div>

<div class="record-layout">

    <!-- กล่องข้อมูลส่วนตัวผู้ป่วย -->
    <div class="card card-flush">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-id-card"></i> ข้อมูลทั่วไป</h2>
        </div>

        <div class="patient-id-block">
            <div class="patient-avatar">
                <i class="fa-solid fa-user"></i>
            </div>
            <h3 class="patient-fullname">
                <?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?>
            </h3>
            <span class="hn-pill">
                <?= htmlspecialchars($patient['hn']) ?>
            </span>
        </div>

        <ul class="patient-facts">
            <li><strong>เลขบัตร ปชช.:</strong> <code><?= htmlspecialchars($patient['national_id']) ?></code></li>
            <li><strong>เพศ:</strong> <?= htmlspecialchars($patient['gender'] ?: '-') ?></li>
            <li><strong>วันเกิด:</strong> <?= $patient['date_of_birth'] ? thai_datetime($patient['date_of_birth'], false) : '-' ?> (อายุ <?= $age ?>)</li>
            <li><strong>เบอร์โทรศัพท์:</strong> <?= htmlspecialchars($patient['phone_number'] ?: '-') ?></li>
            <li><strong>ที่อยู่:</strong> <?= nl2br(htmlspecialchars($patient['address'] ?: '-')) ?></li>
            <li><strong>ลงทะเบียนเมื่อ:</strong> <?= thai_datetime($patient['created_at']) ?></li>
        </ul>
    </div>

    <!-- ประวัติการมาโรงพยาบาลและคิวการตรวจ -->
    <div class="card card-flush">
        <div class="card-header">
            <h2 class="card-title">
                <i class="fa-solid fa-clock-rotate-left"></i> ประวัติการเข้ารับบริการ (Visits & Queues)
            </h2>
            <a href="visits.php?patient_id=<?= $id ?>" class="btn btn-primary btn-sm">
                <i class="fa-solid fa-plus"></i> เพิ่มการตรวจวันนี้
            </a>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>วันที่ / เวลา</th>
                        <th>แผนก / ห้องตรวจ</th>
                        <th>คิวตรวจ</th>
                        <th>สถานะตรวจ</th>
                        <th>คิวรับยา (Pharmacy)</th>
                        <th>เจ้าหน้าที่</th>
                        <th style="text-align: right;">เอกสาร</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($visits)): ?>
                        <tr>
                            <td colspan="7" class="table-empty">
                                ยังไม่มีประวัติการมาตรวจของท่านนี้
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($visits as $v): ?>
                        <tr>
                            <td><?= thai_datetime($v['visit_date']) ?></td>
                            <td>
                                <div>
                                    <strong>[<?= htmlspecialchars($v['department_code']) ?>]</strong>
                                    <?= htmlspecialchars($v['department_name']) ?>
                                </div>
                                <?php if ($v['room_number']): ?>
                                    <div class="room-line">
                                        <i class="fa-solid fa-door-open"></i> <?= htmlspecialchars($v['room_name'] ?: ('ห้อง ' . $v['room_number'])) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($v['queue_number']): ?>
                                    <strong class="queue-number-strong">
                                        <?= htmlspecialchars($v['queue_number']) ?>
                                    </strong>
                                    <small class="muted-sm">(ลำดับที่ <?= $v['queue_sequence'] ?>)</small>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $v['queue_status'] ? get_queue_status_badge($v['queue_status']) : '-' ?>
                            </td>
                            <td>
                                <?php if ($v['pharmacy_queue_number']): ?>
                                    <a href="print_pharmacy.php?id=<?= $v['rx_id'] ?>" target="_blank" class="badge badge-rx" style="text-decoration: none;" title="พิมพ์สลิปรับยา">
                                        <i class="fa-solid fa-pills"></i> <?= htmlspecialchars($v['pharmacy_queue_number']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="muted-sm">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($v['staff_fname'] ? ($v['staff_fname'] . ' ' . $v['staff_lname']) : '-') ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <?php if ($v['queue_id']): ?>
                                    <a href="print_queue.php?queue_id=<?= $v['queue_id'] ?>" target="_blank" class="btn btn-outline btn-sm" title="พิมพ์บัตรคิวตรวจ">
                                        <i class="fa-solid fa-print"></i> ตรวจ
                                    </a>
                                <?php endif; ?>
                                <?php if ($v['pharmacy_queue_number']): ?>
                                    <a href="print_pharmacy.php?id=<?= $v['rx_id'] ?>" target="_blank" class="btn btn-rx-outline btn-sm" style="margin-left: 4px;" title="พิมพ์บัตรคิวรับยา">
                                        <i class="fa-solid fa-pills"></i> ยา
                                    </a>
                                <?php endif; ?>
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
