WordPress.org plugin-page assets for PostCaster.

Files in this folder:

- `banner-1544x500.png`: high-resolution plugin page header banner
- `banner-772x250.png`: standard-resolution plugin page header banner
- `banner-source-postcaster.png`: uncropped source render used for the derived banners
- `icon-256x256.png`: high-resolution WordPress.org plugin icon
- `icon-128x128.png`: standard-resolution WordPress.org plugin icon
- `icon-source-postcaster.png`: square source crop used for the derived icons

WordPress.org expects the final banner and icon files in the plugin SVN repository's top-level `/assets/` directory, using these exact filenames.

Release flow:

1. Update the asset source files in this folder when needed.
2. Push the release commit to `master`.
3. Let the GitHub release workflow copy the files into the WordPress.org SVN `assets/` directory during deployment.

This folder is excluded from the plugin distribution zip through `.distignore`, so the WordPress.org page assets stay separate from the shipped plugin code.

GitHub Actions release automation:

- A push to `master` creates a missing `v<version>` tag automatically when `postcaster.php` and `readme.txt` agree on the release version.
- The tag-triggered release workflow builds the plugin zip, creates the GitHub release, and deploys the same build to WordPress.org SVN.
- Configure the repository secrets `WPORG_USERNAME` and `WPORG_PASSWORD` before using the automated SVN deploy.
