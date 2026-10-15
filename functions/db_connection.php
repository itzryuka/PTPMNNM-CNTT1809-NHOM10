<?php

function getDbConnection() {
    $servername = "localhost";
    $username = "root";
    $password = "12345678";
    $dbname = "quanlysinhvien";
    $port = 3306;

    // Tạo kết nối
    $conn = mysqli_connect($servername, $username, $password, $dbname, $port);

    // Kiểm tra kết nối
    if (!$conn) {
        die("Kết nối database thất bại: " . mysqli_connect_error());
    }
    // Thiết lập charset cho kết nối (quan trọng để hiển thị tiếng Việt đúng)
    mysqli_set_charset($conn, "utf8");
    return $conn;
}

?>