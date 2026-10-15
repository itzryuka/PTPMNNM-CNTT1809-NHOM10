<?php
require_once __DIR__ .'/../../functions/auth.php';
checkLogin(__DIR__ .'/../../index.php');
?>
<!DOCTYPE html>
<html>

<head>
    <title>DNU - OpenSource</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <div class="container mt-3">
        <h3 class="mt-3 mb-4 text-center">CHỈNH SỬA HỌC PHẦN</h3>
        
        <?php
        // Kiểm tra có ID không
        if (!isset($_GET['id']) || empty($_GET['id'])) {
            header("Location: ../subject.php?error=Không tìm thấy học phần");
            exit;
        }
        
        $id = $_GET['id'];
        
        // Lấy thông tin học phần
        require_once __DIR__ .'/../../handle/subject_process.php';
        $subject = handleGetSubjectById($id);

        if (!$subject) {
            header("Location: ../subject.php?error=Không tìm thấy học phần");
            exit;
        }
        
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
                        <form action="../../handle/subject_process.php" method="POST">
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($subject['id']); ?>">
                            
                            <div class="mb-3">
                                <label for="subject_code" class="form-label">Mã học phần</label>
                                <input type="text" class="form-control" id="subject_code" name="subject_code" 
                                       value="<?php echo htmlspecialchars($subject['subject_code']); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="subject_name" class="form-label">Tên học phần</label>
                                <input type="text" class="form-control" id="subject_name" name="subject_name" 
                                       value="<?php echo htmlspecialchars($subject['subject_name']); ?>" required>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="../subject.php" class="btn btn-secondary me-md-2">Hủy</a>
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
