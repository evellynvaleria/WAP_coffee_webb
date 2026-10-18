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

// Real enum value stored in the database (same as in menu.php)
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

/* STATUS FLOW */
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
    return 'Pending'; // pending, success, paid, etc.
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

function timeText(array $order, string $scope): string
{
    return ($scope === 'all' && $order['date'] !== '' ? $order['date'] . ' ' : '') . $order['time'];
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

// Filter and search
function matchesOrder(array $order, string $filter, string $keyword): bool
{
    if ($filter !== 'All' && $order['status'] !== $filter) {
        return false;
    }

    if ($keyword === '') {
        return true;
    }

    $text = $order['number'] . ' ' . $order['customer'] . ' ' . implode(' ', array_column($order['items'], 'name'));

    return mb_stripos($text, $keyword) !== false;
}

// Queue order: Pending -> Processing (oldest first), then finished ones (newest first)
function sortOrders(array $list): array
{
    $rank = ['Pending' => 0, 'Processing' => 1, 'Completed' => 2, 'Cancelled' => 3];

    usort($list, function ($a, $b) use ($rank) {
        $diff = $rank[$a['status']] - $rank[$b['status']];

        if ($diff !== 0) {
            return $diff;
        }

        return in_array($a['status'], ['Completed', 'Cancelled'], true)
            ? $b['id'] <=> $a['id']
            : $a['id'] <=> $b['id'];
    });

    return $list;
}

// PAGE STATE (filter, scope, search, selected order) is carried in the URL
function readState(array $src): array
{
    $filter = (string)($src['filter'] ?? 'All');
    $order  = filter_var($src['order'] ?? null, FILTER_VALIDATE_INT);

    return [
        'filter' => in_array($filter, ['All', 'Pending', 'Processing', 'Completed'], true) ? $filter : 'All',
        'scope'  => ($src['scope'] ?? 'today') === 'all' ? 'all' : 'today',
        'q'      => mb_substr(trim((string)($src['q'] ?? '')), 0, 100),
        'order'  => ($order && $order > 0) ? $order : 0
    ];
}

function buildUrl(array $state, array $override = []): string
{
    $params = array_merge($state, $override);

    if ($params['filter'] === 'All') unset($params['filter']);
    if ($params['scope'] === 'today') unset($params['scope']);
    if ($params['q'] === '')          unset($params['q']);
    if (empty($params['order']))      unset($params['order']);

    return 'order_receive.php' . ($params ? '?' . http_build_query($params) : '');
}

function stateInputs(array $state): string
{
    $html = '';

    foreach (['filter', 'scope', 'q', 'order'] as $key) {
        if (!empty($state[$key])) {
            $html .= '<input type="hidden" name="' . e($key) . '" value="' . e($state[$key]) . '">';
        }
    }

    return $html;
}

// COLUMN DETECTION
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

// LOAD ORDERS + ITEMS
function loadOrders(mysqli $conn, array $cfg, string $scope): array
{
    $where = $scope === 'today' ? 'WHERE DATE(o.order_date) = CURDATE()' : '';

    $typeSel = $cfg['type']    ? ", o.`{$cfg['type']}` AS order_type"       : ", NULL AS order_type";
    $paySel  = $cfg['payment'] ? ", o.`{$cfg['payment']}` AS payment_method" : ", NULL AS payment_method";

    $result = $conn->query("
        SELECT
            o.order_id,
            o.`{$cfg['amount']}` AS total_amount,
            o.`{$cfg['status']}` AS order_status,
            DATE_FORMAT(o.order_date, '%H:%i') AS time_label,
            DATE_FORMAT(o.order_date, '%d/%m') AS date_label,
            TIMESTAMPDIFF(MINUTE, o.order_date, NOW()) AS age_min,
            u.name  AS customer_name,
            u.email AS customer_email,
            (c.user_id IS NOT NULL) AS is_member
            $typeSel
            $paySel
        FROM orders o
        LEFT JOIN users u    ON u.user_id = o.user_id
        LEFT JOIN customer c ON c.user_id = o.user_id
        $where
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
            'date'      => $row['date_label'] ?? '',
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

// UPDATE STATUS (plain POST, then redirect back to the page)
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

        $stmt = $conn->prepare("SELECT `{$cfg['status']}` AS s FROM orders WHERE order_id = ? LIMIT 1");
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

        $dbStatus = enumValue($conn, 'orders', $cfg['status'], $target);

        $stmt = $conn->prepare("UPDATE orders SET `{$cfg['status']}` = ? WHERE order_id = ?");
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

// DATA FOR THE VIEW
$state    = readState($_GET);
$filter   = $state['filter'];
$scope    = $state['scope'];
$keyword  = $state['q'];

$orders = loadOrders($conn, $cfg, $scope);

// Summary & chips (counted from all orders in the scope, before filtering)
$count = ['All' => count($orders), 'Pending' => 0, 'Processing' => 0, 'Completed' => 0];

foreach ($orders as $order) {
    if (isset($count[$order['status']])) {
        $count[$order['status']]++;
    }
}

$visible  = sortOrders(array_values(array_filter(
    $orders,
    fn($order) => matchesOrder($order, $filter, $keyword)
)));

// Order selected for the detail panel
$selected = null;

foreach ($orders as $order) {
    if ($order['id'] === $state['order']) {
        $selected = $order;
        break;
    }
}

// One-time message (toast)
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
    <title>Order Receive - LÜMA</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="menu.css">
    <link rel="stylesheet" href="order_receive.css">

    <style>
        a.filter-btn, a.scope-btn { text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .ord-number a { color: inherit; text-decoration: none; }
        .ord-number a:hover { text-decoration: underline; }
        .ord-foot form { margin: 0; }
        .ord-detail form { margin: 0; }
        .search-box button { border: 0; background: none; padding: 0; color: inherit; cursor: pointer; }
        .ord-link { font-size: 12px; text-decoration: none; opacity: .7; }
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

    <!-- MAIN CONTENT -->
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

        <div class="menu-columns">

            <!-- LEFT COLUMN: SUMMARY + ORDER LIST -->
            <div class="menu-left">

                <section class="summary-grid">

                    <article class="summary-card">
                        <div class="summary-icon"><i class="fa-solid fa-hourglass-half"></i></div>
                        <div>
                            <span class="summary-label">Pending</span>
                            <strong class="summary-number"><?= $count['Pending'] ?></strong>
                        </div>
                    </article>

                    <article class="summary-card">
                        <div class="summary-icon"><i class="fa-solid fa-fire-burner"></i></div>
                        <div>
                            <span class="summary-label">Processing</span>
                            <strong class="summary-number"><?= $count['Processing'] ?></strong>
                        </div>
                    </article>

                    <article class="summary-card">
                        <div class="summary-icon"><i class="fa-regular fa-circle-check"></i></div>
                        <div>
                            <span class="summary-label">Completed</span>
                            <strong class="summary-number"><?= $count['Completed'] ?></strong>
                        </div>
                    </article>

                    <article class="summary-card">
                        <div class="summary-icon"><i class="fa-solid fa-receipt"></i></div>
                        <div>
                            <span class="summary-label">Total Orders</span>
                            <strong class="summary-number"><?= $count['All'] ?></strong>
                        </div>
                    </article>

                </section>

                <section class="menu-section">

                    <div class="section-heading">
                        <h2>Incoming Orders</h2>
                        <p>Unfinished orders are shown first, with the longest-waiting order at the front</p>
                    </div>

                    <div class="menu-toolbar">

                        <!-- STATUS FILTER -->
                        <div class="filters">
                            <?php foreach (['All', 'Pending', 'Processing', 'Completed'] as $status): ?>
                                <a href="<?= e(buildUrl($state, ['filter' => $status, 'order' => 0])) ?>"
                                   class="filter-btn <?= $filter === $status ? 'active' : '' ?>">
                                    <?= e($status) ?> <span class="chip-count"><?= $count[$status] ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <div class="toolbar-right">

                            <!-- SCOPE -->
                            <div class="scope-toggle">
                                <a href="<?= e(buildUrl($state, ['scope' => 'today', 'order' => 0])) ?>"
                                   class="scope-btn <?= $scope === 'today' ? 'active' : '' ?>">Today</a>
                                <a href="<?= e(buildUrl($state, ['scope' => 'all', 'order' => 0])) ?>"
                                   class="scope-btn <?= $scope === 'all' ? 'active' : '' ?>">All</a>
                            </div>

                            <!-- SEARCH -->
                            <form method="get" action="order_receive.php" class="search-box">
                                <?php if ($filter !== 'All'): ?><input type="hidden" name="filter" value="<?= e($filter) ?>"><?php endif; ?>
                                <?php if ($scope !== 'today'): ?><input type="hidden" name="scope" value="<?= e($scope) ?>"><?php endif; ?>
                                <button type="submit" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
                                <input type="text" name="q" value="<?= e($keyword) ?>" placeholder="Search order no. / name / menu...">
                            </form>

                            <!-- REFRESH -->
                            <a href="<?= e(buildUrl($state)) ?>" class="ingredient-btn" style="text-decoration:none">
                                <i class="fa-solid fa-rotate"></i>
                                Refresh
                            </a>

                        </div>

                    </div>

                    <small class="live-status">
                        Updated <?= e(date('H:i:s')) ?> · auto-refresh every 15 seconds
                    </small>

                    <div class="ord-grid">

                        <?php foreach ($visible as $order): ?>

                            <?php $action = nextAction($order); ?>

                            <article class="ord-card <?= e(statusClass($order['status'])) ?><?= $order['id'] === $state['order'] ? ' selected' : '' ?>">

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
                                        <?= e(timeText($order, $scope) . ' · ' . ageText($order['age'])) ?>
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
                                        <form method="post" action="order_receive.php">
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

            <!-- RIGHT COLUMN: ORDER DETAILS -->
            <aside class="order-card ord-detail<?= $selected ? ' open' : '' ?>" id="detailPanel">

                <div class="detail-head">
                    <h3 class="order-title">Order Details</h3>

                    <?php if ($selected): ?>
                        <a href="<?= e(buildUrl($state, ['order' => 0])) ?>" class="detail-close" aria-label="Close" style="text-decoration:none">
                            <i class="fa-solid fa-xmark"></i>
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (!$selected): ?>

                    <p class="order-empty">Select an order to view its details.</p>

                <?php else: ?>

                    <?php $action = nextAction($selected); ?>
                    <?php $current = array_search($selected['status'], $steps, true); ?>

                    <div class="detail-body">

                        <div class="detail-top">
                            <strong class="detail-number"><?= e($selected['number']) ?></strong>
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
                            <div><dt>Customer</dt><dd><?= e($selected['customer'] . ($selected['is_member'] ? ' (Member)' : '')) ?></dd></div>
                            <div><dt>Type</dt><dd><?= e($selected['type']) ?></dd></div>
                            <div><dt>Time</dt><dd><?= e(timeText($selected, $scope) . ' · ' . ageText($selected['age'])) ?></dd></div>
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

                        <?php if ($action): ?>
                            <form method="post" action="order_receive.php">
                                <input type="hidden" name="update_status" value="1">
                                <input type="hidden" name="order_id" value="<?= (int)$selected['id'] ?>">
                                <input type="hidden" name="status" value="<?= e($action['target']) ?>">
                                <?= stateInputs($state) ?>
                                <button type="submit" class="pay-btn"><?= e($action['long']) ?></button>
                            </form>
                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            </aside>

        </div>

    </main>

</div>

<?php if ($flash): ?>
    <!-- Toast: shown once, gone on the next reload (max. 15 seconds) -->
    <div class="ord-toast show<?= $flash['error'] ? ' error' : '' ?>" role="status"><?= e($flash['message']) ?></div>
<?php endif; ?>

</body>
</html>