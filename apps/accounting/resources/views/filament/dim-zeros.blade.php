<script>
(() => {
    // Single-digit / zero number dimmer.
    // Targets common Filament numeric containers + custom .ak-mono cells,
    // adds `.ak-dim` (grey, opacity 0.2) to text whose numeric value is 0
    // or |n| < 10. Re-runs after Livewire DOM updates.
    // Filament v3 uses `fi-wi-` prefix for widgets. Older guesses (fi-stats-*)
    // never existed, which is why dimming silently no-op'd.
    const SELECTORS = [
        '.fi-wi-stats-overview-stat-value',
        '.fi-wi-stats-overview-stat-description',
        '.fi-ta-text-item-label',
        '.fi-badge',
        '.ak-mono',
    ].join(',');

    const NUM_RE = /-?\d+(?:[.,]\d+)?/;

    function shouldDim(text) {
        if (!text) return false;
        const m = text.match(NUM_RE);
        if (!m) return false;
        const n = parseFloat(m[0].replace(',', '.'));
        if (Number.isNaN(n)) return false;
        return n === 0 || (Math.abs(n) < 10 && Number.isInteger(n));
    }

    function scan(root = document) {
        root.querySelectorAll(SELECTORS).forEach((el) => {
            // skip if already a child contains explicit non-numeric pill (handled per-element)
            const text = (el.textContent || '').trim();
            if (shouldDim(text)) {
                el.classList.add('ak-dim');
            } else {
                el.classList.remove('ak-dim');
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => scan());

    // Livewire 3 morph hook — re-scan after each component update
    document.addEventListener('livewire:init', () => {
        if (window.Livewire && window.Livewire.hook) {
            window.Livewire.hook('morph.updated', ({ el }) => scan(el));
            window.Livewire.hook('commit', ({ succeed }) => {
                succeed(() => queueMicrotask(() => scan()));
            });
        }
    });

    // Fallback observer for non-Livewire DOM mutations (Alpine, manual injects)
    new MutationObserver((mutations) => {
        for (const m of mutations) {
            for (const node of m.addedNodes) {
                if (node.nodeType === 1) scan(node);
            }
        }
    }).observe(document.body, { childList: true, subtree: true });
})();
</script>
