import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['menu', 'burger'];

    toggle() {
        this.menuTarget.classList.toggle('is-active');
        this.burgerTarget.classList.toggle('is-active');
    }
}
