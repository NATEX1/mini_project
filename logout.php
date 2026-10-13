<?php
// logout.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION = [];
session_destroy();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/functions.php';
set_flash('info', 'คุณได้ออกจากระบบเรียบร้อยแล้ว');
header('Location: login.php');
exit;
