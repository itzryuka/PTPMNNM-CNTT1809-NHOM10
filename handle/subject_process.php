<?php
// session_start();
require_once __DIR__ . '/../functions/subject_functions.php';

// Kiểm tra action được truyền qua URL hoặc POST
$action = '';
if (isset($_GET['action'])) {
    $action = $_GET['action'];
} elseif (isset($_POST['action'])) {
    $action = $_POST['action'];
}

switch ($action) {
    case 'create':
        handleCreateSubject();
        break;
    case 'edit':
        handleEditSubject();
        break;
    case 'delete':
        handleDeleteSubject();
        break;
    // default:
    //     header("Location: ../views/subject.php?error=Hành động không hợp lệ");
    //     exit();
}

function handleGetAllSubjects() {
    return getAllSubjects();
    // Xử lý hiển thị danh sách subjects
}
function handleGetSubjectById($id) {
    return getSubjectById($id);
}

/**
 * Xử lý tạo học phần mới
 */
function handleCreateSubject () {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header("Location: ../views/subject.php?error=Phương thức không hợp lệ");
        exit();
    }
    
    if (!isset($_POST['subject_code']) || !isset($_POST['subject_name'])) {
        header("Location: ../views/subject/create_subject.php?error=Thiếu thông tin cần thiết");
        exit();
    }
    
    $subject_code = trim($_POST['subject_code']);
    $subject_name = trim($_POST['subject_name']);
    
    // Validate dữ liệu
    if (empty($subject_code) || empty($subject_name)) {
        header("Location: ../views/subject/create_subject.php?error=Vui lòng điền đầy đủ thông tin");
        exit();
    }

    // Gọi hàm thêm học phần
    $result = addSubject($subject_code, $subject_name);

    if ($result) {
        header("Location: ../views/subject.php?success=Thêm học phần thành công");
    } else {
        header("Location: ../views/subject/create_subject.php?error=Có lỗi xảy ra khi thêm học phần");
    }
    exit();
}

/**
 * Xử lý chỉnh sửa học phần
 */
function handleEditSubject() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header("Location: ../views/subject.php?error=Phương thức không hợp lệ");
        exit();
    }
    
    if (!isset($_POST['id']) || !isset($_POST['subject_code']) || !isset($_POST['subject_name'])) {
        header("Location: ../views/subject.php?error=Thiếu thông tin cần thiết");
        exit();
    }
    
    $id = $_POST['id'];
    $subject_code = trim($_POST['subject_code']);
    $subject_name = trim($_POST['subject_name']);
    
    // Validate dữ liệu
    if (empty($subject_code) || empty($subject_name)) {
        header("Location: ../views/edit_subject.php?id=" . $id . "&error=Vui lòng điền đầy đủ thông tin");
        exit();
    }

    // Gọi function để cập nhật học phần
    $result = updateSubject($id, $subject_code, $subject_name);
    
    if ($result) {
        header("Location: ../views/subject.php?success=Cập nhật học phần thành công");
    } else {
        header("Location: ../views/edit_subject.php?id=" . $id . "&error=Cập nhật học phần thất bại");
    }
    exit();
}

/**
 * Xử lý xóa học phần
 */
function handleDeleteSubject() {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header("Location: ../views/subject.php?error=Phương thức không hợp lệ");
        exit();
    }
    
    if (!isset($_GET['id']) || empty($_GET['id'])) {
        header("Location: ../views/subject.php?error=Không tìm thấy ID học phần");
        exit();
    }
    
    $id = $_GET['id'];
    
    // Validate ID là số
    if (!is_numeric($id)) {
        header("Location: ../views/subject.php?error=ID học phần không hợp lệ");
        exit();
    }
    
    // Gọi function để xóa học phần
    $result = deleteSubject($id);

    if ($result) {
        header("Location: ../views/subject.php?success=Xóa học phần thành công");
    } else {
        header("Location: ../views/subject.php?error=Xóa học phần thất bại");
    }
    exit();
}
?>
