# Changelog

## 1.1.0 – 2026-11-20

* News digest: when the Nextcloud News app is installed, the catalog gets a *News* entry that
  offers the latest articles as an EPUB, with one part per feed and one chapter per article.
  The personal settings choose all feeds or specific folders and feeds, the time span (last
  24 hours, since midnight, 48 hours or 7 days), the number of articles per feed, unread only,
  and whether images are embedded. Editions are cached and prepared in the background for
  people who use them.
* Renamed the app from "OPDS Catalog" to "OPDS+". The app id (`opds`), feed URLs and saved
  settings are unchanged, so existing installs and reader logins keep working.

## 1.0.0 – 2026-11-14

* Initial release: OPDS 1.2 navigation and acquisition feeds with Basic (app password)
  authentication, metadata extraction for EPUB, PDF, CBZ/CBR, MOBI/AZW, FB2 and DOCX/ODT,
  cover extraction, incremental indexing (request, background job, occ), personal settings page.
