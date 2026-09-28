<?php
require_once __DIR__ . '/../includes/helpers.php';

// Test 1: Empty text
assert(render_expandable_text('') === '', 'Empty string should return empty string');
assert(render_expandable_text(null) === '', 'Null should return empty string');

// Test 2: Short text under threshold
$short = 'This is a brief job overview.';
$short_out = render_expandable_text($short, 100);
assert(strpos($short_out, '<p') !== false, 'Short text should output plain <p>');
assert(strpos($short_out, 'see-more-btn') === false, 'Short text should NOT have a see-more button');
assert(strpos($short_out, 'This is a brief job overview.') !== false, 'Short text should be contained');

// Test 3: Long text exceeding threshold
$long = 'FreeMark is an online marketplace connecting clients with skilled freelancers worldwide. ' .
        'We provide escrow-protected payments, real-time messaging, verified skill testing, and milestone tracking. ' .
        'Join thousands of businesses and independent professionals building great projects together.';
$long_out = render_expandable_text($long, 100, 3, 'custom-test-cls');

assert(strpos($long_out, 'expandable-text') !== false, 'Long text should output expandable-text wrapper');
assert(strpos($long_out, 'clamped-3') !== false, 'Long text should have clamped-3 class');
assert(strpos($long_out, 'see-more-btn') !== false, 'Long text should include see-more button');
assert(strpos($long_out, 'custom-test-cls') !== false, 'Custom extra classes should be preserved');
assert(strpos($long_out, 'data-lucide="chevron-down"') !== false, 'Lucide chevron-down should be present');

// Test 4: Custom clamp lines
$clamped_4 = render_expandable_text($long, 100, 4);
assert(strpos($clamped_4, 'clamped-4') !== false, 'Clamp lines 4 should output clamped-4 class');

echo "PASS: All expandable text helper tests executed successfully!\n";
