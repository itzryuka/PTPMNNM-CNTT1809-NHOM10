<?php
require_once __DIR__ . '/../functions/auth.php';
checkLogin(__DIR__ . '/../index.php');
?>
<!DOCTYPE html>
<html>

<head>
    <title>DNU - OpenSource</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <?php include './menu.php'; ?>
    <div class="container mt-3">

        <h3 class="mt-3">DANH SÁCH HỌC PHẦN</h3>

        <?php
        // Hiển thị thông báo thành công
        if (isset($_GET['success'])) {
            echo '<div class="alert alert-success alert-dismissible fade show" role="alert">
                ' . htmlspecialchars($_GET['success']) . '
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>';
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

        <a href="subject/create_subject.php" class="btn btn-primary mb-3">Create</a>
        <table class="table table-bordered">
            <thead>
                <tr>
                    <!-- <th scope="col">STT</th> -->
                    <th scope="col">ID</th>
                    <th scope="col">Mã học phần</th>
                    <th scope="col">Tên học phần</th>
                    <th scope="col">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php
                require_once '../functions/subject_functions.php';
                $subjects = getAllSubjects();

                foreach($subjects as $index => $sub){
                    $stt = $index + 1;
            ?>
                <tr>
                    <td><?= $sub["id"] ?></td>
                    <td><?= $sub["subject_code"] ?></td>
                    <td><?= $sub["subject_name"] ?></td>
                    <td>
                        <a href="subject/edit_subject.php?id=<?= $sub["id"] ?>" class="btn btn-warning btn-sm">Edit</a>
                        <a href="../handle/subject_process.php?action=delete&id=<?= $sub["id"] ?>"
                            class="btn btn-danger btn-sm"
                            onclick="return confirm('Bạn có chắc chắn muốn xóa học phần này?')">Delete</a>
                    </td>
                </tr>
                <?php } ?>

            </tbody>
        </table>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>


</html>