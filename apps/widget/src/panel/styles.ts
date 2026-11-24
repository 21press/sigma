// Panel CSS, from the design spec v1. Tokens live in ../bubble-css.ts.
// 0.1.2 spacing (owner feedback): chat turns 24px apart, label→body 8px, Markdown blocks 12px,
// list items 6px, line-height 1.55, chips and notice 16px below the answer, body padding 20px from 640px.
// Changed on purpose: px instead of rem (theme root font-size must not reach the widget); explicit resets on
// buttons/inputs (`all: unset` is not used on form controls so focus rings and a11y stay native); a few states the
// reference CSS didn't cover (loading, link-style buttons, left side, forced colours).
export const panelCss = `
.da-sr{position:absolute!important;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}
button,input,textarea{font:inherit;color:inherit;margin:0;letter-spacing:inherit}
p,h2{margin:0}
.da-panel{position:fixed;z-index:var(--da-z);bottom:calc(var(--da-launcher-offset-y) + env(safe-area-inset-bottom) + 72px);right:var(--da-launcher-offset-x);width:380px;max-width:calc(100vw - 32px);height:min(640px,calc(100vh - 120px - var(--da-panel-reserve,0px)));height:min(640px,calc(100dvh - 120px - var(--da-panel-reserve,0px)));display:flex;flex-direction:column;overflow:hidden;background:var(--da-surface);color:var(--da-ink);border:var(--da-bw) solid var(--da-border);border-radius:var(--da-radius-lg);box-shadow:var(--da-shadow);font:400 14px/1.5 var(--da-font);letter-spacing:normal;text-align:left;text-transform:none;-webkit-font-smoothing:antialiased;transform-origin:bottom right;animation:da-in var(--da-base) var(--da-curve-in)}
:host([data-side=left]) .da-panel{right:auto;left:var(--da-launcher-offset-x);transform-origin:bottom left}
.da-panel[hidden]{display:none}
@keyframes da-in{from{opacity:0;transform:translateY(8px) scale(.98)}}
@media (prefers-reduced-motion:reduce){.da-panel{animation:none}}
@media (max-width:639.98px){.da-panel,:host([data-side=left]) .da-panel{inset:0;width:100%;max-width:none;height:100%;height:100dvh;border:0;border-radius:0;box-shadow:none;padding-bottom:env(safe-area-inset-bottom)}}

.da-header{flex:none;display:flex;align-items:center;gap:4px;min-height:56px;padding:0 4px;border-bottom:1px solid var(--da-border)}
.da-header__id{flex:1 1 auto;min-width:0;display:flex;align-items:center;gap:12px;padding-left:12px}
.da-header__btn+.da-header__id{padding-left:0}
.da-header__logo{flex:none;width:24px;height:24px;border-radius:var(--da-radius-sm);color:var(--da-ink);object-fit:contain}
.da-header__text{min-width:0;display:flex;flex-direction:column}
.da-header__name{font:600 16px/1.3 var(--da-font);letter-spacing:-.01em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--da-ink)}
.da-header__status{font-size:12px;line-height:1.4;color:var(--da-ink-muted)}
.da-header__btn{flex:none;width:44px;height:44px;display:grid;place-items:center;padding:0;border:0;border-radius:var(--da-radius-full);background:transparent;color:var(--da-ink);cursor:pointer;transition:background-color var(--da-fast) var(--da-curve)}
.da-header__btn:hover{background:var(--da-surface-hover)}
.da-header__btn:disabled{color:var(--da-ink-muted);cursor:default;background:transparent}
.da-header__new{margin-right:-4px}
.da-header__btn svg{width:20px;height:20px}

.da-body{position:relative;flex:1 1 auto;overflow-y:auto;overscroll-behavior:contain;padding:16px;display:flex;flex-direction:column;gap:16px;background:var(--da-surface-body)}
/* Home on the body tint like the chat, so the two option cards (on the panel colour) stand out. */
.da-body--home{background:var(--da-surface-body)}
@media (min-width:640px){.da-body{padding:20px}}
.da-center{align-items:center;justify-content:center;text-align:center;gap:12px}
.da-log{display:flex;flex-direction:column;gap:24px}
.da-log:empty{display:none}

.da-greeting{display:flex;flex-direction:column;gap:8px;padding-bottom:12px}
.da-ai{display:inline-block;margin-left:6px;padding:1px 5px;border:1px solid var(--da-border-control);border-radius:4px;font:600 10px/1.3 var(--da-font-mono);letter-spacing:.04em;color:var(--da-ink-muted);vertical-align:1px}
.da-eyebrow,.da-msg__label{font:400 12px/1.3 var(--da-font-mono);letter-spacing:.06em;text-transform:uppercase;color:var(--da-ink-muted)}
.da-greeting__title{font:600 24px/1.25 var(--da-font);letter-spacing:-.015em;text-wrap:balance;color:var(--da-ink)}
.da-greeting__intro{color:var(--da-ink-muted);text-wrap:pretty}
.da-option{display:flex;align-items:center;gap:12px;width:100%;text-align:left;padding:12px;border:var(--da-bw) solid var(--da-border);border-radius:var(--da-radius-inner);background:var(--da-surface);color:var(--da-ink);cursor:pointer;transition:background-color var(--da-fast) var(--da-curve),border-color var(--da-fast) var(--da-curve)}
.da-option:hover{background:var(--da-surface-hover);border-color:var(--da-border-control)}
.da-option__icon{flex:none;width:40px;height:40px;display:grid;place-items:center;border-radius:var(--da-radius-md);background:var(--da-surface-body)}
.da-option__icon svg{width:20px;height:20px}
.da-option__text{flex:1 1 auto;display:flex;flex-direction:column}
.da-option__title{font-size:16px;font-weight:500;line-height:1.35}
.da-option__desc{font-size:14px;color:var(--da-ink-muted);display:flex;align-items:center;gap:6px}
.da-option__desc--clip{display:block;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.da-option__text{min-width:0}
.da-option{--da-row:var(--da-surface)}
.da-option:hover{--da-row:var(--da-surface-hover)}
.da-option--human{padding-left:16px}
.da-team{display:flex;align-items:center;flex:none;padding-left:8px}
.da-team__a{position:relative;display:grid;place-items:center;width:28px;height:28px;flex:none;border-radius:50%;object-fit:cover;box-shadow:0 0 0 2px var(--da-row);background:var(--da-surface-hover);font:600 12px/1 var(--da-font);color:var(--da-ink)}
.da-team__a+.da-team__a{margin-left:-8px}
.da-team__i{background:color-mix(in srgb,var(--da-brand) 22%,var(--da-surface))}
.da-team__more{z-index:0;font-size:11px;letter-spacing:-.02em;padding-left:8px} /* centre the count in the part not covered by the previous avatar */
.da-msg__head{display:flex;align-items:center;gap:8px}
.da-bot-av{flex:none}
.da-remy{display:grid;place-items:center;border-radius:50%;overflow:hidden;background:var(--da-brand);color:var(--da-on-brand)}
.da-remy svg{display:block;width:62%;height:62%}
.da-remy--assistant svg,.da-remy--remy svg{width:100%;height:100%}
.da-remy--remy svg{--remy-fill:var(--da-brand);color:var(--da-on-brand)}
.da-remy img{display:block;width:100%;height:100%;object-fit:cover}
.da-header__id .da-bot-av{margin-right:0}
.da-option--bot,.da-option--continue{padding-left:16px}
.da-option .da-bot-av{box-shadow:0 0 0 2px var(--da-row)}
.da-option>svg:last-child{width:18px;height:18px;color:var(--da-ink-muted)}

.da-msg{display:flex;flex-direction:column;gap:8px;max-width:100%}
.da-msg__body{overflow-wrap:anywhere}
.da-msg--visitor{align-self:flex-end;max-width:85%}
.da-msg--visitor .da-msg__body{padding:8px 12px;border-radius:12px 12px 4px 12px;background:var(--da-brand);color:var(--da-on-brand);white-space:pre-wrap}
.da-msg--ai{align-self:stretch}
.da-msg--agent{align-self:flex-start;max-width:85%}
.da-msg--agent .da-msg__body{padding:8px 12px;border-radius:12px 12px 12px 4px;background:var(--da-surface-hover)}
.md{line-height:1.55}
.md p,.md ul,.md ol,.md pre{margin:0 0 12px}
.md>:last-child{margin-bottom:0}
.md ul,.md ol{padding-left:1.25em}
.md li+li{margin-top:6px}
.md code{font-family:var(--da-font-mono);font-size:13px;padding:1px 5px;border-radius:var(--da-radius-sm);background:var(--da-surface-hover)}
.md pre{padding:8px 12px;border-radius:var(--da-radius-md);background:var(--da-surface-hover);overflow-x:auto;white-space:pre}
.md pre code{padding:0;background:none}
.md a,.da-privacy a{color:var(--da-ink);text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:3px;text-decoration-color:var(--da-border-control)}
.md a:hover{text-decoration-color:currentColor}
.da-msg__meta{font-size:12px;color:var(--da-ink-muted)}
.da-msg__meta--error{color:var(--da-danger);display:flex;align-items:center;gap:4px;align-self:flex-end}
.da-msg__meta--error svg{width:14px;height:14px}
.da-link-btn{padding:0;border:0;background:none;color:inherit;font:inherit;text-decoration:underline;text-underline-offset:2px;cursor:pointer;min-height:24px}
.da-link-btn[disabled]{opacity:.5;cursor:not-allowed}

.da-sources{display:flex;flex-direction:column;gap:8px;margin-top:16px}
.da-sources__list{display:flex;flex-wrap:wrap;gap:8px;margin:0;padding:0;list-style:none}
.da-chip{position:relative;display:inline-flex;align-items:center;gap:6px;max-width:100%;min-height:36px;padding:0 12px 0 10px;border:var(--da-bw) solid var(--da-border-control);border-radius:var(--da-radius-full);color:var(--da-ink);text-decoration:none;font-size:14px;line-height:1.2;transition:background-color var(--da-fast) var(--da-curve),border-color var(--da-fast) var(--da-curve)}
.da-chip::after{content:"";position:absolute;inset:-4px 0}
.da-chip:hover{background:var(--da-surface-hover);border-color:var(--da-ink)}
.da-chip svg{width:16px;height:16px;color:var(--da-ink-muted)}
.da-chip__title{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:240px}

.da-stream{margin-top:8px}
.da-stream__text{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.55}
.da-msg--partial .da-msg__body{color:var(--da-ink-muted)}
.da-msg--partial .da-msg__meta--error{align-self:flex-start}
.da-typing-row{display:flex;align-items:center;gap:8px}
.da-typing{display:inline-flex;gap:5px;align-items:center;height:21px}
.da-typing i{width:6px;height:6px;border-radius:50%;background:var(--da-ink-muted);animation:da-dot 1.2s infinite ease-in-out}
.da-typing i:nth-child(2){animation-delay:.15s}
.da-typing i:nth-child(3){animation-delay:.3s}
@keyframes da-dot{0%,60%,100%{opacity:.3}30%{opacity:1}}
.da-typing__slow{font-size:12px;color:var(--da-ink-muted)}
@media (prefers-reduced-motion:reduce){.da-typing i{animation:none;opacity:.7}}
.da-system{align-self:center;font-size:12px;color:var(--da-ink-muted);text-align:center}

.da-notice{display:grid;grid-template-columns:20px 1fr;column-gap:12px;row-gap:4px;padding:12px 16px 16px 12px;border-radius:var(--da-radius-inner);background:var(--da-surface);border:var(--da-bw) solid var(--da-border)}
.da-notice>svg{width:20px;height:20px;margin-top:1px;color:var(--da-ink-muted)}
.da-notice__title{font-weight:600}
.da-notice__text{color:var(--da-ink-muted);grid-column:2}
.da-notice__actions{grid-column:2;display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
.da-notice--warning{background:var(--da-warning-bg);border-color:transparent}
.da-notice--warning>svg{color:var(--da-warning)}
.da-notice--danger{background:var(--da-danger-bg);border-color:transparent}
.da-notice--danger>svg{color:var(--da-danger)}
.da-notice--warning .da-notice__text,.da-notice--danger .da-notice__text{color:var(--da-ink)}
.da-msg .da-notice{margin-top:16px}

.da-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:0 24px;border:var(--da-bw) solid transparent;border-radius:var(--da-radius-full);font:500 16px/1 var(--da-font);cursor:pointer;text-decoration:none;transition:background-color var(--da-fast) var(--da-curve),color var(--da-fast) var(--da-curve),border-color var(--da-fast) var(--da-curve)}
.da-btn svg{width:18px;height:18px}
.da-btn--primary{background:var(--da-brand);color:var(--da-on-brand);border-color:var(--da-brand)}
.da-btn--primary:hover{background:var(--da-brand-hover);border-color:transparent}
.da-btn--secondary{background:transparent;color:var(--da-ink);border-color:var(--da-ink)}
.da-btn--secondary:hover{background:var(--da-brand);border-color:var(--da-brand);color:var(--da-on-brand)}
.da-btn--ghost{background:transparent;color:var(--da-ink);padding:0 12px}
.da-btn--ghost:hover{background:var(--da-surface-hover)}
.da-btn--sm{min-height:36px;padding:0 16px;font-size:14px}
.da-btn--ghost.da-btn--sm{padding:0 12px}
.da-btn--block{width:100%}
.da-btn[disabled],.da-btn[aria-disabled=true]{opacity:.45;cursor:not-allowed}

:is(.da-btn,.da-header__btn,.da-option,.da-chip,.da-link-btn,.md a,.da-privacy a,.da-composer__send):focus-visible{outline:2px solid var(--da-focus);outline-offset:2px}
:focus{outline:none}
[tabindex="-1"]:focus-visible{outline:none}

.da-form{display:flex;flex-direction:column;gap:16px}
.da-field{display:flex;flex-direction:column;gap:6px}
.da-label{font-size:14px;font-weight:500}
.da-input{display:block;width:100%;min-height:44px;padding:10px 12px;font:400 16px/1.4 var(--da-font);color:var(--da-ink);background:var(--da-surface-sunken);border:var(--da-bw) solid var(--da-border-control);border-radius:var(--da-radius-md)}
textarea.da-input{min-height:112px;resize:vertical;max-height:280px}
.da-input::placeholder,.da-composer__input::placeholder{color:var(--da-ink-muted);opacity:1}
.da-input:hover{border-color:var(--da-ink-muted)}
.da-input:focus-visible{outline:2px solid var(--da-focus);outline-offset:1px;border-color:var(--da-ink)}
.da-input[aria-invalid=true]{border-color:var(--da-danger)}
.da-input[readonly]{opacity:.7}
.da-hint{font-size:12px;color:var(--da-ink-muted)}
.da-error{font-size:12px;color:var(--da-danger);display:flex;align-items:center;gap:4px}
.da-error svg{width:14px;height:14px}
.da-hp{position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden}
.da-challenge{min-height:65px}
.da-challenge__box{width:100%}
.da-gate{position:absolute;left:16px;right:16px;bottom:96px;z-index:2;display:flex;flex-direction:column;gap:8px;padding:16px;background:var(--da-surface);color:var(--da-ink);border:var(--da-bw) solid var(--da-border-control);border-radius:var(--da-radius-inner);box-shadow:var(--da-shadow)}

.da-footer{flex:none;padding:12px 16px;border-top:1px solid var(--da-border);background:var(--da-surface);display:flex;flex-direction:column;gap:8px}
.da-composer{display:flex;align-items:flex-end;gap:8px;padding:4px 4px 4px 12px;border:var(--da-bw) solid var(--da-border-control);border-radius:22px;background:var(--da-surface-sunken);transition:border-color var(--da-fast) var(--da-curve)}
.da-composer:hover{border-color:var(--da-ink-muted)}
.da-composer:focus-within{outline:2px solid var(--da-focus);outline-offset:1px;border-color:var(--da-ink)}
.da-composer__input{flex:1 1 auto;display:block;min-height:34px;max-height:120px;padding:7px 0;resize:none;border:0;outline:0;background:transparent;font:400 16px/1.3 var(--da-font);color:var(--da-ink)}
.da-composer__send{flex:none;width:36px;height:36px;display:grid;place-items:center;padding:0;border:0;border-radius:var(--da-radius-full);background:var(--da-brand);color:var(--da-on-brand);cursor:pointer;transition:background-color var(--da-fast) var(--da-curve)}
.da-composer__send:hover{background:var(--da-brand-hover)}
.da-composer__mic{flex:none;width:36px;height:36px;display:grid;place-items:center;padding:0;border:0;border-radius:var(--da-radius-full);background:transparent;color:var(--da-ink-muted);cursor:pointer;transition:background-color var(--da-fast) var(--da-curve),color var(--da-fast) var(--da-curve)}
.da-composer__mic svg{width:20px;height:20px}
.da-composer__mic:hover{background:var(--da-surface-hover);color:var(--da-ink)}
.da-composer__mic:focus-visible{outline:2px solid var(--da-focus);outline-offset:2px}
.da-composer__mic[aria-pressed=true]{background:var(--da-brand);color:var(--da-on-brand);animation:da-mic 1.4s ease-in-out infinite}
.da-composer__mic:disabled{color:var(--da-ink-muted);cursor:not-allowed;opacity:.6}
@keyframes da-mic{0%,100%{box-shadow:0 0 0 0 color-mix(in srgb,var(--da-brand) 45%,transparent)}50%{box-shadow:0 0 0 6px color-mix(in srgb,var(--da-brand) 0%,transparent)}}
@media (prefers-reduced-motion:reduce){.da-composer__mic[aria-pressed=true]{animation:none;box-shadow:0 0 0 3px color-mix(in srgb,var(--da-brand) 35%,transparent)}}
.da-voice-msg{margin:6px 4px 0;font-size:13px;line-height:1.4;color:var(--da-ink-muted)}
.da-composer__send svg{width:18px;height:18px}
.da-composer__send:disabled{background:var(--da-surface-hover);color:var(--da-ink-muted);cursor:not-allowed}
.da-composer--disabled{background:var(--da-surface);border-style:dashed}
.da-composer--disabled .da-composer__input{cursor:not-allowed}
.da-privacy{font-size:12px;line-height:1.5;color:var(--da-ink-muted);text-wrap:pretty}
.da-privacy a{color:inherit;text-underline-offset:2px;text-decoration-color:currentColor}
.da-count{text-align:right;font-variant-numeric:tabular-nums}

.da-end{margin:auto 0;display:flex;flex-direction:column;align-items:center;text-align:center;gap:8px;padding:24px 16px}
.da-end__icon{width:56px;height:56px;display:grid;place-items:center;border-radius:var(--da-radius-full);background:var(--da-surface);border:var(--da-bw) solid var(--da-border);color:var(--da-success);margin-bottom:8px}
.da-end__icon svg{width:26px;height:26px}
.da-end__title{font:600 18px/1.4 var(--da-font);letter-spacing:-.01em}
.da-end__text{color:var(--da-ink-muted);max-width:30ch;text-wrap:pretty}
.da-end__text strong{color:var(--da-ink);font-weight:500;overflow-wrap:anywhere}
.da-end .da-btn{margin-top:16px}
.da-spinner{width:24px;height:24px;border-radius:50%;border:2px solid var(--da-border);border-top-color:var(--da-ink);animation:da-spin .8s linear infinite}
@media (prefers-reduced-motion:reduce){.da-spinner{animation-duration:2.4s}}
@media (forced-colors:active){.da-panel,.da-chip,.da-btn,.da-composer,.da-input,.da-option,.da-notice{border:1px solid CanvasText}}
`;
