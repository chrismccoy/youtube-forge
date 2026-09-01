# YouTube Forge

This is a WordPress plugin that goes through your posts, finds every YouTube link, checks with the YouTube API to see if each video still works, and shows you a list of the ones that do not.

## Features

* **Finds links anywhere in a post** - checks the post text and every custom field, so links inside page builders, plugins, and theme options are picked up too.
* **Handles all the common links** - normal watch links, short youtu.be links, embed codes, Shorts, and live links.
* **Uses YouTube directly** - uses the official YouTube Data API, so the result comes from YouTube.
*  **Tells you what is wrong** - a video can be missing, private, not allowed to be embedded, or still processing, and each one gets its own label.
* **Shows progress** - a black log window fills in line by line while the scan runs, so you can see it working.
*  **Cleanup buttons** - each result row has View, Edit, and Trash. There is also a Trash all button for the whole report. Both ask you to confirm first and show you which links will go.
* **Keeps a history** - the last ten finished scans are saved. Old reports mark which posts you have since trashed or deleted, and a report where everything has been handled gets a Resolved badge.
*  **You pick what gets scanned** - Select the post types you want, such as Posts, Pages, or anything your theme added.
* **Checks your key before saving it** - the key is tested against YouTube first, so a wrong key is refused right away instead of turning into a scan that checks nothing.
* **Hides your key** - the saved key is shown with most of it blanked out, and it is stripped out of any error message before that message is stored.
*  **Adjusts to your server** - the scan measures how long each batch took and makes the next batch smaller or large, so slow hosting does not stall it.
* **Survives a page reload** - progress is saved as it goes, so reloading the page rejoins a scan that is still running.
*  **Works from the command line** - one WP-CLI command runs the same scan in a terminal and can write the results to a file.
* **Cleans up after itself** - deleting the plugin removes every setting and saved report it created.

### Run it from a terminal instead (optional)

If your host gives you WP-CLI, you can run the same scan without opening a browser. This is handy for large sites.

```
wp youtube-forge scan
```

Some things you can add to that:

| Option | What it does |
| --- | --- |
| `--limit=200` | Stop after 200 posts. |
| `--post-type=post,page` | Scan these post types for this run only, without changing your saved setting. |
| `--post-status=draft` | Scan drafts instead of published posts. |
| `--found` | List the working links as well as the broken ones. |
| `--fast` | Only ask whether each video still exists. Half the data, but a video that exists and cannot be embedded is counted as working. |
| `--format=csv` | Print as CSV instead of a table. JSON and YAML also work. |
| `--trash` | Move every post with a broken link to the trash. It asks you to confirm first. |
| `--yes` | Use with `--trash` to skip the confirmation prompt. |
| `--dry-run` | Use with `--trash` to see how many posts would go, without touching anything. |

For example, this writes every link it finds to a spreadsheet file:

```
wp youtube-forge scan --found --format=csv > links.csv
```
