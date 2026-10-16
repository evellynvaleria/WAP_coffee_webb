<?php
session_start();

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'customer'
) {
    header('Location: ../login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: cart.php');
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'remove') {
    $key = $_POST['key'] ?? '';

    if (is_string($key) && isset($_SESSION['cart'][$key])) {
        unset($_SESSION['cart'][$key]);
    }
} elseif ($action === 'clear') {
    $_SESSION['cart'] = [];
}

header('Location: cart.php');
exit;