<?php

defined('ABSPATH') || exit(1);

use Plandalf_Test as T;

T::add('thank you: pending Plandalf invoice hides the transaction error', function () {
    $_GET['plandalf_invoice'] = 'RCP-UNAPPLIED-TEST';
    $_GET['trans_num'] = 'RCP-UNAPPLIED-TEST';

    try {
        $content = Plandalf_Mepr_Checkout::pending_thank_you_content('<p>Transaction not found</p>');
        T::false(str_contains($content, 'Transaction not found'));
        T::contains('We are activating your membership', $content);
    } finally {
        unset($_GET['plandalf_invoice'], $_GET['trans_num']);
    }
});

T::add('thank you: unrelated content is unchanged', function () {
    T::same('Normal page', Plandalf_Mepr_Checkout::pending_thank_you_content('Normal page'));
});
