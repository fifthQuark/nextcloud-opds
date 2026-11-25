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

    <p><a class="button" href="<?php echo $e($_['libraryUrl']); ?>">Open the book browser</a></p>

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

        <span class="opds-label">Hidden files</span>
        <div>
            <input type="checkbox" class="checkbox" id="opds-show-hidden" <?php if ($s['show_hidden'] === '1') { echo 'checked'; } ?>>
            <label for="opds-show-hidden">Show hidden files</label>
            <p class="settings-hint">Files and folders whose name starts with a dot, such as <code>.Book.epub</code> or everything in <code>.private</code>.</p>
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

    <?php $n = $_['news']; ?>
    <h3 id="opds-news-heading">News</h3>
    <?php if (!$_['newsInstalled']): ?>
        <p class="settings-hint">
            Install and enable the <strong>News</strong> app to read the day's articles from your feeds as an e-book:
            the catalog then offers a <em>News</em> entry with one part per feed and one chapter per article.
        </p>
    <?php else: ?>
    <div id="opds-news" data-news="<?php p(json_encode($n)); ?>">
        <p class="settings-hint">
            Adds a <em>News</em> entry to the catalog with an EPUB of the latest articles from Nextcloud News:
            each feed becomes a part of the book and each article a chapter.
        </p>
        <p>
            <input type="checkbox" class="checkbox" id="opds-news-enabled" <?php if ($n['enabled']) { echo 'checked'; } ?>>
            <label for="opds-news-enabled">Offer the news e-book in the catalog</label>
        </p>
        <div class="opds-grid" id="opds-news-options">
            <label for="opds-news-period">Articles from</label>
            <div>
                <select id="opds-news-period">
                    <?php foreach ($_['newsPeriods'] as $key => $label): ?>
                        <option value="<?php echo $e($key); ?>" <?php if ($n['period'] === $key) { echo 'selected'; } ?>><?php echo $e($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <label for="opds-news-max">At most</label>
            <div><input type="number" id="opds-news-max" min="1" max="500" value="<?php echo $e((string)$n['max']); ?>"> articles per feed (newest first)</div>

            <span class="opds-label">Options</span>
            <div>
                <input type="checkbox" class="checkbox" id="opds-news-unread" <?php if ($n['unread']) { echo 'checked'; } ?>>
                <label for="opds-news-unread">Only unread articles</label>
                <br>
                <input type="checkbox" class="checkbox" id="opds-news-images" <?php if ($n['images']) { echo 'checked'; } ?>>
                <label for="opds-news-images">Include images (downloaded by the server, larger book)</label>
            </div>

            <span class="opds-label">Feeds</span>
            <div>
                <input type="radio" class="radio" name="opds-news-scope" id="opds-news-all" value="all" <?php if ($n['all']) { echo 'checked'; } ?>>
                <label for="opds-news-all">All feeds</label>
                <br>
                <input type="radio" class="radio" name="opds-news-scope" id="opds-news-some" value="some" <?php if (!$n['all']) { echo 'checked'; } ?>>
                <label for="opds-news-some">Only the folders and feeds selected below</label>
            </div>
        </div>

        <div id="opds-news-tree" class="opds-news-tree">
            <?php if ($_['newsTree']['folders'] === [] && $_['newsTree']['feeds'] === []): ?>
                <p class="settings-hint">You have not subscribed to any feeds in News yet.</p>
            <?php endif; ?>
            <?php foreach ($_['newsTree']['folders'] as $folder): ?>
                <div class="opds-news-folder">
                    <input type="checkbox" class="checkbox opds-news-folder-box" id="opds-news-folder-<?php echo (int)$folder['id']; ?>"
                           data-folder="<?php echo (int)$folder['id']; ?>" <?php if (in_array($folder['id'], $n['folders'], true)) { echo 'checked'; } ?>>
                    <label for="opds-news-folder-<?php echo (int)$folder['id']; ?>"><strong><?php echo $e($folder['name']); ?></strong>
                        <span class="settings-hint">(whole folder, including feeds added later)</span></label>
                    <ul>
                        <?php foreach ($folder['feeds'] as $feed): ?>
                            <li>
                                <input type="checkbox" class="checkbox opds-news-feed-box" id="opds-news-feed-<?php echo (int)$feed['id']; ?>"
                                       data-feed="<?php echo (int)$feed['id']; ?>" data-parent="<?php echo (int)$folder['id']; ?>"
                                       <?php if (in_array($feed['id'], $n['feeds'], true)) { echo 'checked'; } ?>>
                                <label for="opds-news-feed-<?php echo (int)$feed['id']; ?>"><?php echo $e($feed['title']); ?></label>
                            </li>
                        <?php endforeach; ?>
                        <?php if ($folder['feeds'] === []): ?>
                            <li class="settings-hint">No feeds in this folder</li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
            <?php if ($_['newsTree']['feeds'] !== []): ?>
                <div class="opds-news-folder">
                    <strong><?php echo $_['newsTree']['folders'] === [] ? 'Feeds' : 'Feeds without a folder'; ?></strong>
                    <ul>
                        <?php foreach ($_['newsTree']['feeds'] as $feed): ?>
                            <li>
                                <input type="checkbox" class="checkbox opds-news-feed-box" id="opds-news-feed-<?php echo (int)$feed['id']; ?>"
                                       data-feed="<?php echo (int)$feed['id']; ?>"
                                       <?php if (in_array($feed['id'], $n['feeds'], true)) { echo 'checked'; } ?>>
                                <label for="opds-news-feed-<?php echo (int)$feed['id']; ?>"><?php echo $e($feed['title']); ?></label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>

        <div class="opds-actions">
            <button type="button" id="opds-news-save" class="primary">Save news settings</button>
            <span id="opds-news-status" class="opds-status" aria-live="polite"></span>
        </div>
    </div>
    <?php endif; ?>

    <div id="opds-calibre" data-enabled="<?php echo $_['calibre'] ? '1' : '0'; ?>">
        <h3>Calibre libraries <span class="opds-beta">Beta</span></h3>
        <p class="settings-hint">
            For folders with a Calibre database (<code>metadata.db</code>): use what Calibre knows about the books, and edit titles,
            authors, series, tags, covers and more in the book browser. Edits change <code>metadata.db</code> and rename the folders
            and files of the book the way Calibre does. Close Calibre and let your files synchronise before editing here.
        </p>
        <?php if (!$_['calibreAvailable']): ?>
            <p class="settings-hint">Not available: this server has no SQLite support for PHP (sqlite3 or pdo_sqlite).</p>
        <?php else: ?>
        <p>
            <input type="checkbox" class="checkbox" id="opds-calibre-enabled" <?php if ($_['calibre']) { echo 'checked'; } ?>>
            <label for="opds-calibre-enabled">Use and edit Calibre libraries</label>
        </p>
        <div id="opds-calibre-more" <?php if (!$_['calibre']) { echo 'hidden'; } ?>>
            <div id="opds-calibre-list"></div>
            <h4>Make the current library folder a Calibre library</h4>
            <p class="settings-hint">
                Adds a Calibre database to the library folder and moves the book files into <em>Author/Title</em> folders inside it.
                Nothing is copied, so every book is listed once and no extra space is needed. Folders that become empty are
                removed. If the folder is a Calibre library already, only books that Calibre does not know yet are added.
            </p>
            <div class="opds-row">
                <button type="button" id="opds-calibre-convert">Convert the current folder</button>
            </div>
            <h4>Copy the books into a new Calibre library</h4>
            <p class="settings-hint">
                Copies all books of the current library folder into a new folder with a Calibre database, sorted into
                <em>Author/Title</em> folders. The originals are not changed, so this needs the same space once more.
            </p>
            <div class="opds-row">
                <input type="text" id="opds-calibre-path" placeholder="/Calibre Library" aria-label="Folder for the new library">
                <button type="button" id="opds-calibre-create">Create and copy books</button>
            </div>
        </div>
        <p><span id="opds-calibre-status" class="opds-status" aria-live="polite"></span></p>
        <button type="button" id="opds-calibre-use" hidden>Use the new library from now on</button>
        <?php endif; ?>
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
