<?php
/**
 * Automated Bill Creation System
 * Automatically creates bills when meter readings are processed
 */

function createAutomatedBill($client_id, $reading_value, $billing_cycle_id, $conn) {
    try {
        // Get client info and category
        $client_stmt = $conn->prepare("
            SELECT cl.*, ca.firstname, ca.lastname 
            FROM client_list cl
            JOIN customer_accounts ca ON cl.customer_id = ca.id 
            WHERE cl.id = ?
        ");
        $client_stmt->bind_param("i", $client_id);
        $client_stmt->execute();
        $client = $client_stmt->get_result()->fetch_assoc();
        
        if (!$client) {
            throw new Exception("Client not found");
        }
        
        // Get billing cycle info
        $cycle_stmt = $conn->prepare("SELECT * FROM billing_cycles WHERE id = ?");
        $cycle_stmt->bind_param("i", $billing_cycle_id);
        $cycle_stmt->execute();
        $cycle = $cycle_stmt->get_result()->fetch_assoc();
        
        if (!$cycle) {
            throw new Exception("Billing cycle not found");
        }
        
        // Get previous reading
        $prev_stmt = $conn->prepare("
            SELECT reading FROM billing_list 
            WHERE client_id = ? 
            ORDER BY reading_date DESC 
            LIMIT 1
        ");
        $prev_stmt->bind_param("i", $client_id);
        $prev_stmt->execute();
        $prev_result = $prev_stmt->get_result();
        $previous_reading = $prev_result->num_rows > 0 ? $prev_result->fetch_assoc()['reading'] : 0;
        
        // Calculate consumption
        $consumption = max(0, $reading_value - $previous_reading);
        
        // Get rates for client's category
        $rate_stmt = $conn->prepare("SELECT rate, excess_rate FROM category_rates WHERE category_id = ?");
        $rate_stmt->bind_param("i", $client['category_id']);
        $rate_stmt->execute();
        $rate_result = $rate_stmt->get_result();
        $rate_data = $rate_result->fetch_assoc();
        
        if (!$rate_data) {
            throw new Exception("Rate data not found for client category");
        }
        
        // Calculate base bill amount
        if ($consumption <= 6) {
            $base_total = $rate_data['rate'];
        } else {
            $excess = $consumption - 6;
            $base_total = $rate_data['rate'] + ($excess * $rate_data['excess_rate']);
        }
        
        // Get applicable additional fees using comprehensive fee system
        require_once 'comprehensive_fee_manager.php';
        $fees_result = getApplicableFees($client_id, 'regular_bill', $base_total, $conn);
        
        if (!$fees_result['success']) {
            throw new Exception("Failed to calculate fees: " . $fees_result['error']);
        }
        
        $additional_fees_total = $fees_result['total_fees'];
        $applied_fees = array_map(function($fee) {
            return [
                'fee_id' => $fee['fee_id'],
                'fee_amount' => $fee['fee_amount']
            ];
        }, $fees_result['fees']);
        
        // Calculate final total
        $final_total = $base_total + $additional_fees_total;
        
        // Create the bill
        $bill_stmt = $conn->prepare("
            INSERT INTO billing_list 
            (client_id, billing_cycle_id, reading_date, due_date, reading, previous, rate, total, status, date_created) 
            VALUES (?, ?, CURRENT_DATE(), ?, ?, ?, ?, ?, 0, NOW())
        ");
        
        $bill_stmt->bind_param("iisdddd", 
            $client_id,
            $billing_cycle_id,
            $cycle['due_date'],
            $reading_value,
            $previous_reading,
            $base_total,
            $final_total
        );
        
        if (!$bill_stmt->execute()) {
            throw new Exception("Failed to create bill: " . $conn->error);
        }
        
        $bill_id = $bill_stmt->insert_id;
        
        // Insert applied additional fees
        if (!empty($applied_fees)) {
            $fee_stmt = $conn->prepare("
                INSERT INTO bill_additional_fees (bill_id, fee_id, fee_amount) 
                VALUES (?, ?, ?)
            ");
            
            foreach ($applied_fees as $applied_fee) {
                $fee_stmt->bind_param("iid", $bill_id, $applied_fee['fee_id'], $applied_fee['fee_amount']);
                $fee_stmt->execute();
            }
        }
        
        return [
            'success' => true,
            'bill_id' => $bill_id,
            'consumption' => $consumption,
            'base_total' => $base_total,
            'additional_fees' => $additional_fees_total,
            'final_total' => $final_total,
            'client_name' => $client['firstname'] . ' ' . $client['lastname']
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Process OCR readings and automatically create bills
 */
function processOCRReadingsAutomatically($conn) {
    $processed_count = 0;
    $errors = [];
    
    // Get current active billing cycle
    $cycle_stmt = $conn->prepare("SELECT * FROM billing_cycles WHERE status = 'active' LIMIT 1");
    $cycle_stmt->execute();
    $active_cycle = $cycle_stmt->get_result()->fetch_assoc();
    
    if (!$active_cycle) {
        return [
            'success' => false,
            'error' => 'No active billing cycle found'
        ];
    }
    
    // Get all processed readings with OCR values (scanned readings that have been processed)
    // Priority: ocr_reading > verified_reading > reading_value
    $readings_stmt = $conn->prepare("
        SELECT pmr.*, cl.firstname, cl.lastname,
               COALESCE(pmr.ocr_reading, pmr.verified_reading, pmr.reading_value, 0) as meter_reading
        FROM pending_meter_readings pmr
        JOIN client_list cl ON pmr.client_id = cl.id
        WHERE pmr.status = 'processed' 
        AND (
            pmr.ocr_reading IS NOT NULL AND pmr.ocr_reading > 0
            OR pmr.verified_reading IS NOT NULL AND pmr.verified_reading > 0
            OR pmr.reading_value IS NOT NULL AND pmr.reading_value > 0
        )
        AND pmr.billing_cycle_id = ?
        ORDER BY pmr.processed_at DESC
    ");
    $readings_stmt->bind_param("i", $active_cycle['id']);
    $readings_stmt->execute();
    $readings_result = $readings_stmt->get_result();
    
    while ($reading = $readings_result->fetch_assoc()) {
        // Get the actual reading value (priority: ocr_reading > verified_reading > reading_value)
        $meter_reading = $reading['ocr_reading'] ?? $reading['verified_reading'] ?? $reading['reading_value'] ?? 0;
        
        if ($meter_reading <= 0) {
            continue; // Skip if no valid reading
        }
        
        // Check if bill already exists for this client in this cycle
        $check_stmt = $conn->prepare("
            SELECT id FROM billing_list 
            WHERE client_id = ? AND billing_cycle_id = ?
        ");
        $check_stmt->bind_param("ii", $reading['client_id'], $active_cycle['id']);
        $check_stmt->execute();
        $existing_bill = $check_stmt->get_result()->fetch_assoc();
        
        if ($existing_bill) {
            continue; // Skip if bill already exists
        }
        
        // Create automated bill using the meter reading value
        $result = createAutomatedBill(
            $reading['client_id'], 
            $meter_reading, 
            $active_cycle['id'], 
            $conn
        );
        
        if ($result['success']) {
            // Reading is already marked as 'processed' - bill has been created successfully
            // Optionally update a flag or timestamp to indicate bill was created
            $update_stmt = $conn->prepare("
                UPDATE pending_meter_readings 
                SET processed_date = NOW() 
                WHERE id = ?
            ");
            $update_stmt->bind_param("i", $reading['id']);
            $update_stmt->execute();
            
            $processed_count++;
        } else {
            // Mark reading as failed (bill creation failed)
            $update_stmt = $conn->prepare("
                UPDATE pending_meter_readings 
                SET status = 'failed', admin_notes = ?, processed_at = NOW() 
                WHERE id = ?
            ");
            $errorMsg = "Bill creation failed: " . $result['error'];
            $update_stmt->bind_param("si", $errorMsg, $reading['id']);
            $update_stmt->execute();
            
            $errors[] = "Failed to create bill for " . $reading['firstname'] . " " . $reading['lastname'] . ": " . $result['error'];
        }
    }
    
    return [
        'success' => true,
        'processed_count' => $processed_count,
        'errors' => $errors,
        'cycle_name' => $active_cycle['cycle_name']
    ];
}
?> 