<?php
// api/get_queues.php - JSON API สำหรับหน้าจอแสดงผลคิว TV และ Real-time polling
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';

$today = date('Y-m-d');
$dept_id = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : 0;

try {
    // 1. คิวตรวจที่กำลังเรียก หรือ กำลังรับบริการ (Called / Serving)
    $calling_sql = "
        SELECT 
            q.id as queue_id,
            q.queue_number,
            q.queue_sequence,
            q.status,
            q.called_at,
            d.department_code,
            d.department_name,
            r.room_number,
            r.room_name,
            CONCAT(p.first_name, ' ', SUBSTRING(p.last_name, 1, 3), '..') as patient_name
        FROM queues q
        JOIN visits v ON q.visit_id = v.id
        JOIN patients p ON v.patient_id = p.id
        JOIN departments d ON q.department_id = d.id
        LEFT JOIN rooms r ON q.room_id = r.id
        WHERE DATE(q.created_at) = ? AND q.status IN ('called', 'serving')
    ";
    $calling_params = [$today];
    if ($dept_id > 0) {
        $calling_sql .= " AND q.department_id = ?";
        $calling_params[] = $dept_id;
    }
    $calling_sql .= " ORDER BY q.called_at DESC, q.id DESC LIMIT 6";

    $calling_stmt = $pdo->prepare($calling_sql);
    $calling_stmt->execute($calling_params);
    $calling = $calling_stmt->fetchAll();

    // 2. คิวตรวจที่กำลังรอรับบริการ (Waiting)
    $waiting_sql = "
        SELECT 
            q.id as queue_id,
            q.queue_number,
            q.queue_sequence,
            d.department_code,
            d.department_name,
            r.room_number,
            r.room_name
        FROM queues q
        JOIN departments d ON q.department_id = d.id
        LEFT JOIN rooms r ON q.room_id = r.id
        WHERE DATE(q.created_at) = ? AND q.status = 'waiting'
    ";
    $waiting_params = [$today];
    if ($dept_id > 0) {
        $waiting_sql .= " AND q.department_id = ?";
        $waiting_params[] = $dept_id;
    }
    $waiting_sql .= " ORDER BY q.queue_sequence ASC LIMIT 10";

    $waiting_stmt = $pdo->prepare($waiting_sql);
    $waiting_stmt->execute($waiting_params);
    $waiting = $waiting_stmt->fetchAll();

    // 3. คิวตรวจที่เพิ่งถูกกดเรียกครั้งล่าสุด (เพื่อ Trigger เสียงอัตโนมัติบนจอ TV)
    $last_called_sql = "
        SELECT 
            q.id as queue_id,
            q.queue_number,
            d.department_name,
            r.room_name,
            r.room_number,
            q.called_at
        FROM queues q
        JOIN departments d ON q.department_id = d.id
        LEFT JOIN rooms r ON q.room_id = r.id
        WHERE DATE(q.created_at) = ? AND q.status = 'called' AND q.called_at IS NOT NULL
    ";
    $last_params = [$today];
    if ($dept_id > 0) {
        $last_called_sql .= " AND q.department_id = ?";
        $last_params[] = $dept_id;
    }
    $last_called_sql .= " ORDER BY q.called_at DESC LIMIT 1";

    $last_stmt = $pdo->prepare($last_called_sql);
    $last_stmt->execute($last_params);
    $last_called = $last_stmt->fetch();

    // 4. คิวรับยา (Pharmacy Queues)
    $pharmacy_calling = $pdo->query("
        SELECT 
            pq.id,
            pq.pharmacy_queue_number,
            pq.sequence,
            pq.counter_number,
            pq.status,
            pq.called_at,
            CONCAT(p.first_name, ' ', SUBSTRING(p.last_name, 1, 3), '..') as patient_name
        FROM pharmacy_queues pq
        JOIN visits v ON pq.visit_id = v.id
        JOIN patients p ON v.patient_id = p.id
        WHERE DATE(pq.created_at) = CURDATE() AND pq.status = 'called'
        ORDER BY pq.called_at DESC LIMIT 4
    ")->fetchAll();

    $pharmacy_waiting = $pdo->query("
        SELECT 
            pharmacy_queue_number,
            sequence,
            counter_number,
            status
        FROM pharmacy_queues
        WHERE DATE(created_at) = CURDATE() AND status IN ('waiting', 'preparing')
        ORDER BY sequence ASC LIMIT 8
    ")->fetchAll();

    $pharmacy_last_called = $pdo->query("
        SELECT pharmacy_queue_number, counter_number, called_at
        FROM pharmacy_queues
        WHERE DATE(created_at) = CURDATE() AND status = 'called' AND called_at IS NOT NULL
        ORDER BY called_at DESC LIMIT 1
    ")->fetch();

    echo json_encode([
        'success'              => true,
        'timestamp'            => date('H:i:s'),
        'calling'              => $calling,
        'waiting'              => $waiting,
        'last_called'          => $last_called ?: null,
        'pharmacy_calling'     => $pharmacy_calling,
        'pharmacy_waiting'     => $pharmacy_waiting,
        'pharmacy_last_called' => $pharmacy_last_called ?: null
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
