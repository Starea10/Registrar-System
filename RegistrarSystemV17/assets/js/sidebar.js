/**
 * sidebar.js
 *
 * Shared mobile sidebar (drawer) behavior for all admin pages.
 * The hamburger button calls toggleSidebar() and the overlay calls
 * closeSidebar() via inline onclick attributes, so both functions must
 * remain in the global scope.
 */

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    const toggle = document.querySelector('.mobile-menu-toggle');

    if (sidebar && overlay) {
        const isOpen = sidebar.classList.toggle('show');
        overlay.classList.toggle('show', isOpen);
        document.body.classList.toggle('sidebar-open', isOpen);
        if (toggle) {
            toggle.classList.toggle('active', isOpen);
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }
    }
}

function closeSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    const toggle = document.querySelector('.mobile-menu-toggle');

    if (sidebar && overlay) {
        sidebar.classList.remove('show');
        overlay.classList.remove('show');
        document.body.classList.remove('sidebar-open');
        if (toggle) {
            toggle.classList.remove('active');
            toggle.setAttribute('aria-expanded', 'false');
        }
    }
}

function initSidebar() {
    // Close the drawer when navigating away via a sidebar link on mobile.
    const sidebar = document.getElementById('sidebar');
    if (sidebar) {
        sidebar.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 768) {
                    closeSidebar();
                }
            });
        });
    }

    // Close the drawer with Escape.
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeSidebar();
        }
    });

    // If the viewport grows past the mobile breakpoint while the drawer is
    // open, reset everything back to the desktop state.
    window.addEventListener('resize', () => {
        if (window.innerWidth >= 768) {
            closeSidebar();
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSidebar);
} else {
    initSidebar();
}
