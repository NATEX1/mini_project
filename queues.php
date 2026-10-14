<?php
// queues.php - โต๊ะเรียกคิวและจัดการคิวตรวจสำหรับแพทย์และเจ้าหน้าที่
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$page_title = 'จุดเรียกและจัดการคิวตรวจ';

$today = date('Y-m-d');
$user = current_user();

// จัดการ Action เปลี่ยนสถานะคิวผ่าน POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['queue_id'])) {
    $action   = $_POST['action'];
    $queue_id = (int)$_POST['queue_id'];

    $q_stmt = $pdo->prepare("SELECT q.*, v.patient_id, p.first_name, p.last_name FROM queues q JOIN visits v ON q.visit_id = v.id JOIN patients p ON v.patient_id = p.id WHERE q.id = ?");
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
            set_flash('info', "คิว {$target_queue['queue_number']} กำลังรับบริการ/ตรวจในห้อง");

        } elseif ($action === 'complete') {
            // เสร็จสิ้นแบบไม่รับยา
            $update = $pdo->prepare("UPDATE queues SET status = 'completed', completed_at = NOW() WHERE id = ?");
            $update->execute([$queue_id]);
            $pdo->prepare("UPDATE visits SET status = 'completed' WHERE id = ?")->execute([$target_queue['visit_id']]);
            set_flash('success', "คิว {$target_queue['queue_number']} ({$target_queue['first_name']} {$target_queue['last_name']}) ตรวจเสร็จสิ้นเรียบร้อย (ไม่รับยา)");

        } elseif ($action === 'complete_and_pharmacy' || $action === 'send_pharmacy') {
            // เสร็จสิ้นและส่งไปรับยา (สร้างคิวรับยาใหม่)
            $update = $pdo->prepare("UPDATE queues SET status = 'completed', completed_at = IF(completed_at IS NULL, NOW(), completed_at) WHERE id = ?");
            $update->execute([$queue_id]);
            $pdo->prepare("UPDATE visits SET status = 'prescribed' WHERE id = ?")->execute([$target_queue['visit_id']]);

            // ตรวจสอบว่าเคยมีคิวรับยาสำหรับ Visit นี้วันนี้หรือยัง
            $check_rx = $pdo->prepare("SELECT id, pharmacy_queue_number FROM pharmacy_queues WHERE visit_id = ?");
            $check_rx->execute([$target_queue['visit_id']]);
            $existing_rx = $check_rx->fetch();

            if ($existing_rx) {
                $rx_num = $existing_rx['pharmacy_queue_number'];
                $rx_id  = $existing_rx['id'];
                set_flash('info', "คิวนี้มีรายการรับยา {$rx_num} อยู่แล้ว", [
                    'url' => "print_pharmacy.php?id={$rx_id}",
                    'label' => 'พิมพ์บัตรรับยา',
                    'blank' => true
                ]);
            } else {
                $rx_info = generate_pharmacy_queue_number($pdo);
                $rx_stmt = $pdo->prepare("
                    INSERT INTO pharmacy_queues (visit_id, queue_id, pharmacy_queue_number, sequence, counter_number, status)
                    VALUES (?, ?, ?, ?, 'ช่องจ่ายยา 1', 'waiting')
                ");
                $rx_stmt->execute([
                    $target_queue['visit_id'],
                    $queue_id,
                    $rx_info['pharmacy_queue_number'],
                    $rx_info['sequence']
                ]);
                $new_rx_id = $pdo->lastInsertId();

                set_flash('success', "ตรวจเสร็จสิ้น ส่งต่อห้องจ่ายยาแล้ว หมายเลขคิวรับยา {$rx_info['pharmacy_queue_number']}", [
                    'url' => "print_pharmacy.php?id={$new_rx_id}",
                    'label' => 'พิมพ์บัตรคิวรับยา',
                    'blank' => true
                ]);
            }

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

// รายการแผนกและห้องตรวจสำหรับ Filter
$departments = $pdo->query("SELECT * FROM departments WHERE is_active = 1 ORDER BY department_name ASC")->fetchAll();
$all_rooms = $pdo->query("SELECT r.*, d.department_code FROM rooms r JOIN departments d ON r.department_id = d.id WHERE r.is_active = 1 ORDER BY r.department_id ASC, r.room_number ASC")->fetchAll();

// กรองเริ่มต้นถ้าเข้าใช้งานด้วยบัญชีแพทย์
$default_dept = 0;
$default_room = 0;
if ($user['role'] === 'แพทย์' && !isset($_GET['dept_id']) && !isset($_GET['dept'])) {
    if (!empty($user['department_id'])) $default_dept = (int)$user['department_id'];
    if (!empty($user['room_id'])) $default_room = (int)$user['room_id'];
}

$selected_dept = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : $default_dept;
if (!$selected_dept && isset($_GET['dept'])) {
    $dcode_stmt = $pdo->prepare("SELECT id FROM departments WHERE department_code = ? LIMIT 1");
    $dcode_stmt->execute([trim($_GET['dept'])]);
    $selected_dept = (int)$dcode_stmt->fetchColumn();
}

$selected_room = isset($_GET['room_id']) ? (int)$_GET['room_id'] : $default_room;
$status_filter = trim($_GET['status'] ?? '');

// สร้าง Query คิวประจำวัน พร้อมข้อมูลห้องตรวจ และสถานะคิวรับยา
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
        d.department_name,
        r.id as room_id,
        r.room_number,
        r.room_name,
        pq.id as pharmacy_id,
        pq.pharmacy_queue_number,
        pq.status as pharmacy_status
    FROM queues q
    JOIN visits v ON q.visit_id = v.id
    JOIN patients p ON v.patient_id = p.id
    JOIN departments d ON q.department_id = d.id
    LEFT JOIN rooms r ON q.room_id = r.id
    LEFT JOIN pharmacy_queues pq ON pq.visit_id = v.id
    WHERE DATE(q.created_at) = ?
";
$params = [$today];

if ($selected_dept > 0) {
    $sql .= " AND q.department_id = ?";
    $params[] = $selected_dept;
}

if ($selected_room > 0) {
    $sql .= " AND q.room_id = ?";
    $params[] = $selected_room;
}

if ($status_filter !== '') {
    $sql .= " AND q.status = ?";
    $params[] = $status_filter;
}

$sql .= " ORDER BY FIELD(q.status, 'called', 'serving', 'waiting', 'completed', 'cancelled'), q.queue_sequence ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$queue_list = $stmt->fetchAll();

// สถิติคัดกรองตามเงื่อนไขที่เลือก
$count_waiting = 0;
$count_called = 0;
$count_serving = 0;
$count_completed = 0;
$count_rx = 0;
foreach ($queue_list as $item) {
    if ($item['queue_status'] === 'waiting') $count_waiting++;
    elseif ($item['queue_status'] === 'called') $count_called++;
    elseif ($item['queue_status'] === 'serving') $count_serving++;
    elseif ($item['queue_status'] === 'completed') $count_completed++;
    if (!empty($item['pharmacy_queue_number'])) $count_rx++;
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="fa-solid fa-bullhorn"></i> โต๊ะเรียกและจัดการคิวตรวจ</h1>
        <p class="page-subtitle">
            ประจำวันที่ <?= thai_datetime(date('Y-m-d H:i:s'), false) ?> (วันนี้)
            <?php if ($user['role'] === 'แพทย์'): ?>
                <span class="badge badge-doctor" style="margin-left: 8px;">
                    <i class="fa-solid fa-user-doctor"></i> แพทย์: <?= htmlspecialchars($user['name']) ?>
                </span>
            <?php endif; ?>
        </p>
    </div>
    <div class="page-actions">
        <a href="pharmacy.php" class="btn btn-rx-outline">
            <i class="fa-solid fa-pills"></i> ห้องจ่ายยา
        </a>
        <a href="queue_display.php" target="_blank" class="btn btn-outline">
            <i class="fa-solid fa-tv"></i> จอแสดงคิว TV
        </a>
        <a href="visits.php" class="btn btn-primary">
            <i class="fa-solid fa-ticket"></i> ออกคิวใหม่
        </a>
    </div>
</div>

<!-- ส่วนตัวกรองแผนกและห้องตรวจ + ปุ่มสถานะ -->
<div class="card">
    <form method="GET" action="queues.php" class="filter-form">
        <div class="filter-field">
            <label for="dept_id"><i class="fa-solid fa-stethoscope"></i> แผนกตรวจ / คลินิก</label>
            <select id="dept_id" name="dept_id" onchange="this.form.submit()">
                <option value="0">-- ทุกแผนกตรวจ --</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= ($selected_dept === (int)$d['id']) ? 'selected' : '' ?>>
                        [<?= htmlspecialchars($d['department_code']) ?>] <?= htmlspecialchars($d['department_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-field narrow">
            <label for="room_id"><i class="fa-solid fa-door-open"></i> ห้องตรวจ (Room)</label>
            <select id="room_id" name="room_id" onchange="this.form.submit()">
                <option value="0">-- ทุกห้องตรวจ --</option>
                <?php foreach ($all_rooms as $r): ?>
                    <?php if ($selected_dept > 0 && (int)$r['department_id'] !== $selected_dept) continue; ?>
                    <option value="<?= $r['id'] ?>" <?= ($selected_room === (int)$r['id']) ? 'selected' : '' ?>>
                        [<?= htmlspecialchars($r['department_code']) ?>] <?= htmlspecialchars($r['room_name']) ?> (<?= htmlspecialchars($r['room_number']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-field slim">
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

        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-rotate"></i> รีเฟรช
            </button>
            <?php if ($selected_dept > 0 || $selected_room > 0 || $status_filter !== ''): ?>
                <a href="queues.php" class="btn btn-outline" title="ล้างตัวกรอง">
                    <i class="fa-solid fa-xmark"></i> ล้างค่า
                </a>
            <?php endif; ?>
        </div>
    </form>

    <div class="status-tally">
        <div class="tally-item">
            <span class="tally-label">รอรับบริการ</span>
            <span class="tally-count text-amber"><?= $count_waiting ?></span>
        </div>
        <div class="tally-item">
            <span class="tally-label">เรียกคิวแล้ว</span>
            <span class="tally-count text-violet"><?= $count_called ?></span>
        </div>
        <div class="tally-item">
            <span class="tally-label">กำลังรับบริการ</span>
            <span class="tally-count text-cyan"><?= $count_serving ?></span>
        </div>
        <div class="tally-item">
            <span class="tally-label">เสร็จสิ้นแล้ว</span>
            <span class="tally-count text-emerald"><?= $count_completed ?></span>
        </div>
        <div class="tally-item">
            <span class="tally-label">ส่งรับยาแล้ว</span>
            <span class="tally-count text-rx"><?= $count_rx ?></span>
        </div>
    </div>
</div>

<!-- รายการคิวประจำวัน -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="fa-solid fa-list-check"></i> รายการคิวตรวจ (พบ <?= count($queue_list) ?> คิว)
        </h2>
    </div>

    <?php if (empty($queue_list)): ?>
        <div class="empty-state">
            <div class="empty-state-title">ยังไม่มีคิวในเงื่อนไขที่เลือกประจำวันนี้</div>
        </div>
    <?php else: ?>
        <div class="queue-list">
            <?php foreach ($queue_list as $q): ?>
                <?php
                $is_called = $q['queue_status'] === 'called';
                $is_serving = $q['queue_status'] === 'serving';
                $card_class = $is_called ? 'queue-card is-called' : ($is_serving ? 'queue-card is-serving' : 'queue-card');
                $room_display = $q['room_name'] ? "{$q['room_name']} (ห้อง {$q['room_number']})" : "ห้องตรวจทั่วไป";
                ?>
                <div class="<?= $card_class ?>">

                    <!-- ฝั่งซ้าย: เลขคิว และ ข้อมูลผู้ป่วย -->
                    <div class="queue-main">
                        <div class="queue-id">
                            <div class="queue-tag"><?= htmlspecialchars($q['queue_number']) ?></div>
                            <div class="queue-seq">ลำดับที่ <?= $q['queue_sequence'] ?></div>
                        </div>

                        <div>
                            <div class="queue-title-row">
                                <h3 class="queue-name">
                                    <?= htmlspecialchars($q['first_name'] . ' ' . $q['last_name']) ?>
                                </h3>
                                <?= get_queue_status_badge($q['queue_status']) ?>

                                <!-- แท็กแสดงห้องตรวจ -->
                                <span class="badge badge-room">
                                    <i class="fa-solid fa-door-open"></i> <?= htmlspecialchars($room_display) ?>
                                </span>

                                <!-- ป้ายคิวรับยาถ้าถูกส่งไปแล้ว -->
                                <?php if (!empty($q['pharmacy_queue_number'])): ?>
                                    <a href="print_pharmacy.php?id=<?= $q['pharmacy_id'] ?>" target="_blank" class="badge badge-rx" style="text-decoration: none;" title="คลิกเพื่อพิมพ์บัตรคิวรับยา">
                                        <i class="fa-solid fa-pills"></i> คิวรับยา: <strong><?= htmlspecialchars($q['pharmacy_queue_number']) ?></strong> (<?= htmlspecialchars($q['pharmacy_status']) ?>) <i class="fa-solid fa-print" style="margin-left: 4px; font-size: 0.75rem;"></i>
                                    </a>
                                <?php endif; ?>
                            </div>

                            <div class="queue-meta">
                                <span>HN: <strong><?= htmlspecialchars($q['hn']) ?></strong></span>
                                <span>แผนก: <strong>[<?= htmlspecialchars($q['department_code']) ?>] <?= htmlspecialchars($q['department_name']) ?></strong></span>
                                <span>เวลาออกคิว: <?= date('H:i น.', strtotime($q['queue_time'])) ?></span>
                                <?php if ($q['called_at']): ?>
                                    <span>เวลาเรียก: <?= date('H:i น.', strtotime($q['called_at'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ฝั่งขวา: ปุ่ม Action (ปุ่มหลักตามสถานะ + ส่งรับยา) -->
                    <div class="queue-actions">
                        <?php $action_url = 'queues.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''); ?>

                        <?php if ($q['queue_status'] === 'waiting'): ?>
                            <!-- รอคิว: ปุ่มหลัก "เรียกคิว" พร้อมเสียงภาษาไทย -->
                            <form method="POST" action="<?= $action_url ?>">
                                <input type="hidden" name="queue_id" value="<?= $q['queue_id'] ?>">
                                <input type="hidden" name="action" value="call">
                                <button type="submit" class="btn btn-primary" onclick="speakQueue('<?= htmlspecialchars($q['queue_number']) ?>', '<?= htmlspecialchars($q['department_name']) ?>', '<?= htmlspecialchars($q['room_name'] ?: ('ห้อง ' . $q['room_number'])) ?>')">
                                    <i class="fa-solid fa-bullhorn"></i> เรียกคิว
                                </button>
                            </form>
                            <form method="POST" action="<?= $action_url ?>">
                                <input type="hidden" name="queue_id" value="<?= $q['queue_id'] ?>">
                                <input type="hidden" name="action" value="serve">
                                <button type="submit" class="btn btn-outline" title="ผู้ป่วยเข้าตรวจแล้ว">
                                    <i class="fa-solid fa-play"></i> เข้าตรวจ
                                </button>
                            </form>

                        <?php elseif ($q['queue_status'] === 'called'): ?>
                            <!-- เรียกแล้ว: ปุ่มหลัก "เข้าตรวจ" + เรียกซ้ำ + เสร็จสิ้น -->
                            <form method="POST" action="<?= $action_url ?>">
                                <input type="hidden" name="queue_id" value="<?= $q['queue_id'] ?>">
                                <input type="hidden" name="action" value="serve">
                                <button type="submit" class="btn btn-primary" title="ผู้ป่วยเข้าตรวจแล้ว">
                                    <i class="fa-solid fa-play"></i> เข้าตรวจ
                                </button>
                            </form>
                            <form method="POST" action="<?= $action_url ?>">
                                <input type="hidden" name="queue_id" value="<?= $q['queue_id'] ?>">
                                <input type="hidden" name="action" value="call">
                                <button type="submit" class="btn btn-secondary btn-sm" onclick="speakQueue('<?= htmlspecialchars($q['queue_number']) ?>', '<?= htmlspecialchars($q['department_name']) ?>', '<?= htmlspecialchars($q['room_name'] ?: ('ห้อง ' . $q['room_number'])) ?>')" title="เรียกซ้ำ">
                                    <i class="fa-solid fa-volume-high"></i> เรียกซ้ำ
                                </button>
                            </form>

                            <!-- ปุ่ม: เสร็จสิ้นและส่งรับยา -->
                            <form method="POST" action="<?= $action_url ?>">
                                <input type="hidden" name="queue_id" value="<?= $q['queue_id'] ?>">
                                <input type="hidden" name="action" value="complete_and_pharmacy">
                                <button type="submit" class="btn btn-success" title="เสร็จสิ้นและออกคิวรับยา">
                                    <i class="fa-solid fa-pills"></i> ส่งไปรับยา
                                </button>
                            </form>

                        <?php elseif ($q['queue_status'] === 'serving'): ?>
                            <!-- กำลังตรวจ: ปุ่มหลัก 1) "เสร็จสิ้นและส่งรับยา" (คิวรับยารันใหม่) 2) "เสร็จสิ้น (ไม่รับยา)" -->
                            <form method="POST" action="<?= $action_url ?>">
                                <input type="hidden" name="queue_id" value="<?= $q['queue_id'] ?>">
                                <input type="hidden" name="action" value="complete_and_pharmacy">
                                <button type="submit" class="btn btn-rx" title="ตรวจเสร็จและส่งต่อไปรับยา (ออกคิว RX ทันที)">
                                    <i class="fa-solid fa-pills"></i> เสร็จสิ้นและส่งรับยา
                                </button>
                            </form>

                            <form method="POST" action="<?= $action_url ?>">
                                <input type="hidden" name="queue_id" value="<?= $q['queue_id'] ?>">
                                <input type="hidden" name="action" value="complete">
                                <button type="submit" class="btn btn-outline" title="ตรวจรักษาเสร็จสิ้น ไม่มียา">
                                    <i class="fa-solid fa-circle-check"></i> ไม่รับยา
                                </button>
                            </form>

                        <?php elseif ($q['queue_status'] === 'completed' && empty($q['pharmacy_queue_number'])): ?>
                            <!-- ตรวจเสร็จแล้วแต่ยังไม่ได้ส่งรับยา -->
                            <form method="POST" action="<?= $action_url ?>">
                                <input type="hidden" name="queue_id" value="<?= $q['queue_id'] ?>">
                                <input type="hidden" name="action" value="send_pharmacy">
                                <button type="submit" class="btn btn-rx-outline btn-sm" title="ออกคิวรับยาเพิ่มเติม">
                                    <i class="fa-solid fa-pills"></i> ส่งไปรับยา
                                </button>
                            </form>
                        <?php endif; ?>

                        <!-- ปุ่มเสริม: พิมพ์บัตรคิวตรวจซ้ำ -->
                        <a href="print_queue.php?queue_id=<?= $q['queue_id'] ?>" target="_blank" class="btn btn-outline btn-sm" title="พิมพ์บัตรคิวตรวจ">
                            <i class="fa-solid fa-print"></i>
                        </a>

                        <!-- ปุ่มเสริม: ยกเลิกคิว (เฉพาะคิวยังไม่จบขั้นตอน) -->
                        <?php if ($q['queue_status'] !== 'completed' && $q['queue_status'] !== 'cancelled'): ?>
                        <form method="POST" action="<?= $action_url ?>" onsubmit="return confirm('ยืนยันที่จะยกเลิกคิวนี้หรือไม่?');">
                            <input type="hidden" name="queue_id" value="<?= $q['queue_id'] ?>">
                            <input type="hidden" name="action" value="cancel">
                            <button type="submit" class="btn btn-danger-outline btn-sm" title="ยกเลิกคิว">
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
