/**
 * Scoped Tailwind: every utility is prefixed with `dev3cm-` and only applies inside `.dev3cm-root`,
 * and preflight is off, so nothing leaks into wp-admin, Elementor or WPBakery styles.
 */
module.exports = {
	prefix: 'dev3cm-',
	important: '.dev3cm-root',
	content: [ './src/**/*.js' ],
	corePlugins: { preflight: false },
	theme: {
		extend: {
			colors: {
				brand: { 50: '#eef2ff', 100: '#e0e7ff', 500: '#6366f1', 600: '#4f46e5', 700: '#4338ca' },
			},
		},
	},
};
