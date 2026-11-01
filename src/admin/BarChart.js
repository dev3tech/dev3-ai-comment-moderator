import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { formatNumber } from '../core3/ui';

export const SERIES = [
	{ key: 'live', label: __( 'Live', 'dev3-ai-comment-moderator' ) },
	{ key: 'held', label: __( 'Held', 'dev3-ai-comment-moderator' ) },
	{ key: 'spam', label: __( 'Spam', 'dev3-ai-comment-moderator' ) },
];

/**
 * Day label.
 *
 * @param {string} date Y-m-d.
 * @return {string} Label.
 */
const dayLabel = ( date ) => {
	const d = new Date( `${ date }T12:00:00Z` );
	return isNaN( d ) ? date : d.toLocaleDateString( undefined, { month: 'short', day: 'numeric' } );
};

/**
 * Stacked daily bars (live / held / spam) with a focusable tooltip and a screen-reader table.
 *
 * @param {Object} props      Props.
 * @param {Array}  props.data [ { date, live, held, spam } ].
 * @return {Element} Chart.
 */
export default function BarChart( { data = [] } ) {
	const [ hover, setHover ] = useState( null );
	const totals = data.map( ( d ) => d.live + d.held + d.spam );
	const max = Math.max( 1, ...totals );
	const nice = max <= 4 ? 4 : Math.ceil( max / 4 ) * 4;
	const active = hover !== null ? data[ hover ] : null;
	return (
		<div className="dev3cm-chart">
			<div className="dev3cm-chart__plot" onMouseLeave={ () => setHover( null ) }>
				<div className="dev3cm-chart__grid" aria-hidden="true">
					<span>{ formatNumber( nice ) }</span>
					<span>{ formatNumber( nice / 2 ) }</span>
					<span>0</span>
				</div>
				<div className="dev3cm-chart__cols">
					{ data.map( ( d, i ) => (
						<button
							type="button"
							key={ d.date }
							className={ 'dev3cm-chart__col' + ( hover === i ? ' is-active' : '' ) }
							onMouseEnter={ () => setHover( i ) }
							onFocus={ () => setHover( i ) }
							onBlur={ () => setHover( null ) }
							aria-label={ `${ dayLabel( d.date ) }: ${ SERIES.map( ( s ) => `${ d[ s.key ] } ${ s.label }` ).join( ', ' ) }` }
						>
							<span className="dev3cm-chart__stack" style={ { height: `${ ( totals[ i ] / nice ) * 100 }%` } }>
								{ SERIES.map( ( s ) =>
									d[ s.key ] ? <span key={ s.key } className={ `dev3cm-chart__seg dev3cm-chart__seg--${ s.key }` } style={ { flexGrow: d[ s.key ] } } /> : null
								) }
							</span>
						</button>
					) ) }
				</div>
				{ active && (
					<div className="dev3cm-chart__tip" role="status" style={ { left: `${ ( ( hover + 0.5 ) / data.length ) * 100 }%` } }>
						<strong>{ dayLabel( active.date ) }</strong>
						{ SERIES.map( ( s ) => (
							<span key={ s.key }>
								<i className={ `dev3cm-chart__key dev3cm-chart__key--${ s.key }` } /> { s.label }: { active[ s.key ] }
							</span>
						) ) }
					</div>
				) }
			</div>
			<div className="dev3cm-chart__axis" aria-hidden="true">
				<span>{ data.length ? dayLabel( data[ 0 ].date ) : '' }</span>
				<span>{ data.length ? dayLabel( data[ data.length - 1 ].date ) : '' }</span>
			</div>
			<div className="dev3cm-chart__legend" aria-hidden="true">
				{ SERIES.map( ( s ) => (
					<span key={ s.key }>
						<i className={ `dev3cm-chart__key dev3cm-chart__key--${ s.key }` } /> { s.label }
					</span>
				) ) }
			</div>
			<table className="dev3cm-ui-sr-only">
				<caption>{ __( 'Screened items per day', 'dev3-ai-comment-moderator' ) }</caption>
				<thead>
					<tr>
						<th scope="col">{ __( 'Day', 'dev3-ai-comment-moderator' ) }</th>
						{ SERIES.map( ( s ) => (
							<th scope="col" key={ s.key }>
								{ s.label }
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ data.map( ( d ) => (
						<tr key={ d.date }>
							<td>{ dayLabel( d.date ) }</td>
							{ SERIES.map( ( s ) => (
								<td key={ s.key }>{ d[ s.key ] }</td>
							) ) }
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}
