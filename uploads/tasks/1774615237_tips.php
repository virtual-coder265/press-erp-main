<?php
$pageTitle    = 'Tips & Advice';
$pageDesc     = 'Expert paint tips from Hillstor – learn how to prevent and fix common paint problems like fungal growth, blistering, chalking and more.';
$pageKeywords = 'paint tips Malawi, how to paint walls, fix paint problems, paint advice Africa, chalking paint, blistering paint, fungal paint, paint application tips';
require_once 'includes/header.php';

$tips = fetchAll('SELECT * FROM tips WHERE is_active = 1 ORDER BY sort_order');
$activeTipSlug = $_GET['tip'] ?? ($tips[0]['slug'] ?? '');
$activeTip = null;
foreach ($tips as $tip) {
    if ($tip['slug'] === $activeTipSlug) { $activeTip = $tip; break; }
}
if (!$activeTip && !empty($tips)) $activeTip = $tips[0];
?>

<!-- Page Hero -->
<section class="page-hero" style="background-image:url('images/banners/BANNER 6.jpeg');">
    <div class="container">
        <div class="breadcrumb">
            <a href="<?= url() ?>">Home</a>
            <span class="material-icons">chevron_right</span>
            <span>Tips & Advice</span>
            <?php if ($activeTip): ?>
                <span class="material-icons">chevron_right</span>
                <span><?= h($activeTip['title']) ?></span>
            <?php endif; ?>
        </div>
        <h1>Paint Tips &amp; Advice</h1>
        <p>Expert guidance on preventing and solving common paint problems for a perfect, long-lasting finish.</p>
    </div>
</section>

<section class="section section-bg">
    <div class="container">
        <div class="tips-page-grid">

            <!-- Main Content -->
            <div>
                <?php if ($activeTip): ?>
                <div class="tip-full" data-aos="fade-up">
                    <div style="display:flex;align-items:center;gap:16px;margin-bottom:24px;">
                        <div class="tip-icon">
                            <span class="material-icons"><?= h($activeTip['icon']) ?></span>
                        </div>
                        <div>
                            <p style="font-size:0.78rem;text-transform:uppercase;letter-spacing:2px;color:var(--primary);font-weight:700;margin-bottom:4px;">Paint Tips</p>
                            <h2><?= h($activeTip['title']) ?></h2>
                        </div>
                    </div>

                    <div class="tip-content">
                        <?= $activeTip['content'] ?>
                    </div>

                    <!-- Products suggestion -->
                    <div style="background:linear-gradient(135deg,var(--primary),var(--primary-dark));border-radius:var(--radius);padding:28px;margin-top:40px;color:#fff;">
                        <div style="display:flex;align-items:flex-start;gap:16px;">
                            <span class="material-icons" style="font-size:28px;color:var(--accent-light);flex-shrink:0;margin-top:4px;">lightbulb</span>
                            <div>
                                <h4 style="margin-bottom:8px;font-size:1rem;">Hillstor Recommendation</h4>
                                <p style="font-size:0.9rem;opacity:0.9;line-height:1.6;margin-bottom:20px;">
                                    Prevent this problem from the start by choosing the right Hillstor paint. Our range includes specialist formulas designed for Africa's climate with built-in protection.
                                </p>
                                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                                    <a href="<?= url('products') ?>" class="btn btn-white btn-sm">
                                        <span class="material-icons">palette</span> Browse Products
                                    </a>
                                    <a href="<?= url('contact') ?>" class="btn btn-sm" style="border:2px solid rgba(255,255,255,0.6);color:#fff;border-radius:50px;padding:9px 20px;font-weight:600;display:inline-flex;align-items:center;gap:6px;font-size:0.85rem;">
                                        <span class="material-icons">chat</span> Expert Advice
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation between tips -->
                <div style="display:flex;justify-content:space-between;gap:16px;margin-top:28px;flex-wrap:wrap;">
                    <?php
                    $prevTip = null; $nextTip = null; $found = false;
                    foreach ($tips as $t) {
                        if ($found) { $nextTip = $t; break; }
                        if ($t['slug'] === $activeTip['slug']) { $found = true; } else { $prevTip = $t; }
                    }
                    ?>
                    <div>
                        <?php if ($prevTip): ?>
                        <a href="<?= url('tips') . '?tip=' . h($prevTip['slug']) ?>" class="btn btn-outline btn-sm">
                            <span class="material-icons">arrow_back</span> <?= h($prevTip['title']) ?>
                        </a>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if ($nextTip): ?>
                        <a href="<?= url('tips') . '?tip=' . h($nextTip['slug']) ?>" class="btn btn-primary btn-sm">
                            <?= h($nextTip['title']) ?> <span class="material-icons">arrow_forward</span>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php else: ?>
                <div class="tip-full" style="text-align:center;padding:80px 20px;">
                    <span class="material-icons" style="font-size:56px;color:var(--border);">lightbulb_outline</span>
                    <h3 style="color:var(--text-3);margin:16px 0 8px;">No tips found</h3>
                    <p style="color:var(--text-3);">Please check back soon for expert paint advice.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <aside>
                <div class="tips-sidebar-list">
                    <h3>All Paint Tips</h3>
                    <?php foreach ($tips as $tip): ?>
                          <a href="<?= url('tips') . '?tip=' . h($tip['slug']) ?>"
                       class="tips-sidebar-item <?= ($activeTip && $activeTip['slug'] === $tip['slug']) ? 'active' : '' ?>">
                        <span class="material-icons"><?= h($tip['icon']) ?></span>
                        <?= h($tip['title']) ?>
                    </a>
                    <?php endforeach; ?>
                </div>

                <!-- Quick Contact Widget -->
                <div style="background:var(--dark);border-radius:var(--radius);padding:28px;margin-top:24px;color:#fff;">
                    <h4 style="color:#fff;margin-bottom:12px;">Need Expert Help?</h4>
                    <p style="font-size:0.85rem;color:rgba(255,255,255,0.7);line-height:1.6;margin-bottom:20px;">
                        Our paint specialists are ready to help you choose the right product and application technique.
                    </p>
                    <a href="<?= url('contact') ?>" class="btn btn-accent" style="width:100%;justify-content:center;">
                        <span class="material-icons">phone</span> Talk to an Expert
                    </a>
                </div>

                <!-- Products Widget -->
                <div style="background:var(--surface);border-radius:var(--radius);padding:24px;margin-top:24px;border:1px solid var(--border);box-shadow:var(--shadow);">
                    <h4 style="font-size:0.9rem;margin-bottom:16px;border-bottom:2px solid var(--primary);padding-bottom:8px;display:inline-block;">Top Products</h4>
                    <?php
                    $topProducts = fetchAll('SELECT name, slug, image FROM products WHERE is_featured = 1 AND is_active = 1 LIMIT 3');
                    foreach ($topProducts as $tp): ?>
                    <a href="<?= url('products/' . h($tp['slug'])) ?>" style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);text-decoration:none;color:var(--text);">
                        <img src="<?= url(h($tp['image'])) ?>" alt="<?= h($tp['name']) ?>" style="width:50px;height:50px;object-fit:cover;border-radius:var(--radius-sm);">
                        <div>
                            <p style="font-weight:600;font-size:0.88rem;margin-bottom:3px;"><?= h($tp['name']) ?></p>
                            <p style="font-size:0.78rem;color:var(--primary);">View Product →</p>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </aside>

        </div>
    </div>
</section>

<?php
// ── Structured Data: BreadcrumbList + Article ─────────────────────────────
$_schemaBase = rtrim(APP_URL, '/') . (APP_BASE_PATH ? '/' . ltrim(APP_BASE_PATH, '/') : '');
$_logoUrl    = $_schemaBase . '/images/logo.png';

$_bcItems = [
    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home',           'item' => $_schemaBase . '/'],
    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Tips & Advice',  'item' => $_schemaBase . '/tips'],
];
if ($activeTip) {
    $_bcItems[] = ['@type' => 'ListItem', 'position' => 3, 'name' => $activeTip['title'], 'item' => $_schemaBase . '/tips?tip=' . $activeTip['slug']];
}
$_breadcrumb = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $_bcItems];
echo '<script type="application/ld+json">' . json_encode($_breadcrumb, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";

if ($activeTip) {
    $_tipUrl = $_schemaBase . '/tips?tip=' . $activeTip['slug'];
    // Strip HTML tags from content for schema description
    $_tipDesc = mb_substr(strip_tags($activeTip['content']), 0, 200);
    $_article = [
        '@context'         => 'https://schema.org',
        '@type'            => 'Article',
        '@id'              => $_tipUrl . '#article',
        'headline'         => $activeTip['title'],
        'description'      => $_tipDesc,
        'author'           => [
            '@type' => 'Organization',
            'name'  => SITE_NAME,
            '@id'   => APP_URL . '/#organization',
        ],
        'publisher'        => [
            '@type' => 'Organization',
            'name'  => SITE_NAME,
            'logo'  => ['@type' => 'ImageObject', 'url' => $_logoUrl],
        ],
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $_tipUrl],
        'inLanguage'       => 'en-US',
    ];
    echo '<script type="application/ld+json">' . json_encode($_article, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
}
?>
<?php require_once 'includes/footer.php'; ?>
