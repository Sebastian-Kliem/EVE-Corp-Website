import { Controller } from '@hotwired/stimulus';

/*
 * Autocomplete for k-space solar systems: the visible input holds the name,
 * the hidden input the system ID that the server stores.
 */
export default class extends Controller {
    static targets = ['input', 'systemId', 'results', 'hint'];
    static values = { url: String };

    connect() {
        this.debounceTimer = null;
        this.abortController = null;
        this.systems = [];
        this.activeIndex = -1;
    }

    disconnect() {
        clearTimeout(this.debounceTimer);
        this.abortController?.abort();
    }

    search() {
        // Typing invalidates a previous selection until a system is picked again
        this.systemIdTarget.value = '';
        this.hintTarget.classList.add('hidden');

        clearTimeout(this.debounceTimer);
        const query = this.inputTarget.value.trim();
        if (query === '') {
            this.clearResults();
            return;
        }
        this.debounceTimer = setTimeout(() => this.fetchSystems(query), 150);
    }

    async fetchSystems(query) {
        this.abortController?.abort();
        this.abortController = new AbortController();

        try {
            const response = await fetch(`${this.urlValue}?q=${encodeURIComponent(query)}`, {
                headers: { 'Accept': 'application/json' },
                signal: this.abortController.signal,
            });
            if (!response.ok) {
                return;
            }
            this.systems = await response.json();
            this.activeIndex = this.systems.length > 0 ? 0 : -1;
            this.renderResults();
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.clearResults();
            }
        }
    }

    renderResults() {
        this.resultsTarget.innerHTML = '';

        if (this.systems.length === 0) {
            const emptyRow = document.createElement('div');
            emptyRow.className = 'px-3 py-1.5 text-xs text-eve-muted';
            emptyRow.textContent = 'Kein passendes K-Space-System gefunden.';
            this.resultsTarget.appendChild(emptyRow);
            this.resultsTarget.classList.remove('hidden');
            return;
        }

        this.systems.forEach((system, index) => {
            const row = document.createElement('button');
            row.type = 'button';
            // Explicit background and border: without Tailwind preflight buttons keep the light browser default
            row.className = 'w-full flex items-center justify-between gap-2 px-3 py-1.5 border-0 text-left text-xs cursor-pointer transition-colors '
                + (index === this.activeIndex ? 'bg-eve-primary/15 text-white' : 'bg-transparent text-eve-text hover:bg-white/5');
            row.addEventListener('mousedown', (event) => {
                // mousedown fires before the input loses focus
                event.preventDefault();
                this.select(index);
            });

            const name = document.createElement('span');
            name.textContent = system.name;

            const meta = document.createElement('span');
            meta.className = 'flex items-center gap-2 text-[11px]';
            const security = document.createElement('span');
            security.className = this.securityClass(system.security);
            security.textContent = system.security.toFixed(1);
            const region = document.createElement('span');
            region.className = 'text-eve-muted';
            region.textContent = system.regionName;
            meta.append(security, region);

            row.append(name, meta);
            this.resultsTarget.appendChild(row);
        });
        this.resultsTarget.classList.remove('hidden');
    }

    navigate(event) {
        if (this.resultsTarget.classList.contains('hidden') || this.systems.length === 0) {
            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            this.activeIndex = (this.activeIndex + step + this.systems.length) % this.systems.length;
            this.renderResults();
        } else if (event.key === 'Enter' && this.activeIndex >= 0) {
            event.preventDefault();
            this.select(this.activeIndex);
        } else if (event.key === 'Escape') {
            this.clearResults();
        }
    }

    select(index) {
        const system = this.systems[index];
        if (!system) {
            return;
        }
        this.inputTarget.value = system.name;
        this.systemIdTarget.value = String(system.id);
        this.hintTarget.classList.add('hidden');
        this.clearResults();
    }

    hideResults() {
        this.clearResults();
    }

    validate(event) {
        if (this.systemIdTarget.value === '') {
            event.preventDefault();
            this.hintTarget.classList.remove('hidden');
            this.inputTarget.focus();
        }
    }

    clearResults() {
        this.systems = [];
        this.activeIndex = -1;
        this.resultsTarget.innerHTML = '';
        this.resultsTarget.classList.add('hidden');
    }

    securityClass(security) {
        if (security >= 0.5) {
            return 'font-mono text-emerald-400';
        }
        if (security > 0) {
            return 'font-mono text-amber-400';
        }
        return 'font-mono text-rose-400';
    }
}
