
<?php
session_start();

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'customer'
) {
    header('Location: ../login.php');
    exit;
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function rupiah($value): string {
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

$cart = $_SESSION['cart'] ?? [];
$total = 0;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Cart | LŪMA Café</title>
    <link rel="stylesheet" href="luma_moments.css">
</head>

<body>

<main class="container section">

    <a href="menu.php" class="text-link">
        ← Back to add some menu
    </a>

    <div class="section-heading" style="margin-top: 24px">
        <div>
            <span class="eyebrow">YOUR SELECTION</span>
            <h2>Your Cart</h2>
            <p>Please check your orders before checkout.</p>
        </div>
    </div>

    <?php if (empty($cart)): ?>

        <div class="empty-state">
            <h3>Your cart is waiting.</h3>
            <p>your cart is empty, please add some menu.</p>

            <a href="menu.php" class="text-link">
                Let's start your journey →
            </a>
        </div>

    <?php else: ?>

        <div class="orders-list">

            <?php foreach ($cart as $key => $item): ?>

                <?php
                $subtotal = (float) $item['price']
                          * (int) $item['quantity'];

                $total += $subtotal;
                ?>

                <article class="order-card">

                    <div class="order-top">
                        <div>
                            <strong>
                                <?= e($item['menu_name']) ?>
                            </strong>

                            <p>
                                Size: <?= e(ucfirst($item['size'])) ?>
                            </p>

                            <p>
                                Quantity: <?= (int) $item['quantity'] ?>
                            </p>
                        </div>

                        <strong>
                            <?= e(rupiah($subtotal)) ?>
                        </strong>
                    </div>

                    <p>
                        <?= e(rupiah($item['price'])) ?> per item
                    </p>

                    <!-- Tombol Remove Item -->
                    <form
                        action="remove_from_cart.php"
                        method="POST"
                        onsubmit="return confirm('Hapus menu ini dari keranjang?');"
                    >
                        <input
                            type="hidden"
                            name="action"
                            value="remove"
                        >

                        <input
                            type="hidden"
                            name="key"
                            value="<?= e($key) ?>"
                        >

                        <button
                            type="submit"
                            class="remove-cart-button"
                        >
                            Remove item
                        </button>
                    </form>

                </article>

            <?php endforeach; ?>

        </div>

        <!-- Ringkasan Keranjang -->
        <div class="rewards-card" style="margin-top: 24px">

            <div>
                <span class="reward-label">TOTAL</span>

                <div class="points-number">
                    <?= e(rupiah($total)) ?>
                </div>
            </div>

            <!-- Tombol Clear Cart -->
            <form
                action="remove_from_cart.php"
                method="POST"
                onsubmit="return confirm('Yakin ingin mengosongkan seluruh keranjang?');"
            >
                <input
                    type="hidden"
                    name="action"
                    value="clear"
                >

                <button
                    type="submit"
                    class="clear-cart-button"
                >
                    Clear Cart
                </button>
            </form>

            <!-- Tombol Checkout -->
            <div class="reward-level">
                <a href="checkout.php" class="btn-primary">
                    Continue to Checkout →
                </a>
            </div>

        </div>

    <?php endif; ?>

</main>

</body>
</html>