# Notebook

A personal assistant that runs entirely on your own machine: chat, memories,
tasks, reminders and a small secrets store, backed by a single SQLite file.

Includes an optional Chrome side-panel extension so the assistant is reachable
from any tab.

## Requirements

- PHP 8.1 or newer, with `pdo_sqlite` and `curl` enabled
- An [OpenRouter](https://openrouter.ai) account for the AI features

## Setup

1. Clone the repository and serve the folder with any PHP server, for example:

   ```
   php -S localhost:8000
   ```

2. Open `http://localhost:8000` in a browser.

3. Go to **Settings** and paste your OpenRouter API key. The key is stored in
   your local database, never in the repository.

The `data/` folder and its SQLite database are created automatically on first
run, so a fresh clone starts with an empty notebook.

Default model is `google/gemini-2.0-flash-001:free`; other models can be picked
in Settings.

## Chrome extension

Open `chrome://extensions`, enable **Developer mode**, choose **Load unpacked**
and select the `extension/` folder. Open the panel with `Cmd+Shift+M`
(`Ctrl+Shift+M` on Windows and Linux).

If the app is not served from `http://localhost/tools/notebook/`, update
`host_permissions` in `extension/manifest.json` to match your own URL.

## Reminders in the background

`notify.php` sends due reminders as desktop notifications.
`com.notebook.reminders.plist` is a macOS launchd job that runs it every
minute. **The paths inside it are absolute and point at the machine it was
written on** - edit both the PHP binary path and the project path before
installing it with:

```
cp com.notebook.reminders.plist ~/Library/LaunchAgents/
launchctl load ~/Library/LaunchAgents/com.notebook.reminders.plist
```

## What is not in this repository

`.gitignore` excludes the `data/` folder, which holds the API key, saved
secrets, chat history, memories and reminders. Only `data/.htaccess` is kept,
because it is what blocks the database from being fetched over HTTP. Keep it.

## Security notes

This app is built to run on one machine, for one person, and makes two
assumptions that follow from that:

- Every page refuses any request that does not come from `127.0.0.1`
  (`lib/access.php`). There is no login.
- Values in the secrets store are held unencrypted in the local database. The
  assistant is never given them - the only tool that reaches the store returns
  names and notes, never values (`lib/secrets.php`).

Do not deploy this to a public server as it stands.

## Tests

```
php tests/run.php
```
