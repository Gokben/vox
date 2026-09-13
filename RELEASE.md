# VOX release rules

Local edits, tests and GitHub pushes are not releases. Do not increment or publish a version during local work. Only deploy when the user explicitly asks to take the changes live ("canlıya al").

During an authorized live deployment, cPanel runs `php publish-release.php --live` after copying the application files. Running the script without `--live` is a no-op; Windows and APP_ENV=local cannot publish live releases. Do not publish before copying succeeds.

The local version display tracks the last verified release at https://voxisitme.com/erp/release.json. After a successful live deployment, copy the verified public release metadata to the local release.json without incrementing it. Never upload the local release.json to production.

`release.json` is generated on the live server and must not be copied from development over production. The version follows Golf: day + month + last year digit, followed by the daily sequence (for example `13096.01`). Publishing unchanged code preserves the version. Changed code advances the sequence, or starts at `.01` on a new Istanbul calendar date. Asset uploads and local database configuration do not count as releases.

The browser checks `app-release.php` every 60 seconds and on returning to the tab. A different build displays a notice. Reload waits for all desktop windows (including minimized windows) and dialogs to close. Hidden tabs, directly opened forms and modified top-level forms are protected. Application asset URLs include a content hash; HTML and release metadata are not cached. No patient records, sessions or user preferences are deleted.

Checks: `php tests/app-release.test.php` and `node --test tests/app-release-browser.test.mjs`.
