<?php
// includes/functions.php - ฟังก์ชันช่วยเหลือทั่วไปในระบบ

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * ป้องกัน XSS และทำความสะอาดข้อมูล
 */
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
}

/**
 * บันทึก Flash message เพื่อแสดงผลหลังจาก redirect
 * $action (optional): ['url' => 'print_pharmacy.php?id=3', 'label' => 'พิมพ์บัตรคิวรับยา', 'blank' => true]
 * ข้อความล้วนเท่านั้น (render จะ escape ให้) ปุ่ม action วาดจากข้อมูล ไม่ใช่ HTML
 */
function set_flash($type, $message, $action = null) {
    $link = null;
    if (is_array($action) && !empty($action['url']) && !empty($action['label'])) {
        // รับเฉพาะลิงก์ภายใน (กัน javascript: / scheme แปลกๆ)
        if (preg_match('/^[A-Za-z0-9_\-\.\/\?\=\&\%]+$/', $action['url'])) {
            $link = [
                'url'   => $action['url'],
                'label' => $action['label'],
                'blank' => !empty($action['blank']),
            ];
        }
    }
    $_SESSION['flash'] = [
        'type' => $type, // 'success', 'danger', 'warning', 'info'
        'message' => $message,
        'action' => $link
    ];
}

/**
 * ดึงและลบ Flash message ออกจาก Session
 */
function get_flash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * ตรวจสอบสถานะการเข้าสู่ระบบ
 */
function is_logged_in() {
    return isset($_SESSION['staff_id']);
}

/**
 * ดึงข้อมูลผู้ใช้งานปัจจุบัน
 */
function current_user() {
    return [
        'id' => $_SESSION['staff_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'name' => $_SESSION['staff_name'] ?? null,
        'role' => $_SESSION['role'] ?? null,
        'department_id' => $_SESSION['department_id'] ?? null,
        'room_id' => $_SESSION['room_id'] ?? null,
    ];
}

/**
 * บังคับให้ต้องเข้าสู่ระบบ
 */
function require_login() {
    if (!is_logged_in()) {
        set_flash('warning', 'กรุณาเข้าสู่ระบบก่อนใช้งาน');
        header('Location: login.php');
        exit;
    }
}

/**
 * บังคับสิทธิ์ผู้ดูแลระบบ (Admin)
 */
function require_admin() {
    require_login();
    if ($_SESSION['role'] !== 'ผู้ดูแลระบบ') {
        set_flash('danger', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้ (เฉพาะผู้ดูแลระบบเท่านั้น)');
        header('Location: index.php');
        exit;
    }
}

/**
 * สร้างหมายเลขประจำตัวผู้ป่วย (HN) อัตโนมัติ เช่น HN-260005
 */
function generate_hn($pdo) {
    $year = date('y'); // 2 หลักท้าย เช่น 26
    $prefix = "HN-{$year}";
    
    $stmt = $pdo->prepare("SELECT hn FROM patients WHERE hn LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute(["{$prefix}%"]);
    $last_hn = $stmt->fetchColumn();
    
    if ($last_hn) {
        $last_num = (int)substr($last_hn, strlen($prefix));
        $next_num = $last_num + 1;
    } else {
        $next_num = 1;
    }
    
    return $prefix . str_pad($next_num, 4, '0', STR_PAD_LEFT);
}

/**
 * สร้างหมายเลขคิวประจำวันและลำดับคิว
 * คืนค่า array ['queue_number' => 'MED-001', 'queue_sequence' => 1]
 */
function generate_queue_number($pdo, $department_id) {
    // 1. ดึงรหัสแผนก
    $dept_stmt = $pdo->prepare("SELECT department_code FROM departments WHERE id = ?");
    $dept_stmt->execute([$department_id]);
    $dept_code = $dept_stmt->fetchColumn();
    if (!$dept_code) {
        $dept_code = 'Q';
    }

    // 2. นับคิวของแผนกนี้ในวันนี้
    $today = date('Y-m-d');
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM queues q
        WHERE q.department_id = ? 
          AND DATE(q.created_at) = ?
    ");
    $count_stmt->execute([$department_id, $today]);
    $sequence = (int)$count_stmt->fetchColumn() + 1;

    $queue_number = strtoupper($dept_code) . '-' . str_pad($sequence, 3, '0', STR_PAD_LEFT);

    return [
        'queue_number' => $queue_number,
        'queue_sequence' => $sequence
    ];
}

/**
 * สร้างหมายเลขคิวรับยาประจำวัน (Pharmacy Queue) เช่น RX-001
 * คืนค่า array ['pharmacy_queue_number' => 'RX-001', 'sequence' => 1]
 */
function generate_pharmacy_queue_number($pdo) {
    $today = date('Y-m-d');
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM pharmacy_queues 
        WHERE DATE(created_at) = ?
    ");
    $count_stmt->execute([$today]);
    $sequence = (int)$count_stmt->fetchColumn() + 1;

    $queue_number = 'RX-' . str_pad($sequence, 3, '0', STR_PAD_LEFT);

    return [
        'pharmacy_queue_number' => $queue_number,
        'sequence' => $sequence
    ];
}

/**
 * แสดง Badge สถานะคิวตรวจ
 */
function get_queue_status_badge($status) {
    switch ($status) {
        case 'waiting':
            return '<span class="badge badge-waiting"><i class="fa-regular fa-clock"></i> รอรับบริการ</span>';
        case 'called':
            return '<span class="badge badge-called"><i class="fa-solid fa-volume-high"></i> เรียกคิวแล้ว</span>';
        case 'serving':
            return '<span class="badge badge-serving"><i class="fa-solid fa-stethoscope"></i> กำลังตรวจ</span>';
        case 'completed':
            return '<span class="badge badge-completed"><i class="fa-solid fa-check"></i> ตรวจเสร็จสิ้น</span>';
        case 'cancelled':
            return '<span class="badge badge-cancelled"><i class="fa-solid fa-xmark"></i> ยกเลิก</span>';
        default:
            return '<span class="badge badge-secondary">' . htmlspecialchars($status) . '</span>';
    }
}

/**
 * แสดง Badge สถานะคิวรับยา
 */
function get_pharmacy_status_badge($status) {
    switch ($status) {
        case 'waiting':
            return '<span class="badge badge-waiting"><i class="fa-regular fa-clock"></i> รอจัดยา</span>';
        case 'preparing':
            return '<span class="badge badge-serving"><i class="fa-solid fa-flask"></i> กำลังจัดยา</span>';
        case 'called':
            return '<span class="badge badge-called"><i class="fa-solid fa-volume-high"></i> เรียกรับยา</span>';
        case 'completed':
            return '<span class="badge badge-completed"><i class="fa-solid fa-check-double"></i> จ่ายยาเสร็จสิ้น</span>';
        case 'cancelled':
            return '<span class="badge badge-cancelled"><i class="fa-solid fa-xmark"></i> ยกเลิก</span>';
        default:
            return '<span class="badge badge-secondary">' . htmlspecialchars($status) . '</span>';
    }
}

/**
 * แปลงวันที่เป็นรูปแบบภาษาไทย
 */
function thai_datetime($datetime, $show_time = true) {
    if (!$datetime || $datetime === '0000-00-00 00:00:00') return '-';
    $timestamp = strtotime($datetime);
    if (!$timestamp) return '-';

    $thai_months = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
        5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
        9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'
    ];

    $day = date('j', $timestamp);
    $month = $thai_months[(int)date('n', $timestamp)];
    $year = (int)date('Y', $timestamp) + 543;
    $time = date('H:i น.', $timestamp);

    if ($show_time) {
        return "{$day} {$month} {$year} {$time}";
    }
    return "{$day} {$month} {$year}";
}
