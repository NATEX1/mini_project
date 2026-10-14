<?php
// login.php - หน้าเข้าสู่ระบบเจ้าหน้าที่ (Clinical Workstation Authentication)
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM staff WHERE username = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$username]);
        $staff = $stmt->fetch();

        if ($staff && password_verify($password, $staff['password_hash'])) {
            // เข้าสู่ระบบสำเร็จ
            $_SESSION['staff_id'] = $staff['id'];
            $_SESSION['username'] = $staff['username'];
            $_SESSION['staff_name'] = $staff['first_name'] . ' ' . $staff['last_name'];
            $_SESSION['role'] = $staff['role'];
            $_SESSION['department_id'] = $staff['department_id'] ?? null;
            $_SESSION['room_id'] = $staff['room_id'] ?? null;

            set_flash('success', 'ยินดีต้อนรับเข้าสู่ระบบ ' . $_SESSION['staff_name']);
            header('Location: index.php');
            exit;
        } else {
            $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง หรือบัญชีถูกระงับการใช้งาน';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - ระบบลงทะเบียนและจัดการคิวโรงพยาบาล</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="login-page-body">

<div class="login-card-authoritative">
    <div class="login-brand-header">
        <div class="login-brand-cross">
            <i class="fa-solid fa-hospital"></i>
        </div>
        <h1 class="login-brand-title">ระบบลงทะเบียนและจัดการคิว</h1>
        <p class="login-brand-sub">โรงพยาบาลทั่วไป • Patient Registration & Clinical Queue</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom: 20px;">
            <div>
                <i class="fa-solid fa-circle-exclamation"></i>
                <span style="margin-left: 6px;"><?= htmlspecialchars($error) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php" id="loginForm">
        <div class="form-group">
            <label for="username">ชื่อผู้ใช้ (Username)</label>
            <input type="text" id="username" name="username" placeholder="กรอกชื่อผู้ใช้..." required autofocus value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label for="password">รหัสผ่าน (Password)</label>
            <input type="password" id="password" name="password" placeholder="กรอกรหัสผ่าน..." required>
        </div>

        <button type="submit" class="btn btn-primary btn-lg" style="width: 100%; margin-top: 6px;">
            <i class="fa-solid fa-right-to-bracket"></i> เข้าสู่ระบบ
        </button>
    </form>

    <div class="login-roster-box">
        <div class="login-roster-header">
            <span><i class="fa-solid fa-id-card-clip"></i> บัญชีทดสอบระบบตามฐานข้อมูล</span>
            <span style="color: var(--slate-400); font-weight: normal;">คลิกเพื่อกรอกอัตโนมัติ</span>
        </div>
        <table class="login-roster-table">
            <tbody>
                <tr>
                    <td class="login-roster-role">ผู้ดูแลระบบ</td>
                    <td class="login-roster-user">admin / admin123</td>
                    <td style="text-align: right;">
                        <button type="button" class="btn-quick-fill" onclick="fillLogin('admin', 'admin123')">กรอก</button>
                    </td>
                </tr>
                <tr>
                    <td class="login-roster-role">แพทย์ (อายุรกรรม ห้อง 101)</td>
                    <td class="login-roster-user">doctor1 / admin123</td>
                    <td style="text-align: right;">
                        <button type="button" class="btn-quick-fill" onclick="fillLogin('doctor1', 'admin123')">กรอก</button>
                    </td>
                </tr>
                <tr>
                    <td class="login-roster-role">แพทย์ (กุมารเวชกรรม ห้อง 201)</td>
                    <td class="login-roster-user">doctor2 / admin123</td>
                    <td style="text-align: right;">
                        <button type="button" class="btn-quick-fill" onclick="fillLogin('doctor2', 'admin123')">กรอก</button>
                    </td>
                </tr>
                <tr>
                    <td class="login-roster-role">เจ้าหน้าที่ลงทะเบียน</td>
                    <td class="login-roster-user">staff1 / staff123</td>
                    <td style="text-align: right;">
                        <button type="button" class="btn-quick-fill" onclick="fillLogin('staff1', 'staff123')">กรอก</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
function fillLogin(u, p) {
    document.getElementById('username').value = u;
    document.getElementById('password').value = p;
    document.getElementById('password').focus();
}
</script>

</body>
</html>
