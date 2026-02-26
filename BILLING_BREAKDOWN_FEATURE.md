# Billing Breakdown Feature Documentation

## Overview
Added an interactive billing breakdown modal to the customer dashboard that displays a detailed cost breakdown for each billing record. Customers can now view exactly how their total bill amount is calculated by clicking a "View Breakdown" action button.

## Files Modified/Created

### 1. **customer_dashboard.php** (Modified)
- **Changes**: 
  - Added "Action" column header to the billing history table (line ~1363)
  - Added "View Breakdown" button to each billing row (lines ~1478-1487)
  - Added billing breakdown modal with styled header (lines ~2004-2026)
  - Added JavaScript function `loadBillBreakdown()` to handle modal interaction (lines ~2028-2100)

- **Table Header Update**:
  ```html
  <th class="d-none d-md-table-cell">Action</th>
  ```
  - Responsive: Hidden on mobile devices
  - Positioned between "Due Date" and "Status" columns

- **Action Button**:
  ```html
  <button class="btn btn-sm btn-outline-primary" 
          onclick="loadBillBreakdown(billId, readingDate, totalAmount)">
    <i class="fas fa-eye me-1"></i>View Breakdown
  </button>
  ```

### 2. **get_bill_breakdown.php** (New File)
Backend API endpoint that fetches and calculates the complete billing breakdown for a specific bill.

**Functionality**:
1. Validates customer session and authorization
2. Verifies bill belongs to current customer
3. Calculates breakdown including:
   - Base water usage charge (usage × rate)
   - Applied fees (if any)
   - Tax calculation (if enabled)
   - Subtotal and final total

**Security**:
- Session validation required
- Customer ownership verification
- Returns 403 Forbidden for unauthorized access

**Response Format**:
```json
{
  "success": true,
  "base_charge": 500.00,
  "usage": 50,
  "rate_per_cubic": 10.00,
  "fees": [
    {"name": "Late Payment Fee", "amount": 50.00, "type": "additional"},
    {"name": "Environmental Fee", "amount": 25.00, "type": "tax"}
  ],
  "total_fees": 75.00,
  "tax_rate": 12,
  "tax_enabled": true,
  "tax_amount": 69.00,
  "subtotal": 575.00,
  "final_total": 644.00,
  "amount_paid": 0,
  "remaining": 644.00
}
```

## User Experience Flow

1. **Customer views billing history** in Dashboard → Billing Information tab
2. **Clicks "View Breakdown" button** beside any bill's due date
3. **Modal opens with spinner** showing loading state
4. **Breakdown displays showing**:
   - Reading Date
   - Total Amount (highlighted in blue)
   - Water Usage calculation (usage × rate/m³)
   - Additional Fees (if applicable, highlighted in orange)
   - Tax Calculation (if applicable)
   - Subtotal (highlighted in gray background)

5. **Customer can close modal** using:
   - "Close" button in modal footer
   - X button in modal header
   - Escape key

## Visual Design

### Modal Header
- Blue gradient background (matching app theme)
- White icon and text
- Title: "Billing Breakdown" with receipt icon
- White close button for contrast

### Modal Content
- **Header Section**: Reading date and total amount
- **Cost Breakdown Section**:
  - Water Usage (primary, black text)
  - Additional Fees (orange text for warning)
  - Tax (if applicable)
  - Subtotal (gray background highlight)
- All amounts formatted with ₱ (Philippine Peso)
- Clean spacing and borders for readability

### Responsive Design
- Modal centered on screen
- Button visible only on medium+ screens (hidden on mobile)
- Table remains responsive on all devices
- Mobile users can still see breakdown info in collapse sections (if fees present)

## Database Queries

### Query 1: Bill Authorization Check
Verifies the bill belongs to the authenticated customer via customer account relationship.

### Query 2: Bill Details with Payment Summary
```sql
SELECT bl.*, bc.rate_per_cubic, (bl.reading - bl.previous) as usage,
       COALESCE(SUM(p.amount), 0) as amount_paid
FROM billing_list bl
LEFT JOIN billing_cycles bc ON bl.billing_cycle_id = bc.id
LEFT JOIN payment_list p ON bl.id = p.billing_id AND p.status = 1
WHERE bl.id = ?
GROUP BY bl.id
```

### Query 3: Applied Fees
```sql
SELECT af.id, af.fee_name, af.amount, af.fee_type
FROM applied_fees af
WHERE af.bill_id = ?
ORDER BY af.id ASC
```

### Query 4: System Settings (Tax)
```sql
SELECT setting_value FROM system_settings 
WHERE setting_key IN ('tax_rate', 'tax_enabled')
```

## Calculations

### Base Charge
```
Base Charge = (Current Reading - Previous Reading) × Rate per Cubic Meter
```

### Total Fees
```
Total Fees = SUM of all applied_fees for the bill
```

### Subtotal
```
Subtotal = Base Charge + Total Fees
```

### Tax Amount
```
Tax Amount = Subtotal × (Tax Rate / 100)  [if tax_enabled = 1]
```

### Final Total
```
Final Total = Subtotal + Tax Amount
```

### Remaining Balance
```
Remaining Balance = Final Total - Amount Paid
```

## Configuration Requirements

The feature uses these system_settings:
- `tax_rate`: Percentage rate for tax calculation (e.g., 12)
- `tax_enabled`: Boolean (0 or 1) to enable/disable tax

If not set in system_settings, defaults to:
- `tax_rate`: 0
- `tax_enabled`: 0 (no tax)

## Integration Points

### Dependencies
- **comprehensive_fee_manager.php**: Not used in new file, but included in customer_dashboard.php
- **Bootstrap 5.3**: Modal styling and responsive utilities
- **Font Awesome 6.0+**: Icons (fa-eye, fa-receipt, fa-list, etc.)
- **db.php**: Database connection
- **session_validation.php**: Session security

### Database Tables Required
- `billing_list`: Main billing records
- `billing_cycles`: Cycle information and rate
- `customer_accounts`: Customer to client relationship
- `payment_list`: Payment records
- `applied_fees`: Fee details for bills
- `system_settings`: Configuration (tax)

## Testing Checklist

- [ ] Click "View Breakdown" button for a paid bill
- [ ] Click "View Breakdown" button for a pending bill
- [ ] Click "View Breakdown" button for an overdue bill
- [ ] Verify breakdown shows correct base charge
- [ ] Verify fees display correctly (if bill has fees)
- [ ] Verify tax calculation (if tax_enabled = 1)
- [ ] Verify subtotal is correct (base + fees)
- [ ] Verify final total matches bill total
- [ ] Close modal using X button
- [ ] Close modal using Close button
- [ ] Close modal using Escape key
- [ ] View on mobile (button should not display on mobile)
- [ ] Verify unauthorized user cannot access breakdown for other customers

## Performance Considerations

- **AJAX loading**: Each breakdown fetched on demand (not pre-loaded)
- **Query optimization**: Uses indexed queries on billing_id, client_id
- **Caching**: Modal reuses same element, data fetched fresh each time
- **File size**: Minimal JavaScript, no external dependencies

## Security Features

1. **Session Validation**: Checks $_SESSION['customer_id']
2. **Customer Verification**: Ensures bill belongs to logged-in customer
3. **SQL Injection Prevention**: Uses prepared statements (bind_param)
4. **Output Escaping**: JSON encoding prevents XSS
5. **HTTP Status Codes**: Proper 403/400/500 responses

## Future Enhancements

Potential improvements:
- Export breakdown as PDF
- Print breakdown
- Email breakdown
- Compare multiple bills breakdown
- Historical breakdown archive
- Tariff information display
- Usage trending in breakdown
- Payment history linked to breakdown

## Git Commit Information

- **Commit Hash**: a1c3ed5
- **Branch**: master
- **Message**: "Feature: Add billing breakdown modal to customer dashboard with detailed cost breakdown"
- **Files Modified**: customer_dashboard.php
- **Files Created**: get_bill_breakdown.php
- **Date**: February 26, 2026

## Support & Troubleshooting

### Issue: "View Breakdown" button not appearing
**Solution**: 
- Ensure you're viewing on medium+ screen size (hidden on mobile)
- Clear browser cache (Ctrl+F5)
- Check that billing records exist in billing_list table

### Issue: Modal shows error "Unable to load breakdown details"
**Solution**:
- Check browser console for error messages
- Verify get_bill_breakdown.php exists and is accessible
- Ensure system_settings table has required configuration
- Check database connection in db.php

### Issue: Incorrect breakdown amounts
**Solution**:
- Verify billing_cycles.rate_per_cubic is set correctly
- Check applied_fees table has correct fee_name and amount values
- Verify tax_rate and tax_enabled settings
- Check payment_list for any data integrity issues

