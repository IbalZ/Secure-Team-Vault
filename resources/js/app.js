import './bootstrap';

const SECRET_VISIBLE_MS = 20_000;
const MASKED_SECRET = '••••••••••••';
const revealedTargets = new Map();
const revealedGroups = new Map();
let liveRegionTimer;

class NavigationRequired extends Error {}

const liveRegion = () => document.getElementById('stv-live-region');

const showFeedback = (message, isError = false) => {
    const region = liveRegion();

    if (!region) {
        return;
    }

    window.clearTimeout(liveRegionTimer);
    region.textContent = message;
    region.setAttribute('role', isError ? 'alert' : 'status');
    region.classList.toggle('is-error', isError);
    region.classList.add('is-visible');

    liveRegionTimer = window.setTimeout(() => {
        region.classList.remove('is-visible', 'is-error');
        region.textContent = '';
    }, 4_500);
};

const targetFor = (button, type) => {
    const reference = type === 'reveal'
        ? button.dataset.revealTarget
        : button.dataset.copyTarget;
    const targetReference = reference || button.dataset.target || button.getAttribute('aria-controls');

    if (!targetReference) {
        return null;
    }

    try {
        return targetReference.startsWith('#')
            ? document.querySelector(targetReference)
            : document.getElementById(targetReference);
    } catch {
        return null;
    }
};

const writeTarget = (target, value) => {
    if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement) {
        target.value = value;
        return;
    }

    target.textContent = value;
};

const readTarget = (target) => {
    if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement) {
        return target.value;
    }

    return target.textContent || '';
};

const groupField = (group, name) => (
    group.querySelector('[data-secret-field="' + name + '"]')
    || group.querySelector('#secret-' + name)
);

const countdownFor = (group) => {
    const reference = group.dataset.countdownTarget;

    if (reference) {
        return document.getElementById(reference.replace(/^#/, ''));
    }

    return document.getElementById('secret-countdown');
};

const setRevealButtonState = (button, isRevealed) => {
    if (!button) {
        return;
    }

    button.setAttribute('aria-pressed', isRevealed ? 'true' : 'false');
    button.setAttribute('aria-label', isRevealed ? 'Hide credential secret' : 'Reveal credential secret');

    const label = button.querySelector('[data-reveal-label]');

    if (label) {
        label.textContent = isRevealed
            ? (button.dataset.hideLabel || 'Hide')
            : (button.dataset.showLabel || 'Reveal');
    }
};

const concealTarget = (target) => {
    const activeReveal = revealedTargets.get(target);

    if (activeReveal) {
        window.clearTimeout(activeReveal.timer);
        setRevealButtonState(activeReveal.button, false);
        revealedTargets.delete(target);
    }

    writeTarget(target, target.dataset.maskedValue || MASKED_SECRET);

    if (target instanceof HTMLInputElement && target.dataset.concealedType) {
        target.type = target.dataset.concealedType;
        delete target.dataset.concealedType;
    }

    target.dataset.secretState = 'hidden';
};

const concealGroup = (group) => {
    const activeReveal = revealedGroups.get(group);
    const countdown = activeReveal?.countdown || countdownFor(group);

    if (activeReveal) {
        window.clearInterval(activeReveal.interval);
        window.clearTimeout(activeReveal.timer);
        setRevealButtonState(activeReveal.button, false);
        revealedGroups.delete(group);
    }

    const username = groupField(group, 'username');
    const password = groupField(group, 'password');
    const notes = groupField(group, 'notes');

    if (username) writeTarget(username, username.dataset.maskedValue || MASKED_SECRET);
    if (password) writeTarget(password, password.dataset.maskedValue || MASKED_SECRET);
    if (notes) writeTarget(notes, notes.dataset.maskedValue || 'Hidden');
    if (countdown) {
        countdown.textContent = '';
    }

    group.dataset.secretState = 'hidden';
};

const revealTarget = (target, button, secret) => {
    concealTarget(target);

    if (target instanceof HTMLInputElement && target.type === 'password') {
        target.dataset.concealedType = 'password';
        target.type = 'text';
    }

    writeTarget(target, secret);
    target.dataset.secretState = 'revealed';
    setRevealButtonState(button, true);

    const timer = window.setTimeout(() => {
        concealTarget(target);
        showFeedback('Credential hidden automatically.');
    }, SECRET_VISIBLE_MS);

    revealedTargets.set(target, { button, timer });
};

const clearPayloadSecrets = (payload) => {
    if (!payload || typeof payload !== 'object') {
        return;
    }

    ['secret', 'username', 'password', 'notes', 'value'].forEach((key) => {
        if (Object.hasOwn(payload, key)) {
            payload[key] = '';
        }
    });

    if (payload.data && typeof payload.data === 'object') {
        ['secret', 'username', 'password', 'notes', 'value'].forEach((key) => {
            if (Object.hasOwn(payload.data, key)) {
                payload.data[key] = '';
            }
        });
    }
};

const extractSecret = (payload) => {
    const candidates = [
        payload?.secret,
        payload?.password,
        payload?.value,
        payload?.data?.secret,
        payload?.data?.password,
        payload?.data?.value,
    ];

    return candidates.find((candidate) => typeof candidate === 'string' && candidate.length > 0) || '';
};

const responseError = (status) => {
    if (status === 401 || status === 419) {
        return 'Your session has expired. Refresh the page and sign in again.';
    }

    if (status === 403) {
        return 'You do not have permission to access this credential.';
    }

    if (status === 404) {
        return 'This credential could not be found.';
    }

    if (status === 429) {
        return 'Too many attempts. Wait a moment and try again.';
    }

    return 'The secure request could not be completed.';
};

const postForSecret = async (url) => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

    if (!csrfToken) {
        throw new Error('Your secure session token is unavailable. Refresh the page.');
    }

    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({}),
    });

    if (response.status === 428) {
        const confirmation = await response.json().catch(() => ({}));

        if (typeof confirmation.confirm_url === 'string') {
            window.location.assign(confirmation.confirm_url);
            throw new NavigationRequired();
        }

        throw new Error('Confirm your password to continue.');
    }

    if (!response.ok) {
        throw new Error(responseError(response.status));
    }

    if (response.status === 204) {
        return {};
    }

    const contentType = response.headers.get('content-type') || '';

    if (!contentType.includes('application/json')) {
        return {};
    }

    return response.json();
};

const copyText = async (value) => {
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(value);
        return;
    }

    const temporaryInput = document.createElement('textarea');
    temporaryInput.value = value;
    temporaryInput.setAttribute('readonly', '');
    temporaryInput.style.position = 'fixed';
    temporaryInput.style.opacity = '0';
    document.body.appendChild(temporaryInput);
    temporaryInput.select();

    const copied = document.execCommand('copy');
    temporaryInput.value = '';
    temporaryInput.remove();

    if (!copied) {
        throw new Error('Your browser blocked clipboard access.');
    }
};

const handleGroupReveal = async (button, group) => {
    if (group.dataset.secretState === 'revealed') {
        concealGroup(group);
        showFeedback('Credential hidden.');
        return;
    }

    button.disabled = true;
    button.setAttribute('aria-busy', 'true');

    let payload;

    try {
        payload = await postForSecret(button.dataset.revealUrl);

        if (typeof payload.password !== 'string' || payload.password.length === 0) {
            throw new Error('The credential could not be revealed.');
        }

        concealGroup(group);
        const username = group.querySelector('#secret-username');
        const password = group.querySelector('#secret-password');
        const notes = group.querySelector('#secret-notes');
        const countdown = document.getElementById('secret-countdown');

        if (username) username.textContent = payload.username || 'Not provided';
        if (password) password.textContent = payload.password;
        if (notes) notes.textContent = payload.notes || 'No secure notes';

        group.dataset.secretState = 'revealed';
        setRevealButtonState(button, true);

        let remaining = Number(payload.expires_in) || 20;
        if (countdown) countdown.textContent = `Hidden in ${remaining}s`;

        const interval = window.setInterval(() => {
            remaining -= 1;
            if (countdown && remaining > 0) countdown.textContent = `Hidden in ${remaining}s`;
        }, 1_000);
        const timer = window.setTimeout(() => {
            concealGroup(group);
            showFeedback('Credential hidden automatically.');
        }, SECRET_VISIBLE_MS);

        revealedGroups.set(group, { button, interval, timer });
        showFeedback('Credential revealed for 20 seconds.');
    } catch (error) {
        if (error instanceof NavigationRequired) {
            return;
        }

        showFeedback(error instanceof Error ? error.message : 'The credential could not be revealed.', true);
    } finally {
        clearPayloadSecrets(payload);
        button.disabled = false;
        button.removeAttribute('aria-busy');
    }
};

const handleReveal = async (button) => {
    const groupId = button.dataset.revealGroup;
    const group = groupId ? document.getElementById(groupId) : null;

    if (group) {
        await handleGroupReveal(button, group);
        return;
    }

    const target = targetFor(button, 'reveal');

    if (!target) {
        showFeedback('The secret display area is unavailable.', true);
        return;
    }

    if (target.dataset.secretState === 'revealed') {
        concealTarget(target);
        showFeedback('Credential hidden.');
        return;
    }

    button.disabled = true;
    button.setAttribute('aria-busy', 'true');

    let payload;
    let secret = '';

    try {
        payload = await postForSecret(button.dataset.revealUrl);
        secret = extractSecret(payload);

        if (!secret) {
            throw new Error('The credential could not be revealed.');
        }

        revealTarget(target, button, secret);
        showFeedback('Credential revealed for 20 seconds.');
    } catch (error) {
        if (error instanceof NavigationRequired) {
            return;
        }

        showFeedback(error instanceof Error ? error.message : 'The credential could not be revealed.', true);
    } finally {
        secret = '';
        clearPayloadSecrets(payload);
        button.disabled = false;
        button.removeAttribute('aria-busy');
    }
};

const handleCopy = async (button) => {
    const target = targetFor(button, 'copy');
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');

    let payload;
    let secret = '';

    try {
        payload = await postForSecret(button.dataset.copyUrl);
        secret = extractSecret(payload);

        if (!secret && target?.dataset.secretState === 'revealed') {
            secret = readTarget(target);
        }

        if (!secret) {
            throw new Error('The credential could not be copied.');
        }

        await copyText(secret);
        showFeedback('Credential copied to the clipboard.');
    } catch (error) {
        if (error instanceof NavigationRequired) {
            return;
        }

        showFeedback(error instanceof Error ? error.message : 'The credential could not be copied.', true);
    } finally {
        secret = '';
        clearPayloadSecrets(payload);
        button.disabled = false;
        button.removeAttribute('aria-busy');
    }
};

document.addEventListener('click', (event) => {
    const source = event.target instanceof Element ? event.target : null;

    if (!source) {
        return;
    }

    const revealButton = source.closest('[data-reveal-url]');

    if (revealButton instanceof HTMLButtonElement || revealButton instanceof HTMLAnchorElement) {
        event.preventDefault();
        void handleReveal(revealButton);
        return;
    }

    const copyButton = source.closest('[data-copy-url]');

    if (copyButton instanceof HTMLButtonElement || copyButton instanceof HTMLAnchorElement) {
        event.preventDefault();
        void handleCopy(copyButton);
    }
});

const syncSecretReplacement = (toggle) => {
    const form = toggle.closest('form');
    const fields = form?.querySelector('[data-secret-fields]');
    const retained = form?.querySelector('[data-secret-retained-message]');
    const password = form?.querySelector('#vault_password');

    if (!fields || !password) {
        return;
    }

    fields.hidden = !toggle.checked;
    if (retained) retained.hidden = toggle.checked;
    password.required = toggle.checked;
};

document.querySelectorAll('[data-secret-replacement-toggle]').forEach((toggle) => {
    if (!(toggle instanceof HTMLInputElement)) {
        return;
    }

    toggle.addEventListener('change', () => syncSecretReplacement(toggle));
    syncSecretReplacement(toggle);
});

document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || !form.dataset.confirm) {
        return;
    }

    if (!window.confirm(form.dataset.confirm)) {
        event.preventDefault();
    }
});

const concealAllSecrets = () => {
    Array.from(revealedTargets.keys()).forEach((target) => concealTarget(target));
    Array.from(revealedGroups.keys()).forEach((group) => concealGroup(group));
};

document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        concealAllSecrets();
    }
});

window.addEventListener('pagehide', concealAllSecrets);
