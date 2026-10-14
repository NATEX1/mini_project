<?php
// print_pharmacy.php - พิมพ์บัตรคิวรับยา (Pharmacy Dispensing Slip)
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$rx_id = (int)($_GET['id'] ?? 0);
if (!$rx_id) {
    header('Location: pharmacy.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT 
        pq.*,
        v.visit_date,
        p.hn,
        p.first_name,
        p.last_name,
        d.department_name,
        d.department_code,
        r.room_number,
        r.room_name,
        q.queue_number as exam_queue_number
    FROM pharmacy_queues pq
    JOIN visits v ON pq.visit_id = v.id
    JOIN patients p ON v.patient_id = p.id
    JOIN departments d ON v.department_id = d.id
    LEFT JOIN rooms r ON v.room_id = r.id
    LEFT JOIN queues q ON pq.queue_id = q.id
    WHERE pq.id = ?
");
$stmt->execute([$rx_id]);
$rx = $stmt->fetch();

if (!$rx) {
    die('ไม่พบคิวรับยาที่ระบุ');
}

// นับคิวรับยาก่อนหน้าที่ยังรอรับยาอยู่
$waiting_ahead_stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM pharmacy_queues 
    WHERE status IN ('waiting', 'preparing') 
      AND id < ? 
      AND DATE(created_at) = DATE(?)
");
$waiting_ahead_stmt->execute([$rx_id, $rx['created_at']]);
$waiting_ahead = (int)$waiting_ahead_stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>บัตรคิวรับยา <?= htmlspecialchars($rx['pharmacy_queue_number']) ?> - ห้องจ่ายยา</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="print-page">

<div class="actions-bar no-print">
    <button onclick="window.print()" class="btn btn-rx">
        <i class="fa-solid fa-print"></i> พิมพ์บัตรคิวรับยา
    </button>
    <a href="pharmacy.php" class="btn btn-outline">
        <i class="fa-solid fa-pills"></i> ห้องจ่ายยา
    </a>
    <a href="queues.php" class="btn btn-secondary">
        <i class="fa-solid fa-arrow-left"></i> จุดตรวจแพทย์
    </a>
</div>

<div class="ticket-wrapper ticket-rx">
    <div class="ticket-hospital">
        <i class="fa-solid fa-pills"></i> แผนกเภสัชกรรม / ห้องจ่ายยา
    </div>

    <div class="ticket-dept" style="font-size: 1.15rem;">
        บัตรคิวรอรับยา (Prescription Queue)
    </div>

    <div class="ticket-cue">
        หมายเลขคิวรับยาของคุณ
    </div>

    <div class="ticket-number" style="letter-spacing: 2px;">
        <?= htmlspecialchars($rx['pharmacy_queue_number']) ?>
    </div>

    <div class="ticket-dest-box">
        <div class="ticket-dest-label">
            <i class="fa-solid fa-location-dot"></i> จุดบริการรับยา:
        </div>
        <div class="ticket-destination">
            <?= htmlspecialchars($rx['counter_number'] ?: 'ช่องจ่ายยา 1') ?>
        </div>
        <div class="ticket-dest-sub">
            (ส่งต่อจาก: [<?= htmlspecialchars($rx['department_code']) ?>] <?= htmlspecialchars($rx['room_name'] ?: $rx['department_name']) ?>)
        </div>
    </div>

    <div class="ticket-patient">
        ผู้ป่วย: <strong><?= htmlspecialchars($rx['first_name'] . ' ' . $rx['last_name']) ?></strong>
    </div>

    <div class="ticket-hn">
        HN: <code><?= htmlspecialchars($rx['hn']) ?></code>
        <?php if ($rx['exam_queue_number']): ?>
            | คิวตรวจเดิม: <strong><?= htmlspecialchars($rx['exam_queue_number']) ?></strong>
        <?php endif; ?>
    </div>

    <div class="ticket-tally">
        <div class="tally-item">ลำดับรับยา: <strong><?= $rx['sequence'] ?></strong></div>
        <div class="tally-item">คิวรอก่อนหน้า: <strong class="tally-ahead"><?= $waiting_ahead ?></strong> คิว</div>
    </div>

    <div class="ticket-meta">
        <div>เวลาออกคิวรับยา: <?= thai_datetime($rx['created_at']) ?></div>
        <div class="ticket-notice">
            *** กรุณารอฟังเสียงเรียกคิว ณ จุดรอรับยาหน้าช่องบริการ ***
        </div>
    </div>
</div>

<script>
    window.addEventListener('load', () => {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('auto') === '1') {
            window.print();
        }
    });
</script>

</body>
</html>
