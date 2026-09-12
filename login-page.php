<!-- /**
 * Staff login page.
 *
 * Displays the portal login form that submits credentials to the shared
 * authentication handler for session-based sign-in.
 */ -->
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Staff Login | CvSU Naic Registrar</title>
  <link rel="icon" href="assets/images/logo.png" type="image/x-icon">

  <!-- Stylesheets -->
  <link rel="stylesheet" href="assets/css/variables.css">
  <link rel="stylesheet" href="assets/css/login.css">
</head>

<body>
  <div class="login-container">
    <!-- Left Section -->
    <div class="login-left">
      <img src="assets/images/logo.png" alt="CvSU Logo">
      <h1>Registrar Management Portal</h1>
      <p>Welcome back! Sign in to manage document requests, student records, and registrar services.</p>
    </div>

    <!-- Right Section - Login Form -->
    <div class="login-right">
      <div class="login-card">
        <h2>Staff Login</h2>
        <p>Sign in using your registrar credentials.</p>
          <form id="loginForm" action="includes/login.php" method="POST">

              <div class="input-group">
                  <label>Username</label>
                  <input
                      type="text"
                      id="username"
                      name="username"
                      placeholder="Enter your username"
                      required>
              </div>

              <div class="input-group">
                  <label>Password</label>

                  <div class="password-box">
                      <input
                          type="password"
                          id="password"
                          name="password"
                          placeholder="Password"
                          required>

                      <button
                          type="button"
                          id="togglePassword">
                          👁
                      </button>
                  </div>
              </div>

              <div class="remember">
                  <label>
                      <input type="checkbox" id="remember">
                      Remember Me
                  </label> 

                  <!-- <a href="#">Forgot Password?</a> -->
              </div>

              <button class="login-btn" type="submit">
                  Login
              </button>

              <?php if (isset($_GET['error'])): ?>
                  <div class="alert alert-danger">
                      Invalid username or password.
                  </div>
              <?php endif; ?>

              <?php if (isset($_GET['signup']) && $_GET['signup'] === 'success'): ?>
                  <div class="alert alert-success">
                      Account created successfully. You can now login.
                  </div>
              <?php endif; ?>

          </form>
        <a href="index.php" class="back-home">← Back to Homepage</a>
      </div>
    </div>
  </div>

  <div id="toast"></div>
  <script src="assets/js/login.js"></script>
</body>
</html>