<?php
require_once 'db_connection.php';

/**
 * Lấy tất cả danh sách subjects từ database
 * @return array Danh sách subjects
 */
function getAllSubjects() {
    $conn = getDbConnection();
    
    // Truy vấn lấy tất cả subjects
    $sql = "SELECT id, subject_code, subject_name FROM subjects ORDER BY id";
    $result = mysqli_query($conn, $sql);

    $subjects = [];
    if ($result && mysqli_num_rows($result) > 0) {
        // Lặp qua từng dòng trong kết quả truy vấn $result
        while ($row = mysqli_fetch_assoc($result)) { 
            $subjects[] = $row; // Thêm mảng $row vào cuối mảng $subjects
        }
    }
    
    mysqli_close($conn);
    return $subjects;
}

/**
 * Thêm subject mới
 * @param string $subject_code Mã học phần
 * @param string $subject_name Tên học phần
 * @return bool True nếu thành công, False nếu thất bại
 */
function addSubject($subject_code, $subject_name) {
    $conn = getDbConnection();

    $sql = "INSERT INTO subjects (subject_code, subject_name) VALUES (?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ss", $subject_code, $subject_name);
        $success = mysqli_stmt_execute($stmt);
        
        mysqli_stmt_close($stmt);
        mysqli_close($conn);
        return $success;
    }
    
    mysqli_close($conn);
    return false;
}

/**
 * Lấy thông tin một subject theo ID
 * @param int $id ID của subject
 * @return array|null Thông tin subject hoặc null nếu không tìm thấy
 */
function getSubjectById($id) {
    $conn = getDbConnection();

    $sql = "SELECT id, subject_code, subject_name FROM subjects WHERE id = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($result) > 0) {
            $subject = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);
            mysqli_close($conn);
            return $subject;
        }
        
        mysqli_stmt_close($stmt);
    }
    
    mysqli_close($conn);
    return null;
}

/**
 * Cập nhật thông tin subject
 * @param int $id ID của subject
 * @param string $subject_code Mã học phần mới
 * @param string $subject_name Tên học phần mới
 * @return bool True nếu thành công, False nếu thất bại
 */
function updateSubject($id, $subject_code, $subject_name) {
    $conn = getDbConnection();
    
    $sql = "UPDATE subjects SET subject_code = ?, subject_name = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ssi", $subject_code, $subject_name, $id);
        $success = mysqli_stmt_execute($stmt);
        
        mysqli_stmt_close($stmt);
        mysqli_close($conn);
        return $success;
    }
    
    mysqli_close($conn);
    return false;
}

/**
 * Xóa subject theo ID
 * @param int $id ID của subject cần xóa
 * @return bool True nếu thành công, False nếu thất bại
 */
function deleteSubject($id) {
    $conn = getDbConnection();
    
    $sql = "DELETE FROM subjects WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $id);
        $success = mysqli_stmt_execute($stmt);
        
        mysqli_stmt_close($stmt);
        mysqli_close($conn);
        return $success;
    }
    
    mysqli_close($conn);
    return false;
}
?>
