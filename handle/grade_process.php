<?php
// session_start();
require_once __DIR__ . '/../functions/grade_functions.php';

// Kiểm tra action được truyền qua URL hoặc POST
$action = '';
if (isset($_GET['action'])) {
    $action = $_GET['action'];
} elseif (isset($_POST['action'])) {
    $action = $_POST['action'];
}

switch ($action) {
    case 'create':
        handleCreateGrade();
        break;
    case 'edit':
        handleEditGrade();
        break;
    case 'delete':
        handleDeleteGrade();
        break;
    // default:
    //     header("Location: ../views/grade.php?error=Hành động không hợp lệ");
    //     exit();
}
/**
 * Lấy tất cả danh sách điểm
 */
function handleGetAllGrades() {
    return getAllGrades();
}

function handleGetGradeById($id) {
    return getGradeById($id);
}

/**
 * Xử lý tạo điểm mới
 */
function handleCreateGrade() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header("Location: ../views/grade.php?error=Phương thức không hợp lệ");
        exit();
    }
    
    if (!isset($_POST['student_id']) || !isset($_POST['subject_id']) || !isset($_POST['grade'])) {
        header("Location: ../views/grade/create_grade.php?error=Thiếu thông tin cần thiết");
        exit();
    }
    
    $student_id = trim($_POST['student_id']);
    $subject_id = trim($_POST['subject_id']);
    $grade = trim($_POST['grade']);
    
    // Validate dữ liệu
    if (empty($student_id) || empty($subject_id) || empty($grade)) {
        header("Location: ../views/grade/create_grade.php?error=Vui lòng điền đầy đủ thông tin");
        exit();
    }

    // Kiểm tra điểm hợp lệ (từ 0 đến 10)
    if (!is_numeric($grade) || $grade < 0 || $grade > 10) {
        header("Location: ../views/grade/create_grade.php?error=Điểm phải là số từ 0 đến 10");
        exit();
    }
    
    // Kiểm tra xem sinh viên đã có điểm môn học này chưa
    if (checkGradeExists($student_id, $subject_id)) {
        header("Location: ../views/grade/create_grade.php?error=Sinh viên đã có điểm môn học này");
        exit();
    }
    
    $success = addGrade($student_id, $subject_id, $grade);
    
    if ($success) {
        header("Location: ../views/grade.php?success=Thêm điểm thành công");
    } else {
        header("Location: ../views/grade/create_grade.php?error=Có lỗi xảy ra khi thêm điểm");
    }
    exit();
}

/**
 * Xử lý cập nhật điểm
 */
function handleEditGrade() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header("Location: ../views/grade.php?error=Phương thức không hợp lệ");
        exit();
    }
    
    if (!isset($_POST['id']) || !isset($_POST['student_id']) || !isset($_POST['subject_id']) || !isset($_POST['grade'])) {
        header("Location: ../views/grade.php?error=Thiếu thông tin cần thiết");
        exit();
    }
    
    $id = (int)$_POST['id'];
    $student_id = trim($_POST['student_id']);
    $subject_id = trim($_POST['subject_id']);
    $grade = trim($_POST['grade']);
    
    // Validate dữ liệu
    if (empty($student_id) || empty($subject_id) || empty($grade)) {
        header("Location: ../views/grade/edit_grade.php?id=$id&error=Vui lòng điền đầy đủ thông tin");
        exit();
    }

    // Kiểm tra điểm hợp lệ (từ 0 đến 10)
    if (!is_numeric($grade) || $grade < 0 || $grade > 10) {
        header("Location: ../views/grade/edit_grade.php?id=$id&error=Điểm phải là số từ 0 đến 10");
        exit();
    }
    
    // Kiểm tra xem grade có tồn tại không
    $currentGrade = getGradeById($id);
    if (!$currentGrade) {
        header("Location: ../views/grade.php?error=Điểm không tồn tại");
        exit();
    }
    
    // Kiểm tra nếu thay đổi student_id hoặc subject_id thì có bị trùng không
    if ($currentGrade['student_id'] != $student_id || $currentGrade['subject_id'] != $subject_id) {
        if (checkGradeExists($student_id, $subject_id)) {
            header("Location: ../views/grade/edit_grade.php?id=$id&error=Sinh viên đã có điểm môn học này");
            exit();
        }
    }
    
    $success = updateGrade($id, $student_id, $subject_id, $grade);
    
    if ($success) {
        header("Location: ../views/grade.php?success=Cập nhật điểm thành công");
    } else {
        header("Location: ../views/grade/edit_grade.php?id=$id&error=Có lỗi xảy ra khi cập nhật điểm");
    }
    exit();
}

/**
 * Xử lý xóa điểm
 */
function handleDeleteGrade() {
    if (!isset($_GET['id'])) {
        header("Location: ../views/grade.php?error=Thiếu ID điểm");
        exit();
    }
    
    $id = (int)$_GET['id'];
    
    // Kiểm tra xem grade có tồn tại không
    $grade = getGradeById($id);
    if (!$grade) {
        header("Location: ../views/grade.php?error=Điểm không tồn tại");
        exit();
    }
    
    $success = deleteGrade($id);
    
    if ($success) {
        header("Location: ../views/grade.php?success=Xóa điểm thành công");
    } else {
        header("Location: ../views/grade.php?error=Có lỗi xảy ra khi xóa điểm");
    }
    exit();
}
?>
