/*=====================================
DOM ELEMENTS
=====================================*/
const loginForm = document.getElementById('loginForm');
const username = document.getElementById('username');
const password = document.getElementById('password');
const togglePassword = document.getElementById('togglePassword');
const remember = document.querySelector('.remember input');
const loginButton = document.querySelector('.login-btn');

/*=====================================
PASSWORD TOGGLE
=====================================*/
password.addEventListener("input", () => {

    if (password.value.length > 0) {

        togglePassword.classList.add("show-icon");

    } else {

        togglePassword.classList.remove("show-icon");

        password.type = "password";

        togglePassword.innerHTML = "👁";

    }

});

togglePassword.addEventListener("click", () => {

    if (password.type === "password") {

        password.type = "text";
        togglePassword.innerHTML = "🙈";

    } else {

        password.type = "password";
        togglePassword.innerHTML = "👁";

    }

});

/*=====================================
LOGIN VALIDATION
=====================================*/
loginForm.addEventListener("submit", (e) => {

    if (username.value.trim() === "") {
        e.preventDefault();
        alert("Please enter your username.");
        username.focus();
        return;
    }

    if (password.value.trim() === "") {
        e.preventDefault();
        alert("Please enter your password.");
        password.focus();
        return;
    }

    saveRemember();

    loginButton.disabled = true;
    loginButton.innerHTML = "Signing In...";
});

/*=====================================
REMEMBER ME
=====================================*/
function saveRemember() {
    if (remember.checked) {
        localStorage.setItem("rememberUsername", username.value);

    } else {
        localStorage.removeItem("rememberUsername");
    }
}

/*=====================================
LOAD SAVED EMAIL
=====================================*/
window.addEventListener("load", () => {
    const saved = localStorage.getItem("rememberUsername");

    if (saved) {
        username.value = saved;
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