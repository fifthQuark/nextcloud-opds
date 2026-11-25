# Publishing OPDS+ to the Nextcloud App Store

Once the app is in the [App Store](https://apps.nextcloud.com) it can be installed from
**Apps** in the Nextcloud web UI of any instance, including managed ones such as Hetzner
Storage Share where you cannot upload files or run `occ`.

Everything below is a one-time setup except the last section. Nothing here can be done by
a script on your behalf: the certificate and the store account are tied to you.

## 1. Put the code on GitHub

```bash
make set-owner OWNER=<your-github-user>      # replaces the OWNER placeholder in info.xml etc.
git add -A && git commit -m "Set repository owner"
```

Create an empty **public** repository named `nextcloud-opds` on GitHub, then:

```bash
git remote add origin git@github.com:<your-github-user>/nextcloud-opds.git
git push -u origin main
```

(If you prefer another repository name, change the URLs in `appinfo/info.xml` accordingly.)

## 2. Get a signing certificate from Nextcloud

Nextcloud only installs store apps whose files are signed with a certificate issued by
Nextcloud for that app id. The procedure is documented in the
[App Store developer guide](https://nextcloudappstore.readthedocs.io/en/latest/developer.html#obtaining-a-certificate)
and the server manual's [code signing page](https://docs.nextcloud.com/server/latest/developer_manual/app_publishing_maintenance/code_signing.html).
In short:

```bash
mkdir -p ~/.nextcloud/certificates && cd ~/.nextcloud/certificates
openssl req -nodes -newkey rsa:4096 -keyout opds.key -out opds.csr -subj "/CN=opds"
```

Keep `opds.key` **private**, never commit it, and back it up (a lost key means a new
certificate request). Then:

1. Make sure your e-mail address is visible on your GitHub profile (the docs ask for this
   so the maintainers can verify you).
2. Fork <https://github.com/nextcloud/app-certificate-requests>, add the file
   `opds/opds.csr` (a folder named after the app id, containing the CSR) and open a pull
   request against the upstream repository. Put the link to your public app repository,
   `https://github.com/fifthQuark/nextcloud-opds`, in the PR description.
3. A Nextcloud maintainer verifies the request (they may ask questions in the PR) and posts
   the signed certificate as `opds/opds.crt` in that repository. This is manual and usually
   takes a few days.
4. Save it as `~/.nextcloud/certificates/opds.crt`, next to the key.

The app id `opds` is reserved for your account by this certificate. If the id is already
taken, the maintainers will tell you; change `<id>` in `appinfo/info.xml`, the folder
name, the `namespace` stays `Opds`, and the routes keep working.

## 3. Register the app in the App Store

1. Create an account at <https://apps.nextcloud.com/register> and confirm the e-mail.
2. Open <https://apps.nextcloud.com/developer/apps/new>, paste the content of `opds.crt`
   into *Certificate* and, into *Signature*, the signed app id generated with:

   ```bash
   echo -n "opds" | openssl dgst -sha512 -sign ~/.nextcloud/certificates/opds.key | openssl base64 -A
   ```

3. Create an API token at <https://apps.nextcloud.com/account/token> (needed for the
   automated release workflow).

## 4. Configure GitHub Actions

In the GitHub repository go to **Settings → Secrets and variables → Actions** and add:

| Secret | Value |
| --- | --- |
| `APP_PRIVATE_KEY` | full content of `opds.key` |
| `APP_CERTIFICATE` | full content of `opds.crt` |
| `APPSTORE_TOKEN` | the API token from step 3 |

## 5. Release

Bump `<version>` in `appinfo/info.xml` and `CHANGELOG.md`, commit, then tag and push:

```bash
git tag v1.0.0 && git push origin main v1.0.0
```

The *Release* workflow first runs the tests (`tests/`, see `test.yml`) and publishes nothing if one
fails. Then it lints the code, signs the files with `occ integrity:sign-app` inside
a Nextcloud container, builds `opds-1.0.0.tar.gz`, attaches it to a GitHub release and
registers the release with the App Store. A few minutes later the app shows up in the store
and in the **Apps** page of every Nextcloud whose version is within the `min-version` /
`max-version` range in `info.xml` (currently 28–35). Check <https://apps.nextcloud.com/apps/opds>.

### Releasing by hand instead

```bash
make release KEY=opds.key CERT=opds.crt
```

builds `build/opds-<version>.tar.gz` plus `build/opds-<version>.tar.gz.sig`. Upload the
tarball to a GitHub release (or any https URL, max. 20 MB), then open
<https://apps.nextcloud.com/developer/apps/releases/new>, paste the download URL and the
content of the `.sig` file.

## Notes for Hetzner Storage Share

* Storage Share admins install apps from the App Store under **Apps → Multimedia** (or by
  searching "OPDS+"). The Nextcloud version there must be inside the app's supported range;
  when Hetzner upgrades to a newer major version, raise `max-version` in `info.xml` and
  publish a new release.
* There is no shell, so indexing happens from the settings page button, automatically on
  catalog requests (20 seconds at a time) and through the background job that Hetzner's
  cron runs. For a library with thousands of books press the scan button a few times or
  simply open the catalog once in a while until the settings page reports a complete scan.
* PDF covers depend on Hetzner enabling the PDF preview provider; everything else works
  without server configuration.

## Keeping the app in good standing

* Each new Nextcloud major version needs a release with a raised `max-version`; the store
  hides apps that do not support the viewer's server version.
* The App Store rejects a release when the tag, `info.xml` version or signature do not
  match. The workflow checks the first two before building.
