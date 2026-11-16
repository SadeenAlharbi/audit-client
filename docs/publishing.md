# Publishing `company/audit-client`

The package is internal (proprietary license), so it is **not** published on packagist.org. It is served from a private Composer repository. Three options are described below; pick one per organisation and use it consistently.

**Current setup:** the package lives in the private GitHub repository [SadeenAlharbi/audit-client](https://github.com/SadeenAlharbi/audit-client), released with SemVer tags and GitHub Releases, and consuming projects use it as a `vcs` repository (option B below), with read access granted through GitHub. Satis or another private Composer repository can be added later without any change to the package.

All options rely on git tags: Composer reads the version from the tag (`v1.0.0` → `1.0.0`), so `composer.json` has no `version` field.

## Releasing a version

1. Make sure `main` is green (Pint, PHPStan, Pest in `.github/workflows/tests.yml`).
2. Move the `[Unreleased]` entries in `CHANGELOG.md` under a new version heading with today's date and update the compare links.
3. Choose the version number by [Semantic Versioning](https://semver.org/) (see the "Versioning" section of the README): patch for fixes, minor for backwards-compatible features, major for breaking changes.
4. Commit, tag and push:

   ```bash
   git commit -am "Release 1.1.0"
   git tag -a v1.1.0 -m "v1.1.0"
   git push origin main --tags
   ```

5. Rebuild / notify the private repository (see below) so the new tag is visible to `composer`.

Tags are immutable: never move or delete a published tag; release a new patch version instead.

---

## Option A — Satis (self-hosted static repository)

[Satis](https://github.com/composer/satis) builds a static `packages.json` (and optional zip archives) from your git repositories. Host the output on any internal web server.

An example configuration is in [`docs/satis.json`](satis.json):

```json
{
    "name": "company/packages",
    "homepage": "https://packages.company.internal",
    "repositories": [
        { "type": "vcs", "url": "git@github.com:SadeenAlharbi/audit-client.git" }
    ],
    "require": {
        "company/audit-client": "^1.0"
    },
    "archive": {
        "directory": "dist",
        "format": "zip",
        "skip-dev": true
    },
    "require-dependencies": false,
    "require-dev-dependencies": false,
    "output-dir": "public"
}
```

Build it (on the server, or in a CI job triggered by new tags) with an account that can read the git repository:

```bash
composer create-project composer/satis --stability=dev --keep-vcs /opt/satis
php /opt/satis/bin/satis build satis.json public/
# only rebuild this package after a new tag:
php /opt/satis/bin/satis build satis.json public/ company/audit-client
```

Serve `public/` over HTTPS behind authentication (HTTP basic auth or your SSO proxy). `archive` makes Composer download zips from Satis instead of cloning, which is faster and does not require consumers to have git access.

**Consuming projects** add the repository once:

```bash
composer config repositories.company composer https://packages.company.internal
composer config http-basic.packages.company.internal <user> <token>   # stored in auth.json, never commit it
composer require company/audit-client:^1.0
```

which results in:

```json
"repositories": {
    "company": { "type": "composer", "url": "https://packages.company.internal" }
}
```

---

## Option B — GitHub Packages

GitHub Packages has no Composer registry. With GitHub, use the repository itself as a `vcs` source (Composer reads tags through the GitHub API), or Private Packagist.

**Consuming projects:**

```bash
composer config repositories.audit-client vcs https://github.com/SadeenAlharbi/audit-client
composer config --global github-oauth.github.com <token-with-repo-read>   # or COMPOSER_AUTH in CI
composer require company/audit-client:^1.0
```

```json
"repositories": {
    "audit-client": { "type": "vcs", "url": "https://github.com/SadeenAlharbi/audit-client" }
}
```

In GitHub Actions of a consuming project, give the job a token that can read the repository:

```yaml
- run: composer install --prefer-dist --no-progress
  env:
    COMPOSER_AUTH: '{"github-oauth": {"github.com": "${{ secrets.COMPOSER_GITHUB_TOKEN }}"}}'
```

When more than a few internal packages exist, [Private Packagist](https://packagist.com) (hosted, paid) replaces the per-package `vcs` entries with one `composer` repository and adds access control per team.

---

## Option C — GitLab Package Registry

GitLab has a built-in Composer registry per group.

**Publishing** — after pushing a tag, send it to the registry (manually or from `.gitlab-ci.yml`):

```bash
curl --fail-with-body --header "Job-Token: $CI_JOB_TOKEN" \
     --data tag=v1.0.0 \
     "https://gitlab.company.internal/api/v4/projects/$CI_PROJECT_ID/packages/composer"
```

```yaml
# .gitlab-ci.yml
publish:
  stage: deploy
  rules:
    - if: $CI_COMMIT_TAG
  script:
    - 'curl --fail-with-body --header "Job-Token: $CI_JOB_TOKEN" --data tag=$CI_COMMIT_TAG "${CI_API_V4_URL}/projects/$CI_PROJECT_ID/packages/composer"'
```

**Consuming projects** (`<group-id>` is the numeric id of the GitLab group):

```bash
composer config repositories.gitlab.company.internal/<group-id> '{"type": "composer", "url": "https://gitlab.company.internal/api/v4/group/<group-id>/-/packages/composer/packages.json"}'
composer config gitlab-token.gitlab.company.internal <personal-access-token-with-read_api>
composer config gitlab-domains gitlab.company.internal
composer require company/audit-client:^1.0
```

---

## After installing

Whatever the source, the consuming project then follows the README:

```bash
php artisan vendor:publish --tag=audit-config
php artisan vendor:publish --tag=audit-migrations && php artisan migrate   # database outbox
```

and sets `AUDIT_ENDPOINT`, `AUDIT_PROJECT`, `AUDIT_API_KEY` and `AUDIT_HMAC_SECRET` in `.env`. Credentials for the Composer repository belong in `auth.json` (git-ignored) or the `COMPOSER_AUTH` environment variable, never in `composer.json`.
