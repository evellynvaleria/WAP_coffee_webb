<?php
session_start();

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'customer'
) {
    header('Location: ../login.php');
    exit;
}

$cart = $_SESSION['cart'] ?? [];

function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rupiah($value): string {
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

$total = 0;

foreach ($cart as $item) {
    $total += (float) ($item['price'] ?? 0) * (int) ($item['quantity'] ?? 1);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Cart | LŪMA Café</title>
    <link rel="stylesheet" href="cart.css">
</head>
<body>

<header class="navbar">
    <div class="nav-content">
        <a href="dashboard.php" class="logo">LŪMA<span>.</span></a>

        <nav class="nav-links">
            <a href="dashboard.php">Home</a>
            <a href="menu.php">Menu</a>
            <a href="luma_moments.php">LŪMA Moments</a>
            <a href="dashboard.php#rewards">Rewards</a>
            <a href="my_orders.php">My Orders</a>
        </nav>
    </div>
</header>

<main class="container">
    <div class="page-heading">
        <p class="eyebrow">YOUR SELECTION</p>
        <h1>My Cart</h1>
        <p>Review your favorites before placing your order.</p>
    </div>

    <?php if (empty($cart)): ?>
        <section class="empty-cart">
            <h2>Your cart is empty</h2>
            <p>Discover your next favorite drink at LŪMA.</p>
            <a href="menu.php" class="btn-primary">Explore Menu</a>
        </section>
    <?php else: ?>

        <div class="cart-layout">
            <section class="cart-items">
                <?php foreach ($cart as $key => $item): ?>
                    <?php
                    $name = $item['menu_name'] ?? 'Menu item';
                    $size = $item['size'] ?? '-';
                    $price = (float) ($item['price'] ?? 0);
                    $quantity = max(1, (int) ($item['quantity'] ?? 1));
                    $image = $item['image'] ?? '';
                    ?>

                    <article class="cart-item">
                        <?php if ($image !== ''): ?>
                            <img
                                src="../<?= e(ltrim($image, '/')) ?>"
                                alt="<?= e($name) ?>"
                                class="product-image"
                            >
                        <?php else: ?>
                            <div class="product-placeholder">LŪMA</div>
                        <?php endif; ?>

                        <div class="item-info">
                            <h3><?= e($name) ?></h3>
                            <p>Size: <?= e($size) ?></p>
                            <p><?= rupiah($price) ?> × <?= $quantity ?></p>
                            <strong><?= rupiah($price * $quantity) ?></strong>
                        </div>

                        <a
                            class="remove-link"
                            href="remove_from_cart.php?key=<?= urlencode((string) $key) ?>"
                            onclick="return confirm('Remove this item from your cart?')"
                        >
                            Remove
                        </a>
                    </article>
                <?php endforeach; ?>
            </section>

            <aside class="order-summary">
                <h2>Order Summary</h2>

                <div class="summary-row">
                    <span>Total items</span>
                    <span><?= array_sum(array_map(
                        fn($item) => max(1, (int) ($item['quantity'] ?? 1)),
                        $cart
                    )) ?></span>
                </div>

                <div class="summary-row total-row">
                    <span>Total</span>
                    <strong><?= rupiah($total) ?></strong>
                </div>

                <!-- Link langsung menuju checkout.php -->
                <a href="checkout.php" class="btn-primary checkout-button">
                    Proceed to Checkout
                </a>

                <a href="menu.php" class="continue-link">
                    Continue Shopping
                </a>
            </aside>
        </div>

    <?php endif; ?>
</main>

<footer class="footer">
    <p>© <?= date('Y') ?> LŪMA Café. Crafted for your everyday moments.</p>
</footer>

</body>
</html>