# OPDS+ for Nextcloud

Turns a folder in your Nextcloud into an [OPDS 1.2](https://specs.opds.io/opds-1.2) catalog that
e-reader apps can browse, sort, search and download from. Readers sign in with a Nextcloud
**user name and an app password**.

* **Metadata** is read from the files themselves: EPUB 2/3 (including Calibre series tags),
  PDF (Info dictionary and XMP), CBZ/CBR (`ComicInfo.xml`), MOBI/AZW/AZW3 (EXTH headers),
  FB2 and DOCX/ODT. Files without metadata fall back to the file name (`Author - Title.ext`).
* **Browse** by author, title (A–Z), series, genre/subject, publisher, language, publication
  year, format or folder; authors open to their series; full-text search over title, author, subjects, series and publisher.
* **Sort** every book list by title, author, publication date, date added, date modified,
  publisher, series, language, format or file size, ascending or descending. Sort options are
  exposed as OPDS facets, so readers that support facets show them as a menu; every option is
  also a plain URL parameter.
* **Covers** are extracted from EPUB, CBZ, MOBI, FB2 and DOCX/ODT files and served as OPDS
  images and thumbnails. PDF covers use Nextcloud's preview generator when the PDF preview
  provider is enabled on the server.
* **News digest** (optional, needs the [News](https://apps.nextcloud.com/apps/news) app): a
  *News* entry offers the latest articles of your feeds as an EPUB. See [News digest](#news-digest).
* **Book browser** inside Nextcloud: an *OPDS+* entry in the app menu shows the same library as
  a page with covers, search and filters. See [Book browser](#book-browser).
* **Calibre libraries** (beta, off by default): use and edit the metadata and covers of a Calibre
  library. See [Calibre libraries](#calibre-libraries-beta).
* **Hidden files** (files and folders whose name starts with a dot) are left out unless *Show
  hidden files* is turned on in the settings.
* The index lives in two database tables and is refreshed incrementally: on catalog requests
  (at most every *n* minutes, with a time budget so requests stay fast), by a background job
  every six hours, from the settings page, or with `occ opds:scan`.

## Requirements

* Nextcloud 28 – 35, PHP 8.1+ with the `zip`, `dom`, `xmlwriter` and `mbstring` extensions
  (all required by Nextcloud itself). `intl` improves accent-insensitive sorting, `gd` or
  `imagick` enables cover resizing, the `rar` extension enables CBR metadata.
* Pretty URLs are not required; the catalog is served under `/index.php/apps/opds/` as well.

## Installation

1. Copy (or clone) this directory to `<nextcloud>/apps/opds` or `<nextcloud>/custom_apps/opds`
   and make sure the web server user can read it.
2. Enable it: `occ app:enable opds` (or Apps → Disabled apps in the web UI).
3. In **Settings → Personal → OPDS+** choose the library folder (default `/Books`),
   optionally adjust the file types, default sort order and page size, and press *Save*.
4. Press *Scan for new and changed books* (or run `occ opds:scan <user>` for large libraries).
5. Create an app password under **Settings → Security → Devices & sessions**.

No build step is needed; the settings page and the book browser use plain JavaScript.

## Connecting a reader

Add the catalog URL shown on the settings page (`https://cloud.example.com/apps/opds/`) as an
OPDS catalog in your reader and enter your Nextcloud user name and the app password when asked.
Nextcloud authenticates the request with HTTP Basic authentication exactly like WebDAV does, so
any app password works and two-factor authentication is not a problem. Your account password
works as well unless 2FA is enabled, but an app password can be revoked individually and is
the recommended choice.

Known to work with the usual OPDS clients: KOReader, Moon+ Reader, Librera, Thorium Reader,
Aldiko, PocketBook, Foliate, Cantook and Calibre's "Get books" (via an OPDS plugin).

## Catalog structure and URL parameters

| Feed | URL | Notes |
| --- | --- | --- |
| Root navigation | `/apps/opds/` | Entry points for everything below |
| All books / filtered lists | `/apps/opds/books` | Acquisition feed, paginated |
| Search | `/apps/opds/search?q=term` | Also advertised through OpenSearch |
| Group lists | `/apps/opds/group/{author,title,series,subject,publisher,language,year,format,rating}`, `/apps/opds/group/column-{label}` for a custom column of Calibre | Lists with counts; long lists are split into A–Z groups (`?letter=A`), nested lists open with `?parent=Fiction` |
| Author | `/apps/opds/author?name=…` | All books, each series and the books outside of any series |
| Folders | `/apps/opds/folders?path=/Books/SF` | Sub folders plus the books in that folder |
| Download | `/apps/opds/download/{fileId}/{filename}` | Streams the file with the right MIME type |
| Cover / thumbnail | `/apps/opds/cover/{fileId}`, `/apps/opds/thumb/{fileId}` | |
| News | `/apps/opds/news` | The current news edition (only when News is installed and the digest is on) |
| News EPUB | `/apps/opds/news/download/{filename}` | Builds the edition, or serves the cached copy |

Parameters accepted by `/books` (and `/search`, `/folders` where they make sense):

| Parameter | Values |
| --- | --- |
| `sort` | `title`, `author`, `date` (publication date), `added`, `modified`, `publisher`, `series`, `language`, `format`, `size`, `rating` |
| `order` | `asc`, `desc` (defaults to `desc` for `added`, `modified`, `date` and `rating`) |
| `page` | 1-based page number; page size is a per-user setting |
| `standalone` | `1` lists only books that belong to no series |
| `author`, `subject`, `publisher`, `series`, `language`, `format`, `year`, `letter`, `folder`, `q` | Filters; several can be combined |
| `rating` | 1 to 10, which is twice the number of stars |
| `column`, `value` | Books with this value in a custom column of Calibre |

Example: `/apps/opds/books?subject=Fantasy&sort=date&order=desc` lists fantasy books, newest
publication first.

## News digest

When the Nextcloud News app is installed and enabled, the catalog root shows a **News** entry.
It offers one book, *News – Friday 20 November 2026*, with the latest articles from your feeds:

* each feed is a **part** of the book, each article a **chapter** (title, feed, author, time,
  the article text and a link to the original);
* feeds without a folder come first, then folder by folder, alphabetically; articles are
  newest first;
* the table of contents is nested (feed → article) in both the EPUB 3 navigation and the
  EPUB 2 NCX, so older readers show it too.

Settings → Personal → OPDS+ → *News* controls:

| Setting | Choices |
| --- | --- |
| Offer the news e-book | on (default) / off |
| Articles from | last 24 hours (default), today since midnight, last 48 hours, last 7 days |
| At most | articles per feed, newest first (default 30) |
| Only unread articles | off by default; nothing is marked as read by downloading |
| Include images | on by default; images are downloaded by the server, converted to JPEG/PNG/GIF at most 1200 px wide and embedded |
| Feeds | all feeds, or selected **folders** (including feeds added to them later) and/or individual **feeds** |

The edition only changes when its articles change, and built books are cached in the app
data folder. When a reader asks for a book that is not built yet, the server spends at most
about ten seconds on images so that readers do not time out; a background job then completes
the book every 15 minutes for people who downloaded an edition in the last two weeks.
Downloaded images are kept for seven days so new editions only fetch new pictures. The server
fetches images through Nextcloud's HTTP client, which refuses local network addresses unless
the administrator allowed them.

## Book browser

Open *OPDS+* in the app menu of Nextcloud. It shows the library folder as covers, can be browsed
by author (with their series), series, genre, publisher, language, year and format, searched and
sorted, and works on phones. A book opens with its description, download buttons and a link to
its file in Files. Files of the same name in one folder (`Emma.epub` and `Emma.pdf`) are shown as
one book with several formats, here and in the OPDS catalog.

## Calibre libraries (beta)

Turn on *Use and edit Calibre libraries* in Settings → Personal → OPDS+. The server needs PHP's
`sqlite3` or `pdo_sqlite` extension. It applies to every folder with a `metadata.db` in or below
the library folder:

* **Reading:** the title, authors, series, tags, cover and so on that Calibre has stored replace
  what is inside the book files, in the browser and in the OPDS catalog. Changes made in Calibre
  are picked up by the next scan. Turning the option off goes back to the metadata in the files.
* **More from Calibre:** ratings, all languages, identifiers, the sort names of authors and custom
  columns are shown. Ratings and columns of the types text, fixed values, series, yes/no and rating
  get a list of their own in the browser and the OPDS catalog; columns built from a template are not
  available. Tags and text columns that Calibre shows as a hierarchy (`Fiction.Fantasy`, see
  *Preferences → Look & feel → Tag browser*) are nested here as well.
* **Downloads:** an EPUB or kepub that is downloaded or opened in a reader is a copy with the
  title, authors, series, tags, publisher, date, languages, rating, description, identifiers and
  cover from Calibre. Only what differs is written, the stored file is never changed, and the same
  record always gives the same file. Sent as they are: other formats, files over 200 MB, signed or
  copy-protected books, and files whose package document cannot be changed safely. Custom columns
  are not written. Turn it off with *Put the metadata and cover from Calibre into EPUBs*; an app that
  recognises a book by its file sees a new book after an edit.
* **Files:** the formats of a book are those listed in Calibre. Other files in its folder, such as
  those in `data`, are not books of their own and left out.
* **Editing:** a book gets an *Edit* button for title, authors, series, tags, publisher, date,
  languages, rating, description, identifiers, sort names and the cover (upload, take from the
  book file, or remove). A change updates `metadata.db`, `cover.jpg` and `metadata.opf`, and renames
  the folder and files to `Author/Title (id)/Title - Author.ext` when the title or first author
  changes. The stored book files are never changed inside.
* **Current folder:** *Convert the current folder* turns the library folder itself into a Calibre
  library: it gets a `metadata.db`, and the book files are moved into `Author/Title (id)` folders
  inside it. Nothing is copied, reader links keep working, and folders that become empty are
  removed. If the folder is a Calibre library already, books that Calibre does not know are added.
  Left where they are: hidden files, files in folders shared with you or on external storage, files you may not
  move, books in other Calibre libraries below the folder, extra files in the folder of a book, and
  second files of the same format. It can be interrupted and continued.
* **New library:** *Create and copy books* makes a Calibre library in a new or empty folder
  and copies all books of the current library folder into it. The originals stay as they are.

How your library is protected: a copy of the database is changed, checked and only then written
back, and nothing is written if the original changed in the meantime, if Calibre has it open
(`metadata.db-journal`), if there is a conflict copy of it, or if its version is unknown (Calibre 4
to 9 are supported). Moved
files are moved back when anything fails. At most once an hour a backup of `metadata.db` is
made before an edit; the last ones can be downloaded from the settings page. `metadata.opf` is kept up to date, so
*Library maintenance → Restore database* in Calibre can rebuild everything.

**Do not edit in Calibre and here at the same time.** If Calibre works on a synchronised copy of
the folder, close it and let the files synchronise before editing here, and the other way round;
otherwise the sync client will report a conflict on `metadata.db`. Where the conflict copy reaches
the server, as with Syncthing, nothing can be edited until you have chosen a version in Calibre and
deleted the copy; the Nextcloud client usually keeps it on the computer, so look there as well. Deleting books,
editing custom columns and author notes remain tasks for Calibre. Custom columns are kept.

## Server-side tools

```bash
occ opds:scan                 # index every user that configured a library
occ opds:scan alice           # one user
occ opds:scan alice --full    # re-read metadata of every file
occ opds:scan alice --reset   # drop the index and covers first
occ user:setting alice opds root /Library   # set the folder without the web UI
```

Settings keys (per user, app id `opds`): `root`, `extensions`, `filename_pattern`
(`author-title`, `title-author`, `title`), `default_sort`, `default_order`, `page_size`,
`group_threshold`, `scan_interval` (minutes, `0` disables request-triggered scans), `show_hidden`
(`0` or `1`; takes effect with the next scan), `calibre_embed` (`1` by default, `0` sends EPUBs unchanged).

## How metadata is mapped

| Format | Title | Authors | Series | Subjects | Publisher / date / language | Cover |
| --- | --- | --- | --- | --- | --- | --- |
| EPUB | `dc:title` (main title if several) | `dc:creator` with role `aut` (or all), `file-as` honoured | `calibre:series` / `belongs-to-collection` | `dc:subject` (split on `;` and `/`) | `dc:publisher`, `dc:date` (publication event preferred), `dc:language` | `cover-image` property, `meta name="cover"`, or an image named *cover* |
| PDF | `/Title` or XMP `dc:title` (junk like "Microsoft Word - x.docx" ignored) | `/Author` or XMP `dc:creator` | – | `/Keywords`, XMP `dc:subject` | XMP publisher, `/CreationDate` | Nextcloud preview (if enabled) |
| CBZ / CBR | `Title`, else `Series #Number` | `Writer` (else Penciller…) | `Series` + `Number` | `Genre`, `Tags` | `Publisher`, `Year/Month/Day`, `LanguageISO` | First page or a page named *cover* |
| MOBI / AZW | EXTH 503 or full name | EXTH 100 (`Last, First` normalised) | – | EXTH 105 | EXTH 101 / 106 / 524 | EXTH 201 cover record |
| FB2 | `book-title` | `author` elements | `sequence` | `genre` (codes translated) | `publisher`, `date`/`year`, `lang` | `coverpage` binary |
| DOCX / ODT | `dc:title` | `dc:creator` | – | keywords | created date, language | embedded thumbnail |
| Anything else | file name | file name (`Author - Title`) | – | – | `(Year)` suffix | – |

Several people in one author field are separated at `&`, `;` and ` / `, and at commas and *and*,
*with*, *und*, *et* when every part is a full name ("Le Guin, Ursula K." stays one person).

Sorting ignores case, accents and leading articles (*The*, *A*, *Der*, *Le*, *El*, …) and
sorts people by last name.

## Troubleshooting

* **The reader keeps asking for credentials.** Make sure the `Authorization` header reaches
  PHP. Nextcloud's shipped `.htaccess` handles this for Apache; with nginx/php-fpm follow the
  official Nextcloud nginx configuration (`fastcgi_pass_header Authorization` is not needed
  there, but a reverse proxy in front must forward the header). Repeated failures trigger
  Nextcloud's brute-force protection, so wait a while after fixing the password.
* **New files do not show up.** Request-triggered scans run at most every *scan_interval*
  minutes and stop after 20 seconds, continuing on the next request. Run `occ opds:scan` for
  the initial import of a large library, or press the scan button in the settings. Files and
  folders whose name starts with a dot need *Show hidden files*.
* **No PDF covers.** Add `OC\Preview\PDF` to `enabledPreviewProviders` in `config.php` and
  make sure Imagick with Ghostscript is installed.
* **Wrong title/author for a file.** The app only reads what is embedded in the file; fix the
  metadata with Calibre (or rename the file for formats without metadata) and the next scan
  picks it up because the file's ETag changes. Books in a Calibre library can also be edited
  in Nextcloud, see [Calibre libraries](#calibre-libraries-beta).

## Tests

`tests/` needs Python 3 and docker or podman, and starts throwaway Nextcloud servers:

```bash
NEXTCLOUD=34 python3 tests/test_calibre.py                  # with the Calibre from the PATH, or CALIBRE=/opt/calibre
NEXTCLOUD=34 DATABASE=mysql python3 tests/test_upgrades.py  # from every release; sqlite, mysql or pgsql
```

GitHub runs both for every push to `main`, with Calibre 4 to 9 and Nextcloud 28 to 35, and before
every release.

## License

AGPL-3.0-or-later. `resources/calibre-metadata.sql` is the database schema of
[Calibre](https://calibre-ebook.com), © Kovid Goyal, GPL-3.0 (`resources/COPYING.GPL-3.0`).
