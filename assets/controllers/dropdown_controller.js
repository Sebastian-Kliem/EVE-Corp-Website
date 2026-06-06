import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.closeHandler = this.close.bind(this);
        document.addEventListener('click', this.closeHandler);
    }

    disconnect() {
        document.removeEventListener('click', this.closeHandler);
    }

    toggle(event) {
        event.preventDefault();
        event.stopPropagation();
        this.element.classList.toggle('is-active');
    }

    close(event) {
        if (!this.element.contains(event.target)) {
            this.element.classList.remove('is-active');
        }
    }
}
