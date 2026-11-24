<?php
/**
 * Plugin Name:       Sigma
 * Plugin URI:        https://github.com/21press/sigma
 * Description:       A chat box for your site: AI answers drawn only from your own docs and knowledge base, with links, and a "talk to a person" path that emails your support address.
 * Version:           0.2.5
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            21press
 * Author URI:        https://21press.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sigma
 * Domain Path:       /languages
 * Update URI:        https://github.com/21press/sigma
 *
 * A standalone plugin: it works with any theme. Formerly "Delta Assistant": storage keeps that
 * legacy prefix (options `delta_assistant_*`, tables `{prefix}da_*`, post type `da_knowledge`), so
 * switching over keeps every setting, conversation and knowledge entry.
 *
 * What it stores: two tables (conversations, messages) and a counters table, a settings option, the
 * docs corpus option, knowledge base entries (a private post type) and usage counters. What it sends
 * off-site: the visitor's chat text and the matching docs and knowledge, to the AI provider chosen in
 * Settings (Anthropic or OpenAI); never a visitor's name or email. See readme.txt, "External services".
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( defined( 'P21_SIGMA_VERSION' ) ) {
	return;
}

define( 'P21_SIGMA_VERSION', '0.2.5' );
define( 'P21_SIGMA_FILE', __FILE__ );
define( 'P21_SIGMA_DIR', __DIR__ );

require_once __DIR__ . '/includes/class-hooks.php';
require_once __DIR__ . '/includes/class-config.php';
require_once __DIR__ . '/includes/class-schema.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-pricing.php';
require_once __DIR__ . '/includes/class-usage.php';
require_once __DIR__ . '/includes/conversations/class-repository.php';
require_once __DIR__ . '/includes/security/class-visitor-token.php';
require_once __DIR__ . '/includes/security/class-cloudflare.php';
require_once __DIR__ . '/includes/security/class-rate-limiter.php';
require_once __DIR__ . '/includes/security/class-ask-slots.php';
require_once __DIR__ . '/includes/security/class-turnstile.php';
require_once __DIR__ . '/includes/knowledge/class-extractor.php';
require_once __DIR__ . '/includes/knowledge/class-retriever.php';
require_once __DIR__ . '/includes/knowledge/class-corpus.php';
require_once __DIR__ . '/includes/knowledge/class-knowledge-base.php';
require_once __DIR__ . '/includes/ai/interface-provider.php';
require_once __DIR__ . '/includes/ai/interface-streaming-provider.php';
require_once __DIR__ . '/includes/ai/class-prompt.php';
require_once __DIR__ . '/includes/ai/class-answer.php';
require_once __DIR__ . '/includes/ai/class-sse-parser.php';
require_once __DIR__ . '/includes/ai/class-holdback.php';
require_once __DIR__ . '/includes/ai/class-stream-transport.php';
require_once __DIR__ . '/includes/ai/class-anthropic-provider.php';
require_once __DIR__ . '/includes/ai/class-openai-provider.php';
require_once __DIR__ . '/includes/ai/class-mock-provider.php';
require_once __DIR__ . '/includes/ai/class-link-filter.php';
require_once __DIR__ . '/includes/ai/class-answerer.php';
require_once __DIR__ . '/includes/mail/class-transport.php';
require_once __DIR__ . '/includes/mail/class-smtp-check.php';
require_once __DIR__ . '/includes/mail/class-mailer.php';
require_once __DIR__ . '/includes/team/class-team.php';
require_once __DIR__ . '/includes/team/class-avatar-status.php';
require_once __DIR__ . '/includes/team/class-avatars.php';
require_once __DIR__ . '/includes/team/class-profile.php';
require_once __DIR__ . '/includes/privacy/class-privacy.php';
require_once __DIR__ . '/includes/rest/class-sse.php';
require_once __DIR__ . '/includes/rest/class-visitor-controller.php';
require_once __DIR__ . '/includes/rest/class-admin-controller.php';
require_once __DIR__ . '/includes/rest/class-knowledge-controller.php';
require_once __DIR__ . '/includes/admin/class-admin-page.php';
require_once __DIR__ . '/includes/admin/class-build-check.php';
require_once __DIR__ . '/includes/admin/class-notices.php';
require_once __DIR__ . '/includes/admin/class-remy-icon.php';
require_once __DIR__ . '/includes/class-widget-strings.php';
require_once __DIR__ . '/includes/class-widget.php';
require_once __DIR__ . '/includes/class-switch-over.php';
require_once __DIR__ . '/includes/class-updater.php';
require_once __DIR__ . '/includes/class-plugin.php';

register_activation_hook( __FILE__, array( \TwentyOnePress\Sigma\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \TwentyOnePress\Sigma\Plugin::class, 'deactivate' ) );

\TwentyOnePress\Sigma\Plugin::boot();
