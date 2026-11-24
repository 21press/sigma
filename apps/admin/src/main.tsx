import { createRoot } from 'react-dom/client';
import { ConversationsScreen } from './ConversationsScreen';
import { KnowledgeScreen } from './KnowledgeScreen';
import { SettingsScreen } from './SettingsScreen';
import './alpha.css';
import './styles.css';

const el = document.getElementById( 'sigma-admin' );
if ( el ) {
	// The page frame's fallback (Admin_Page::render) checks this: the app is running.
	el.dataset.mounted = '1';
	const screen = el.dataset.screen;
	createRoot( el ).render( screen === 'conversations' ? <ConversationsScreen /> : screen === 'knowledge' ? <KnowledgeScreen /> : <SettingsScreen /> );
}
