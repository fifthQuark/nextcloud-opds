<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
declare(strict_types=1);

$s = $_['settings'];
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?>
<div id="opds-settings" class="section" data-settings="<?php p(json_encode($s)); ?>">
    <h2>OPDS+</h2>
    <p class="settings-hint">
        Serve a folder of e-books as an OPDS catalog that reader apps (KOReader, Moon+ Reader, Thorium, Librera, Aldiko, Foliate …) can browse, sort, search and download from.
    </p>

    <div class="opds-feed-box">
        <label for="opds-feed-url"><strong>Catalog address</strong></label>
        <div class="opds-row">
            <input type="text" id="opds-feed-url" readonly value="<?php echo $e($_['feedUrl']); ?>">
            <button type="button" id="opds-copy-url">Copy</button>
        </div>
        <p class="settings-hint">
            In your reader app add this URL as an OPDS catalog and sign in with your user name
            <code><?php echo $e($_['userId']); ?></code> and an <strong>app password</strong>.
            Create one under <a href="<?php echo $e($_['appPasswordUrl']); ?>">Security → Devices &amp; sessions</a>
            (your normal password works too unless two-factor authentication is enabled, but an app password is safer and can be revoked).
        </p>
    </div>

    <h3>Library</h3>
    <div class="opds-grid">
        <label for="opds-root">Library folder</label>
        <div class="opds-row">
            <input type="text" id="opds-root" name="root" value="<?php echo $e($s['root']); ?>" placeholder="/Books">
            <button type="button" id="opds-pick-folder">Choose…</button>
        </div>

        <label for="opds-extensions">File types</label>
        <div>
            <input type="text" id="opds-extensions" name="extensions" value="<?php echo $e($s['extensions']); ?>">
            <p class="settings-hint">Comma separated list of file extensions to include.</p>
        </div>

        <label for="opds-filename-pattern">File names without metadata</label>
        <div>
            <select id="opds-filename-pattern" name="filename_pattern">
                <option value="author-title" <?php if ($s['filename_pattern'] === 'author-title') { echo 'selected'; } ?>>Author - Title.epub</option>
                <option value="title-author" <?php if ($s['filename_pattern'] === 'title-author') { echo 'selected'; } ?>>Title - Author.epub</option>
                <option value="title" <?php if ($s['filename_pattern'] === 'title') { echo 'selected'; } ?>>Title.epub (whole name is the title)</option>
            </select>
            <p class="settings-hint">Used when a file carries no embedded metadata (plain PDFs, TXT, …).</p>
        </div>
    </div>

    <h3>Catalog</h3>
    <div class="opds-grid">
        <label for="opds-default-sort">Default sort order</label>
        <div class="opds-row">
            <select id="opds-default-sort" name="default_sort">
                <?php foreach ($_['sorts'] as $key => $label): ?>
                    <option value="<?php echo $e($key); ?>" <?php if ($s['default_sort'] === $key) { echo 'selected'; } ?>><?php echo $e($label); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="opds-default-order" name="default_order">
                <option value="asc" <?php if ($s['default_order'] === 'asc') { echo 'selected'; } ?>>Ascending</option>
                <option value="desc" <?php if ($s['default_order'] === 'desc') { echo 'selected'; } ?>>Descending</option>
            </select>
        </div>

        <label for="opds-page-size">Books per page</label>
        <div><input type="number" id="opds-page-size" name="page_size" min="5" max="500" value="<?php echo $e($s['page_size']); ?>"></div>

        <label for="opds-group-threshold">Split long lists into A–Z groups above</label>
        <div><input type="number" id="opds-group-threshold" name="group_threshold" min="10" max="10000" value="<?php echo $e($s['group_threshold']); ?>"> entries</div>

        <label for="opds-scan-interval">Check for new files every</label>
        <div><input type="number" id="opds-scan-interval" name="scan_interval" min="0" max="10080" value="<?php echo $e($s['scan_interval']); ?>"> minutes (0 = only via the button below, occ or the background job)</div>
    </div>

    <div class="opds-actions">
        <button type="button" id="opds-save" class="primary">Save</button>
        <span id="opds-save-status" class="opds-status" aria-live="polite"></span>
    </div>

    <h3>Index</h3>
    <p>
        <strong><?php p((string)$_['bookCount']); ?></strong> books indexed.
        <?php if ($_['lastScan'] > 0): ?>
            Last scan: <?php p(date('Y-m-d H:i', (int)$_['lastScan'])); ?>.
        <?php else: ?>
            Not scanned yet – the first catalog request or the button below will index the folder.
        <?php endif; ?>
        <?php if (is_array($_['report']) && !empty($_['report']['summary'])): ?>
            <br><span class="settings-hint"><?php p((string)$_['report']['summary']); ?></span>
        <?php endif; ?>
    </p>
    <div class="opds-actions">
        <button type="button" id="opds-scan">Scan for new and changed books</button>
        <button type="button" id="opds-rescan">Re-read all metadata</button>
        <button type="button" id="opds-reset">Clear index</button>
        <span id="opds-scan-status" class="opds-status" aria-live="polite"></span>
    </div>
    <p class="settings-hint">
        Large libraries: run <code>occ opds:scan <?php echo $e($_['userId']); ?></code> once on the server; afterwards new files are picked up automatically.
    </p>
</div>
