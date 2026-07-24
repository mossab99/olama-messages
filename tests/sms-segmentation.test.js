'use strict';
const assert = require('assert');
const sms = require('../assets/sms-segmentation.js');

[
	['', 'gsm7', 0, 0],
	['A'.repeat(160), 'gsm7', 160, 1],
	['A'.repeat(161), 'gsm7', 161, 2],
	['^'.repeat(80), 'gsm7', 160, 1],
	['^'.repeat(81), 'gsm7', 162, 2],
	['ش'.repeat(70), 'unicode', 70, 1],
	['ش'.repeat(71), 'unicode', 71, 2],
	['hello 😀', 'unicode', 7, 1]
].forEach(([text, encoding, units, parts]) => {
	const result = sms.info(text);
	assert.strictEqual(result.encoding, encoding);
	assert.strictEqual(result.encoded_units, units);
	assert.strictEqual(result.sms_parts, parts);
});
console.log('JavaScript SMS segmentation: PASS');
