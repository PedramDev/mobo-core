<?php
/**
 * Standalone test: License Token masking helper used by /health.
 *
 * Run: php tests-dev/health-token-mask-test.php
 */

require __DIR__ . '/stubs.php';
require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-health-reporter.php';

echo "Mobo_Core_Health_Reporter::mask_secret\n";
mobo_assert_same( '****5555', Mobo_Core_Health_Reporter::mask_secret( '11111111-2222-4333-8444-555555555555' ), 'GUID masked to last 4' );
mobo_assert_same( '', Mobo_Core_Health_Reporter::mask_secret( '' ), 'empty stays empty' );
mobo_assert_same( '****', Mobo_Core_Health_Reporter::mask_secret( 'abcd' ), 'short secret fully masked' );
mobo_assert_same( '****5555', Mobo_Core_Health_Reporter::mask_secret( "  11111111-2222-4333-8444-555555555555 \n" ), 'whitespace trimmed' );

mobo_finish();
