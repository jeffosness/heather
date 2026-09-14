<?php
declare(strict_types=1);
$settings = app_settings();
$extraScripts = $extraScripts ?? '';
?>
<footer class="border-top mt-5 py-4 text-center text-muted small" style="background: #efeaf7;">
    <div class="container">
        <div>© <?= date('Y') ?> <?= htmlspecialchars((string) $settings['site_name']) ?></div>
        <div class="mt-2"><a href="/admin/" class="text-muted" style="opacity:.6;">Site admin</a></div>
    </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Universal submit feedback — spinner on the clicked submit button.
// CAREFUL: do NOT set btn.disabled = true on the submitter. Safari and
// iOS Chrome drop the submitter's name/value from the POST body when
// it's disabled mid-submit event, silently breaking any handler that
// dispatches on the button's value. Other submit buttons get dimmed
// via CSS so double-taps don't fire twice.
(() => {
    document.addEventListener('submit', (e) => {
        const f = e.target;
        if (!f.matches || !f.matches('form')) return;
        if ((f.getAttribute('method') || '').toLowerCase() !== 'post') return;
        const submitter = e.submitter && f.contains(e.submitter) ? e.submitter : null;
        if (submitter && !submitter.hasAttribute('data-no-spinner')) {
            submitter.dataset.origHtml = submitter.innerHTML;
            submitter.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending...';
        }
        f.querySelectorAll('button[type="submit"]').forEach(btn => {
            if (btn === submitter) return;
            if (btn.hasAttribute('data-no-spinner')) return;
            btn.style.opacity = '.6';
            btn.style.pointerEvents = 'none';
        });
    });
})();
</script>
<?= $extraScripts ?>
</body>
</html>
