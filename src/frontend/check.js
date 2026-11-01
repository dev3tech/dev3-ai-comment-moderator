/**
 * Local "is this unkind?" check (mirrors Data\Text::unkind_signals in PHP). No network.
 */

/**
 * Lowercase words separated by single spaces (letters and digits only).
 *
 * @param {string} text Text.
 * @return {string} Normalised text.
 */
export function normalize( text ) {
	return String( text || '' )
		.toLowerCase()
		.replace( /[^\p{L}\p{N}]+/gu, ' ' )
		.trim();
}

/**
 * Reasons a draft may read as unkind.
 *
 * @param {string}   text  Draft.
 * @param {string[]} words Insult/profanity list.
 * @return {string[]} insult | shouting | pile_on.
 */
export function signals( text, words = [] ) {
	const reasons = [];
	const hay = ` ${ normalize( text ) } `;
	if ( words.some( ( w ) => {
		const n = normalize( w );
		return n && hay.includes( ` ${ n } ` );
	} ) ) {
		reasons.push( 'insult' );
	}
	const letters = String( text ).replace( /[^\p{L}]/gu, '' );
	if ( letters.length >= 12 ) {
		const upper = letters.replace( /[^\p{Lu}]/gu, '' );
		if ( upper.length / letters.length > 0.7 ) {
			reasons.push( 'shouting' );
		}
	}
	if ( /[!?]{4,}/.test( text ) ) {
		reasons.push( 'pile_on' );
	}
	return reasons;
}
