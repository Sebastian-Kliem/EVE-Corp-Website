import { Controller } from '@hotwired/stimulus';

/*
 * Flash toast: success and info messages fade out by themselves, errors stay until closed.
 */
export default class extends Controller {
    static values = { autohide: Boolean };

    connect() {
        if (this.autohideValue) {
            this.hideTimer = setTimeout(() => this.close(), 6000);
        }
    }

    disconnect() {
        clearTimeout(this.hideTimer);
    }

    close() {
        clearTimeout(this.hideTimer);
        this.element.classList.add('opacity-0');
        setTimeout(() => this.element.remove(), 300);
    }
}
