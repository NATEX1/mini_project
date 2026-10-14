<?php
// includes/header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// ดึงหน้าปัจจุบันเพื่อทำ Active Menu
$current_page = basename($_SERVER['PHP_SELF']);
$user = current_user();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($page_title) ? htmlspecialchars($page_title) . ' - ' : '' ?>ระบบลงทะเบียนผู้ป่วยและจัดการคิวโรงพยาบาล</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- FontAwesome 6 icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<?php if (is_logged_in()): ?>
<div class="app-layout">
    <!-- Overlay สำหรับจอมือถือ/แท็บเล็ต -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar เมนูด้านซ้าย -->
    <aside class="app-sidebar" id="appSidebar">
        <!-- โลโก้และชื่อระบบ -->
        <div class="sidebar-header">
            <a href="index.php" class="sidebar-brand">
                <div class="brand-icon">
                    <i class="fa-solid fa-hospital"></i>
                </div>
                <div class="brand-info">
                    <span class="brand-title">Hospital Queue</span>
                    <span class="brand-sub">ระบบจัดการคิวผู้ป่วย</span>
                </div>
            </a>
        </div>

        <!-- รายการเมนูใน Sidebar -->
        <div class="sidebar-menu-wrapper">
            <div class="sidebar-label">ภาพรวมระบบ</div>
            <ul class="sidebar-menu">
                <li>
                    <a href="index.php" class="<?= $current_page == 'index.php' ? 'active' : '' ?>">
                        <i class="fa-solid fa-chart-pie nav-icon"></i>
                        <span>แดชบอร์ดภาพรวม</span>
                    </a>
                </li>
            </ul>

            <div class="sidebar-label">บริการและคิวตรวจ</div>
            <ul class="sidebar-menu">
                <li>
                    <a href="patients.php" class="<?= in_array($current_page, ['patients.php', 'patient_add.php', 'patient_edit.php', 'patient_detail.php']) ? 'active' : '' ?>">
                        <i class="fa-solid fa-hospital-user nav-icon"></i>
                        <span>ทะเบียนผู้ป่วย (HN)</span>
                    </a>
                </li>
                <li>
                    <a href="visits.php" class="<?= $current_page == 'visits.php' ? 'active' : '' ?>">
                        <i class="fa-solid fa-ticket nav-icon"></i>
                        <span>ออกบัตรคิวตรวจ</span>
                    </a>
                </li>
                <li>
                    <a href="queues.php" class="<?= $current_page == 'queues.php' ? 'active' : '' ?>">
                        <i class="fa-solid fa-bullhorn nav-icon"></i>
                        <span>จุดเรียกและจัดการคิว</span>
                    </a>
                </li>
                <li>
                    <a href="pharmacy.php" class="<?= in_array($current_page, ['pharmacy.php', 'print_pharmacy.php']) ? 'active' : '' ?>">
                        <i class="fa-solid fa-pills nav-icon"></i>
                        <span>คิวรับยา / ห้องจ่ายยา</span>
                    </a>
                </li>
                <li>
                    <a href="queue_display.php" target="_blank" class="nav-external">
                        <i class="fa-solid fa-tv nav-icon"></i>
                        <span>จอคิวตรวจแพทย์ (TV)</span>
                        <span class="nav-screen-tag">PIDS</span>
                        <i class="fa-solid fa-arrow-up-right-from-square nav-ext-icon"></i>
                    </a>
                </li>
                <li>
                    <a href="pharmacy_display.php" target="_blank" class="nav-external nav-rx-screen">
                        <i class="fa-solid fa-pills nav-icon"></i>
                        <span>จอคิวรับยา (TV)</span>
                        <span class="nav-screen-tag nav-screen-tag-rx">PIDS</span>
                        <i class="fa-solid fa-arrow-up-right-from-square nav-ext-icon"></i>
                    </a>
                </li>
            </ul>

            <div class="sidebar-label">การจัดการระบบ</div>
            <ul class="sidebar-menu">
                <li>
                    <a href="departments.php" class="<?= in_array($current_page, ['departments.php', 'rooms.php']) ? 'active' : '' ?>">
                        <i class="fa-solid fa-stethoscope nav-icon"></i>
                        <span>แผนกและห้องตรวจ</span>
                    </a>
                </li>
                <?php if ($user['role'] === 'ผู้ดูแลระบบ'): ?>
                <li>
                    <a href="staff.php" class="<?= $current_page == 'staff.php' ? 'active' : '' ?>">
                        <i class="fa-solid fa-user-doctor nav-icon"></i>
                        <span>จัดการแพทย์และเจ้าหน้าที่</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>

        <!-- กล่องข้อมูลผู้ใช้ด้านล่างสุดของ Sidebar -->
        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="user-avatar <?= $user['role'] === 'แพทย์' ? 'avatar-doctor' : '' ?>">
                    <?php if ($user['role'] === 'แพทย์'): ?>
                        <i class="fa-solid fa-user-doctor"></i>
                    <?php else: ?>
                        <i class="fa-regular fa-circle-user"></i>
                    <?php endif; ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?= htmlspecialchars($user['name'] ?: $user['username']) ?></div>
                    <div class="user-role"><?= htmlspecialchars($user['role']) ?></div>
                </div>
                <a href="logout.php" class="btn-logout" title="ออกจากระบบ" onclick="return confirm('ต้องการออกจากระบบหรือไม่?');">
                    <i class="fa-solid fa-right-from-bracket"></i>
                </a>
            </div>
        </div>
    </aside>

    <!-- พื้นที่เนื้อหาหลักฝั่งขวา -->
    <div class="app-main">
        <!-- แถบบน (Topbar) แสดงหัวข้อและวันเวลาปัจจุบัน -->
        <header class="app-topbar">
            <div style="display: flex; align-items: center; gap: 14px;">
                <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle Sidebar">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="topbar-title">
                    <i class="fa-solid fa-hospital-user" style="color: var(--primary);"></i>
                    <span>ระบบเวชระเบียนและจัดการคิวโรงพยาบาล</span>
                </div>
            </div>

            <div class="topbar-actions">
                <div class="topbar-date">
                    <i class="fa-regular fa-calendar-check" style="color: var(--primary);"></i>
                    <span><?= thai_datetime(date('Y-m-d H:i:s'), false) ?></span>
                </div>
                <a href="queue_display.php" target="_blank" class="btn btn-outline btn-sm">
                    <i class="fa-solid fa-tv"></i> จอ TV
                </a>
            </div>
        </header>

        <!-- พื้นที่ส่วนเนื้อหาหน้าเว็บ -->
        <main class="main-body">
            <?php 
            $flash = get_flash(); 
            if ($flash): 
            ?>
                <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
                    <div class="alert-body">
                        <?php if ($flash['type'] === 'success'): ?>
                            <i class="fa-solid fa-circle-check"></i>
                        <?php elseif ($flash['type'] === 'danger'): ?>
                            <i class="fa-solid fa-circle-xmark"></i>
                        <?php elseif ($flash['type'] === 'warning'): ?>
                            <i class="fa-solid fa-triangle-exclamation"></i>
                        <?php else: ?>
                            <i class="fa-solid fa-circle-info"></i>
                        <?php endif; ?>
                        <span class="alert-text"><?= htmlspecialchars($flash['message']) ?></span>
                        <?php if (!empty($flash['action'])): ?>
                            <a href="<?= htmlspecialchars($flash['action']['url']) ?>"<?= $flash['action']['blank'] ? ' target="_blank" rel="noopener"' : '' ?> class="btn btn-primary btn-sm alert-action">
                                <i class="fa-solid fa-print"></i> <?= htmlspecialchars($flash['action']['label']) ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
<?php else: ?>
<!-- สำหรับหน้าที่ไม่ได้เข้าสู่ระบบ (เช่น error หรือ redirect) -->
<main class="main-body" style="padding: 24px;">
<?php endif; ?>
