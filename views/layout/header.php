<?php
/**
 * Phần đầu trang dùng chung: <head>, sidebar, thanh điều hướng trên.
 * Trang gọi cần khai báo trước khi include:
 *   $pageTitle  - tiêu đề hiển thị trên thanh điều hướng
 *   $basePath   - đường dẫn về thư mục gốc: '' (index.php) hoặc '../' (views/*.php)
 *   $activeMenu - trang đang mở: 'dashboard', 'items', 'warehouses', ...
 *   $user       - user đang đăng nhập (từ requireLogin)
 */
// Biến vòng lặp của header đều có tiền tố menu… để không ghi đè biến cùng tên của trang gọi (vd $isOpen)
$flash = takeFlash();
$menuGroups = [
    'manage' => [
        'icon'  => 'fa-box',
        'label' => 'Quản Lý',
        'links' => [
            'warehouses' => ['Danh sách kho', 'views/warehouse_list.php'],
            'items'      => ['Mặt Hàng', 'views/item_list.php'],
            'batches'    => ['Lô Hàng', '#'],
        ],
    ],
    'operations' => [
        'icon'  => 'fa-exchange-alt',
        'label' => 'Nghiệp Vụ Kho',
        'links' => [
            'imports'   => ['Phiếu Nhập Kho', 'views/voucher_list.php?type=IMPORT'],
            'exports'   => ['Phiếu Xuất Kho', 'views/voucher_list.php?type=EXPORT'],
            'transfers' => ['Điều Chuyển', 'views/voucher_list.php?type=TRANSFER'],
        ],
    ],
    'counting' => [
        'icon'  => 'fa-clipboard-check',
        'label' => 'Kiểm kê & Điều chỉnh',
        'links' => [
            'stock_counts' => ['Đợt kiểm kê', 'views/stock_count_list.php'],
            'differences'  => ['Xử lý chênh lệch', 'views/stock_count_list.php?status=COUNTING'],
            'adjustments'  => ['Phiếu điều chỉnh', 'views/voucher_list.php?type=ADJUSTMENT'],
        ],
    ],
    'reports' => [
        'icon'  => 'fa-chart-line',
        'label' => 'Tra cứu & Báo cáo',
        'links' => [
            'current_stock' => ['Tồn kho hiện tại', '#'],
            'ledger'        => ['Sổ phát sinh', '#'],
            'count_report'  => ['Báo cáo kiểm kê', '#'],
        ],
    ],
    'system' => [
        'icon'  => 'fa-user-shield',
        'label' => 'Hệ Thống & Quyền',
        'links' => [
            'users'           => ['Quản lý tài khoản', '#'],
            'user_warehouses' => ['Phân quyền kho', '#'],
        ],
    ],
];

function menuHref(string $href, string $basePath): string
{
    return $href === '#' ? '#' : $basePath . $href;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - Hệ Thống Quản Lý Kho</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?= e(asset($basePath, 'css/style.css')) ?>" rel="stylesheet">
</head>
<body>

    <!-- SIDEBAR -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h2>ERP SYSTEM</h2>
        </div>
        <ul class="menu">
            <li class="menu-item">
                <a class="menu-btn<?= $activeMenu === 'dashboard' ? ' current' : '' ?>" href="<?= $basePath ?>index.php">
                    <i class="fas fa-tachometer-alt icon"></i>
                    <span class="menu-text">DashBoard</span>
                </a>
            </li>

            <?php foreach ($menuGroups as $menuGroup): ?>
                <?php $menuOpen = array_key_exists($activeMenu, $menuGroup['links']); ?>
                <li class="menu-item<?= $menuOpen ? ' active' : '' ?>">
                    <div class="menu-btn" onclick="toggleSubMenu(this)">
                        <i class="fas <?= $menuGroup['icon'] ?> icon"></i>
                        <span class="menu-text"><?= e($menuGroup['label']) ?></span>
                        <i class="fas fa-chevron-down arrow"></i>
                    </div>
                    <ul class="sub-menu">
                        <?php foreach ($menuGroup['links'] as $menuKey => [$menuLabel, $menuLink]): ?>
                            <li>
                                <a href="<?= e(menuHref($menuLink, $basePath)) ?>"<?= $menuKey === $activeMenu ? ' class="current"' : '' ?>><?= e($menuLabel) ?></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content">

        <!-- Top Navbar -->
        <div class="top-navbar">
            <div class="nav-left">
                <button class="toggle-btn" id="toggleBtn"><i class="fas fa-bars"></i></button>
                <h2 class="page-title-nav"><?= e($pageTitle) ?></h2>
            </div>

            <div class="nav-center">
                <div class="nav-search">
                    <input type="text" placeholder="Tìm kiếm chứng từ, mặt hàng...">
                    <i class="fas fa-search"></i>
                </div>
            </div>

            <div class="nav-right">
                <i class="fas fa-bell" title="Thông báo"></i>
                <div class="user-info">
                    <strong><?= e($user['full_name']) ?></strong>
                    <span><?= e(roleLabel($user['role'])) ?></span>
                </div>
                <div class="avatar" title="<?= e($user['username']) ?>"><?= e(userInitials($user['full_name'])) ?></div>
                <form method="post" action="<?= $basePath ?>handle/login_process.php">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button class="toggle-btn" type="submit" title="Đăng xuất"><i class="fas fa-sign-out-alt"></i></button>
                </form>
            </div>
        </div>

        <!-- Content Body -->
        <div class="content-body">

            <?php if (!empty($dbError)): ?>
                <div class="alert-error"><i class="fas fa-exclamation-triangle"></i> <?= e($dbError) ?></div>
            <?php endif; ?>
            <?php if ($flash): ?>
                <div class="alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>">
                    <i class="fas <?= $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?>"></i>
                    <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>
