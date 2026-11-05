// Live updates shared by all pages. Plain DOM, no framework: elements opt in with
// data-* attributes rendered by Blade.

const echo = window.Echo;

// Header bell (staff): private channel App.Models.User.{id}, notifications with
// the "broadcast" channel (PendingReviewNotification). Echo authorizes the
// subscription with POST /broadcasting/auth (session cookie + CSRF token).
const bell = document.querySelector('[data-bell][data-user-id]');
if (echo && bell) {
    echo.private(`App.Models.User.${bell.dataset.userId}`).notification((notification) => {
        if (notification.kind === 'lab') return; // the lab page shows those itself
        let badge = bell.querySelector('[data-unread]');
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'absolute -right-2 -top-2 rounded-full bg-red-600 px-1.5 text-xs leading-5 text-white';
            badge.dataset.unread = '';
            badge.textContent = '0';
            bell.append(badge);
        }
        badge.textContent = String(Number(badge.textContent) + 1);
    });
}

// Stock badges: public channel "warehouse", event BookStockChanged (broadcastAs
// "stock.changed" => listen with a leading dot).
const badges = document.querySelectorAll('[data-live-stock][data-book-id]');
if (echo && badges.length) {
    echo.channel('warehouse').listen('.stock.changed', (e) => {
        badges.forEach((badge) => {
            if (Number(badge.dataset.bookId) !== e.book_id) return;
            badge.dataset.state = e.state;
            ['bg-red-100', 'text-red-800', 'bg-amber-100', 'text-amber-800', 'bg-emerald-100', 'text-emerald-800']
                .forEach((c) => badge.classList.remove(c));
            const colors = {out: ['bg-red-100', 'text-red-800'], low: ['bg-amber-100', 'text-amber-800'], in: ['bg-emerald-100', 'text-emerald-800']};
            badge.classList.add(...colors[e.state]);
            badge.textContent = e.state === 'out' ? e.label : `${e.label} (${e.available})`;
        });
    });
}
