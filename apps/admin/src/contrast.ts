/** WCAG relative luminance of a #rrggbb colour. */
function luminance( hex: string ): number {
	const n = parseInt( hex.slice( 1 ), 16 );
	const c = [ ( n >> 16 ) & 255, ( n >> 8 ) & 255, n & 255 ].map( ( v ) => {
		const s = v / 255;
		return s <= 0.03928 ? s / 12.92 : Math.pow( ( s + 0.055 ) / 1.055, 2.4 );
	} );
	return 0.2126 * c[ 0 ] + 0.7152 * c[ 1 ] + 0.0722 * c[ 2 ];
}

export function ratio( a: string, b: string ): number {
	const [ l1, l2 ] = [ luminance( a ), luminance( b ) ].sort( ( x, y ) => y - x );
	return ( l1 + 0.05 ) / ( l2 + 0.05 );
}

/**
 * The best contrast text on a brand colour gets, with the widget's two text colours
 * (the design spec: #15171B or #FFFFFF). Under 4.5 the screen warns.
 */
export function onBrandContrast( brand: string ): number {
	if ( ! /^#[0-9a-fA-F]{6}$/.test( brand ) ) {
		return 21;
	}
	return Math.max( ratio( brand, '#15171B' ), ratio( brand, '#FFFFFF' ) );
}

/** The neutral defaults: what the bubble shows when an owner hasn't picked colours (apps/widget bubble-css;
 *  brand = the color_light / color_dark defaults in includes/class-settings.php). */
export const PALETTE_DEFAULTS = {
	light: { bg: '#ffffff', text: '#1d1d1f', brand: '#1d1d1f' },
	dark: { bg: '#1c1c1e', text: '#f5f5f7', brand: '#f5f5f7' },
} as const;

/** Contrast of two #rrggbb colours; 21 when either isn't a full hex value (no warning). */
export function pairContrast( a: string, b: string ): number {
	const ok = ( v: string ) => /^#[0-9a-fA-F]{6}$/.test( v );
	return ok( a ) && ok( b ) ? ratio( a, b ) : 21;
}
