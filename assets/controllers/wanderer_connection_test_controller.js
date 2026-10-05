import { Controller } from '@hotwired/stimulus';

/*
 * Tests the Wanderer URL, map slug and API key currently in the form, without saving or leaving the page.
 */
export default class extends Controller {
    static targets = ['url', 'slug', 'key', 'button', 'result'];
    static values = { endpoint: String, token: String };

    async test() {
        const body = new FormData();
        body.append('_token', this.tokenValue);
        body.append('wanderer_api_url', this.urlTarget.value);
        body.append('wanderer_map_slug', this.slugTarget.value);
        body.append('wanderer_api_key', this.keyTarget.value);

        this.buttonTarget.disabled = true;
        this.showResult('Teste Verbindung ...', 'text-eve-muted');

        try {
            const response = await fetch(this.endpointValue, {
                method: 'POST',
                body,
                headers: { 'Accept': 'application/json' },
            });
            const data = await response.json();
            this.showResult(data.message, data.success ? 'text-emerald-400' : 'text-rose-400');
        } catch (error) {
            this.showResult('Der Test konnte nicht ausgeführt werden. Bitte die Seite neu laden.', 'text-rose-400');
        } finally {
            this.buttonTarget.disabled = false;
        }
    }

    showResult(message, colorClass) {
        this.resultTarget.textContent = message;
        this.resultTarget.className = 'text-[11px] mt-1 ' + colorClass;
    }
}
