<div class="error-page-hero error-page-hero-301">
    <span class="error-page-code">301</span>
    <h1 class="error-page-title"><?php echo htmlspecialchars($errorTitle); ?></h1>
    <p class="error-page-message"><?php echo htmlspecialchars($errorMessage); ?></p>
    <?php if (!empty($errorRedirectUrl)): ?>
    <p class="error-page-hint">
        You will be redirected shortly.
        <a class="error-page-inline-link" href="<?php echo htmlspecialchars(BASE_URL . ltrim($errorRedirectUrl, '/')); ?>">
            Continue to the new location
        </a>
    </p>
    <?php else: ?>
    <p class="error-page-hint">This address is no longer active. Use the links below to continue.</p>
    <?php endif; ?>
</div>
