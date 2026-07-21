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
    const statusFilter = document.querySelector('select[name="status"]');
    const tableBody = document.getElementById('requestsTableBody');
    const paginationInfo = document.getElementById('paginationInfo');
    const paginationNav = document.getElementById('paginationNav');

    const query = searchInput.value;
    const status = statusFilter.value;
    const params = new URLSearchParams(window.location.search);
    const view = params.get('view') || '';

    const fetchUrl = `?ajax=1&search=${encodeURIComponent(query)}&status=${encodeURIComponent(status)}&view=${encodeURIComponent(view)}`;

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
 * Handle AJAX status updates
 */
async function handleStatusUpdate(select) {
    const form = select.closest('form');
    const originalStatus = select.dataset.originalStatus;

    // Confirm if marking as released
    if (select.value === 'released') {
        if (!confirm('Marking this request as "Released" will automatically archive it. Are you sure?')) {
            select.value = originalStatus; // Revert on cancel
            return;
        }
    }

    const formData = new FormData(form);
    formData.append('is_ajax', '1');

    try {
        const response = await fetch('requests.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.status === 'success') {
            showAlert(data.message, 'success');
            
            if (data.action === 'remove_row') {
                const row = select.closest('tr');
                row.style.transition = 'opacity 0.5s ease';
                row.style.opacity = '0';
                setTimeout(() => {
                    row.remove();
                }, 500);
            } else {
                // Update color class
                select.className = select.className.replace(/status-\w+/g, '');
                select.classList.add('status-' + select.value);
                // Update the original status so reverting works correctly next time
                select.dataset.originalStatus = select.value;
            }
        } else {
            showAlert(data.message || 'An unknown error occurred.', 'danger');
            select.value = originalStatus; // Revert on error
        }
    } catch (error) {
        console.error('Status update error:', error);
        console.log(error);
        showAlert('A network error occurred. Please try again.', 'danger');
        select.value = originalStatus; // Revert on network error
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

/**
 * Initialize all event listeners and functionality
 */
function initializeRequestSystem() {
    // Search functionality
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.querySelector('select[name="status"]');

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(performSearch, 300);
        });
    }

    if (statusFilter) {
        statusFilter.addEventListener('change', performSearch);
    }

    // Status update functionality
    const tableBody = document.getElementById('requestsTableBody');
    if (tableBody) {
        tableBody.addEventListener('change', function(e) {
            if (e.target && e.target.classList.contains('status-select')) {
                handleStatusUpdate(e.target);
            }
        });
    }

    // Numerical field restrictions
    const numericalFields = document.querySelectorAll('.numerical-only');
    numericalFields.forEach(field => {
        restrictToNumbers(field);
    });

    // New request form validation
    const newRequestForm = document.querySelector('#newRequestModal form');
    if (newRequestForm) {
        newRequestForm.addEventListener('submit', validateNewRequestForm);
    }

    // Make toggleCertificationInput globally available for onclick events
    window.toggleCertificationInput = toggleCertificationInput;
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', initializeRequestSystem);
