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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: luma_moments.php');
    exit;
}

function parsePrice($value): float
{
    if (is_numeric($value)) {
        return (float) $value;
    }

    $value = trim((string) $value);
    $value = str_ireplace('Rp', '', $value);
    $value = preg_replace('/\s+/', '', $value);

    // Format harga Indonesia, misalnya 50.000 atau 50.000,50
    $value = str_replace('.', '', $value);
    $value = str_replace(',', '.', $value);

    return is_numeric($value) ? (float) $value : 0;
}

$menuId = filter_input(INPUT_POST, 'menu_id', FILTER_VALIDATE_INT);
$size = strtolower(trim($_POST['size'] ?? ''));
$quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);

if (
    !$menuId ||
    !$quantity ||
    $quantity < 1 ||
    $quantity > 50
) {
    exit('Invalid order data.');
}

/* Ambil data menu dan kategorinya */
$stmt = $conn->prepare("
    SELECT
        m.menu_id,
        m.menu_name,
        m.image,
        m.price AS menu_price,
        c.category_name
    FROM menus m
    LEFT JOIN categories c
        ON c.category_id = m.category_id
    WHERE m.menu_id = ?
      AND m.status = 'Available'
    LIMIT 1
");

$stmt->bind_param('i', $menuId);
$stmt->execute();
$menu = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$menu) {
    exit('This menu item is not available.');
}

/* Bedakan pastry dengan menu lainnya */
$isPastry = strtolower(trim($menu['category_name'] ?? '')) === 'pastry';

if ($isPastry) {
    // Pastry tidak membutuhkan menu_details
    $size = 'regular';
    $price = parsePrice($menu['menu_price']);
} else {
    // Minuman tetap wajib memiliki ukuran di menu_details
    if (!in_array($size, ['small', 'medium', 'large'], true)) {
        exit('Invalid menu size.');
    }

    $stmt = $conn->prepare("
        SELECT size, price
        FROM menu_details
        WHERE menu_id = ?
          AND LOWER(size) = ?
        LIMIT 1
    ");

    $stmt->bind_param('is', $menuId, $size);
    $stmt->execute();
    $detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$detail) {
        exit('This menu size is not available.');
    }

    $size = strtolower($detail['size']);
    $price = (float) $detail['price'];
}

if ($price <= 0) {
    exit('Invalid menu price. Please check the price in the database.');
}

/* Simpan ke keranjang session */
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$key = $menuId . '-' . $size;

if (isset($_SESSION['cart'][$key])) {
    $newQuantity =
        (int) $_SESSION['cart'][$key]['quantity'] + $quantity;

    if ($newQuantity > 50) {
        exit('The maximum quantity for a single item is 50.');
    }

    $_SESSION['cart'][$key]['quantity'] = $newQuantity;
} else {
    $_SESSION['cart'][$key] = [
        'menu_id'   => (int) $menu['menu_id'],
        'menu_name' => $menu['menu_name'],
        'image'     => $menu['image'],
        'size'      => $size,
        'price'     => $price,
        'quantity'  => $quantity
    ];
}

header('Location: cart.php');
exit;