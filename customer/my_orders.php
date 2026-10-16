
<?php
session_start();

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'customer'
) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function rupiah($value): string
{
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

$userId = (int) $_SESSION['user_id'];
$orders = [];
$error = '';

/*
|--------------------------------------------------------------------------
| Ambil riwayat pesanan milik customer yang sedang login
|--------------------------------------------------------------------------
*/
$sql = "
   SELECT
    order_id,
    order_date,
    total_amount,
    status,
    order_status,
    order_type,
    payment_method
FROM orders
WHERE user_id = ?
ORDER BY order_date DESC, order_id DESC
";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }

    $stmt->close();
} else {
    $error = 'Riwayat pesanan belum dapat dimuat.';
}

/*
|--------------------------------------------------------------------------
| Ambil detail menu dari setiap pesanan
|--------------------------------------------------------------------------
*/
$orderDetails = [];

if (!$error && !empty($orders)) {
    $detailSql = "
        SELECT
            od.order_id,
            od.menu_id,
            m.menu_name,
            od.quantity,
            od.price,
            od.subtotal
        FROM order_details od
        INNER JOIN menus m ON od.menu_id = m.menu_id
        INNER JOIN orders o ON od.order_id = o.order_id
        WHERE o.user_id = ?
        ORDER BY od.order_id DESC, od.order_detail_id ASC
    ";

    $detailStmt = $conn->prepare($detailSql);

    if ($detailStmt) {
        $detailStmt->bind_param('i', $userId);
        $detailStmt->execute();

        $detailResult = $detailStmt->get_result();

        while ($detail = $detailResult->fetch_assoc()) {
            $orderId = (int) $detail['order_id'];
            $orderDetails[$orderId][] = $detail;
        }

        $detailStmt->close();
    } else {
        $error = 'Detail pesanan belum dapat dimuat.';
    }
}

function statusClass($status): string
{
    switch (strtolower((string)$status)) {
        case 'pending':
            return 'pending';

        case 'confirmed':
            return 'confirmed';

        case 'preparing':
            return 'preparing';

        case 'ready':
            return 'ready';

        case 'completed':
            return 'completed';

        case 'cancelled':
            return 'cancelled';

        default:
            return 'default';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Orders | LŪMA Café</title>

    <link rel="stylesheet" href="luma_moments.css">
    <link rel="stylesheet" href="my_orders.css">
</head>

<body>

<!-- NAVBAR -->
<header class="orders-navbar">
    <a href="../dashboard_main.php" class="orders-logo">
        LŪMA<span>.</span>
    </a>

    <nav class="nav-links">
        <a href="dashboard.php">Home</a>
        <a href="menu.php">Menu</a>
        <a href="luma_moments.php">LŪMA Moments</a>
        <a href="dashboard.php#rewards">Rewards</a>
        <a href="my_orders.php" class="active">My Orders</a>
    </nav>

    <a href="cart.php" class="orders-cart">My Cart</a>
</header>

<main class="orders-container">

    <!-- PAGE HEADER -->
    <section class="orders-heading">
        <p class="orders-eyebrow">YOUR LŪMA EXPERIENCE</p>
        <h1>My Orders<span>.</span></h1>
        <p>
            Every order tells a story. Take a look at your LŪMA moments.
        </p>
    </section>

    <!-- ORDER CONTENT -->
    <?php if ($error !== ''): ?>

        <section class="orders-empty">
            <h2>Something went wrong</h2>
            <p><?= e($error) ?></p>
            <a href="menu.php" class="orders-button">
                Explore Menu
            </a>
        </section>

    <?php elseif (empty($orders)): ?>

        <section class="orders-empty">
            <div class="orders-empty-icon">☕</div>

            <h2>No orders yet</h2>

            <p>
                Your next favorite drink might be waiting for you.
                Explore our menu and make your first order.
            </p>

            <a href="menu.php" class="orders-button">
                Explore Menu
            </a>
        </section>

    <?php else: ?>

        <div class="orders-summary">
            <div>
                <span class="summary-label">Total Orders</span>
                <strong><?= count($orders) ?></strong>
            </div>

            <div>
                <span class="summary-label">Latest Order</span>
                <strong>
                    #<?= e($orders[0]['order_id']) ?>
                </strong>
            </div>
        </div>

        <section class="orders-list">

           <?php foreach ($orders as $order): ?>
    <?php
        $orderId = (int) $order['order_id'];
        $status = (string) $order['status'];
        $details = $orderDetails[$orderId] ?? [];
    ?>

                <article class="order-card">

                    <div class="order-card-header">
                        <div>
                            <span class="order-number">
                                ORDER #<?= e($orderId) ?>
                            </span>

                            <h2>
                                <?= e(date(
                                    'd M Y, H:i',
                                    strtotime($order['order_date'])
                                )) ?>
                            </h2>
                        </div>

                        <span class="order-status <?= e(statusClass($status)) ?>">
                            <?= e($status) ?>
                        </span>
                    </div>

                    <div class="order-items">

                        <?php if (empty($details)): ?>

                            <p class="order-no-items">
                                No item details found for this order.
                            </p>

                        <?php else: ?>

                            <?php foreach ($details as $item): ?>
                                <div class="order-item">
                                    <div class="order-item-info">
                                        <h3><?= e($item['menu_name']) ?></h3>

                                        <p>
                                            <?= (int)$item['quantity'] ?> ×
                                            <?= rupiah($item['price']) ?>
                                        </p>
                                    </div>

                                    <strong>
                                        <?= rupiah($item['subtotal']) ?>
                                    </strong>
                                </div>
                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                    <div class="order-card-footer">
                        <div class="order-payment">
                            <span>Payment Method</span>
                            <strong><?= e($order['payment_method']) ?></strong>
                        </div>
                    <div class="order-payment">
                        <span>Order Type</span>
                        <strong><?= e($order['order_type']) ?></strong>
                    </div>
                        <div class="order-total">
                            <span>Total</span>
                            <strong>
                                <?= rupiah($order['total_amount']) ?>
                            </strong>
                        </div>
                    </div>

                </article>

            <?php endforeach; ?>

        </section>

    <?php endif; ?>

    <div class="orders-back">
        <a href="menu.php">← Continue Shopping</a>
    </div>

</main>

</body>
</html>