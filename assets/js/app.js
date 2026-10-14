/**
 * assets/js/app.js
 * JavaScript สำหรับระบบเสียงเรียกคิว และฟังก์ชันเสริม
 */

// ระบบสังเคราะห์เสียงเรียกคิวภาษาไทย (Web Speech API)
function convertQueueNumberToThaiSpeech(queueNumber) {
    let spokenNumber = queueNumber.replace(/-/g, ' ');
    const digitsThai = {
        '0': ' ศูนย์ ',
        '1': ' หนึ่ง ',
        '2': ' สอง ',
        '3': ' สาม ',
        '4': ' สี่ ',
        '5': ' ห้า ',
        '6': ' หก ',
        '7': ' เจ็ด ',
        '8': ' แปด ',
        '9': ' เก้า '
    };
    
    let converted = '';
    for (let char of spokenNumber) {
        if (digitsThai[char] !== undefined) {
            converted += digitsThai[char];
        } else {
            converted += char;
        }
    }
    return converted;
}

function speakTextThai(textToSpeak) {
    if (!('speechSynthesis' in window)) {
        console.warn('เบราว์เซอร์นี้ไม่รองรับการสังเคราะห์เสียง (Web Speech API)');
        return;
    }
    window.speechSynthesis.cancel(); // หยุดเสียงเดิม
    const utterance = new SpeechSynthesisUtterance(textToSpeak);
    utterance.lang = 'th-TH';
    utterance.rate = 0.9;
    utterance.pitch = 1.0;

    const voices = window.speechSynthesis.getVoices();
    const thaiVoice = voices.find(v => v.lang.includes('th') || v.lang.includes('TH'));
    if (thaiVoice) {
        utterance.voice = thaiVoice;
    }
    window.speechSynthesis.speak(utterance);
}

function speakQueue(queueNumber, departmentName, roomName = '') {
    const convertedNumber = convertQueueNumberToThaiSpeech(queueNumber);
    const destination = roomName ? `${departmentName} ${roomName}` : departmentName;
    const textToSpeak = `ขอเชิญหมายเลข ${convertedNumber} ที่${destination} ค่ะ`;
    speakTextThai(textToSpeak);
}

function speakPharmacyQueue(rxNumber, counterName = 'ช่องจ่ายยา 1') {
    const convertedNumber = convertQueueNumberToThaiSpeech(rxNumber);
    const textToSpeak = `ขอเชิญคิวรับยาหมายเลข ${convertedNumber} ที่${counterName} ค่ะ`;
    speakTextThai(textToSpeak);
}

/* ========================================================
   จอ TV (PIDS): นาฬิกา / สวิตช์เสียง / เต็มจอ
   ใช้ร่วมกันทั้งจอคิวตรวจแพทย์และจอห้องจ่ายยา
   ======================================================== */
let isSoundEnabled = true;

function toggleFullscreen() {
    if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen().catch(err => console.log(err));
    } else if (document.exitFullscreen) {
        document.exitFullscreen();
    }
}

function initPidsControls() {
    // นาฬิกาเรียลไทม์
    const clock = document.getElementById('digital-clock');
    if (clock) {
        const tick = () => {
            const now = new Date();
            clock.textContent = [now.getHours(), now.getMinutes(), now.getSeconds()]
                .map(n => String(n).padStart(2, '0'))
                .join(':');
        };
        tick();
        setInterval(tick, 1000);
    }

    // สวิตช์เปิด/ปิดเสียงขาน
    const soundBtn = document.getElementById('toggle-sound-btn');
    const soundStatus = document.getElementById('sound-status');
    if (soundBtn && soundStatus) {
        const soundIcon = soundBtn.querySelector('i');
        soundBtn.addEventListener('click', () => {
            isSoundEnabled = !isSoundEnabled;
            soundStatus.textContent = isSoundEnabled ? 'เปิด' : 'ปิด';
            soundBtn.classList.toggle('is-muted', !isSoundEnabled);
            soundBtn.setAttribute('aria-pressed', String(isSoundEnabled));
            if (soundIcon) {
                soundIcon.className = isSoundEnabled ? 'fa-solid fa-volume-high' : 'fa-solid fa-volume-xmark';
            }
        });
    }
}

// DOM Events
document.addEventListener('DOMContentLoaded', () => {
    // 0. ควบคุมจอ TV ถ้าหน้านี้เป็นจอแสดงผล
    initPidsControls();

    // 1. ซ่อน Alert อัตโนมัติหลังจาก 4 วินาที
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.5s ease';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        }, 4000);
    });

    // 2. ควบคุมการเปิด/ปิด Sidebar บนจอมือถือและแท็บเล็ต
    const sidebarToggle = document.getElementById('sidebarToggle');
    const appSidebar = document.getElementById('appSidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    if (sidebarToggle && appSidebar) {
        sidebarToggle.addEventListener('click', () => {
            appSidebar.classList.toggle('open');
            if (sidebarOverlay) {
                sidebarOverlay.classList.toggle('active');
            }
        });
    }

    if (sidebarOverlay && appSidebar) {
        sidebarOverlay.addEventListener('click', () => {
            appSidebar.classList.remove('open');
            sidebarOverlay.classList.remove('active');
        });
    }
});
