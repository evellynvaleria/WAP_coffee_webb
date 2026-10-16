<?php

session_start();

require_once "config/database.php";


/* =====================================================
   HELPER: REDIRECT BERDASARKAN ROLE
===================================================== */

function redirectByRole(string $role): void
{
    $routes = [
        'customer' => 'customer/dashboard.php',
        'staff'    => 'staff/dashboard.php',
    ];

    if (isset($routes[$role])) {
        header("Location: " . $routes[$role]);
        exit;
    }

    // Role tidak dikenal: hapus session supaya tidak terjadi loop
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}


/* =====================================================
   HELPER: VERIFIKASI PASSWORD
   - Mendukung hash baru (password_hash)
   - Mendukung password lama (plain text), lalu otomatis
     di-upgrade menjadi hash saat login berhasil
===================================================== */

function verifyPassword(string $input, array $user, mysqli $conn): bool
{
    $stored = $user['password'];

    // Sudah berupa hash
    if (password_get_info($stored)['algo'] !== null) {
        return password_verify($input, $stored);
    }

    // Masih plain text (data lama)
    if (hash_equals($stored, $input)) {

        $newHash = password_hash($input, PASSWORD_DEFAULT);

        $up = $conn->prepare(
            "UPDATE users SET password = ? WHERE user_id = ?"
        );

        if ($up) {
            $up->bind_param("si", $newHash, $user['user_id']);
            $up->execute();
            $up->close();
        }

        return true;
    }

    return false;
}


/* =====================================================
   HELPER: AKUN INTERNAL (WALK-IN & MEMBER DARI KASIR)
   Akun ini dibuat sistem dan tidak boleh dipakai login
===================================================== */

function isInternalAccount(string $email): bool
{
    return str_ends_with(strtolower($email), '@luma.local');
}


/* =====================================================
   JIKA USER SUDAH LOGIN
===================================================== */

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    redirectByRole($_SESSION['role']);
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

// Pesan sukses dari register.php (tampil sekali saja)
$success = $_SESSION['register_success'] ?? "";
unset($_SESSION['register_success']);


/* =====================================================
   PROSES LOGIN
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $token    = $_POST['csrf_token'] ?? '';

    // Pembatasan percobaan login (per session)
    $_SESSION['login_attempts'] = $_SESSION['login_attempts'] ?? 0;
    $_SESSION['last_attempt']   = $_SESSION['last_attempt'] ?? 0;

    // Reset hitungan jika sudah lewat 5 menit
    if (time() - $_SESSION['last_attempt'] >= 300) {
        $_SESSION['login_attempts'] = 0;
    }

    if (!hash_equals($_SESSION['csrf_token'], $token)) {

        $error = "Invalid request. Please refresh the page and try again.";

    } elseif ($_SESSION['login_attempts'] >= 5) {

        $error = "Too many failed attempts. Please try again in 5 minutes.";

    } elseif ($email === '' || $password === '') {

        $error = "Please enter your email and password.";

    } else {

        /* ---------------------------------------------
           CARI USER + CEK APAKAH DIA STAFF
        --------------------------------------------- */

        $sql = "
            SELECT
                u.user_id,
                u.name,
                u.email,
                u.password,
                u.phone,

                s.staff_id,
                s.position,
                s.role AS staff_role
            FROM users u

            LEFT JOIN staff s
                ON u.user_id = s.user_id

            WHERE u.email = ?

            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {

            error_log("Login DB error: " . $conn->error);
            $error = "Something went wrong. Please try again later.";

        } else {

            $stmt->bind_param("s", $email);
            $stmt->execute();

            $user = $stmt->get_result()->fetch_assoc();

            $stmt->close();

            if (
                $user
                && !isInternalAccount($user['email'])
                && verifyPassword($password, $user, $conn)
            ) {

                /* -------------------------------------
                   LOGIN BERHASIL
                ------------------------------------- */

                session_regenerate_id(true);

                // Ada di tabel staff = staff, selain itu = customer
                $role = !empty($user['staff_id']) ? 'staff' : 'customer';

                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['name']    = $user['name'];
                $_SESSION['email']   = $user['email'];
                $_SESSION['role']    = $role;

                if ($role === 'staff') {
                    $_SESSION['staff_id']   = $user['staff_id'];
                    $_SESSION['position']   = $user['position'];
                    $_SESSION['staff_role'] = $user['staff_role'];
                }

                unset($_SESSION['login_attempts'], $_SESSION['last_attempt']);

                // Token baru setelah login
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                redirectByRole($role);

            } else {

                /* -------------------------------------
                   LOGIN GAGAL
                ------------------------------------- */

                $_SESSION['login_attempts']++;
                $_SESSION['last_attempt'] = time();

                $error = "Incorrect email or password.";
            }
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

    <title>LÜMA Coffee - Login</title>

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

    <!-- Login CSS -->
    <link
        rel="stylesheet"
        href="css/login.css"
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


        <!-- ================= LOGIN ================= -->

        <div class="col-md-8 login-section">

            <div class="login-content">

                <h1>Welcome Back!</h1>

                <p class="subtitle">Login to your account</p>


                <!-- SUCCESS MESSAGE (setelah register) -->

                <?php if (!empty($success)): ?>

                    <div class="alert alert-success">
                        <?= htmlspecialchars($success) ?>
                    </div>

                <?php endif; ?>


                <!-- ERROR MESSAGE -->

                <?php if (!empty($error)): ?>

                    <div class="alert alert-danger">
                        <?= htmlspecialchars($error) ?>
                    </div>

                <?php endif; ?>


                <!-- LOGIN FORM -->

                <form
                    method="POST"
                    action="login.php"
                    autocomplete="on"
                >

                    <!-- CSRF TOKEN -->
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
                    >


                    <!-- EMAIL -->

                    <div class="input-group-custom">

                        <span class="input-icon">👤</span>

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


                    <!-- PASSWORD -->

                    <div class="input-group-custom">

                        <span class="input-icon">🔒</span>

                        <input
                            type="password"
                            name="password"
                            class="form-control"
                            placeholder="Password"
                            autocomplete="current-password"
                            required
                        >

                    </div>


                    <!-- LOGIN BUTTON -->

                    <button
                        type="submit"
                        class="login-btn"
                    >
                        Login
                    </button>


                    <!-- OR -->

                    <div class="divider">
                        <span></span>
                        <p>or</p>
                        <span></span>
                    </div>


                    <!-- REGISTER -->

                    <p class="register-text">

                        Don't have an account?

                        <a href="regist.php">Register</a>

                    </p>

                </form>

            </div>

        </div>

    </div>

</div>

</body>

</html>