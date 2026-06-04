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
            if (username.includes(query)) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        if (this.hasNoResultsRowTarget) {
            this.noResultsRowTarget.style.display = visibleCount === 0 ? '' : 'none';
        }
    }
}
