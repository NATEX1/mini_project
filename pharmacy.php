<?php
// pharmacy.php - จุดเรียกและจัดการคิวรับยา / ห้องจ่ายยา (Pharmacy Dispensing Counter)
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$page_title = 'จุดจ่ายยาและคิวรับยา (Pharmacy)';
$today = date('Y-m-d');

// จัดการ Action คิวรับยาผ่าน POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['rx_id'])) {
    $action  = $_POST['action'];
    $rx_id   = (int)$_POST['rx_id'];
    $counter = trim($_POST['counter_number'] ?? 'ช่องจ่ายยา 1');

    $rx_stmt = $pdo->prepare("SELECT pq.*, p.first_name, p.last_name FROM pharmacy_queues pq JOIN visits v ON pq.visit_id = v.id JOIN patients p ON v.patient_id = p.id WHERE pq.id = ?");
    $rx_stmt->execute([$rx_id]);
    $rx = $rx_stmt->fetch();

    if ($rx) {
        if ($action === 'call') {
            $pdo->prepare("UPDATE pharmacy_queues SET status = 'called', counter_number = ?, called_at = NOW() WHERE id = ?")
                ->execute([$counter, $rx_id]);
            set_flash('success', "เรียกคิวรับยา {$rx['pharmacy_queue_number']} ที่{$counter} เรียบร้อยแล้ว");

        } elseif ($action === 'prepare') {
            $pdo->prepare("UPDATE pharmacy_queues SET status = 'preparing', counter_number = ? WHERE id = ?")
                ->execute([$counter, $rx_id]);
            set_flash('info', "คิวรับยา {$rx['pharmacy_queue_number']} อยู่ในขั้นตอนจัดยา");

        } elseif ($action === 'complete') {
            $pdo->prepare("UPDATE pharmacy_queues SET status = 'completed', completed_at = NOW() WHERE id = ?")
                ->execute([$rx_id]);
            // อัปเดต visit เป็น completed
            $pdo->prepare("UPDATE visits SET status = 'completed' WHERE id = ?")->execute([$rx['visit_id']]);
            set_flash('success', "จ่ายยาให้ผู้ป่วยคิว {$rx['pharmacy_queue_number']} ({$rx['first_name']} {$rx['last_name']}) เสร็จสิ้นสมบูรณ์");

        } elseif ($action === 'cancel') {
            $pdo->prepare("UPDATE pharmacy_queues SET status = 'cancelled' WHERE id = ?")->execute([$rx_id]);
            set_flash('warning', "ยกเลิกคิวรับยา {$rx['pharmacy_queue_number']} แล้ว");
        }

        $redirect = 'pharmacy.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
        header("Location: {$redirect}");
        exit;
    }
}

$status_filter = trim($_GET['status'] ?? '');
$counter_filter = trim($_GET['counter'] ?? '');

$sql = "
    SELECT 
        pq.*,
        v.visit_date,
        p.id as patient_id,
        p.hn,
        p.first_name,
        p.last_name,
        p.phone_number,
        d.department_code,
        d.department_name,
        r.room_number,
        r.room_name,
        q.queue_number as exam_queue_number
    FROM pharmacy_queues pq
    JOIN visits v ON pq.visit_id = v.id
    JOIN patients p ON v.patient_id = p.id
    JOIN departments d ON v.department_id = d.id
    LEFT JOIN rooms r ON v.room_id = r.id
    LEFT JOIN queues q ON pq.queue_id = q.id
    WHERE DATE(pq.created_at) = ?
";
$params = [$today];

if ($status_filter !== '') {
    $sql .= " AND pq.status = ?";
    $params[] = $status_filter;
}

if ($counter_filter !== '') {
    $sql .= " AND pq.counter_number = ?";
    $params[] = $counter_filter;
}

$sql .= " ORDER BY FIELD(pq.status, 'called', 'preparing', 'waiting', 'completed', 'cancelled'), pq.sequence ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rx_list = $stmt->fetchAll();

// นับสถิติห้องยาประจำวัน
$stat_waiting = 0;
$stat_prep    = 0;
$stat_called  = 0;
$stat_done    = 0;
foreach ($rx_list as $row) {
    if ($row['status'] === 'waiting') $stat_waiting++;
    elseif ($row['status'] === 'preparing') $stat_prep++;
    elseif ($row['status'] === 'called') $stat_called++;
    elseif ($row['status'] === 'completed') $stat_done++;
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="fa-solid fa-pills"></i> จุดจ่ายยาและคิวรับยา</h1>
        <p class="page-subtitle">คิวรับยาของผู้ป่วยที่ตรวจรักษาเสร็จสิ้นแล้ว ประจำวันที่ <?= thai_datetime(date('Y-m-d H:i:s'), false) ?></p>
    </div>
    <div class="page-actions">
        <a href="pharmacy_display.php" target="_blank" class="btn btn-rx-outline">
            <i class="fa-solid fa-tv"></i> จอคิวรับยา TV
        </a>
        <a href="queues.php" class="btn btn-secondary">
            <i class="fa-solid fa-stethoscope"></i> จุดตรวจแพทย์
        </a>
    </div>
</div>

<!-- ตัวกรองสถานะและช่องบริการ -->
<div class="card">
    <form method="GET" action="pharmacy.php" class="filter-form">
        <div class="filter-field">
            <label for="status"><i class="fa-solid fa-filter"></i> สถานะคิวรับยา</label>
            <select id="status" name="status" onchange="this.form.submit()">
                <option value="">-- ทุกสถานะ --</option>
                <option value="waiting" <?= $status_filter === 'waiting' ? 'selected' : '' ?>>รอจัดยา</option>
                <option value="preparing" <?= $status_filter === 'preparing' ? 'selected' : '' ?>>กำลังจัดยา</option>
                <option value="called" <?= $status_filter === 'called' ? 'selected' : '' ?>>เรียกรับยาแล้ว</option>
                <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>จ่ายยาเสร็จสิ้น</option>
                <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>ยกเลิก</option>
            </select>
        </div>

        <div class="filter-field narrow">
            <label for="counter"><i class="fa-solid fa-cash-register"></i> ช่องจ่ายยา</label>
            <select id="counter" name="counter" onchange="this.form.submit()">
                <option value="">-- ทุกช่องบริการ --</option>
                <option value="ช่องจ่ายยา 1" <?= $counter_filter === 'ช่องจ่ายยา 1' ? 'selected' : '' ?>>ช่องจ่ายยา 1</option>
                <option value="ช่องจ่ายยา 2" <?= $counter_filter === 'ช่องจ่ายยา 2' ? 'selected' : '' ?>>ช่องจ่ายยา 2</option>
                <option value="ช่องจ่ายยา 3" <?= $counter_filter === 'ช่องจ่ายยา 3' ? 'selected' : '' ?>>ช่องจ่ายยา 3</option>
            </select>
        </div>

        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-rotate"></i> รีเฟรช
            </button>
            <?php if ($status_filter !== '' || $counter_filter !== ''): ?>
                <a href="pharmacy.php" class="btn btn-outline" title="ล้างตัวกรอง">
                    <i class="fa-solid fa-xmark"></i> ล้างค่า
                </a>
            <?php endif; ?>
        </div>
    </form>

    <div class="status-tally">
        <div class="tally-item">
            <span class="tally-label">รอจัดยา</span>
            <span class="tally-count text-amber"><?= $stat_waiting ?></span>
        </div>
        <div class="tally-item">
            <span class="tally-label">กำลังจัดยา</span>
            <span class="tally-count text-cyan"><?= $stat_prep ?></span>
        </div>
        <div class="tally-item">
            <span class="tally-label">เรียกรับยาแล้ว</span>
            <span class="tally-count text-violet"><?= $stat_called ?></span>
        </div>
        <div class="tally-item">
            <span class="tally-label">จ่ายยาเสร็จสิ้น</span>
            <span class="tally-count text-rx"><?= $stat_done ?></span>
        </div>
    </div>
</div>

<!-- รายการคิวรับยา -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="fa-solid fa-receipt"></i> คิวรับยาประจำวัน (<?= count($rx_list) ?> คิว)
        </h2>
    </div>

    <?php if (empty($rx_list)): ?>
        <div class="empty-state">
            <div class="empty-state-title">ยังไม่มีคิวรับยาวันนี้</div>
            <div class="empty-state-hint">เมื่อแพทย์กด "เสร็จสิ้นและส่งรับยา" คิวจะปรากฏที่นี่ทันที</div>
        </div>
    <?php else: ?>
        <div class="queue-list">
            <?php foreach ($rx_list as $rx): ?>
                <?php
                $rx_status_class = '';
                if ($rx['status'] === 'called')    $rx_status_class = 'is-rx-called';
                elseif ($rx['status'] === 'preparing') $rx_status_class = 'is-preparing';
                ?>
                <div class="queue-card <?= $rx_status_class ?>">

                    <!-- ฝั่งซ้าย: เลขคิวรับยา และข้อมูลคนไข้ -->
                    <div class="queue-main">
                        <div class="queue-id">
                            <div class="queue-tag rx"><?= htmlspecialchars($rx['pharmacy_queue_number']) ?></div>
                            <div class="queue-seq">ลำดับรับยาที่ <?= $rx['sequence'] ?></div>
                        </div>

                        <div>
                            <div class="queue-title-row">
                                <h3 class="queue-name">
                                    <?= htmlspecialchars($rx['first_name'] . ' ' . $rx['last_name']) ?>
                                </h3>
                                <?= get_pharmacy_status_badge($rx['status']) ?>

                                <span class="badge badge-secondary">
                                    <i class="fa-solid fa-cash-register"></i> <?= htmlspecialchars($rx['counter_number'] ?: 'ช่องจ่ายยา 1') ?>
                                </span>

                                <?php if ($rx['exam_queue_number']): ?>
                                    <span class="badge badge-secondary">
                                        คิวตรวจเดิม: <?= htmlspecialchars($rx['exam_queue_number']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="queue-meta">
                                <span>HN: <strong><?= htmlspecialchars($rx['hn']) ?></strong></span>
                                <span>ส่งตรวจจาก: <strong>[<?= htmlspecialchars($rx['department_code']) ?>] <?= htmlspecialchars($rx['department_name']) ?></strong></span>
                                <?php if ($rx['room_name']): ?>
                                    <span><?= htmlspecialchars($rx['room_name']) ?></span>
                                <?php endif; ?>
                                <span>เวลาส่งยา: <?= date('H:i น.', strtotime($rx['created_at'])) ?></span>
                                <?php if ($rx['called_at']): ?>
                                    <span>เวลาเรียก: <?= date('H:i น.', strtotime($rx['called_at'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ฝั่งขวา: Action Buttons -->
                    <div class="queue-actions">
                        <?php $action_url = 'pharmacy.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''); ?>

                        <?php if ($rx['status'] === 'waiting'): ?>
                            <!-- รอจัดยา: 1) กำลังจัดยา 2) เรียกรับยา -->
                            <!-- <form method="POST" action="<?= $action_url ?>">
                                <input type="hidden" name="rx_id" value="<?= $rx['id'] ?>">
                                <input type="hidden" name="action" value="prepare">
                                <button type="submit" class="btn btn-outline" title="เริ่มจัดยา">
                                    <i class="fa-solid fa-flask"></i> เริ่มจัดยา
                                </button>
                            </form> -->

                            <form method="POST" action="<?= $action_url ?>">
                                <input type="hidden" name="rx_id" value="<?= $rx['id'] ?>">
                                <input type="hidden" name="action" value="call">
                                <input type="hidden" name="counter_number" value="<?= htmlspecialchars($rx['counter_number'] ?: 'ช่องจ่ายยา 1') ?>">
                                <button type="submit" class="btn btn-rx" onclick="speakPharmacyQueue('<?= htmlspecialchars($rx['pharmacy_queue_number']) ?>', '<?= htmlspecialchars($rx['counter_number'] ?: 'ช่องจ่ายยา 1') ?>')">
                                    <i class="fa-solid fa-bullhorn"></i> เรียกรับยา
                                </button>
                            </form>

                        <?php elseif ($rx['status'] === 'preparing'): ?>
                            <!-- กำลังจัดยา: ปุ่มหลัก เรียกรับยา -->
                            <form method="POST" action="<?= $action_url ?>">
                                <input type="hidden" name="rx_id" value="<?= $rx['id'] ?>">
                                <input type="hidden" name="action" value="call">
                                <input type="hidden" name="counter_number" value="<?= htmlspecialchars($rx['counter_number'] ?: 'ช่องจ่ายยา 1') ?>">
                                <button type="submit" class="btn btn-rx" onclick="speakPharmacyQueue('<?= htmlspecialchars($rx['pharmacy_queue_number']) ?>', '<?= htmlspecialchars($rx['counter_number'] ?: 'ช่องจ่ายยา 1') ?>')">
                                    <i class="fa-solid fa-bullhorn"></i> จัดเสร็จแล้ว เรียกรับยา
                                </button>
                            </form>

                        <?php elseif ($rx['status'] === 'called'): ?>
                            <!-- เรียกแล้ว: 1) จ่ายยาเสร็จสิ้น 2) เรียกซ้ำ -->
                            <form method="POST" action="<?= $action_url ?>">
                                <input type="hidden" name="rx_id" value="<?= $rx['id'] ?>">
                                <input type="hidden" name="action" value="complete">
                                <button type="submit" class="btn btn-success" title="ผู้ป่วยรับยาและคำแนะนำเรียบร้อย">
                                    <i class="fa-solid fa-circle-check"></i> จ่ายยาเสร็จสิ้น
                                </button>
                            </form>

                            <form method="POST" action="<?= $action_url ?>">
                                <input type="hidden" name="rx_id" value="<?= $rx['id'] ?>">
                                <input type="hidden" name="action" value="call">
                                <input type="hidden" name="counter_number" value="<?= htmlspecialchars($rx['counter_number'] ?: 'ช่องจ่ายยา 1') ?>">
                                <button type="submit" class="btn btn-secondary btn-sm" onclick="speakPharmacyQueue('<?= htmlspecialchars($rx['pharmacy_queue_number']) ?>', '<?= htmlspecialchars($rx['counter_number'] ?: 'ช่องจ่ายยา 1') ?>')" title="เรียกซ้ำ">
                                    <i class="fa-solid fa-volume-high"></i> เรียกซ้ำ
                                </button>
                            </form>
                        <?php endif; ?>

                        <!-- พิมพ์บัตรคิวรับยา -->
                        <a href="print_pharmacy.php?id=<?= $rx['id'] ?>" target="_blank" class="btn btn-outline btn-sm" title="พิมพ์สลิปรับยา">
                            <i class="fa-solid fa-print"></i>
                        </a>

                        <?php if ($rx['status'] !== 'completed' && $rx['status'] !== 'cancelled'): ?>
                        <form method="POST" action="<?= $action_url ?>" onsubmit="return confirm('ยกเลิกคิวรับยานี้?');">
                            <input type="hidden" name="rx_id" value="<?= $rx['id'] ?>">
                            <input type="hidden" name="action" value="cancel">
                            <button type="submit" class="btn btn-danger-outline btn-sm" title="ยกเลิก">
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

<script src="assets/js/app.js"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
