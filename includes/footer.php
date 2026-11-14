<?php

declare(strict_types=1);

$flash = get_flash();
?>
        </div>
    </main>
</div>

<div class="snackbar" id="snackbar" role="status" aria-live="polite">
    <span class="material-icons-round snackbar-icon">check_circle</span>
    <span class="snackbar-text"></span>
    <button type="button" data-snackbar-close>Dismiss</button>
</div>

<script src="<?= e(asset('js/app.js')) ?>"></script>
<?= $extraScripts ?? '' ?>
<?php if ($flash): ?>
<script>
    window.showSnackbar(
        <?= json_encode($flash['message'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        <?= json_encode($flash['type'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
    );
</script>
<?php endif; ?>
</body>
</html>
