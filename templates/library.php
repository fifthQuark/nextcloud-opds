<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
declare(strict_types=1);

$views = [
    ['home', 'All books'],
    ['recent', 'Recently added'],
    ['author', 'Authors'],
    ['series', 'Series'],
    ['subject', 'Genres'],
    ['publisher', 'Publishers'],
    ['language', 'Languages'],
    ['year', 'Years'],
    ['format', 'Formats'],
];
?>
<div id="opds-library">
    <nav class="ol-nav" aria-label="Library">
        <ul>
            <?php foreach ($views as [$id, $label]): ?>
                <li><a href="#/<?php p($id); ?>" data-view="<?php p($id); ?>"><?php p($label); ?></a></li>
            <?php endforeach; ?>
        </ul>
        <div class="ol-nav-foot">
            <button type="button" id="ol-add" hidden>Add books…</button>
            <button type="button" id="ol-refresh">Check for new books</button>
            <a id="ol-settings" href="#">Settings</a>
        </div>
    </nav>
    <div class="ol-main">
        <div class="ol-bar">
            <form id="ol-search-form" role="search">
                <input type="search" id="ol-search" placeholder="Search books" aria-label="Search books" autocomplete="off">
            </form>
            <div class="ol-sort" id="ol-sort">
                <label for="ol-sort-by">Sort by</label>
                <select id="ol-sort-by"></select>
                <button type="button" id="ol-order" aria-label="Reverse the order"></button>
            </div>
        </div>
        <div class="ol-scroll" id="ol-scroll" tabindex="-1">
            <div class="ol-head">
                <h2 id="ol-title"></h2>
                <span id="ol-count" aria-live="polite"></span>
            </div>
            <p id="ol-message" class="ol-message" role="status" hidden></p>
            <div id="ol-content"></div>
            <div id="ol-more" class="ol-more" hidden><button type="button">Show more</button></div>
        </div>
    </div>
    <dialog id="ol-dialog" class="ol-dialog" aria-labelledby="ol-dialog-title"></dialog>
</div>
