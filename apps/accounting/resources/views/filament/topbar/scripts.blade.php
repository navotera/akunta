<script>
    document.addEventListener('alpine:init', () => {
        if (window.Alpine && Alpine.data && !Alpine.$akClockRegistered) {
            Alpine.$akClockRegistered = true;

            // Global store for journal layout (persisted in localStorage)
            Alpine.store('akJournal', {
                ctxOpen: (() => {
                    try {
                        const v = localStorage.getItem('akJournal.ctxOpen');
                        return v === null ? true : v === '1';
                    } catch (e) { return true; }
                })(),
                toggle() {
                    this.ctxOpen = !this.ctxOpen;
                    try { localStorage.setItem('akJournal.ctxOpen', this.ctxOpen ? '1' : '0'); } catch (e) {}
                },
            });

            Alpine.data('akClock', (tz) => ({
                tz,
                dateLabel: '',
                timeLabel: '',
                expanded: (() => {
                    try { return localStorage.getItem('akClock.expanded') !== '0'; } catch (e) { return true; }
                })(),
                init() {
                    this.tick();
                    this.timer = setInterval(() => this.tick(), 1000);
                    this.$watch('expanded', v => {
                        try { localStorage.setItem('akClock.expanded', v ? '1' : '0'); } catch (e) {}
                    });
                },
                destroy() { clearInterval(this.timer); },
                tick() {
                    const d = new Date();
                    this.dateLabel = d.toLocaleDateString('id-ID', {
                        weekday: 'short', day: '2-digit', month: 'short',
                        timeZone: this.tz,
                    });
                    this.timeLabel = d.toLocaleTimeString('id-ID', {
                        hour: '2-digit', minute: '2-digit', second: '2-digit',
                        timeZone: this.tz, hour12: false,
                    });
                },
            }));
        }
    });
</script>
