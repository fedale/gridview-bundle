import { Controller } from '@hotwired/stimulus';
import i18n from '../i18n.js';

/**
 * Mounted on every grid root. Wires the external-switcher listeners once and
 * applies the current locale to this grid's DOM on connect — which also covers
 * content re-injected by Turbo (filters, pagination), since the controller
 * reconnects with the new nodes.
 */
export default class extends Controller {
    connect() {
        i18n.init();
        i18n.apply(this.element);
    }
}
