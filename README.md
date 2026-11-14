# OPDS Catalog for Nextcloud

Turns a folder in your Nextcloud into an [OPDS 1.2](https://specs.opds.io/opds-1.2) catalog that
e-reader apps can browse, sort, search and download from. Readers sign in with a Nextcloud
**user name and an app password**.

* **Metadata** is read from the files themselves: EPUB 2/3 (including Calibre series tags),
  PDF (Info dictionary and XMP), CBZ/CBR (`ComicInfo.xml`), MOBI/AZW/AZW3 (EXTH headers),
  FB2 and DOCX/ODT. Files without metadata fall back to the file name (`Author - Title.ext`).
* **Browse** by author, title (A–Z), series, genre/subject, publisher, language, publication
  year, format or folder; full-text search over title, author, subjects, series and publisher.
* **Sort** every book list by title, author, publication date, date added, date modified,
  publisher, series, language, format or file size, ascending or descending. Sort options are
  exposed as OPDS facets, so readers that support facets show them as a menu; every option is
  also a plain URL parameter.
* **Covers** are extracted from EPUB, CBZ, MOBI, FB2 and DOCX/ODT files and served as OPDS
  images and thumbnails. PDF covers use Nextcloud's preview generator when the PDF preview
  provider is enabled on the server.
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
3. In **Settings → Personal → OPDS Catalog** choose the library folder (default `/Books`),
   optionally adjust the file types, default sort order and page size, and press *Save*.
4. Press *Scan for new and changed books* (or run `occ opds:scan <user>` for large libraries).
5. Create an app password under **Settings → Security → Devices & sessions**.

No build step is needed; the settings page uses plain JavaScript.

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
| Group lists | `/apps/opds/group/{author,title,series,subject,publisher,language,year,format}` | Lists with counts; long lists are split into A–Z groups (`?letter=A`) |
| Folders | `/apps/opds/folders?path=/Books/SF` | Sub folders plus the books in that folder |
| Download | `/apps/opds/download/{fileId}/{filename}` | Streams the file with the right MIME type |
| Cover / thumbnail | `/apps/opds/cover/{fileId}`, `/apps/opds/thumb/{fileId}` | |

Parameters accepted by `/books` (and `/search`, `/folders` where they make sense):

| Parameter | Values |
| --- | --- |
| `sort` | `title`, `author`, `date` (publication date), `added`, `modified`, `publisher`, `series`, `language`, `format`, `size` |
| `order` | `asc`, `desc` (defaults to `desc` for `added`, `modified` and `date`) |
| `page` | 1-based page number; page size is a per-user setting |
| `author`, `subject`, `publisher`, `series`, `language`, `format`, `year`, `letter`, `folder`, `q` | Filters; several can be combined |

Example: `/apps/opds/books?subject=Fantasy&sort=date&order=desc` lists fantasy books, newest
publication first.

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
`group_threshold`, `scan_interval` (minutes, `0` disables request-triggered scans).

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
  the initial import of a large library, or press the scan button in the settings.
* **No PDF covers.** Add `OC\Preview\PDF` to `enabledPreviewProviders` in `config.php` and
  make sure Imagick with Ghostscript is installed.
* **Wrong title/author for a file.** The app only reads what is embedded in the file; fix the
  metadata with Calibre (or rename the file for formats without metadata) and the next scan
  picks it up because the file's ETag changes.

## License

AGPL-3.0-or-later.
