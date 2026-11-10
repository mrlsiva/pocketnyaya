import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Real-time updates (PRD 2.3). Signals carry ids only; the page refreshes to fetch details
// through the normal permission checks. If the Reverb server isn't running, nothing breaks.
const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.getAttribute('content');
const userId = meta('pn-user');

const messages = {
    'matter.assigned': 'You have been added to a matter.',
    'task.assigned': 'A task has been assigned to you.',
    'consultation.message': 'You have a new consultation message.',
    'odr.message': 'There is a new message in one of your disputes.',
    'hearing.scheduled': 'A hearing was added to the diary.',
    'hearing.outcome': 'A hearing outcome was recorded.',
    'diary.conflict': 'A diary clash was detected.',
};

function show(type) {
    const box = document.getElementById('pn-live');
    const text = document.getElementById('pn-live-text');
    if (!box || !text) {
        return;
    }
    text.textContent = messages[type] ?? 'Something was updated.';
    box.classList.remove('d-none');
}

if (userId && import.meta.env.VITE_REVERB_APP_KEY) {
    window.Pusher = Pusher;
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        auth: { headers: { 'X-CSRF-TOKEN': meta('csrf-token') ?? '' } },
    });

    window.Echo.private(`users.${userId}`).listen('.update', (event) => show(event.type));
    const tenantId = meta('pn-tenant');
    if (tenantId) {
        window.Echo.private(`tenants.${tenantId}.diary`).listen('.update', (event) => show(event.type));
    }
}

document.addEventListener('click', (event) => {
    if (event.target instanceof Element && event.target.closest('[data-reload]')) {
        window.location.reload();
    }
});
