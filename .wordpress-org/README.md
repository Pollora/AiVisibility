# WordPress.org directory assets

The images the plugin directory shows on its listing page. **Not** the plugin's
own media — those live in `/assets/`, ship inside the zip, and are served to
every site that installs the plugin. Nothing in this directory is ever
downloaded by a site.

The two are easy to confuse because WordPress.org calls its visuals "assets"
and the WordPress convention calls a plugin's CSS and JS "assets" too. They sit
at different levels of the SVN repository:

| Here, in Git | Lands in SVN at | Shipped to sites |
|---|---|---|
| `/.wordpress-org` | `/assets` (repository root) | no |
| `/assets` | `/trunk/assets` | yes |

`.gitattributes` marks this directory `export-ignore`, so `git archive` — and
therefore the release workflow — leaves it out of the distributed zip.

## Expected files

| File | Size | Format |
|---|---|---|
| `icon-128x128.png` | 128 × 128 | PNG or JPG |
| `icon-256x256.png` | 256 × 256 | PNG or JPG, exactly 2× the above |
| `icon.svg` | square | SVG — an alternative to both PNGs |
| `banner-772x250.png` | 772 × 250 | PNG or JPG |
| `banner-1544x500.png` | 1544 × 500 | PNG or JPG, exactly 2× the above |
| `screenshot-1.png` … | free, but identical across all of them | PNG or JPG |

Names are exact and lowercase; `Banner-772x250.PNG` is silently ignored. Use
sRGB — a JPG in Adobe RGB renders washed out in the browser. No animation.

Screenshots are numbered from 1 with no gaps, and each number takes its caption
from the matching line of `== Screenshots ==` in `readme.txt`. That section
currently describes six, so `screenshot-1` through `screenshot-6` are expected,
in that order.

The directory's content column is 772px wide — the same width as the banner —
so screenshots authored at **1544px wide** display at exactly 2× and are never
resampled.

The banner has the plugin name and author drawn over it by the directory
itself. Keep the left side visually quiet or the title will land on top of the
artwork.

Localised variants are optional and take a locale suffix before the extension:
`banner-772x250-fr_FR.png`, `screenshot-1-fr_FR.png`. The plugin ships six
locales, so translated screenshots are worth having at least for `fr_FR`.

## Publishing them

These files are not versioned per release: the directory keeps one `/assets/`
folder, always the current one, and updating it needs no version bump.

Until the plugin is approved there is no SVN repository to push to. Afterwards:

```bash
svn co https://plugins.svn.wordpress.org/ai-visibility ai-visibility-svn
cp .wordpress-org/* ai-visibility-svn/assets/
cd ai-visibility-svn && svn add assets/* && svn ci -m "assets: icon, banner, screenshots"
```

If the SVN push is ever automated with `10up/action-wordpress-plugin-deploy`,
this is the directory it reads by default — hence the name.
