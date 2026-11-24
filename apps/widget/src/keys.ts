// Storage keys shared by the bubble and the panel.
/** localStorage: {uuid, token, at}. The visitor's conversation secret (PLAN §8). */
export const TOKEN_KEY = 'delta-assistant:v1';
/** sessionStorage: rendered transcript + view (PLAN §8.1). Cleared when the tab closes. */
export const CHAT_KEY = 'delta-assistant:chat';
/** sessionStorage: "1" while the panel is open, so the next page in this tab reopens it. */
export const OPEN_KEY = 'delta-assistant:open';
/** sessionStorage, per visit (§8.5 v1.11): {opened, quiet, teaser}. opened: the chat was opened this visit;
 *  quiet: the teaser's × was used; teaser: 'open' while it shows (it stays on the next page), 'done' after. */
export const VISIT_KEY = 'delta-assistant:visit';
