#!/bin/sh
# Khởi động OmniStock trong container: (tùy chọn) MariaDB nội bộ -> Apache nghe đúng cổng Render cấp
set -e

# Render cấp cổng qua biến PORT (mặc định 10000); Apache trong image mặc định nghe cổng 80
PORT="${PORT:-10000}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

if [ -z "$DB_HOST" ]; then
    # Chế độ gói gọn: MariaDB chạy cùng container. Ổ đĩa của gói miễn phí không lưu lâu dài,
    # nên mỗi lần container khởi động là một CSDL mới nạp từ dữ liệu mẫu.
    echo "[omnistock] Khởi động MariaDB nội bộ..."
    mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
    if [ ! -d /var/lib/mysql/mysql ]; then
        mariadb-install-db --user=mysql --datadir=/var/lib/mysql >/dev/null
    fi
    mariadbd-safe --skip-syslog >/dev/null 2>&1 &

    tries=0
    until mariadb-admin ping --silent >/dev/null 2>&1; do
        tries=$((tries + 1))
        if [ "$tries" -gt 90 ]; then
            echo "[omnistock] MariaDB không khởi động được sau 90 giây." >&2
            exit 1
        fi
        sleep 1
    done

    if ! mariadb -e "USE quanlykhohang" >/dev/null 2>&1; then
        echo "[omnistock] Nạp dữ liệu mẫu..."
        mariadb --default-character-set=utf8mb4 < /opt/omnistock/quanlykhohang.sql
    fi

    # Tài khoản riêng cho web, mật khẩu ngẫu nhiên mỗi lần khởi động (root chỉ đăng nhập qua socket nội bộ)
    DB_PASS="$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24)"
    mariadb -e "CREATE USER IF NOT EXISTS 'omnistock'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
              ALTER USER 'omnistock'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
              GRANT ALL PRIVILEGES ON quanlykhohang.* TO 'omnistock'@'127.0.0.1';
              FLUSH PRIVILEGES;"
    export DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=quanlykhohang DB_USER=omnistock DB_PASS
    echo "[omnistock] CSDL sẵn sàng."
fi

echo "[omnistock] Apache nghe cổng ${PORT}"
exec apache2-foreground
