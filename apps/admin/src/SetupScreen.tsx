import { useState } from 'react';
import type { Draft, SetFn } from './SettingsScreen';
import type { Settings, SettingsResponse } from './types';
import { Check, Icon, Notice, Toggle } from './ui';
import { __, sprintf } from '@wordpress/i18n';

const STEPS = [ __( 'AI provider', 'sigma' ), __( 'Knowledge', 'sigma' ), __( 'Handoffs', 'sigma' ), __( 'Go live', 'sigma' ) ];

/**
 * First-run setup (Settings → #setup): the four things a new site needs, one per step.
 * Each Continue saves what the step changed; Skip setup goes back to the tabs.
 */
export function SetupScreen( { data, s, draft, set, save, busy, error }: { data: SettingsResponse; s: Settings; draft: Draft; set: SetFn; save: ( changes?: Draft ) => Promise< boolean >; busy: boolean; error: string } ) {
	const [ step, setStep ] = useState( 0 );
	const last = step === STEPS.length - 1;

	const next = async () => {
		if ( Object.keys( draft ).length > 0 && ! ( await save() ) ) {
			return;
		}
		if ( last ) {
			window.location.hash = '#chat-box';
		} else {
			setStep( step + 1 );
		}
	};

	const provider = s.provider;
	const keyField = provider === 'openai' ? 'openai_key' : 'anthropic_key';
	const keyStatus = provider === 'openai' ? data.settings.openai_key : data.settings.anthropic_key;

	return (
		<div className="onboarding">
			<div className="onboarding__panel">
				<div className="stepper">
					{ STEPS.map( ( label, i ) => (
						<div key={ label } className={ `stepper__step${ i < step ? ' stepper__step--complete' : '' }${ i === step ? ' stepper__step--current' : '' }` } aria-current={ i === step ? 'step' : undefined }>
							<span className="stepper__marker">{ i < step ? <Icon name="check" /> : i + 1 }</span>
							{ label }
						</div>
					) ) }
				</div>
				<section className="card">
					{ step === 0 && (
						<>
							<div className="card__heading">
								<h3 className="card__title">{ __( 'Connect the AI', 'sigma' ) }</h3>
								<p className="card__description">{ __( 'The agent answers visitors with your AI provider. The key stays encrypted on this site.', 'sigma' ) }</p>
							</div>
							<div className="field">
								<label className="field__label" htmlFor="da-setup-provider">
									{ __( 'Provider', 'sigma' ) }
								</label>
								<select id="da-setup-provider" className="field__select" value={ provider } onChange={ ( e ) => set( 'provider', e.target.value as Settings[ 'provider' ] ) }>
									<option value="anthropic">{ __( 'Anthropic (Claude)', 'sigma' ) }</option>
									<option value="openai">{ __( 'OpenAI', 'sigma' ) }</option>
									{ data.settings.mock_allowed && <option value="mock">{ __( 'Test provider (no AI, local sites only)', 'sigma' ) }</option> }
								</select>
							</div>
							{ provider !== 'mock' &&
								( keyStatus.source === 'constant' ? (
									<Notice>{ /* translators: %s: the key's last 4 characters. */ sprintf( __( 'The key is set in wp-config.php (…%s).', 'sigma' ), keyStatus.last4 ) }</Notice>
								) : (
									<div className="field">
										<label className="field__label" htmlFor="da-setup-key">
											{ __( 'API key', 'sigma' ) }
										</label>
										<input id="da-setup-key" className="field__input field__input--wide" type="password" autoComplete="off" placeholder={ keyStatus.set ? /* translators: %s: the key's last 4 characters. */ sprintf( __( 'Saved (…%s). Type to replace.', 'sigma' ), keyStatus.last4 ) : __( 'Paste the key', 'sigma' ) } value={ ( draft[ keyField ] as string ) ?? '' } onChange={ ( e ) => set( keyField, e.target.value ) } />
										<p className="field__hint">{ __( 'Stored encrypted. It is never shown again or sent to the browser.', 'sigma' ) }</p>
									</div>
								) ) }
						</>
					) }
					{ step === 1 && (
						<>
							<div className="card__heading">
								<h3 className="card__title">{ __( 'What should the agent answer from?', 'sigma' ) }</h3>
								<p className="card__description">{ __( 'The agent only answers from the content you pick here.', 'sigma' ) }</p>
							</div>
							<div className="field">
								<span className="field__label">{ __( 'Sources', 'sigma' ) }</span>
								<div className="stack stack--tight">
									{ data.post_types.map( ( t ) => (
										<Check key={ t.name } label={ t.label } hint={ /* translators: 1: post type name, 2: number of published posts. */ sprintf( __( '%1$s, %2$d published', 'sigma' ), t.name, t.published ) } checked={ s.sources.includes( t.name ) } onChange={ ( on ) => set( 'sources', on ? [ ...s.sources, t.name ] : s.sources.filter( ( x ) => x !== t.name ) ) } />
									) ) }
								</div>
								<p className="field__hint">{ __( 'Published, public posts of these types. Password-protected posts are left out.', 'sigma' ) }</p>
							</div>
						</>
					) }
					{ step === 2 && (
						<>
							<div className="card__heading">
								<h3 className="card__title">{ __( 'Where do handoffs go?', 'sigma' ) }</h3>
								<p className="card__description">{ __( 'When a visitor asks for a person, their message is emailed here. Replies go straight to the visitor.', 'sigma' ) }</p>
							</div>
							<div className="field">
								<label className="field__label" htmlFor="da-setup-email">
									{ __( 'Email', 'sigma' ) }
								</label>
								<input id="da-setup-email" className="field__input" type="email" placeholder={ data.settings.support_email_effective } value={ s.support_email } onChange={ ( e ) => set( 'support_email', e.target.value ) } />
								<p className="field__hint">{ __( 'Empty: the site admin email. Add your team and mail settings later in Human support.', 'sigma' ) }</p>
							</div>
						</>
					) }
					{ step === 3 && (
						<>
							<div className="card__heading">
								<h3 className="card__title">{ __( 'Go live', 'sigma' ) }</h3>
								<p className="card__description">{ __( 'Test the chat box on your site first. When it works, show it to every visitor.', 'sigma' ) }</p>
							</div>
							<div className="stack stack--tight">
								<Toggle label={ __( 'Show the chat box', 'sigma' ) } checked={ s.enabled } disabled={ data.settings.killed_by_constant } onChange={ ( v ) => set( 'enabled', v ) } />
								<Toggle label={ __( 'Show to admins only', 'sigma' ) } hint={ __( 'turn off to show it to everyone', 'sigma' ) } checked={ s.admins_only } onChange={ ( v ) => set( 'admins_only', v ) } />
							</div>
							<div>
								<a className="button" href={ window.SigmaAdmin.homeUrl } target="_blank" rel="noreferrer">
									{ __( 'Open the site', 'sigma' ) }
								</a>
							</div>
						</>
					) }
					{ error && <Notice kind="danger">{ error }</Notice> }
					<div className="card__footer">
						<button className="button" type="button" disabled={ step === 0 || busy } onClick={ () => setStep( step - 1 ) }>
							{ __( 'Back', 'sigma' ) }
						</button>
						<button className="button button--primary" type="button" disabled={ busy } onClick={ () => void next() }>
							{ busy ? __( 'Saving…', 'sigma' ) : last ? __( 'Finish', 'sigma' ) : __( 'Continue', 'sigma' ) }
						</button>
						<a className="button button--link" href="#chat-box">
							{ __( 'Skip setup', 'sigma' ) }
						</a>
					</div>
				</section>
			</div>
		</div>
	);
}
