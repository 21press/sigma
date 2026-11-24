# Changelog

## 0.2.5 (2026-11-25)

**Delta Assistant is now Sigma (public release)**
- The plugin is now called **Sigma**: a free, open-source (GPL-2.0-or-later) plugin by 21press. The menu, the screens and the emails say Sigma, and the plugin lives in the `sigma` folder.
- **Switching over:** install Sigma and activate it. That deactivates Delta Assistant and says so. Your settings, API keys, SMTP settings, conversations, knowledge base and team all carry over, because Sigma uses the same storage. You can then delete Delta Assistant from the Plugins screen or with `wp plugin uninstall`: its uninstall no longer takes your keys or data with it.
- **Updates from GitHub:** Sigma checks the latest GitHub release at most every 12 hours and offers it on the Plugins screen, where updates install the usual way, the auto-update toggle included. Before installing, it downloads the release's sha256 checksum and refuses any zip that doesn't match. **Check for updates** under Sigma on the Plugins screen checks right away. Requests go only to GitHub, without your site's address. Turn it off with `SIGMA_DISABLE_UPDATE_CHECK` or the `sigma_update_check` filter.
- **Translations:** the settings screens and the chat box follow the site's language once a translation is installed (text domain `sigma`, template `languages/sigma.pot`).
- The bot is called **Sigma** by default (it was Remy). A site that still used the old default is renamed once, with a notice; a name you chose stays.
- **Voice input:** visitors can dictate their question with a microphone button in the AI chat. The browser's own speech recognition does it, so no audio reaches your site and there's no extra cost. Nothing is sent until they press Send. On by default; switch it off under AI agent → Voice input.
- A "Made by 21press" card in the settings side panel.
- `readme.txt` lists every external service Sigma talks to (Anthropic, OpenAI, Cloudflare, Gravatar, GitHub), what is sent and when, with links to their terms and privacy policies. The README is now a user guide.

**OpenAI**
- Answers with OpenAI could be cut short or come back empty: the models think before they answer, and that thinking counted against the answer's length limit. Each question now asks for the least thinking the chosen model allows.
- Answers stopped by OpenAI's content filter are handled like a refusal, and an error in the middle of an answer is reported instead of ignored.

**Developers**
- REST namespace `sigma/v1`; admin pages `sigma`, `sigma-knowledge`, `sigma-conversations`; PHP namespace `TwentyOnePress\Sigma`; constants `P21_SIGMA_*`.
- Filters are `sigma_*`. The old `delta_assistant_*` filters still run in 0.2.x, with a deprecation notice.
- wp-config constants are `SIGMA_*`, for example `SIGMA_ANTHROPIC_KEY` and `SIGMA_SMTP_PASSWORD` with `SIGMA_SMTP_HOST`. The old `DELTA_ASSISTANT_*` names still work, and an admin notice asks you to rename them.
- Storage names are unchanged: `delta_assistant_*` options, `{prefix}da_*` tables and the `da_knowledge` post type.
- `npm run release` builds `dist/sigma-{version}.zip` and its `.sha256`. It refuses a dirty tree or mismatched versions.

## 0.2.1 (2026-11-23)

**Editable instructions (new)**
- Settings → AI agent → **Instructions**: change how the bot answers, such as its tone, its scope or what to leave out, in plain text (up to 4,000 characters). It starts with the current default; `{bot_name}`, `{product}` and `{pricing_url}` are filled in for you. **Reset to default** brings the default back, and a "Customised" badge shows when the text differs.
- The rules the plugin relies on stay protected and are always applied after your text: the "not in the docs", sources and "talk to a human" signals, the link rule (only real doc pages, never knowledge entries) and the guard against visitors changing the rules. You can read them in a collapsed "System rules: always applied" panel.
- If your instructions say not to discuss a topic, or to send it to a human, the bot follows that even when the docs or the knowledge base cover it. To fully exclude a topic, also move its knowledge entry to Draft.
- The settings say "the bot" instead of a fixed name, since the name is editable: **Bot icon**, "How the bot answers", and the icon option **Bubble face**. Conversations labels the bot's answers with its configured name.
- **Try with real AI** in the live preview: ask a question with your unsaved instructions and see the real answer before you save. It uses your AI provider and costs like a normal question (up to 20 an hour per admin). Nothing is stored except the usage.

**Developers**
- New filter `delta_assistant_instructions` on the editable part; `delta_assistant_rules` still filters the whole rules block. New admin route `POST /admin/preview/ask`.

## 0.2.0 (2026-11-23)

**Knowledge base (new)**
- Assistant → **Knowledge**: notes the bot reads before it answers, such as prices, policies and how-tos the public pages don't say. Write them in Markdown, or import `.md` and `.txt` files (up to 1 MB each). A file whose title matches an entry replaces that entry's text; imports start as drafts unless you switch "Make new entries active" on.
- The list has All, Active and Draft views, search and bulk actions (Make active, Move to draft, Delete), and shows how many active entries and words the bot uses. Edit, Add, Import and Delete open in side panels.
- The knowledge base is always on as the bot's base source. Settings → AI agent → Knowledge shows it checked; your post types (docs and so on) stay optional on top.
- Entries are private: they have no page on your site, don't show up in search, feeds, sitemaps or the WordPress REST API, and only administrators can see or change them. Their text is never run as shortcodes, blocks or HTML.
- When an entry and a doc page disagree, the bot goes with the entry. The bot never links an entry; Conversations shows which entries an answer used ("Used: …"), each linking to its edit panel.
- Active entries are sent to your AI provider when they help answer a question (the screen says so).
- Uninstall deletes entries only when "Delete data on uninstall" is on. Tools → Export includes them.

**Under the hood**
- Answers that use knowledge end with a hidden `[[SOURCES: kb-…]]` marker; every `[[…]]` marker is removed before visitors see the text, also while it streams. An answer from knowledge counts as answered without a link.

## 0.1.8 (2026-11-22)

**One bot icon (owner)**
- The bot now has one icon, used everywhere it appears: the chat button, the teaser, the "Talk to …" and "Continue" cards (with the bot's name), the chat header and next to each answer. Until now the button, the bot's avatar and the home header could each show a different icon.
- Settings → AI agent → Identity → **Bot icon**: the plugin mark (default), Bubble face, Chat, Sparkles, Help, or an image from the Media Library. It replaces the separate Avatar field and Chat box → Launcher → Icon. The live preview follows it, and a newly picked image shows there right away, before you save.
- Built-in icons sit on a disc in your brand colour, with a contrasting mark. An uploaded image fills the circle; if it can't load, the plugin mark is shown instead, never a broken image.
- The wp-admin menu and the plugin's page header show the same icon. Built-in icons in the menu take the colours of your admin colour scheme; an uploaded image shows as a small round picture.
- On update: a site that had uploaded a bot avatar now uses that image as the bot icon everywhere, the chat button included. Otherwise your launcher icon stays as it was.
- The chat box's home header keeps your site's logo: that's your brand, not the bot's.

## 0.1.7 (2026-11-21)

Security hardening from the 0.1.6 audit (plan §10b). No visitor-facing redesign. Every site gets the safer defaults on update.

**Abuse and cost limits**
- Each visitor IP is now limited to 30 AI questions an hour and 100 a day across all its conversations, and the whole site to 600 an hour (a circuit breaker). Questions are counted before the AI is called, and counted atomically, so parallel requests can't slip past a limit. A question is refunded only when nothing reached the AI.
- At most one answer at a time per conversation, 2 per IP and 4 per site (filter `delta_assistant_max_concurrent_asks`). Extra requests get "busy" right away instead of tying up PHP workers; the widget retries once quietly, then shows "[bot name] is busy, try again in a few seconds."
- Messages to the team: at most 30 an hour for the whole site ("Our inbox is busy, try again later.").
- IPv6 visitors are limited per /64 and per /48; IPv4-mapped addresses count as IPv4.
- Answers stop after 45 s and at most 1,200 tokens, with the lowest thinking setting for every model.
- A conversation is stored only once its first question or message is sent, and empty ones are removed after 24 hours.

**Settings**
- Visitor IP source has a new default, **Automatic**: Cloudflare's visitor IP when the request comes from Cloudflare, otherwise the connection's IP. Sites on "Connection address" move to Automatic once on update; a warning shows if you choose it back while requests come through Cloudflare.
- New **Spam protection** (Human support): optional Cloudflare Turnstile on the message form, off by default. Paste the site key and secret key, press Test keys, then switch it on. The secret is stored encrypted. If Cloudflare can't be reached, messages are still accepted at 1 per hour per visitor and an admin notice shows after 3 such events in an hour.
- SMTP: changing the server, port, username or encryption asks for the password again; a password is never sent without encryption to another machine; the connection test only reaches public mail ports (private addresses are allowed on local and development sites).
- Only post types visitors can view can be knowledge sources, and the knowledge is always built as a logged-out visitor, in the background.

**Data and privacy**
- Stored keys are encrypted with a key derived from the site's salts and re-encrypted on update; a key that can no longer be decrypted shows as "unreadable" instead of "set".
- Uninstall always removes the stored keys and passwords, even when you keep the data, on every site of a multisite network.
- The personal data export includes every message of long conversations; the daily cleanup reschedules itself if it went missing; team photos are downloaded at most 2 MB.

**Links in answers**
- Links are rebuilt from their parts; links with a backslash, a username, quotes, spaces or invisible characters are shown as text. Docs on international domain names or with non-English addresses stay clickable. In the chat box, a link is clickable only if it's one of the answer's sources or on your own site.

**Developers**
- New table `{prefix}da_counters` (additive). REST: 429 `busy`, 503 `handoff_busy`, 403 `turnstile_required` / `turnstile_failed`; `/config` adds `site_host`, `turnstile_site_key`, `turnstile_theme`; `page_url` and `locale` are accepted on `/ask` and `/handoff`; an unknown conversation answers 403 `invalid_token` (was 404). New filters: `delta_assistant_max_concurrent_asks`, `delta_assistant_smtp_ports`, `delta_assistant_smtp_allow_private`; new limits in `delta_assistant_rate_limits`.

## 0.1.6 (2026-11-21)

**Site owners (new admin)**
- Settings in three tabs (Chat box, AI agent, Human support) with a save bar, Discard, and a live preview of the real chat box in the corner that updates as you type.
- A first-run setup (AI provider, knowledge, handoffs, go live) and a checklist that shows what's left.
- Conversations: status filters, search, pagination, and the transcript in a chat layout with a details panel and Retry send.
- Chat box colours per light and dark scheme (background and text; the rest is derived and kept readable), a logo picked from the Media Library, and a choice of four launcher icons. New installs start with neutral colours.
- Note: every site's launcher changes from the chat icon to the plugin mark after this update; pick "Chat" under Chat box → Launcher → Icon to keep the old one.

**Settings fixes (owner review)**
- Notices no longer have a coloured stripe down the left side: one thin border all round, in the notice's colour.
- Model is a list for the chosen provider: Anthropic Claude Haiku 5.5 (default), Sonnet 5.5, Opus 5.5; OpenAI GPT-6 Luna (default), GPT-6.1 Terra, GPT-6.2 Sol, GPT-6.2 Astra. A model saved before that isn't in the list stays selected until you change it. Developers can extend the list with the `delta_assistant_models` filter.
- The API key, SMTP password and the provider and model fields take the row's full width, also on phones.
- The usage estimate knows the prices of every listed model.

## 0.1.5 (2026-11-21)

**Visitors**
- The home screen shows one card per channel: "Talk to a human" and "Talk to [bot name]". Each becomes "Continue with the team" or "Continue with [bot name]" (with the last question on one line) when there's a conversation on that side, including a message sent to the team straight from home.
- A "New chat" button in the bot's chat header starts a fresh conversation with the intro; going Back without sending returns to the previous one.
- The "Talk to a human" offer inside a chat appears only when the docs don't cover the question or the visitor asks for a person (in any language). The always-visible "Talk to a human" button above the text box is gone; the home screen's human card is always there.
- The launcher comes alive on every page load: a gentle breathe and ring 1 s after the page loads, then a ring every 12 s while the chat is closed, independent of the teaser. Opening the chat or pressing Escape on the teaser stops it for that page; nothing at all with reduced motion.

**Under the hood**
- The bot marks a request for a person with an end marker that never reaches the visitor; `/ask` and the stream's final event carry `handoff_requested`. A narrow first-person phrase list (filter `delta_assistant_handoff_patterns`) is the fallback if the marker is missing.

## 0.1.4 (2026-11-21)

**Visitors**
- The bot has its own avatar (a default mark in the site's colours, or an uploaded image) in the teaser, the chat header and next to each answer.
- The home screen has no text box, only cards: "Talk to a human" with the team's faces, "Talk to [bot name]" (starts a new chat with the bot's introduction) and, once there's a conversation, "Continue with [bot name]" (or "Continue with the team" for a conversation sent to the team), with the last question on one line.
- The teaser is a chat line from the bot in the launcher's colours, so it stands out on light and dark pages. It no longer has a × button. It shows above a small cookie card instead of waiting for it to close, and moves beside the launcher when there's no room above. Clicking it opens the chat; Escape on the focused teaser hides it and the pulse for the visit.
- The text box in a new chat says "Ask a question" until the first question, then "Ask a follow-up".
- An uploaded bot avatar that fails to load shows the default mark, never a broken image.

**Site owners**
- Bot settings: name, avatar (from the Media Library, copied to the site), home card title and text, intro message, teaser and pulse. Settings → Home: the "Talk to a human" and "Continue" titles and texts. Defaults use `{bot_name}` and `{product}` (the site name).
- New default privacy line: "{bot_name}'s answers come from our docs and collective knowledge of {product}. Chats are stored for {days} days for better user experience."
- Avatars heal themselves: after an update (for example from 0.1.2, which never made the copies), on activation, and when a visitor's chat finds a member or the bot without a copy, a one-off refresh runs in the background. One refresh runs at a time; unused files are cleaned up. Refresh avatars says when one is already running.

**Privacy**
- The bot's intro is shown by the widget only: it is never sent to the AI and never stored.

## 0.1.3 (2026-11-21)

**Fixes**
- Team avatars: members showed initials instead of their photos. Saving Settings → Team and the daily refresh stopped with a PHP error (`wp_tempnam()` is only loaded in wp-admin) before any avatar copy was made. Both work again; one member's error no longer stops the others or the save.
- Avatar copies follow the site's address (https, a domain move); WebP falls back to PNG when saving WebP fails; pictures smaller than 96 px are kept as a square.
- An avatar that fails to load in the browser shows the member's initial, never a broken image.

**Site owners**
- Settings → Team shows, per member, where the widget avatar comes from, when it was last refreshed and how that went (for example "No Gravatar (404)" or "Gravatar answered HTTP 503"), and warns when the picture fails to load in the browser. A **Refresh avatars** button makes the copies again now.

**Visitors**
- The teaser greets once per visit (a browser tab session), 3 s after the first page, and hides itself after 15 s untouched (hover or focus holds it). × or opening the chat ends it for the visit; the next visit greets again.
- The launcher pulses every 12 s while the chat hasn't been opened in this visit and the teaser isn't showing (the first pulse about 12 s after the teaser hides); paused while the tab is hidden, on hover or focus, and off with reduced motion.

## 0.1.2 (2026-11-21)

There was no 0.1.1 release; its changes ship here.

**Visitors**
- Answers stream in word by word (server-sent events), with the typing dots until the first words. Links and formatting appear when the answer is complete. A host or proxy that buffers responses gets the whole answer at once, as before. Settings → AI → Test streaming checks it.
- The first byte of an answer goes out at once, even on a cold site: a visitor's question never builds the docs index. Rebuilds run on doc save, Rebuild now and cron; with no index at all the bubble offers a retry while it builds.
- "Talk to a human", with the team's avatars beside it (up to three, then "+N"). The pictures are copies kept on the site, never Gravatar links.
- The AI has a name, Remy by default, shown with an "AI" badge, and a teaser above the launcher for first-time visitors ("👋 Hi, this is Remy. How can I help you?"), dismissed for 7 days with ×. The launcher pulses in three short bursts after the page loads while the teaser is not showing, until the chat is first opened (off with reduced motion).
- A "Continue conversation" card on the home screen. Back returns to where the visitor came from. A new conversation replaces the old one only once its first question is accepted.
- More room in the chat: clearer gaps between messages, paragraphs, lists and the doc links.

**Site owners**
- Settings → Team: pick who answers (Authors and above), in order. Members set their chat name and avatar in their profile.
- Settings: bot name, teaser text and on/off, launcher pulse, team avatars on/off.
- The AI introduces itself by the site's name ("the AI assistant for Delta's docs").

## 0.1.0 (2026-11-20)

First release.

- Help bubble on every front-end page, isolated in a shadow root; always on top, and it steps aside for consent cards and walls.
- AI answers from the site's own docs (Anthropic, OpenAI) with doc links. Whole-docs mode while the docs fit the context budget, retrieval mode (BM25) above it; exact token count from the provider.
- "Talk to a person": the conversation is emailed to support with Reply-To the visitor; idempotent, so a retry never sends twice. Built-in SMTP for the plugin's own emails, with a step-by-step connection test.
- wp-admin: Settings and read-only Conversations, with Retry send for a failed email.
- Rate limits, visitor IP behind Cloudflare, kill switch, admins-only mode, retention purge, privacy exporter and eraser.
