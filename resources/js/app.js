//

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';
import './live';

// <details data-dropdown> menus (header "Labs"): close on an outside click and on Escape.
document.addEventListener('click', (event) => {
    document.querySelectorAll('details[data-dropdown][open]').forEach((menu) => {
        if (!menu.contains(event.target)) menu.open = false;
    });
});
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    document.querySelectorAll('details[data-dropdown][open]').forEach((menu) => {
        menu.open = false;
        menu.querySelector('summary').focus();
    });
});
