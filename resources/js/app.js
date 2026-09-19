import './bootstrap';

window.ELIKAS = window.ELIKAS || {};

// ---------------------------------------------------------------------
// Connection badge + live clock -- present in the shared layout header
// on every logged-in page.
// ---------------------------------------------------------------------
function updateConnectionBadge() {
    const badge = document.getElementById('connection-badge');
    if (!badge) return;
    if (navigator.onLine) {
        badge.textContent = 'Online';
        badge.className = 'text-xs px-2.5 py-1 rounded-full font-semibold bg-green-500 text-white';
    } else {
        badge.textContent = 'Offline';
        badge.className = 'text-xs px-2.5 py-1 rounded-full font-semibold bg-gray-500 text-white';
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
document.addEventListener('submit', () => {
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
    backdrop.style.background = 'rgba(15, 36, 71, 0.55)';
    backdrop.style.backdropFilter = 'blur(4px)';
    backdrop.style.webkitBackdropFilter = 'blur(4px)';
    backdrop.setAttribute('data-dynamic-modal-backdrop', '');
    backdrop.innerHTML = '<div class="bg-white rounded-2xl px-6 py-5 text-sm text-gray-500 mt-10">Loading...</div>';
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
            backdrop.innerHTML = '<div class="bg-white rounded-2xl p-6 text-sm text-red-600 mt-10">Could not load the form. Please try again.</div>';
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
    const data = dataEl ? JSON.parse(dataEl.textContent) : { centers: [], cachedEvacuees: [], evacueesIndexUrl: '#' };
    const allCenters = data.centers;
    const cachedEvacuees = data.cachedEvacuees;
    const evacueesIndexUrl = data.evacueesIndexUrl;
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
        <div class="member-row card-modern p-4" data-index="${index}">
            <div class="flex items-center justify-between mb-3">
                <p class="text-sm font-semibold text-gray-600">Member ${index + 1}</p>
                ${index > 0 ? `<button type="button" class="remove-member text-xs text-red-500 font-medium hover:underline">Remove</button>` : ''}
            </div>
            <div class="grid grid-cols-3 gap-3">
                <input type="text" name="members[${index}][first_name]" value="${escAttr(v('first_name'))}" placeholder="First name *" class="m-first_name border border-gray-300 rounded-xl px-3 py-2 text-sm" required>
                <input type="text" name="members[${index}][middle_name]" value="${escAttr(v('middle_name'))}" placeholder="Middle name (optional)" class="border border-gray-300 rounded-xl px-3 py-2 text-sm">
                <input type="text" name="members[${index}][last_name]" value="${escAttr(v('last_name'))}" placeholder="Last name *" class="m-last_name border border-gray-300 rounded-xl px-3 py-2 text-sm" required>
                <select name="members[${index}][sex]" class="border border-gray-300 rounded-xl px-3 py-2 text-sm" required>
                    <option value="">Sex *</option>
                    <option value="male" ${selected('sex', 'male')}>Male</option>
                    <option value="female" ${selected('sex', 'female')}>Female</option>
                </select>
                <div>
                    <label class="text-xs text-gray-500 block mb-0.5">Date of birth *</label>
                    <input type="date" name="members[${index}][date_of_birth]" value="${escAttr(v('date_of_birth'))}" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm" required>
                </div>
                <input type="text" name="members[${index}][contact_number]" value="${escAttr(v('contact_number'))}" placeholder="Contact number (optional)" class="border border-gray-300 rounded-xl px-3 py-2 text-sm">
            </div>
            <div class="dup-warning mt-3 bg-amber-50 text-amber-700 text-xs rounded-xl p-2.5 items-start gap-2" style="display: none;">
                <i class="ti ti-alert-triangle shrink-0 mt-0.5" style="font-size: 14px;" aria-hidden="true"></i>
                <span class="dup-warning-text"></span>
            </div>
            <div class="flex flex-wrap gap-4 mt-3 text-xs text-gray-600 items-center">
                <input type="hidden" name="members[${index}][is_head_of_family]" value="0">
                <label class="flex items-center gap-1.5"><input type="radio" name="members[${index}][is_head_of_family]" value="1" ${isHead ? 'checked' : ''}> Head of family</label>
                <label class="flex items-center gap-1.5"><input type="checkbox" class="m-is_pwd" name="members[${index}][is_pwd]" value="1" ${checked('is_pwd')}> PWD</label>
                <input type="text" placeholder="PWD type" value="${escAttr(v('pwd_type'))}" class="m-pwd_type ${isPwd ? '' : 'hidden'} border border-gray-300 rounded-lg px-2 py-1 text-xs" name="members[${index}][pwd_type]">
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
    // already-loaded cachedEvacuees array, so it works identically whether
    // the device is online or not. Never blocks submission; it's a nudge
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
        for (const evac of cachedEvacuees) {
            if (!evac.head_name) continue;
            const score = nameSimilarity(fullName, evac.head_name);
            if (score >= 0.72 && (!best || score > best.score)) {
                best = Object.assign({ score }, evac);
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
            const link = `${evacueesIndexUrl}?q=${encodeURIComponent(match.head_name)}`;
            warning.querySelector('.dup-warning-text').innerHTML =
                `Possible existing match: <strong>${escapeHtml(match.head_name)}</strong>, registered in ${escapeHtml(barangay)} -- `
                + `<a href="${link}" target="_blank" class="underline font-medium">check All Evacuees</a> before continuing.`;
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
    backdrop.style.background = 'rgba(15, 36, 71, 0.55)';
    backdrop.style.backdropFilter = 'blur(4px)';
    backdrop.style.webkitBackdropFilter = 'blur(4px)';
    backdrop.setAttribute('data-dynamic-modal-backdrop', '');
    backdrop.innerHTML = '<div class="bg-white rounded-2xl px-6 py-5 text-sm text-gray-500 mt-10">Loading...</div>';
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
            backdrop.innerHTML = '<div class="bg-white rounded-2xl p-6 text-sm text-red-600 mt-10">Could not load the form. Please try again.</div>';
        });
}

/**
 * Wires an EC Board entry form's household_type toggle plus the same
 * fetch-based submit/close handling the family form uses (wireModalSubmit/
 * wireModalClose are already generic -- not family-specific -- so this
 * reuses them rather than inventing a second submit mechanism). Called for
 * BOTH the inline "Add evacuee" card on the center page (root has no close
 * button, so wireModalClose() no-ops) and the fetched "Edit" modal.
 */
window.ELIKAS.initEcBoardEntryForm = function initEcBoardEntryForm(root) {
    if (!root) return;

    wireModalClose(root);
    wireModalSubmit(root);

    const existingField = root.querySelector('.household-existing-field');
    const newField = root.querySelector('.household-new-field');

    function applyHouseholdType() {
        const checked = root.querySelector('.household-type-radio:checked');
        const isExisting = !checked || checked.value === 'existing';
        existingField.style.display = isExisting ? 'block' : 'none';
        newField.style.display = isExisting ? 'none' : 'block';
    }

    root.querySelectorAll('.household-type-radio').forEach((radio) => {
        radio.addEventListener('change', applyHouseholdType);
    });

    // Keeps the hidden household_label input in sync with whichever
    // option is currently selected -- AddEvacueeRequest::householdFields()
    // uses this as the display snapshot for a household picked from the
    // live remote list below, which has no local row to look a name back
    // up from later (see that class's own docblock).
    const householdSelect = root.querySelector('.household-select');
    const householdLabelInput = root.querySelector('.household-label-input');
    householdSelect?.addEventListener('change', () => {
        const option = householdSelect.options[householdSelect.selectedIndex];
        if (householdLabelInput) householdLabelInput.value = option ? option.textContent : '';
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
            .then((r) => (r.ok ? r.json() : []))
            .then((remoteHouseholds) => {
                // Households this device already has locally (by remote
                // id) are skipped -- they're already in the select from
                // the server-side render, and listing them twice would
                // just be confusing, not more complete.
                remoteHouseholds
                    .filter((h) => !knownRemoteIds.includes(String(h.value).replace('remote-', '')))
                    .forEach((h) => {
                        const option = document.createElement('option');
                        option.value = h.value;
                        option.textContent = h.label;
                        householdSelect.appendChild(option);
                    });

                if (emptyHint && householdSelect.options.length > 1) emptyHint.style.display = 'none';
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
