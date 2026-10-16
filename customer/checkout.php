
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
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function rupiah($value): string {
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

$cart = $_SESSION['cart'] ?? [];

if (empty($cart)) {
    header('Location: cart.php');
    exit;
}

$total = 0;

foreach ($cart as $item) {
    $price = (float)($item['price'] ?? 0);
    $quantity = max(1, (int)($item['quantity'] ?? 1));

    $total += $price * $quantity;
}

$paymentMethods = ['QRIS', 'Cash', 'Debit'];
$error = '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout | LŪMA Café</title>
    <link rel="stylesheet" href="luma_moments.css">
    <link rel="stylesheet" href="checkout.css">
</head>
<body>

<header class="checkout-navbar">
    <a href="menu.php" class="checkout-logo">LŪMA<span>.</span></a>
    <a href="cart.php" class="back-to-cart">← Back to Cart</a>
</header>

<main class="checkout-container">

    <section class="checkout-heading">
        <p class="checkout-eyebrow">ALMOST YOURS</p>
        <h1>Checkout</h1>
        <p>Review your order before placing it.</p>
    </section>

    <div class="checkout-layout">

        <section class="checkout-panel">
            <h2>Order Summary</h2>

            <?php foreach ($cart as $item): ?>
                <?php
                $price = (float)$item['price'];
                $quantity = max(1, (int)$item['quantity']);
                $subtotal = $price * $quantity;
                ?>

                <div class="checkout-item">
                    <div class="checkout-item-info">
                        <h3><?= e($item['menu_name']) ?></h3>

                        <p>
                            Size: <?= e(ucfirst($item['size'] ?? 'regular')) ?>
                        </p>

                        <p>Qty: <?= $quantity ?></p>
                    </div>

                    <strong><?= rupiah($subtotal) ?></strong>
                </div>
            <?php endforeach; ?>

            <div class="checkout-total">
                <span>Total Payment</span>
                <strong><?= rupiah($total) ?></strong>
            </div>
        </section>

        <section class="checkout-panel">
                

            <h2>Payment Method</h2>

            <!-- ORDER TYPE -->
        <div class="form-group">
            <label for="order_type">Order Type</label>

            <select name="order_type" id="order_type" required>
                <option value="Dine In">Dine In</option>
                <option value="Take Away">Take Away</option>
            </select>
        </div>

            <form action="place_order.php" method="POST">

                <label for="payment_method">
                    Select your payment method
                </label>

                <select
                    name="payment_method"
                    id="payment_method"
                    required
                >
                    <option value="">Choose payment method</option>
                    <?php foreach ($paymentMethods as $method): ?>
                        <option value="<?= e($method) ?>">
                            <?= e($method) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
        

                <p class="checkout-note">
                    Your selected payment method will be recorded
                    with your order.
                </p>
                

                <button
                    type="submit"
                    class="checkout-submit"
                    <?= $total <= 0 ? 'disabled' : '' ?>
                >
                    Place Order · <?= rupiah($total) ?>
                </button>

                <a href="cart.php" class="checkout-cancel">
                    Return to Cart
                </a>

            </form>
        </section>

    </div>

</main>

</body>
</html>