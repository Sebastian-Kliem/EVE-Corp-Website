import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['ping', 'dot', 'count', 'label', 'charCount', 'list'];
    static values = {
        url: { type: String, default: '/online-status' },
        refreshInterval: { type: Number, default: 300000 } // 5 minutes (300,000 ms)
    };

    connect() {
        this.startPolling();
        this.visibilityHandler = () => {
            if (document.visibilityState === 'visible') {
                this.refresh();
            }
        };
        document.addEventListener('visibilitychange', this.visibilityHandler);
    }

    disconnect() {
        this.stopPolling();
        if (this.visibilityHandler) {
            document.removeEventListener('visibilitychange', this.visibilityHandler);
        }
    }

    startPolling() {
        this.stopPolling();
        this.timer = setInterval(() => this.refresh(), this.refreshIntervalValue);
    }

    stopPolling() {
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
    }

    async refresh() {
        try {
            const response = await fetch(this.urlValue, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                return;
            }

            const data = await response.json();
            this.updateUI(data);
        } catch (e) {
            // Silently ignore network / polling errors
        }
    }

    updateUI(data) {
        const userCount = data.userCount ?? 0;
        const charCount = data.characterCount ?? 0;
        const users = data.users ?? [];

        // 1. Update count number and label
        if (this.hasCountTarget) {
            this.countTarget.textContent = userCount;
        }
        if (this.hasLabelTarget) {
            this.labelTarget.textContent = (userCount === 1 ? 'User' : 'User') + ' online';
        }

        // 2. Update status dot & ping animation
        if (this.hasPingTarget) {
            if (userCount > 0) {
                this.pingTarget.classList.remove('hidden');
            } else {
                this.pingTarget.classList.add('hidden');
            }
        }

        if (this.hasDotTarget) {
            if (userCount > 0) {
                this.dotTarget.classList.remove('bg-slate-500');
                this.dotTarget.classList.add('bg-emerald-500');
            } else {
                this.dotTarget.classList.remove('bg-emerald-500');
                this.dotTarget.classList.add('bg-slate-500');
            }
        }

        // 3. Update character count badge in dropdown header
        if (this.hasCharCountTarget) {
            this.charCountTarget.textContent = `${charCount} ${charCount === 1 ? 'Charakter' : 'Charaktere'}`;
        }

        // 4. Update dropdown list content
        if (this.hasListTarget) {
            if (users.length === 0) {
                this.listTarget.innerHTML = `
                    <div class="text-eve-muted py-2 text-center italic">
                        Keine verknüpften Spieler online
                    </div>
                `;
            } else {
                let html = '';
                for (const u of users) {
                    let charsHtml = '';
                    for (const c of (u.characters || [])) {
                        charsHtml += `
                            <div class="py-0.5 text-slate-300 font-medium truncate">
                                ${this.escapeHtml(c.name)}
                            </div>
                        `;
                    }

                    html += `
                        <div class="p-2 rounded bg-white/[0.03] border border-eve-border/30">
                            <div class="font-bold text-eve-text flex items-center justify-between">
                                <span>${this.escapeHtml(u.username)}</span>
                                <span class="text-[0.7rem] text-emerald-400 font-medium">online</span>
                            </div>
                            <div class="mt-1.5 pl-2 border-l border-eve-primary/40 space-y-0.5 text-[0.8rem]">
                                ${charsHtml}
                            </div>
                        </div>
                    `;
                }
                this.listTarget.innerHTML = html;
            }
        }
    }

    escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
}
