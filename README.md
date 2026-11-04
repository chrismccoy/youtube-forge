# YouTube Forge

This plugin checks your WordPress site for YouTube videos that no longer play and shows you exactly which posts they are in. It looks through post text and the extra fields stored with each post, asks YouTube about every video it finds, and gives you a report you can act on right away. It is built for site owners and editors who share a lot of YouTube videos and want to find the dead ones without opening every post by hand. A YouTube Data API key from a Google account is needed before any scan can run.

## Command Line Tools

- Runs a complete scan from the server command line with WP-CLI, using the same settings as the admin screen.
- Lets you pick which post status to scan, such as drafts or scheduled posts.
- Lets you pick which content types to scan for a single run without changing your saved setting.
- Lets you stop after a set number of posts for a quick test run.
- Can list working videos alongside broken ones.
- Offers a faster check that uses less of your YouTube allowance, with a warning that videos blocked from other websites will read as working.
- Prints results as a table, a spreadsheet file, JSON, YAML, or a simple count.
- Can move every post with a broken video to the trash after the scan, asking you to confirm first.
- Offers a preview mode that shows how many posts would be trashed without changing anything.
- Warns you when some videos could not be checked or when the listing was shortened.

## Finding Broken Videos

- Finds YouTube links in the text of your posts, including regular watch links, short youtu.be links, embedded players, Shorts, and live streams.
- Also checks the extra fields saved with each post, such as custom fields added by your theme or other plugins.
- Skips embedded playlists so only single videos are checked.
- Flags videos that have been removed or no longer exist on YouTube.
- Flags videos that their owners have made private.
- Flags videos whose owners have blocked them from playing on other websites.
- Flags videos that YouTube failed to process or rejected.
- Treats unlisted videos as working, since anyone with the link can still watch them.
- Scans only published posts from the admin screen, so drafts are left alone.

## Scan Reports

- Shows a table of every post with a broken video, with its status, video link, where in the post it was found, post title, and content type.
- Groups several broken videos from the same post into one row and shows how many there are.
- Gives each post a View button and, if you are allowed to edit it, an Edit button.
- Opens each broken video link in a new tab so you can check it yourself.
- Shows when the scan started and ended, along with totals for posts scanned, videos checked, and videos broken.
- Tells you how many videos could not be checked and suggests running the scan again to retry them.
- Adds a note when a scan continued an earlier one, stopped early because the YouTube allowance ran out, or used the faster check.
- Keeps the totals exact even when a very long list of broken videos is shortened for display.
- Lists any problems the scan ran into in a separate errors section.

## Scan History

- Keeps your last ten finished scans on a Logs page.
- Shows each saved scan as a collapsible summary with its date and its counts of posts, checked videos, and broken videos.
- Opens the newest scan automatically and loads older ones only when you click them.
- Labels scans that were run from the command line.
- Marks posts that have been trashed or deleted since the scan and keeps a running count of each.
- Shows a Resolved badge once every broken video in a saved scan has been dealt with.
- Lets you trash posts straight from a saved scan, with the counts updating as you go.
- Lets you clear all saved scans with one button after confirming.

## Settings

- Lets you save your YouTube Data API key, which is checked with YouTube before it is saved and requires a Google account.
- Shows only the first and last few characters of your saved key so it stays private on screen.
- Shows whether your key was verified or rejected, along with YouTube's reason.
- Lets you delete the saved key after a confirmation prompt.
- Lets you choose which public content types are scanned, such as posts, pages, or products, with Posts chosen by default.
- Offers a Reset button that puts the content type choice back to Posts only.
- Adds a Settings link to the plugin's row on the Plugins screen.
- Removes its saved key, settings, scan history, and stored video results when you delete the plugin.

## Scanning Large Sites

- Works through your posts in small steps so big sites can be scanned without the page timing out.
- Adjusts how many posts each step handles based on how fast your server is responding.
- Tries again up to three more times on videos YouTube did not answer for before marking them as unchecked.
- Remembers each video's result for a day, so repeat scans and repeated videos use less of your YouTube allowance.
- Stops cleanly when your daily YouTube allowance runs out, and the next scan picks up where it left off within the following week.
- Clears away a scan that was abandoned for over an hour, such as one left behind when a browser tab was closed.

## Live Scan Screen

- Starts a scan with a single Scan Now button under the YouTube Forge menu.
- Shows a live log of each video as it is checked, colored by working, broken, and retrying.
- Shows a running count of posts scanned and videos checked, broken, and unchecked.
- Reconnects to a scan that is still running if you leave the page and come back.
- Warns you when no API key is saved and points you to the Settings page.
- Lets you dismiss the finished results to clear the screen.

## Cleaning Up Problem Posts

- Lets you move a single post to the trash from the report, after a prompt that lists the broken videos inside it.
- Lets you trash every post with a broken video in one step, with a progress count as it works.
- Moves posts to the trash rather than deleting them, so they can still be restored.
- Checks your permission for each post and skips any you are not allowed to remove, telling you how many were skipped.
- Marks each trashed post in the report right away so you can see what is left to handle.

## Security and Access

- Limits every YouTube Forge screen and action to site administrators.
- Keeps your API key out of the error messages the scan records.
- Lets only one scan run at a time, so two people cannot start overlapping scans.
