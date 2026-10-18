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

/*
|--------------------------------------------------------------------------
| Progres pesanan (diatur kasir di Order Receive)
| Pending -> Processing -> Completed
| Mengembalikan [label, class CSS di my_orders.css, keterangan untuk customer]
|--------------------------------------------------------------------------
*/
function progressInfo($raw): array
{
    $key = strtolower(trim((string)$raw));

    if (in_array($key, ['processing', 'process', 'preparing'], true)) {
        return ['Processing', 'preparing', 'Pesananmu sedang dibuat oleh barista.'];
    }

    if (in_array($key, ['completed', 'complete', 'done', 'selesai'], true)) {
        return ['Completed', 'completed', 'Pesananmu sudah selesai dibuat. Selamat menikmati!'];
    }

    if (str_contains($key, 'cancel')) {
        return ['Cancelled', 'cancelled', 'Pesanan ini dibatalkan.'];
    }

    return ['Pending', 'pending', 'Pesananmu sudah diterima dan menunggu diproses kasir.'];
}

// Status pembayaran: Success / Failed (kolom orders.status)
function paymentInfo($raw): array
{
    $key = strtolower(trim((string)$raw));

    if ($key === 'failed') {
        return ['Failed', 'cancelled'];
    }

    return [ucfirst($key) !== '' ? ucfirst($key) : 'Success', 'default'];
}

$userId = (int) $_SESSION['user_id'];
$orders = [];
$error = '';
$justOrdered = isset($_GET['success']);

/*
|--------------------------------------------------------------------------
| Ambil riwayat pesanan milik customer yang sedang login
| status       = status pembayaran (Success / Failed)
| order_status = progres pesanan (Pending / Processing / Completed)
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Status pesanan diperbarui otomatis tiap 20 detik (tanpa JavaScript) -->
    <meta http-equiv="refresh" content="20">

    <title>My Orders | LŪMA Café</title>
    <link rel="stylesheet" href="my_orders.css?v=4">

    <style>
        .order-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .order-progress-note {
            margin: 0;
            padding: 12px 28px;
            font-size: 14px;
            font-weight: 500;
            color: #6b4f3a;
            background: #fbf7f2;
            border-bottom: 1px solid #efe6db;
        }

        .orders-success {
            margin: 0 0 24px;
            padding: 14px 18px;
            border-radius: 14px;
            background: #eef6ee;
            border: 1px solid #cfe3cf;
            color: #2f5d34;
            font-size: 14px;
        }
    </style>
</head>

<body>

<!-- NAVBAR -->
<header class="orders-navbar">
    <a href="dashboard.php" class="orders-logo">
        LŪMA<span>.</span>
    </a>

    <nav class="nav-links">
        <a href="dashboard.php">Home</a>
        <a href="menu.php">Menu</a>
        <a href="luma_moments.php">LŪMA Moments</a>
        <a href="dashboard.php#rewards">Rewards</a>
        <a href="my_orders.php" class="active">My Orders</a>
        <a href="dashboard.php#profile">Profile</a>
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

    <?php if ($justOrdered && $error === ''): ?>
        <div class="orders-success">
            Pesananmu sudah kami terima dan akan segera diproses oleh kasir.
        </div>
    <?php endif; ?>

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
                [$statusLabel, $statusCss, $statusNote] = progressInfo($order['order_status']);
                [$payLabel, $payCss] = paymentInfo($order['status']);
                $details = $orderDetails[$orderId] ?? [];
                $timestamp = strtotime((string) $order['order_date']);
                ?>

                <article class="order-card">

                    <div class="order-card-header">
                        <div>
                            <span class="order-number">
                                ORDER #<?= e($orderId) ?>
                            </span>

                            <h2>
                                <?= $timestamp
                                    ? e(date('d M Y, H:i', $timestamp))
                                    : e($order['order_date']) ?>
                            </h2>
                        </div>

                        <div class="order-badges">
                            <span class="order-status <?= e($payCss) ?>">
                                <?= e($payLabel) ?>
                            </span>

                            <span class="order-status <?= e($statusCss) ?>">
                                <?= e($statusLabel) ?>
                            </span>
                        </div>
                    </div>

                    <p class="order-progress-note">
                        <?= e($statusNote) ?>
                    </p>

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