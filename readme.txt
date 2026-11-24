=== Sigma ===
Contributors: 21press
Tags: chat, ai, support, knowledge base, help desk
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.2.5
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A chat box for your site: AI answers drawn only from your own docs and knowledge base, and a message to your team when a visitor wants a person.

== Description ==

Sigma adds a chat box to your site. Visitors ask a question and get an answer from an AI that reads only your own content: the post types you choose (docs, pages, anything public) and a knowledge base of notes you write in Markdown. Answers link to the pages they come from. When the answer isn't in your content, or the visitor asks for a person, they can send a message to your team, which arrives by email.

* Answers come only from your content, with links. A visitor can't talk the AI into other topics or out of its rules.
* A knowledge base: prices, policies and how-tos that your public pages don't cover. It has no page on your site, but every Active entry is sent to your AI provider with every question and the bot can share it with visitors, so keep anything private out of it.
* Your choice of AI provider: Anthropic (Claude) or OpenAI, with your own API key, stored encrypted.
* Editable instructions for the bot's tone and scope, with a live preview.
* Handoff to your team by email, through your own SMTP server or WordPress's mail.
* Built-in limits against abuse and runaway costs, optional Cloudflare Turnstile on the contact form.
* Conversations in wp-admin, deleted automatically after the period you set. Works with WordPress's privacy export and erase tools.
* Updates come from GitHub releases, checked against a published sha256 checksum before they install.

Sigma is free. You pay your AI provider for the questions answered (usually a fraction of a cent each; Settings shows this month's usage and an estimate).

== Installation ==

1. Download `sigma-{version}.zip` from the latest release at https://github.com/21press/sigma/releases (not the "Source code" zip).
2. In wp-admin, go to Plugins → Add New → Upload Plugin, choose the zip and activate Sigma.
3. Open Sigma → Settings in wp-admin and follow the setup: add your AI provider's API key, choose what the bot answers from, set the support email, then show the chat box.

Coming from Delta Assistant? Activating Sigma deactivates Delta Assistant. Your settings, conversations and knowledge base carry over, and you can then delete Delta Assistant safely.

== Frequently Asked Questions ==

= Does Sigma send my content to the AI provider? =

The parts of your chosen content that match the question, all of your Active knowledge entries, and the instructions. See "External services" below.

= Can I use it without an API key? =

The chat box needs an AI provider for answers. Without one, visitors can still send a message to your team.

= Is Sigma translated? =

It's ready for translation (text domain `sigma`, template `languages/sigma.pot`): the settings screens and the chat box follow the site's language once a translation is installed.

= How do I turn off the update check? =

Add `define( 'SIGMA_DISABLE_UPDATE_CHECK', true );` to wp-config.php, or return false from the `sigma_update_check` filter.

== External services ==

Sigma connects to the services below, only for the features described. It never sends a visitor's name or email address to any of them.

= Anthropic (Claude) =

Used when Anthropic is the AI provider chosen in Settings. When a visitor asks a question, the site sends to https://api.anthropic.com: the question and the recent turns of that chat, the instructions, the matching excerpts of your chosen content, all of your Active knowledge entries (with every question), your site's name and address, the page the visitor is on, and today's date. When your content is rebuilt, the site also sends it to Anthropic's free token-counting endpoint to measure its size. Your API key authenticates the requests.
Terms: https://www.anthropic.com/legal/commercial-terms
Privacy: https://www.anthropic.com/legal/privacy

= OpenAI =

Used when OpenAI is the AI provider chosen in Settings. When a visitor asks a question, the site sends the same information as above to https://api.openai.com.
Terms: https://openai.com/policies/business-terms
Privacy: https://openai.com/policies/privacy-policy

= Cloudflare =

* Turnstile, only when you turn on Spam protection: the visitor's browser loads Cloudflare's Turnstile script from challenges.cloudflare.com when the contact form (or a check) needs it, and the site sends the resulting token, your Turnstile secret key and the visitor's IP address to Cloudflare's siteverify service to check it. "Test keys" in Settings sends your secret key with a dummy token.
* IP ranges: when you press "Refresh" next to the Cloudflare IP list in Settings, the site downloads Cloudflare's published IP ranges from www.cloudflare.com. Nothing is sent.
Terms: https://www.cloudflare.com/website-terms/
Privacy: https://www.cloudflare.com/privacypolicy/ and, for Turnstile, https://www.cloudflare.com/turnstile-privacy-policy/

= Gravatar =

When you add team members to the chat box and a member has not chosen a picture, the site asks Gravatar for that member's avatar (WordPress sends a hash of the member's email address) and keeps a local copy, which is what visitors see. Visitors' browsers never contact Gravatar. Turn off team avatars in Settings to skip this.
Terms: https://automattic.com/tos/
Privacy: https://automattic.com/privacy/

= GitHub =

To offer updates, the site asks GitHub at most every 12 hours for the latest release of Sigma (https://api.github.com/repos/21press/sigma) and reads that release's readme.txt; when you update, it downloads the release zip and its checksum. These requests send only "Sigma/{version}" as the user agent, without your site's address. Turn it off with `SIGMA_DISABLE_UPDATE_CHECK`.
Terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
Privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

= Speech recognition (the visitor's browser) =

Used when Voice input is on (Settings → AI agent → Voice input; on by default) and the visitor's browser supports it (Chrome, Edge and Safari, not Firefox). When a visitor taps the microphone in the AI chat, the browser itself turns their speech into text with the Web Speech API. Depending on the browser, the audio is processed on the device or sent to the browser maker's speech service (Google for Chrome, Microsoft for Edge, Apple for Safari). Sigma and your site never receive the audio; only the text the visitor chooses to send reaches your AI provider, like typed text. Turn Voice input off to remove the microphone.
Privacy: https://www.google.com/chrome/privacy/, https://www.microsoft.com/en-us/privacy/privacystatement and https://www.apple.com/legal/privacy/

== Privacy ==

Sigma stores chats in your database (two tables) and deletes them after the retention period in Settings (90 days by default). It records the visitor's name and email only when they send a message to your team. Visitor IP addresses are never stored: rate limits use a hash with a salt that changes every day. The WordPress privacy tools (Tools → Export and Erase Personal Data) include Sigma's chats.

== Changelog ==

See CHANGELOG.md in the plugin, or the release notes at https://github.com/21press/sigma/releases.
