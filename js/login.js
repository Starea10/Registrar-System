/*=====================================
DOM ELEMENTS
=====================================*/
const loginForm = document.getElementById('loginForm');
const email = document.getElementById('email');
const password = document.getElementById('password');
const togglePassword = document.getElementById('togglePassword');
const remember = document.querySelector('.remember input');
const loginButton = document.querySelector('.login-btn');

/*=====================================
PASSWORD TOGGLE
=====================================*/
togglePassword.addEventListener('click', () => {
  if (password.type === 'password') {
    password.type = 'text';
    togglePassword.innerHTML = '🙈';
  } else {
    password.type = 'password';
    togglePassword.innerHTML = '👁';
  }
});

/*=====================================
EMAIL VALIDATION
=====================================*/
function validEmail(emailAddress) {
  const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  return regex.test(emailAddress);
}

/*=====================================
LOGIN VALIDATION
=====================================*/
loginForm.addEventListener('submit', (e) => {
  e.preventDefault();

  if (email.value.trim() === '') {
    alert('Please enter your email.');
    email.focus();
    return;
  }

  if (!validEmail(email.value)) {
    alert('Invalid email address.');
    email.focus();
    return;
  }

  if (password.value.trim() === '') {
    alert('Please enter your password.');
    password.focus();
    return;
  }

  login();
});

/*=====================================
LOGIN PROCESS
=====================================*/
function login() {
  loginButton.disabled = true;
  loginButton.innerHTML = 'Signing In...';

  setTimeout(() => {
    loginButton.innerHTML = 'Success ✓';
    saveRemember();

    /*
    =====================================
    PHP LOGIN HERE

    window.location = "dashboard.php";

    or

    fetch("login.php")

    =====================================
    */

    setTimeout(() => {
      window.location = 'dashboard.html';
    }, 1000);
  }, 1500);
}

/*=====================================
REMEMBER ME
=====================================*/
function saveRemember() {
  if (remember.checked) {
    localStorage.setItem('rememberEmail', email.value);
  } else {
    localStorage.removeItem('rememberEmail');
  }
}

/*=====================================
LOAD SAVED EMAIL
=====================================*/
window.addEventListener('load', () => {
  const saved = localStorage.getItem('rememberEmail');
  if (saved) {
    email.value = saved;
    remember.checked = true;
  }
});

/*=====================================
ENTER KEY SUPPORT
=====================================*/
document.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') {
    loginForm.requestSubmit();
  }
});

/*=====================================
INPUT FOCUS EFFECTS
=====================================*/
document.querySelectorAll('input').forEach(input => {
  input.addEventListener('focus', () => {
    input.parentElement.classList.add('focused');
  });

  input.addEventListener('blur', () => {
    input.parentElement.classList.remove('focused');
  });
});

/*=====================================
PREVENT DOUBLE SUBMIT
=====================================*/
loginButton.addEventListener('dblclick', (e) => {
  e.preventDefault();
});

/*=====================================
TOAST NOTIFICATION
=====================================*/
function toast(message) {
  const t = document.getElementById('toast');
  t.innerHTML = message;
  t.classList.add('show');

  setTimeout(() => {
    t.classList.remove('show');
  }, 3000);
}

//toast('Login Successful!');