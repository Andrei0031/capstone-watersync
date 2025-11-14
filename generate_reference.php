<?php

function generateReferenceNumber() {
    // Format: WB-YYYYMMDD-XXXX
    // WB: Water Bill
    // YYYYMMDD: Current date
    // XXXX: Random 4-digit number
    
    $prefix = 'WB';
    $date = date('Ymd');
    $random = str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    return $prefix . '-' . $date . '-' . $random;
}

if (isset($_POST['generate'])) {
    header('Content-Type: application/json');
    echo json_encode(['reference' => generateReferenceNumber()]);
}
?> 