import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'row', 'noResultsRow'];

    connect() {
        if (this.hasInputTarget && this.inputTarget.value) {
            this.filter();
        }
    }

    filter() {
        const query = this.inputTarget.value.toLowerCase().trim();
        let visibleCount = 0;

        this.rowTargets.forEach(row => {
            const username = row.dataset.username.toLowerCase();
            const matches = username.includes(query);
            row.classList.toggle('hidden', !matches);
            if (matches) {
                visibleCount++;
            }
        });

        if (this.hasNoResultsRowTarget) {
            this.noResultsRowTarget.classList.toggle('hidden', visibleCount !== 0);
        }
    }
}
