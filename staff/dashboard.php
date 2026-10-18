<?php
session_start();

require_once "../config/database.php";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// LOGIN & ROLE
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
    header("Location: ../login.php");
    exit;
}

$userName = $_SESSION['name'] ?? 'Staff';

// HELPER FUNCTIONS
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function rupiah($number): string
{
    return 'Rp ' . number_format((float)$number, 0, ',', '.');
}

// 8.00 -> 8   |   2.50 -> 2.5
function formatQty($number): string
{
    return rtrim(rtrim(number_format((float)$number, 2, '.', ''), '0'), '.');
}

function getColumnNames(mysqli $conn, string $table): array
{
    // Table names are always constants in this file.
    $columns = [];
    $result  = $conn->query("SHOW COLUMNS FROM `$table`");

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

// Real enum value stored in the database (same as in menu.php / order_receive.php)
function enumValue(mysqli $conn, string $table, string $column, string $wanted): string
{
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    $row    = $result ? $result->fetch_assoc() : null;

    if (!$row || stripos((string)$row['Type'], 'enum(') !== 0) {
        return $wanted;
    }

    preg_match_all("/'((?:[^']|'')*)'/", $row['Type'], $matches);

    $normalize = fn($v) => strtolower(preg_replace('/\s+/', '', (string)$v));
    $options   = array_map(fn($v) => str_replace("''", "'", $v), $matches[1]);

    foreach ($options as $option) {
        if ($normalize($option) === $normalize($wanted)) {
            return $option;
        }
    }

    throw new Exception(
        "Value '$wanted' does not exist in column $table.$column (options: " . implode(', ', $options) . ")."
    );
}

/*
 * STATUS FLOW (same as order_receive.php)
 * Pending -> Processing -> Completed
 * A 'Success' status from the cashier is treated as Pending.
 */
function normalizeStatus($raw): string
{
    $key = strtolower(trim((string)$raw));

    if (in_array($key, ['processing', 'process', 'preparing'], true)) {
        return 'Processing';
    }

    if (in_array($key, ['completed', 'complete', 'done', 'selesai'], true)) {
        return 'Completed';
    }

    if (str_contains($key, 'cancel')) {
        return 'Cancelled';
    }

    return 'Pending';
}

function statusClass(string $status): string
{
    return 'status-' . strtolower($status);
}

function ageText(int $minutes): string
{
    if ($minutes < 1) {
        return 'Just now';
    }

    if ($minutes < 60) {
        return $minutes . ' min ago';
    }

    $hours = intdiv($minutes, 60);

    return $hours < 24 ? $hours . ' h ago' : intdiv($hours, 24) . ' d ago';
}

function isLate(array $order): bool
{
    return $order['status'] === 'Pending' && $order['age'] >= 10;
}

// Next step for an order
function nextAction(array $order): ?array
{
    if ($order['status'] === 'Pending') {
        return ['target' => 'Processing', 'label' => 'Start', 'icon' => 'fa-play', 'long' => 'Start Order'];
    }

    if ($order['status'] === 'Processing') {
        return ['target' => 'Completed', 'label' => 'Done', 'icon' => 'fa-check', 'long' => 'Mark as Completed'];
    }

    return null;
}

// "+ 3 from yesterday" / "- 2 from yesterday" / "Same as yesterday"
function compareText(float $diff, callable $formatter): string
{
    if ((int)round($diff) === 0) {
        return 'Same as yesterday';
    }

    return ($diff > 0 ? '+ ' : '- ') . $formatter(abs($diff)) . ' from yesterday';
}

// Only render an image if the file exists
function menuImage(string $folder, $image): string
{
    $image = (string)$image;

    return ($image !== '' && is_file(__DIR__ . '/' . $folder . $image)) ? $folder . $image : '';
}

// PAGE STATE (status filter + selected order) is carried in the URL
function readState(array $src): array
{
    $filter = (string)($src['filter'] ?? 'All');
    $order  = filter_var($src['order'] ?? null, FILTER_VALIDATE_INT);

    return [
        'filter' => in_array($filter, ['All', 'Pending', 'Processing', 'Completed'], true) ? $filter : 'All',
        'order'  => ($order && $order > 0) ? $order : 0
    ];
}

function buildUrl(array $state, array $override = []): string
{
    $params = array_merge($state, $override);

    if ($params['filter'] === 'All') unset($params['filter']);
    if (empty($params['order']))     unset($params['order']);

    return 'dashboard.php' . ($params ? '?' . http_build_query($params) : '');
}

function stateInputs(array $state): string
{
    $html = '';

    foreach (['filter', 'order'] as $key) {
        if (!empty($state[$key])) {
            $html .= '<input type="hidden" name="' . e($key) . '" value="' . e($state[$key]) . '">';
        }
    }

    return $html;
}

// COLUMNS of orders (same as order_receive.php)
try {
    $orderColumns  = getColumnNames($conn, 'orders');
    $detailColumns = getColumnNames($conn, 'order_details');
} catch (Throwable $err) {
    die('Table structure error: ' . e($err->getMessage()));
}

$cfg = [
    'status'      => firstExisting(['order_status', 'status', 'payment_status'], $orderColumns),
    'amount'      => firstExisting(['total_amount', 'total', 'grand_total', 'total_price'], $orderColumns),
    'type'        => firstExisting(['order_type', 'type', 'service_type'], $orderColumns),
    'payment'     => firstExisting(['payment_method', 'payment'], $orderColumns),
    'size'        => firstExisting(['size', 'menu_size', 'drink_size', 'variant', 'item_size'], $detailColumns),
    'detailOrder' => in_array('order_detail_id', $detailColumns, true) ? 'ORDER BY od.order_detail_id ASC' : ''
];

if ($cfg['status'] === null || $cfg['amount'] === null) {
    die('Status/total column not found in the orders table. Available columns: ' . e(implode(', ', $orderColumns)));
}

$statusCol = $cfg['status'];
$amountCol = $cfg['amount'];

// LOAD TODAY'S ORDERS + ITEMS
function loadTodayOrders(mysqli $conn, array $cfg): array
{
    $typeSel = $cfg['type']    ? ", o.`{$cfg['type']}` AS order_type"       : ", NULL AS order_type";
    $paySel  = $cfg['payment'] ? ", o.`{$cfg['payment']}` AS payment_method" : ", NULL AS payment_method";

    $result = $conn->query("
        SELECT
            o.order_id,
            o.`{$cfg['amount']}` AS total_amount,
            o.`{$cfg['status']}` AS order_status,
            DATE_FORMAT(o.order_date, '%H:%i') AS time_label,
            TIMESTAMPDIFF(MINUTE, o.order_date, NOW()) AS age_min,
            u.name  AS customer_name,
            u.email AS customer_email,
            (c.user_id IS NOT NULL) AS is_member
            $typeSel
            $paySel
        FROM orders o
        LEFT JOIN users u    ON u.user_id = o.user_id
        LEFT JOIN customer c ON c.user_id = o.user_id
        WHERE DATE(o.order_date) = CURDATE()
        ORDER BY o.order_id DESC
        LIMIT 300
    ");

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    // All items at once (single query)
    $itemsByOrder = [];

    if ($rows) {
        $ids = implode(',', array_map('intval', array_column($rows, 'order_id')));

        $sizeSel = $cfg['size'] ? ", od.`{$cfg['size']}` AS item_size" : ", NULL AS item_size";

        $details = $conn->query("
            SELECT od.order_id, m.menu_name, od.quantity, od.price, od.subtotal $sizeSel
            FROM order_details od
            LEFT JOIN menus m ON m.menu_id = od.menu_id
            WHERE od.order_id IN ($ids)
            {$cfg['detailOrder']}
        ");

        while ($d = $details->fetch_assoc()) {
            $size = trim((string)($d['item_size'] ?? ''));

            $itemsByOrder[(int)$d['order_id']][] = [
                'name'     => $d['menu_name'] ?? 'Menu',
                'qty'      => (int)$d['quantity'],
                'price'    => (float)$d['price'],
                'subtotal' => (float)$d['subtotal'],
                'size'     => $size !== '' ? ucfirst(strtolower($size)) : ''
            ];
        }
    }

    $orders = [];

    foreach ($rows as $row) {

        $id     = (int)$row['order_id'];
        $walkin = strtolower((string)$row['customer_email']) === 'walkin@luma.local';

        $orders[] = [
            'id'        => $id,
            'number'    => '#ORD-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT),
            'customer'  => $walkin ? 'Walk-in' : ($row['customer_name'] ?: 'Guest'),
            'is_member' => !$walkin && (int)$row['is_member'] === 1,
            'time'      => $row['time_label'] ?? '-',
            'age'       => max(0, (int)$row['age_min']),
            'type'      => $row['order_type'] ?: '-',
            'payment'   => $row['payment_method'] ?: '',
            'total'     => (float)$row['total_amount'],
            'status'    => normalizeStatus($row['order_status']),
            'items'     => $itemsByOrder[$id] ?? []
        ];
    }

    return $orders;
}

// UPDATE STATUS (one step forward only; plain POST then redirect)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {

    $backState = readState($_POST);

    try {
        $orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
        $target  = (string)($_POST['status'] ?? '');

        if (!$orderId || $orderId < 1) {
            throw new Exception('Invalid order.');
        }

        if (!in_array($target, ['Processing', 'Completed'], true)) {
            throw new Exception('Invalid status.');
        }

        $stmt = $conn->prepare("SELECT `$statusCol` AS s FROM orders WHERE order_id = ? LIMIT 1");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$current) {
            throw new Exception('Order not found.');
        }

        $next = ['Pending' => 'Processing', 'Processing' => 'Completed'];

        if (($next[normalizeStatus($current['s'])] ?? null) !== $target) {
            throw new Exception('The order status has already changed; data refreshed.');
        }

        $dbStatus = enumValue($conn, 'orders', $statusCol, $target);

        $stmt = $conn->prepare("UPDATE orders SET `$statusCol` = ? WHERE order_id = ?");
        $stmt->bind_param("si", $dbStatus, $orderId);
        $stmt->execute();
        $stmt->close();

        $_SESSION['flash'] = [
            'message' => '#ORD-' . str_pad((string)$orderId, 3, '0', STR_PAD_LEFT) . ' → ' . $target,
            'error'   => false
        ];

    } catch (Throwable $err) {
        $_SESSION['flash'] = ['message' => $err->getMessage(), 'error' => true];
    }

    // Post/Redirect/Get: refreshing the browser does not resubmit the form
    header('Location: ' . buildUrl($backState));
    exit;
}

// YESTERDAY (for the "from yesterday" comparison)
$yesterday = $conn->query("
    SELECT COUNT(*) AS total_orders, COALESCE(SUM(`$amountCol`), 0) AS revenue
    FROM orders
    WHERE DATE(order_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
      AND LOWER(`$statusCol`) NOT LIKE '%cancel%'
")->fetch_assoc();

$yesterdayData = [
    'orders'  => (int)$yesterday['total_orders'],
    'revenue' => (float)$yesterday['revenue']
];

// POPULAR MENU
$imageFolder  = "../assets/menu/";
$popularMenus = [];

$resultPopular = $conn->query("
    SELECT
        m.menu_id,
        m.menu_name,
        m.price,
        m.image,
        c.category_name,
        SUM(od.quantity) AS total_sold
    FROM order_details od
    INNER JOIN menus m ON m.menu_id = od.menu_id
    LEFT JOIN categories c ON c.category_id = m.category_id
    GROUP BY m.menu_id, m.menu_name, m.price, m.image, c.category_name
    ORDER BY total_sold DESC, m.menu_name ASC
    LIMIT 4
");

while ($row = $resultPopular->fetch_assoc()) {
    $popularMenus[] = $row;
}

// INGREDIENTS NEEDING ATTENTION (stock <= minimum)
$lowStock = [];
$outCount = 0;

$resultStock = $conn->query("
    SELECT ingredient_name, unit, stock, minimum_stock
    FROM ingredients
    WHERE stock <= minimum_stock
    ORDER BY stock ASC, ingredient_name ASC
");

while ($row = $resultStock->fetch_assoc()) {
    if ((float)$row['stock'] <= 0) {
        $outCount++;
    }
    $lowStock[] = $row;
}

$lowCount = count($lowStock);

// TODAY'S ORDER DATA
$state  = readState($_GET);
$filter = $state['filter'];

$orders = loadTodayOrders($conn, $cfg);

$recentLimit = 8;

// Summary: cancelled orders are not counted
$revenue    = 0.0;
$countedOrd = 0;
$pending    = 0;
$processing = 0;
$chip       = ['All' => count($orders), 'Pending' => 0, 'Processing' => 0, 'Completed' => 0];

foreach ($orders as $order) {

    if ($order['status'] !== 'Cancelled') {
        $countedOrd++;
        $revenue += $order['total'];
    }

    if ($order['status'] === 'Pending')    $pending++;
    if ($order['status'] === 'Processing') $processing++;

    if (isset($chip[$order['status']])) {
        $chip[$order['status']]++;
    }
}

// Recent orders: newest first, filtered, max 8
$filtered = array_values(array_filter(
    $orders,
    fn($order) => $filter === 'All' || $order['status'] === $filter
));

usort($filtered, fn($a, $b) => $b['id'] <=> $a['id']);

$visible = array_slice($filtered, 0, $recentLimit);

// Order selected for the detail popup
$selected = null;

foreach ($orders as $order) {
    if ($order['id'] === $state['order']) {
        $selected = $order;
        break;
    }
}

// One-time message
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$steps = ['Pending', 'Processing', 'Completed'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Auto reload every 15 seconds -->
    <meta http-equiv="refresh" content="15">
    <title>Dashboard - LÜMA</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="menu.css">
    <link rel="stylesheet" href="order_receive.css">
    <link rel="stylesheet" href="dashboard.css">

    <style>
        a.filter-btn { display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
        a.close-btn { display: inline-block; text-align: center; text-decoration: none; }
        .ord-number a { color: inherit; text-decoration: none; }
        .ord-number a:hover { text-decoration: underline; }
        .ord-foot form, .detail-modal form { margin: 0; }
        .modal.show.d-block { overflow-y: auto; }
    </style>
</head>

<body>

<div class="app-layout">

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

        <header class="topbar">

            <h2>Dashboard</h2>

            <div class="profile">
                <i class="fa-regular fa-bell notification-icon"></i>

                <div class="profile-info">
                    <strong><?= e($userName) ?></strong>
                    <small>Staff</small>
                </div>
            </div>

        </header>

        <section class="welcome">
            <h1>Welcome back, <?= e($userName) ?>! 👋</h1>
            <p>Here's today's summary.</p>
        </section>

        <!-- SUMMARY -->
        <section class="summary-grid">

            <article class="summary-card">
                <div class="summary-icon"><i class="fa-solid fa-box"></i></div>
                <div>
                    <span class="summary-label">Today's Orders</span>
                    <strong class="summary-number"><?= $countedOrd ?></strong>
                    <small class="summary-sub"><?= e(compareText($countedOrd - $yesterdayData['orders'], 'strval')) ?></small>
                </div>
            </article>

            <article class="summary-card">
                <div class="summary-icon"><i class="fa-solid fa-money-bill-wave"></i></div>
                <div>
                    <span class="summary-label">Today's Revenue</span>
                    <strong class="summary-number"><?= e(rupiah($revenue)) ?></strong>
                    <small class="summary-sub"><?= e(compareText($revenue - $yesterdayData['revenue'], 'rupiah')) ?></small>
                </div>
            </article>

            <a href="order_receive.php" class="summary-card summary-link">
                <div class="summary-icon"><i class="fa-solid fa-fire-burner"></i></div>
                <div>
                    <span class="summary-label">In Queue</span>
                    <strong class="summary-number"><?= $pending + $processing ?></strong>
                    <small class="summary-sub"><?= $pending ?> pending · <?= $processing ?> processing</small>
                </div>
            </a>

            <a href="menu.php?panel=ingredients" class="summary-card summary-link">
                <div class="summary-icon"><i class="fa-solid fa-box-open"></i></div>
                <div>
                    <span class="summary-label">Low Stock</span>
                    <strong class="summary-number"><?= $lowCount ?></strong>
                    <small class="summary-sub <?= $outCount > 0 ? 'bad' : '' ?>">
                        <?= $outCount > 0 ? $outCount . ' out of stock' : ($lowCount > 0 ? 'Needs restock' : 'All ingredients OK') ?>
                    </small>
                </div>
            </a>

        </section>

        <div class="menu-columns">

            <!-- LEFT COLUMN: RECENT ORDERS -->
            <div class="menu-left">

                <section class="menu-section">

                    <div class="section-heading section-heading-row">

                        <div>
                            <h2>Recent Orders</h2>
                            <p>Today's transactions, newest first</p>
                        </div>

                        <a href="menu.php" class="ingredient-btn">
                            <i class="fa-solid fa-plus"></i>
                            New Order
                        </a>

                    </div>

                    <div class="menu-toolbar">

                        <!-- STATUS FILTER -->
                        <div class="filters">
                            <?php foreach (['All', 'Pending', 'Processing', 'Completed'] as $status): ?>
                                <a href="<?= e(buildUrl($state, ['filter' => $status, 'order' => 0])) ?>"
                                   class="filter-btn <?= $filter === $status ? 'active' : '' ?>">
                                    <?= e($status) ?> <span class="chip-count"><?= $chip[$status] ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <a href="order_receive.php" class="see-all">
                            View all in Order Receive <i class="fa-solid fa-arrow-right"></i>
                        </a>

                    </div>

                    <small class="live-status">
                        Showing <?= count($visible) ?> of <?= count($filtered) ?> orders today ·
                        updated <?= e(date('H:i:s')) ?>
                    </small>

                    <div class="ord-grid">

                        <?php foreach ($visible as $order): ?>

                            <?php $action = nextAction($order); ?>

                            <article class="ord-card <?= e(statusClass($order['status'])) ?>">

                                <div class="ord-head">
                                    <strong class="ord-number">
                                        <a href="<?= e(buildUrl($state, ['order' => $order['id']])) ?>"><?= e($order['number']) ?></a>
                                    </strong>
                                    <span class="status-badge <?= e(statusClass($order['status'])) ?>"><?= e($order['status']) ?></span>
                                </div>

                                <div class="ord-who">
                                    <span class="ord-customer"><?= e($order['customer']) ?></span>
                                    <?php if ($order['is_member']): ?>
                                        <span class="member-chip">Member</span>
                                    <?php endif; ?>
                                </div>

                                <div class="ord-meta">
                                    <span class="type-badge"><?= e($order['type']) ?></span>
                                    <?php if ($order['payment'] !== ''): ?>
                                        <span class="type-badge"><?= e($order['payment']) ?></span>
                                    <?php endif; ?>
                                    <span class="ord-time<?= isLate($order) ? ' late' : '' ?>">
                                        <?= e($order['time'] . ' · ' . ageText($order['age'])) ?>
                                    </span>
                                </div>

                                <ul class="ord-items">
                                    <?php if (!$order['items']): ?>
                                        <li class="more">No items</li>
                                    <?php endif; ?>

                                    <?php foreach (array_slice($order['items'], 0, 3) as $item): ?>
                                        <li><?= e($item['qty'] . '× ' . $item['name'] . ($item['size'] !== '' ? ' (' . $item['size'] . ')' : '')) ?></li>
                                    <?php endforeach; ?>

                                    <?php if (count($order['items']) > 3): ?>
                                        <li class="more">+<?= count($order['items']) - 3 ?> more items</li>
                                    <?php endif; ?>
                                </ul>

                                <div class="ord-foot">
                                    <strong class="ord-total"><?= e(rupiah($order['total'])) ?></strong>

                                    <?php if ($action): ?>
                                        <form method="post" action="dashboard.php">
                                            <input type="hidden" name="update_status" value="1">
                                            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                                            <input type="hidden" name="status" value="<?= e($action['target']) ?>">
                                            <?= stateInputs($state) ?>
                                            <button type="submit" class="ord-action">
                                                <i class="fa-solid <?= e($action['icon']) ?>"></i> <?= e($action['label']) ?>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="ord-done"><?= $order['status'] === 'Completed' ? 'Done' : e($order['status']) ?></span>
                                    <?php endif; ?>
                                </div>

                            </article>

                        <?php endforeach; ?>

                    </div>

                    <div class="empty-state<?= $visible ? ' hidden' : '' ?>">
                        <i class="fa-solid fa-mug-hot"></i>
                        <strong>No orders found</strong>
                        <span>No orders match this filter.</span>
                    </div>

                </section>

            </div>

            <!-- RIGHT COLUMN: POPULAR + STOCK -->
            <aside class="side-stack">

                <section class="side-card">

                    <div class="side-head">
                        <h3>Popular Menu</h3>
                        <a href="menu.php">Menu</a>
                    </div>

                    <?php if ($popularMenus): ?>

                        <?php foreach ($popularMenus as $menu): ?>

                            <?php $image = menuImage($imageFolder, $menu['image']); ?>

                            <a href="menu.php" class="pop-item">

                                <div class="pop-thumb">
                                    <i class="fa-solid fa-mug-hot"></i>

                                    <?php if ($image !== ''): ?>
                                        <img
                                            src="<?= e($image) ?>"
                                            alt="<?= e($menu['menu_name']) ?>"
                                            loading="lazy"
                                        >
                                    <?php endif; ?>
                                </div>

                                <div class="pop-info">
                                    <strong><?= e($menu['menu_name']) ?></strong>
                                    <small><?= e($menu['category_name']) ?> · <?= rupiah($menu['price']) ?></small>
                                </div>

                                <span class="pop-sold"><?= (int)$menu['total_sold'] ?> sold</span>

                            </a>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <p class="side-empty">Popular menu appears after orders come in.</p>

                    <?php endif; ?>

                </section>

                <section class="side-card">

                    <div class="side-head">
                        <h3>Ingredient Stock</h3>
                        <a href="menu.php?panel=ingredients">Manage</a>
                    </div>

                    <?php if ($lowStock): ?>

                        <?php foreach (array_slice($lowStock, 0, 5) as $ingredient): ?>

                            <?php $isOut = (float)$ingredient['stock'] <= 0; ?>

                            <div class="stock-row">

                                <div class="stock-info">
                                    <strong><?= e($ingredient['ingredient_name']) ?></strong>
                                    <small>
                                        <?= e(formatQty($ingredient['stock'])) ?> / min <?= e(formatQty($ingredient['minimum_stock'])) ?>
                                        <?= e($ingredient['unit']) ?>
                                    </small>
                                </div>

                                <span class="status-badge <?= $isOut ? 'status-out' : 'status-low' ?>">
                                    <?= $isOut ? 'Out of Stock' : 'Low' ?>
                                </span>

                            </div>

                        <?php endforeach; ?>

                        <?php if ($lowCount > 5): ?>
                            <p class="side-empty">+<?= $lowCount - 5 ?> more ingredients</p>
                        <?php endif; ?>

                    <?php else: ?>

                        <p class="side-empty">All ingredients are sufficiently stocked.</p>

                    <?php endif; ?>

                </section>

            </aside>

        </div>

    </main>

</div>

<?php if ($selected): ?>

<?php
$action  = nextAction($selected);
$current = array_search($selected['status'], $steps, true);
?>

<!-- ORDER DETAIL MODAL (opened via ?order=ID) -->
<div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content detail-modal">

            <div class="modal-header detail-modal-header">
                <div>
                    <h5 class="modal-title">Order Detail</h5>
                    <small><?= e($selected['number']) ?></small>
                </div>

                <a href="<?= e(buildUrl($state, ['order' => 0])) ?>" class="btn-close btn-close-white" aria-label="Close"></a>
            </div>

            <div class="modal-body menu-modal-body dash-modal-body">

                <div class="detail-top">
                    <strong class="detail-number"><?= e($selected['customer'] . ($selected['is_member'] ? ' (Member)' : '')) ?></strong>
                    <span class="status-badge <?= e(statusClass($selected['status'])) ?>"><?= e($selected['status']) ?></span>
                </div>

                <?php if ($current !== false): ?>
                    <div class="stepper">
                        <?php foreach ($steps as $index => $name): ?>
                            <div class="step<?= $index < $current ? ' done' : '' ?><?= $index === $current ? ' active' : '' ?>">
                                <span class="step-dot"></span>
                                <small><?= e($name) ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <dl class="detail-meta">
                    <div><dt>Type</dt><dd><?= e($selected['type']) ?></dd></div>
                    <div><dt>Time</dt><dd><?= e($selected['time'] . ' · ' . ageText($selected['age'])) ?></dd></div>
                    <div><dt>Payment</dt><dd><?= e($selected['payment'] !== '' ? $selected['payment'] : '-') ?></dd></div>
                </dl>

                <div class="order-items">

                    <?php if (!$selected['items']): ?>
                        <p class="order-empty">No items in this order.</p>
                    <?php endif; ?>

                    <?php foreach ($selected['items'] as $item): ?>
                        <div class="order-item">
                            <div class="order-item-info">
                                <strong><?= e($item['qty'] . '× ' . $item['name']) ?></strong>
                                <small><?= e(($item['size'] !== '' ? $item['size'] . ' · ' : '') . rupiah($item['price'])) ?></small>
                            </div>
                            <strong class="order-item-sub"><?= e(rupiah($item['subtotal'])) ?></strong>
                        </div>
                    <?php endforeach; ?>

                </div>

                <div class="order-total">
                    <span>Total</span>
                    <strong><?= e(rupiah($selected['total'])) ?></strong>
                </div>

            </div>

            <div class="modal-footer detail-modal-footer">

                <a href="<?= e(buildUrl($state, ['order' => 0])) ?>" class="close-btn close-btn-light">Close</a>

                <?php if ($action): ?>
                    <form method="post" action="dashboard.php">
                        <input type="hidden" name="update_status" value="1">
                        <input type="hidden" name="order_id" value="<?= (int)$selected['id'] ?>">
                        <input type="hidden" name="status" value="<?= e($action['target']) ?>">
                        <?= stateInputs($state) ?>
                        <button type="submit" class="close-btn"><?= e($action['long']) ?></button>
                    </form>
                <?php endif; ?>

            </div>

        </div>
    </div>
</div>
<div class="modal-backdrop fade show"></div>

<?php endif; ?>

<?php if ($flash): ?>
    <!-- Toast: shown once, gone on the next reload (max. 15 seconds) -->
    <div class="ord-toast show<?= $flash['error'] ? ' error' : '' ?>" role="status"><?= e($flash['message']) ?></div>
<?php endif; ?>

</body>
</html>