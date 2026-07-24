(function (root, factory) {
	'use strict';
	var api = factory();
	if (typeof module === 'object' && module.exports) {
		module.exports = api;
	}
	root.OlamaSms = api;
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
	'use strict';
	var basic = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ\u001bÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
	var extension = '^{}\\[~]|€';

	function info(text) {
		var chars = Array.from(String(text || ''));
		var encoding = 'gsm7';
		var units = 0;
		chars.some(function (character) {
			if (basic.indexOf(character) !== -1) {
				units += 1;
			} else if (extension.indexOf(character) !== -1) {
				units += 2;
			} else {
				encoding = 'unicode';
				units = chars.length;
				return true;
			}
			return false;
		});
		var single = encoding === 'gsm7' ? 160 : 70;
		var multi = encoding === 'gsm7' ? 153 : 67;
		return {
			encoding: encoding,
			char_count: chars.length,
			encoded_units: units,
			sms_parts: units === 0 ? 0 : (units <= single ? 1 : Math.ceil(units / multi)),
			single_part_limit: single,
			multipart_limit: multi,
			chars_per_part: units <= single ? single : multi
		};
	}
	return { info: info };
}));
