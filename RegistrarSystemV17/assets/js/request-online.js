/*=====================================
VARIABLES
=====================================*/
const form = document.getElementById('requestForm');
const steps = document.querySelectorAll('.form-step');
const indicators = document.querySelectorAll('.step');
const nextBtns = document.querySelectorAll('.next');
const prevBtns = document.querySelectorAll('.previous');
const summary = document.querySelector('.summary');
// The confirmation-step "I certify..." checkbox -- selected by id, not by
// its shared .checkbox class, since that class is reused on three other
// checkboxes inside the TOR section (board exam / transferee / previous-
// request-confirmed), which all come earlier in the document. A plain
// class selector here would silently grab the first of those instead.
const checkbox = document.getElementById('certifyCheckbox');

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
// Radio groups that need their own visible error text when required and
// unpicked, since plain radio <input>s don't show the .error/.success
// classes the way text inputs do (no border to turn red). Keyed by the
// group's `name` attribute -> id of the message element to show/hide.
const RADIO_GROUP_ERROR_MESSAGES = {
  tor_request_type: 'torRequestTypeError'
};

function validateStep(step) {
  const inputs = steps[step].querySelectorAll('input, select, textarea');
  let valid = true;
  const checkedRadioGroups = new Set();

  inputs.forEach(input => {
    if (input.type === 'button') return;

    if (input.type === 'checkbox') {
      const checkboxRequired = input.required || input.dataset.required === '1';
      if (!checkboxRequired || input.offsetParent === null) return;
      input.classList.toggle('error', !input.checked);
      input.classList.toggle('success', input.checked);
      if (!input.checked) valid = false;
      return;
    }

    // Radios can't go through the blank-value test below: an unchecked
    // radio still reports its value attribute, so every required radio
    // would look filled in. Validate the group as a whole instead, once.
    if (input.type === 'radio') {
      const radioRequired = input.required || input.dataset.required === '1';
      if (!radioRequired || input.offsetParent === null) return;
      if (checkedRadioGroups.has(input.name)) return;
      checkedRadioGroups.add(input.name);

      const group = steps[step].querySelectorAll(`input[type="radio"][name="${input.name}"]`);
      const anyChecked = Array.from(group).some(radio => radio.checked);
      group.forEach(radio => {
        radio.classList.toggle('error', !anyChecked);
        radio.classList.toggle('success', anyChecked);
      });

      const errorMessageId = RADIO_GROUP_ERROR_MESSAGES[input.name];
      const errorMessageEl = errorMessageId ? document.getElementById(errorMessageId) : null;
      if (errorMessageEl) {
        errorMessageEl.style.display = anyChecked ? 'none' : 'block';
        if (!anyChecked) {
          errorMessageEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      }

      if (!anyChecked) valid = false;
      return;
    }

    // Only fields marked required (see request-online.php) can block
    // moving on -- this keeps optional fields like Notes from being
    // treated as mandatory, and skips fields that are currently hidden
    // (e.g. the "specify your course" box, which is only required once
    // "Others" is picked and the box is shown).
    if (!input.required) return;
    if (input.offsetParent === null) return;

    if (input.value.trim() === '') {
      input.classList.add('error');
      input.classList.remove('success');
      valid = false;
    } else if (input.name === 'Contact Number' && !/[0-9]/.test(input.value)) {
      input.classList.add('error');
      input.classList.remove('success');
      valid = false;
    } else {
      input.classList.remove('error');
      input.classList.add('success');
    }
  });

  return valid;
}

function generateSummary() {
    const data = new FormData(form);
    let html = "";

    // Convert FormData to object
    const values = {};
    data.forEach((value, key) => {
        values[key] = value;
    });

    // If Program is Others, replace it with Course input
    if (
        values["Program"] &&
        values["Program"].toLowerCase() === "others" &&
        values["Course"]
    ) {
        values["Program"] = values["Course"];
    }

    // If Document is Others, replace it with specified document
    if (
        values["Document"] &&
        values["Document"].toLowerCase() === "others" &&
        values["others_type"]
    ) {
        values["Document"] = values["others_type"];
    }

    // If Purpose is Others, replace it with specified purpose
    if (
        values["Purpose"] &&
        values["Purpose"].toLowerCase() === "others" &&
        values["others_purpose"]
    ) {
        values["Purpose"] = values["others_purpose"];
    }

    // Fields that should never appear in summary
    const hiddenFields = [
        "Course",
        "others_type",
        "others_purpose",
        "csrf_token",
        "website",
        "tor_previous_confirmed",
        "tor_board_exam",
        "tor_transferee"
    ];

    for (const key in values) {
        if (hiddenFields.includes(key)) continue;

        // File inputs serialize as File objects under multipart forms --
        // print the chosen filename, not "[object File]".
        let displayValue = values[key];
        if (displayValue instanceof File) {
            if (displayValue.name === '') continue; // no file chosen
            displayValue = displayValue.name;
        }

        html += `
            <p>
                <strong>${key}</strong><br>
                ${displayValue}
            </p>
        `;
    }

    summary.innerHTML = html;
}

/*=====================================
SUBMIT REQUEST
=====================================*/
function submitRequest() {
  const button = document.querySelector('.submit');
  const originalLabel = button.innerHTML;
  button.disabled = true;
  button.innerHTML = 'Submitting...';

  const formData = new FormData(form);

  fetch('submit-online-request.php', {
    method: 'POST',
    body: formData
  })
    .then(async response => {
      const raw = await response.text();
      try {
        return JSON.parse(raw);
      } catch (parseError) {
        // The server didn't send back JSON -- almost always a PHP error
        // (wrong path to submit-online-request.php, a fatal error in it,
        // etc). Logging the raw response here is what actually shows you
        // the PHP error text, instead of just "network error".
        console.error('submit-online-request.php did not return valid JSON. Raw response:', raw);
        throw new Error('The server returned an unexpected response.');
      }
    })
    .then(data => {
      if (data.status === 'success') {
        // Only clear the saved draft once the request has actually been
        // recorded -- previously this ran unconditionally, so a failed
        // or offline submission still wiped the student's typed-in data.
        clearStorage();
        form.innerHTML = `
          <div class="success-card">
            <div class="success-icon">✓</div>
            <h2>Request Submitted</h2>
            <p>Your request has been successfully submitted. A confirmation email will be sent shortly.</p>
            ${data.reference ? `<div style="margin: 20px 0; padding: 16px; background: var(--background); border-radius: 10px;">
              <span style="display: block; font-size: 0.8rem; color: var(--gray); text-transform: uppercase; font-weight: 700;">Tracking Reference</span>
              <div style="display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: 12px; margin-top: 4px;">
                <strong style="color: var(--primary); font-size: 1.35rem; font-weight: 800;">${data.reference}</strong>
                <button type="button" class="btn-primary" data-tracking-code="${data.reference}" onclick="copyTrackingCode(this)" style="background: var(--secondary); color: var(--text); padding: 10px 16px; white-space: nowrap;">Copy</button>
              </div>
            </div><p>Save this code and enter it on the <a href="track.php">Request Tracker</a> to view your status.</p>` : ''}
            ${data.reminder ? `<p class="success-reminder"><em>${data.reminder}</em></p>` : ''}
          </div>
        `;
      } else {
        alert(data.message || 'Something went wrong while submitting your request. Please try again.');
        button.disabled = false;
        button.innerHTML = originalLabel;
      }
    })
    .catch(error => {
      console.error('Request submission error:', error);
      alert('A network error occurred. Please check your connection and try again.');
      button.disabled = false;
      button.innerHTML = originalLabel;
    });
}

async function copyTrackingCode(button) {
  const code = button.dataset.trackingCode;
  const originalLabel = button.textContent;

  try {
    await navigator.clipboard.writeText(code);
  } catch (error) {
    const fallbackInput = document.createElement('input');
    fallbackInput.value = code;
    document.body.appendChild(fallbackInput);
    fallbackInput.select();
    document.execCommand('copy');
    fallbackInput.remove();
  }

  button.textContent = 'Copied';
  setTimeout(() => {
    button.textContent = originalLabel;
  }, 1500);
}

/*=====================================
CLEAR STORAGE
=====================================*/
function clearStorage() {
  const fields = document.querySelectorAll('input:not([type="hidden"]):not([type="file"]), select, textarea');
  fields.forEach(field => {
    localStorage.removeItem(field.name);
  });
}

/*=====================================
NEXT BUTTON HANDLER
=====================================*/
nextBtns.forEach(btn => {
  btn.addEventListener('click', () => {
    if (!validateStep(currentStep)) {
      alert('Please complete all required fields.');
      return;
    }

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
// Excludes hidden inputs (the CSRF token) -- see the note above the
// auto-save block below for why hidden fields can't go through this.
document.querySelectorAll('input:not([type="hidden"]):not([type="file"]), select, textarea').forEach(input => {
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
// Hidden inputs are excluded here: the CSRF token field is rendered by
// PHP with a fresh value on every page load, and this loop runs
// `field.value = localStorage.getItem(field.name) || ''` on load -- if
// the token field were included, a fresh visit (nothing in localStorage
// yet) would immediately blank it out to '', and every submission would
// fail the CSRF check server-side.
// File inputs are also excluded: a File can't be meaningfully stored in
// localStorage, and browsers block assigning .value on type=file, which
// would throw and break the whole restore loop.
// Radios are excluded too: this loop keys purely off `field.name`, which
// works for a text/select field (one element per name) but breaks for a
// radio GROUP, where every option in the group shares one name. Every
// radio in the group would get `.value` forced to the same single stored
// string (or '' the first time), overwriting their real "first_time" /
// "previous" values -- which then never matches what updateTorSubsections()
// checks for, so the file-upload sections it controls never appear no
// matter which option is picked. Restoring a draft radio choice isn't
// worth the corruption; simplest fix is to leave radios alone entirely.
// Checkboxes are excluded too: `field.value = ''` on a checkbox overwrites its
// value attribute ("1" -> ""), so a ticked box was submitted as an empty string
// and the server treated it as unchecked (e.g. "I've requested a TOR before").
const fields = document.querySelectorAll('input:not([type="hidden"]):not([type="file"]):not([type="radio"]):not([type="checkbox"]), select, textarea');

fields.forEach(field => {
  // Only restore a value that was actually saved. Assigning '' unconditionally
  // blanked every <select> that has no empty option (Document, Purpose), which
  // is why those dropdowns showed up empty on a fresh visit.
  const saved = localStorage.getItem(field.name);
  const isRestorable = saved !== null &&
    (field.tagName !== 'SELECT' || Array.from(field.options).some(o => o.value === saved));
  if (isRestorable) field.value = saved;

  field.addEventListener('input', () => {
    localStorage.setItem(field.name, field.value);
  });
});

/*=====================================
TEXT AREA CHARACTER COUNTER
=====================================*/
// Was `document.querySelector('textarea')`, which grabs the FIRST textarea
// in the form (the "others_purpose" box) rather than the intended "Notes"
// field, since "others_purpose" appears earlier in the markup.
const notes = document.querySelector('textarea[name="Notes"]');
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
      // Refresh the requirements sections for the pre-selected type.
      docSelect.dispatchEvent(new Event('change'));
    }
  }
});

const documentType = document.getElementById("documentType");
addFileClearButtons();

/*=====================================
 DYNAMIC DOCUMENT REQUIREMENTS
 =====================================*/
// Show/hide the per-document upload sections added in request-online.php.
// Hidden sections have every input disabled (so they don't submit) and
// not required (so validateStep() ignores them) -- same on/off pattern
// as the otherDocumentGroup toggle below.
const docSections = document.querySelectorAll('#documentRequirements .doc-requirements');

function syncUploadClearButtons() {
  document.querySelectorAll('input[type="file"]').forEach(input => {
    const clearButton = input.nextElementSibling;
    if (clearButton && clearButton.classList.contains('clear-upload')) {
      clearButton.style.display = input.disabled || !input.value ? 'none' : 'inline-block';
    }
  });
}

function addFileClearButtons() {
  document.querySelectorAll('input[type="file"]').forEach(input => {
    const clearButton = document.createElement('button');
    clearButton.type = 'button';
    clearButton.textContent = 'Clear';
    clearButton.className = 'clear-upload';
    clearButton.style.cssText = 'display: none; margin-top: 6px; padding: 6px 12px; border: 1px solid #dc3545; border-radius: 6px; background: white; color: #dc3545; cursor: pointer;';
    input.addEventListener('change', syncUploadClearButtons);
    clearButton.addEventListener('click', () => {
      input.value = '';
      input.classList.remove('success');
      input.classList.add('error');
      syncUploadClearButtons();
    });
    input.insertAdjacentElement('afterend', clearButton);
  });
  syncUploadClearButtons();
}

function setGroupState(container, visible) {
    container.style.display = visible ? 'block' : 'none';
    container.querySelectorAll('input, select, textarea').forEach(input => {
        if (visible) {
            input.disabled = false;
            if (input.dataset.required === '1') input.required = true;
        } else {
            input.required = false;
            input.disabled = true;
            if (input.type === 'checkbox' || input.type === 'radio') {
                input.checked = false;
            } else {
                input.value = '';
            }
            input.classList.remove('error', 'success');
        }
    });
}

// Mark which inputs are required-when-visible up front (data-required),
// so setGroupState() can restore the flag when a section reappears.
const REQUIRED_UPLOADS = {
    TOR_first: ['student_id_image', 'clearance_form_image', 'proof_of_graduation_image'],
    TOR_previous: ['student_id_image'],
    COE: ['student_id_image', 'registration_form_image'],
    COG: ['student_id_image'],
    CAV: ['government_id_image', 'original_document_image', 'photocopy_document_image', 'photocopy_document_image_2']
};

const torFirstTimeFields = document.getElementById('torFirstTimeFields');
const torPreviousFields = document.getElementById('torPreviousFields');
const torBoardExam = document.getElementById('torBoardExam');
const torTransferee = document.getElementById('torTransferee');
const torIdPictureGroup = document.getElementById('torIdPictureGroup');
const torHonorableDismissalGroup = document.getElementById('torHonorableDismissalGroup');
const torPreviousConfirmed = document.getElementById('torPreviousConfirmed');

function markRequired(container, names) {
    container.querySelectorAll('input[type="file"]').forEach(input => {
        input.dataset.required = names.includes(input.name) ? '1' : '0';
        input.required = false;
    });
}

function updateTorSubsections() {
    const selected = document.querySelector('input[name="tor_request_type"]:checked');
    const type = selected ? selected.value : null;

    // Clear the "please choose one" message as soon as a choice is made,
    // rather than waiting for the next Next-button click to re-validate.
    if (selected) {
        const torErrorEl = document.getElementById('torRequestTypeError');
        if (torErrorEl) torErrorEl.style.display = 'none';
    }

    // First-time block
    const showFirst = type === 'first_time';
    torFirstTimeFields.style.display = showFirst ? 'block' : 'none';
    torFirstTimeFields.querySelectorAll('input').forEach(i => { i.disabled = !showFirst; });
    if (showFirst) {
        markRequired(torFirstTimeFields, REQUIRED_UPLOADS.TOR_first);
        torFirstTimeFields.querySelectorAll('input[type="file"]').forEach(i => {
            if (i.dataset.required === '1' && i.offsetParent !== null) i.required = true;
        });
    } else {
        torFirstTimeFields.querySelectorAll('input').forEach(i => {
            i.required = false;
            if (i.type === 'checkbox') i.checked = false; else i.value = '';
        });
    }

    // Previous block
    const showPrev = type === 'previous';
    torPreviousFields.style.display = showPrev ? 'block' : 'none';
    torPreviousFields.querySelectorAll('input').forEach(i => { i.disabled = !showPrev; });
    if (showPrev) {
        markRequired(torPreviousFields, REQUIRED_UPLOADS.TOR_previous);
        torPreviousConfirmed.required = false;
        torPreviousFields.querySelectorAll('input[type="file"]').forEach(i => {
            if (i.dataset.required === '1') i.required = true;
        });
    } else {
        torPreviousFields.querySelectorAll('input').forEach(i => {
            i.required = false;
            if (i.type === 'checkbox') i.checked = false; else i.value = '';
        });
    }

    updateTorConditionalFields();
    syncUploadClearButtons();
}

// Board-exam / transferee toggles inside the TOR first-time block.
function updateTorConditionalFields() {
    const firstVisible = torFirstTimeFields.style.display !== 'none';

    const showIdPic = firstVisible && torBoardExam.checked;
    torIdPictureGroup.style.display = showIdPic ? 'block' : 'none';
    const idPicInput = torIdPictureGroup.querySelector('input[type="file"]');
    idPicInput.disabled = !showIdPic;
    idPicInput.required = showIdPic;
    if (!showIdPic) idPicInput.value = '';

    const showHd = firstVisible && torTransferee.checked;
    torHonorableDismissalGroup.style.display = showHd ? 'block' : 'none';
    const hdInput = torHonorableDismissalGroup.querySelector('input[type="file"]');
    hdInput.disabled = !showHd;
    hdInput.required = showHd;
    if (!showHd) hdInput.value = '';
    syncUploadClearButtons();
}

document.querySelectorAll('input[name="tor_request_type"]').forEach(radio => {
    radio.addEventListener('change', updateTorSubsections);
});
torBoardExam.addEventListener('change', updateTorConditionalFields);
torTransferee.addEventListener('change', updateTorConditionalFields);

function updateDocumentRequirements() {
    const doc = documentType.value;
    docSections.forEach(section => {
        const visible = section.dataset.doc === doc;
        setGroupState(section, visible);
        if (visible) {
            if (doc === 'TOR') {
                // TOR's required flags depend on the first-time/previous
                // sub-selection, handled by updateTorSubsections().
                updateTorSubsections();
            } else {
                markRequired(section, REQUIRED_UPLOADS[doc] || []);
                section.querySelectorAll('input[type="file"]').forEach(i => {
                    if (i.dataset.required === '1') i.required = true;
                });
            }
        }
    });
}

documentType.addEventListener("change", function () {
    updateDocumentRequirements();
});

// Initialize on load (the TOR option is selected by default, and the
// ?doc= URL param handler below may pre-select another type).
updateDocumentRequirements();


/////////
const programSelect = document.getElementById("program");
const otherCourseGroup = document.getElementById("otherCourseGroup");
const otherCourseInput = document.getElementById("Course");

programSelect.addEventListener("change", function () {
    if (this.value === "others") {
        otherCourseGroup.style.display = "block";
        otherCourseInput.required = true;
    } else {
        otherCourseGroup.style.display = "none";
        otherCourseInput.required = false;
        otherCourseInput.value = "";
    }
});

const documentSelect = document.getElementById("documentType");
const otherDocumentGroup = document.getElementById("otherDocumentGroup");
const otherDocumentInput = document.getElementById("otherDocument");

documentSelect.addEventListener("change", function () {

    if (this.value === "Others") {
        otherDocumentGroup.style.display = "block";
        otherDocumentInput.required = true;
    } else {
        otherDocumentGroup.style.display = "none";
        otherDocumentInput.required = false;
        otherDocumentInput.value = "";
    }

    // Keep rendering required files
     
});

const purposeSelect = document.querySelector('select[name="Purpose"]');
const otherPurpose = document.getElementById("others_purpose");

purposeSelect.addEventListener("change", function () {

    if (this.value === "Others") {
        otherPurpose.style.display = "block";
        otherPurpose.required = true;
    } else {
        otherPurpose.style.display = "none";
        otherPurpose.required = false;
        otherPurpose.value = "";
    }

});