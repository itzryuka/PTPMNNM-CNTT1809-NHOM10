<?php
// session_start();
require_once __DIR__ . '/../functions/student_functions.php';

// Kiểm tra action được truyền qua URL hoặc POST
$action = '';
if (isset($_GET['action'])) {
    $action = $_GET['action'];
} elseif (isset($_POST['action'])) {
    $action = $_POST['action'];
}

switch ($action) {
    case 'create':
        handleCreateStudent();
        break;
    case 'edit':
        handleEditStudent();
        break;
    case 'delete':
        handleDeleteStudent();
        break;
    // default:
    //     header("Location: ../views/student.php?error=Hành động không hợp lệ");
    //     exit();
}
/**
 * Lấy tất cả danh sách sinh viên
 */
function handleGetAllStudents() {
    return getAllStudents();
}

function handleGetStudentById($id) {
    return getStudentById($id);
}

/**
 * Xử lý tạo sinh viên mới
 */
function handleCreateStudent() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header("Location: ../views/student.php?error=Phương thức không hợp lệ");
        exit();
    }
    
    if (!isset($_POST['student_code']) || !isset($_POST['student_name'])) {
        header("Location: ../views/student/create_student.php?error=Thiếu thông tin cần thiết");
        exit();
    }
    
    $student_code = trim($_POST['student_code']);
    $student_name = trim($_POST['student_name']);
    
    // Validate dữ liệu
    if (empty($student_code) || empty($student_name)) {
        header("Location: ../views/student/create_student.php?error=Vui lòng điền đầy đủ thông tin");
        exit();
    }
    
    // Gọi hàm thêm sinh viên
    $result = addStudent($student_code, $student_name);
    
    if ($result) {
        header("Location: ../views/student.php?success=Thêm sinh viên thành công");
    } else {
        header("Location: ../views/student/create_student.php?error=Có lỗi xảy ra khi thêm sinh viên");
    }
    exit();
}

/**
 * Xử lý chỉnh sửa sinh viên
 */
function handleEditStudent() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header("Location: ../views/student.php?error=Phương thức không hợp lệ");
        exit();
    }
    
    if (!isset($_POST['id']) || !isset($_POST['student_code']) || !isset($_POST['student_name'])) {
        header("Location: ../views/student.php?error=Thiếu thông tin cần thiết");
        exit();
    }
    
    $id = $_POST['id'];
    $student_code = trim($_POST['student_code']);
    $student_name = trim($_POST['student_name']);
    
    // Validate dữ liệu
    if (empty($student_code) || empty($student_name)) {
        header("Location: ../views/edit_student.php?id=" . $id . "&error=Vui lòng điền đầy đủ thông tin");
        exit();
    }
    
    // Gọi function để cập nhật sinh viên
    $result = updateStudent($id, $student_code, $student_name);
    
    if ($result) {
        header("Location: ../views/student.php?success=Cập nhật sinh viên thành công");
    } else {
        header("Location: ../views/edit_student.php?id=" . $id . "&error=Cập nhật sinh viên thất bại");
    }
    exit();
}

/**
 * Xử lý xóa sinh viên
 */
function handleDeleteStudent() {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header("Location: ../views/student.php?error=Phương thức không hợp lệ");
        exit();
    }
    
    if (!isset($_GET['id']) || empty($_GET['id'])) {
        header("Location: ../views/student.php?error=Không tìm thấy ID sinh viên");
        exit();
    }
    
    $id = $_GET['id'];
    
    // Validate ID là số
    if (!is_numeric($id)) {
        header("Location: ../views/student.php?error=ID sinh viên không hợp lệ");
        exit();
    }
    
    // Gọi function để xóa sinh viên
    $result = deleteStudent($id);
    
    if ($result) {
        header("Location: ../views/student.php?success=Xóa sinh viên thành công");
    } else {
        header("Location: ../views/student.php?error=Xóa sinh viên thất bại");
    }
    exit();
}
?>
