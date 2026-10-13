<?php
// login.php - หน้าเข้าสู่ระบบเจ้าหน้าที่
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

            set_flash('success', 'ยินดีต้อนรับเข้าสู่ระบบ ' . htmlspecialchars($_SESSION['staff_name']));
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
    <style>
        body {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .login-card {
            background: #ffffff;
            width: 100%;
            max-width: 440px;
            border-radius: var(--radius-lg);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
            padding: 40px 32px;
            border: 1px solid var(--border);
        }
        .login-header {
            text-align: center;
            margin-bottom: 28px;
        }
        .login-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #0284c7, #06b6d4);
            color: #fff;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 16px;
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.4);
        }
        .login-title {
            font-size: 1.45rem;
            font-weight: 700;
            color: #0f172a;
        }
        .login-desc {
            font-size: 0.88rem;
            color: #64748b;
            margin-top: 4px;
        }
        .demo-box {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: var(--radius-md);
            padding: 12px 14px;
            margin-top: 24px;
            font-size: 0.82rem;
            color: #475569;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="login-header">
        <div class="login-icon">
            <i class="fa-solid fa-hospital"></i>
        </div>
        <h1 class="login-title">ระบบลงทะเบียนและจัดการคิว</h1>
        <p class="login-desc">Hospital Patient Registration & Queue System</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom: 20px;">
            <div>
                <i class="fa-solid fa-circle-exclamation"></i>
                <span style="margin-left: 6px;"><?= htmlspecialchars($error) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <div class="form-group">
            <label for="username"><i class="fa-regular fa-user"></i> ชื่อผู้ใช้ (Username)</label>
            <input type="text" id="username" name="username" placeholder="กรอกชื่อผู้ใช้..." required autofocus value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label for="password"><i class="fa-solid fa-lock"></i> รหัสผ่าน (Password)</label>
            <input type="password" id="password" name="password" placeholder="กรอกรหัสผ่าน..." required>
        </div>

        <button type="submit" class="btn btn-primary btn-lg" style="width: 100%; margin-top: 10px;">
            <i class="fa-solid fa-right-to-bracket"></i> เข้าสู่ระบบ
        </button>
    </form>

    <div class="demo-box">
        <strong><i class="fa-solid fa-key"></i> บัญชีทดสอบเริ่มต้น (ตามฐานข้อมูล):</strong><br>
        • ผู้ดูแลระบบ: user: <code>admin</code> / pass: <code>admin123</code><br>
        • เจ้าหน้าที่ลงทะเบียน: user: <code>staff1</code> / pass: <code>staff123</code>
    </div>
</div>

</body>
</html>
