<?php
session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* =========================================================
   DATABASE
   Error asli MySQL ditampilkan supaya mudah di-debug.
   Hapus/ubah pesan ini saat sudah production.
   ========================================================= */
try {
    $conn = new mysqli("localhost", "root", "", "luma");
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $ex) {
    die('Database connection error: ' . htmlspecialchars($ex->getMessage()));
}

/* =========================================================
   SESSION / PROFILE
   ========================================================= */
$userName = $_SESSION['name']
    ?? $_SESSION['user_name']
    ?? 'Cashier';

/* =========================================================
   HELPER FUNCTIONS
   ========================================================= */
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function rupiah($value): string
{
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function getColumnNames(mysqli $conn, string $table): array
{
    // Nama tabel yang dikirim ke sini selalu konstanta di file ini.
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

/* =========================================================
   DETECT OPTIONAL COLUMNS
   ========================================================= */
try {
    $orderDetailColumns = getColumnNames($conn, 'order_details');
    $menuColumns        = getColumnNames($conn, 'menus');
    $orderColumns       = getColumnNames($conn, 'orders');
} catch (mysqli_sql_exception $ex) {
    die('Table structure error: ' . htmlspecialchars($ex->getMessage()));
}

$statusCol = firstExisting(['order_status', 'status', 'payment_status'], $orderColumns);
$amountCol = firstExisting(['total_amount', 'total', 'grand_total', 'total_price'], $orderColumns);
$typeCol   = firstExisting(['order_type', 'type', 'service_type'], $orderColumns);

if ($statusCol === null) {
    die('Kolom status tidak ditemukan di tabel orders. Kolom yang ada: '
        . htmlspecialchars(implode(', ', $orderColumns)));
}
if ($amountCol === null) {
    die('Kolom total tidak ditemukan di tabel orders. Kolom yang ada: '
        . htmlspecialchars(implode(', ', $orderColumns)));
}

$typeSelect = $typeCol ? ", o.`$typeCol` AS order_type" : ", NULL AS order_type";

$sizeColumn     = firstExisting(['size', 'menu_size', 'drink_size', 'variant', 'item_size'], $orderDetailColumns);
$categoryColumn = firstExisting(['category', 'menu_category', 'menu_type', 'type'], $menuColumns);
$detailOrderBy  = in_array('order_detail_id', $orderDetailColumns, true)
    ? 'ORDER BY od.order_detail_id ASC'
    : '';

/* =========================================================
   UPDATE STATUS
   Checklist hanya mengubah order menjadi Completed.
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);

    if ($orderId && $orderId > 0) {
        $stmt = $conn->prepare(
            "UPDATE orders SET `$statusCol` = 'Completed' WHERE order_id = ?"
        );
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $stmt->close();
    }

    $redirectFilter = $_POST['filter'] ?? 'All';
    $validFilters = ['All', 'Pending', 'Processing', 'Completed'];
    if (!in_array($redirectFilter, $validFilters, true)) {
        $redirectFilter = 'All';
    }

    header('Location: order_receive.php?status=' . urlencode($redirectFilter));
    exit;
}

/* =========================================================
   FILTER
   ========================================================= */
$filter = $_GET['status'] ?? 'All';
$allowedFilters = ['All', 'Pending', 'Processing', 'Completed'];

if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'All';
}

/* =========================================================
   SUMMARY CARDS
   ========================================================= */
$summarySql = "
    SELECT
        COUNT(*) AS total_orders,
        COALESCE(SUM(CASE WHEN `$statusCol` = 'Pending' THEN 1 ELSE 0 END), 0) AS pending_orders,
        COALESCE(SUM(CASE WHEN `$statusCol` = 'Processing' THEN 1 ELSE 0 END), 0) AS processing_orders,
        COALESCE(SUM(CASE WHEN `$statusCol` IN ('Completed', 'Success') THEN 1 ELSE 0 END), 0) AS completed_orders
    FROM orders
";

try {
    $summaryResult = $conn->query($summarySql);
    $summary = $summaryResult->fetch_assoc();
} catch (mysqli_sql_exception $ex) {
    die('Summary query error: ' . htmlspecialchars($ex->getMessage()));
}

$totalOrders      = (int)($summary['total_orders'] ?? 0);
$pendingOrders    = (int)($summary['pending_orders'] ?? 0);
$processingOrders = (int)($summary['processing_orders'] ?? 0);
$completedOrders  = (int)($summary['completed_orders'] ?? 0);

/* =========================================================
   FETCH ORDERS
   Order terbaru di atas.
   ========================================================= */
$sql = "
    SELECT
        o.order_id,
        o.user_id,
        o.order_date,
        o.`$amountCol` AS total_amount,
        o.`$statusCol` AS order_status
        $typeSelect,
        u.name AS customer_name
    FROM orders o
    LEFT JOIN users u ON u.user_id = o.user_id
";

if ($filter !== 'All') {
    $sql .= " WHERE o.`$statusCol` = ? ";
}

$sql .= " ORDER BY o.order_id DESC, o.order_date DESC";

/* Query detail dibuat sekali di luar loop. */
$sizeSelect = $sizeColumn
    ? ", od.`" . $sizeColumn . "` AS item_size"
    : ", NULL AS item_size";

$categorySelect = $categoryColumn
    ? ", m.`" . $categoryColumn . "` AS menu_category"
    : ", '' AS menu_category";

$detailSql = "
    SELECT
        m.menu_name,
        od.quantity,
        od.price,
        od.subtotal
        $sizeSelect
        $categorySelect
    FROM order_details od
    LEFT JOIN menus m ON m.menu_id = od.menu_id
    WHERE od.order_id = ?
    $detailOrderBy
";

$orders = [];

try {
    $stmt = $conn->prepare($sql);
    if ($filter !== 'All') {
        $stmt->bind_param("s", $filter);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $detailStmt = $conn->prepare($detailSql);

    while ($row = $result->fetch_assoc()) {
        $orderId = (int)$row['order_id'];

        $detailStmt->bind_param("i", $orderId);
        $detailStmt->execute();
        $detailResult = $detailStmt->get_result();

        $items = [];

        while ($detail = $detailResult->fetch_assoc()) {
            $rawSize  = trim((string)($detail['item_size'] ?? ''));
            $category = strtolower(trim((string)($detail['menu_category'] ?? '')));
            $menuName = $detail['menu_name'] ?? 'Menu';

            // Deteksi minuman dari kategori menu (jika kolomnya ada).
            $isDrink = (
                str_contains($category, 'drink') ||
                str_contains($category, 'beverage') ||
                str_contains($category, 'minuman') ||
                str_contains($category, 'coffee') ||
                str_contains($category, 'kopi') ||
                str_contains($category, 'tea')
            );

            if ($rawSize !== '') {
                $displaySize = ucfirst(strtolower($rawSize));
            } elseif ($isDrink || $categoryColumn === null) {
                $displaySize = 'Medium';
            } else {
                $displaySize = '';
            }

            $items[] = [
                'name'     => $menuName,
                'quantity' => (int)($detail['quantity'] ?? 0),
                'price'    => (float)($detail['price'] ?? 0),
                'subtotal' => (float)($detail['subtotal'] ?? 0),
                'size'     => $displaySize,
                'is_drink' => $isDrink || $categoryColumn === null || $rawSize !== ''
            ];
        }

        $row['items']    = $items;
        $row['number']   = '#ORD-' . str_pad((string)$orderId, 3, '0', STR_PAD_LEFT);
        $row['customer'] = $row['customer_name'] ?: 'Guest';
        $row['time']     = !empty($row['order_date'])
            ? date('H:i', strtotime($row['order_date']))
            : '-';
        $row['type']     = $row['order_type'] ?? '-';
        $row['total']    = (float)($row['total_amount'] ?? 0);
        $row['status']   = $row['order_status'] ?? 'Pending';

        $orders[] = $row;
    }

    $detailStmt->close();
    $stmt->close();
} catch (mysqli_sql_exception $ex) {
    die('Orders query error: ' . htmlspecialchars($ex->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Receive - LÜMA</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="order_receive.css">
</head>
<body>
<div class="app-layout">

    <aside class="sidebar">
        <div class="logo">
            <img src="../assets/Group 194.png" alt="LÜMA Logo">
        </div>

        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item">
                <i class="fa-solid fa-table-cells-large"></i>
                <span>Dashboard</span>
            </a>

            <a href="order_receive.php" class="menu-item active">
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

    <main class="main-content">
        <header class="topbar">
            <h2>Order Receive</h2>
            <div class="profile">
                <i class="fa-regular fa-bell notification-icon"></i>
                <div class="profile-info">
                    <strong><?= e($userName) ?></strong>
                    <small>Staff</small>
                </div>
            </div>
        </header>

        <section class="welcome">
            <h1>Good Morning, Cashier! 👋</h1>
            <p>Here's today's order.</p>
        </section>

        <section class="summary-grid">
            <article class="summary-card">
                <div class="summary-icon"><i class="fa-solid fa-hourglass-half"></i></div>
                <div>
                    <span class="summary-label">Pending</span>
                    <strong class="summary-number"><?= $pendingOrders ?></strong>
                </div>
            </article>

            <article class="summary-card">
                <div class="summary-icon"><i class="fa-solid fa-rotate"></i></div>
                <div>
                    <span class="summary-label">Processing</span>
                    <strong class="summary-number"><?= $processingOrders ?></strong>
                </div>
            </article>

            <article class="summary-card">
                <div class="summary-icon"><i class="fa-regular fa-circle-check"></i></div>
                <div>
                    <span class="summary-label">Completed</span>
                    <strong class="summary-number"><?= $completedOrders ?></strong>
                </div>
            </article>

            <article class="summary-card">
                <div class="summary-icon"><i class="fa-solid fa-receipt"></i></div>
                <div>
                    <span class="summary-label">Total Orders</span>
                    <strong class="summary-number"><?= $totalOrders ?></strong>
                </div>
            </article>
        </section>

        <section class="orders-section">
            <div class="section-heading">
                <h2>Order Receive</h2>
                <p>Manage today's customer orders</p>
            </div>

            <nav class="filters" aria-label="Filter orders">
                <?php foreach (['All', 'Pending', 'Processing', 'Completed'] as $statusFilter): ?>
                    <a
                        href="order_receive.php?status=<?= urlencode($statusFilter) ?>"
                        class="filter-btn <?= $filter === $statusFilter ? 'active' : '' ?>"
                    ><?= e($statusFilter) ?></a>
                <?php endforeach; ?>
            </nav>

            <div class="table-card">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Order Number</th>
                                <th>Customer</th>
                                <th>Time</th>
                                <th>Order Type</th>
                                <th>Items</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($orders): ?>
                            <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td class="order-number"><?= e($order['number']) ?></td>
                                    <td><?= e($order['customer']) ?></td>
                                    <td><?= e($order['time']) ?></td>
                                    <td><span class="type-badge"><?= e($order['type']) ?></span></td>

                                    <td class="items-list">
                                        <?php if ($order['items']): ?>
                                            <?php foreach ($order['items'] as $item): ?>
                                                <div class="item-line">
                                                    <strong><?= e($item['name']) ?> ×<?= $item['quantity'] ?></strong>
                                                    <?php if ($item['size'] !== ''): ?>
                                                        <small><?= e($item['size']) ?></small>
                                                    <?php endif; ?>
                                                    <small><?= rupiah($item['subtotal']) ?></small>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <small class="muted">No items</small>
                                        <?php endif; ?>
                                    </td>

                                    <td><strong><?= rupiah($order['total']) ?></strong></td>

                                    <td>
                                        <span class="status-badge status-<?= strtolower(e($order['status'])) ?>">
                                            <?= e($order['status']) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <div class="action-buttons">
                                            <button
                                                type="button"
                                                class="action-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#detailModal<?= (int)$order['order_id'] ?>"
                                                aria-label="View <?= e($order['number']) ?> details"
                                                title="View order detail"
                                            >
                                                <i class="fa-regular fa-eye"></i>
                                            </button>

                                            <?php if ($order['status'] === 'Completed'): ?>
                                                <button type="button" class="action-btn complete completed" disabled title="Completed">
                                                    <i class="fa-solid fa-check"></i>
                                                </button>
                                            <?php else: ?>
                                                <form method="POST" action="order_receive.php" class="complete-form"
                                                      onsubmit="return confirm('Mark this order as completed?');">
                                                    <input type="hidden" name="order_id" value="<?= (int)$order['order_id'] ?>">
                                                    <input type="hidden" name="filter" value="<?= e($filter) ?>">
                                                    <button type="submit" name="update_status" value="1"
                                                            class="action-btn complete" title="Mark as completed"
                                                            aria-label="Mark <?= e($order['number']) ?> as completed">
                                                        <i class="fa-solid fa-check"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8">
                                    <div class="empty-state">
                                        <i class="fa-solid fa-mug-hot"></i>
                                        <strong>No orders found</strong>
                                        <span>There are no orders with this status.</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>

<?php foreach ($orders as $order): ?>
    <div class="modal fade" id="detailModal<?= (int)$order['order_id'] ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content detail-modal">
                <div class="modal-header detail-modal-header">
                    <div>
                        <h5 class="modal-title">Order Detail</h5>
                        <small><?= e($order['number']) ?></small>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body detail-modal-body">
                    <div class="detail-meta">
                        <div>
                            <span>Customer</span>
                            <strong><?= e($order['customer']) ?></strong>
                        </div>
                        <div>
                            <span>Order Type</span>
                            <strong><?= e($order['type']) ?></strong>
                        </div>
                        <div>
                            <span>Time</span>
                            <strong><?= e($order['time']) ?></strong>
                        </div>
                        <div>
                            <span>Status</span>
                            <strong><?= e($order['status']) ?></strong>
                        </div>
                    </div>

                    <h3>Order Items</h3>
                    <div class="detail-items">
                        <?php if ($order['items']): ?>
                            <?php foreach ($order['items'] as $item): ?>
                                <div class="detail-item">
                                    <div>
                                        <strong><?= e($item['name']) ?> ×<?= $item['quantity'] ?></strong>
                                        <?php if ($item['size'] !== ''): ?>
                                            <small><?= e($item['size']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <strong><?= rupiah($item['subtotal']) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="muted">No items recorded for this order.</p>
                        <?php endif; ?>
                    </div>

                    <div class="detail-total">
                        <span>Total</span>
                        <strong><?= rupiah($order['total']) ?></strong>
                    </div>
                </div>

                <div class="modal-footer detail-modal-footer">
                    <button type="button" class="close-btn" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>