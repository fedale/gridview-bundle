/**
 * Tiny dependency-free prompt modal (replaces window.prompt for naming saved
 * searches/selections). Returns a Promise that resolves to the trimmed value, or
 * null on cancel. Enter confirms, Escape / backdrop cancels.
 */
export function promptModal({ title = '', label = '', value = '', okLabel = 'OK', cancelLabel = 'Annulla' } = {}) {
    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'gv-prompt-overlay';
        // The modal lives on <body>, outside the grid container. The data-gridview
        // marker lets it inherit the --gv-* theme variables (light/dark, host- or
        // OS-driven) and the scoped [data-gridview] .gv-btn styles for its buttons.
        overlay.setAttribute('data-gridview', '');
        overlay.innerHTML = `
            <div class="gv-prompt" role="dialog" aria-modal="true">
                ${title ? `<div class="gv-prompt-title">${esc(title)}</div>` : ''}
                ${label ? `<label class="gv-prompt-label">${esc(label)}</label>` : ''}
                <input type="text" class="gv-prompt-input">
                <div class="gv-prompt-actions">
                    <button type="button" class="gv-btn gv-prompt-cancel">${esc(cancelLabel)}</button>
                    <button type="button" class="gv-btn gv-btn-primary gv-prompt-ok">${esc(okLabel)}</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);

        const input = overlay.querySelector('.gv-prompt-input');
        input.value = value;
        input.focus();
        input.select();

        let done = false;
        const close = (result) => {
            if (done) return;
            done = true;
            document.removeEventListener('keydown', onKey, true);
            overlay.remove();
            resolve(result);
        };
        const onKey = (e) => {
            if (e.key === 'Escape') { e.preventDefault(); close(null); }
            else if (e.key === 'Enter') { e.preventDefault(); close(input.value.trim() || null); }
        };

        document.addEventListener('keydown', onKey, true);
        overlay.querySelector('.gv-prompt-ok').addEventListener('click', () => close(input.value.trim() || null));
        overlay.querySelector('.gv-prompt-cancel').addEventListener('click', () => close(null));
        overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close(null); });
    });
}

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
