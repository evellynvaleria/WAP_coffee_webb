<?php
session_start();

// Pastikan hanya customer yang dapat mengakses Cart
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'customer'
) {
    header('Location: ../login.php');
    exit;
}

// Pastikan Cart tersedia
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Ambil key item yang ingin dihapus
$key = $_GET['key'] ?? '';

// Hapus hanya item yang dipilih
if ($key !== '' && array_key_exists($key, $_SESSION['cart'])) {
    unset($_SESSION['cart'][$key]);
}

// Kembali ke halaman Cart
header('Location: cart.php');
exit;
