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
        } elseif ($action === 'complete') {
            $pdo->prepare("UPDATE queues SET status = 'completed', completed_at = NOW() WHERE id = ?")->execute([$queue_id]);
            $pdo->prepare("UPDATE visits SET status = 'completed' WHERE id = ?")->execute([$target_queue['visit_id']]);
            set_flash('success', "บันทึกคิว {$target_queue['queue_number']} ตรวจเสร็จสิ้นแล้ว");
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
        d.department_code
    FROM queues q
    JOIN visits v ON q.visit_id = v.id
    JOIN patients p ON v.patient_id = p.id
    JOIN departments d ON q.department_id = d.id
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
<div class="page-header" style="margin-bottom: 20px;">
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
        <span class="triage-label">ผู้ป่วยในระบบ</span>
        <span class="triage-value text-slate"><?= number_format($total_patients) ?></span>
        <span class="triage-sub">เวชระเบียนทั้งหมด</span>
    </div>
</div>

<!-- พื้นที่ปฏิบัติการ 2 ฝั่ง (Clinical Workstation Grid) -->
<div class="workstation-grid">
    
    <!-- ฝั่งซ้าย (70%): โต๊ะปฏิบัติการคิวสดแบบเรียลไทม์ -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header" style="flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <h2 class="card-title"><i class="fa-solid fa-clipboard-list"></i> รายการคิวประจำวัน</h2>
                <span class="badge badge-secondary"><?= count($active_queues) ?> คิว</span>
                <?php if ($filter_dept > 0): ?>
                    <a href="index.php" class="btn btn-outline btn-sm" style="font-size: 0.78rem; padding: 2px 8px;">
                        <i class="fa-solid fa-xmark"></i> ล้างตัวกรองแผนก
                    </a>
                <?php endif; ?>
            </div>
            
            <div style="display: flex; gap: 8px; align-items: center;">
                <span style="font-size: 0.82rem; color: var(--text-muted);"><i class="fa-solid fa-volume-high"></i> กดเรียกคิวมีเสียงพูดภาษาไทย</span>
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
                            <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">
                                <i class="fa-regular fa-folder-open" style="font-size: 2rem; margin-bottom: 8px; display: block; opacity: 0.5;"></i>
                                ยังไม่มีรายการคิว<?= $filter_dept ? 'ในแผนกนี้' : '' ?>ประจำวันนี้
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($active_queues as $q): ?>
                            <?php 
                            $is_called = $q['status'] === 'called';
                            $is_serving = $q['status'] === 'serving';
                            $row_bg = $is_called ? 'background-color: #faf5ff;' : ($is_serving ? 'background-color: #f0f9ff;' : '');
                            ?>
                            <tr style="<?= $row_bg ?>">
                                <td>
                                    <strong class="queue-chip <?= $is_called ? 'called' : ($is_serving ? 'serving' : '') ?>">
                                        <?= htmlspecialchars($q['queue_number']) ?>
                                    </strong>
                                </td>
                                <td><code><?= htmlspecialchars($q['hn']) ?></code></td>
                                <td>
                                    <a href="patient_detail.php?id=<?= $q['patient_id'] ?>" style="font-weight: 600; color: #0f172a;">
                                        <?= htmlspecialchars($q['first_name'] . ' ' . $q['last_name']) ?>
                                    </a>
                                </td>
                                <td>
                                    <span style="color: #475569; font-weight: 500;">
                                        [<?= htmlspecialchars($q['department_code']) ?>] <?= htmlspecialchars($q['department_name']) ?>
                                    </span>
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
                                            <button type="submit" class="btn btn-primary btn-sm" onclick="speakQueue('<?= htmlspecialchars($q['queue_number']) ?>', '<?= htmlspecialchars($q['department_name']) ?>')">
                                                <i class="fa-solid fa-bullhorn"></i> เรียกคิว
                                            </button>
                                        <?php elseif ($q['status'] === 'called'): ?>
                                            <input type="hidden" name="action" value="serve">
                                            <button type="submit" class="btn btn-outline btn-sm" style="color: var(--primary); border-color: #bae6fd;">
                                                <i class="fa-solid fa-play"></i> เข้าตรวจ
                                            </button>
                                            <button type="button" class="btn btn-secondary btn-sm" onclick="speakQueue('<?= htmlspecialchars($q['queue_number']) ?>', '<?= htmlspecialchars($q['department_name']) ?>')" title="เรียกซ้ำ">
                                                <i class="fa-solid fa-volume-high"></i>
                                            </button>
                                        <?php elseif ($q['status'] === 'serving'): ?>
                                            <input type="hidden" name="action" value="complete">
                                            <button type="submit" class="btn btn-success btn-sm">
                                                <i class="fa-solid fa-check"></i> เสร็จสิ้น
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
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px;">
                        <div>
                            <strong style="color: #0f172a; font-size: 0.95rem;">
                                <?= htmlspecialchars($dept['department_name']) ?>
                            </strong>
                            <div style="font-size: 0.76rem; color: var(--text-muted);">
                                รหัส: <code><?= htmlspecialchars($dept['department_code']) ?></code>
                            </div>
                        </div>
                        <span class="dept-total-pill"><?= (int)$dept['total_queues'] ?> คิว</span>
                    </div>

                    <div style="display: flex; gap: 8px; font-size: 0.8rem;">
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
