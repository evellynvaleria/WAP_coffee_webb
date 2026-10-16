<?php

ob_start();

// Tampilkan error PHP saat development (hapus dua baris ini kalau sudah selesai)
ini_set('display_errors', '1');
error_reporting(E_ALL);

session_start();

require_once "config/database.php";
require_once "config/rewards.php";

/* Saat development: true = pesan error database ditampilkan di form.
   Kalau sudah selesai, ganti ke false. */
const SHOW_DB_ERROR = true;


/* =====================================================
   CEK KONEKSI DATABASE
===================================================== */

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Koneksi database tidak ditemukan. Pastikan config/database.php membuat variabel \$conn (mysqli).");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);


/* =====================================================
   HELPER: REDIRECT
===================================================== */

function redirectTo(string $url): void
{
    if (!headers_sent()) {
        header("Location: " . $url);
        exit;
    }

    echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url) . '">';
    echo '<p>Akun berhasil dibuat. <a href="' . htmlspecialchars($url) . '">Klik di sini untuk login</a></p>';
    exit;
}


/* =====================================================
   JIKA USER SUDAH LOGIN
===================================================== */

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    redirectTo("login.php");
}


/* =====================================================
   CSRF TOKEN
===================================================== */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


/* =====================================================
   VARIABLE
===================================================== */

$error = "";


/* =====================================================
   PROSES REGISTER
   Register hanya untuk CUSTOMER:
   data masuk ke tabel users, lalu customer, lalu rewards.
   Staff tidak bisa register (akun staff dibuat manual).
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name     = trim($_POST['name'] ?? '');
    $email    = strtolower(trim($_POST['email'] ?? ''));
    // Nomor HP dirapikan (08xxxx) karena dipakai sebagai identitas member
    $phone    = normalizePhone((string)($_POST['phone'] ?? ''));
    $gender   = (string)($_POST['gender'] ?? '');
    $dob      = trim($_POST['date_of_birth'] ?? '');
    $address  = trim($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    $token    = $_POST['csrf_token'] ?? '';

    $started = false;

    try {

        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            throw new Exception("Invalid request. Please refresh the page and try again.");
        }

        if ($name === '' || $email === '' || $phone === '' || $password === '' || $confirm === '') {
            throw new Exception("Please fill in all required fields.");
        }

        if (mb_strlen($name) > 100) {
            throw new Exception("Name is too long.");
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format.");
        }

        // Domain internal dipakai akun walk-in & member dari kasir
        if (substr($email, -10) === '@luma.local') {
            throw new Exception("Please use a different email address.");
        }

        if (strlen($phone) < 8 || strlen($phone) > 15) {
            throw new Exception("Invalid phone number.");
        }

        if (!in_array($gender, ['L', 'P'], true)) {
            throw new Exception("Please select your gender.");
        }

        $dobDate = DateTime::createFromFormat('Y-m-d', $dob);

        if (!$dobDate || $dobDate->format('Y-m-d') !== $dob || $dobDate > new DateTime('today')) {
            throw new Exception("Invalid date of birth.");
        }

        if ($address === '') {
            $address = '-';
        }

        if (strlen($password) < 8) {
            throw new Exception("Password must be at least 8 characters.");
        }

        if ($password !== $confirm) {
            throw new Exception("Passwords do not match.");
        }

        // Email / no. HP tidak boleh sudah terdaftar
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? OR phone = ? LIMIT 1");
        $stmt->bind_param("ss", $email, $phone);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($exists) {
            throw new Exception("Email or phone number is already registered.");
        }

        // UJI COBA: password disimpan apa adanya (TANPA hash)
        $storedPassword = $password;

        $conn->begin_transaction();
        $started = true;

        // 1. Masuk ke tabel users
        $stmt = $conn->prepare("
            INSERT INTO users (name, email, password, phone, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("ssss", $name, $email, $storedPassword, $phone);
        $stmt->execute();
        $newUserId = $conn->insert_id;
        $stmt->close();

        if ($newUserId <= 0) {
            throw new Exception("Failed to create user (user_id is not AUTO_INCREMENT).");
        }

        // 2. Lalu masuk ke tabel customer
        $stmt = $conn->prepare("
            INSERT INTO customer (user_id, gender, address, date_of_birth)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("isss", $newUserId, $gender, $address, $dob);
        $stmt->execute();
        $stmt->close();

        // 3. Terakhir, buat row rewards (0 poin, level Bronze)
        ensureRewardRow($conn, $newUserId);

        $conn->commit();

        // 4. Kembali ke halaman login
        $_SESSION['csrf_token']       = bin2hex(random_bytes(32));
        $_SESSION['register_success'] = "Account created. Please login.";

        redirectTo("login.php");

    } catch (Throwable $err) {

        if ($started) {
            try {
                $conn->rollback();
            } catch (Throwable $ignore) {
            }
        }

        if ($err instanceof mysqli_sql_exception) {

            error_log("Register DB error: " . $err->getMessage());

            $error = SHOW_DB_ERROR
                ? "Database error: " . $err->getMessage()
                : "Something went wrong. Please try again later.";

        } else {
            $error = $err->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>LÜMA Coffee - Register</title>

    <!-- Bootstrap CSS -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Bootstrap Icons -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
    >

    <!-- Register CSS -->
    <link
        rel="stylesheet"
        href="css/regist.css"
    >

</head>

<body>

<div class="container-fluid p-0">

    <div class="row g-0">

        <!-- ================= POSTER ================= -->

        <div class="col-md-4 poster-section">

            <img
                src="assets/Group 177.png"
                alt="Luma Coffee"
            >

        </div>


        <!-- ================= REGISTER ================= -->

        <div class="col-md-8 login-section">

            <div class="login-content">

                <h1>Create Account</h1>

                <p class="subtitle">Register to start ordering</p>


                <!-- ERROR MESSAGE -->

                <?php if (!empty($error)): ?>

                    <div class="alert alert-danger">
                        <?= htmlspecialchars($error) ?>
                    </div>

                <?php endif; ?>


                <!-- REGISTER FORM (action kosong = kirim ke halaman ini sendiri) -->

                <form
                    method="POST"
                    action=""
                    autocomplete="on"
                >

                    <!-- CSRF TOKEN -->
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
                    >


                    <!-- NAME -->

                    <div class="input-group-custom">

                        <span class="input-icon">👤</span>

                        <input
                            type="text"
                            name="name"
                            class="form-control"
                            placeholder="Full name"
                            maxlength="100"
                            value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                            autocomplete="name"
                            required
                        >

                    </div>


                    <!-- EMAIL -->

                    <div class="input-group-custom">

                        <span class="input-icon">✉️</span>

                        <input
                            type="email"
                            name="email"
                            class="form-control"
                            placeholder="Email"
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                            autocomplete="email"
                            required
                        >

                    </div>


                    <!-- PHONE -->

                    <div class="input-group-custom">

                        <span class="input-icon">📞</span>

                        <input
                            type="tel"
                            name="phone"
                            class="form-control"
                            placeholder="Phone number"
                            inputmode="numeric"
                            value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                            autocomplete="tel"
                            required
                        >

                    </div>


                    <!-- GENDER -->

                    <div class="input-group-custom">

                        <span class="input-icon">⚧</span>

                        <select
                            name="gender"
                            class="form-control"
                            required
                        >
                            <option value="">Gender</option>
                            <option value="P" <?= ($_POST['gender'] ?? '') === 'P' ? 'selected' : '' ?>>Perempuan</option>
                            <option value="L" <?= ($_POST['gender'] ?? '') === 'L' ? 'selected' : '' ?>>Laki-laki</option>
                        </select>

                    </div>


                    <!-- DATE OF BIRTH -->

                    <div class="input-group-custom">

                        <span class="input-icon">🎂</span>

                        <input
                            type="date"
                            name="date_of_birth"
                            class="form-control"
                            max="<?= date('Y-m-d') ?>"
                            value="<?= htmlspecialchars($_POST['date_of_birth'] ?? '') ?>"
                            required
                        >

                    </div>


                    <!-- ADDRESS (opsional) -->

                    <div class="input-group-custom">

                        <span class="input-icon">📍</span>

                        <input
                            type="text"
                            name="address"
                            class="form-control"
                            placeholder="Address (optional)"
                            value="<?= htmlspecialchars($_POST['address'] ?? '') ?>"
                            autocomplete="street-address"
                        >

                    </div>


                    <!-- PASSWORD -->

                    <div class="input-group-custom">

                        <span class="input-icon">🔒</span>

                        <input
                            type="password"
                            name="password"
                            class="form-control"
                            placeholder="Password (min. 8 characters)"
                            minlength="8"
                            autocomplete="new-password"
                            required
                        >

                    </div>


                    <!-- CONFIRM PASSWORD -->

                    <div class="input-group-custom">

                        <span class="input-icon">🔒</span>

                        <input
                            type="password"
                            name="confirm_password"
                            class="form-control"
                            placeholder="Confirm password"
                            minlength="8"
                            autocomplete="new-password"
                            required
                        >

                    </div>


                    <!-- REGISTER BUTTON -->

                    <button
                        type="submit"
                        class="login-btn"
                    >
                        Register
                    </button>


                    <!-- OR -->

                    <div class="divider">
                        <span></span>
                        <p>or</p>
                        <span></span>
                    </div>


                    <!-- LOGIN -->

                    <p class="register-text">

                        Already have an account?

                        <a href="login.php">Login</a>

                    </p>

                </form>

            </div>

        </div>

    </div>

</div>

</body>

</html>