<?php
// queue_display.php - หน้าจอแสดงผลคิวสำหรับผู้รับบริการ (TV Public Queue Display)
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$departments = $pdo->query("SELECT * FROM departments WHERE is_active = 1 ORDER BY department_name ASC")->fetchAll();

$view = $_GET['view'] ?? '';
$dept_id = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : 0;

if (isset($_GET['scope']) && $_GET['scope'] === 'pharmacy') {
    $view = 'pharmacy';
}
if (isset($_GET['dept_id']) && $_GET['dept_id'] === 'pharmacy') {
    $view = 'pharmacy';
    $dept_id = 0;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จอแสดงผลคิวผู้รับบริการ - โรงพยาบาล (Clinical Queue PIDS)</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="tv-pids-body">

<!-- Header แถบควบคุมบนสุด -->
<header class="pids-header">
    <div class="pids-brand">
        <div class="pids-brand-icon <?= $view === 'pharmacy' ? 'rx' : '' ?>">
            <i class="fa-solid <?= $view === 'pharmacy' ? 'fa-pills' : 'fa-hospital' ?>"></i>
        </div>
        <div>
            <div class="pids-brand-title">
                <?= $view === 'pharmacy' ? 'ห้องจ่ายยาและเวชภัณฑ์ (Pharmacy PIDS)' : 'ระบบแสดงผลคิวผู้ป่วยนอก (Clinical PIDS)' ?>
            </div>
            <div class="pids-brand-subtitle">
                <?php if ($view === 'pharmacy'): ?>
                    จุดจ่ายยา รอฟังเสียงเรียกและติดต่อตามช่องบริการ
                <?php elseif ($dept_id > 0): ?>
                    <?php 
                    $current_dept = '';
                    foreach ($departments as $d) {
                        if ($d['id'] == $dept_id) $current_dept = $d['department_name'];
                    }
                    ?>
                    ประจำแผนก: <strong><?= htmlspecialchars($current_dept) ?></strong>
                <?php else: ?>
                    ทุกแผนกตรวจและห้องจ่ายยา โรงพยาบาลทั่วไป
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="pids-controls">
        <!-- ปุ่มสลับมุมมอง -->
        <a href="queue_display.php" class="pids-btn <?= ($view !== 'pharmacy' && $dept_id === 0) ? 'is-active' : '' ?>">
            <i class="fa-solid fa-layer-group"></i> คิวตรวจทั้งหมด
        </a>
        <a href="pharmacy_display.php" class="pids-btn rx">
            <i class="fa-solid fa-pills"></i> จอแยกห้องยา (TV)
        </a>

        <!-- เลือกแผนกตรวจเฉพาะ (ย่อชื่อให้สั้นพอดีจอ TV: ตัดคำนำหน้าแผนกและวงเล็บภาษาอังกฤษ) -->
        <select class="pids-select" aria-label="เลือกแผนกหรือห้องตรวจ" onchange="switchTvView(this.value)">
            <option value="all" <?= ($view !== 'pharmacy' && $dept_id === 0) ? 'selected' : '' ?>>-- แสดงผลทุกแผนกตรวจ --</option>
            <option value="pharmacy" <?= ($view === 'pharmacy') ? 'selected' : '' ?>>[RX] ห้องจ่ายยา / คิวรับยา</option>
            <optgroup label="แผนกตรวจแพทย์">
                <?php foreach ($departments as $d): ?>
                    <?php $short_dept = trim(preg_replace('/\s*\(.*\)$/', '', preg_replace('/^แผนก/', '', $d['department_name']))); ?>
                    <option value="<?= $d['id'] ?>" <?= ($view !== 'pharmacy' && $dept_id === (int)$d['id']) ? 'selected' : '' ?>>
                        [<?= htmlspecialchars($d['department_code']) ?>] <?= htmlspecialchars($short_dept) ?>
                    </option>
                <?php endforeach; ?>
            </optgroup>
        </select>

        <!-- สวิตช์เปิด/ปิดเสียง -->
        <button id="toggle-sound-btn" class="pids-btn" type="button" aria-pressed="true">
            <i class="fa-solid fa-volume-high"></i> เสียงขาน: <span id="sound-status">เปิด</span>
        </button>

        <!-- เต็มหน้าจอ -->
        <button class="pids-btn" type="button" onclick="toggleFullscreen()" title="โหมดเต็มจอ">
            <i class="fa-solid fa-expand"></i> เต็มจอ
        </button>

        <!-- นาฬิกา Real-time -->
        <div class="pids-clock" id="digital-clock">00:00:00</div>
    </div>
</header>

<?php if ($view === 'pharmacy'): ?>
    <!-- โหมดเฉพาะห้องจ่ายยา -->
    <main class="pids-grid">
        <section class="pids-panel">
            <div class="pids-panel-header">
                <h2 class="pids-panel-title rx">
                    <i class="fa-solid fa-bullhorn"></i> หมายเลขคิวรับยาที่กำลังเรียก
                </h2>
                <span class="pids-panel-badge">ช่องบริการจ่ายยา</span>
            </div>
            <div id="rx-calling-container">
                <div class="pids-loading">
                    <i class="fa-solid fa-spinner fa-spin pids-loading-icon pids-spinner rx"></i>
                    <div class="pids-loading-text">กำลังโหลดข้อมูลคิวรับยา...</div>
                </div>
            </div>
        </section>

        <section class="pids-panel">
            <div class="pids-panel-header">
                <h2 class="pids-panel-title">
                    <i class="fa-solid fa-clock-rotate-left"></i> ลำดับคิวรอรับยา
                </h2>
                <span class="pids-panel-badge" id="rx-waiting-count-badge">รอรับยา 0 คิว</span>
            </div>
            <div id="rx-waiting-container">
                <div class="pids-loading">
                    <div class="pids-loading-text">กำลังโหลด...</div>
                </div>
            </div>
        </section>
    </main>

<?php else: ?>
    <!-- โหมดคิวตรวจแพทย์ + กล่องคิวรับยาด้านล่าง -->
    <main class="pids-grid">
        <!-- กล่องซ้าย: กำลังตรวจในห้องตรวจ -->
        <section class="pids-panel">
            <div class="pids-panel-header">
                <h2 class="pids-panel-title clinic">
                    <i class="fa-solid fa-stethoscope"></i> หมายเลขที่กำลังตรวจรักษา
                </h2>
                <span class="pids-panel-badge">ห้องตรวจแพทย์</span>
            </div>

            <div id="calling-container">
                <div class="pids-loading">
                    <i class="fa-solid fa-spinner fa-spin pids-loading-icon pids-spinner clinic"></i>
                    <div class="pids-loading-text">กำลังโหลดข้อมูลคิวตรวจ...</div>
                </div>
            </div>
        </section>

        <!-- กล่องขวา: ลำดับคิวรอตรวจ -->
        <section class="pids-panel">
            <div class="pids-panel-header">
                <h2 class="pids-panel-title">
                    <i class="fa-solid fa-clock-rotate-left"></i> ลำดับคิวรอตรวจ
                </h2>
                <span class="pids-panel-badge" id="waiting-count-badge">รอรับบริการ</span>
            </div>

            <div id="waiting-container">
                <div class="pids-loading">
                    <div class="pids-loading-text">กำลังโหลด...</div>
                </div>
            </div>
        </section>
    </main>
<?php endif; ?>

<script src="assets/js/app.js"></script>
<script>
    const isPharmacyMode = <?= ($view === 'pharmacy') ? 'true' : 'false' ?>;
    const deptId = <?= $dept_id ?>;
    let lastSpokenQueueKey = '';
    let lastSpokenRxKey = '';

    function switchTvView(val) {
        if (val === 'pharmacy') {
            location.href = 'queue_display.php?scope=pharmacy';
        } else if (val === 'all') {
            location.href = 'queue_display.php';
        } else {
            location.href = 'queue_display.php?dept_id=' + val;
        }
    }

    // ฟังก์ชันดึงข้อมูลคิวผ่าน AJAX
    async function fetchQueueData() {
        try {
            const url = `api/get_queues.php?dept_id=${deptId}`;
            const res = await fetch(url);
            const data = await res.json();

            if (!data.success) return;

            if (isPharmacyMode) {
                renderPharmacyDedicated(data.pharmacy_calling, data.pharmacy_waiting);
            } else {
                renderCalling(data.calling);
                renderWaiting(data.waiting);
            }

            // 1. ตรวจสอบเสียงเรียกคิวตรวจ (เฉพาะเมื่อไม่ใช่โหมดห้องยาล้วน)
            if (!isPharmacyMode && data.last_called && isSoundEnabled) {
                const currentKey = `${data.last_called.queue_number}_${data.last_called.called_at}`;
                if (lastSpokenQueueKey !== '' && lastSpokenQueueKey !== currentKey) {
                    const roomTxt = data.last_called.room_name || (data.last_called.room_number ? `ห้อง ${data.last_called.room_number}` : '');
                    speakQueue(data.last_called.queue_number, data.last_called.department_name, roomTxt);
                }
                lastSpokenQueueKey = currentKey;
            } else if (data.last_called && lastSpokenQueueKey === '') {
                lastSpokenQueueKey = `${data.last_called.queue_number}_${data.last_called.called_at}`;
            }

            // 2. ตรวจสอบเสียงเรียกคิวรับยา (เฉพาะโหมดห้องยา — จอรวมไม่แสดงคิวรับยาแล้ว)
            if (isPharmacyMode && data.pharmacy_last_called && isSoundEnabled) {
                const currentRxKey = `${data.pharmacy_last_called.pharmacy_queue_number}_${data.pharmacy_last_called.called_at}`;
                if (lastSpokenRxKey !== '' && lastSpokenRxKey !== currentRxKey) {
                    speakPharmacyQueue(data.pharmacy_last_called.pharmacy_queue_number, data.pharmacy_last_called.counter_number || 'ช่องจ่ายยา 1');
                }
                lastSpokenRxKey = currentRxKey;
            } else if (data.pharmacy_last_called && lastSpokenRxKey === '') {
                lastSpokenRxKey = `${data.pharmacy_last_called.pharmacy_queue_number}_${data.pharmacy_last_called.called_at}`;
            }

        } catch (e) {
            console.error('Fetch error:', e);
        }
    }

    // วาดกล่อง Calling ตรวจ
    function renderCalling(callingList) {
        const container = document.getElementById('calling-container');
        if (!container) return;

        if (!callingList || callingList.length === 0) {
            container.innerHTML = `
                <div class="pids-empty">
                    <div class="pids-empty-title">ไม่มีคิวที่กำลังรับบริการในขณะนี้</div>
                    <div class="pids-empty-hint">ระบบจะแสดงผลและขานเรียกเมื่อแพทย์กดเรียกคิว</div>
                </div>
            `;
            return;
        }

        let heroHtml = '';
        let historyHtml = '';

        callingList.forEach((q, idx) => {
            const roomTxt = q.room_name ? `${q.room_name} (${q.room_number ? 'ห้อง ' + q.room_number : ''})` : (q.room_number ? `ห้อง ${q.room_number}` : 'ห้องตรวจแพทย์');

            if (idx === 0) {
                heroHtml = `
                    <div class="pids-hero-card">
                        <div>
                            <div class="pids-hero-meta-label">
                                เชิญหมายเลขคิว
                            </div>
                            <div class="pids-huge-number clinic">${q.queue_number}</div>
                            <div class="pids-hero-patient">
                                ผู้รับบริการ: ${q.patient_name}
                            </div>
                        </div>

                        <div class="pids-signpost">
                            <div class="pids-signpost-label">แผนก ${q.department_name}</div>
                            <div class="pids-signpost-title clinic">${roomTxt}</div>
                            <div class="pids-signpost-sub">${q.status === 'called' ? 'เรียกคิวตรวจ' : 'กำลังตรวจรักษา'}</div>
                        </div>
                    </div>
                `;
            } else {
                historyHtml += `
                    <div class="pids-history-item">
                        <div class="pids-history-id">
                            <span class="pids-history-num clinic">${q.queue_number}</span>
                            <span class="pids-history-name">${q.patient_name}</span>
                        </div>
                        <div class="pids-history-dest">
                            ${roomTxt} <span class="pids-history-dest-sub">${q.department_name}</span>
                        </div>
                    </div>
                `;
            }
        });

        let finalHtml = heroHtml;
        if (historyHtml !== '') {
            finalHtml += `
                <div class="pids-history-head">
                    คิวที่กำลังตรวจห้องอื่นๆ
                </div>
                <div class="pids-history-list">
                    ${historyHtml}
                </div>
            `;
        }

        container.innerHTML = finalHtml;
    }

    // วาดกล่อง Waiting ตรวจ
    function renderWaiting(waitingList) {
        const container = document.getElementById('waiting-container');
        const badge = document.getElementById('waiting-count-badge');
        if (!container) return;

        const total = waitingList ? waitingList.length : 0;
        if (badge) {
            badge.textContent = `รอรับบริการ ${total} คิว`;
        }

        if (!waitingList || waitingList.length === 0) {
            container.innerHTML = `
                <div class="pids-empty">
                    <div class="pids-empty-title">ไม่มีคิวรอตรวจในขณะนี้</div>
                </div>
            `;
            return;
        }

        let html = '<div class="pids-waiting-grid">';
        waitingList.forEach(q => {
            const roomLabel = q.room_number ? `ห้อง ${q.room_number}` : q.department_code;
            html += `
                <div class="pids-waiting-card">
                    <div class="pids-waiting-num">${q.queue_number}</div>
                    <span class="pids-waiting-tag pids-tag-wait">${roomLabel}</span>
                </div>
            `;
        });
        html += '</div>';
        container.innerHTML = html;
    }

    // วาดคิวรับยาบนหน้าจอเฉพาะห้องยา (Pharmacy Dedicated Screen)
    function renderPharmacyDedicated(callingRx, waitingRx) {
        const callingBox = document.getElementById('rx-calling-container');
        const waitingBox = document.getElementById('rx-waiting-container');
        const badge = document.getElementById('rx-waiting-count-badge');

        if (!callingBox || !waitingBox) return;

        const totalWaiting = waitingRx ? waitingRx.length : 0;
        if (badge) {
            badge.textContent = `รอรับยา ${totalWaiting} คิว`;
        }

        if (!callingRx || callingRx.length === 0) {
            callingBox.innerHTML = `
                <div class="pids-empty">
                    <div class="pids-empty-title">ยังไม่มีคิวเรียกรับยาในขณะนี้</div>
                </div>
            `;
        } else {
            let heroHtml = '';
            let historyHtml = '';

            callingRx.forEach((r, idx) => {
                if (idx === 0) {
                    heroHtml = `
                        <div class="pids-hero-card rx-mode">
                            <div>
                                <div class="pids-hero-meta-label">
                                    เชิญหมายเลขคิว
                                </div>
                                <div class="pids-huge-number rx">${r.pharmacy_queue_number}</div>
                                <div class="pids-hero-patient">
                                    ผู้รับบริการ: ${r.patient_name}
                                </div>
                            </div>
                            <div class="pids-signpost rx">
                                <div class="pids-signpost-label">กรุณาติดต่อที่</div>
                                <div class="pids-signpost-title rx">${r.counter_number || 'ช่องจ่ายยา 1'}</div>
                                <div class="pids-signpost-sub">พร้อมจ่ายยาและคำแนะนำ</div>
                            </div>
                        </div>
                    `;
                } else {
                    historyHtml += `
                        <div class="pids-history-item">
                            <div class="pids-history-id">
                                <span class="pids-history-num rx">${r.pharmacy_queue_number}</span>
                                <span class="pids-history-name">${r.patient_name}</span>
                            </div>
                            <div class="pids-history-dest">
                                ${r.counter_number || 'ช่องจ่ายยา 1'}
                            </div>
                        </div>
                    `;
                }
            });

            let finalHtml = heroHtml;
            if (historyHtml !== '') {
                finalHtml += `
                    <div class="pids-history-head">
                        คิวที่เรียกก่อนหน้า
                    </div>
                    <div class="pids-history-list">
                        ${historyHtml}
                    </div>
                `;
            }
            callingBox.innerHTML = finalHtml;
        }

        if (!waitingRx || waitingRx.length === 0) {
            waitingBox.innerHTML = `
                <div class="pids-empty">
                    <div class="pids-empty-title">ไม่มีคิวรอรับยาในขณะนี้</div>
                </div>
            `;
        } else {
            let wHtml = '<div class="pids-waiting-grid">';
            waitingRx.forEach(r => {
                const statusTxt = r.status === 'preparing' ? 'กำลังจัดยา' : 'รอจัดยา';
                const statusClass = r.status === 'preparing' ? 'pids-tag-prep' : 'pids-tag-wait';
                wHtml += `
                    <div class="pids-waiting-card">
                        <div class="pids-waiting-num">${r.pharmacy_queue_number}</div>
                        <span class="pids-waiting-tag ${statusClass}">${statusTxt}</span>
                    </div>
                `;
            });
            wHtml += '</div>';
            waitingBox.innerHTML = wHtml;
        }
    }

    // ดึงข้อมูลครั้งแรก และตั้งเวลาโพลทุกๆ 3 วินาที
    fetchQueueData();
    setInterval(fetchQueueData, 3000);
</script>

</body>
</html>
