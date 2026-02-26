# Billing Breakdown Feature - Visual Guide

## 📺 User Interface Overview

### Billing Table with Action Button

```
╔════════════════════════════════════════════════════════════════════════════════╗
║                         Recent Billing History                                ║
║ ┌───────────┬──────────────────┬──────────┬──────────────┬─────────┬─────────┐ ║
║ │ Reading   │ Readings         │ Usage    │ Amount       │ Due     │ Action  │ ║
║ │ Date      │ (Prev/Current)   │          │              │ Date    │         │ ║
╠════════════════════════════════════════════════════════════════════════════════╣
║ │ Feb 26    │ Previous: 254    │ 50 m³   │ ₱6,060.00    │ Mar 15  │ View    │ ║
║ │ 2026      │ Current: 304     │          │ Due: ₱6,060  │ 2026    │ Break ↓ │ ║
║ ├───────────┼──────────────────┼──────────┼──────────────┼─────────┼─────────┤ ║
║ │ Jan 26    │ Previous: 200    │ 54 m³   │ ₱5,800.00    │ Feb 15  │ View    │ ║
║ │ 2026      │ Current: 254     │          │ Paid: ₱5,800 │ 2026    │ Break ↓ ║
║ ├───────────┼──────────────────┼──────────┼──────────────┼─────────┼─────────┤ ║
║ │ Dec 26    │ Previous: 150    │ 50 m³   │ ₱5,600.00    │ Jan 15  │ View    │ ║
║ │ 2025      │ Current: 200     │          │ Paid: ₱5,600 │ 2026    │ Break ↓ │ ║
║ └───────────┴──────────────────┴──────────┴──────────────┴─────────┴─────────┘ ║
║        [Paid] [Paid] [Paid]                                                     ║
╚════════════════════════════════════════════════════════════════════════════════╝

                           ↓ Click "View Breakdown" ↓
```

### Breakdown Modal Detailed View

```
╔═══════════════════════════════════════════════════════════╗
║  📋 Billing Breakdown                                  ✕  ║
╠═══════════════════════════════════════════════════════════╣
║                                                           ║
║  Reading Date:    Feb 26, 2026                          ║
║  Total Amount:    ₱6,060.00                             ║
║  ───────────────────────────────────────────────        ║
║                                                           ║
║  📋 Cost Breakdown                                        ║
║                                                           ║
║  Water Usage                                              ║
║  304 m³ × ₱10.00/m³                      ₱3,040.00      ║
║  ───────────────────────────────────────────────        ║
║                                                           ║
║  ⚠️  Additional Fees                                      ║
║     Service Fee                           ₱500.00       ║
║     Environmental Fee                     ₱300.00       ║
║     Late Payment Fee                      ₱200.00       ║
║  ───────────────────────────────────────────────        ║
║                                                           ║
║  Tax (12% of subtotal)                    ₱468.00       ║
║  ───────────────────────────────────────────────        ║
║                                                           ║
║  ┌─────────────────────────────────────────┐            ║
║  │  Subtotal:                  ₱4,008.00  │            ║
║  └─────────────────────────────────────────┘            ║
║                                                           ║
╠═══════════════════════════════════════════════════════════╣
║                                    [Close]               ║
╚═══════════════════════════════════════════════════════════╝
```

## 🔄 Feature Flow Diagram

```
┌─────────────────────────────────────┐
│   Customer Login                     │
└─────────────┬───────────────────────┘
              │
              ↓
┌─────────────────────────────────────┐
│   Dashboard → Billing Information   │
└─────────────┬───────────────────────┘
              │
              ↓
┌─────────────────────────────────────┐
│   View Recent Billing History       │
│   (5 latest bills in table)          │
└─────────────┬───────────────────────┘
              │
              ↓
    ┌─────────┴──────────┐
    │                    │
    ↓                    ↓
  [View]           [View]
 [Breakdown]      [Breakdown]
    │                    │
    └─────────┬──────────┘
              │
              ↓
   ┌──────────────────────┐
   │  AJAX Request        │
   │ get_bill_breakdown   │
   │      .php            │
   └──────────┬───────────┘
              │
              ↓
   ┌──────────────────────┐
   │  Validation          │
   │ - Session check      │
   │ - Customer verify    │
   │ - Bill ownership     │
   └──────────┬───────────┘
              │
              ↓
   ┌──────────────────────┐
   │  Calculations        │
   │ - Base charge        │
   │ - Fees sum           │
   │ - Tax calculation    │
   │ - Totals             │
   └──────────┬───────────┘
              │
              ↓
   ┌──────────────────────┐
   │  JSON Response       │
   │ ✓ Success with data  │
   │ ✗ Error message      │
   └──────────┬───────────┘
              │
              ↓
   ┌──────────────────────┐
   │  Modal Opens         │
   │  Display Breakdown   │
   └──────────┬───────────┘
              │
              ↓
   ┌──────────────────────┐
   │  Customer Closes     │
   │  (X / Close / Esc)   │
   └──────────────────────┘
```

## 📱 Responsive Design Behavior

### Desktop View (Medium+ screens)
```
┌─────────────────────────────────────────────────────┐
│ Reading Date │ Readings │ Usage │ Amount │ Due Date │
│              │          │       │        │          │
│ Feb 26       │ 254→304  │ 50m³  │ ₱6,060 │ Mar 15   │
│              │          │       │        │          │
│                                  [View Breakdown ↓]  │
└─────────────────────────────────────────────────────┘
              ✓ Action button visible
```

### Tablet View (Medium screens)
```
┌─────────────────────────────────────┐
│ Reading Date │ Readings │ Usage     │
│              │          │           │
│ Feb 26       │ 254→304  │ 50m³      │
│              │          │           │
│       [View Breakdown ↓]            │
└─────────────────────────────────────┘
              ✓ Action button visible
```

### Mobile View (Small screens)
```
┌──────────────────────┐
│ Reading: Feb 26      │
│ Usage: 50m³          │
│ Amount: ₱6,060       │
│ Due: Mar 15          │
│ Status: [PAID]       │
└──────────────────────┘
         ✗ Action button hidden
    (Collapse section for fees)
```

## 🗂️ Directory Structure

```
CAPSTONE/
├── customer_dashboard.php          (Modified)
│   ├── Table with action button
│   ├── Breakdown modal HTML
│   └── loadBillBreakdown() function
│
├── get_bill_breakdown.php          (New)
│   ├── Session validation
│   ├── Customer verification
│   ├── Calculations
│   └── JSON response
│
└── Documentation/
    ├── BILLING_BREAKDOWN_FEATURE.md              (New)
    ├── BILLING_BREAKDOWN_QUICK_REFERENCE.md      (New)
    ├── BILLING_BREAKDOWN_IMPLEMENTATION.md       (New)
    └── BILLING_BREAKDOWN_VISUAL_GUIDE.md         (This file)
```

## 🔢 Data Flow Visualization

```
┌─────────────────┐
│  Frontend       │
│  (JavaScript)   │
│                 │
│ Click Button →  │ fetch('get_bill_breakdown.php')
└────────┬────────┘
         │
         ↓ HTTP Request (AJAX)
         │
┌────────────────────────────┐
│  Backend (get_bill_breakdown.php)
│                            │
│  1. Verify Session         │
│  2. Check Customer         │
│  3. Get Bill Data          │ ← Query billing_list
│  4. Get Rate              │ ← Query billing_cycles
│  5. Get Fees              │ ← Query applied_fees
│  6. Get Settings          │ ← Query system_settings
│  7. Calculate Breakdown    │
│  8. Build Response         │
│                            │
└────────┬───────────────────┘
         │
         ↓ JSON Response
         │
┌────────────────────────────┐
│  Frontend (JavaScript)      │
│                            │
│  1. Parse JSON             │
│  2. Format Currency        │
│  3. Build HTML             │
│  4. Update Modal Content   │
│  5. Display to User        │
│                            │
└────────────────────────────┘
```

## 💾 Database Schema Used

```
billing_list
├── id (PK)
├── client_id (FK)
├── billing_cycle_id (FK)
├── reading (current m³)
├── previous (previous m³)
├── total (final amount)
├── due_date
└── status

billing_cycles
├── id (PK)
├── cycle_name
└── rate_per_cubic ← Used for calculation

applied_fees
├── id (PK)
├── bill_id (FK)
├── fee_name
├── amount
└── fee_type

payment_list
├── id (PK)
├── billing_id (FK)
├── amount
└── status

system_settings
├── setting_key
└── setting_value
    ├── tax_rate
    └── tax_enabled

customer_accounts
├── id (PK)
├── client_id (FK)
└── [other customer data]
```

## 🎨 Color Scheme

| Element | Color | RGB | Usage |
|---------|-------|-----|-------|
| Header | Blue Gradient | #2196F3 → #1976D2 | Modal header background |
| Primary Text | Blue | #1976D2 | Total amount, section headers |
| Secondary Text | Orange | #F57C00 | Additional fees label |
| Success | Green | #4CAF50 | Paid status |
| Warning | Orange | #FFC107 | Additional fees amount |
| Danger | Red | #F44336 | Error messages |
| Background | Gray | #F5F5F5 | Subtotal highlight box |
| Border | Light Gray | #E0E0E0 | Dividers |

## 🎯 Calculation Logic Tree

```
                    ┌─ Total Bill Amount ─┐
                    │                     │
         ┌──────────┴─────────────────────┴────────┐
         │                                         │
    BASE CHARGE                          ADDITIONAL ITEMS
         │                                         │
    ┌────┴────┐                        ┌──────────┴──────────┐
    │          │                        │                     │
  Usage    Rate/m³                    FEES                  TAX
    │          │                        │                     │
    │          │                    Fee 1                Tax Rate
    │          │                    Fee 2                 │
    │          │                    Fee 3              Subtotal
    │          │                      │                  ×
    └────┬─────┘                      │             Tax Percent
         │                            │                  ÷ 100
    Base Charge                        │
         │                            │
         └──────────┬─────────────────┘
                    │
                SUBTOTAL
                    │
                    ├─ Amount Paid
                    │
                REMAINING BALANCE
```

## ✨ Feature Highlights

```
╔════════════════════════════════════════════════════════════╗
║                                                            ║
║  ✓ Complete Transparency                                  ║
║    Customers see exactly what they're paying for          ║
║                                                            ║
║  ✓ Interactive Modal                                      ║
║    Clean, professional presentation                       ║
║                                                            ║
║  ✓ AJAX Loading                                           ║
║    No page refresh needed                                 ║
║                                                            ║
║  ✓ Responsive Design                                      ║
║    Works on all screen sizes                              ║
║                                                            ║
║  ✓ Security Validated                                     ║
║    Customer can only view their own bills                 ║
║                                                            ║
║  ✓ Error Handling                                         ║
║    Graceful error messages                                ║
║                                                            ║
║  ✓ Professional Styling                                   ║
║    Matches app theme and design                           ║
║                                                            ║
╚════════════════════════════════════════════════════════════╝
```

## 📖 Navigation Guide

To test the feature:

1. **Login** as a customer
2. **Navigate** to Customer Dashboard
3. **Click** on "Billing Information" tab
4. **Scroll** to "Recent Billing History" table
5. **Locate** "View Breakdown" button in Action column
6. **Click** button to open modal
7. **Review** detailed cost breakdown
8. **Close** modal using X, Close button, or Escape key

## 🎓 Understanding the Breakdown

Each bill is composed of:

**1. Water Usage Charge** (Main)
   - Cubic meters used × Rate per cubic meter
   - Example: 50 m³ × ₱10.00/m³ = ₱500.00

**2. Additional Fees** (Optional)
   - Service fees
   - Environmental fees
   - Penalties or late charges
   - Sum of all applicable fees

**3. Tax** (If Enabled)
   - Calculated on subtotal
   - Example: Subtotal ₱500 × 12% = ₱60

**4. Total Amount Due**
   - Base + Fees + Tax
   - What customer needs to pay

