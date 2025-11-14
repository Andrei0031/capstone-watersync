# Mobile App Configuration - Quick Reference

## 🔗 API Base URLs

### Development (Local)
```
http://localhost/CAPSTONE/api/
OR
http://192.168.1.XXX/CAPSTONE/api/  (Your local IP)
```

### Production (Verpex)
```
https://yourdomain.com/api/
OR
https://api.yourdomain.com/  (If using subdomain)
```

---

## 🔑 Authentication

### Authorization Header
```
Authorization: Bearer watersync_mobile_2024_new_malitbog
```

### Header Format (Full)
```json
{
  "Content-Type": "application/json",
  "Authorization": "Bearer watersync_mobile_2024_new_malitbog"
}
```

---

## 📍 API Endpoints

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/auth.php` | POST | User login (customer/admin) |
| `/api/mobile_client_list.php` | GET | Get list of clients for current billing cycle |
| `/api/mobile_meter_reading.php` | POST | Submit meter reading with image |
| `/api/ocr_meter_reading.php` | POST | Process OCR from image (optional) |

---

## 📱 Mobile App Code Examples

### Flutter/Dart Example
```dart
class ApiService {
  // CHANGE THIS TO YOUR VERPEX DOMAIN
  static const String baseUrl = 'https://yourdomain.com/api/';
  static const String authToken = 'Bearer watersync_mobile_2024_new_malitbog';
  
  static Map<String, String> get headers => {
    'Content-Type': 'application/json',
    'Authorization': authToken,
  };
  
  // Get Client List
  static Future<Map<String, dynamic>> getClientList() async {
    final response = await http.get(
      Uri.parse('${baseUrl}mobile_client_list.php'),
      headers: headers,
    );
    return json.decode(response.body);
  }
  
  // Submit Meter Reading
  static Future<Map<String, dynamic>> submitReading({
    required int clientId,
    required double reading,
    required String imageBase64,
  }) async {
    final response = await http.post(
      Uri.parse('${baseUrl}mobile_meter_reading.php'),
      headers: headers,
      body: json.encode({
        'client_id': clientId,
        'meter_reading': reading,
        'image_data': imageBase64,
        'device_info': 'Android/iOS',
        'app_version': '1.0.0',
      }),
    );
    return json.decode(response.body);
  }
}
```

### React Native/JavaScript Example
```javascript
const API_CONFIG = {
  BASE_URL: 'https://yourdomain.com/api/',
  AUTH_TOKEN: 'Bearer watersync_mobile_2024_new_malitbog',
};

const headers = {
  'Content-Type': 'application/json',
  'Authorization': API_CONFIG.AUTH_TOKEN,
};

// Get Client List
export const getClientList = async () => {
  const response = await fetch(`${API_CONFIG.BASE_URL}mobile_client_list.php`, {
    method: 'GET',
    headers: headers,
  });
  return await response.json();
};

// Submit Meter Reading
export const submitReading = async (clientId, reading, imageBase64) => {
  const response = await fetch(`${API_CONFIG.BASE_URL}mobile_meter_reading.php`, {
    method: 'POST',
    headers: headers,
    body: JSON.stringify({
      client_id: clientId,
      meter_reading: reading,
      image_data: imageBase64,
      device_info: 'Android/iOS',
      app_version: '1.0.0',
    }),
  });
  return await response.json();
};
```

---

## ✅ Checklist for Mobile App Update

### Before Deployment
- [ ] Update base URL to Verpex domain
- [ ] Change `http://` to `https://`
- [ ] Remove localhost/local IP addresses
- [ ] Test API connection
- [ ] Verify authentication works
- [ ] Test image upload
- [ ] Test OCR processing (if used)

### After Deployment
- [ ] Test login functionality
- [ ] Test fetching client list
- [ ] Test submitting meter reading
- [ ] Test error handling
- [ ] Test on WiFi and mobile data
- [ ] Verify images are uploading correctly

---

## 🛠️ Troubleshooting

### Connection Issues
1. **Check base URL** - Must be `https://yourdomain.com/api/`
2. **Check SSL** - Ensure HTTPS is enabled
3. **Check CORS** - Server should allow all origins (`*`)
4. **Check firewall** - Verpex firewall should allow API requests

### Authentication Issues
1. **Check token** - Must be exactly: `watersync_mobile_2024_new_malitbog`
2. **Check header** - Must be: `Authorization: Bearer [token]`
3. **Check format** - Token must include "Bearer " prefix

### Image Upload Issues
1. **Check base64 encoding** - Image must be properly encoded
2. **Check image size** - Should be under 10MB
3. **Check permissions** - Upload folder must be writable

---

## 📞 Support

If issues persist:
1. Check Verpex error logs
2. Test API endpoints with Postman
3. Verify database connection
4. Check file permissions

---

**Remember**: Replace `yourdomain.com` with your actual Verpex domain name!

