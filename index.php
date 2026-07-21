<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Request Management System - CvSU Naic Campus Registrar Office</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
  * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
  }

  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
    background-image: url('assets/images/university-background.jpg');
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    background-attachment: fixed;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    position: relative;
  }

  body::before {
    content: '';
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, 
      rgba(34, 139, 34, 0.7) 0%, 
      rgba(0, 128, 0, 0.6) 25%, 
      rgba(46, 125, 50, 0.7) 50%,
      rgba(27, 94, 32, 0.8) 75%,
      rgba(0, 100, 0, 0.6) 100%
    );
    backdrop-filter: blur(3px);
    z-index: -1;
  }

  .login-container {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    padding: 48px 40px;
    width: 100%;
    max-width: 420px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2), 
                0 0 0 1px rgba(255, 255, 255, 0.3);
    border: 1px solid rgba(255, 255, 255, 0.2);
    transition: transform 0.3s ease;
    position: relative;
    z-index: 1;
  }

  .login-container:hover {
    transform: translateY(-2px);
  }

  .login-logo {
    text-align: center;
    margin-bottom: 40px;
  }

  .university-logo {
    width: 95px;
    height: 90px;
    margin: 0 auto 20px;
    transition: transform 0.3s ease;
    filter: drop-shadow(0 8px 24px rgba(46, 125, 50, 0.3));
  }

  .university-logo:hover {
    transform: scale(1.05);
  }

  .login-logo h2 {
    font-size: 22px;
    font-weight: 600;
    color: #1a1a1a;
    letter-spacing: -0.5px;
    margin-bottom: 8px;
  }

  .login-logo .subtitle {
    font-size: 14px;
    color: #666;
    font-weight: 500;
    line-height: 1.4;
  }

  .form-group {
    margin-bottom: 24px;
    position: relative;
  }

  .form-label {
    display: block;
    font-size: 14px;
    font-weight: 500;
    color: #374151;
    margin-bottom: 8px;
  }

  .form-control {
    width: 100%;
    padding: 16px 20px;
    font-size: 16px;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    background: #ffffff;
    transition: all 0.3s ease;
    font-family: inherit;
  }

  .form-control:focus {
    outline: none;
    border-color: #2e7d32;
    box-shadow: 0 0 0 4px rgba(46, 125, 50, 0.1);
    transform: translateY(-1px);
  }

  .password-wrapper {
    position: relative;
  }

  .password-wrapper .form-control {
    padding-right: 56px;
  }

  .toggle-password {
    position: absolute;
    right: 18px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    color: #9ca3af;
    font-size: 18px;
    opacity: 0;
    visibility: hidden;
    transition: all 0.2s ease;
    user-select: none;
    padding: 4px;
  }

  .toggle-password.show-icon {
    opacity: 1;
    visibility: visible;
  }

  .toggle-password:hover {
    color: #2e7d32;
  }

  .btn-primary {
    width: 100%;
    padding: 16px;
    font-size: 16px;
    font-weight: 600;
    color: white;
    background: linear-gradient(135deg, #2e7d32, #388e3c);
    border: none;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.3s ease;
    margin-top: 8px;
    box-shadow: 0 4px 16px rgba(46, 125, 50, 0.4);
    text-decoration: none;
    display: inline-block;
    text-align: center;
  }

  .btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(46, 125, 50, 0.5);
  }

  .btn-primary:active {
    transform: translateY(0);
  }

  .btn-secondary {
    width: 100%;
    padding: 16px;
    font-size: 16px;
    font-weight: 600;
    color: #2e7d32;
    background: rgba(46, 125, 50, 0.1);
    border: 2px solid #2e7d32;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.3s ease;
    margin-top: 12px;
    text-decoration: none;
    display: inline-block;
    text-align: center;
    box-shadow: 0 2px 8px rgba(46, 125, 50, 0.2);
  }

  .btn-secondary:hover {
    background: #2e7d32;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(46, 125, 50, 0.4);
  }

  .btn-secondary:active {
    transform: translateY(0);
  }

  .button-divider {
    display: flex;
    align-items: center;
    margin: 20px 0;
    color: #9ca3af;
    font-size: 14px;
    font-weight: 500;
  }

  .button-divider::before,
  .button-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: #e5e7eb;
  }

  .button-divider span {
    padding: 0 16px;
  }

  .alert {
    padding: 16px 20px;
    border-radius: 12px;
    margin-top: 20px;
    font-size: 14px;
    font-weight: 500;
  }

  .alert-danger {
    background: rgba(239, 68, 68, 0.1);
    color: #dc2626;
    border: 1px solid rgba(239, 68, 68, 0.2);
  }

  .alert-success {
    background: rgba(34, 197, 94, 0.1);
    color: #16a34a;
    border: 1px solid rgba(34, 197, 94, 0.2);
  }

  .signup-link {
    text-align: center;
    margin-top: 32px;
    padding-top: 24px;
    border-top: 1px solid #e5e7eb;
  }

  .signup-link a {
    color: #2e7d32;
    text-decoration: none;
    font-weight: 500;
    font-size: 14px;
    transition: color 0.2s ease;
  }

  .signup-link a:hover {
    color: #1b5e20;
  }

  @media (max-width: 480px) {
    body {
      padding: 16px;
    }
    
    .login-container {
      padding: 32px 24px;
    }
    
    .university-logo {
      width: 70px;
      height: 70px;
    }
    
    .login-logo h2 {
      font-size: 18px;
    }

    .login-logo .subtitle {
      font-size: 13px;
    }
  }

  /* Smooth page load animation */
  .login-container {
    animation: fadeInUp 0.6s ease-out;
  }

  @keyframes fadeInUp {
    from {
      opacity: 0;
      transform: translateY(30px);
    }
    to {
      opacity: 1;
      transform: translateY(0);
    }
  }
</style>
</head>
<body>
  <div class="login-container">
    <div class="login-logo">
      <img src="assets/images/cvsu-logo-diamond.png" 
           alt="CvSU Naic Campus Registrar Office Logo" 
           class="university-logo">
      
      <div class="subtitle">Cavite State University Naic<br>Office of the Campus Registrar</div>
    </div>
      <div style="text-align: center;">
        <h2>Request Management System</h2>
      </div>
      
      <a href="client_view.php" class="btn-primary">
        <i class="fas fa-eye me-2"></i>View Public Request Tracker
      </a>

      <div class="button-divider">
        <span>or</span>
      </div>

      <form action="includes/login.php" method="POST">
      <div class="form-group">
        <label for="username" class="form-label">Username</label>
        <input id="username" name="username" type="text" class="form-control" required>
      </div>

      <div class="form-group">
        <label for="password" class="form-label">Password</label>
        <div class="password-wrapper">
          <input id="password" name="password" type="password" class="form-control" required>
          <i id="togglePassword" class="fas fa-eye toggle-password" aria-hidden="true"></i>
        </div>
      </div>

      <button type="submit" class="btn-secondary">
        <i class="fas fa-sign-in-alt me-2"></i>Sign In
      </button>

      

      <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger">Invalid username or password</div>
      <?php endif; ?>

      <?php if (isset($_GET['signup']) && $_GET['signup'] === 'success'): ?>
        <div class="alert alert-success">Account created successfully. You can now login.</div>
      <?php endif; ?>



<script>
  document.addEventListener('DOMContentLoaded', () => {
    const password = document.getElementById('password');
    const toggle = document.getElementById('togglePassword');

    // Show/hide toggle icon based on input
    password.addEventListener('input', () => {
      if (password.value.length > 0) {
        toggle.classList.add('show-icon');
      } else {
        toggle.classList.remove('show-icon');
        // Reset to password type when cleared
        if (password.type !== 'password') {
          password.type = 'password';
          toggle.classList.remove('fa-eye-slash');
          toggle.classList.add('fa-eye');
        }
      }
    });

    // Toggle password visibility
    toggle.addEventListener('click', () => {
      const type = password.type === 'password' ? 'text' : 'password';
      password.type = type;
      
      toggle.classList.toggle('fa-eye');
      toggle.classList.toggle('fa-eye-slash');
    });
  });
</script>
</body>
</html>