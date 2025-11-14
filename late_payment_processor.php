<?php
/**
 * Late Payment Fee Processor
 * Handles automatic application of late payment fees from additional_fees system
 */

function calculateLateFees($bill_id, $payment_date, $conn) {
    try {
        // Get bill details including due date
        $bill_stmt = $conn->prepare("
            SELECT bl.*, cl.category_id 
            FROM billing_list bl 
            JOIN client_list cl ON bl.client_id = cl.id 
            WHERE bl.id = ?
        ");
        $bill_stmt->bind_param("i", $bill_id);
        $bill_stmt->execute();
        $bill = $bill_stmt->get_result()->fetch_assoc();
        
        if (!$bill) {
            return ['has_late_fee' => false, 'error' => 'Bill not found'];
        }
        
        // Check if payment is late
        $is_late = strtotime($payment_date) > strtotime($bill['due_date']);
        
        if (!$is_late) {
            return ['has_late_fee' => false, 'message' => 'Payment is on time'];
        }
        
        // Check if late fee has already been applied to this bill
        $existing_fee_stmt = $conn->prepare("
            SELECT baf.* FROM bill_additional_fees baf
            JOIN additional_fees af ON baf.fee_id = af.id
            WHERE baf.bill_id = ? AND af.fee_name = 'Late Payment Fee'
        ");
        $existing_fee_stmt->bind_param("i", $bill_id);
        $existing_fee_stmt->execute();
        $existing_fee = $existing_fee_stmt->get_result()->fetch_assoc();
        
        if ($existing_fee) {
            return [
                'has_late_fee' => true, 
                'fee_amount' => $existing_fee['fee_amount'],
                'already_applied' => true,
                'message' => 'Late fee already applied to this bill'
            ];
        }
        
        // Get late payment fee from additional_fees
        $client_type = ($bill['category_id'] == 1) ? 'residential' : 'commercial';
        $fee_stmt = $conn->prepare("
            SELECT * FROM additional_fees 
            WHERE fee_name = 'Late Payment Fee' 
            AND is_active = 1 
            AND (applies_to = 'all' OR applies_to = ?)
            LIMIT 1
        ");
        $fee_stmt->bind_param("s", $client_type);
        $fee_stmt->execute();
        $late_fee = $fee_stmt->get_result()->fetch_assoc();
        
        if (!$late_fee) {
            return ['has_late_fee' => false, 'message' => 'No active late payment fee configured'];
        }
        
        // Calculate fee amount
        $fee_amount = 0;
        if ($late_fee['fee_type'] === 'fixed') {
            $fee_amount = $late_fee['fee_amount'];
        } else { // percentage
            $fee_amount = ($bill['total'] * $late_fee['fee_amount']) / 100;
        }
        
        return [
            'has_late_fee' => true,
            'fee_id' => $late_fee['id'],
            'fee_amount' => $fee_amount,
            'fee_name' => $late_fee['fee_name'],
            'fee_description' => $late_fee['description'],
            'already_applied' => false,
            'original_bill_total' => $bill['total'],
            'days_late' => ceil((strtotime($payment_date) - strtotime($bill['due_date'])) / (60 * 60 * 24))
        ];
        
    } catch (Exception $e) {
        return ['has_late_fee' => false, 'error' => $e->getMessage()];
    }
}

function applyLateFee($bill_id, $fee_data, $conn) {
    try {
        if ($fee_data['already_applied']) {
            return ['success' => true, 'message' => 'Late fee already applied'];
        }
        
        // Insert the late fee record
        $insert_fee_stmt = $conn->prepare("
            INSERT INTO bill_additional_fees (bill_id, fee_id, fee_amount) 
            VALUES (?, ?, ?)
        ");
        $insert_fee_stmt->bind_param("iid", $bill_id, $fee_data['fee_id'], $fee_data['fee_amount']);
        
        if (!$insert_fee_stmt->execute()) {
            throw new Exception("Failed to record late fee");
        }
        
        // Update bill total
        $new_total = $fee_data['original_bill_total'] + $fee_data['fee_amount'];
        $update_bill_stmt = $conn->prepare("UPDATE billing_list SET total = ? WHERE id = ?");
        $update_bill_stmt->bind_param("di", $new_total, $bill_id);
        
        if (!$update_bill_stmt->execute()) {
            throw new Exception("Failed to update bill total");
        }
        
        return [
            'success' => true, 
            'fee_amount' => $fee_data['fee_amount'],
            'new_total' => $new_total,
            'days_late' => $fee_data['days_late']
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function getAppliedFees($bill_id, $conn) {
    try {
        $fees_stmt = $conn->prepare("
            SELECT baf.*, af.fee_name, af.fee_type, af.description
            FROM bill_additional_fees baf
            JOIN additional_fees af ON baf.fee_id = af.id
            WHERE baf.bill_id = ?
            ORDER BY baf.applied_at DESC
        ");
        $fees_stmt->bind_param("i", $bill_id);
        $fees_stmt->execute();
        $result = $fees_stmt->get_result();
        
        $fees = [];
        while ($fee = $result->fetch_assoc()) {
            $fees[] = $fee;
        }
        
        return $fees;
        
    } catch (Exception $e) {
        return [];
    }
}
?> 