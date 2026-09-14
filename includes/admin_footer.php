<?php $extraScripts = $extraScripts ?? ''; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Universal submit feedback — spinner on the clicked submit button.
// CAREFUL: do NOT set btn.disabled = true on the submitter. Safari and
// iOS Chrome drop the submitter's name/value from the POST body when
// it's disabled mid-submit event, silently breaking any handler that
// dispatches on the button's value (e.g. name="action" value="approve").
(() => {
    document.addEventListener('submit', (e) => {
        const f = e.target;
        if (!f.matches || !f.matches('form')) return;
        if ((f.getAttribute('method') || '').toLowerCase() !== 'post') return;
        const submitter = e.submitter && f.contains(e.submitter) ? e.submitter : null;
        if (submitter && !submitter.hasAttribute('data-no-spinner')) {
            submitter.dataset.origHtml = submitter.innerHTML;
            submitter.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
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
