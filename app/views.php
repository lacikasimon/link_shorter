<?php
declare(strict_types=1);

function icon(string $name): string
{
    $paths = [
        'link' => '<path d="M10 13a5 5 0 0 0 7 .1l3-3a5 5 0 0 0-7-7l-1.7 1.7M14 11a5 5 0 0 0-7-.1l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
        'arrow' => '<path d="M5 12h14m-6-6 6 6-6 6"/>',
        'copy' => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3m-4 5v2"/>',
        'logout' => '<path d="M9 5H5v14h4m5-14 7 7-7 7m-5-7h12"/>',
    ];
    return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['link']) . '</svg>';
}

function page_start(string $title, string $path, bool $loggedIn = false): void
{
    ?>
    <!doctype html>
    <html lang="hu">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Egyszerű linkrövidítő, állítható kódhosszal.">
        <meta name="robots" content="noindex, nofollow">
        <meta name="theme-color" content="#0b6b63">
        <title><?= e($title) ?> · Rövid</title>
        <link rel="icon" href="<?= e($path) ?>assets/favicon.svg" type="image/svg+xml">
        <link rel="stylesheet" href="<?= e($path) ?>assets/app.css">
        <script src="<?= e($path) ?>assets/app.js" defer></script>
    </head>
    <body>
    <a class="skip-link" href="#main">Ugrás a tartalomhoz</a>
    <div class="shell">
        <header class="topbar">
            <a class="brand" href="<?= e($path) ?>" aria-label="Rövid – kezdőlap"><span class="brand-mark"><?= icon('link') ?></span>rövid<span class="brand-dot">.</span></a>
            <?php if ($loggedIn): ?>
                <form method="post" action="<?= e($path) ?>">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button class="text-button" type="submit"><?= icon('logout') ?> Kilépés</button>
                </form>
            <?php else: ?>
                <span class="topbar-label">Linkrövidítő</span>
            <?php endif; ?>
        </header>
        <main id="main">
    <?php
}

function page_end(): void
{
    ?>
        </main>
        <footer><span>rövid.</span> Kevesebb karakter. Ugyanaz a cél.</footer>
    </div>
    <div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
    </body>
    </html>
    <?php
}

function notice(string $message, string $kind = 'error'): void
{
    if ($message !== '') {
        echo '<div class="notice ' . e($kind) . '" role="alert">' . e($message) . '</div>';
    }
}

function render_problem(string $title, string $message, string $path, bool $setup = false): never
{
    page_start($title, $path);
    ?>
    <section class="card message-card">
        <span class="section-icon"><?= icon('link') ?></span>
        <h1><?= e($title) ?></h1>
        <p class="muted"><?= e($message) ?></p>
        <?php if ($setup): ?>
            <ol class="setup-steps">
                <li>Hozz létre egy MySQL-adatbázist és felhasználót a cPanelben.</li>
                <li>Importáld a <code>schema.sql</code> fájlt a phpMyAdminban.</li>
                <li>Másold a <code>config.example.php</code> fájlt <code>config.php</code> néven.</li>
                <li>Add meg benne a webcímet, az adatbázis adatait és a saját kezelői jelszavadat.</li>
            </ol>
            <p class="small muted">A részletes útmutató a csomag README.md fájljában található.</p>
        <?php endif; ?>
        <a class="button secondary" href="<?= e($path) ?>">Vissza a kezdőlapra <?= icon('arrow') ?></a>
    </section>
    <?php
    page_end();
    exit;
}

function render_login(array $config, string $error = ''): never
{
    $path = app_path($config);
    page_start('Belépés', $path);
    ?>
    <section class="card login-card">
        <span class="section-icon"><?= icon('lock') ?></span>
        <h1>A linkjeid, egy helyen.</h1>
        <p class="muted">Lépj be új rövid linkek létrehozásához.</p>
        <?php notice($error); ?>
        <form method="post" action="<?= e($path) ?>" class="login-form">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="login">
            <label for="password">Kezelői jelszó</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
            <button type="submit" class="button">Belépés <?= icon('arrow') ?></button>
        </form>
    </section>
    <?php
    page_end();
    exit;
}

function render_dashboard(array $config, array $links, int $total, int $page, string $error, string $destination, int $length, ?array $result): void
{
    $path = app_path($config);
    page_start('Linkrövidítő', $path, true);
    ?>
    <div class="page-heading"><div><p class="eyebrow">LINKEK</p><h1>Hosszú helyett rövid.</h1></div><span class="count-badge"><?= number_format($total, 0, ',', ' ') ?> link</span></div>
    <section class="card create-card" aria-labelledby="create-title">
        <div class="card-heading"><span class="section-icon"><?= icon('link') ?></span><h2 id="create-title">Új rövid link</h2></div>
        <?php notice($error); ?>
        <form method="post" action="<?= e($path) ?>" id="shorten-form">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="create">
            <div class="input-grid">
                <div class="field"><label for="url">Eredeti link</label><input id="url" name="url" type="url" placeholder="https://pelda.hu/egy-hosszu-link" value="<?= e($destination) ?>" maxlength="8192" autocomplete="off" spellcheck="false" required aria-describedby="url-hint"><p class="hint" id="url-hint">Illeszd be a rövidíteni kívánt teljes webcímet.</p></div>
                <div class="field length-field"><label for="length">Kód hossza</label><div class="number-input"><input id="length" name="length" type="number" min="3" max="32" step="1" value="<?= $length ?>" required aria-describedby="length-hint"><span>karakter</span></div><p class="hint" id="length-hint">3–32, alapból <?= $config['default_length'] ?></p></div>
            </div>
            <div class="form-bottom"><p class="preview"><span>Így fog kinézni</span><code id="code-preview" data-base="<?= e($config['base_url'] . ($config['query_links'] ? '/index.php?code=' : '/')) ?>"><?= e($config['base_url'] . ($config['query_links'] ? '/index.php?code=' : '/')) ?><strong><?= e(substr('aB3xZ7kP2mQ9nR4sT6vW8yD1eF5gH0jL', 0, $length)) ?></strong></code></p><button class="button" type="submit">Link rövidítése <?= icon('arrow') ?></button></div>
        </form>
    </section>

    <?php if ($result !== null): $url = short_url($config, $result['code']); ?>
    <section class="result-card" aria-labelledby="result-title" tabindex="-1" id="result">
        <div class="result-heading"><?= icon('check') ?><h2 id="result-title">Elkészült a rövid linked</h2></div>
        <div class="result-row"><a class="result-link" href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer"><?= e($url) ?></a><button class="button copy-button" type="button" data-copy="<?= e($url) ?>"><?= icon('copy') ?><span>Másolás</span></button></div>
        <p class="result-destination"><?= e($result['destination']) ?></p>
    </section>
    <?php endif; ?>

    <section class="links-section" aria-labelledby="links-title">
        <div class="list-heading"><h2 id="links-title">Létrehozott linkek</h2><span class="small muted">Legújabbak elöl</span></div>
        <?php if ($links === []): ?>
            <div class="empty-state"><span class="empty-icon"><?= icon('link') ?></span><h3>Itt lesznek a rövid linkjeid</h3><p>Az első linked létrehozásához használd a fenti mezőt.</p></div>
        <?php else: ?>
            <div class="link-list">
            <?php foreach ($links as $link): $url = short_url($config, $link['code']); ?>
                <article class="link-row">
                    <div class="link-content"><a class="short-link" href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer"><?= e($url) ?></a><p class="destination" title="<?= e($link['destination']) ?>"><?= e($link['destination']) ?></p><time class="small muted" datetime="<?= e(str_replace(' ', 'T', $link['created_at'])) ?>Z"><?= e(display_date($link['created_at'])) ?></time></div>
                    <div class="link-actions"><span class="clicks"><strong><?= number_format((int) $link['clicks'], 0, ',', ' ') ?></strong><span>megnyitás</span></span><button class="icon-button" type="button" data-copy="<?= e($url) ?>" aria-label="<?= e($link['code']) ?> link másolása"><?= icon('copy') ?></button></div>
                </article>
            <?php endforeach; ?>
            </div>
            <?php if ($total > 20): ?>
                <nav class="pagination" aria-label="Linklista lapozása">
                    <?php if ($page > 1): ?><a class="button secondary" href="<?= e($path) ?>?page=<?= $page - 1 ?>">Előző</a><?php endif; ?>
                    <span><?= $page ?> / <?= (int) ceil($total / 20) ?>. oldal</span>
                    <?php if ($page * 20 < $total): ?><a class="button secondary" href="<?= e($path) ?>?page=<?= $page + 1 ?>">Következő</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php
    page_end();
}

