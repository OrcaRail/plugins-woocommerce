(function () {
	'use strict';

	var settings = typeof wc === 'undefined' || !wc.wcSettings
		? null
		: wc.wcSettings.getSetting('orcarail_data', null);

	if (!settings || typeof wc === 'undefined' || !wc.wcBlocksRegistry) {
		return;
	}

	var decodeEntities = wp.htmlEntities.decodeEntities;
	var createElement = wp.element.createElement;
	var registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;

	var Label = function () {
		var children = [
			createElement(
				'span',
				{ key: 'title', className: 'wc-block-components-payment-method-label' },
				decodeEntities(settings.title || 'OrcaRail')
			)
		];

		if (settings.icon) {
			children.push(
				createElement('img', {
					key: 'icon',
					src: settings.icon,
					alt: decodeEntities(settings.title || 'OrcaRail'),
					style: { marginLeft: '0.5rem', height: '24px', width: '24px' }
				})
			);
		}

		return createElement('span', { style: { display: 'flex', alignItems: 'center' } }, children);
	};

	var Content = function () {
		return createElement(
			'div',
			null,
			decodeEntities(settings.description || '')
		);
	};

	registerPaymentMethod({
		name: 'orcarail',
		label: createElement(Label, null),
		ariaLabel: decodeEntities(settings.title || 'OrcaRail'),
		content: createElement(Content, null),
		edit: createElement(Content, null),
		canMakePayment: function () {
			return true;
		},
		supports: {
			features: settings.supports || ['products']
		}
	});
})();
