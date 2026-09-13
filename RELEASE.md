# VOX release rules

After finishing and validating a local code update, run `php publish-release.php` from the application directory. cPanel runs this command automatically after copying deployment files. Do not publish before the copy succeeds.

`release.json` is generated per environment and must not be copied from development over production. The version follows Golf: day + month + last year digit, followed by the daily sequence (for example `13096.01`). Publishing unchanged code preserves the version. Changed code advances the sequence, or starts at `.01` on a new Istanbul calendar date. Asset uploads and local database configuration do not count as releases.

The browser checks `app-release.php` every 60 seconds and on returning to the tab. A different build displays a notice. Reload waits for all desktop windows (including minimized windows) and dialogs to close. Hidden tabs, directly opened forms and modified top-level forms are protected. Application asset URLs include a content hash; HTML and release metadata are not cached. No patient records, sessions or user preferences are deleted.

Checks: `php tests/app-release.test.php` and `node --test tests/app-release-browser.test.mjs`.
