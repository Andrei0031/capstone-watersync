# Production Forecast Debugging Guide

## Issue
Forecasting works locally but not on production (Verpex).

## Changes Made

1. **Added comprehensive error handling** in `dashboard_data.php`
2. **Added detailed logging** for debugging forecast generation
3. **Created test endpoint** `test_forecast_api.php` for diagnostics

## Step 1: Pull Latest Changes

1. Verpex cPanel → Git Version Control → "Manage" → "Update from Remote"
2. Wait for completion

## Step 2: Test the Forecast API Directly

Visit this URL on your production server (replace with your domain):
```
https://brgymalitbog-watersync.site/test_forecast_api.php?method=linear&period=monthly
```

This will show:
- If RevenueForecast class loads correctly
- Available forecast methods
- If linear regression is working
- Historical data count
- Any errors

## Step 3: Check Browser Console

1. Go to Admin Dashboard on production
2. Press F12 → Console tab
3. Look for:
   - "Fetching revenue forecast..." messages
   - "Revenue forecast data received:" messages
   - Any error messages (red text)

## Step 4: Check Server Error Logs

On Verpex cPanel:
1. Go to **Error Log** or **Logs** section
2. Look for entries containing "Forecast Debug" or "Forecast Error"
3. Common issues:
   - `Forecast Debug: Method 'linear' not found` → Method name mismatch
   - `Forecast Debug: No 'forecasts' key` → getComprehensiveForecast() failing
   - `Forecast Error: ...` → Exception details

## Step 5: Test API with Debug Mode

Add `&debug=1` to the API call:
```
https://brgymalitbog-watersync.site/dashboard_data.php?action=revenue_forecast&period=monthly&forecast_method=linear&forecast_months=6&debug=1
```

This will return debug information in the JSON response.

## Common Issues & Solutions

### Issue 1: "Method 'linear' not found"
**Cause:** Forecast method name mismatch
**Solution:** Check that `revenue_forecasting.php` includes 'linear' in the forecasts array

### Issue 2: Empty forecast array
**Cause:** No historical data or forecast generation failing silently
**Solution:** 
- Check if you have paid bills (`status = 1`) in database
- Check error logs for exceptions

### Issue 3: JavaScript errors
**Cause:** API returning invalid JSON or error response
**Solution:**
- Check browser console for AJAX errors
- Test API directly (Step 2)
- Check if `dashboard_data.php` is accessible

### Issue 4: File not found errors
**Cause:** `revenue_forecasting.php` not included correctly
**Solution:**
- Verify `revenue_forecasting.php` exists in `public_html/`
- Check file permissions (should be 644)

### Issue 5: Database connection issues
**Cause:** Wrong credentials or database not accessible
**Solution:**
- Verify `db.php` has correct Verpex credentials
- Test database connection separately

## Quick Diagnostic Checklist

- [ ] `revenue_forecasting.php` exists in production
- [ ] `dashboard_data.php` exists in production
- [ ] Database has paid bills (`status = 1`)
- [ ] No PHP errors in error logs
- [ ] Browser console shows API calls
- [ ] API returns valid JSON

## Next Steps After Testing

1. **If test_forecast_api.php works:** The issue is in JavaScript/AJAX
2. **If test_forecast_api.php fails:** Check the specific error message
3. **If no errors but empty forecast:** Check historical data count
4. **If method not found:** Verify method name matches exactly ('linear', not 'Linear')

## Contact Points

If issues persist, check:
- Verpex error logs
- Browser console errors
- Network tab (F12 → Network) for failed API calls
- PHP error logs in cPanel

