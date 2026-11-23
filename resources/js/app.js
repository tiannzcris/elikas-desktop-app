import './bootstrap';

window.ELIKAS = window.ELIKAS || {};

// ---------------------------------------------------------------------
// <time datetime="..." data-local-time> -- server timestamps shown in the
// device's own local time (the server renders UTC), with how long ago it
// was, e.g. the EC Board's "As of". Called on load and again by anything
// that swaps in fresh markup.
// ---------------------------------------------------------------------
function describeAge(ms) {
    const minutes = Math.round(ms / 60000);
    const words = (n, one, many) => `${n.toLocaleString('en-US')} ${n === 1 ? one : many}`;
    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${words(minutes, 'minute', 'minutes')} ago`;
    const hours = Math.round(minutes / 60);
    if (hours < 24) return `${words(hours, 'hour', 'hours')} ago`;
    return `${words(Math.round(hours / 24), 'day', 'days')} ago`;
}

// "Nov 19, 1:44 AM" (the year only when it is not this year), as everywhere in the app.
window.ELIKAS.formatWhen = function formatWhen(when) {
    const options = { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' };
    if (when.getFullYear() !== new Date().getFullYear()) options.year = 'numeric';
    return when.toLocaleString('en-US', options);
};

window.ELIKAS.localizeTimes = function localizeTimes(root = document) {
    root.querySelectorAll('time[data-local-time]').forEach((el) => {
        const when = new Date(el.getAttribute('datetime'));
        if (Number.isNaN(when.getTime())) return;
        el.textContent = window.ELIKAS.formatWhen(when);
        let age = el.nextElementSibling;
        if (!age || !age.classList.contains('local-time-age')) {
            age = document.createElement('span');
            age.className = 'local-time-age';
            el.after(age);
        }
        age.textContent = ` (${describeAge(Date.now() - when.getTime())})`;
    });
};

document.addEventListener('DOMContentLoaded', () => window.ELIKAS.localizeTimes());
// A board left open offline keeps aging -- "(5 minutes ago)" must not freeze.
setInterval(() => window.ELIKAS.localizeTimes(), 60000);

// ---------------------------------------------------------------------
// Connection badge + live clock -- present in the shared layout header
// on every logged-in page.
// ---------------------------------------------------------------------
function updateConnectionBadge() {
    const badge = document.getElementById('connection-badge');
    if (!badge) return;
    if (navigator.onLine) {
        badge.textContent = 'Online';
        badge.className = 'badge badge-success';
    } else {
        badge.textContent = 'Offline';
        badge.className = 'badge badge-neutral';
    }
}

function updateClock() {
    const el = document.getElementById('live-clock');
    if (!el) return;
    const formatted = window.ELIKAS.formatWhen(new Date());
    el.innerHTML = '<i class="ti ti-calendar" style="font-size: 15px;" aria-hidden="true"></i> ' + formatted;
}

updateConnectionBadge();
window.addEventListener('online', updateConnectionBadge);
window.addEventListener('offline', updateConnectionBadge);
updateClock();
setInterval(updateClock, 30000);

// ---------------------------------------------------------------------
// "Sync now" offline guard -- shared by every [data-sync-control] on the
// page (Registered Families' own button, and EC Board's). Reuses the
// SAME navigator.onLine signal as the connection badge above (this
// app's one existing connectivity-detection mechanism -- no new
// detection logic here), disabling the button and showing why, rather
// than letting someone click it while offline and either wait on a
// doomed request or see a confusing error.
// ---------------------------------------------------------------------
function updateSyncButtons() {
    document.querySelectorAll('[data-sync-control]').forEach((control) => {
        const button = control.querySelector('[data-sync-button]');
        const warning = control.querySelector('[data-sync-offline-warning]');
        if (!button) return;
        button.disabled = !navigator.onLine;
        button.classList.toggle('opacity-50', !navigator.onLine);
        button.classList.toggle('cursor-not-allowed', !navigator.onLine);
        if (warning) warning.style.display = navigator.onLine ? 'none' : 'flex';
    });
}

updateSyncButtons();
window.addEventListener('online', updateSyncButtons);
window.addEventListener('offline', updateSyncButtons);

// ---------------------------------------------------------------------
// User menu dropdown
// ---------------------------------------------------------------------
const userMenuBtn = document.getElementById('user-menu-btn');
const userMenu = document.getElementById('user-menu');
if (userMenuBtn && userMenu) {
    userMenuBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        userMenu.classList.toggle('hidden');
    });
    document.addEventListener('click', () => userMenu.classList.add('hidden'));
}

// ---------------------------------------------------------------------
// Page transitions -- fades the content area out just before leaving for
// another page in this app, and back in once the new page loads, so
// navigation doesn't feel like an abrupt full-page reload. Skipped for
// [data-modal-trigger] links, which open an in-page modal instead (see
// below) rather than navigating anywhere.
// ---------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
    requestAnimationFrame(() => document.querySelector('main')?.classList.add('in'));
});
// Not for a form a pop-up sends itself (wireModalSubmit() prevents the
// default): it may come back with a message to show, and fading the
// page then would leave that message, and the page, invisible.
document.addEventListener('submit', (e) => {
    if (e.defaultPrevented) return;
    document.querySelector('main')?.classList.remove('in');
});

// ---------------------------------------------------------------------
// In-page modals (Edit pending entry): fetch the form, inject it over
// there is deliberately only one implementation.
// ---------------------------------------------------------------------
function closeDynamicModal(backdrop) {
    backdrop.remove();
}

function wireModalClose(modalRoot) {
    const closeBtn = modalRoot.querySelector('.modal-close-btn');
    if (!closeBtn) return;
    closeBtn.addEventListener('click', (e) => {
        const dynamicBackdrop = modalRoot.closest('[data-dynamic-modal-backdrop]');
        if (dynamicBackdrop) {
            e.preventDefault();
            closeDynamicModal(dynamicBackdrop);
        }
        // Otherwise this is the full-page fallback (direct navigation to
        // /families/create, no page to return to) -- let the link's
        // default href navigate to the dashboard as normal.
    });
}

/**
 * Confirmed real bug this fixes: after a successful "Add evacuee"
 * submission, the redirect target is almost always the EXACT SAME URL
 * the form was already on (same center+event) -- assigning
 * window.location.href to a URL identical to the current one is a no-op
 * in Chromium (no navigation happens at all), so the page's own DOMContent
 * Loaded handlers (including loadRemoteHouseholds(), see
 * initEcBoardEntryForm() below) never ran again, leaving the just-added
 * household invisible as "existing" until a manual refresh. Forces a real
 * reload whenever the target is the page we're already on, instead of
 * relying on assignment alone to always navigate.
 */
function navigateFreshTo(url) {
    if (url === window.location.href) {
        window.location.reload();
    } else {
        window.location.href = url;
    }
}

function wireModalSubmit(modalRoot) {
    const form = modalRoot.querySelector('form');
    const errorBox = modalRoot.querySelector('.form-errors');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const submitBtn = form.querySelector('button[type="submit"]');
        submitBtn.disabled = true;

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (response.redirected) {
                // Lets the page react to a save that's about to reload it
                // (the EC Board reopens Add evacuee). The redirect's own
                // flash messages were already used up by this fetch.
                modalRoot.dispatchEvent(new CustomEvent('elikas:saved', { bubbles: true }));
                navigateFreshTo(response.url);
                return;
            }

            if (response.status === 422) {
                const data = await response.json();
                const firstError = Object.values(data.errors || {})[0]?.[0] || data.message || 'Please check the form and try again.';
                errorBox.textContent = firstError;
                errorBox.style.display = 'block';
                modalRoot.scrollIntoView({ behavior: 'smooth', block: 'start' });
                return;
            }

            // Unexpected response shape -- fail safe with a real navigation
            // rather than leaving the user stuck on a silently broken form.
            navigateFreshTo(form.action);
        } finally {
            submitBtn.disabled = false;
        }
    });
}

// ---------------------------------------------------------------------
// "Edit pending evacuee entry" modal: fetches the form fragment and
// injects it over the current page. The "Add evacuee" form is embedded
// on the board itself; initEcBoardEntryForm() wires both.
// ---------------------------------------------------------------------
function openEcBoardEntryModal(url) {
    const backdrop = document.createElement('div');
    backdrop.className = 'fixed inset-0 z-40 flex items-start justify-center overflow-y-auto py-10 px-4';
    backdrop.style.background = 'rgba(17, 24, 39, 0.5)';
    backdrop.setAttribute('data-dynamic-modal-backdrop', '');
    backdrop.innerHTML = '<div class="bg-white rounded-xl px-6 py-5 text-sm text-gray-600 mt-10">Loading...</div>';
    document.body.appendChild(backdrop);

    backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) closeDynamicModal(backdrop);
    });
    // Esc closes it, as every dialog (web design system, keyboard use).
    const opener = document.activeElement;
    const onKey = (e) => {
        if (e.key !== 'Escape' || !document.body.contains(backdrop)) return;
        closeDynamicModal(backdrop);
        document.removeEventListener('keydown', onKey);
        opener?.focus?.();
    };
    document.addEventListener('keydown', onKey);

    fetch(url, { headers: { 'X-Modal-Request': '1' }, credentials: 'same-origin' })
        .then((r) => r.text())
        .then((html) => {
            backdrop.innerHTML = html;
            const modalRoot = backdrop.querySelector('[data-ec-board-entry-modal]');
            if (modalRoot) window.ELIKAS.initEcBoardEntryForm(modalRoot);
            // Focus starts on the first field.
            backdrop.querySelector('select:not([disabled]), input:not([type=hidden]):not([disabled])')?.focus();
        })
        .catch(() => {
            backdrop.innerHTML = '<div class="bg-white rounded-xl p-6 text-sm text-red-700 mt-10">Could not load the form. Please try again.</div>';
        });
}

/**
 * Wires an EC Board entry form (see _entry_fields.blade.php): the household
 * mode toggle, the household-head questions, the optional sectoral flags,
 * the live "Will be recorded" read-back, and the same fetch-based submit/
 * close handling the family form uses. Called for BOTH the inline "Add
 * evacuee" card and the fetched "Edit" modal -- which can be on the page at
 * the same time, so everything is looked up inside `root`, never by id.
 */
window.ELIKAS.initEcBoardEntryForm = function initEcBoardEntryForm(root) {
    if (!root) return;

    wireModalClose(root);
    wireModalSubmit(root);

    const q = (selector) => root.querySelector(selector);
    const show = (el, visible, display = 'block') => { if (el) el.style.display = visible ? display : 'none'; };

    const existingField = q('.household-existing-field');
    const newField = q('.household-new-field');
    const householdSelect = q('.household-select');
    const headSelfNew = q('[data-head-self="new"]');
    const headSelfExisting = q('[data-head-self="existing"]');
    const sexSelect = q('select[name="sex"]');
    const ageSelect = q('select[name="age_bracket"]');

    // The entry that created its household renders a fixed hidden "new"
    // instead of the toggle (see _entry_fields.blade.php).
    const mode = () => q('.household-type-radio:checked')?.value
        ?? q('input[type="hidden"][name="household_type"]')?.value
        ?? 'existing';

    const selectedHousehold = () => (householdSelect?.value ? householdSelect.options[householdSelect.selectedIndex] : null);

    // "This person is the household head" is only offered for an existing
    // household with no head linked yet -- the real head arriving later.
    const existingHeadOffered = () => mode() === 'existing' && selectedHousehold()?.dataset.headOpen === '1';

    const personIsHead = () => (mode() === 'new'
        ? Boolean(headSelfNew?.checked)
        : existingHeadOffered() && Boolean(headSelfExisting?.checked));

    function applyHousehold() {
        const current = mode();
        show(existingField, current === 'existing', 'flex');
        show(newField, current === 'new', 'flex');

        // A hidden/unoffered tickbox is disabled, not just hidden -- both
        // share the name head_is_self, and a disabled input is never sent.
        const offered = existingHeadOffered();
        show(q('.entry-existing-head'), offered, 'flex');
        if (headSelfExisting) {
            headSelfExisting.disabled = !offered;
            if (!offered) headSelfExisting.checked = false;
        }
        if (headSelfNew) headSelfNew.disabled = current !== 'new';

        show(q('.entry-head-note'), personIsHead(), 'flex');
        show(q('.entry-head-section'), current === 'new' && !headSelfNew?.checked);
        renderSummary();
    }

    // The "Will be recorded" read-back: plain sentences built from exactly
    // what the form will save, so a wrong answer is visible before saving.
    // Mirrors the web dashboard's own renderAddEvacueeSummary().
    const optionText = (select) => (select?.value ? select.options[select.selectedIndex].textContent.trim() : '');
    const isMinorBracket = (bracket) => ['infant', 'toddler', 'preschooler', 'school_age', 'teenage'].includes(bracket);
    const minorText = (isMinor) => (isMinor === null ? 'minor or not: not yet known' : (isMinor ? 'a minor' : 'not a minor'));
    const answerText = (value) => ({ 1: 'yes', 0: 'no' })[value] ?? 'not yet known';

    function renderSummary() {
        const summary = q('.entry-summary');
        if (!summary) return;

        const age = optionText(ageSelect);
        const headIsMinor = ageSelect?.value ? isMinorBracket(ageSelect.value) : null;
        const lines = [sexSelect?.value && age ? `Adding 1 ${sexSelect.value}, ${age.toLowerCase()}.` : 'Choose this person\'s age group and sex.'];

        if (mode() === 'existing') {
            const label = optionText(householdSelect);
            lines.push(label ? `Joins the family already here: ${label}.` : 'Choose the family this person belongs to.');
            if (personIsHead()) lines.push(`Becomes that family's head (${minorText(headIsMinor)}).`);
        } else {
            const name = q('input[name="new_household_head_name"]')?.value.trim();
            const barangay = optionText(q('.household-barangay-select'));
            lines.push(`New family: ${name || '(family name not entered yet)'}, ${barangay || '(home barangay not chosen yet)'}.`);
            if (personIsHead()) {
                lines.push(`Head: this person (${minorText(headIsMinor)}).`);
            } else {
                const headMinor = q('select[name="head_is_minor"]')?.value ?? '';
                lines.push(`Head: someone else, ${q('select[name="head_sex"]')?.value || 'sex not yet known'}, ${minorText(headMinor === '' ? null : headMinor === '1')}.`);
            }
            lines.push(`Single-headed: ${answerText(q('select[name="is_single_headed"]')?.value)}.`);
        }

        const flags = [...root.querySelectorAll('.entry-sectoral-flag:checked')].map((box) => box.parentElement.textContent.trim());
        lines.push(flags.length ? `Sectoral: ${flags.join(', ')}.` : 'No sectoral details.');

        // textContent, not innerHTML -- household names are typed by staff.
        summary.replaceChildren(...lines.map((text) => {
            const li = document.createElement('li');
            li.textContent = text;
            return li;
        }));
    }

    // One click for the common case: the family lives in this center's own barangay.
    q('.household-barangay-same')?.addEventListener('click', (e) => {
        const select = q('.household-barangay-select');
        select.value = e.currentTarget.dataset.barangayId;
        select.dispatchEvent(new Event('change', { bubbles: true }));
    });

    // Purok: only the puroks of the chosen home barangay; hidden (and
    // cleared) when this device has none for it.
    const purokField = q('[data-purok-field]');
    function applyPurok() {
        if (!purokField) return;
        const barangaySelect = q('.household-barangay-select');
        const remoteId = barangaySelect?.selectedOptions[0]?.dataset.remoteId ?? '';
        const purokSelect = purokField.querySelector('select');
        let any = false;
        purokSelect.querySelectorAll('option[data-barangay-remote-id]').forEach((option) => {
            const match = option.dataset.barangayRemoteId === remoteId;
            option.hidden = !match;
            option.disabled = !match;
            any = any || match;
        });
        if (purokSelect.selectedOptions[0]?.disabled) purokSelect.value = '';
        purokField.hidden = !any;
    }
    q('.household-barangay-select')?.addEventListener('change', applyPurok);
    applyPurok();

    root.querySelectorAll('.household-type-radio').forEach((radio) => radio.addEventListener('change', applyHousehold));
    headSelfNew?.addEventListener('change', applyHousehold);
    headSelfExisting?.addEventListener('change', applyHousehold);
    // Anything else on the form only changes the read-back.
    root.addEventListener('input', renderSummary);
    root.addEventListener('change', renderSummary);

    // Optional sectoral flags (see _entry_fields.blade.php): pregnant/
    // lactating hidden AND cleared for a male evacuee -- clearing matters,
    // since a hidden-but-still-ticked box would still be submitted -- and
    // the collapsed header's badge shows how many are ticked, so a closed
    // section never hides that something is set.
    const sectoralBadge = q('.entry-sectoral-count');
    function applySectoralFlags() {
        const isMale = sexSelect?.value === 'male';
        root.querySelectorAll('.entry-sectoral [data-female-only]').forEach((label) => {
            label.style.display = isMale ? 'none' : '';
            if (isMale) label.querySelector('input').checked = false;
        });
        const ticked = root.querySelectorAll('.entry-sectoral-flag:checked').length;
        if (sectoralBadge) {
            sectoralBadge.textContent = `${ticked} ticked`;
            sectoralBadge.style.display = ticked ? '' : 'none';
        }
    }
    sexSelect?.addEventListener('change', applySectoralFlags);
    root.querySelector('.entry-sectoral')?.addEventListener('change', applySectoralFlags);
    applySectoralFlags();

    // Keeps the hidden household_label input in sync with whichever
    // option is currently selected -- AddEvacueeRequest::householdFields()
    // uses this as the display snapshot for a household picked from the
    // live remote list below, which has no local row to look a name back
    // up from later (see that class's own docblock).
    const householdLabelInput = q('.household-label-input');
    householdSelect?.addEventListener('change', () => {
        const option = householdSelect.options[householdSelect.selectedIndex];
        if (householdLabelInput) householdLabelInput.value = option ? option.textContent.trim() : '';
        applyHousehold();
    });

    // Live households fetch -- appends households known to the central
    // server but not yet in this device's own local list (e.g.
    // registered from a different device) to the SAME dropdown above,
    // rather than a separate list, so staff only ever pick from one
    // place. Fires after the form is already usable (this device's own
    // local households, if any, are already in the select from the
    // server-side render) and fails completely silently offline -- see
    // EvacuationCenterController::refreshHouseholds()'s own docblock for
    // why this is a fetch(), not part of any page's synchronous render.
    function loadRemoteHouseholds() {
        const refreshUrlInput = root.querySelector('.household-refresh-url');
        const eventSelect = root.querySelector('[name="evacuation_event_id"]');
        if (!refreshUrlInput || !householdSelect || !eventSelect?.value) return;

        const knownRemoteIds = (root.querySelector('.household-local-remote-ids')?.value || '')
            .split(',').filter(Boolean);
        const loadingHint = root.querySelector('.household-loading-hint');
        const emptyHint = root.querySelector('.household-empty-hint');

        if (loadingHint) loadingHint.style.display = 'block';

        // cache: 'no-store' + a cache-busting _ param -- this must always
        // reflect who's ACTUALLY registered right now, never a stale
        // browser-cached response from an earlier visit to this exact
        // URL (a real, confirmed source of confusion: the server-side
        // data was already correct, but a cached fetch() response kept
        // showing an outdated household list even after a page reload).
        fetch(`${refreshUrlInput.value}?event=${encodeURIComponent(eventSelect.value)}&_=${Date.now()}`, { cache: 'no-store' })
            .then((r) => (r.ok ? r.json() : null))
            .then((result) => {
                if (!result) return;
                const remoteHouseholds = result.households;

                // This device's SYNCED households the server no longer has
                // here (everyone checked out) leave the list -- the server
                // would refuse them. Not-yet-synced ones, and the one an
                // entry being edited already points at, stay.
                const here = new Set(result.here_remote_ids.map(String));
                [...householdSelect.options]
                    .filter((o) => o.dataset.remoteId && !here.has(o.dataset.remoteId) && !o.selected)
                    .forEach((o) => o.remove());

                // Households this device already has locally (by remote
                // id), or already in the list (an entry being edited),
                // are skipped -- listing one twice is confusing, not more
                // complete.
                const present = new Set([...householdSelect.options].map((o) => o.value));
                remoteHouseholds
                    .filter((h) => !knownRemoteIds.includes(String(h.value).replace('remote-', '')) && !present.has(h.value))
                    .forEach((h) => {
                        const option = document.createElement('option');
                        option.value = h.value;
                        option.textContent = h.label;
                        option.dataset.headOpen = h.head_linked ? '0' : '1';
                        householdSelect.appendChild(option);
                    });

                if (emptyHint) emptyHint.style.display = householdSelect.options.length > 1 ? 'none' : 'block';
                applyHousehold();
            })
            .catch(() => {
                // Offline, timeout, or session expired -- this device's
                // own local households (already in the select) remain
                // fully usable either way.
            })
            .finally(() => {
                if (loadingHint) loadingHint.style.display = 'none';
            });
    }

    applyHousehold();
    loadRemoteHouseholds();
    root.querySelector('[name="evacuation_event_id"]')?.addEventListener('change', loadRemoteHouseholds);
};

// ---------------------------------------------------------------------
// Delegated click handler for the whole document -- opens the edit-entry
// family modal for [data-modal-trigger] links, otherwise runs the page
// transition for normal same-origin navigation.
// ---------------------------------------------------------------------
document.addEventListener('click', (e) => {
    const ecBoardTrigger = e.target.closest('[data-modal-trigger="ec-board-entry"]');
    if (ecBoardTrigger) {
        e.preventDefault();
        openEcBoardEntryModal(ecBoardTrigger.href);
        return;
    }

    const link = e.target.closest('a[href]');
    if (!link || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    const url = new URL(link.href, window.location.href);
    if (url.origin !== window.location.origin || link.target === '_blank' || link.hasAttribute('download')) return;
    e.preventDefault();
    document.querySelector('main')?.classList.remove('in');
    setTimeout(() => { window.location.href = link.href; }, 150);
});

// ---------------------------------------------------------------------
// Confirm dialog (web design system 5, Ui.confirm): the title is the
// question, the button names the action. A form with data-confirm asks
// first; data-confirm-title / -label / -tone="danger" shape the dialog.
// ---------------------------------------------------------------------
window.ELIKAS.confirm = function confirmDialog({ title, message = '', confirmLabel = 'Continue', cancelLabel = 'Cancel', tone = 'primary' }) {
    return new Promise((resolve) => {
        const backdrop = document.createElement('div');
        backdrop.className = 'board-modal-backdrop';
        backdrop.style.display = 'flex';
        backdrop.innerHTML = `
            <div class="modal modal-pop max-w-md" role="alertdialog" aria-modal="true" aria-labelledby="confirm-title">
                <div class="modal-header"><h2 id="confirm-title" class="modal-title"></h2></div>
                <p class="px-6 pt-4 text-gray-700 whitespace-pre-line" data-confirm-message></p>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-confirm-cancel></button>
                    <button type="button" class="btn ${tone === 'danger' ? 'btn-danger' : 'btn-primary'}" data-confirm-ok></button>
                </div>
            </div>`;
        backdrop.querySelector('#confirm-title').textContent = title;
        backdrop.querySelector('[data-confirm-message]').textContent = message;
        backdrop.querySelector('[data-confirm-ok]').textContent = confirmLabel;
        backdrop.querySelector('[data-confirm-cancel]').textContent = cancelLabel;
        const done = (answer) => {
            backdrop.remove();
            document.removeEventListener('keydown', onKey);
            resolve(answer);
        };
        const onKey = (e) => { if (e.key === 'Escape') done(false); };
        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop || e.target.closest('[data-confirm-cancel]')) done(false);
            if (e.target.closest('[data-confirm-ok]')) done(true);
        });
        document.addEventListener('keydown', onKey);
        document.body.appendChild(backdrop);
        backdrop.querySelector('[data-confirm-cancel]').focus();
    });
};

document.addEventListener('submit', async (e) => {
    const form = e.target;
    if (!form.dataset?.confirm || form.dataset.confirmed === '1') return;
    e.preventDefault();
    e.stopImmediatePropagation();
    const ok = await window.ELIKAS.confirm({
        title: form.dataset.confirmTitle || form.dataset.confirm,
        message: form.dataset.confirmTitle ? form.dataset.confirm : '',
        confirmLabel: form.dataset.confirmLabel || 'Continue',
        cancelLabel: form.dataset.confirmCancel || 'Cancel',
        tone: form.dataset.confirmTone || 'primary',
    });
    if (!ok) return;
    form.dataset.confirmed = '1';
    form.requestSubmit();
}, true);

// ---------------------------------------------------------------------
// "..." menus (web design system 5): row actions, destructive ones last.
// ---------------------------------------------------------------------
document.addEventListener('click', (e) => {
    const toggle = e.target.closest('[data-menu-toggle]');
    document.querySelectorAll('[data-menu-list]').forEach((list) => {
        if (!toggle || list !== toggle.nextElementSibling) {
            list.hidden = true;
            list.previousElementSibling?.setAttribute('aria-expanded', 'false');
        }
    });
    if (toggle) {
        const list = toggle.nextElementSibling;
        list.hidden = !list.hidden;
        toggle.setAttribute('aria-expanded', list.hidden ? 'false' : 'true');
    }
});

// ---------------------------------------------------------------------
// Sync now, one record at a time: the local server answers one request
// at a time, so the page sends a record per request (sync.step) and the
// window stays usable. Reloads when done so every figure is current.
// ---------------------------------------------------------------------
window.ELIKAS.syncRunning = false;
window.ELIKAS.runSync = async function runSync(form, { retryFailed = true } = {}) {
    if (window.ELIKAS.syncRunning) return null;
    window.ELIKAS.syncRunning = true;
    const control = form.closest('[data-sync-control]');
    const progress = control?.querySelector('[data-sync-progress]');
    const button = form.querySelector('[data-sync-button]');
    const token = form.querySelector('input[name="_token"]').value;
    const post = (url, body = {}) => fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
        body: JSON.stringify(body),
    }).then((r) => r.json());

    if (button) button.disabled = true;
    let sent = 0;
    let refused = 0;
    let last = null;
    try {
        if (retryFailed) await post(form.dataset.retryUrl);
        for (let i = 0; i < 1000; i++) {
            last = await post(form.dataset.stepUrl);
            if (last.status === 'sent') sent++;
            if (['failed', 'decision', 'event_closed'].includes(last.status)) refused++;
            if (progress) progress.textContent = `Sent ${sent}` + (last.remaining ? `, ${last.remaining} to go` : '');
            if (last.status === 'empty' || last.status === 'stopped') break;
        }
    } catch (e) {
        last = { status: 'stopped', stop: 'offline' };
    } finally {
        window.ELIKAS.syncRunning = false;
        if (button) button.disabled = !navigator.onLine;
    }
    return { sent, refused, last };
};

document.querySelectorAll('[data-sync-form]').forEach((form) => {
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const result = await window.ELIKAS.runSync(form);
        if (!result) return;
        if (result.last?.stop === 'auth') {
            form.submit(); // the server shows "Log in again"
            return;
        }
        window.location.reload();
    });
});

// ---------------------------------------------------------------------
// Auto sync (barangay officials): whenever records wait and the central
// server answers, send them without anyone pressing a button. Runs on
// page load, when the connection comes back, when the window gets focus,
// and every minute while records wait. Refused records are left for the
// Sync page (no automatic retry of a refusal). Never reloads the page:
// someone may be typing.
// ---------------------------------------------------------------------
(function autoSync() {
    const form = document.querySelector('[data-auto-sync]');
    const chip = document.getElementById('sync-chip');
    if (!form || !chip) return;

    const plural = (n) => `${Number(n).toLocaleString('en-US')} ${n === 1 ? 'record' : 'records'}`;
    let state = null; // null: idle; else 'sending' | 'auth' | 'attention' | 'unreachable'
    const setChip = (text, tone) => {
        chip.textContent = text;
        chip.className = `badge badge-${tone} text-sm px-3 py-1`;
    };
    // What the chip says, from the connection, the queue and the last run.
    const render = () => {
        const waiting = Number(chip.dataset.pending || 0);
        if (!navigator.onLine) return setChip(waiting ? `Offline · ${plural(waiting)} waiting` : 'Offline', waiting ? 'warning' : 'neutral');
        if (state === 'sending') return setChip('Sending…', 'info');
        if (state === 'auth') return setChip('Log in again to send', 'danger');
        if (state === 'attention') return setChip('Needs attention', 'danger');
        if (state === 'unreachable' && waiting) return setChip(`Server not reachable · ${plural(waiting)} waiting`, 'warning');
        return setChip(waiting ? `${plural(waiting)} waiting` : 'All sent', waiting ? 'warning' : 'success');
    };
    window.ELIKAS.renderSyncChip = render;
    render();
    window.addEventListener('offline', render);
    window.addEventListener('online', () => { state = null; render(); });

    async function attempt() {
        if (!navigator.onLine || window.ELIKAS.syncRunning) return;
        if (Number(chip.dataset.pending || 0) === 0) return;
        let probe;
        try {
            probe = await fetch(form.dataset.probeUrl, { headers: { Accept: 'application/json' } }).then((r) => r.json());
        } catch (e) {
            return;
        }
        if (!probe.reachable) { state = 'unreachable'; return render(); }
        if (!probe.auth) { state = 'auth'; return render(); }
        state = 'sending';
        render();
        const result = await window.ELIKAS.runSync(form, { retryFailed: false });
        if (!result) return;
        chip.dataset.pending = String(result.last?.remaining ?? 0);
        state = result.last?.stop === 'auth' ? 'auth' : (result.refused > 0 ? 'attention' : null);
        render();
    }

    setTimeout(attempt, 3000);
    window.addEventListener('online', () => setTimeout(attempt, 1500));
    window.addEventListener('focus', attempt);
    setInterval(attempt, 60000);
})();

// ---------------------------------------------------------------------
// Tabs (web design system 5): one panel at a time, choice kept in the URL
// hash, arrow keys move between tabs.
// ---------------------------------------------------------------------
document.querySelectorAll('[data-tabs]').forEach((list) => {
    const tabs = [...list.querySelectorAll('[data-tab]')];
    const show = (name) => {
        tabs.forEach((tab) => {
            const on = tab.dataset.tab === name;
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            tab.tabIndex = on ? 0 : -1;
        });
        document.querySelectorAll('[data-tab-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.tabPanel !== name;
        });
    };
    const initial = tabs.find((t) => `#${t.dataset.tab}` === window.location.hash) ?? tabs[0];
    if (initial) show(initial.dataset.tab);
    tabs.forEach((tab, i) => {
        tab.addEventListener('click', () => {
            show(tab.dataset.tab);
            history.replaceState(null, '', `#${tab.dataset.tab}`);
        });
        tab.addEventListener('keydown', (e) => {
            if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
            const next = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
            next.focus();
            next.click();
        });
    });
});

// Buttons that need the central server are disabled while offline.
function updateOnlineOnly() {
    document.querySelectorAll('[data-online-only]').forEach((el) => { el.disabled = !navigator.onLine; });
}
updateOnlineOnly();
window.addEventListener('online', updateOnlineOnly);
window.addEventListener('offline', updateOnlineOnly);

// Esc closes an open "..." menu or the account menu and returns focus to its button.
document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('[data-menu-list]:not([hidden])').forEach((list) => {
        list.hidden = true;
        list.previousElementSibling?.setAttribute('aria-expanded', 'false');
        list.previousElementSibling?.focus();
    });
    const userMenu = document.getElementById('user-menu');
    if (userMenu && !userMenu.classList.contains('hidden')) {
        userMenu.classList.add('hidden');
        document.getElementById('user-menu-btn')?.focus();
    }
});

// ---------------------------------------------------------------------
// The bell: in-app notifications (GET /notifications through this app).
// A notification carries only its web href; /transfer-requests opens the
// Transfers screen (the list is loaded again), others say where on the web.
// Loaded at start and every minute while online; the last copy offline.
// ---------------------------------------------------------------------
(function bell() {
    const root = document.querySelector('[data-bell]');
    if (!root) return;
    const count = root.querySelector('[data-bell-count]');
    const list = root.querySelector('[data-bell-list]');
    const toggle = root.querySelector('[data-bell-toggle]');
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    async function load() {
        let data;
        try {
            data = await fetch(root.dataset.feedUrl, { headers: { Accept: 'application/json' } }).then((r) => r.json());
        } catch (e) {
            return;
        }
        count.hidden = !data.unread;
        count.textContent = data.unread > 9 ? '9+' : String(data.unread);
        toggle.setAttribute('aria-label', data.unread ? `Notifications, ${data.unread} unread` : 'Notifications');
        list.innerHTML = data.notifications.length
            ? data.notifications.map((n) => `
                <a href="${esc(n.open)}" class="menu-item flex-col items-start gap-0.5 ${n.read ? '' : 'bg-blue-50'}" role="menuitem" data-notification>
                    <span class="font-semibold text-gray-900">${esc(n.title)}</span>
                    <span class="text-sm text-gray-700 whitespace-normal">${esc(n.message)}</span>
                    ${n.in_app ? '' : '<span class="text-xs text-gray-500">Opens on the web</span>'}
                </a>`).join('')
            : '<p class="px-3 py-2 text-sm text-gray-600">No notifications.</p>';
    }

    toggle.addEventListener('click', async (e) => {
        e.stopPropagation();
        const open = list.hidden;
        list.hidden = !open;
        toggle.setAttribute('aria-expanded', String(open));
        if (open && !count.hidden && navigator.onLine) {
            const token = document.querySelector('meta[name="csrf-token"]').content;
            fetch(root.dataset.readUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' } })
                .then(() => { count.hidden = true; }).catch(() => {});
        }
    });
    document.addEventListener('click', (e) => { if (!root.contains(e.target)) { list.hidden = true; toggle.setAttribute('aria-expanded', 'false'); } });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !list.hidden) { list.hidden = true; toggle.focus(); } });

    setTimeout(load, 1500);
    setInterval(() => { if (navigator.onLine) load(); }, 60000);
    window.addEventListener('online', load);
})();

// ---------------------------------------------------------------------
// Update notice: checks the latest published version (downloads/
// desktop-version.json on the hosting, through this app) shortly after
// start and every 6 hours while online. "Later" hides it until the app
// is opened again.
// ---------------------------------------------------------------------
(function updateNotice() {
    const banner = document.querySelector('[data-update-banner]');
    if (!banner) return;
    let dismissed = false;
    try { dismissed = sessionStorage.getItem('elikas-update-later') === '1'; } catch (e) {}

    async function check() {
        if (dismissed) return;
        try {
            const data = await fetch(banner.dataset.checkUrl, { headers: { Accept: 'application/json' } }).then((r) => r.json());
            if (data.update) {
                banner.querySelector('[data-update-latest]').textContent = data.latest;
                banner.hidden = false;
            }
        } catch (e) {}
    }
    banner.querySelector('[data-update-later]')?.addEventListener('click', () => {
        banner.hidden = true;
        dismissed = true;
        try { sessionStorage.setItem('elikas-update-later', '1'); } catch (e) {}
    });

    setTimeout(check, 4000);
    setInterval(() => { if (navigator.onLine) check(); }, 6 * 60 * 60 * 1000);
})();

// ---------------------------------------------------------------------
// Signing out after inactivity (Data and security): no key, click, scroll
// or mouse movement for the set time signs the person out of the screen.
// The server checks too, so a computer left asleep is signed out as well.
// ---------------------------------------------------------------------
(function idleSignOut() {
    const meta = document.querySelector('meta[name="idle-timeout"]');
    if (!meta) return;
    const limitMs = Number(meta.content) * 1000;
    if (!limitMs) return;
    let last = Date.now();
    const seen = () => { last = Date.now(); };
    ['mousemove', 'mousedown', 'keydown', 'wheel', 'touchstart', 'scroll'].forEach((type) => document.addEventListener(type, seen, { passive: true, capture: true }));
    setInterval(() => {
        if (Date.now() - last < limitMs) return;
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = meta.dataset.lockUrl;
        form.innerHTML = `<input type="hidden" name="_token" value="${document.querySelector('meta[name="csrf-token"]').content}">`;
        document.body.appendChild(form);
        form.submit();
    }, 15000);
})();
// ---------------------------------------------------------------------
// The administrator's notices (GET /system-notices through this app):
// shortly after start and every 5 minutes while online; the last ones
// offline. Never counts as activity (a background request).
// ---------------------------------------------------------------------
(function systemNotices() {
    const box = document.querySelector('[data-system-notices]');
    if (!box) return;
    const load = async () => {
        if (!navigator.onLine) return;
        try {
            const r = await fetch(`${box.dataset.feedUrl}?_=${Date.now()}`, { cache: 'no-store', headers: { Accept: 'text/html' } });
            if (r.ok) box.innerHTML = await r.text();
        } catch (e) {}
    };
    setTimeout(load, 4000);
    setInterval(load, 5 * 60000);
})();