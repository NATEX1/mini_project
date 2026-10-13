<?php
// index.php - หน้าแดชบอร์ดหลักของระบบ
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$page_title = 'แดชบอร์ดภาพรวม';

$today = date('Y-m-d');

// 1. สถิติตัวเลขสำคัญ
$total_patients = $pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();

$stmt_today_visits = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE DATE(visit_date) = ?");
$stmt_today_visits->execute([$today]);
$today_visits = $stmt_today_visits->fetchColumn();

$stmt_waiting = $pdo->prepare("SELECT COUNT(*) FROM queues WHERE status = 'waiting' AND DATE(created_at) = ?");
$stmt_waiting->execute([$today]);
$today_waiting = $stmt_waiting->fetchColumn();

$stmt_serving = $pdo->prepare("SELECT COUNT(*) FROM queues WHERE status IN ('called', 'serving') AND DATE(created_at) = ?");
$stmt_serving->execute([$today]);
$today_serving = $stmt_serving->fetchColumn();

$stmt_completed = $pdo->prepare("SELECT COUNT(*) FROM queues WHERE status = 'completed' AND DATE(created_at) = ?");
$stmt_completed->execute([$today]);
$today_completed = $stmt_completed->fetchColumn();

// 2. สรุปคิวตามแผนกในวันนี้
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

// 3. รายการคิวล่าสุดประจำวัน
$recent_queues_stmt = $pdo->prepare("
    SELECT 
        q.id as queue_id,
        q.queue_number,
        q.status,
        q.created_at as queue_time,
        p.hn,
        CONCAT(p.first_name, ' ', p.last_name) as patient_name,
        d.department_name,
        d.department_code
    FROM queues q
    JOIN visits v ON q.visit_id = v.id
    JOIN patients p ON v.patient_id = p.id
    JOIN departments d ON q.department_id = d.id
    WHERE DATE(q.created_at) = ?
    ORDER BY q.id DESC
    LIMIT 8
");
$recent_queues_stmt->execute([$today]);
$recent_queues = $recent_queues_stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">แดชบอร์ดจัดการคิวโรงพยาบาล</h1>
        <p class="page-subtitle">ประจำวันที่ <?= thai_datetime(date('Y-m-d H:i:s'), false) ?> (วันนี้)</p>
    </div>
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="patient_add.php" class="btn btn-outline">
            <i class="fa-solid fa-user-plus"></i> ลงทะเบียนผู้ป่วยใหม่
        </a>
        <a href="visits.php" class="btn btn-primary">
            <i class="fa-solid fa-ticket"></i> ออกคิวตรวจใหม่
        </a>
        <a href="queues.php" class="btn btn-success">
            <i class="fa-solid fa-bullhorn"></i> หน้าเรียกคิว
        </a>
    </div>
</div>

<!-- กล่องตัวเลขสรุปสถิติ -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="fa-solid fa-users"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($total_patients) ?></div>
            <div class="stat-label">ผู้ป่วยในระบบทั้งหมด</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon indigo">
            <i class="fa-solid fa-clipboard-check"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($today_visits) ?></div>
            <div class="stat-label">ผู้ป่วยมาตรวจวันนี้</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon amber">
            <i class="fa-solid fa-clock"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($today_waiting) ?></div>
            <div class="stat-label">คิวกำลังรอรับบริการ</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon rose">
            <i class="fa-solid fa-stethoscope"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($today_serving) ?></div>
            <div class="stat-label">กำลังเรียก / อยู่ในห้องตรวจ</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fa-solid fa-circle-check"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($today_completed) ?></div>
            <div class="stat-label">ตรวจเสร็จสิ้นแล้ววันนี้</div>
        </div>
    </div>
</div>

<!-- แถวเนื้อหาหลัก 2 คอลัมน์ -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;">
    
    <!-- สรุปคิวตามแผนกตรวจ -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-hospital-user"></i> สถานะคิวแยกตามแผนกวันนี้</h2>
            <a href="departments.php" class="btn btn-outline btn-sm">ดูแผนกทั้งหมด</a>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>แผนก / คลินิก</th>
                        <th style="text-align: center;">รอรับบริการ</th>
                        <th style="text-align: center;">กำลังตรวจ</th>
                        <th style="text-align: center;">เสร็จสิ้น</th>
                        <th style="text-align: center;">รวมวันนี้</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($department_stats)): ?>
                        <tr><td colspan="5" style="text-align:center;">ยังไม่มีข้อมูลแผนก</td></tr>
                    <?php else: ?>
                        <?php foreach ($department_stats as $dept): ?>
                        <tr>
                            <td>
                                <strong>[<?= htmlspecialchars($dept['department_code']) ?>]</strong>
                                <?= htmlspecialchars($dept['department_name']) ?>
                            </td>
                            <td style="text-align: center;">
                                <span class="badge badge-waiting"><?= (int)$dept['waiting_count'] ?></span>
                            </td>
                            <td style="text-align: center;">
                                <span class="badge badge-serving"><?= (int)$dept['serving_count'] ?></span>
                            </td>
                            <td style="text-align: center;">
                                <span class="badge badge-completed"><?= (int)$dept['completed_count'] ?></span>
                            </td>
                            <td style="text-align: center; font-weight: 600;">
                                <?= (int)$dept['total_queues'] ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ลิงก์ด่วน และเครื่องมืออำนวยความสะดวก -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-bolt"></i> ลิงก์ด่วนและการทำงาน</h2>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
            <a href="visits.php" class="btn btn-outline" style="padding: 18px; flex-direction: column; text-align: center; gap: 10px;">
                <i class="fa-solid fa-ticket" style="font-size: 1.8rem; color: var(--primary);"></i>
                <strong>ออกบัตรคิวตรวจ</strong>
                <span style="font-size: 0.8rem; color: var(--text-muted);">เลือกผู้ป่วยและพิมพ์บัตรคิว</span>
            </a>

            <a href="queues.php" class="btn btn-outline" style="padding: 18px; flex-direction: column; text-align: center; gap: 10px;">
                <i class="fa-solid fa-bullhorn" style="font-size: 1.8rem; color: var(--warning);"></i>
                <strong>โต๊ะเรียกคิว (Desk)</strong>
                <span style="font-size: 0.8rem; color: var(--text-muted);">เรียกคิวพร้อมเสียงขานอัตโนมัติ</span>
            </a>

            <a href="queue_display.php" target="_blank" class="btn btn-outline" style="padding: 18px; flex-direction: column; text-align: center; gap: 10px;">
                <i class="fa-solid fa-tv" style="font-size: 1.8rem; color: #7c3aed;"></i>
                <strong>จอแสดงคิว TV</strong>
                <span style="font-size: 0.8rem; color: var(--text-muted);">เปิดขึ้นจอทีวีสำหรับผู้ป่วย</span>
            </a>

            <a href="patients.php" class="btn btn-outline" style="padding: 18px; flex-direction: column; text-align: center; gap: 10px;">
                <i class="fa-solid fa-address-book" style="font-size: 1.8rem; color: var(--success);"></i>
                <strong>ค้นหาเวชระเบียน</strong>
                <span style="font-size: 0.8rem; color: var(--text-muted);">ค้นหาด้วย HN, บัตร ปชช., ชื่อ</span>
            </a>
        </div>

        <div style="margin-top: 20px; padding: 14px; background: #f0fdf4; border-radius: var(--radius-md); border: 1px solid #bbf7d0; font-size: 0.88rem; color: #166534;">
            <i class="fa-solid fa-circle-check"></i> <strong>ระบบพร้อมใช้งาน:</strong> ฐานข้อมูลเชื่อมต่อเรียบร้อย มีการเข้ารหัสผ่านแบบปลอดภัย และรองรับการพิมพ์บัตรคิวลงเครื่องพิมพ์ความร้อน
        </div>
    </div>
</div>

<!-- ตารางคิวล่าสุดวันนี้ -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title"><i class="fa-solid fa-list-ol"></i> รายการคิวล่าสุดประจำวันนี้</h2>
        <a href="queues.php" class="btn btn-outline btn-sm">จัดการคิวทั้งหมด</a>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>หมายเลขคิว</th>
                    <th>HN</th>
                    <th>ชื่อผู้ป่วย</th>
                    <th>แผนกตรวจ</th>
                    <th>เวลาที่ออกคิว</th>
                    <th>สถานะ</th>
                    <th style="text-align: right;">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent_queues)): ?>
                    <tr><td colspan="7" style="text-align:center; padding: 25px; color: var(--text-muted);">ยังไม่มีคิวที่ออกในวันนี้</td></tr>
                <?php else: ?>
                    <?php foreach ($recent_queues as $q): ?>
                    <tr>
                        <td>
                            <strong style="font-size: 1.15rem; color: var(--primary);"><?= htmlspecialchars($q['queue_number']) ?></strong>
                        </td>
                        <td><code><?= htmlspecialchars($q['hn']) ?></code></td>
                        <td><strong><?= htmlspecialchars($q['patient_name']) ?></strong></td>
                        <td><?= htmlspecialchars($q['department_name']) ?></td>
                        <td><?= thai_datetime($q['queue_time']) ?></td>
                        <td><?= get_queue_status_badge($q['status']) ?></td>
                        <td style="text-align: right;">
                            <a href="print_queue.php?queue_id=<?= $q['queue_id'] ?>" target="_blank" class="btn btn-outline btn-sm" title="พิมพ์บัตรคิว">
                                <i class="fa-solid fa-print"></i> พิมพ์
                            </a>
                            <a href="queues.php?dept=<?= $q['department_code'] ?>" class="btn btn-primary btn-sm">
                                <i class="fa-solid fa-arrow-right"></i> ไปที่แผนก
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
