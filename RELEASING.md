# Releasing PostCaster

This repository is set up so a release starts from a normal push to `master`.

## One-time setup

Configure these GitHub repository secrets:

- `WPORG_USERNAME`
- `WPORG_PASSWORD`

GitHub path:

`Repository > Settings > Secrets and variables > Actions`

These credentials are used by the release workflow to commit the built plugin to the WordPress.org SVN repository.

Also make sure GitHub Actions is enabled for the repository.

## What triggers a release

A release is created when:

1. you update the plugin version in `postcaster.php`
2. you update `Stable tag` in `readme.txt` to the same version
3. you push that commit to `master`

If the version is new, GitHub Actions will:

1. create and push tag `v<version>`
2. build the plugin zip from the current repository state
3. create a GitHub release for that tag
4. deploy the same build to WordPress.org SVN:
   `trunk/`
   `tags/<version>/`
   `assets/`

## Release checklist

Before pushing to `master`:

1. Update `Version:` in [postcaster.php](C:/www/prive/postcaster/postcaster.php:6)
2. Update `Stable tag:` in [readme.txt](C:/www/prive/postcaster/readme.txt:7)
3. Update the changelog in [readme.txt](C:/www/prive/postcaster/readme.txt:132)
4. If needed, update the WordPress.org page assets in [assets-wporg/README.md](C:/www/prive/postcaster/assets-wporg/README.md:1)
5. Commit and push to `master`

## Expected flow

After the push to `master`:

1. `Tag Release On Master` runs
2. it creates `v<version>` if that tag does not already exist
3. the tag triggers `Release Plugin`
4. that workflow builds `dist/postcaster-v<version>.zip`
5. it creates the GitHub release
6. it commits the release to the WordPress.org SVN plugin repo

## Notes

- If tag `v<version>` already exists, no new tag is created.
- If `postcaster.php` and `readme.txt` do not agree on the version, the tag workflow fails.
- The distribution zip is built by [bin/build-release.php](C:/www/prive/postcaster/bin/build-release.php:1).
- WordPress.org SVN deployment is handled by [bin/deploy-wporg.sh](C:/www/prive/postcaster/bin/deploy-wporg.sh:1).
- WordPress.org assets come from `assets-wporg/` and are copied into SVN `assets/` during release.

## Troubleshooting

If the release fails:

- check the Actions tab in GitHub for the failing workflow
- verify `WPORG_USERNAME` and `WPORG_PASSWORD`
- verify the WordPress.org plugin slug is correct in the workflow/script
- verify the version in `postcaster.php` matches `Stable tag` in `readme.txt`
- verify the tag does not already point to the wrong commit
