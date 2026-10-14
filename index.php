<?php
// index.php - หน้าแดชบอร์ดจัดการคิวโรงพยาบาล (Clinical Workstation Layout)
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$page_title = 'ศูนย์ควบคุมคิวตรวจ';

$today = date('Y-m-d');
$filter_dept = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : 0;

// จัดการการเรียกคิวแบบด่วนจากหน้าแดชบอร์ด (Quick Actions)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['queue_id'])) {
    $action   = $_POST['action'];
    $queue_id = (int)$_POST['queue_id'];

    $q_stmt = $pdo->prepare("SELECT * FROM queues WHERE id = ?");
    $q_stmt->execute([$queue_id]);
    $target_queue = $q_stmt->fetch();

    if ($target_queue) {
        if ($action === 'call') {
            $pdo->prepare("UPDATE queues SET status = 'called', called_at = NOW() WHERE id = ?")->execute([$queue_id]);
            set_flash('success', "เรียกคิว {$target_queue['queue_number']} เรียบร้อยแล้ว");
        } elseif ($action === 'serve') {
            $pdo->prepare("UPDATE queues SET status = 'serving' WHERE id = ?")->execute([$queue_id]);
            set_flash('info', "คิว {$target_queue['queue_number']} กำลังรับบริการในห้องตรวจ");
        } elseif ($action === 'complete_and_pharmacy') {
            $pdo->prepare("UPDATE queues SET status = 'completed', completed_at = NOW() WHERE id = ?")->execute([$queue_id]);
            $pdo->prepare("UPDATE visits SET status = 'prescribed' WHERE id = ?")->execute([$target_queue['visit_id']]);
            
            $rx_info = generate_pharmacy_queue_number($pdo);
            $rx_stmt = $pdo->prepare("INSERT INTO pharmacy_queues (visit_id, queue_id, pharmacy_queue_number, sequence, counter_number, status) VALUES (?, ?, ?, ?, 'ช่องจ่ายยา 1', 'waiting')");
            $rx_stmt->execute([$target_queue['visit_id'], $queue_id, $rx_info['pharmacy_queue_number'], $rx_info['sequence']]);
            $rx_id = $pdo->lastInsertId();

            set_flash('success', "คิว {$target_queue['queue_number']} ตรวจเสร็จสิ้น ออกคิวรับยา {$rx_info['pharmacy_queue_number']} แล้ว", [
                'url' => "print_pharmacy.php?id={$rx_id}",
                'label' => 'พิมพ์บัตรรับยา',
                'blank' => true
            ]);
        } elseif ($action === 'complete') {
            $pdo->prepare("UPDATE queues SET status = 'completed', completed_at = NOW() WHERE id = ?")->execute([$queue_id]);
            $pdo->prepare("UPDATE visits SET status = 'completed' WHERE id = ?")->execute([$target_queue['visit_id']]);
            set_flash('success', "บันทึกคิว {$target_queue['queue_number']} ตรวจเสร็จสิ้นแล้ว (ไม่รับยา)");
        }
        $redirect = 'index.php' . ($filter_dept ? "?dept_id={$filter_dept}" : '');
        header("Location: {$redirect}");
        exit;
    }
}

// 1. สถิติตัวเลขเวชระเบียนและคิว
$total_patients = $pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();

$stmt_today_visits = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE DATE(visit_date) = ?");
$stmt_today_visits->execute([$today]);
$today_visits = (int)$stmt_today_visits->fetchColumn();

$stmt_waiting = $pdo->prepare("SELECT COUNT(*) FROM queues WHERE status = 'waiting' AND DATE(created_at) = ?");
$stmt_waiting->execute([$today]);
$today_waiting = (int)$stmt_waiting->fetchColumn();

$stmt_serving = $pdo->prepare("SELECT COUNT(*) FROM queues WHERE status IN ('called', 'serving') AND DATE(created_at) = ?");
$stmt_serving->execute([$today]);
$today_serving = (int)$stmt_serving->fetchColumn();

$stmt_completed = $pdo->prepare("SELECT COUNT(*) FROM queues WHERE status = 'completed' AND DATE(created_at) = ?");
$stmt_completed->execute([$today]);
$today_completed = (int)$stmt_completed->fetchColumn();

// สถิติคิวรับยาวันนี้
$today_rx_count = (int)$pdo->query("SELECT COUNT(*) FROM pharmacy_queues WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$today_rx_waiting = (int)$pdo->query("SELECT COUNT(*) FROM pharmacy_queues WHERE DATE(created_at) = CURDATE() AND status IN ('waiting', 'preparing')")->fetchColumn();

// 2. สรุปภาระงานแยกตามแผนก
$dept_stats_stmt = $pdo->prepare("
    SELECT 
        d.id, d.department_code, d.department_name,
        COUNT(q.id) as total_queues,
        SUM(CASE WHEN q.status = 'waiting' THEN 1 ELSE 0 END) as waiting_count,
        SUM(CASE WHEN q.status IN ('called', 'serving') THEN 1 ELSE 0 END) as serving_count,
        SUM(CASE WHEN q.status = 'completed' THEN 1 ELSE 0 END) as completed_count
    FROM departments d
    LEFT JOIN queues q ON d.id = q.department_id AND DATE(q.created_at) = ?
    WHERE d.is_active = 1
    GROUP BY d.id
    ORDER BY total_queues DESC, d.department_name ASC
");
$dept_stats_stmt->execute([$today]);
$department_stats = $dept_stats_stmt->fetchAll();

// 2.5 สถิติสำหรับ Charts: แนวโน้ม 7 วัน, ชั่วโมงเร่งด่วนวันนี้, สัดส่วนสถานะวันนี้
$counts_by_date = [];
foreach ($pdo->query("
    SELECT DATE(created_at) AS stat_date, COUNT(*) AS total
    FROM queues
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(created_at)
")->fetchAll() as $row) {
    $counts_by_date[$row['stat_date']] = (int)$row['total'];
}
$daily_series = [];
for ($i = 6; $i >= 0; $i--) {
    $ds = date('Y-m-d', strtotime("-{$i} days"));
    $daily_series[$ds] = $counts_by_date[$ds] ?? 0;
}
$daily_max = max(max($daily_series), 1);
$thai_days = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];

// จำนวนคิวแยกตามชั่วโมงของวันนี้ (07:00–18:00)
$hourly = array_fill(7, 12, 0);
foreach ($pdo->query("
    SELECT HOUR(created_at) AS h, COUNT(*) AS total
    FROM queues
    WHERE DATE(created_at) = CURDATE()
    GROUP BY HOUR(created_at)
")->fetchAll() as $row) {
    $h = (int)$row['h'];
    if ($h >= 7 && $h <= 18) {
        $hourly[$h] = (int)$row['total'];
    }
}
$hourly_max  = max(max($hourly), 1);
$peak_hour   = (int)array_search(max($hourly), $hourly);

// สัดส่วนสถานะคิววันนี้ (โดนัท)
$status_today_stmt = $pdo->prepare("
    SELECT status, COUNT(*) AS total
    FROM queues
    WHERE DATE(created_at) = ?
    GROUP BY status
");
$status_today_stmt->execute([$today]);
$status_today = [];
foreach ($status_today_stmt->fetchAll() as $row) {
    $status_today[$row['status']] = (int)$row['total'];
}
$queues_today_total = array_sum($status_today);

$status_order  = ['waiting', 'called', 'serving', 'completed', 'cancelled'];
$status_colors = [
    'waiting'   => 'var(--amber-text)',
    'called'    => 'var(--violet-text)',
    'serving'   => 'var(--cyan-text)',
    'completed' => 'var(--green-text)',
    'cancelled' => 'var(--red-text)',
];
$donut_c = 2 * M_PI * 54; // เส้นรอบวงของ SVG donut (r = 54)

// 3. รายการคิวประจำวัน (รองรับ Filter แผนก)
$query_queues = "
    SELECT 
        q.id as queue_id,
        q.queue_number,
        q.queue_sequence,
        q.status,
        q.called_at,
        q.created_at as queue_time,
        p.id as patient_id,
        p.hn,
        p.first_name,
        p.last_name,
        d.id as department_id,
        d.department_name,
        d.department_code,
        r.room_number,
        r.room_name,
        pq.id as rx_id,
        pq.pharmacy_queue_number
    FROM queues q
    JOIN visits v ON q.visit_id = v.id
    JOIN patients p ON v.patient_id = p.id
    JOIN departments d ON q.department_id = d.id
    LEFT JOIN rooms r ON q.room_id = r.id
    LEFT JOIN pharmacy_queues pq ON pq.visit_id = v.id
    WHERE DATE(q.created_at) = ?
";
$params_queues = [$today];
if ($filter_dept > 0) {
    $query_queues .= " AND q.department_id = ?";
    $params_queues[] = $filter_dept;
}
$query_queues .= " ORDER BY FIELD(q.status, 'called', 'serving', 'waiting', 'completed', 'cancelled'), q.queue_sequence ASC";

$stmt_queues = $pdo->prepare($query_queues);
$stmt_queues->execute($params_queues);
$active_queues = $stmt_queues->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<!-- หัวหน้างาน (Operational Header) -->
<div class="page-header">
    <div>
        <h1 class="page-title">ศูนย์จัดการและเรียกคิวผู้ป่วย</h1>
        <p class="page-subtitle">เวชปฏิบัติประจำวันที่ <?= thai_datetime(date('Y-m-d H:i:s'), false) ?> (วันนี้)</p>
    </div>
    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <a href="visits.php" class="btn btn-primary">
            <i class="fa-solid fa-ticket"></i> ออกบัตรคิวตรวจ
        </a>
        <a href="patient_add.php" class="btn btn-outline">
            <i class="fa-solid fa-user-plus"></i> ลงทะเบียนผู้ป่วยใหม่
        </a>
        <a href="queue_display.php" target="_blank" class="btn btn-outline">
            <i class="fa-solid fa-tv"></i> จอ TV
        </a>
    </div>
</div>

<!-- แถบดัชนีคิว (Clinical Queue Triage Strip) -->
<div class="triage-strip">
    <div class="triage-cell">
        <span class="triage-label">มาตรวจวันนี้</span>
        <span class="triage-value"><?= number_format($today_visits) ?></span>
        <span class="triage-sub">รวมทุกแผนก</span>
    </div>
    <div class="triage-divider"></div>

    <div class="triage-cell">
        <span class="triage-label">รอรับบริการ</span>
        <span class="triage-value text-amber"><?= number_format($today_waiting) ?></span>
        <span class="triage-sub">คิวในลำดับรอตรวจ</span>
    </div>
    <div class="triage-divider"></div>

    <div class="triage-cell">
        <span class="triage-label">กำลังเรียก / อยู่ในห้องตรวจ</span>
        <span class="triage-value text-cyan"><?= number_format($today_serving) ?></span>
        <span class="triage-sub">แพทย์กำลังตรวจรักษา</span>
    </div>
    <div class="triage-divider"></div>

    <div class="triage-cell">
        <span class="triage-label">ตรวจเสร็จสิ้นแล้ว</span>
        <span class="triage-value text-emerald"><?= number_format($today_completed) ?></span>
        <span class="triage-sub">บันทึกเสร็จสมบูรณ์</span>
    </div>
    <div class="triage-divider"></div>

    <div class="triage-cell">
        <span class="triage-label">คิวรับยา (Pharmacy)</span>
        <span class="triage-value text-emerald"><?= number_format($today_rx_count) ?></span>
        <span class="triage-sub">รอรับยา <?= number_format($today_rx_waiting) ?> คิว</span>
    </div>
    <div class="triage-divider"></div>

    <div class="triage-cell">
        <span class="triage-label">ผู้ป่วยในระบบ</span>
        <span class="triage-value text-slate"><?= number_format($total_patients) ?></span>
        <span class="triage-sub">เวชระเบียนทั้งหมด</span>
    </div>
</div>

<!-- แถบสถิติประจำวัน (Operational Statistics) -->
<div class="stats-grid">

    <!-- คิวสะสม 7 วันย้อนหลัง -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-chart-column"></i> คิวสะสม 7 วันย้อนหลัง</h2>
        </div>
        <div class="bar-chart">
            <?php foreach ($daily_series as $ds => $count): ?>
                <?php
                $is_peak_day = $count > 0 && $count === $daily_max;
                $bar_px = $count > 0 ? max(4, (int)round($count / $daily_max * 106)) : 0;
                ?>
                <div class="bar-col" title="<?= thai_datetime($ds, false) ?>: <?= $count ?> คิว">
                    <span class="bar-value <?= $is_peak_day ? 'is-peak' : '' ?>"><?= $count > 0 ? $count : '' ?></span>
                    <div class="bar-track">
                        <?php if ($bar_px > 0): ?>
                            <div class="bar <?= $is_peak_day ? 'is-peak' : '' ?>" style="height: <?= $bar_px ?>px;" aria-hidden="true"></div>
                        <?php endif; ?>
                    </div>
                    <span class="bar-label"><?= $thai_days[(int)date('w', strtotime($ds))] ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ชั่วโมงเร่งด่วนวันนี้ -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-clock"></i> คิวเข้าระบบตามชั่วโมง (วันนี้)</h2>
        </div>
        <div class="bar-chart">
            <?php foreach ($hourly as $h => $total): ?>
                <?php
                $is_peak_h = $total > 0 && $h === $peak_hour;
                $bar_px = $total > 0 ? max(4, (int)round($total / $hourly_max * 106)) : 0;
                ?>
                <div class="bar-col" title="<?= str_pad($h, 2, '0', STR_PAD_LEFT) ?>:00 น.: <?= $total ?> คิว">
                    <span class="bar-value <?= $is_peak_h ? 'is-peak' : '' ?>"><?= $is_peak_h ? $total : '' ?></span>
                    <div class="bar-track">
                        <?php if ($bar_px > 0): ?>
                            <div class="bar <?= $is_peak_h ? 'is-peak' : '' ?>" style="height: <?= $bar_px ?>px;" aria-hidden="true"></div>
                        <?php endif; ?>
                    </div>
                    <span class="bar-label"><?= str_pad($h, 2, '0', STR_PAD_LEFT) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- สัดส่วนสถานะคิววันนี้ -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-chart-pie"></i> สัดส่วนสถานะคิววันนี้</h2>
        </div>
        <?php if ($queues_today_total === 0): ?>
            <p style="color: var(--text-muted); font-size: 0.9rem; padding: 12px 0;">
                ยังไม่มีคิวเข้าระบบวันนี้ — เมื่อออกบัตรคิวใบแรก สัดส่วนจะปรากฏที่นี่
            </p>
        <?php else: ?>
            <div class="donut-wrap">
                <svg class="donut" viewBox="0 0 140 140" role="img" aria-label="สัดส่วนสถานะคิววันนี้ รวม <?= $queues_today_total ?> คิว">
                    <circle class="donut-track" cx="70" cy="70" r="54"></circle>
                    <?php $donut_offset = 0; foreach ($status_order as $st): ?>
                        <?php if (empty($status_today[$st])) continue; ?>
                        <?php $seg_len = $status_today[$st] / $queues_today_total * $donut_c; ?>
                        <circle class="donut-seg" cx="70" cy="70" r="54"
                                style="stroke: <?= $status_colors[$st] ?>; stroke-dasharray: <?= number_format($seg_len, 2) ?> <?= number_format($donut_c, 2) ?>; stroke-dashoffset: <?= number_format(-$donut_offset, 2) ?>;"></circle>
                        <?php $donut_offset += $seg_len; ?>
                    <?php endforeach; ?>
                    <text x="70" y="65" text-anchor="middle" class="donut-total"><?= number_format($queues_today_total) ?></text>
                    <text x="70" y="84" text-anchor="middle" class="donut-caption">คิววันนี้</text>
                </svg>
                <ul class="donut-legend">
                    <?php foreach ($status_order as $st): ?>
                        <?php if (empty($status_today[$st])) continue; ?>
                        <li>
                            <?= get_queue_status_badge($st) ?>
                            <span class="legend-count"><?= number_format($status_today[$st]) ?> คิว (<?= round($status_today[$st] / $queues_today_total * 100) ?>%)</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- พื้นที่ปฏิบัติการ 2 ฝั่ง (Clinical Workstation Grid) -->
<div class="workstation-grid">
    
    <!-- ฝั่งซ้าย (70%): โต๊ะปฏิบัติการคิวสดแบบเรียลไทม์ -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header">
            <div class="card-header-title">
                <h2 class="card-title"><i class="fa-solid fa-clipboard-list"></i> รายการคิวประจำวัน</h2>
                <span class="badge badge-secondary"><?= count($active_queues) ?> คิว</span>
                <?php if ($filter_dept > 0): ?>
                    <a href="index.php" class="btn btn-outline btn-sm" title="ล้างตัวกรองแผนก">
                        <i class="fa-solid fa-xmark"></i> ล้างตัวกรองแผนก
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 110px;">หมายเลขคิว</th>
                        <th style="width: 100px;">HN</th>
                        <th>ชื่อ - นามสกุลผู้ป่วย</th>
                        <th>แผนกตรวจ</th>
                        <th style="width: 110px;">สถานะ</th>
                        <th style="text-align: right; min-width: 170px;">การปฏิบัติการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($active_queues)): ?>
                        <tr>
                            <td colspan="6" class="table-empty">
                                ยังไม่มีรายการคิว<?= $filter_dept ? 'ในแผนกนี้' : '' ?>ประจำวันนี้
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($active_queues as $q): ?>
                            <?php 
                            $is_called = $q['status'] === 'called';
                            $is_serving = $q['status'] === 'serving';
                            $row_class = $is_called ? 'is-called' : ($is_serving ? 'is-serving' : '');
                            ?>
                            <tr class="<?= $row_class ?>">
                                <td>
                                    <strong class="queue-chip <?= $is_called ? 'called' : ($is_serving ? 'serving' : '') ?>">
                                        <?= htmlspecialchars($q['queue_number']) ?>
                                    </strong>
                                </td>
                                <td><code><?= htmlspecialchars($q['hn']) ?></code></td>
                                <td>
                                    <a href="patient_detail.php?id=<?= $q['patient_id'] ?>" class="patient-link">
                                        <?= htmlspecialchars($q['first_name'] . ' ' . $q['last_name']) ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="dept-line">
                                        [<?= htmlspecialchars($q['department_code']) ?>] <?= htmlspecialchars($q['department_name']) ?>
                                    </span>
                                    <?php if ($q['room_number']): ?>
                                        <div class="room-line">
                                            <i class="fa-solid fa-door-open"></i> <?= htmlspecialchars($q['room_name'] ?: ('ห้อง ' . $q['room_number'])) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($q['pharmacy_queue_number']): ?>
                                        <div style="margin-top: 3px;">
                                            <a href="print_pharmacy.php?id=<?= $q['rx_id'] ?>" target="_blank" class="badge badge-rx" style="text-decoration: none;" title="พิมพ์สลิปรับยา">
                                                <i class="fa-solid fa-pills"></i> คิวรับยา: <?= htmlspecialchars($q['pharmacy_queue_number']) ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= get_queue_status_badge($q['status']) ?>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <!-- ปุ่มปฏิบัติการคิว -->
                                    <form method="POST" action="index.php<?= $filter_dept ? "?dept_id={$filter_dept}" : '' ?>" style="display: inline-block; margin: 0;">
                                        <input type="hidden" name="queue_id" value="<?= $q['queue_id'] ?>">
                                        
                                        <?php if ($q['status'] === 'waiting'): ?>
                                            <input type="hidden" name="action" value="call">
                                            <button type="submit" class="btn btn-primary btn-sm" onclick="speakQueue('<?= htmlspecialchars($q['queue_number']) ?>', '<?= htmlspecialchars($q['department_name']) ?>', '<?= htmlspecialchars($q['room_name'] ?: ('ห้อง ' . $q['room_number'])) ?>')">
                                                <i class="fa-solid fa-bullhorn"></i> เรียกคิว
                                            </button>
                                        <?php elseif ($q['status'] === 'called'): ?>
                                            <input type="hidden" name="action" value="serve">
                                            <button type="submit" class="btn btn-outline btn-sm">
                                                <i class="fa-solid fa-play"></i> เข้าตรวจ
                                            </button>
                                            <button type="button" class="btn btn-secondary btn-sm" onclick="speakQueue('<?= htmlspecialchars($q['queue_number']) ?>', '<?= htmlspecialchars($q['department_name']) ?>', '<?= htmlspecialchars($q['room_name'] ?: ('ห้อง ' . $q['room_number'])) ?>')" title="เรียกซ้ำ">
                                                <i class="fa-solid fa-volume-high"></i>
                                            </button>
                                        <?php elseif ($q['status'] === 'serving'): ?>
                                            <button type="submit" name="action" value="complete_and_pharmacy" class="btn btn-success btn-sm" title="ตรวจเสร็จและส่งต่อไปรับยา">
                                                <i class="fa-solid fa-pills"></i> เสร็จและส่งรับยา
                                            </button>
                                            <button type="submit" name="action" value="complete" class="btn btn-outline btn-sm" title="ตรวจเสร็จ ไม่รับยา">
                                                <i class="fa-solid fa-check"></i> ไม่รับยา
                                            </button>
                                        <?php endif; ?>
                                    </form>

                                    <!-- พิมพ์บัตรคิว -->
                                    <a href="print_queue.php?queue_id=<?= $q['queue_id'] ?>" target="_blank" class="btn btn-outline btn-sm" title="พิมพ์บัตรคิว" style="margin-left: 4px;">
                                        <i class="fa-solid fa-print"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ฝั่งขวา (30%): สรุปภาระงานแต่ละแผนก (Department Load Breakdown) -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-stethoscope"></i> ภาระงานแยกตามแผนก</h2>
            <a href="departments.php" class="btn btn-outline btn-sm">จัดการ</a>
        </div>

        <div style="display: flex; flex-direction: column; gap: 10px;">
            <?php foreach ($department_stats as $dept): ?>
                <?php 
                $is_selected = ($filter_dept === (int)$dept['id']);
                $has_waiting = (int)$dept['waiting_count'] > 0;
                $has_serving = (int)$dept['serving_count'] > 0;
                ?>
                <a href="index.php?dept_id=<?= $dept['id'] ?>" class="dept-status-card <?= $is_selected ? 'selected' : '' ?>">
                    <div class="dept-status-head">
                        <div>
                            <strong class="dept-status-name">
                                <?= htmlspecialchars($dept['department_name']) ?>
                            </strong>
                            <div class="dept-status-code">
                                รหัส: <code><?= htmlspecialchars($dept['department_code']) ?></code>
                            </div>
                        </div>
                        <span class="dept-total-pill"><?= (int)$dept['total_queues'] ?> คิว</span>
                    </div>

                    <div class="dept-status-badges">
                        <span class="dept-mini-badge amber">รอ: <?= (int)$dept['waiting_count'] ?></span>
                        <span class="dept-mini-badge cyan">ตรวจ: <?= (int)$dept['serving_count'] ?></span>
                        <span class="dept-mini-badge green">เสร็จ: <?= (int)$dept['completed_count'] ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
