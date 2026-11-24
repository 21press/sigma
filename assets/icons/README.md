# Remy's icons (plan §8.8)

One source for every place Remy's icon shows: the widget (launcher, teaser, cards, chat header, "Remy · AI" labels), the wp-admin menu and the Settings header.

- `assistant.svg`: the plugin mark (default). `viewBox` 0 0 32 32, drawn to fill a circle.
- `chat.svg`, `sparkles.svg`, `help.svg`: 1.5 px line icons, `viewBox` 0 0 24 24.
- `remy`: `../remy-avatar.svg`, with its own disc (`--remy-fill`) and mark (`currentColor`).

Every mark uses `currentColor`, so the widget colours it with CSS. The wp-admin menu swaps it for black before base64, so wp-admin tints it like core icons. Change a path here and every place follows.
