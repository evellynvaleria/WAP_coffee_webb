<?php
session_start();

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'customer'
) {
    header('Location: ../login.php');
    exit;
}

// Koneksi database
require_once __DIR__ . '/../config/database.php';

// Ambil cart dari session
$cart = $_SESSION['cart'] ?? [];

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

// Hitung total harga
$total = 0;

foreach ($cart as $item) {
    $quantity = max(1, (int) ($item['quantity'] ?? 1));

    $total += (float) ($item['price'] ?? 0) * $quantity;
}

/*
 * Konfirmasi hapus item (pengganti JavaScript confirm()).
 * Klik "Remove" -> cart.php?confirm_remove=KEY -> tampil kotak konfirmasi.
 * Klik "Yes, remove" -> remove_from_cart.php?key=KEY
 */
$confirmKey  = isset($_GET['confirm_remove'])
    ? (string) $_GET['confirm_remove']
    : '';

$confirmItem = ($confirmKey !== '' && isset($cart[$confirmKey]))
    ? $cart[$confirmKey]
    : null;

// Siapkan query foto menu dari database
$imageStmt = $conn->prepare(
    "SELECT image FROM menus WHERE menu_id = ?"
);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Cart | LŪMA Café</title>

    <link rel="stylesheet" href="cart.css">
</head>

<body>

<header class="navbar">
    <div class="nav-content">

        <a href="dashboard.php" class="logo">
            LŪMA<span>.</span>
        </a>

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

    <?php if ($confirmItem !== null): ?>

        <section class="confirm-box">
            <p>
                Remove
                <strong><?= e($confirmItem['menu_name'] ?? 'this item') ?></strong>
                from your cart?
            </p>

            <div class="confirm-actions">
                <a
                    class="confirm-yes"
                    href="remove_from_cart.php?key=<?= urlencode($confirmKey) ?>"
                >
                    Yes, remove
                </a>

                <a class="confirm-cancel" href="cart.php">
                    Cancel
                </a>
            </div>
        </section>

    <?php endif; ?>

    <?php if (empty($cart)): ?>

        <section class="empty-cart">
            <h2>Your cart is empty</h2>
            <p>Discover your next favorite drink at LŪMA.</p>

            <a href="menu.php" class="btn-primary">
                Explore Menu
            </a>
        </section>

    <?php else: ?>

        <div class="cart-layout">

            <section class="cart-items">

                <?php foreach ($cart as $key => $item): ?>

                    <?php
                    $name = $item['menu_name'] ?? 'Menu item';
                    $size = $item['size'] ?? '-';

                    $price = (float) ($item['price'] ?? 0);

                    $quantity = max(
                        1,
                        (int) ($item['quantity'] ?? 1)
                    );

                    // Ambil ID menu dari cart
                    $menuId = (int) ($item['menu_id'] ?? 0);

                    // Ambil nama foto dari tabel menus
                    $image = '';

                    if ($menuId > 0) {
                        $imageStmt->bind_param('i', $menuId);
                        $imageStmt->execute();

                        $imageResult = $imageStmt->get_result();
                        $imageData = $imageResult->fetch_assoc();

                        if ($imageData) {
                            $image = trim(
                                (string) ($imageData['image'] ?? '')
                            );
                        }
                    }

                    // Folder gambar: WAP_coffee_webb/assets/menu/
                    $imageName = basename($image);

                    // Cek file gambar di server (pengganti JavaScript onerror)
                    $imageFile = __DIR__ . '/../assets/menu/' . $imageName;

                    $imageSrc = ($imageName !== '' && is_file($imageFile))
                        ? '../assets/menu/' . rawurlencode($imageName)
                        : '';
                    ?>

                    <article class="cart-item">

                        <?php if ($imageSrc !== ''): ?>

                            <img
                                src="<?= e($imageSrc) ?>"
                                alt="<?= e($name) ?>"
                                class="product-image"
                            >

                        <?php else: ?>

                            <div class="product-placeholder">
                                LŪMA
                            </div>

                        <?php endif; ?>

                        <div class="item-info">

                            <h3><?= e($name) ?></h3>

                            <p>Size: <?= e($size) ?></p>

                            <p>
                                <?= rupiah($price) ?>
                                × <?= $quantity ?>
                            </p>

                            <strong>
                                <?= rupiah($price * $quantity) ?>
                            </strong>

                        </div>

                        <a
                            class="remove-link"
                            href="cart.php?confirm_remove=<?= urlencode((string) $key) ?>"
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

                    <span>
                        <?= array_sum(array_map(
                            fn($item) => max(
                                1,
                                (int) ($item['quantity'] ?? 1)
                            ),
                            $cart
                        )) ?>
                    </span>
                </div>

                <div class="summary-row total-row">
                    <span>Total</span>
                    <strong><?= rupiah($total) ?></strong>
                </div>

                <a
                    href="checkout.php"
                    class="btn-primary checkout-button"
                >
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
    <p>
        © <?= date('Y') ?> LŪMA Café.
        Crafted for your everyday moments.
    </p>
</footer>

<?php
$imageStmt->close();
?>

</body>
</html>