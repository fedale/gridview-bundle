import { Controller } from '@hotwired/stimulus';

// Salta direttamente alla pagina selezionata nella <select>.
// Il valore di ogni <option> è l'URL della pagina, così non serve
// ricostruire la query string lato JS.
export default class extends Controller {
    static values = { turbo: Boolean };

    jump(event) {
        const url = event.target.value;
        if (!url) return;

        if (this.turboValue && window.Turbo) {
            // A synthesized <a> click inside the frame, not Turbo.visit(): the
            // pagination links (plain <a data-turbo-action="advance"> rendered
            // inside the <turbo-frame>) already get the right behavior for free
            // — frame-scoped content swap *and* a pushed history entry — because
            // that's Turbo's native click-handling path. Turbo.visit() driven
            // from JS doesn't reliably reproduce both halves of that (a global
            // visit replaces the whole document, losing anything set outside the
            // frame like a client-set dark theme attribute; visit({frame}) swaps
            // the frame but doesn't push history), so reuse the real path
            // instead of re-guessing its behavior here.
            const link = document.createElement('a');
            link.href = url;
            link.setAttribute('data-turbo-action', 'advance');
            link.hidden = true;
            (this.element.closest('turbo-frame') || document.body).appendChild(link);
            link.click();
            link.remove();
        } else {
            window.location.href = url;
        }
    }
}
