<?php
namespace UtsovAPI;
require(dirname(__FILE__).'/email-builder.php');

$type = isset($_GET['type']) ? $_GET['type'] : 'donation';

if ($type === 'booking') {
    $url = buildEmailImage(
        'BOOKING CONFIRMED', false,
        "You're going to UTSOV 2026!",
        array("Dear Sanjit Das,", "Your payment of \$220.00 has been received.", "Your 2 passes are confirmed!"),
        'BOOKING DETAILS',
        array(
            array('Passes',          'UTSOV 2026 x 2 passes',   false, false, false),
            array('Days',            'Both Days (Oct 17 & 18)',  true,  false, false),
            array('Adults',          '1 adult + 2 children',     false, false, false),
            array('Meal Preference', 'Vegetarian',               true,  false, false),
            array('Total Paid',      '$220.00',                  true,  true,  false),
            array('Order ID',        '3XY45940A9285905E',        false, false, true),
        ),
        array('Full schedule & venue details will be shared closer to', 'October 17-18, New Jersey. See you there!'),
        'TEST_BOOKING_V2'
    );
} else {
    $url = buildEmailImage(
        'DONATION CONFIRMED', false,
        'Thank you for supporting UTSOV 2026!',
        array("Dear testing34,", "Your generous donation of \$500.00 has been received.", "Your support means everything to our community."),
        'DONATION DETAILS',
        array(
            array('Amount',    '$500.00',              false, true,  false),
            array('Date',      '2026-07-04 15:50:41',  true,  false, false),
            array('Reference', '1VA62035BH72180SI',    false, false, true),
        ),
        array('Please keep this email for your records.', 'Every donation helps bring our community together.'),
        'TEST_DONATION_V2'
    );
}

if ($url) { header('Location: ' . $url); } else { echo 'buildEmailImage() returned null'; }
?>
