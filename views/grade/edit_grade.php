<?php
require_once __DIR__ . '/../../functions/auth.php';
checkLogin(__DIR__ . '/../../index.php');
require_once __DIR__ . '/../../functions/grade_functions.php';
?>
<!DOCTYPE html>
<html>

<head>
    <title>DNU - Chỉnh sửa điểm</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <div class="container mt-3">
        <h3 class="mt-3 mb-4 text-center">CHỈNH SỬA ĐIỂM</h3>
        <?php
            // Kiểm tra có ID không
            if (!isset($_GET['id']) || empty($_GET['id'])) {
                header("Location: ../grade.php?error=Không tìm thấy điểm");
                exit;
            }
            
            $id = $_GET['id'];
            
            // Lấy thông tin điểm
            require_once __DIR__ . '/../../handle/grade_process.php';
            $gradeInfo = handleGetGradeById($id);

            if (!$gradeInfo) {
                header("Location: ../grade.php?error=Không tìm thấy điểm");
                exit;
            }
            
            // Lấy danh sách sinh viên và môn học cho dropdown
            $students = getAllStudentsForDropdown();
            $subjects = getAllSubjectsForDropdown();
            
            // Hiển thị thông báo lỗi
            if (isset($_GET['error'])) {
                echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                    ' . htmlspecialchars($_GET['error']) . '
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>';
            }
        ?>
            <script>
            // Sau 3 giây sẽ tự động ẩn alert
            setTimeout(() => {
                let alertNode = document.querySelector('.alert');
                if (alertNode) {
                    let bsAlert = bootstrap.Alert.getOrCreateInstance(alertNode);
                    bsAlert.close();
                }
            }, 3000);
            </script>
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <form action="../../handle/grade_process.php" method="POST">
                                <input type="hidden" name="action" value="edit">
                                <input type="hidden" name="id" value="<?php echo htmlspecialchars($gradeInfo['id']); ?>">

                                <div class="mb-3">
                                    <label for="student_id" class="form-label">Sinh viên</label>
                                    <select class="form-select" id="student_id" name="student_id" required>
                                        <option value="">-- Chọn sinh viên --</option>
                                        <?php foreach ($students as $student): ?>
                                            <option value="<?= $student['id'] ?>" 
                                                <?= $student['id'] == $gradeInfo['student_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($student['student_code']) ?> - <?= htmlspecialchars($student['student_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="subject_id" class="form-label">Môn học</label>
                                    <select class="form-select" id="subject_id" name="subject_id" required>
                                        <option value="">-- Chọn môn học --</option>
                                        <?php foreach ($subjects as $subject): ?>
                                            <option value="<?= $subject['id'] ?>" 
                                                <?= $subject['id'] == $gradeInfo['subject_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($subject['subject_code']) ?> - <?= htmlspecialchars($subject['subject_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="grade" class="form-label">Điểm (0-10)</label>
                                    <input type="number" class="form-control" id="grade" name="grade" 
                                           min="0" max="10" step="0.1" 
                                           value="<?php echo htmlspecialchars($gradeInfo['grade']); ?>" required>
                                </div>

                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <a href="../grade.php" class="btn btn-secondary me-md-2">Hủy</a>
                                    <button type="submit" class="btn btn-primary">Cập nhật</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
