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
        badge.className = 'text-xs px-2.5 py-1 rounded-lg font-medium bg-green-500 text-white';
    } else {
        badge.textContent = 'Offline';
        badge.className = 'text-xs px-2.5 py-1 rounded-lg font-medium bg-gray-500 text-white';
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
                window.location.href = response.url;
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
            window.location.href = form.action;
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

    wireModalClose(modalRoot);
    wireModalSubmit(modalRoot);

    const membersContainer = modalRoot.querySelector('#members-container');
    let memberCount = 0;

    function memberRowHtml(index) {
        return `
        <div class="member-row bg-white border border-gray-200 rounded-xl p-4" data-index="${index}">
            <div class="flex items-center justify-between mb-3">
                <p class="text-sm font-medium text-gray-600">Member ${index + 1}</p>
                ${index > 0 ? `<button type="button" class="remove-member text-xs text-red-500 hover:underline">Remove</button>` : ''}
            </div>
            <div class="grid grid-cols-3 gap-3">
                <input type="text" name="members[${index}][first_name]" placeholder="First name" class="m-first_name border border-gray-300 rounded-lg px-3 py-2 text-sm" required>
                <input type="text" name="members[${index}][middle_name]" placeholder="Middle name" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <input type="text" name="members[${index}][last_name]" placeholder="Last name" class="m-last_name border border-gray-300 rounded-lg px-3 py-2 text-sm" required>
                <select name="members[${index}][sex]" class="border border-gray-300 rounded-lg px-3 py-2 text-sm" required>
                    <option value="">Sex</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                </select>
                <input type="date" name="members[${index}][date_of_birth]" class="border border-gray-300 rounded-lg px-3 py-2 text-sm" required>
                <input type="text" name="members[${index}][contact_number]" placeholder="Contact number" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div class="dup-warning mt-3 bg-amber-50 text-amber-700 text-xs rounded-lg p-2.5 items-start gap-2" style="display: none;">
                <i class="ti ti-alert-triangle shrink-0 mt-0.5" style="font-size: 14px;" aria-hidden="true"></i>
                <span class="dup-warning-text"></span>
            </div>
            <div class="flex flex-wrap gap-4 mt-3 text-xs text-gray-600 items-center">
                <input type="hidden" name="members[${index}][is_head_of_family]" value="0">
                <label class="flex items-center gap-1.5"><input type="radio" name="members[${index}][is_head_of_family]" value="1"> Head of family</label>
                <label class="flex items-center gap-1.5"><input type="checkbox" class="m-is_pwd" name="members[${index}][is_pwd]" value="1"> PWD</label>
                <input type="text" placeholder="PWD type" class="m-pwd_type hidden border border-gray-300 rounded-lg px-2 py-1 text-xs" name="members[${index}][pwd_type]">
                <label class="flex items-center gap-1.5"><input type="checkbox" name="members[${index}][is_pregnant]" value="1"> Pregnant</label>
                <label class="flex items-center gap-1.5"><input type="checkbox" name="members[${index}][is_lactating]" value="1"> Lactating</label>
                <label class="flex items-center gap-1.5"><input type="checkbox" name="members[${index}][is_solo_parent]" value="1"> Solo parent</label>
                <label class="flex items-center gap-1.5"><input type="checkbox" name="members[${index}][is_indigenous_person]" value="1"> Indigenous person</label>
            </div>
        </div>`;
    }

    function addMemberRow() {
        membersContainer.insertAdjacentHTML('beforeend', memberRowHtml(memberCount));
        memberCount++;
    }

    modalRoot.querySelector('#add-member-btn').addEventListener('click', addMemberRow);
    addMemberRow();

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

    modalRoot.querySelector('[name="barangay_id"]').addEventListener('change', (e) => {
        const selectedOption = e.target.options[e.target.selectedIndex];
        const barangayRemoteId = Number(selectedOption.dataset.remoteId);
        const select = modalRoot.querySelector('[name="evacuation_center_id"]');
        const filtered = allCenters.filter((c) => c.barangay_remote_id === barangayRemoteId);
        select.innerHTML = '<option value="">Select center</option>' +
            filtered.map((c) => `<option value="${c.id}">${c.name}</option>`).join('');
    });

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

    const link = e.target.closest('a[href]');
    if (!link || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    const url = new URL(link.href, window.location.href);
    if (url.origin !== window.location.origin || link.target === '_blank' || link.hasAttribute('download')) return;
    e.preventDefault();
    document.querySelector('main')?.classList.remove('in');
    setTimeout(() => { window.location.href = link.href; }, 150);
});
