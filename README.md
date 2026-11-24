# Sigma

A chat box for WordPress. Visitors ask a question and get an answer from an AI that reads only your own content: the post types you choose and a knowledge base you write. When the answer isn't there, or the visitor wants a person, they send a message to your team by email.

Free and open source (GPL-2.0-or-later). Made by [21press](https://21press.com).

- [Install](#install)
- [Set up](#set-up)
- [AI providers and costs](#ai-providers-and-costs)
- [Knowledge base](#knowledge-base)
- [Instructions](#instructions)
- [Voice input (dictation)](#voice-input-dictation)
- [Talk to a human: email and SMTP](#talk-to-a-human-email-and-smtp)
- [Spam protection: Cloudflare Turnstile](#spam-protection-cloudflare-turnstile)
- [Privacy and the data that is sent](#privacy-and-the-data-that-is-sent)
- [Updates](#updates)
- [For developers](#for-developers)
- [FAQ](#faq)

## Install

Requires WordPress 6.9 or later and PHP 8.2 or later.

1. Download **`sigma-{version}.zip`** from the [latest release](https://github.com/21press/sigma/releases/latest). Don't use GitHub's "Source code" zip: it has no built files and the wrong folder name.
2. In wp-admin, go to **Plugins → Add New Plugin → Upload Plugin**, choose the zip, then **Activate**.
3. Open **Sigma → Settings**.

**Coming from Delta Assistant?** Activating Sigma deactivates Delta Assistant. Your settings, keys, conversations and knowledge base carry over, because Sigma uses the same storage. You can then delete Delta Assistant from the Plugins screen (or with `wp plugin uninstall`): Sigma keeps your keys and data.

## Set up

Sigma → Settings has three tabs, **Chat box**, **AI agent** and **Human support**, plus a **Setup** checklist in the side panel. A new install starts with the chat box shown **to administrators only**, so you can try it on your own site first.

1. **AI agent → Connection:** choose a provider, paste its API key and pick a model (see [AI providers and costs](#ai-providers-and-costs)).
2. **AI agent → Knowledge:** choose what the bot answers from. Your knowledge base is always included; add the post types you want, such as docs or pages. Only published, public posts are used; password-protected posts are left out. The index rebuilds by itself when you publish or edit content.
3. **Human support → Handoff email:** set the address that receives messages from visitors. Empty means the site admin email.
4. **Human support → Sending email:** send a test email (see [email and SMTP](#talk-to-a-human-email-and-smtp)).
5. **Chat box → Status:** when you're happy, switch off "Show to admins only" so every visitor sees the chat box.

The **live preview** on the Settings screen shows your changes before you save them. To see real answers there with your unsaved instructions, switch on **Try with real AI** under AI agent → Instructions. It uses your AI provider and costs like a normal question, up to 20 an hour per admin.

Also on the Chat box tab: the name shown in the header, colours for light and dark, the font, the launcher's position and offset, the arrival pulse and the teaser, the home screen texts, how long chats are kept (90 days by default), and the privacy page link.

## AI providers and costs

Sigma works with **Anthropic (Claude)** or **OpenAI**, using your own API key. The key is stored encrypted in your database and is never shown again or sent to the browser.

| Provider | Models in Settings | Default |
|---|---|---|
| Anthropic | Claude Haiku 5.5, Claude Sonnet 5.5, Claude Opus 5.5 | Claude Haiku 5.5 |
| OpenAI | GPT-6 Luna, GPT-6.1 Terra, GPT-6.2 Sol, GPT-6.2 Astra | GPT-6 Luna |

Faster models answer sooner and cost less; the most capable ones are slower and cost more.

**Costs.** Sigma is free; you pay your AI provider for the questions it answers. **AI agent → Pricing and cost → Usage** shows this month's usage and an estimated cost. Sigma has no spending cap of its own, so set a monthly limit in your provider's dashboard if you want one. Built-in limits keep a single visitor or a bot from running up costs:

- 20 questions an hour and 60 a day per conversation;
- 30 questions an hour and 100 a day per visitor IP address (the address is never stored, only a daily hash);
- 600 questions an hour for the whole site, as a circuit breaker;
- answers are at most 1,200 tokens.

A visitor who hits a limit is offered a person instead.

At most 6 answers are written at the same time on the whole site, and 1 per visitor network (2 per IPv6 /48); a visitor who arrives while all are busy is asked to try again in a few seconds. That keeps your server's PHP workers free for your pages.

**Outer guard (recommended):** these limits run inside WordPress. If your site is behind Cloudflare, add a rate-limiting rule for `/wp-json/sigma/v1/` (for example 20 requests per minute per IP) so floods are stopped before they reach your server.

**Behind a proxy or Cloudflare?** **Chat box → Data and privacy → Visitor IP from** should stay on **Automatic**. It reads Cloudflare's visitor address only when a request really comes from Cloudflare, so the per-visitor limits work with or without it.

## Knowledge base

**Sigma → Knowledge** holds notes the bot reads before it answers: prices, policies, opening hours, how-tos your public pages don't cover. They have no page on your site and the bot never links to them, but they aren't secret: every Active entry is sent to your AI provider with every question, and the bot can share anything in them with any visitor who asks. Don't put passwords, internal contacts or anything private here.

- Write entries in Markdown, or **Import** `.md` and `.txt` files, up to 1 MB each. A file's title comes from its first `#` heading, or its name. A file with the same title as an entry replaces that entry's text.
- **Active** entries are used; **Draft** entries are not. Imports start as drafts unless you switch "Make new entries active" on.
- When an entry and a doc page disagree, the bot goes with the entry.
- **Conversations** shows which entries each answer used.

## Instructions

**AI agent → Instructions** is the text that tells the bot who it is, what it talks about, and its tone, length and language. It starts with a sensible default; edit it in plain text, up to 4,000 characters. You can use `{bot_name}`, `{product}` and `{pricing_url}`; they are filled in for each answer. **Reset to default** brings the default back.

Below your text, a set of **system rules** always applies and can't be edited: answer only from your content, link only real pages, offer a person when the answer isn't there, and ignore visitors who try to change the rules. You can read them in the collapsed "System rules: always applied" panel.

To keep the bot off a topic, say so in the instructions ("Don't discuss refunds; offer a person instead"). If a knowledge entry covers that topic, move it to Draft too.

## Voice input (dictation)

Visitors can dictate a question instead of typing it: a microphone button sits next to Send in the AI chat. Tap it to start, tap again to stop. It also stops after 8 seconds of silence, on Escape, or when the message is sent. The words appear in the box as they're recognised, and nothing is sent until the visitor presses **Send**.

- It uses the browser's own speech recognition, so there's no extra cost and no audio is uploaded to your site. In Chrome and Edge, the browser sends the audio to its maker's speech service (Google or Microsoft); Safari may use Apple's.
- The microphone appears only where it can work: in Chrome, Edge and Safari, on an HTTPS site. Firefox has no speech recognition, so visitors there just type.
- The language is your site's language.
- It's **on by default**. Turn it off under **AI agent → Voice input**. While it's on, the suggested privacy policy text (Settings → Privacy) includes a paragraph about it.

## Talk to a human: email and SMTP

When a visitor asks for a person, or the answer isn't in your content, they can send a message to your team. It arrives at the handoff address by email, with the chat so far, and **Reply-To** set to the visitor, so you just reply. The **team** under Human support decides whose faces and names appear on the "Talk to a human" card.

**Human support → Sending email → Send with:**

- **WordPress mail:** uses whatever your site already sends mail with (an SMTP plugin, your host's mail).
- **SMTP (built in):** Sigma's own SMTP, for its emails only; other emails on the site are not affected. Enter the host, the port and encryption (STARTTLS on 587 is the usual choice; SSL/TLS on 465), and the username and password. The password is stored encrypted (or set `SIGMA_SMTP_PASSWORD` and `SIGMA_SMTP_HOST` in `wp-config.php`; see [For developers](#for-developers)).

Use a **From** address on your own domain that your SMTP provider is allowed to send for (SPF and DKIM). Then use **Test connection** and **Send test email**. If a handoff email fails, Conversations marks it so you can send it again.

## Spam protection: Cloudflare Turnstile

Optional. In the [Cloudflare Turnstile dashboard](https://dash.cloudflare.com/?to=/:account/turnstile), create a widget for your domain and paste the site key and secret key under **Human support → Spam protection**. Press **Test keys**; once they pass you can switch Turnstile on. Visitors then confirm they are human before a message to your team is sent and, while the site is unusually busy (past half of the hourly question limit), before a new chat starts. If Cloudflare can't be reached, messages still go through, with stricter limits.

## Privacy and the data that is sent

- **Stored on your site:** chats, in two database tables, deleted automatically after the period you set (90 days by default). A visitor's name and email are recorded only when they send a message to your team. IP addresses are never stored. Chats are included in **Tools → Export Personal Data** and **Erase Personal Data**.
- **Sent to your AI provider** (Anthropic or OpenAI), only when a visitor asks a question: the question and the recent turns of that chat, your instructions, the matching parts of your chosen content, all of your Active knowledge entries, your site's name and address, the page the visitor is on, and today's date. Never the visitor's name or email.
- **Cloudflare:** only if you turn on Turnstile (the visitor's browser loads Cloudflare's script, and your site checks the token), or when you press "Refresh" next to the Cloudflare IP list.
- **Gravatar:** only for team members without a chosen picture. Sigma keeps a local copy, so visitors' browsers never contact Gravatar. You can turn team avatars off.
- **Speech recognition:** with Voice input on (the default), visitors can tap a microphone in the AI chat and dictate. Their **browser** turns speech into text; in Chrome and Edge the audio goes to the browser maker's speech service (Google, Microsoft), and Safari may use Apple's. Your site never receives audio, only the text the visitor sends. Turn it off under **AI agent → Voice input**.
- **GitHub:** for updates (see below).

The full list, with each service's terms and privacy policy, is in `readme.txt` under "External services".

## Updates

Sigma isn't on WordPress.org. It checks [GitHub releases](https://github.com/21press/sigma/releases) at most every 12 hours and offers new versions on the Plugins screen, where updates install the usual way. WordPress's auto-update toggle works too. Before installing, Sigma downloads the release's published **sha256 checksum** and refuses any zip that doesn't match.

- **Check now:** the **Check for updates** link under Sigma on the Plugins screen.
- **Privacy:** the requests go only to GitHub, with the user agent `Sigma/{version}` and without your site's address.
- **Turn it off:** `define( 'SIGMA_DISABLE_UPDATE_CHECK', true );` in `wp-config.php`, or return `false` from the `sigma_update_check` filter.

## For developers

**Optional constants in `wp-config.php`.** These replace the matching settings:

| Constant | What it does |
|---|---|
| `SIGMA_ANTHROPIC_KEY`, `SIGMA_OPENAI_KEY` | The provider's API key. |
| `SIGMA_SMTP_PASSWORD` with `SIGMA_SMTP_HOST` | The SMTP password. It's used only while `SIGMA_SMTP_HOST` matches the host saved in Settings, so it is never sent to another server. |
| `SIGMA_TURNSTILE_SECRET` | The Turnstile secret key. |
| `SIGMA_DISABLED` | Switches the chat box off, whatever Settings says. |
| `SIGMA_DISABLE_UPDATE_CHECK` | No update checks. |

The old `DELTA_ASSISTANT_*` names still work, with a notice asking you to rename them.

**Filters** start with `sigma_`, for example `sigma_client_ip` (the visitor IP behind another proxy), `sigma_rate_limits`, `sigma_models`, `sigma_rules` and `sigma_instructions`. The old `delta_assistant_*` names still work in 0.2.x, with a deprecation notice when debugging is on.

**Storage** keeps its original names, so a switch from Delta Assistant needs no migration: options `delta_assistant_*`, tables `{prefix}da_*`, the post type `da_knowledge`.

**Translations.** The text domain is `sigma`; the template is `languages/sigma.pot`. Strings in the admin screens load through WordPress's script translations, and the chat box gets its strings from the plugin in the site's language.

**Building from source.** See [CONTRIBUTING.md](CONTRIBUTING.md) for setup and checks, and [AGENTS.md](AGENTS.md) for the build and test rules. In short:

- `npm run build` builds the admin app and the chat box into `build/` (and `languages/sigma.pot` when WP-CLI is installed); `npm run package` makes `dist/sigma.zip`.
- `npm test` (unit), `npm run test:int` (wp-env, Docker) or `npm run test:int:local` (a local MySQL; see `tests/bin/int-local.mjs`), `npm run lint:php`.
- AI evals: `wp eval-file tests/evals/run.php provider=anthropic` on a local site with the docs imported (`tests/bin/import-docs.php`), with the key in `ANTHROPIC_API_KEY` for that command only.

## FAQ

**Can visitors use it without an AI key?**
Yes, but without answers: the chat box then offers only "Talk to a human".

**Will the bot make things up?**
It's told to answer only from your content and knowledge base, to say when something isn't covered, and to offer a person. Visitors can't talk it out of those rules. Like any AI it can still be wrong now and then, so Conversations lets you read what it said.

**Does it work with page caching?**
Yes. The chat box's script and data are the same for every visitor; each chat talks to the REST API directly.

**Where do I see the chats?**
**Sigma → Conversations**: read-only transcripts, which entries each answer used, and any handoff emails that failed.

**How do I remove everything?**
Turn on **Chat box → Data and privacy → On delete**, then delete the plugin. Otherwise deleting keeps your chats and settings (but always removes the saved keys).

**Something's wrong. Where do I report it?**
[Open an issue](https://github.com/21press/sigma/issues). For security problems, use the repository's **Security** tab ("Report a vulnerability") instead.
