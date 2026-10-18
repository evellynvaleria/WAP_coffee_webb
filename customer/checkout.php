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

function e($value): string {
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function rupiah($value): string {
    return 'Rp ' . number_format(
        (float) $value,
        0,
        ',',
        '.'
    );
}

// Ambil data Cart
$cart = $_SESSION['cart'] ?? [];

if (empty($cart)) {
    header('Location: cart.php');
    exit;
}

// Hitung total pembayaran
$total = 0;

foreach ($cart as $item) {
    $price = (float) ($item['price'] ?? 0);
    $quantity = max(
        1,
        (int) ($item['quantity'] ?? 1)
    );

    $total += $price * $quantity;
}

// Metode pembayaran yang tersedia
$paymentMethods = ['QRIS', 'Cash', 'Debit'];

// Nilai awal form
$error = '';
$orderType = 'Dine In';
$paymentMethod = '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Checkout | LŪMA Café</title>

    <!-- CSS khusus Checkout -->
    <link rel="stylesheet" href="checkout.css?v=3">
</head>

<body>

<!-- NAVBAR -->
<header class="checkout-navbar">

    <a href="dashboard.php" class="checkout-logo">
        LŪMA<span>.</span>
    </a>

    <nav class="checkout-nav-links">
        <a href="dashboard.php">Home</a>
        <a href="menu.php">Menu</a>
        <a href="luma_moments.php">LŪMA Moments</a>
        <a href="dashboard.php#rewards">Rewards</a>
        <a href="my_orders.php">My Orders</a>
    </nav>

    <a href="cart.php" class="back-to-cart">
        ← Back to Cart
    </a>

</header>

<!-- MAIN CONTENT -->
<main class="checkout-container">

    <section class="checkout-heading">
        <p class="checkout-eyebrow">ALMOST YOURS</p>

        <h1>Checkout</h1>

        <p>
            Review your order before placing it.
        </p>
    </section>

    <div class="checkout-layout">

        <!-- ORDER SUMMARY -->
        <section class="checkout-panel">

            <h2>Order Summary</h2>

            <?php foreach ($cart as $item): ?>

                <?php
                $name = $item['menu_name'] ?? 'Menu item';
                $size = $item['size'] ?? 'Regular';

                $price = (float) ($item['price'] ?? 0);

                $quantity = max(
                    1,
                    (int) ($item['quantity'] ?? 1)
                );

                $subtotal = $price * $quantity;
                ?>

                <article class="checkout-item">

                    <div class="checkout-item-info">

                        <h3><?= e($name) ?></h3>

                        <p>
                            Size: <?= e(ucfirst($size)) ?>
                        </p>

                        <p>
                            Qty: <?= $quantity ?>
                        </p>

                    </div>

                    <strong>
                        <?= rupiah($subtotal) ?>
                    </strong>

                </article>

            <?php endforeach; ?>

            <div class="checkout-total">

                <span>Total Payment</span>

                <strong>
                    <?= rupiah($total) ?>
                </strong>

            </div>

            <a href="cart.php" class="checkout-cancel">
                ← Edit Cart
            </a>

        </section>

        <!-- CHECKOUT FORM -->
        <section class="checkout-panel">

            <h2>Order Details</h2>

            <p class="checkout-description">
                Choose your order type and payment method.
            </p>

            <form
                action="place_order.php"
                method="POST"
                class="checkout-form"
            >

                <!-- ORDER TYPE -->
                <div class="form-group">

                    <label for="order_type">
                        Order Type
                    </label>

                    <select
                        name="order_type"
                        id="order_type"
                        required
                    >
                        <option
                            value="Dine In"
                            <?= $orderType === 'Dine In' ? 'selected' : '' ?>
                        >
                            Dine In
                        </option>

                        <option value="Take Away">
                            Take Away
                        </option>
                    </select>

                </div>

                <!-- PAYMENT METHOD -->
                <div class="form-group">

                    <label for="payment_method">
                        Payment Method
                    </label>

                    <select
                        name="payment_method"
                        id="payment_method"
                        required
                    >

                        <option value="">
                            Choose payment method
                        </option>

                        <?php foreach ($paymentMethods as $method): ?>

                            <option
                                value="<?= e($method) ?>"
                                <?= $paymentMethod === $method ? 'selected' : '' ?>
                            >
                                <?= e($method) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <p class="checkout-note">
                    Your order details and selected payment
                    method will be recorded with your order.
                </p>

                <!-- TOTAL -->
                <div class="checkout-total form-total">

                    <span>Total to Pay</span>

                    <strong>
                        <?= rupiah($total) ?>
                    </strong>

                </div>

                <!-- PLACE ORDER -->
                <button
                    type="submit"
                    name="place_order"
                    value="1"
                    class="checkout-submit"
                    <?= $total <= 0 ? 'disabled' : '' ?>
                >
                    Place Order · <?= rupiah($total) ?>
                </button>

                <a
                    href="cart.php"
                    class="checkout-back-link"
                >
                    Return to Cart
                </a>

            </form>

        </section>

    </div>

</main>

<footer class="checkout-footer">
    <p>
        © <?= date('Y') ?> LŪMA Café.
        Crafted for your everyday moments.
    </p>
</footer>

</body>
</html>