# Testing Guide - Delete Function Fixes

## Prerequisites
- Admin account with access to Settings > Additional Fees
- Test database with multiple customers and bills
- Access to customer billing history modal

---

## Test Suite

### ✅ TEST 1: Delete Button Visibility When Protection is DISABLED

**Objective:** Verify delete button is hidden when protection is not configured

**Steps:**
1. Login as admin
2. Go to **Settings > Additional Fees**
3. Scroll to "Delete Password Protection" section
4. Ensure toggle is **OFF** (unchecked)
5. Navigate to **Billing List**
6. Click on any customer row to open modal
7. Scroll to "Billing History" section

**Expected Result:**
- ✅ "Delete Selected" button should be **HIDDEN/NOT VISIBLE**
- ✅ "Select All" button should be visible
- ✅ Billing history table loads normally

**If Test Fails:**
- Clear browser cache
- Hard refresh page (Ctrl+F5)
- Check browser console for JavaScript errors

---

### ✅ TEST 2: Delete Button Visibility When Protection is ENABLED

**Objective:** Verify delete button is visible when protection is configured

**Prerequisites for this test:**
- Delete password must be set
- Delete toggle must be ON

**Steps:**
1. Login as admin
2. Go to **Settings > Additional Fees**
3. Scroll to "Delete Password Protection" section
4. If password not set:
   - Click "Set Delete Password"
   - Enter password (e.g., "test1234")
   - Confirm password
   - Click "Update Password"
5. Ensure toggle is **ON** (checked)
6. Navigate to **Billing List**
7. Click on any customer row to open modal
8. Scroll to "Billing History" section

**Expected Result:**
- ✅ "Delete Selected" button should be **VISIBLE**
- ✅ Button should be red/danger style
- ✅ Button should be clickable

**If Test Fails:**
- Verify password was saved (should show "Password is set")
- Verify toggle is checked
- Clear browser cache and refresh

---

### ✅ TEST 3: Delete Single Bill (Only One Bill Deleted)

**Objective:** Verify that deleting 1 selected bill deletes ONLY that bill

**Prerequisites:**
- Delete protection must be ENABLED (password set + toggle ON)
- Select a customer with 5+ bills visible

**Steps:**
1. Open customer details modal
2. Note the bill count shown in "Bills" statistic
3. Select **ONLY 1 bill** (check its checkbox)
4. Click "Delete Selected" button
5. Confirm in dialog: "Delete 1 selected bill(s)?"
6. Enter delete password
7. Wait for page reload/confirmation

**Expected Result:**
- ✅ EXACTLY 1 bill deleted
- ✅ Billing count should decrease by 1
- ✅ All other bills still present
- ✅ Success message shows "Successfully deleted 1 bill(s)"

**Verification:**
- Reopen customer modal
- Verify correct bill was deleted
- Verify other bills still exist
- Check database: `SELECT COUNT(*) FROM billing_list WHERE client_id = X;`

**If Test Fails:**
- ❌ More than 1 bill deleted → BUG NOT FIXED
- ❌ Wrong bill deleted → Issue with bill selection
- Check browser console for errors
- Check server logs for SQL errors

---

### ✅ TEST 4: Delete Multiple Bills (All Selected Bills Deleted, Nothing Extra)

**Objective:** Verify selecting 3 bills and deleting removes exactly 3 bills

**Prerequisites:**
- Delete protection must be ENABLED
- Select a customer with 10+ bills

**Steps:**
1. Open customer details modal
2. Note initial bill count (e.g., 10 bills)
3. Select **EXACTLY 3 bills** (check 3 checkboxes):
   - Bill 1: December 2025
   - Bill 2: November 2025
   - Bill 3: October 2025
   - (leave others unchecked)
4. Click "Delete Selected" button
5. Confirm dialog shows: "Delete 3 selected bill(s)?"
6. Enter delete password
7. Wait for page reload

**Expected Result:**
- ✅ Initial count: 10 bills
- ✅ Exactly 3 bills deleted
- ✅ Final count: 7 bills
- ✅ All unselected bills still present
- ✅ Success message: "Successfully deleted 3 bill(s)"

**Verification:**
- Reopen customer modal
- Verify selected 3 bills are gone
- Verify unselected 7 bills still exist
- Database check: `SELECT COUNT(*) FROM billing_list WHERE client_id = X;` should show 7

**If Test Fails:**
- ❌ More than 3 bills deleted (e.g., all 10) → BUG NOT FIXED
- Check which bills were deleted
- Restore database from backup and retry

---

### ✅ TEST 5: Delete Bills from Multiple Customers

**Objective:** Verify multi-customer deletion doesn't delete unrelated bills

**Prerequisites:**
- Delete protection ENABLED
- Have 2 customers visible in billing list
- Customer A: 5 bills
- Customer B: 8 bills

**Steps:**
1. In main **Billing List** table
2. Select bills from both customers:
   - Select 2 bills from Customer A
   - Select 3 bills from Customer B
   - Total: 5 bills selected
3. Scroll to bulk delete button
4. Click delete button
5. Confirm deletion
6. Enter password

**Expected Result:**
- ✅ Exactly 5 bills deleted (2 from A, 3 from B)
- ✅ Customer A now has: 5 - 2 = 3 bills
- ✅ Customer B now has: 8 - 3 = 5 bills
- ✅ No unintended bills deleted

**If Test Fails:**
- ❌ All bills for a customer deleted → BUG NOT FIXED
- ❌ Wrong count deleted
- Check logs and restore from backup

---

### ✅ TEST 6: Delete Protection Blocks Deletion When Disabled

**Objective:** Verify deletion is completely blocked when protection is OFF

**Prerequisites:**
- Delete protection DISABLED (toggle OFF)

**Steps:**
1. Navigate to Billing List
2. Try to access delete button (may be hidden via HTML)
3. If you can access delete button (shouldn't happen):
   - Click it
4. Try to delete via form submission (direct POST)

**Expected Result:**
- ✅ Delete button not visible in UI
- ✅ If somehow deleted via direct form submission:
   - Error message: "Delete is disabled in Settings > Additional Fees"
   - Bills NOT deleted
   - Transaction rolled back

**If Test Fails:**
- ❌ Deletion succeeded when disabled
- Immediately enable protection
- Review security

---

### ✅ TEST 7: Wrong Password Blocks Deletion

**Objective:** Verify incorrect password prevents deletion

**Prerequisites:**
- Delete protection ENABLED
- Password set to: "correct123"

**Steps:**
1. Open customer modal
2. Select 1 bill
3. Click "Delete Selected"
4. In password prompt, enter: "wrong123"
5. Try to confirm delete

**Expected Result:**
- ✅ Error message: "Incorrect delete password. Please try again."
- ✅ Bill NOT deleted
- ✅ Modal remains open
- ✅ User can retry with correct password

**If Test Fails:**
- ❌ Deletion succeeded with wrong password
- Security issue! Check delete_bill.php and delete_reading.php

---

### ✅ TEST 8: Correct Password Allows Deletion

**Objective:** Verify correct password allows deletion to proceed

**Prerequisites:**
- Delete protection ENABLED
- Password set to: "correct123"

**Steps:**
1. Open customer modal
2. Select 1 bill
3. Click "Delete Selected"
4. In password prompt, enter: "correct123"
5. Confirm delete

**Expected Result:**
- ✅ Password accepted
- ✅ Bill deleted successfully
- ✅ Success message shown
- ✅ Bill no longer in list

**If Test Fails:**
- ❌ Password rejected with correct password
- Check password hash in database
- Verify password verification logic

---

## Database Verification Commands

After each delete operation, verify using SQL:

```sql
-- Check bill count for a customer
SELECT COUNT(*) as bill_count FROM billing_list WHERE client_id = 1;

-- List all bills for a customer
SELECT id, billing_date, amount, status FROM billing_list 
WHERE client_id = 1 
ORDER BY billing_date DESC;

-- Verify related records also deleted
SELECT * FROM bill_additional_fees WHERE bill_id = 123;  -- Should be empty
SELECT * FROM payment_list WHERE billing_id = 123;  -- Should be empty
SELECT * FROM disconnection_notices WHERE billing_id = 123;  -- Should be empty
```

---

## Regression Testing

### UI Elements
- [ ] Customer modal still loads
- [ ] Billing history table displays
- [ ] Select/Deselect All buttons work
- [ ] Checkboxes function properly
- [ ] Stats display correctly

### Delete Functionality
- [ ] Password prompt appears
- [ ] Wrong password rejected
- [ ] Correct password accepted
- [ ] Loading indicator shows
- [ ] Success message appears
- [ ] Page reloads with updated data

### Data Integrity
- [ ] Only selected bills deleted
- [ ] Related records cleaned up
- [ ] Customer count accurate
- [ ] Statistics updated
- [ ] No orphaned records

---

## Rollback Procedure (If Issues Found)

If tests fail and data issues occur:

```bash
# Restore from backup (if available)
mysql -u root -p database_name < backup_date.sql

# Or manually restore deleted bills if you have the IDs
# Contact support with bill IDs and customer IDs
```

---

## Success Criteria - All Must Pass ✅

- [x] TEST 1: Delete button hidden when protection disabled
- [x] TEST 2: Delete button visible when protection enabled
- [x] TEST 3: Deleting 1 bill only deletes that 1 bill
- [x] TEST 4: Deleting 3 bills only deletes those 3 bills
- [x] TEST 5: Multi-customer delete only affects selected bills
- [x] TEST 6: Deletion blocked when protection disabled
- [x] TEST 7: Wrong password blocks deletion
- [x] TEST 8: Correct password allows deletion

**When all tests pass:** ✅ FIXES ARE VERIFIED AND READY FOR PRODUCTION

