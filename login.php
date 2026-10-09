<?php

session_start();

require_once "config/database.php";


/* =====================================================
   VARIABLE
===================================================== */

$error = "";


/* =====================================================
   JIKA USER SUDAH LOGIN
===================================================== */

if (isset($_SESSION['user_id'])) {

    if ($_SESSION['role'] === 'customer') {

        header("Location: customer/dashboard.php");
        exit;

    }

    if ($_SESSION['role'] === 'staff') {

        header("Location: staff/dashboard.php");
        exit;

    }

}


/* =====================================================
   PROSES LOGIN
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    /* =================================================
       AMBIL DATA FORM
    ================================================= */

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';


    /* =================================================
       VALIDASI
    ================================================= */

    if ($email === '' || $password === '') {

        $error = "Please enter your email and password.";

    } else {


        /* =================================================
           CARI USER + CEK APAKAH DIA STAFF
        ================================================= */

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

            $error = "Database error: " . $conn->error;

        } else {


            $stmt->bind_param(
                "s",
                $email
            );


            $stmt->execute();


            $result = $stmt->get_result();


            /* =============================================
               USER DITEMUKAN
            ============================================= */

            if ($result->num_rows === 1) {


                $user = $result->fetch_assoc();


                /* =========================================
                   CEK PASSWORD
                   
                   PASSWORD DATABASE KAMU SAAT INI
                   MASIH PLAIN TEXT
                ========================================= */

                if ($password === $user['password']) {


                    /* =====================================
                       REGENERATE SESSION
                    ===================================== */

                    session_regenerate_id(true);


                    /* =====================================
                       TENTUKAN ROLE
                    ===================================== */

                    if (!empty($user['staff_id'])) {

                        // User adalah STAFF
                        $system_role = 'staff';

                    } else {

                        // User bukan staff = CUSTOMER
                        $system_role = 'customer';

                    }


                    /* =====================================
                       SIMPAN SESSION
                    ===================================== */

                    $_SESSION['user_id'] =
                        $user['user_id'];

                    $_SESSION['name'] =
                        $user['name'];

                    $_SESSION['email'] =
                        $user['email'];

                    $_SESSION['role'] =
                        $system_role;


                    /* =====================================
                       DATA STAFF
                    ===================================== */

                    if ($system_role === 'staff') {

                        $_SESSION['staff_id'] =
                            $user['staff_id'];

                        $_SESSION['position'] =
                            $user['position'];

                        $_SESSION['staff_role'] =
                            $user['staff_role'];

                    }


                    /* =====================================
                       REDIRECT
                    ===================================== */

                    if ($system_role === 'customer') {

                        header(
                            "Location: customer/dashboard.php"
                        );

                        exit;

                    }


                    if ($system_role === 'staff') {

                        header(
                            "Location: staff/dashboard.php"
                        );

                        exit;

                    }


                } else {

                    $error =
                        "Incorrect email or password.";

                }


            } else {

                $error =
                    "Incorrect email or password.";

            }


            $stmt->close();

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

    <title>
        My Website
    </title>


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


        <!-- =================================================
             POSTER
        ================================================== -->

        <div class="col-md-4 poster-section">

            <img
                src="assets/Group 177.png"
                alt="Luma Coffee"
            >

        </div>



        <!-- =================================================
             LOGIN
        ================================================== -->

        <div class="col-md-8 login-section">


            <div class="login-content">


                <h1>
                    Welcome Back!
                </h1>


                <p class="subtitle">
                    Login to your account
                </p>



                <!-- =================================================
                     ERROR MESSAGE
                ================================================== -->

                <?php if (!empty($error)): ?>

                    <div class="alert alert-danger">

                        <?= htmlspecialchars($error) ?>

                    </div>

                <?php endif; ?>



                <!-- =================================================
                     LOGIN FORM
                ================================================== -->

                <form
                    method="POST"
                    action="login.php"
                >


                    <!-- EMAIL -->

                    <div class="input-group-custom">

                        <span class="input-icon">
                            👤
                        </span>


                        <input
                            type="email"
                            name="email"
                            class="form-control"
                            placeholder="Email"
                            value="<?= htmlspecialchars(
                                $_POST['email'] ?? ''
                            ) ?>"
                            autocomplete="email"
                            required
                        >

                    </div>



                    <!-- PASSWORD -->

                    <div class="input-group-custom">

                        <span class="input-icon">
                            🔒
                        </span>


                        <input
                            type="password"
                            name="password"
                            class="form-control"
                            placeholder="Password"
                            autocomplete="current-password"
                            required
                        >

                    </div>



                    <!-- REMEMBER ME -->

                    <div class="remember">

                        <input
                            type="checkbox"
                            id="remember"
                            name="remember"
                        >


                        <label for="remember">

                            Remember me

                        </label>

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

                        <p>
                            or
                        </p>

                        <span></span>

                    </div>



                    <!-- REGISTER -->

                    <p class="register-text">

                        Don't have an account?

                        <a href="#">
                            Register
                        </a>

                    </p>


                </form>


            </div>

        </div>


    </div>

</div>



<!-- Bootstrap JavaScript -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js">
</script>


</body>

</html>