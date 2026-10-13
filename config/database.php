<?php
// config/database.php - การเชื่อมต่อฐานข้อมูล MySQL ด้วย PDO

$db_host = getenv('DB_HOST') ?: '127.0.0.1';
$db_port = getenv('DB_PORT') ?: '3306';
$db_name = getenv('DB_NAME') ?: 'hospital_queue_db';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';

try {
    $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    // แสดงคำแนะนำการแก้ไขหากยังไม่ได้สร้างฐานข้อมูลหรือรหัสผ่านไม่ถูกต้อง
    die('
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 25px; border: 1px solid #f87171; border-radius: 8px; background: #fef2f2; color: #991b1b;">
        <h2 style="margin-top:0;">⚠️ ไม่สามารถเชื่อมต่อฐานข้อมูลได้ (Database Connection Error)</h2>
        <p><strong>ข้อความแจ้งเตือน:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>
        <hr style="border: 0; border-top: 1px solid #fecaca; margin: 15px 0;">
        <h4>วิธีแก้ไข:</h4>
        <ol style="padding-left: 20px; line-height: 1.6;">
            <li>เปิด <strong>phpMyAdmin</strong> แล้วนำไฟล์ <code>schema.sql</code> ไปกด <strong>Import (นำเข้า)</strong> เพื่อสร้างฐานข้อมูล <code>hospital_queue_db</code></li>
            <li>ตรวจสอบการตั้งค่า Host, Username, Password ในไฟล์ <code>config/database.php</code> ให้ตรงกับโปรแกรมของคุณ (เช่น XAMPP ค่าเริ่มต้นคือ user: <code>root</code>, pass: ว่างเปล่า)</li>
        </ol>
    </div>
    ');
}
