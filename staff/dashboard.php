<?php
session_start();

require_once "../config/database.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// =====================================================
// CEK LOGIN & ROLE
// =====================================================
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
    header("Location: ../login.php");
    exit;
}

// =====================================================
// DATA STAFF
// =====================================================
$userId    = $_SESSION['user_id'];
$userName  = $_SESSION['name'] ?? 'Staff';
$userEmail = $_SESSION['email'] ?? '';

// =====================================================
// HELPER
// =====================================================
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function rupiah($number): string
{
    return 'Rp ' . number_format((float)$number, 0, ',', '.');
}

function getColumnNames(mysqli $conn, string $table): array
{
    // Nama tabel selalu konstanta di file ini.
    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM `$table`");
    while ($column = $result->fetch_assoc()) {
        $columns[] = $column['Field'];
    }
    return $columns;
}

function firstExisting(array $candidates, array $available): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $available, true)) {
            return $candidate;
        }
    }
    return null;
}

try {

    // =================================================
    // DETEKSI NAMA KOLOM
    // =================================================
    $orderCols  = getColumnNames($conn, 'orders');
    $detailCols = getColumnNames($conn, 'order_details');
    $menuCols   = getColumnNames($conn, 'menus');

    $statusCol  = firstExisting(['order_status', 'status'], $orderCols);
    $amountCol  = firstExisting(['total_amount', 'total', 'grand_total'], $orderCols);
    $paymentCol = firstExisting(['payment_method', 'payment'], $orderCols);
    $typeCol    = firstExisting(['order_type', 'type'], $orderCols);

    if ($statusCol === null) {
        throw new RuntimeException("Kolom status tidak ditemukan di tabel orders. Kolom yang ada: " . implode(', ', $orderCols));
    }
    if ($amountCol === null) {
        throw new RuntimeException("Kolom total tidak ditemukan di tabel orders. Kolom yang ada: " . implode(', ', $orderCols));
    }

    // Status yang dianggap selesai (menghasilkan revenue)
    $doneStatuses = "('Completed', 'Success')";

    // =================================================
    // TODAY / YESTERDAY
    // =================================================
    $todayOrders = (int)$conn->query("
        SELECT COUNT(*) AS c FROM orders
        WHERE DATE(order_date) = CURDATE()
    ")->fetch_assoc()['c'];

    $yesterdayOrders = (int)$conn->query("
        SELECT COUNT(*) AS c FROM orders
        WHERE DATE(order_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
    ")->fetch_assoc()['c'];

    $todayRevenue = (float)$conn->query("
        SELECT COALESCE(SUM(`$amountCol`), 0) AS r FROM orders
        WHERE DATE(order_date) = CURDATE()
        AND `$statusCol` IN $doneStatuses
    ")->fetch_assoc()['r'];

    $yesterdayRevenue = (float)$conn->query("
        SELECT COALESCE(SUM(`$amountCol`), 0) AS r FROM orders
        WHERE DATE(order_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
        AND `$statusCol` IN $doneStatuses
    ")->fetch_assoc()['r'];

    // =================================================
    // PAGINATION
    // =================================================
    $ordersPerPage = 8;
    $currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($currentPage < 1) {
        $currentPage = 1;
    }

    $totalOrders = $todayOrders;
    $totalPages  = max(1, (int)ceil($totalOrders / $ordersPerPage));
    if ($currentPage > $totalPages) {
        $currentPage = $totalPages;
    }
    $offset = ($currentPage - 1) * $ordersPerPage;

    // =================================================
    // QUERY ORDER HARI INI
    // =================================================
    $paymentSelect = $paymentCol ? ", o.`$paymentCol` AS payment_method" : ", NULL AS payment_method";
    $typeSelect    = $typeCol ? ", o.`$typeCol` AS order_type" : ", NULL AS order_type";

    $sqlOrders = "
        SELECT
            o.order_id,
            o.user_id,
            o.order_date,
            o.`$amountCol` AS total_amount,
            o.`$statusCol` AS order_status
            $paymentSelect
            $typeSelect,
            u.name AS customer_name
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.user_id
        WHERE DATE(o.order_date) = CURDATE()
        ORDER BY o.order_id DESC
        LIMIT " . (int)$ordersPerPage . "
        OFFSET " . (int)$offset . "
    ";

    // =================================================
    // QUERY DETAIL ITEM (disiapkan sekali)
    // =================================================
    $detailHasName = in_array('menu_name', $detailCols, true);
    $joinMenus     = '';

    if ($detailHasName) {
        $nameSelect = "od.menu_name AS menu_name";
    } elseif (in_array('menu_name', $menuCols, true) && in_array('menu_id', $detailCols, true)) {
        $nameSelect = "m.menu_name AS menu_name";
        $joinMenus  = "LEFT JOIN menus m ON m.menu_id = od.menu_id";
    } else {
        $nameSelect = "'Menu' AS menu_name";
    }

    $qtySelect  = in_array('quantity', $detailCols, true) ? "od.quantity AS quantity" : "0 AS quantity";
    $sizeSel    = in_array('size', $detailCols, true) ? "od.size AS size" : "'' AS size";
    $tempSel    = in_array('temperature', $detailCols, true) ? "od.temperature AS temperature" : "'' AS temperature";
    $noteSel    = in_array('note', $detailCols, true) ? "od.note AS note" : "'' AS note";
    $detailSort = in_array('order_detail_id', $detailCols, true) ? "ORDER BY od.order_detail_id ASC" : "";

    $sqlDetails = "
        SELECT $nameSelect, $qtySelect, $sizeSel, $tempSel, $noteSel
        FROM order_details od
        $joinMenus
        WHERE od.order_id = ?
        $detailSort
    ";

    $detailStmt = $conn->prepare($sqlDetails);

    // =================================================
    // PROSES DATA ORDER
    // =================================================
    $orders = [];
    $resultOrders = $conn->query($sqlOrders);

    while ($order = $resultOrders->fetch_assoc()) {
        $orderId = (int)$order['order_id'];

        $detailStmt->bind_param("i", $orderId);
        $detailStmt->execute();
        $detailResult = $detailStmt->get_result();

        $menuItems = [];
        while ($detail = $detailResult->fetch_assoc()) {
            $menuItems[] = [
                'menu_name'   => $detail['menu_name'] ?? '',
                'quantity'    => (int)($detail['quantity'] ?? 0),
                'size'        => $detail['size'] ?? '',
                'temperature' => $detail['temperature'] ?? '',
                'note'        => $detail['note'] ?? ''
            ];
        }

        $orders[] = [
            'order_id'       => $orderId,
            'order_number'   => 'ORD-' . str_pad((string)$orderId, 4, '0', STR_PAD_LEFT),
            'time'           => !empty($order['order_date']) ? date('H:i', strtotime($order['order_date'])) : '-',
            'customer'       => $order['customer_name'] ?: 'Customer',
            'type'           => $order['order_type'] ?: '-',
            'payment_method' => $order['payment_method'] ?: '-',
            'status'         => $order['order_status'] ?: '-',
            'total_amount'   => (float)($order['total_amount'] ?? 0),
            'menu'           => $menuItems
        ];
    }

    $detailStmt->close();

} catch (Throwable $ex) {
    die('Dashboard error: ' . e($ex->getMessage()));
}

// Class CSS untuk badge status
function statusClass(string $status): string
{
    $status = strtolower($status);
    if ($status === 'completed' || $status === 'success') {
        return 'complete';
    }
    if ($status === 'cancelled' || $status === 'canceled') {
        return 'cancelled';
    }
    if ($status === 'processing') {
        return 'processing';
    }
    return 'pending';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - LÜMA</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css">
</head>
<body>

<div class="dashboard">

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="logo">
            <img src="../assets/Group 194.png" alt="LÜMA Logo">
        </div>

        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item active">
                <i class="fa-solid fa-table-cells-large"></i>
                <span>Dashboard</span>
            </a>

            <a href="order_receive.php" class="menu-item">
                <i class="fa-solid fa-receipt"></i>
                <span>Order Receive</span>
            </a>

            <a href="menu.php" class="menu-item">
                <i class="fa-solid fa-mug-hot"></i>
                <span>Menu</span>
            </a>
        </nav>

        <a href="../logout.php" class="logout">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-content">

        <!-- TOPBAR -->
        <header class="topbar">
            <div class="topbar-title">
                <h2>Dashboard</h2>
            </div>

            <div class="cashier-profile">
                <button type="button" class="notification" aria-label="Notifications">
                    <i class="fa-regular fa-bell"></i>
                </button>

                <div class="profile-info">
                    <strong><?= e($userName) ?></strong>
                    <small>Staff</small>
                </div>
            </div>
        </header>

        <section class="content">

            <!-- WELCOME -->
            <div class="welcome">
                <h1>Welcome back, <?= e($userName) ?>! 👋</h1>
                <p>Here's today's summary.</p>
            </div>

            <!-- SUMMARY CARDS -->
            <div class="summary-cards">

                <div class="summary-card">
                    <div class="card-icon">
                        <i class="fa-solid fa-cube"></i>
                    </div>
                    <div class="card-info">
                        <span class="card-title">Today's Orders</span>
                        <strong class="card-value"><?= $todayOrders ?></strong>
                        <span class="card-condition">
                            <?php $orderDifference = $todayOrders - $yesterdayOrders; ?>
                            <?= $orderDifference >= 0 ? '+ ' . $orderDifference : $orderDifference ?>
                            from yesterday
                        </span>
                    </div>
                </div>

                <div class="summary-card">
                    <div class="card-icon">
                        <i class="fa-solid fa-money-bill"></i>
                    </div>
                    <div class="card-info">
                        <span class="card-title">Today's Revenue</span>
                        <strong class="card-value"><?= rupiah($todayRevenue) ?></strong>
                        <span class="card-condition">
                            <?php $revenueDifference = $todayRevenue - $yesterdayRevenue; ?>
                            <?= $revenueDifference >= 0 ? '+ ' . rupiah($revenueDifference) : '- ' . rupiah(abs($revenueDifference)) ?>
                            from yesterday
                        </span>
                    </div>
                </div>

            </div>

            <!-- RECENT ORDERS -->
            <section class="orders-section">

                <div class="section-header">
                    <div class="section-title">
                        <h1>Recent Orders</h1>
                        <p>Today's transactions</p>
                    </div>

                    <a href="receive_order.php" class="new-order-btn">
                        <i class="fa-solid fa-plus"></i>
                        New Order
                    </a>
                </div>

                <div class="orders-card">

                    <div class="orders-table">

                        <!-- TABLE HEADER -->
                        <div class="order-row order-header">
                            <div>Order Number</div>
                            <div>Time</div>
                            <div>Menu</div>
                            <div>Customer Name</div>
                            <div>Order Type</div>
                            <div>Payment Method</div>
                            <div>Status</div>
                            <div>Action</div>
                        </div>

                        <?php if (empty($orders)): ?>

                            <div class="no-orders">
                                <i class="fa-solid fa-mug-hot"></i>
                                <span>No orders today</span>
                            </div>

                        <?php else: ?>

                            <?php foreach ($orders as $order): ?>

                                <div class="order-row">

                                    <div class="order-number"><?= e($order['order_number']) ?></div>

                                    <div><?= e($order['time']) ?></div>

                                    <div class="menu-column">
                                        <?php if (!empty($order['menu'])): ?>
                                            <?php foreach ($order['menu'] as $menu): ?>
                                                <div class="menu-item-row">
                                                    <strong><?= e($menu['menu_name']) ?></strong>
                                                    <small>
                                                        <?= (int)$menu['quantity'] ?>x
                                                        <?php if (!empty($menu['size'])): ?>
                                                            · <?= e($menu['size']) ?>
                                                        <?php endif; ?>
                                                        <?php if (!empty($menu['temperature'])): ?>
                                                            · <?= e($menu['temperature']) ?>
                                                        <?php endif; ?>
                                                    </small>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="no-menu">-</span>
                                        <?php endif; ?>
                                    </div>

                                    <div><?= e($order['customer']) ?></div>

                                    <div><?= e($order['type']) ?></div>

                                    <div class="payment-method"><?= e($order['payment_method']) ?></div>

                                    <div>
                                        <span class="status <?= statusClass($order['status']) ?>">
                                            <?= e($order['status']) ?>
                                        </span>
                                    </div>

                                    <div>
                                        <a href="order_detail.php?id=<?= (int)$order['order_id'] ?>" class="details-btn">
                                            Details
                                        </a>
                                    </div>

                                </div>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                    <!-- FOOTER / PAGINATION -->
                    <?php if ($totalOrders > 0): ?>

                        <div class="table-footer">

                            <div>
                                Showing
                                <?= (($currentPage - 1) * $ordersPerPage) + 1 ?>
                                -
                                <?= min($currentPage * $ordersPerPage, $totalOrders) ?>
                                of
                                <?= $totalOrders ?>
                                orders today
                            </div>

                            <div class="pagination">

                                <?php if ($currentPage > 1): ?>
                                    <a href="?page=<?= $currentPage - 1 ?>" class="page-btn">
                                        <i class="fa-solid fa-chevron-left"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="page-btn disabled">
                                        <i class="fa-solid fa-chevron-left"></i>
                                    </span>
                                <?php endif; ?>

                                <?php for ($page = 1; $page <= $totalPages; $page++): ?>
                                    <a
                                        href="?page=<?= $page ?>"
                                        class="page-btn <?= $page === $currentPage ? 'active' : '' ?>"
                                    ><?= $page ?></a>
                                <?php endfor; ?>

                                <?php if ($currentPage < $totalPages): ?>
                                    <a href="?page=<?= $currentPage + 1 ?>" class="page-btn">
                                        <i class="fa-solid fa-chevron-right"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="page-btn disabled">
                                        <i class="fa-solid fa-chevron-right"></i>
                                    </span>
                                <?php endif; ?>

                            </div>

                        </div>

                    <?php endif; ?>

                </div>

            </section>

        </section>

    </main>

</div>

</body>
</html>