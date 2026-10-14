<?php
// pharmacy_display.php - จอแสดงผลคิวห้องจ่ายยาสำหรับผู้รับบริการ (TV Public Pharmacy Display)
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จอแสดงผลคิวรับยา - ห้องจ่ายยา (Pharmacy PIDS)</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="tv-pids-body">

<!-- Header แถบข้อมูลและแถบควบคุมบนสุด -->
<header class="pids-header">
    <div class="pids-brand">
        <div class="pids-brand-icon rx">
            <i class="fa-solid fa-pills"></i>
        </div>
        <div>
            <div class="pids-brand-title">ห้องจ่ายยาและเวชภัณฑ์ (Pharmacy)</div>
            <div class="pids-brand-subtitle">กรุณารอฟังเสียงเรียกและติดต่อตามช่องบริการที่ระบุ</div>
        </div>
    </div>

    <div class="pids-controls">
        <a href="queue_display.php" class="pids-btn" title="สลับไปจอตรวจแพทย์">
            <i class="fa-solid fa-stethoscope"></i> จอคิวตรวจแพทย์
        </a>

        <button id="toggle-sound-btn" class="pids-btn" type="button" aria-pressed="true">
            <i class="fa-solid fa-volume-high"></i> เสียงขาน: <span id="sound-status">เปิด</span>
        </button>

        <button class="pids-btn" type="button" onclick="toggleFullscreen()" title="โหมดเต็มจอ">
            <i class="fa-solid fa-expand"></i> เต็มจอ
        </button>

        <div class="pids-clock" id="digital-clock">00:00:00</div>
    </div>
</header>

<!-- Main Workspace Layout Grid -->
<main class="pids-grid">
    <!-- กล่องซ้าย: หมายเลขที่กำลังเรียกรับยา (Calling RX) -->
    <section class="pids-panel">
        <div class="pids-panel-header">
            <h2 class="pids-panel-title rx">
                <i class="fa-solid fa-bullhorn"></i> หมายเลขคิวที่กำลังเรียกรับยา
            </h2>
            <span class="pids-panel-badge">ช่องบริการจ่ายยา</span>
        </div>

        <div id="calling-container">
            <div class="pids-loading">
                <i class="fa-solid fa-spinner fa-spin pids-loading-icon pids-spinner rx"></i>
                <div class="pids-loading-text">กำลังโหลดข้อมูลคิวห้องจ่ายยา...</div>
            </div>
        </div>
    </section>

    <!-- กล่องขวา: หมายเลขคิวรอจัดยา / รอรับยา (Waiting RX) -->
    <section class="pids-panel">
        <div class="pids-panel-header">
            <h2 class="pids-panel-title">
                <i class="fa-solid fa-clock-rotate-left"></i> ลำดับคิวที่รอรับบริการ
            </h2>
            <span class="pids-panel-badge" id="waiting-count-badge">รอรับยา 0 คิว</span>
        </div>

        <div id="waiting-container">
            <div class="pids-loading">
                <div class="pids-loading-text">กำลังโหลด...</div>
            </div>
        </div>
    </section>
</main>

<script src="assets/js/app.js"></script>
<script>
    let lastSpokenRxKey = '';

    // ดึงข้อมูลผ่าน AJAX Polling
    async function fetchPharmacyQueueData() {
        try {
            const res = await fetch('api/get_queues.php');
            const data = await res.json();

            if (!data.success) return;

            renderCalling(data.pharmacy_calling);
            renderWaiting(data.pharmacy_waiting);

            // ตรวจสอบคิวที่เพิ่งถูกเรียกเพื่อส่งเสียงขาน
            if (data.pharmacy_last_called && isSoundEnabled) {
                const currentKey = `${data.pharmacy_last_called.pharmacy_queue_number}_${data.pharmacy_last_called.called_at}`;
                if (lastSpokenRxKey !== '' && lastSpokenRxKey !== currentKey) {
                    speakPharmacyQueue(data.pharmacy_last_called.pharmacy_queue_number, data.pharmacy_last_called.counter_number || 'ช่องจ่ายยา 1');
                }
                lastSpokenRxKey = currentKey;
            } else if (data.pharmacy_last_called && lastSpokenRxKey === '') {
                lastSpokenRxKey = `${data.pharmacy_last_called.pharmacy_queue_number}_${data.pharmacy_last_called.called_at}`;
            }

        } catch (e) {
            console.error('Fetch error:', e);
        }
    }

    // วาดคิวที่กำลังเรียก
    function renderCalling(callingList) {
        const container = document.getElementById('calling-container');
        if (!callingList || callingList.length === 0) {
            container.innerHTML = `
                <div class="pids-empty">
                    <div class="pids-empty-title">ยังไม่มีคิวเรียกรับยาในขณะนี้</div>
                    <div class="pids-empty-hint">ระบบจะขานเรียกและแสดงผลทันทีเมื่อจัดยาเสร็จสิ้น</div>
                </div>
            `;
            return;
        }

        let heroHtml = '';
        let historyHtml = '';

        callingList.forEach((rx, idx) => {
            if (idx === 0) {
                heroHtml = `
                    <div class="pids-hero-card rx-mode">
                        <div>
                            <div class="pids-hero-meta-label">
                                เชิญหมายเลขคิว
                            </div>
                            <div class="pids-huge-number rx">${rx.pharmacy_queue_number}</div>
                            <div class="pids-hero-patient">
                                ผู้รับบริการ: ${rx.patient_name}
                            </div>
                        </div>

                        <div class="pids-signpost rx">
                            <div class="pids-signpost-label">กรุณาติดต่อที่</div>
                            <div class="pids-signpost-title rx">${rx.counter_number || 'ช่องจ่ายยา 1'}</div>
                            <div class="pids-signpost-sub">พร้อมรับยาและคำแนะนำ</div>
                        </div>
                    </div>
                `;
            } else {
                historyHtml += `
                    <div class="pids-history-item">
                        <div class="pids-history-id">
                            <span class="pids-history-num rx">${rx.pharmacy_queue_number}</span>
                            <span class="pids-history-name">${rx.patient_name}</span>
                        </div>
                        <div class="pids-history-dest">
                            ${rx.counter_number || 'ช่องจ่ายยา 1'}
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

        container.innerHTML = finalHtml;
    }

    // วาดคิวที่รอจัดยา
    function renderWaiting(waitingList) {
        const container = document.getElementById('waiting-container');
        const badge = document.getElementById('waiting-count-badge');

        const totalWaiting = waitingList ? waitingList.length : 0;
        if (badge) {
            badge.textContent = `รอรับยา ${totalWaiting} คิว`;
        }

        if (!waitingList || waitingList.length === 0) {
            container.innerHTML = `
                <div class="pids-empty">
                    <div class="pids-empty-title">ไม่มีคิวรอรับยาในขณะนี้</div>
                </div>
            `;
            return;
        }

        let html = '<div class="pids-waiting-grid">';
        waitingList.forEach(rx => {
            const isPrep = rx.status === 'preparing';
            const statusLabel = isPrep ? 'กำลังจัดยา' : 'รอจัดยา';
            const statusClass = isPrep ? 'pids-tag-prep' : 'pids-tag-wait';

            html += `
                <div class="pids-waiting-card">
                    <div class="pids-waiting-num">${rx.pharmacy_queue_number}</div>
                    <span class="pids-waiting-tag ${statusClass}">${statusLabel}</span>
                </div>
            `;
        });
        html += '</div>';

        container.innerHTML = html;
    }

    // เรียกครั้งแรก และ Polling ทุก 3 วินาที
    fetchPharmacyQueueData();
    setInterval(fetchPharmacyQueueData, 3000);
</script>

</body>
</html>
