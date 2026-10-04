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
    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${minutes} min ago`;
    const hours = Math.round(minutes / 60);
    if (hours < 24) return `${hours} ${hours === 1 ? 'hour' : 'hours'} ago`;
    const days = Math.round(hours / 24);
    return `${days} ${days === 1 ? 'day' : 'days'} ago`;
}

window.ELIKAS.localizeTimes = function localizeTimes(root = document) {
    root.querySelectorAll('time[data-local-time]').forEach((el) => {
        const when = new Date(el.getAttribute('datetime'));
        if (Number.isNaN(when.getTime())) return;
        el.textContent = when.toLocaleString('en-US', {
            month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
        });
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
// A board left open offline keeps aging -- "(5 min ago)" must not freeze.
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
    const formatted = new Date().toLocaleString('en-US', {
        month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
    });
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
// "Register a family" true in-page modal -- fetches the form and injects
// it over whatever page triggered it (Dashboard, Registered Families),
// with that page's real content blurred behind it, instead of navigating
// to a separate page. Every entry point uses this exact same mechanism;
// there is deliberately only one implementation.
// ---------------------------------------------------------------------
function closeDynamicModal(backdrop) {
    backdrop.remove();
}

function openRegisterFamilyModal(url) {
    const backdrop = document.createElement('div');
    backdrop.className = 'fixed inset-0 z-40 flex items-start justify-center overflow-y-auto py-10 px-4';
    backdrop.style.background = 'rgba(17, 24, 39, 0.5)';
    backdrop.setAttribute('data-dynamic-modal-backdrop', '');
    backdrop.innerHTML = '<div class="bg-white rounded-xl px-6 py-5 text-sm text-gray-600 mt-10">Loading...</div>';
    document.body.appendChild(backdrop);

    backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) closeDynamicModal(backdrop);
    });

    fetch(url, { headers: { 'X-Modal-Request': '1' }, credentials: 'same-origin' })
        .then((r) => r.text())
        .then((html) => {
            backdrop.innerHTML = html;
            const modalRoot = backdrop.querySelector('[data-register-family-modal]');
            if (modalRoot) window.ELIKAS.initRegisterFamilyForm(modalRoot);
        })
        .catch(() => {
            backdrop.innerHTML = '<div class="bg-white rounded-xl p-6 text-sm text-red-700 mt-10">Could not load the form. Please try again.</div>';
        });
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

window.ELIKAS.openRegisterFamilyModal = openRegisterFamilyModal;

window.ELIKAS.initRegisterFamilyForm = function initRegisterFamilyForm(modalRoot) {
    const dataEl = document.getElementById('register-family-data');
    const data = dataEl ? JSON.parse(dataEl.textContent) : { centers: [], knownHouseholds: [], familiesSearchUrl: '#' };
    const allCenters = data.centers;
    const knownHouseholds = data.knownHouseholds;
    const familiesSearchUrl = data.familiesSearchUrl;
    // Both null/absent on a plain create() form -- only edit() passes them.
    const existingMembers = data.existingMembers || null;
    const currentCenterId = data.currentCenterId ?? null;

    wireModalClose(modalRoot);
    wireModalSubmit(modalRoot);

    const membersContainer = modalRoot.querySelector('#members-container');
    let memberCount = 0;

    // `member` is optional -- omitted (plain create()) every field just
    // starts blank/unchecked, exactly as before. When editing, it carries
    // this row's current values so the row starts pre-filled instead of
    // empty; see the `existingMembers` loop below.
    function memberRowHtml(index, member) {
        const v = (field, fallback = '') => (member && member[field] != null ? member[field] : fallback);
        const checked = (field) => (member && member[field] ? 'checked' : '');
        const selected = (field, option) => (member && member[field] === option ? 'selected' : '');
        const escAttr = (s) => String(s).replace(/"/g, '&quot;');
        const isHead = member ? !!member.is_head_of_family : false;
        const isPwd = member ? !!member.is_pwd : false;

        return `
        <div class="member-row card p-4" data-index="${index}">
            <div class="flex items-center justify-between mb-3">
                <p class="text-sm font-semibold text-gray-900">Member ${index + 1}</p>
                ${index > 0 ? `<button type="button" class="remove-member text-xs text-red-700 font-medium hover:underline">Remove</button>` : ''}
            </div>
            <div class="grid grid-cols-3 gap-3 items-end">
                <input type="text" name="members[${index}][first_name]" value="${escAttr(v('first_name'))}" placeholder="First name *" class="m-first_name input" required>
                <input type="text" name="members[${index}][middle_name]" value="${escAttr(v('middle_name'))}" placeholder="Middle name (optional)" class="input">
                <input type="text" name="members[${index}][last_name]" value="${escAttr(v('last_name'))}" placeholder="Last name *" class="m-last_name input" required>
                <select name="members[${index}][sex]" class="input" required>
                    <option value="">Sex *</option>
                    <option value="male" ${selected('sex', 'male')}>Male</option>
                    <option value="female" ${selected('sex', 'female')}>Female</option>
                </select>
                <div>
                    <label class="label-sm">Date of birth *</label>
                    <input type="date" name="members[${index}][date_of_birth]" value="${escAttr(v('date_of_birth'))}" class="input" required>
                </div>
                <input type="text" name="members[${index}][contact_number]" value="${escAttr(v('contact_number'))}" placeholder="Contact number (optional)" class="input">
            </div>
            <div class="dup-warning callout callout-warning mt-3 text-xs items-start gap-2" style="display: none;">
                <i class="ti ti-alert-triangle shrink-0 mt-0.5" style="font-size: 14px;" aria-hidden="true"></i>
                <span class="dup-warning-text"></span>
            </div>
            <div class="flex flex-wrap gap-4 mt-3 text-xs text-gray-700 items-center">
                <input type="hidden" name="members[${index}][is_head_of_family]" value="0">
                <label class="flex items-center gap-1.5"><input type="radio" name="members[${index}][is_head_of_family]" value="1" ${isHead ? 'checked' : ''}> Head of family</label>
                <label class="flex items-center gap-1.5"><input type="checkbox" class="m-is_pwd" name="members[${index}][is_pwd]" value="1" ${checked('is_pwd')}> PWD</label>
                <input type="text" placeholder="PWD type" value="${escAttr(v('pwd_type'))}" class="m-pwd_type ${isPwd ? '' : 'hidden'} input input-sm w-40 text-xs" name="members[${index}][pwd_type]">
                <label class="flex items-center gap-1.5"><input type="checkbox" name="members[${index}][is_pregnant]" value="1" ${checked('is_pregnant')}> Pregnant</label>
                <label class="flex items-center gap-1.5"><input type="checkbox" name="members[${index}][is_lactating]" value="1" ${checked('is_lactating')}> Lactating</label>
                <label class="flex items-center gap-1.5"><input type="checkbox" name="members[${index}][is_solo_parent]" value="1" ${checked('is_solo_parent')}> Solo parent</label>
                <label class="flex items-center gap-1.5"><input type="checkbox" name="members[${index}][is_indigenous_person]" value="1" ${checked('is_indigenous_person')}> Indigenous person</label>
            </div>
        </div>`;
    }

    function addMemberRow(member) {
        membersContainer.insertAdjacentHTML('beforeend', memberRowHtml(memberCount, member));
        memberCount++;
    }

    modalRoot.querySelector('#add-member-btn').addEventListener('click', () => addMemberRow());

    if (existingMembers && existingMembers.length > 0) {
        existingMembers.forEach((member) => addMemberRow(member));
    } else {
        addMemberRow();
    }

    membersContainer.addEventListener('click', (e) => {
        if (e.target.classList.contains('remove-member')) {
            e.target.closest('.member-row').remove();
        }
    });

    membersContainer.addEventListener('change', (e) => {
        if (e.target.classList.contains('m-is_pwd')) {
            const pwdTypeInput = e.target.closest('.member-row').querySelector('.m-pwd_type');
            pwdTypeInput.classList.toggle('hidden', !e.target.checked);
        }
    });

    modalRoot.querySelector('#displacement_type').addEventListener('change', (e) => {
        modalRoot.querySelector('#center-field').style.display = e.target.value === 'inside_center' ? 'block' : 'none';
    });

    function populateCentersFor(barangaySelectEl, selectCenterId) {
        const selectedOption = barangaySelectEl.options[barangaySelectEl.selectedIndex];
        if (!selectedOption || !selectedOption.value) return;
        const barangayRemoteId = Number(selectedOption.dataset.remoteId);
        const select = modalRoot.querySelector('[name="evacuation_center_id"]');
        const filtered = allCenters.filter((c) => c.barangay_remote_id === barangayRemoteId);
        select.innerHTML = '<option value="">Select center</option>' +
            filtered.map((c) => `<option value="${c.id}">${c.name}</option>`).join('');
        if (selectCenterId != null) {
            select.value = String(selectCenterId);
        }
    }

    const barangaySelect = modalRoot.querySelector('[name="barangay_id"]');
    barangaySelect.addEventListener('change', (e) => populateCentersFor(e.target));

    // A pre-selected barangay <select> never fires its own 'change' event
    // on page load, so without this the center dropdown would stay empty
    // even though a barangay is already chosen -- true both when editing
    // a family already inside a center (currentCenterId set, selects it
    // in the freshly-populated list) AND for a brand-new registration
    // where _form.blade.php defaulted the barangay to this staff
    // account's own one (currentCenterId stays null there -- there's no
    // single "assigned center" to preselect, just a narrower, already-
    // relevant list to pick from instead of every center system-wide).
    if (barangaySelect.value) {
        populateCentersFor(barangaySelect, currentCenterId);
    }

    // Inline duplicate-name warning -- purely client-side against the
    // already-loaded knownHouseholds array (this device's own households),
    // so it works whether or not the device is online. Never blocks; it's a nudge
    // for staff to double-check, since two different people can share a
    // name and registration still needs to be able to proceed.
    function levenshteinDistance(a, b) {
        const rows = a.length + 1;
        const cols = b.length + 1;
        const dp = Array.from({ length: rows }, () => new Array(cols).fill(0));
        for (let i = 0; i < rows; i++) dp[i][0] = i;
        for (let j = 0; j < cols; j++) dp[0][j] = j;
        for (let i = 1; i < rows; i++) {
            for (let j = 1; j < cols; j++) {
                dp[i][j] = a[i - 1] === b[j - 1]
                    ? dp[i - 1][j - 1]
                    : 1 + Math.min(dp[i - 1][j], dp[i][j - 1], dp[i - 1][j - 1]);
            }
        }
        return dp[rows - 1][cols - 1];
    }

    function nameSimilarity(a, b) {
        a = a.toLowerCase().trim().replace(/\s+/g, ' ');
        b = b.toLowerCase().trim().replace(/\s+/g, ' ');
        if (!a || !b) return 0;
        if (a === b) return 1;
        if (a.length >= 4 && (a.includes(b) || b.includes(a))) return 0.9;
        return 1 - (levenshteinDistance(a, b) / Math.max(a.length, b.length));
    }

    function findPossibleMatch(fullName) {
        if (fullName.trim().length < 4) return null;
        let best = null;
        for (const household of knownHouseholds) {
            if (!household.head_name) continue;
            const score = nameSimilarity(fullName, household.head_name);
            if (score >= 0.72 && (!best || score > best.score)) {
                best = Object.assign({ score }, household);
            }
        }
        return best;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function checkRowForDuplicate(row) {
        const warning = row.querySelector('.dup-warning');
        if (!warning) return;

        const first = row.querySelector('.m-first_name')?.value || '';
        const last = row.querySelector('.m-last_name')?.value || '';
        const match = findPossibleMatch(`${first} ${last}`);

        if (match) {
            const barangay = match.barangay_name || 'an unknown barangay';
            const link = `${familiesSearchUrl}?search=${encodeURIComponent(match.head_name)}`;
            warning.querySelector('.dup-warning-text').innerHTML =
                `Possible existing match: <strong>${escapeHtml(match.head_name)}</strong>, already recorded on this device (${escapeHtml(barangay)}) -- `
                + `<a href="${link}" target="_blank" class="underline font-medium">check Registered families</a> before continuing.`;
            warning.style.display = 'flex';
        } else {
            warning.style.display = 'none';
        }
    }

    membersContainer.addEventListener('input', (e) => {
        if (!e.target.classList.contains('m-first_name') && !e.target.classList.contains('m-last_name')) return;
        const row = e.target.closest('.member-row');
        clearTimeout(row._dupCheckTimer);
        row._dupCheckTimer = setTimeout(() => checkRowForDuplicate(row), 400);
    });
};

// ---------------------------------------------------------------------
// "Edit pending evacuee entry" modal -- same dynamic-modal mechanism as
// openRegisterFamilyModal() above (fetch the form fragment, inject it over
// the current page), just targeting the EC Board entry form's own mount
// point instead. The "Add evacuee" form itself never needs this: it's
// always embedded directly on the center detail page, not opened as a
// modal -- see initEcBoardEntryForm() below, which wires both cases with
// one shared function.
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

    fetch(url, { headers: { 'X-Modal-Request': '1' }, credentials: 'same-origin' })
        .then((r) => r.text())
        .then((html) => {
            backdrop.innerHTML = html;
            const modalRoot = backdrop.querySelector('[data-ec-board-entry-modal]');
            if (modalRoot) window.ELIKAS.initEcBoardEntryForm(modalRoot);
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
// Delegated click handler for the whole document -- opens the register-
// family modal for [data-modal-trigger] links, otherwise runs the page
// transition for normal same-origin navigation.
// ---------------------------------------------------------------------
document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-modal-trigger="register-family"]');
    if (trigger) {
        e.preventDefault();
        openRegisterFamilyModal(trigger.href);
        return;
    }

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
