<?php
// queues.php - โต๊ะเรียกคิวและจัดการคิวสำหรับเจ้าหน้าที่/ห้องตรวจ
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$page_title = 'จุดเรียกและจัดการคิวตรวจ';

$today = date('Y-m-d');

// จัดการ Action เปลี่ยนสถานะคิวผ่าน POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['queue_id'])) {
    $action   = $_POST['action'];
    $queue_id = (int)$_POST['queue_id'];

    $q_stmt = $pdo->prepare("SELECT * FROM queues WHERE id = ?");
    $q_stmt->execute([$queue_id]);
    $target_queue = $q_stmt->fetch();

    if ($target_queue) {
        if ($action === 'call') {
            $update = $pdo->prepare("UPDATE queues SET status = 'called', called_at = NOW() WHERE id = ?");
            $update->execute([$queue_id]);
            set_flash('success', "เรียกคิว {$target_queue['queue_number']} เรียบร้อยแล้ว");
        } elseif ($action === 'serve') {
            $update = $pdo->prepare("UPDATE queues SET status = 'serving' WHERE id = ?");
            $update->execute([$queue_id]);
            set_flash('info', "คิว {$target_queue['queue_number']} กำลังรับบริการ/ตรวจ");
        } elseif ($action === 'complete') {
            $update = $pdo->prepare("UPDATE queues SET status = 'completed', completed_at = NOW() WHERE id = ?");
            $update->execute([$queue_id]);
            // อัปเดต visit ด้วย
            $pdo->prepare("UPDATE visits SET status = 'completed' WHERE id = ?")->execute([$target_queue['visit_id']]);
            set_flash('success', "คิว {$target_queue['queue_number']} ตรวจเสร็จสิ้นเรียบร้อย");
        } elseif ($action === 'cancel') {
            $update = $pdo->prepare("UPDATE queues SET status = 'cancelled' WHERE id = ?");
            $update->execute([$queue_id]);
            $pdo->prepare("UPDATE visits SET status = 'cancelled' WHERE id = ?")->execute([$target_queue['visit_id']]);
            set_flash('warning', "ยกเลิกคิว {$target_queue['queue_number']} แล้ว");
        }

        // Redirect กลับหน้าเดิมพร้อม query string
        $redirect_url = 'queues.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
        header("Location: {$redirect_url}");
        exit;
    }
}

// แผนกทั้งหมด
$departments = $pdo->query("SELECT * FROM departments WHERE is_active = 1 ORDER BY department_name ASC")->fetchAll();

$selected_dept = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : 0;
// ถ้าส่งรหัสมาเป็นตัวอักษร เช่น dept=MED
if (!$selected_dept && isset($_GET['dept'])) {
    $dcode_stmt = $pdo->prepare("SELECT id FROM departments WHERE department_code = ? LIMIT 1");
    $dcode_stmt->execute([trim($_GET['dept'])]);
    $selected_dept = (int)$dcode_stmt->fetchColumn();
}

$status_filter = trim($_GET['status'] ?? '');

// สร้าง Query คิวประจำวัน
$sql = "
    SELECT 
        q.id as queue_id,
        q.queue_number,
        q.queue_sequence,
        q.status as queue_status,
        q.called_at,
        q.completed_at,
        q.created_at as queue_time,
        v.id as visit_id,
        p.id as patient_id,
        p.hn,
        p.first_name,
        p.last_name,
        p.phone_number,
        d.id as department_id,
        d.department_code,
        d.department_name
    FROM queues q
    JOIN visits v ON q.visit_id = v.id
    JOIN patients p ON v.patient_id = p.id
    JOIN departments d ON q.department_id = d.id
    WHERE DATE(q.created_at) = ?
";
$params = [$today];

if ($selected_dept > 0) {
    $sql .= " AND q.department_id = ?";
    $params[] = $selected_dept;
}

if ($status_filter !== '') {
    $sql .= " AND q.status = ?";
    $params[] = $status_filter;
}

$sql .= " ORDER BY FIELD(q.status, 'called', 'serving', 'waiting', 'completed', 'cancelled'), q.queue_sequence ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$queue_list = $stmt->fetchAll();

// สถิติคัดกรองตามแผนกที่เลือก
$count_waiting = 0;
$count_called = 0;
$count_serving = 0;
$count_completed = 0;
foreach ($queue_list as $item) {
    if ($item['queue_status'] === 'waiting') $count_waiting++;
    elseif ($item['queue_status'] === 'called') $count_called++;
    elseif ($item['queue_status'] === 'serving') $count_serving++;
    elseif ($item['queue_status'] === 'completed') $count_completed++;
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="fa-solid fa-bullhorn"></i> โต๊ะเรียกและจัดการคิวตรวจ</h1>
        <p class="page-subtitle">ประจำวันที่ <?= thai_datetime(date('Y-m-d H:i:s'), false) ?> (วันนี้)</p>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="queue_display.php" target="_blank" class="btn btn-outline">
            <i class="fa-solid fa-tv"></i> เปิดจอแสดงคิว TV <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.75rem;"></i>
        </a>
        <a href="visits.php" class="btn btn-primary">
            <i class="fa-solid fa-ticket"></i> ออกคิวใหม่
        </a>
    </div>
</div>

<!-- ส่วนตัวกรองแผนกและสถานะ -->
<div class="card">
    <form method="GET" action="queues.php" style="display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end;">
        <div style="flex: 1; min-width: 240px;">
            <label for="dept_id"><i class="fa-solid fa-stethoscope"></i> เลือกแผนกตรวจ / คลินิก</label>
            <select id="dept_id" name="dept_id" onchange="this.form.submit()">
                <option value="0">-- ทุกแผนกตรวจ --</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= ($selected_dept === (int)$d['id']) ? 'selected' : '' ?>>
                        [<?= htmlspecialchars($d['department_code']) ?>] <?= htmlspecialchars($d['department_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="width: 200px;">
            <label for="status"><i class="fa-solid fa-filter"></i> สถานะคิว</label>
            <select id="status" name="status" onchange="this.form.submit()">
                <option value="">ทั้งหมด</option>
                <option value="waiting" <?= $status_filter === 'waiting' ? 'selected' : '' ?>>รอรับบริการ</option>
                <option value="called" <?= $status_filter === 'called' ? 'selected' : '' ?>>เรียกคิวแล้ว</option>
                <option value="serving" <?= $status_filter === 'serving' ? 'selected' : '' ?>>กำลังรับบริการ</option>
                <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>เสร็จสิ้น</option>
                <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>ยกเลิก</option>
            </select>
        </div>

        <div>
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-rotate"></i> รีเฟรช
            </button>
            <?php if ($selected_dept > 0 || $status_filter !== ''): ?>
                <a href="queues.php" class="btn btn-outline" title="ล้างตัวกรอง">
                    <i class="fa-solid fa-xmark"></i> ล้างค่า
                </a>
            <?php endif; ?>
        </div>
    </form>

    <div style="display: flex; gap: 14px; margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--border); flex-wrap: wrap; font-size: 0.9rem;">
        <div>รอรับบริการ: <strong style="color: #b45309;"><?= $count_waiting ?></strong> คิว</div>
        <div>•</div>
        <div>เรียกคิวแล้ว: <strong style="color: #6d28d9;"><?= $count_called ?></strong> คิว</div>
        <div>•</div>
        <div>กำลังรับบริการ: <strong style="color: #0284c7;"><?= $count_serving ?></strong> คิว</div>
        <div>•</div>
        <div>เสร็จสิ้นแล้ว: <strong style="color: #059669;"><?= $count_completed ?></strong> คิว</div>
    </div>
</div>

<!-- รายการคิวประจำวัน -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="fa-solid fa-list-check"></i> รายการคิวตรวจ (พบ <?= count($queue_list) ?> คิว)
        </h2>
        <span style="font-size: 0.85rem; color: var(--text-muted);">
            <i class="fa-solid fa-volume-high" style="color: var(--primary);"></i> กดปุ่ม "เรียกคิว" เพื่อขานเสียงภาษาไทย
        </span>
    </div>

    <?php if (empty($queue_list)): ?>
        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
            <i class="fa-solid fa-clipboard" style="font-size: 2.5rem; opacity: 0.4; margin-bottom: 12px; display: block;"></i>
            ยังไม่มีคิวในเงื่อนไขที่เลือกประจำวันนี้
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 12px;">
            <?php foreach ($queue_list as $q): ?>
                <?php
                $is_called = $q['queue_status'] === 'called';
                $is_serving = $q['queue_status'] === 'serving';
                $card_class = $is_called ? 'queue-card is-called' : ($is_serving ? 'queue-card is-serving' : 'queue-card');
                ?>
                <div class="<?= $card_class ?>">
                    
                    <!-- ฝั่งซ้าย: เลขคิว และ ข้อมูลผู้ป่วย -->
                    <div style="display: flex; align-items: center; gap: 20px;">
                        <div style="text-align: center; min-width: 120px;">
                            <div class="queue-tag"><?= htmlspecialchars($q['queue_number']) ?></div>
                            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 4px;">ลำดับที่ <?= $q['queue_sequence'] ?></div>
                        </div>

                        <div>
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
                                <h3 style="font-size: 1.15rem; font-weight: 700; margin: 0; color: #0f172a;">
                                    <?= htmlspecialchars($q['first_name'] . ' ' . $q['last_name']) ?>
                                </h3>
                                <?= get_queue_status_badge($q['queue_status']) ?>
                            </div>

                            <div style="font-size: 0.88rem; color: #64748b; display: flex; gap: 14px; flex-wrap: wrap;">
                                <span>HN: <strong><?= htmlspecialchars($q['hn']) ?></strong></span>
                                <span>แผนก: <strong>[<?= htmlspecialchars($q['department_code']) ?>] <?= htmlspecialchars($q['department_name']) ?></strong></span>
                                <span>เวลาออกคิว: <?= date('H:i น.', strtotime($q['queue_time'])) ?></span>
                                <?php if ($q['called_at']): ?>
                                    <span>เวลาเรียก: <?= date('H:i น.', strtotime($q['called_at'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ฝั่งขวา: ปุ่ม Action -->
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        
                        <!-- ปุ่มเรียกคิวพร้อมสังเคราะห์เสียง -->
                        <form method="POST" action="queues.php<?= !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '' ?>" style="margin: 0;">
                            <input type="hidden" name="queue_id" value="<?= $q['queue_id'] ?>">
                            <input type="hidden" name="action" value="call">
                            <button type="submit" class="btn <?= $is_called ? 'btn-secondary' : 'btn-primary' ?>" onclick="speakQueue('<?= htmlspecialchars($q['queue_number']) ?>', '<?= htmlspecialchars($q['department_name']) ?>')">
                                <i class="fa-solid fa-bullhorn"></i> <?= $is_called ? 'เรียกซ้ำ (Recall)' : 'เรียกคิว (Call)' ?>
                            </button>
                        </form>

                        <!-- ปุ่มเข้าห้องตรวจ / ให้บริการ -->
                        <?php if ($q['queue_status'] === 'called' || $q['queue_status'] === 'waiting'): ?>
                        <form method="POST" action="queues.php<?= !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '' ?>" style="margin: 0;">
                            <input type="hidden" name="queue_id" value="<?= $q['queue_id'] ?>">
                            <input type="hidden" name="action" value="serve">
                            <button type="submit" class="btn btn-outline" title="ผู้ป่วยเข้าตรวจแล้ว">
                                <i class="fa-solid fa-play"></i> เข้าตรวจ
                            </button>
                        </form>
                        <?php endif; ?>

                        <!-- ปุ่มตรวจเสร็จสิ้น -->
                        <?php if ($q['queue_status'] === 'serving' || $q['queue_status'] === 'called'): ?>
                        <form method="POST" action="queues.php<?= !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '' ?>" style="margin: 0;">
                            <input type="hidden" name="queue_id" value="<?= $q['queue_id'] ?>">
                            <input type="hidden" name="action" value="complete">
                            <button type="submit" class="btn btn-success" title="ตรวจรักษาเสร็จสิ้น">
                                <i class="fa-solid fa-circle-check"></i> เสร็จสิ้น
                            </button>
                        </form>
                        <?php endif; ?>

                        <!-- ปุ่มพิมพ์บัตรคิวซ้ำ -->
                        <a href="print_queue.php?queue_id=<?= $q['queue_id'] ?>" target="_blank" class="btn btn-outline btn-sm" title="พิมพ์บัตรคิว">
                            <i class="fa-solid fa-print"></i>
                        </a>

                        <!-- ปุ่มยกเลิกคิว -->
                        <?php if ($q['queue_status'] !== 'completed' && $q['queue_status'] !== 'cancelled'): ?>
                        <form method="POST" action="queues.php<?= !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '' ?>" style="margin: 0;" onsubmit="return confirm('ยืนยันที่จะยกเลิกคิวนี้หรือไม่?');">
                            <input type="hidden" name="queue_id" value="<?= $q['queue_id'] ?>">
                            <input type="hidden" name="action" value="cancel">
                            <button type="submit" class="btn btn-outline btn-sm" style="color: var(--danger); border-color: #fecaca;" title="ยกเลิกคิว">
                                <i class="fa-solid fa-ban"></i>
                            </button>
                        </form>
                        <?php endif; ?>

                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
