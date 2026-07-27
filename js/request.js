/*=====================================
VARIABLES
=====================================*/
const form = document.getElementById('requestForm');
const steps = document.querySelectorAll('.form-step');
const indicators = document.querySelectorAll('.step');
const nextBtns = document.querySelectorAll('.next');
const prevBtns = document.querySelectorAll('.previous');
const summary = document.querySelector('.summary');
const checkbox = document.querySelector('.checkbox input');

let currentStep = 0;

/*=====================================
SHOW STEP
=====================================*/
function showStep(index) {
  steps.forEach(step => {
    step.classList.remove('active');
  });

  steps[index].classList.add('active');

  indicators.forEach((step, i) => {
    step.classList.remove('active', 'completed');

    if (i < index) {
      step.classList.add('completed');
    } else if (i === index) {
      step.classList.add('active');
    }
  });
}

/*=====================================
VALIDATE STEP
=====================================*/
// function validateStep(step) {
//   const inputs = steps[step].querySelectorAll('input, select, textarea');
//   let valid = true;

//   inputs.forEach(input => {
//     if (input.type === 'button' || input.type === 'checkbox') return;

//     if (input.value.trim() === '') {
//       input.classList.add('error');
//       input.classList.remove('success');
//       valid = false;
//     } else {
//       input.classList.remove('error');
//       input.classList.add('success');
//     }
//   });

//   return valid;
// }

/*=====================================
GENERATE SUMMARY
=====================================*/
function generateSummary() {
  const data = new FormData(form);
  let html = '';

  data.forEach((value, key) => {
    html += `<p><strong>${key}</strong><br>${value}</p>`;
  });

  summary.innerHTML = html;
}

/*=====================================
SUBMIT REQUEST
=====================================*/
function submitRequest() {
  const button = document.querySelector('.submit');
  button.disabled = true;
  button.innerHTML = 'Submitting...';

  setTimeout(() => {
    form.innerHTML = `
      <div class="success-card">
        <div class="success-icon">✓</div>
        <h2>Request Submitted</h2>
        <p>Your request has been successfully submitted. A confirmation email will be sent shortly.</p>
      </div>
    `;
  }, 1800);
  clearStorage();
}

/*=====================================
CLEAR STORAGE
=====================================*/
function clearStorage() {
  const fields = document.querySelectorAll('input, select, textarea');
  fields.forEach(field => {
    localStorage.removeItem(field.name);
  });
}

/*=====================================
NEXT BUTTON HANDLER
=====================================*/
nextBtns.forEach(btn => {
  btn.addEventListener('click', () => {
    // if (!validateStep(currentStep)) {
    //   alert('Please complete all required fields.');
    //   return;
    // }

    if (currentStep === 1) {
      generateSummary();
    }

    currentStep++;
    showStep(currentStep);
  });
});

/*=====================================
PREVIOUS BUTTON HANDLER
=====================================*/
prevBtns.forEach(btn => {
  btn.addEventListener('click', () => {
    currentStep--;
    showStep(currentStep);
  });
});

/*=====================================
FORM SUBMIT HANDLER
=====================================*/
form.addEventListener('submit', (e) => {
  e.preventDefault();

  if (!checkbox.checked) {
    alert('Please certify the information.');
    return;
  }

  submitRequest();
});

/*=====================================
LIVE VALIDATION
=====================================*/
document.querySelectorAll('input, select, textarea').forEach(input => {
  input.addEventListener('input', () => {
    if (input.value.trim() !== '') {
      input.classList.remove('error');
      input.classList.add('success');
    }
  });
});

/*=====================================
AUTO SAVE TO LOCAL STORAGE
=====================================*/
const fields = document.querySelectorAll('input, select, textarea');

fields.forEach(field => {
  field.value = localStorage.getItem(field.name) || '';

  field.addEventListener('input', () => {
    localStorage.setItem(field.name, field.value);
  });
});

/*=====================================
TEXT AREA CHARACTER COUNTER
=====================================*/
const notes = document.querySelector('textarea');
const counter = document.createElement('small');
notes.parentNode.append(counter);

notes.addEventListener('input', () => {
  counter.innerHTML = `${notes.value.length}/300`;
});

/*=====================================
PREVENT DOUBLE CLICK
=====================================*/
nextBtns.forEach(btn => {
  btn.addEventListener('dblclick', (e) => {
    e.preventDefault();
  });
});

/*=====================================
INITIALIZE
=====================================*/
showStep(currentStep);

// Auto-select document dropdown based on URL search query parameter
document.addEventListener('DOMContentLoaded', () => {
  const urlParams = new URLSearchParams(window.location.search);
  const selectedDoc = urlParams.get('doc');

  if (selectedDoc) {
    const docSelect = document.querySelector('select[name="Document"]');
    if (docSelect) {
      // Find matching option or set value
      for (let option of docSelect.options) {
        if (option.value.toLowerCase() === selectedDoc.toLowerCase() || 
            option.text.toLowerCase().includes(selectedDoc.toLowerCase())) {
          option.selected = true;
          break;
        }
      }
    }
  }
});

