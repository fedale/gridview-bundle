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
            window.Turbo.visit(url, { action: 'advance' });
        } else {
            window.location.href = url;
        }
    }
}
