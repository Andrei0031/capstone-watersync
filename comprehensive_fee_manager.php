<?php
/**
 * Comprehensive Fee Management System
 * Handles all types of additional fees across all system workflows
 */

function getApplicableFees($client_id, $context = 'regular_bill', $base_amount = 0, $conn) {
    try {
        // Get client info
        $client_stmt = $conn->prepare("SELECT * FROM client_list WHERE id = ?");
        $client_stmt->bind_param("i", $client_id);
        $client_stmt->execute();
        $client = $client_stmt->get_result()->fetch_assoc();
        
        if (!$client) {
            return ['success' => false, 'error' => 'Client not found'];
        }
        
        $client_type = ($client['category_id'] == 1) ? 'residential' : 'commercial';
        $applicable_fees = [];
        $total_fees = 0;
        
        // Define which fees apply in which contexts
        $fee_contexts = [
            'regular_bill' => ['Service Fee', 'Processing Fee'],
            'new_connection' => ['Connection Fee', 'Service Fee', 'Processing Fee'],
            'reconnection' => ['Reconnection Fee', 'Service Fee', 'Processing Fee'],
            'late_payment' => ['Late Payment Fee'],
            'all_fees' => ['Connection Fee', 'Service Fee', 'Late Payment Fee', 'Reconnection Fee', 'Processing Fee']
        ];
        
        $context_fees = $fee_contexts[$context] ?? $fee_contexts['regular_bill'];
        
        // Get fees that apply to this context
        $placeholders = str_repeat('?,', count($context_fees) - 1) . '?';
        $params = array_merge([$client_type], $context_fees);
        $types = 's' . str_repeat('s', count($context_fees));
        
        $fees_stmt = $conn->prepare("
            SELECT * FROM additional_fees 
            WHERE is_active = 1 
            AND (applies_to = 'all' OR applies_to = ?)
            AND fee_name IN ($placeholders)
        ");
        $fees_stmt->bind_param($types, ...$params);
        $fees_stmt->execute();
        $fees_result = $fees_stmt->get_result();
        
        while ($fee = $fees_result->fetch_assoc()) {
            $fee_amount = 0;
            if ($fee['fee_type'] === 'fixed') {
                $fee_amount = $fee['fee_amount'];
            } else { // percentage
                $fee_amount = ($base_amount * $fee['fee_amount']) / 100;
            }
            
            $applicable_fees[] = [
                'fee_id' => $fee['id'],
                'fee_name' => $fee['fee_name'],
                'fee_type' => $fee['fee_type'],
                'fee_amount' => $fee_amount,
                'description' => $fee['description']
            ];
            
            $total_fees += $fee_amount;
        }
        
        return [
            'success' => true,
            'fees' => $applicable_fees,
            'total_fees' => $total_fees,
            'client_type' => $client_type,
            'context' => $context
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function applyFeesToBill($bill_id, $fees_data, $conn) {
    try {
        if (empty($fees_data['fees'])) {
            return ['success' => true, 'message' => 'No fees to apply'];
        }
        
        $applied_fees = [];
        $total_applied = 0;
        
        // Insert each fee
        $insert_stmt = $conn->prepare("
            INSERT INTO bill_additional_fees (bill_id, fee_id, fee_amount) 
            VALUES (?, ?, ?)
        ");
        
        foreach ($fees_data['fees'] as $fee) {
            // Check if this fee was already applied
            $check_stmt = $conn->prepare("
                SELECT id FROM bill_additional_fees 
                WHERE bill_id = ? AND fee_id = ?
            ");
            $check_stmt->bind_param("ii", $bill_id, $fee['fee_id']);
            $check_stmt->execute();
            $existing = $check_stmt->get_result()->fetch_assoc();
            
            if (!$existing) {
                $insert_stmt->bind_param("iid", $bill_id, $fee['fee_id'], $fee['fee_amount']);
                if ($insert_stmt->execute()) {
                    $applied_fees[] = $fee;
                    $total_applied += $fee['fee_amount'];
                }
            }
        }
        
        // Update bill total if fees were applied
        if ($total_applied > 0) {
            $update_stmt = $conn->prepare("
                UPDATE billing_list 
                SET total = total + ? 
                WHERE id = ?
            ");
            $update_stmt->bind_param("di", $total_applied, $bill_id);
            $update_stmt->execute();
        }
        
        return [
            'success' => true,
            'applied_fees' => $applied_fees,
            'total_applied' => $total_applied,
            'fees_count' => count($applied_fees)
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function calculateBillWithFees($client_id, $reading_value, $previous_reading, $context = 'regular_bill', $conn) {
    try {
        // Get client and rate info
        $client_stmt = $conn->prepare("
            SELECT cl.*, cr.rate, cr.excess_rate 
            FROM client_list cl
            LEFT JOIN category_rates cr ON cl.category_id = cr.category_id
            WHERE cl.id = ?
        ");
        $client_stmt->bind_param("i", $client_id);
        $client_stmt->execute();
        $client = $client_stmt->get_result()->fetch_assoc();
        
        if (!$client) {
            return ['success' => false, 'error' => 'Client not found'];
        }
        
        // Calculate base consumption charge
        $consumption = max(0, $reading_value - $previous_reading);
        
        if ($consumption <= 6) {
            $base_total = $client['rate'] ?? 100; // Default rate if not set
        } else {
            $excess = $consumption - 6;
            $base_total = ($client['rate'] ?? 100) + ($excess * ($client['excess_rate'] ?? 20));
        }
        
        // Get applicable fees
        $fees_data = getApplicableFees($client_id, $context, $base_total, $conn);
        
        if (!$fees_data['success']) {
            return $fees_data;
        }
        
        $final_total = $base_total + $fees_data['total_fees'];
        
        return [
            'success' => true,
            'consumption' => $consumption,
            'base_total' => $base_total,
            'fees_data' => $fees_data,
            'total_fees' => $fees_data['total_fees'],
            'final_total' => $final_total,
            'client_info' => $client
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function getBillFeeBreakdown($bill_id, $conn) {
    try {
        // Get bill info
        $bill_stmt = $conn->prepare("
            SELECT bl.*, cl.firstname, cl.lastname, cl.meter_code
            FROM billing_list bl
            JOIN client_list cl ON bl.client_id = cl.id
            WHERE bl.id = ?
        ");
        $bill_stmt->bind_param("i", $bill_id);
        $bill_stmt->execute();
        $bill = $bill_stmt->get_result()->fetch_assoc();
        
        if (!$bill) {
            return ['success' => false, 'error' => 'Bill not found'];
        }
        
        // Get applied fees
        $fees_stmt = $conn->prepare("
            SELECT baf.*, af.fee_name, af.fee_type, af.description
            FROM bill_additional_fees baf
            JOIN additional_fees af ON baf.fee_id = af.id
            WHERE baf.bill_id = ?
            ORDER BY baf.applied_at
        ");
        $fees_stmt->bind_param("i", $bill_id);
        $fees_stmt->execute();
        $fees_result = $fees_stmt->get_result();
        
        $applied_fees = [];
        $total_fees = 0;
        
        while ($fee = $fees_result->fetch_assoc()) {
            $applied_fees[] = $fee;
            $total_fees += $fee['fee_amount'];
        }
        
        // Calculate base amount (total - fees)
        $base_amount = $bill['total'] - $total_fees;
        
        return [
            'success' => true,
            'bill' => $bill,
            'base_amount' => $base_amount,
            'applied_fees' => $applied_fees,
            'total_fees' => $total_fees,
            'final_total' => $bill['total']
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function applyConnectionFee($client_id, $conn) {
    try {
        // Check if connection fee was already applied
        $check_stmt = $conn->prepare("
            SELECT baf.id FROM bill_additional_fees baf
            JOIN additional_fees af ON baf.fee_id = af.id
            JOIN billing_list bl ON baf.bill_id = bl.id
            WHERE bl.client_id = ? AND af.fee_name = 'Connection Fee'
        ");
        $check_stmt->bind_param("i", $client_id);
        $check_stmt->execute();
        $existing = $check_stmt->get_result()->fetch_assoc();
        
        if ($existing) {
            return ['success' => false, 'message' => 'Connection fee already applied to this client'];
        }
        
        // Get the latest bill for this client
        $bill_stmt = $conn->prepare("
            SELECT id FROM billing_list 
            WHERE client_id = ? 
            ORDER BY reading_date DESC 
            LIMIT 1
        ");
        $bill_stmt->bind_param("i", $client_id);
        $bill_stmt->execute();
        $bill = $bill_stmt->get_result()->fetch_assoc();
        
        if (!$bill) {
            return ['success' => false, 'message' => 'No bills found for this client'];
        }
        
        // Apply connection fee
        $fees_data = getApplicableFees($client_id, 'new_connection', 0, $conn);
        if ($fees_data['success']) {
            // Filter to only connection fee
            $connection_fees = array_filter($fees_data['fees'], function($fee) {
                return $fee['fee_name'] === 'Connection Fee';
            });
            
            if (!empty($connection_fees)) {
                $fees_data['fees'] = $connection_fees;
                $fees_data['total_fees'] = array_sum(array_column($connection_fees, 'fee_amount'));
                
                return applyFeesToBill($bill['id'], $fees_data, $conn);
            }
        }
        
        return ['success' => false, 'message' => 'Connection fee not configured'];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function applyReconnectionFee($client_id, $conn) {
    try {
        // Get the latest bill for this client
        $bill_stmt = $conn->prepare("
            SELECT id FROM billing_list 
            WHERE client_id = ? 
            ORDER BY reading_date DESC 
            LIMIT 1
        ");
        $bill_stmt->bind_param("i", $client_id);
        $bill_stmt->execute();
        $bill = $bill_stmt->get_result()->fetch_assoc();
        
        if (!$bill) {
            return ['success' => false, 'message' => 'No bills found for this client'];
        }
        
        // Apply reconnection fee
        $fees_data = getApplicableFees($client_id, 'reconnection', 0, $conn);
        if ($fees_data['success']) {
            // Filter to only reconnection fee
            $reconnection_fees = array_filter($fees_data['fees'], function($fee) {
                return $fee['fee_name'] === 'Reconnection Fee';
            });
            
            if (!empty($reconnection_fees)) {
                $fees_data['fees'] = $reconnection_fees;
                $fees_data['total_fees'] = array_sum(array_column($reconnection_fees, 'fee_amount'));
                
                return applyFeesToBill($bill['id'], $fees_data, $conn);
            }
        }
        
        return ['success' => false, 'message' => 'Reconnection fee not configured'];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
?> 