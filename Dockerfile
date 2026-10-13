FROM php:8.2-apache

# ติดตั้งส่วนเสริม pdo_mysql สำหรับเชื่อมต่อฐานข้อมูล
RUN docker-php-ext-install pdo pdo_mysql

# เปิด mod_rewrite ใน Apache
RUN a2enmod rewrite

# คัดลอกไฟล์โปรเจกต์
COPY . /var/www/html/

# กำหนดสิทธิ์โฟลเดอร์
RUN chown -R www-data:www-data /var/www/html
