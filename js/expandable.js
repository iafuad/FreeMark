/**
 * FreeMark - Expandable Text ("See More / See Less") Toggle Handler
 * Uses delegated click listener to toggle clamped states without page reload.
 */
document.addEventListener('DOMContentLoaded', function () {
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.see-more-btn');
        if (!btn) return;
        
        e.preventDefault();
        const container = btn.closest('.expandable-text');
        if (!container) return;

        const isExpanded = container.classList.toggle('expanded');
        btn.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');

        btn.innerHTML = isExpanded
            ? 'See less <i data-lucide="chevron-up" class="icon-inline" style="width:14px;height:14px;display:inline;"></i>'
            : 'See more <i data-lucide="chevron-down" class="icon-inline" style="width:14px;height:14px;display:inline;"></i>';

        if (window.lucide && typeof lucide.createIcons === 'function') {
            lucide.createIcons();
        }
    });
});
