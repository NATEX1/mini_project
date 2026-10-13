<?php
// queue_display.php - หน้าจอแสดงผลคิวสำหรับผู้รับบริการ (TV Public Queue Display)
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$departments = $pdo->query("SELECT * FROM departments WHERE is_active = 1 ORDER BY department_name ASC")->fetchAll();
$dept_id = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : 0;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จอแสดงผลคิวผู้รับบริการ - โรงพยาบาล</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="tv-display-body">

<div class="tv-header">
    <div class="tv-title">
        <i class="fa-solid fa-hospital"></i>
        <span>ระบบแสดงผลคิวโรงพยาบาล</span>
        <?php if ($dept_id > 0): ?>
            <?php 
            $current_dept = '';
            foreach ($departments as $d) {
                if ($d['id'] == $dept_id) $current_dept = $d['department_name'];
            }
            ?>
            <span style="color: #f59e0b; font-size: 1.25rem;">(<?= htmlspecialchars($current_dept) ?>)</span>
        <?php endif; ?>
    </div>

    <div style="display: flex; align-items: center; gap: 20px;">
        <!-- ตัวเลือกแผนก -->
        <select onchange="location.href='queue_display.php?dept_id=' + this.value" style="background: #1e293b; color: #fff; border: 1px solid #475569; padding: 6px 12px; border-radius: 6px; font-size: 0.9rem;">
            <option value="0">-- แสดงผลทุกแผนก --</option>
            <?php foreach ($departments as $d): ?>
                <option value="<?= $d['id'] ?>" <?= ($dept_id === (int)$d['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d['department_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <!-- สวิตช์เปิด/ปิดเสียง -->
        <button id="toggle-sound-btn" class="btn btn-outline" style="color: #38bdf8; border-color: #38bdf8; padding: 6px 12px; font-size: 0.88rem;">
            <i class="fa-solid fa-volume-high"></i> เสียงขาน: <span id="sound-status">เปิด</span>
        </button>

        <!-- เต็มหน้าจอ -->
        <button onclick="toggleFullscreen()" class="btn btn-outline" style="color: #94a3b8; border-color: #475569; padding: 6px 12px; font-size: 0.88rem;">
            <i class="fa-solid fa-expand"></i> เต็มจอ
        </button>

        <!-- นาฬิกาแบบ Real-time -->
        <div class="tv-time" id="digital-clock">00:00:00</div>
    </div>
</div>

<div class="tv-grid">
    <!-- กล่องซ้าย: กำลังเรียก / กำลังรับบริการ -->
    <div class="tv-panel">
        <div class="tv-panel-title">
            <span style="color: #38bdf8;"><i class="fa-solid fa-bullhorn"></i> หมายเลขที่กำลังตรวจ / รับบริการ</span>
            <span style="font-size: 0.9rem; color: #94a3b8;">ห้องตรวจ</span>
        </div>

        <div id="calling-container">
            <div style="text-align: center; padding: 40px; color: #64748b;">
                <i class="fa-solid fa-spinner fa-spin" style="font-size: 2rem;"></i>
                <div style="margin-top: 10px;">กำลังโหลดข้อมูลคิว...</div>
            </div>
        </div>
    </div>

    <!-- กล่องขวา: รอรับบริการ -->
    <div class="tv-panel">
        <div class="tv-panel-title">
            <span style="color: #f59e0b;"><i class="fa-solid fa-clock"></i> ลำดับคิวรอตรวจ</span>
            <span style="font-size: 0.9rem; color: #94a3b8;">จุดพักรอ</span>
        </div>

        <div id="waiting-container">
            <div style="text-align: center; padding: 40px; color: #64748b;">
                กำลังโหลด...
            </div>
        </div>
    </div>
</div>

<script src="assets/js/app.js"></script>
<script>
    const deptId = <?= $dept_id ?>;
    let isSoundEnabled = true;
    let lastSpokenQueueKey = '';

    // นาฬิกาดิจิทัล
    function updateClock() {
        const now = new Date();
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const seconds = String(now.getSeconds()).padStart(2, '0');
        document.getElementById('digital-clock').textContent = `${hours}:${minutes}:${seconds}`;
    }
    setInterval(updateClock, 1000);
    updateClock();

    // ปุ่มเปิด-ปิดเสียง
    const soundBtn = document.getElementById('toggle-sound-btn');
    const soundStatus = document.getElementById('sound-status');
    soundBtn.addEventListener('click', () => {
        isSoundEnabled = !isSoundEnabled;
        soundStatus.textContent = isSoundEnabled ? 'เปิด' : 'ปิด';
        soundBtn.style.color = isSoundEnabled ? '#38bdf8' : '#ef4444';
        soundBtn.style.borderColor = isSoundEnabled ? '#38bdf8' : '#ef4444';
    });

    // ปุ่ม Fullscreen
    function toggleFullscreen() {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen().catch(err => console.log(err));
        } else {
            if (document.exitFullscreen) {
                document.exitFullscreen();
            }
        }
    }

    // ฟังก์ชันดึงข้อมูลคิวผ่าน AJAX
    async function fetchQueueData() {
        try {
            const url = `api/get_queues.php?dept_id=${deptId}`;
            const res = await fetch(url);
            const data = await res.json();

            if (!data.success) return;

            renderCalling(data.calling);
            renderWaiting(data.waiting);

            // ตรวจสอบว่ามีคิวถูกเรียกใหม่เพื่อขานเสียงหรือไม่
            if (data.last_called && isSoundEnabled) {
                const currentKey = `${data.last_called.queue_number}_${data.last_called.called_at}`;
                if (lastSpokenQueueKey !== '' && lastSpokenQueueKey !== currentKey) {
                    speakQueue(data.last_called.queue_number, data.last_called.department_name);
                }
                lastSpokenQueueKey = currentKey;
            } else if (data.last_called && lastSpokenQueueKey === '') {
                // จำค่าครั้งแรกไว้เพื่อไม่ให้เปิดจอแล้วตะโกนเรียกย้อนหลัง
                lastSpokenQueueKey = `${data.last_called.queue_number}_${data.last_called.called_at}`;
            }

        } catch (e) {
            console.error('Fetch error:', e);
        }
    }

    // วาดกล่อง Calling
    function renderCalling(callingList) {
        const container = document.getElementById('calling-container');
        if (!callingList || callingList.length === 0) {
            container.innerHTML = `
                <div style="text-align: center; padding: 50px 20px; color: #64748b;">
                    <i class="fa-solid fa-circle-check" style="font-size: 3rem; margin-bottom: 12px; opacity: 0.3; display:block;"></i>
                    <div style="font-size: 1.2rem;">ไม่มีคิวที่กำลังรับบริการในขณะนี้</div>
                </div>
            `;
            return;
        }

        let html = '';
        callingList.forEach((q, idx) => {
            if (idx === 0) {
                // คิวแรกเด่นสุด มี animation
                html += `
                    <div class="tv-calling-card">
                        <div>
                            <div style="font-size: 0.95rem; text-transform: uppercase; color: #e0f2fe; letter-spacing: 1px; margin-bottom: 4px;">
                                <i class="fa-solid fa-volume-high"></i> ขอเชิญหมายเลข
                            </div>
                            <div class="tv-number-huge">${q.queue_number}</div>
                            <div style="font-size: 1.15rem; color: #f0fdf4; margin-top: 6px; font-weight: 500;">
                                ผู้รับบริการ: ${q.patient_name}
                            </div>
                        </div>
                        <div style="text-align: right; background: rgba(0,0,0,0.25); padding: 14px 20px; border-radius: 12px;">
                            <div style="font-size: 0.9rem; color: #cbd5e1;">ห้องตรวจ / แผนก</div>
                            <div style="font-size: 1.5rem; font-weight: 700; color: #ffffff;">${q.department_name}</div>
                            <div style="font-size: 0.85rem; color: #bae6fd; margin-top: 4px;">${q.status === 'called' ? '• เรียกคิว' : '• กำลังตรวจ'}</div>
                        </div>
                    </div>
                `;
            } else {
                // คิวถัดมา
                html += `
                    <div style="background: rgba(15, 23, 42, 0.6); border: 1px solid #334155; border-radius: 12px; padding: 16px 20px; display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                        <div style="display: flex; align-items: center; gap: 18px;">
                            <span style="font-size: 2rem; font-weight: 800; color: #38bdf8;">${q.queue_number}</span>
                            <span style="color: #cbd5e1; font-size: 1rem;">${q.patient_name}</span>
                        </div>
                        <div style="color: #94a3b8; font-size: 1rem; font-weight: 500;">${q.department_name}</div>
                    </div>
                `;
            }
        });
        container.innerHTML = html;
    }

    // วาดกล่อง Waiting
    function renderWaiting(waitingList) {
        const container = document.getElementById('waiting-container');
        if (!waitingList || waitingList.length === 0) {
            container.innerHTML = `
                <div style="text-align: center; padding: 50px 20px; color: #64748b;">
                    <div style="font-size: 1.1rem;">ไม่มีคิวรอรับบริการ</div>
                </div>
            `;
            return;
        }

        let html = '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">';
        waitingList.forEach(q => {
            html += `
                <div style="background: rgba(15, 23, 42, 0.6); border: 1px solid #334155; border-radius: 10px; padding: 14px; text-align: center;">
                    <div style="font-size: 1.8rem; font-weight: 800; color: #fbbf24;">${q.queue_number}</div>
                    <div style="font-size: 0.85rem; color: #94a3b8; margin-top: 2px;">${q.department_code}</div>
                </div>
            `;
        });
        html += '</div>';
        container.innerHTML = html;
    }

    // ทำงานและตั้งเวลาโพลทุกๆ 3 วินาที
    fetchQueueData();
    setInterval(fetchQueueData, 3000);
</script>

</body>
</html>
