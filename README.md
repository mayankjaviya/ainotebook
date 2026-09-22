<div align="center">

# 🪶 Notebook — Your Self-Hosted AI Assistant with a Real Memory

### An open-source, privacy-first AI agent that remembers you, controls your browser, and runs entirely on your own machine. No cloud account. No subscription. No telemetry.

[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net)
[![SQLite](https://img.shields.io/badge/SQLite-Local%20Storage-003B57?style=for-the-badge&logo=sqlite&logoColor=white)](https://www.sqlite.org)
[![OpenRouter](https://img.shields.io/badge/OpenRouter-400%2B%20Models-6467F2?style=for-the-badge)](https://openrouter.ai)
[![Chrome Extension](https://img.shields.io/badge/Chrome-Side%20Panel-4285F4?style=for-the-badge&logo=googlechrome&logoColor=white)](#-the-chrome-extension-your-ai-in-every-tab)
[![Runs Locally](https://img.shields.io/badge/Data-100%25%20Local-2ea44f?style=for-the-badge&logo=shieldsdotio&logoColor=white)](#-privacy-by-architecture-not-by-promise)

**[Features](#-what-makes-notebook-different) · [Quick Start](#-quick-start-under-two-minutes) · [AI Tools](#-18-ai-tools-what-your-assistant-can-actually-do) · [The App](#-the-six-surfaces) · [FAQ](#-frequently-asked-questions)**

</div>

---

## 💡 Why Notebook Exists

Every mainstream AI chatbot forgets you the moment you close the tab. The ones that *do* remember store your thoughts on someone else's servers, behind a monthly bill, under a privacy policy that changes without asking.

**Notebook flips that.** It is a genuine AI agent — one that *acts*, not just answers — built on a single SQLite file that never leaves your computer. It remembers what matters, drives your browser on your behalf, builds working software on request, and nags you about the things you forgot.

It runs on plain PHP. There is no build step, no Docker, no Node toolchain, no account to create. Clone it, point PHP at the folder, paste one API key, and you have a personal AI assistant that is **yours**.

> **One file. One folder. One person. Yours.**

---

## ✨ What Makes Notebook Different

<table>
<tr>
<td width="33%" valign="top">

### 🧠 Memory That Persists
Your assistant decides, mid-conversation, what is worth remembering — and writes it down. Powered by **SQLite FTS5 full-text search** with **BM25 relevance ranking**, so recall stays instant whether you have 10 memories or 10,000.

</td>
<td width="33%" valign="top">

### 🌐 It Drives Your Browser
Not a summariser. A real **browser automation agent** that navigates, reads, clicks, types and scrolls through live pages via a Chrome side panel — while you watch it happen.

</td>
<td width="33%" valign="top">

### 👁️ It Can See Your Screen
Ask *"what's on this page?"* and it captures your active tab and reasons over the **image**. Full **multimodal vision** support, with vision-capable models free out of the box.

</td>
</tr>
<tr>
<td valign="top">

### 🎨 It Builds Real Things
Ask for a dashboard, a landing page, a Snake clone. The AI writes complete single-file HTML apps that render instantly in your **Creations** gallery. Working software, not a code block.

</td>
<td valign="top">

### 🔐 A Vault the AI Cannot Read
Store API keys and passwords locally. The assistant can list **names and notes** to reason about them — but there is deliberately **no tool that returns a value**. Enforced in code, covered by a test.

</td>
<td valign="top">

### 🔀 400+ Models, Zero Lock-In
Powered by **OpenRouter**. Switch between Gemini, Llama, Qwen, DeepSeek and hundreds more from a dropdown. Ships with **five free models** — you can run the whole thing at **$0/month**.

</td>
</tr>
</table>

---

## 🚀 Quick Start (Under Two Minutes)

**Requirements:** PHP 8.1+ with `pdo_sqlite` and `curl`. That is genuinely the entire list.

```bash
git clone https://github.com/YOUR-USERNAME/notebook.git
cd notebook
php -S localhost:8000
```

Open **http://localhost:8000**, go to **Settings**, and paste an [OpenRouter API key](https://openrouter.ai/keys).

That's it. The database, tables and folders build themselves on first run.

> 💸 **Free forever tier:** the default model is `google/gemini-2.0-flash-001:free`. Notebook ships with five free models preselected, so you can run it indefinitely without spending a cent.

---

## 🧰 18 AI Tools: What Your Assistant Can *Actually* Do

Most "AI notebooks" are a text box wrapped around an API call. Notebook gives the model **18 real tools** and lets it chain them autonomously to finish a job.

### 🧠 Memory & Recall
| Tool | What it does |
|:--|:--|
| `save_memory` | Stores a fact about you, unprompted, when it judges it worth keeping |
| `search_memory` | BM25-ranked full-text search across everything it knows |
| `update_memory` | Revises a memory when the truth changes |
| `delete_memory` | Forgets on request |

### 🌐 Browser Control *(the headline act)*
| Tool | What it does |
|:--|:--|
| `browser_navigate` | Opens any URL in your real browser |
| `browser_read_page` | Extracts the live DOM as readable text |
| `browser_click` | Clicks buttons, links and controls |
| `browser_type` | Fills in forms and search boxes |
| `browser_scroll` | Scrolls to reach content below the fold |
| `capture_page_screenshot` | Sees your active tab as an image |

### ✅ Getting Things Done
| Tool | What it does |
|:--|:--|
| `add_task` · `list_tasks` · `complete_task` · `delete_task` | A full task manager the AI drives from plain conversation |
| `set_reminder` | Schedules a desktop notification — Chrome, macOS, or both |
| `send_webhook` | Fires an HTTP request to Slack, Discord, Zapier, n8n or your own API |

### 🎨 Creation & Security
| Tool | What it does |
|:--|:--|
| `artifact` | Generates a complete, runnable HTML app, UI component or game |
| `list_secrets` | Sees which credentials exist — **never their values** |

---

## 💬 What That Looks Like in Practice

> **"Open Hacker News, read the top 5 stories, and save anything about SQLite to memory."**
> → Notebook navigates, reads the live page, filters, and writes memories. Autonomously.

> **"What is this page asking me to agree to?"**
> → Screenshots your active tab, reads the image, and explains the terms in plain English.

> **"Build me a pomodoro timer with a dark theme."**
> → Writes a complete single-file app and renders it live in Creations.

> **"Remind me to renew the domain on Friday at 9am."**
> → Schedules it. A desktop notification arrives on Friday at 9am.

> **"What did I decide about the database migration last month?"**
> → BM25-ranked recall across every memory it has ever saved.

---

## 📱 The Six Surfaces

| Page | Purpose |
|:--|:--|
| 💬 **Chat** | The agent itself — image uploads, and live step-by-step progress as each tool fires |
| 🧠 **Memories** | Everything it knows about you, searchable and editable by hand |
| ✅ **Tasks** | Priorities, completion, full CRUD — shared with the AI |
| 🎨 **Creations** | A gallery of every artifact the AI has built for you |
| 🔐 **Secrets** | Your local vault, visible to you in the browser and to nothing else |
| ⚙️ **Settings** | Model picker, custom system prompt, web search, reasoning effort, notification channel |

---

## 🧩 The Chrome Extension: Your AI in Every Tab

A **Manifest V3 side panel** that puts Notebook one keystroke away — `Cmd+Shift+M` / `Ctrl+Shift+M` — on any page you are reading.

- 📄 **Page-aware chat** — it knows what tab you are on without you pasting anything
- 🔔 **Native notifications** — reminders arrive even when the app tab is closed
- 🤖 **The control channel** — this is what makes real browser automation possible

```
chrome://extensions → Developer mode → Load unpacked → select extension/
```

---

## 🔒 Privacy by Architecture, Not by Promise

Privacy claims are cheap. These are structural:

| Guarantee | How it is enforced |
|:--|:--|
| **No cloud storage** | Everything lives in one SQLite file in `data/` |
| **No account, ever** | There is no sign-up, no server, no user table |
| **Localhost-only** | Every request from outside `127.0.0.1` is refused in `lib/access.php` |
| **Database unreachable over HTTP** | `data/.htaccess` denies all web access to the folder |
| **Secrets hidden from the AI** | No tool exists that returns a secret value — and a test asserts it |
| **Your key, your bill** | Calls go directly from your machine to OpenRouter. Nothing is proxied |
| **Nothing committed by accident** | `.gitignore` excludes the entire `data/` folder |

> **Honest caveat:** this is a single-user app for `localhost`. There is no login, and secrets are stored unencrypted in the local database — because the design assumes the file never leaves your machine. **Do not deploy it to a public server as it stands.**

---

## ⚙️ Tuning It to You

Everything below is adjustable from the **Settings** page — no code, no restart:

- 🤖 **Model** — any of OpenRouter's 400+, or bring your own list
- 📝 **System prompt** — rewrite your assistant's personality and rules
- 🔍 **Web search** — let the model search the live internet mid-answer
- 🧮 **Reasoning effort** — dial deliberation up for hard problems, down for speed
- 🔁 **Fallbacks** — auto-retry on another model when one is down
- 🔔 **Notification channel** — Chrome, macOS, or both

---

## 🏗️ Under the Hood

```
notebook/
├── lib/          12 focused modules — ai, chat, memory, tasks, tools, secrets…
├── pages/        6 UI surfaces
├── extension/    Chrome MV3 side panel + service worker
├── data/         Your SQLite database (git-ignored)
└── tests/        php tests/run.php
```

**Design principles:** no framework, no build step, no dependency tree. Plain PHP 8.1, plain JavaScript, one SQLite file. Readable end to end in an afternoon — and hackable the same day.

```bash
php tests/run.php   # run the test suite
```

### ⏰ Background reminders on macOS

`com.notebook.reminders.plist` is a launchd job that fires `notify.php` every minute.
⚠️ **Edit both absolute paths inside it first** — the PHP binary and the project folder:

```bash
cp com.notebook.reminders.plist ~/Library/LaunchAgents/
launchctl load ~/Library/LaunchAgents/com.notebook.reminders.plist
```

---

## ❓ Frequently Asked Questions

<details>
<summary><b>Is Notebook really free?</b></summary><br>
The software is free and open source. It ships with five free OpenRouter models selected by default, so you can run it at $0/month indefinitely. Paid models are available if you want frontier quality — billed by OpenRouter directly, with no markup from us.
</details>

<details>
<summary><b>How is this different from ChatGPT or Claude?</b></summary><br>
Three ways. Your data stays on your machine instead of a vendor's servers. Memory is permanent, inspectable and editable by hand. And it isn't locked to one provider — switch between 400+ models from a dropdown.
</details>

<details>
<summary><b>Does my data ever leave my computer?</b></summary><br>
Only the text of the message you send goes to your chosen model via OpenRouter — the same as any AI app. Your memories, tasks, chat history, artifacts and secrets are stored solely in a local SQLite file. There is no Notebook server to send anything to.
</details>

<details>
<summary><b>Can the AI read my saved passwords?</b></summary><br>
No, and this is enforced structurally rather than by instruction. The only secrets tool returns names and notes. No tool capable of returning a value exists in the codebase, and a test asserts that values never reach the model.
</details>

<details>
<summary><b>Do I need Docker, Node or a build step?</b></summary><br>
None of them. If you have PHP 8.1, you can run Notebook. There is no compile step and no dependency install.
</details>

<details>
<summary><b>Does it work on Windows and Linux?</b></summary><br>
The web app and Chrome extension work anywhere PHP runs. The launchd background reminder job is macOS-only — on Windows or Linux, use Task Scheduler or cron to run <code>notify.php</code> every minute instead.
</details>

<details>
<summary><b>Can it browse the web on its own?</b></summary><br>
Yes. With the Chrome extension installed it can navigate, read, click, type and scroll through real pages in your actual browser, chaining those tools to complete multi-step jobs while you watch.
</details>

<details>
<summary><b>How do I back it up?</b></summary><br>
Copy <code>data/memory.sqlite</code>. That single file is your entire assistant — memories, chats, tasks, reminders and secrets.
</details>

---

<div align="center">

### ⭐ If Notebook is useful to you, star the repo — it genuinely helps others find it.

**Built for people who want an AI that remembers them — without renting their memory back.**

</div>

---

<sub>
<b>Keywords:</b> self-hosted AI assistant · open source AI agent · local AI assistant · privacy-first AI · personal AI with memory · AI second brain · OpenRouter client · PHP AI assistant · SQLite AI memory · Chrome side panel AI · browser automation agent · AI agent with tool calling · multimodal AI vision assistant · AI artifact generator · local-first software · no-cloud AI · free ChatGPT alternative · self-hosted Claude alternative · AI note taking app · personal knowledge management · AI task manager · AI reminders · agentic AI · function calling · RAG memory · full-text search AI · offline AI assistant · single-user AI · BM25 semantic recall · MV3 Chrome extension · zero-dependency PHP app
</sub>
