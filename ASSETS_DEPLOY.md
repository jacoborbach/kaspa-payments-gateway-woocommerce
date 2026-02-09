# Plugin assets (screenshots, banner, icons) for WordPress.org

The **Screenshots** tab in "View details" (and on the plugin's WordPress.org page) only appears when the image files are in the plugin's **WordPress.org SVN** repository.

## Why the Screenshots tab is missing

"View details" in WordPress admin loads data from WordPress.org. Screenshots are served from the plugin's **assets** folder in that SVN repo. If that folder is missing or the screenshot files were never committed there, the Screenshots section will not show.

## What you need on WordPress.org SVN

In your plugin's SVN checkout (e.g. `https://plugins.svn.wordpress.org/kaspa-payments-gateway-woocommerce/`), the layout must be:

```
kaspa-payments-gateway-woocommerce/
├── assets/                    ← top level, same as trunk and tags
│   ├── screenshot-1.png
│   ├── screenshot-2.png
│   ├── screenshot-3.png
│   ├── screenshot-4.png
│   ├── banner-772x250.png
│   ├── banner-1544x500.png
│   ├── icon-128x128.png
│   └── icon-256x256.png
├── trunk/
│   ├── readme.txt             ← must contain "= Screenshots =" and numbered captions
│   └── ...
└── tags/
    └── ...
```

- **Do not** put screenshots under `trunk/assets/` or `tags/x.x.x/assets/`. They must be in the root **assets/** folder.
- Filenames must be lowercase: `screenshot-1.png`, not `Screenshot-1.png`.
- `trunk/readme.txt` must have a `= Screenshots =` section with one caption per screenshot (1. …, 2. …, etc.). That is already in place.

## Deploy steps

1. Check out (or update) the plugin from WordPress.org SVN:
   ```bash
   svn co https://plugins.svn.wordpress.org/kaspa-payments-gateway-woocommerce/ wp-plugin-svn
   cd wp-plugin-svn
   ```

2. Copy the contents of this repo's **assets** folder into the SVN **assets** folder:
   ```bash
   cp /path/to/kaspa-plugin-svn/assets/screenshot-*.png assets/
   # Copy banner and icon too if you want them updated:
   # cp /path/to/kaspa-plugin-svn/assets/banner-*.png assets/
   # cp /path/to/kaspa-plugin-svn/assets/icon-*.png assets/
   ```

3. Add any new files and commit:
   ```bash
   svn add assets/screenshot-1.png assets/screenshot-2.png assets/screenshot-3.png assets/screenshot-4.png
   svn status
   svn commit -m "Add plugin screenshots for Description and View details"
   ```

4. Wait a few minutes (or up to a few hours when servers are busy). WordPress.org serves assets from a CDN; after it updates, the **Screenshots** tab will appear in "View details" and on the plugin page.

## Optional: set MIME types (if images download instead of display)

If screenshots download instead of showing in the browser, set SVN MIME types and recommit:

```bash
svn propset svn:mime-type image/png assets/*.png
svn commit -m "Set PNG MIME type for assets"
```
