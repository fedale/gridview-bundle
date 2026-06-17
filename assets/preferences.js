/**
 * Pluggable persistence for saved searches and selections.
 *
 * A provider implements:
 *   load(scope, bucket): Array
 *   save(scope, bucket, items): void
 *
 * `scope` is a per-grid/per-route key, `bucket` is e.g. 'searches' | 'selections'.
 * The default keeps everything in localStorage (persistent, per-browser). To use a
 * backend instead, set `window.gridviewPreferenceProvider` to your own object
 * implementing the same two methods before the controllers connect.
 */
export class LocalStoragePreferenceProvider {
    constructor(prefix = 'gv-prefs') {
        this.prefix = prefix;
    }

    _key(scope) {
        return `${this.prefix}:${scope}`;
    }

    _all(scope) {
        try {
            return JSON.parse(localStorage.getItem(this._key(scope)) || '{}');
        } catch (_) {
            return {};
        }
    }

    load(scope, bucket) {
        return this._all(scope)[bucket] || [];
    }

    save(scope, bucket, items) {
        const all = this._all(scope);
        all[bucket] = items;
        localStorage.setItem(this._key(scope), JSON.stringify(all));
    }
}

const defaultProvider = new LocalStoragePreferenceProvider();

/** Resolve the active provider (app override or the localStorage default). */
export function preferenceProvider() {
    return (typeof window !== 'undefined' && window.gridviewPreferenceProvider) || defaultProvider;
}
