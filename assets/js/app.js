/**
 * assets/js/app.js
 * JavaScript สำหรับระบบเสียงเรียกคิว และฟังก์ชันเสริม
 */

// ระบบสังเคราะห์เสียงเรียกคิวภาษาไทย (Web Speech API)
function speakQueue(queueNumber, departmentName) {
    if (!('speechSynthesis' in window)) {
        console.warn('เบราว์เซอร์นี้ไม่รองรับการสังเคราะห์เสียง (Web Speech API)');
        return;
    }

    // แปลงรหัสคิวให้ออกเสียงชัดเจน เช่น 'MED-001' -> 'เอ็ม อี ดี ศูนย์ ศูนย์ หนึ่ง'
    let spokenNumber = queueNumber.replace(/-/g, ' ');
    // แทนที่ตัวเลข 0-9 เป็นคำภาษาไทย
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
    
    // แปลงตัวเลขทีละหลัก
    let convertedNumber = '';
    for (let char of spokenNumber) {
        if (digitsThai[char] !== undefined) {
            convertedNumber += digitsThai[char];
        } else {
            convertedNumber += char;
        }
    }

    const textToSpeak = `ขอเชิญหมายเลข ${convertedNumber} ที่${departmentName} ค่ะ`;

    window.speechSynthesis.cancel(); // หยุดเสียงเก่าก่อน
    const utterance = new SpeechSynthesisUtterance(textToSpeak);
    utterance.lang = 'th-TH';
    utterance.rate = 0.9; // ความเร็วพอดีๆ ชัดเจน
    utterance.pitch = 1.0;

    // ค้นหาเสียงภาษาไทยถ้ามี
    const voices = window.speechSynthesis.getVoices();
    const thaiVoice = voices.find(v => v.lang.includes('th') || v.lang.includes('TH'));
    if (thaiVoice) {
        utterance.voice = thaiVoice;
    }

    window.speechSynthesis.speak(utterance);
}

// DOM Events
document.addEventListener('DOMContentLoaded', () => {
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
