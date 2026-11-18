# OmniStock trên Render (hoặc máy nào có Docker): PHP 8.2 + Apache.
#   - Không đặt biến DB_HOST: chạy kèm MariaDB ngay trong container, nạp dữ liệu mẫu khi khởi động
#     (miễn phí hoàn toàn, nhưng dữ liệu về lại dữ liệu mẫu mỗi lần container khởi động lại).
#   - Có DB_HOST (+ DB_PORT, DB_NAME, DB_USER, DB_PASS, DB_SSL): dùng MySQL bên ngoài, dữ liệu giữ lâu dài.
# Chi tiết: docs/deploy-render.md
FROM php:8.2-apache

# pdo_mysql cho PHP; mariadb-server cho chế độ chạy kèm CSDL; mbstring + curl đã có sẵn trong image
RUN apt-get update \
    && apt-get install -y --no-install-recommends mariadb-server ca-certificates \
    && docker-php-ext-install pdo_mysql \
    && rm -rf /var/lib/apt/lists/* \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Apache + MariaDB gọn nhẹ cho gói miễn phí của Render (512 MB RAM)
COPY docker/apache.conf /etc/apache2/conf-available/omnistock.conf
COPY docker/mariadb.cnf /etc/mysql/mariadb.conf.d/99-omnistock.cnf
RUN a2enconf omnistock

# Dữ liệu mẫu để ngoài thư mục web (không ai tải được file .sql qua trình duyệt)
COPY database/quanlykhohang.sql /opt/omnistock/quanlykhohang.sql
COPY docker/entrypoint.sh /usr/local/bin/omnistock-entrypoint
RUN chmod +x /usr/local/bin/omnistock-entrypoint

COPY . /var/www/html/
# Bỏ những thứ không được để lộ trên web: script dựng CSDL, bộ test (tạo / xóa CSDL), file Docker
RUN rm -rf /var/www/html/database /var/www/html/tests /var/www/html/docker /var/www/html/Dockerfile /var/www/html/render.yaml

ENTRYPOINT ["omnistock-entrypoint"]
