/**
 * Request Management System - JavaScript Module
 * Handles dynamic search, status updates, form validation, and UI interactions
 */

// Global variables
let debounceTimer;

/**
 * Display dynamic alert messages
 * @param {string} message - The message to display
 * @param {string} type - Alert type (success, danger, warning, info)
 */
function showAlert(message, type = 'success') {
    const alertsContainer = document.getElementById('ajax-alerts-container');
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} alert-dismissible fade show`;
    alert.role = 'alert';
    alert.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;
    alertsContainer.prepend(alert);
    
    // Automatically remove the alert after 5 seconds
    setTimeout(() => {
        new bootstrap.Alert(alert).close();
    }, 5000);
}

/**
 * Perform AJAX search with current filters
 */
async function performSearch() {
    const searchInput = document.getElementById('searchInput');
    const tableBody = document.getElementById('requestsTableBody');
    const paginationInfo = document.getElementById('paginationInfo');
    const paginationNav = document.getElementById('paginationNav');

    const query = searchInput.value;
    const params = new URLSearchParams(window.location.search);
    const view = params.get('view') || '';

    const fetchUrl = `?ajax=1&search=${encodeURIComponent(query)}&view=${encodeURIComponent(view)}`;

    try {
        const response = await fetch(fetchUrl);
        const data = await response.json();

        tableBody.innerHTML = data.html;
        if (paginationInfo) {
            paginationInfo.innerHTML = data.info;
        }
        if (paginationNav) {
            paginationNav.style.display = query ? 'none' : 'block';
        }
    } catch (error) {
        console.error('Error during live search:', error);
        tableBody.innerHTML = '<tr><td colspan="10" class="text-center text-danger">Error loading results.</td></tr>';
    }
}


/**
 * Restrict input field to numbers only
 * @param {HTMLInputElement} input - The input element to restrict
 */
function restrictToNumbers(input) {
    input.addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '');
    });
}

/**
 * Toggle certification input field visibility
 */
function toggleCertificationInput() {
    const checkbox = document.getElementById('certification');
    const input = document.getElementById('certification_type');
    
    const quantityId = `certification_quantity`;
    const dateId = `certification_claiming_date`;

    const quantity = document.getElementById(quantityId);
    const date = document.getElementById(dateId);

    if (checkbox && input) {
        if (checkbox.checked) {
            input.style.display = 'block';
            input.required = true;

            quantity.disabled = false;
            date.disabled = false;

            quantity.required = true;
            date.required = true;

            quantity.value = 1;
        } else {
            input.style.display = 'none';
            input.required = false;
            input.value = '';
            
            quantity.disabled = true;
            date.disabled = true;

            quantity.required = false;
            date.required = false;

            quantity.value = '';
            date.value = '';

        }
    }
}

function toggleProgramOthersInput(){
    const program = document.getElementById('program');
    const input = document.getElementById('others_program');

    if (program.value === "others" && input) {
            input.style.display = 'block';
            input.required = true;
    } else {
            input.style.display = 'none';
            input.required = false;
            input.value = '';

    }
}

function togglePurposeOthersInput() {
    const purpose = document.getElementById('purpose');
    const input = document.getElementById('others_purpose');
    

    if (purpose.value === "Others" && input) {
        
            input.style.display = 'block';
            input.required = true;
    } else {
            input.style.display = 'none';
            input.required = false;
            input.value = '';

    }
    
}

// Clickable table rows on the request slip form
document.querySelectorAll('.clickable-row').forEach(row => {
  row.addEventListener('click', function(event) {
    // Find the checkbox inside this row
    const checkbox = this.querySelector('.row-checkbox');
    
    // Prevent double-toggle if the user clicked the checkbox directly
    if (event.target !== checkbox) {
      checkbox.checked = !checkbox.checked;
    }
})
})
/**
 * Toggle others input field visibility
 */
function toggleOthersInput() {
    const checkbox = document.getElementById('others');
    const input = document.getElementById('others_type');
    
    const quantityId = `others_quantity`;
    const dateId = `others_claiming_date`;

    const quantity = document.getElementById(quantityId);
    const date = document.getElementById(dateId);


    if (checkbox && input) {
        if (checkbox.checked) {
            input.style.display = 'block';
            input.required = true;

            quantity.disabled = false;
            date.disabled = false;

            quantity.required = true;
            date.required = true;

            quantity.value = 1;
        } else {
            input.style.display = 'none';
            input.required = false;
            input.value = '';

            quantity.disabled = true;
            date.disabled = true;

            quantity.required = false;
            date.required = false;

            quantity.value = '';
            date.value = '';
        }
    }
}

/**
 * Validate new request form
 * @param {Event} e - Form submit event
 */
function validateNewRequestForm(e) {
    const form = e.target;
    const checkboxes = form.querySelectorAll('input[type="checkbox"]');
    let isChecked = false;
    
    checkboxes.forEach(function(checkbox) {
        if (checkbox.checked) {
            isChecked = true;
        }
    });
    
    if (!isChecked) {
        e.preventDefault();
        alert('Please select at least one document type.');
        return false;
    }
}

function toggleQuantityAndDateInput(element) {
    const quantityId = `${element}_quantity`;                                    
    const dateId = `${element}_claiming_date`;

    const checkbox = document.getElementById(element);

    const quantity = document.getElementById(quantityId);
    const date = document.getElementById(dateId);

    
    if (checkbox) {
        if (checkbox.checked) {
            quantity.disabled = false;
            date.disabled = false;

            quantity.required = true;
            date.required = true;

            quantity.value = 1;
        } else {
            quantity.disabled = true;
            date.disabled = true;

            quantity.required = false;
            date.required = false;

            quantity.value = '';
            date.value = '';
        }
    }
}

function toggleContactInput(){

    const contact_choice = document.getElementById('contact_choice');
    const input = document.getElementById('contact');


    if (contact_choice.value === 'email' && input) {
        input.placeholder = 'Enter email address';
        input.title = 'Please enter a valid email address (e.g., user@example.com)';
        input.removeAttribute('pattern');
        input.inputMode = 'email';
    }
    else{
        input.placeholder = 'Enter contact number';
        input.title = 'Please enter numbers only';
        input.pattern = '[0-9]*';
        input.inputMode = 'numeric';
    }
}
/**
 * Initialize all event listeners and functionality
 */
function initializeRequestSystem() {
    // Search functionality
    const searchInput = document.getElementById('searchInput');

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(performSearch, 300);
        });
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', initializeRequestSystem);
