<?php
// print_queue.php - พิมพ์บัตรคิวสำหรับผู้ป่วย (รองรับ Thermal Slip / เครื่องพิมพ์ความร้อน)
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$queue_id = (int)($_GET['queue_id'] ?? 0);
if (!$queue_id) {
    header('Location: queues.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT 
        q.id as queue_id,
        q.queue_number,
        q.queue_sequence,
        q.status as queue_status,
        q.created_at as queue_time,
        v.visit_date,
        p.hn,
        p.first_name,
        p.last_name,
        d.id as department_id,
        d.department_name,
        d.department_code,
        r.room_number,
        r.room_name,
        doc.first_name as doc_fname,
        doc.last_name as doc_lname,
        s.first_name as staff_fname,
        s.last_name as staff_lname
    FROM queues q
    JOIN visits v ON q.visit_id = v.id
    JOIN patients p ON v.patient_id = p.id
    JOIN departments d ON q.department_id = d.id
    LEFT JOIN rooms r ON q.room_id = r.id
    LEFT JOIN staff doc ON r.doctor_id = doc.id
    LEFT JOIN staff s ON v.staff_id = s.id
    WHERE q.id = ?
");
$stmt->execute([$queue_id]);
$queue = $stmt->fetch();

if (!$queue) {
    die('ไม่พบคิวที่ระบุ');
}

// นับจำนวนคิวก่อนหน้าที่กำลังรอตรวจอยู่ในแผนกเดียวกันวันนี้
$waiting_ahead_stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM queues 
    WHERE department_id = ? 
      AND status = 'waiting' 
      AND id < ? 
      AND DATE(created_at) = DATE(?)
");
$waiting_ahead_stmt->execute([$queue['department_id'], $queue_id, $queue['queue_time']]);
$waiting_ahead = (int)$waiting_ahead_stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>บัตรคิว <?= htmlspecialchars($queue['queue_number']) ?> - โรงพยาบาล</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="print-page">

<div class="actions-bar no-print">
    <button onclick="window.print()" class="btn btn-primary">
        <i class="fa-solid fa-print"></i> สั่งพิมพ์ทันที
    </button>
    <a href="visits.php" class="btn btn-outline">
        <i class="fa-solid fa-plus"></i> ออกคิวใหม่
    </a>
    <a href="queues.php" class="btn btn-secondary">
        <i class="fa-solid fa-arrow-left"></i> หน้าเรียกคิว
    </a>
</div>

<div class="ticket-wrapper">
    <div class="ticket-hospital">
        <i class="fa-solid fa-hospital-user"></i> โรงพยาบาลทั่วไป
    </div>
    
    <div class="ticket-dept">
        [<?= htmlspecialchars($queue['department_code']) ?>] <?= htmlspecialchars($queue['department_name']) ?>
    </div>

    <div class="ticket-cue">
        หมายเลขคิวตรวจของคุณ
    </div>

    <div class="ticket-number">
        <?= htmlspecialchars($queue['queue_number']) ?>
    </div>

    <!-- กล่องบอกห้องตรวจที่คนไข้ต้องไป -->
    <div class="ticket-dest-box">
        <div class="ticket-dest-label">
            <i class="fa-solid fa-location-dot"></i> กรุณาไปรอตรวจที่:
        </div>
        <div class="ticket-destination">
            <?= htmlspecialchars($queue['room_name'] ?: ('ห้องตรวจ ' . ($queue['room_number'] ?: '1'))) ?>
        </div>
        <?php if ($queue['room_number']): ?>
            <div class="ticket-dest-sub">
                (ห้อง <?= htmlspecialchars($queue['room_number']) ?>)
            </div>
        <?php endif; ?>
        <?php if ($queue['doc_fname']): ?>
            <div class="ticket-dest-note">
                แพทย์ตรวจ: <?= htmlspecialchars($queue['doc_fname'] . ' ' . $queue['doc_lname']) ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="ticket-patient">
        ผู้ป่วย: <strong><?= htmlspecialchars($queue['first_name'] . ' ' . $queue['last_name']) ?></strong>
    </div>

    <div class="ticket-hn">
        HN: <code><?= htmlspecialchars($queue['hn']) ?></code>
    </div>

    <div class="ticket-tally">
        <div class="tally-item">ลำดับคิวที่: <strong><?= $queue['queue_sequence'] ?></strong></div>
        <div class="tally-item">คิวรอก่อนหน้า: <strong class="tally-ahead"><?= $waiting_ahead ?></strong> คิว</div>
    </div>

    <div class="ticket-meta">
        <div>วันที่ออกคิว: <?= thai_datetime($queue['queue_time']) ?></div>
        <div>ผู้ออกคิว: <?= htmlspecialchars($queue['staff_fname'] ? ($queue['staff_fname'] . ' ' . $queue['staff_lname']) : 'เจ้าหน้าที่') ?></div>
        <div class="ticket-notice">
            *** กรุณารอฟังเสียงเรียกคิว ณ จุดรอรับบริการหน้าห้องตรวจ ***
        </div>
    </div>
</div>

<script>
    // สั่งพิมพ์อัตโนมัติเมื่อเปิดหน้า (ถ้าระบุ auto=1)
    window.addEventListener('load', () => {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('auto') === '1') {
            window.print();
        }
    });
</script>

</body>
</html>
