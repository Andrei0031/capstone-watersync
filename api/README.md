# WaterSync Mobile API Documentation

## Base URL

### Development (Local)
```
http://localhost/CAPSTONE/api/
# OR
http://192.168.1.XXX/CAPSTONE/api/  (Your local IP)
```

### Production (Verpex Hosting)
```
https://yourdomain.com/api/
# OR if using subdomain:
https://api.yourdomain.com/
```

**Important**: Replace `yourdomain.com` with your actual Verpex domain name!

## Authentication
All API endpoints require an Authorization header:
```
Authorization: Bearer watersync_mobile_2024_new_malitbog
```

## Response Format
All responses follow this format:
```json
{
  "success": true/false,
  "message": "Description message",
  "data": {} // Only present if success is true and data exists
}
```

## Endpoints

### 1. Authentication
**POST** `/auth.php`

Login for both customers and admin users.

**Customer Login Request:**
```json
{
  "email": "customer@example.com",
  "password": "password123",
  "user_type": "customer"
}
```

**Admin Login Request:**
```json
{
  "username": "admin_username", 
  "password": "admin_password",
  "user_type": "admin"
}
```

**Response (Success):**
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user_id": 1,
    "name": "John Doe", // for customer
    "username": "admin", // for admin
    "email": "user@example.com", // for customer only
    "user_type": "customer", // or "admin"
    "token": "Bearer watersync_mobile_2024_new_malitbog"
  }
}
```

### 2. Customer Management
**GET** `/customers.php` - Get all customers (admin)
**GET** `/customers.php?id=1` - Get specific customer
**POST** `/customers.php` - Create new customer (admin)

**Create Customer Request:**
```json
{
  "firstname": "John",
  "lastname": "Doe",
  "email": "john@example.com",
  "phone": "09123456789",
  "address": "123 Main St",
  "meter_number": "MT001" // optional
}
```

### 3. Bills Management
**GET** `/bills.php` - Get all bills (admin)
**GET** `/bills.php?customer_id=1` - Get bills for specific customer
**GET** `/bills.php?bill_id=1` - Get specific bill
**POST** `/bills.php` - Create new bill (admin)
**PUT** `/bills.php` - Update bill status

**Create Bill Request:**
```json
{
  "client_id": 1,
  "current_reading": 1250.5,
  "billing_period": "January 2024"
}
```

**Mark Bill as Paid Request:**
```json
{
  "bill_id": 1,
  "action": "mark_paid",
  "payment_amount": 450.00,
  "payment_method": "cash"
}
```

### 4. Water Interruption Notices
**GET** `/notices.php` - Get all active notices
**GET** `/notices.php?id=1` - Get specific notice
**POST** `/notices.php` - Create new notice (admin)
**PUT** `/notices.php` - Update notice status

**Create Notice Request:**
```json
{
  "title": "Scheduled Water Interruption",
  "content": "Water service will be interrupted on Jan 15, 2024 from 8AM-5PM for maintenance.",
  "notice_type": "interruption"
}
```

### 5. Outage Reports
**GET** `/outage_reports.php` - Get all reports (admin)
**GET** `/outage_reports.php?customer_id=1` - Get reports for specific customer
**GET** `/outage_reports.php?status=pending` - Filter by status
**POST** `/outage_reports.php` - Submit new outage report
**PUT** `/outage_reports.php` - Update report status (admin)

**Submit Report Request:**
```json
{
  "customer_id": 1,
  "description": "No water supply since this morning",
  "location": "Barangay New Malitbog, Street 123",
  "urgency_level": "high" // low, medium, high
}
```

**Update Report Status:**
```json
{
  "report_id": 1,
  "status": "resolved", // pending, in_progress, resolved, cancelled
  "resolution_notes": "Water supply restored. Pipe leak fixed."
}
```

### 6. Meter Reading Upload
**POST** `/upload_reading.php`

Upload meter reading with image and OCR data.

**Request Body:**
```json
{
  "client_id": 1,
  "image_data": "base64_encoded_image_data",
  "mobile_upload_id": "unique_mobile_id", // optional
  "ocr_reading": 1250.5 // optional, from Google ML Kit
}
```

**Response:**
```json
{
  "success": true,
  "message": "Reading uploaded successfully",
  "data": {
    "reading_id": 1,
    "client_info": {
      "meter_number": "MT001",
      "customer_name": "John Doe"
    },
    "filename": "mobile_12345_2024-01-15_10-30-00.jpg",
    "ocr_reading": 1250.5
  }
}
```

### 7. Dashboard Statistics
**GET** `/dashboard_stats.php`

Get overview statistics for dashboard display.

**Response:**
```json
{
  "success": true,
  "message": "Dashboard statistics retrieved successfully",
  "data": {
    "total_clients": 150,
    "active_customers": 148,
    "bills_this_month": 45,
    "unpaid_bills": 12,
    "paid_bills": 138,
    "recent_bills": 8,
    "collection_rate": 92.0,
    "system_status": "active",
    "last_updated": "2024-01-15 10:30:00"
  }
}
```

### 8. Client List
**GET** `/client_list.php` - Get paginated client list
**GET** `/client_list.php?page=1&limit=20` - With pagination
**GET** `/client_list.php?search=john` - With search

**Query Parameters:**
- `page` (optional): Page number (default: 1)
- `limit` (optional): Items per page (default: 20, max: 100)
- `search` (optional): Search term for name, email, or meter number

**Response:**
```json
{
  "success": true,
  "message": "Client list retrieved successfully",
  "data": {
    "clients": [
      {
        "client_id": 1,
        "customer_id": 1,
        "meter_number": "MT001",
        "connection_date": "2023-01-15",
        "customer_name": "John Doe",
        "firstname": "John",
        "lastname": "Doe",
        "email": "john@example.com",
        "phone": "09123456789",
        "address": "123 Main St",
        "status": "active",
        "total_bills": 12,
        "unpaid_bills": 2,
        "last_bill_date": "2024-01-01"
      }
    ],
    "pagination": {
      "current_page": 1,
      "total_pages": 8,
      "total_clients": 150,
      "clients_per_page": 20,
      "has_next": true,
      "has_previous": false
    }
  }
}
```

## Mobile App Integration Guide

### For Flutter/React Native Apps:

1. **HTTP Client Setup:**
```dart
// Flutter example
class ApiService {
  // DEVELOPMENT (Local):
  // static const String baseUrl = 'http://localhost/CAPSTONE/api/';
  // static const String baseUrl = 'http://192.168.1.XXX/CAPSTONE/api/';
  
  // PRODUCTION (Verpex):
  static const String baseUrl = 'https://yourdomain.com/api/';
  
  static const String authToken = 'Bearer watersync_mobile_2024_new_malitbog';
  
  static Map<String, String> get headers => {
    'Content-Type': 'application/json',
    'Authorization': authToken,
  };
}
```

2. **Login Examples:**

**Customer Login:**
```dart
Future<Map<String, dynamic>> customerLogin(String email, String password) async {
  final response = await http.post(
    Uri.parse('${ApiService.baseUrl}auth.php'),
    headers: ApiService.headers,
    body: json.encode({
      'email': email,
      'password': password,
      'user_type': 'customer',
    }),
  );
  
  return json.decode(response.body);
}
```

**Admin Login:**
```dart
Future<Map<String, dynamic>> adminLogin(String username, String password) async {
  final response = await http.post(
    Uri.parse('${ApiService.baseUrl}auth.php'),
    headers: ApiService.headers,
    body: json.encode({
      'username': username,
      'password': password,
      'user_type': 'admin',
    }),
  );
  
  return json.decode(response.body);
}
```

3. **Dashboard Statistics Example:**
```dart
Future<Map<String, dynamic>> getDashboardStats() async {
  final response = await http.get(
    Uri.parse('${ApiService.baseUrl}dashboard_stats.php'),
    headers: ApiService.headers,
  );
  
  return json.decode(response.body);
}

4. **Client List Example:**
```dart
Future<Map<String, dynamic>> getClientList({int page = 1, int limit = 20, String search = ''}) async {
  var uri = Uri.parse('${ApiService.baseUrl}client_list.php');
  
  // Add query parameters
  var queryParams = <String, String>{
    'page': page.toString(),
    'limit': limit.toString(),
  };
  
  if (search.isNotEmpty) {
    queryParams['search'] = search;
  }
  
  uri = uri.replace(queryParameters: queryParams);
  
  final response = await http.get(uri, headers: ApiService.headers);
  
  return json.decode(response.body);
}
```

5. **Upload Meter Reading with Google ML Kit:**
```dart
Future<Map<String, dynamic>> uploadMeterReading(
  int clientId, 
  String imageBase64,
  double? ocrReading
) async {
  final response = await http.post(
    Uri.parse('${ApiService.baseUrl}upload_reading.php'),
    headers: ApiService.headers,
    body: json.encode({
      'client_id': clientId,
      'image_data': imageBase64,
      'ocr_reading': ocrReading,
      'mobile_upload_id': DateTime.now().millisecondsSinceEpoch.toString(),
    }),
  );
  
  return json.decode(response.body);
}
```

### Google ML Kit Text Recognition Integration:

```dart
// Add to pubspec.yaml:
// google_mlkit_text_recognition: ^0.5.0

import 'package:google_mlkit_text_recognition/google_mlkit_text_recognition.dart';

Future<double?> extractMeterReading(String imagePath) async {
  final inputImage = InputImage.fromFilePath(imagePath);
  final textRecognizer = TextRecognizer(script: TextRecognitionScript.latin);
  
  try {
    final RecognizedText recognizedText = await textRecognizer.processImage(inputImage);
    
    // Extract numeric values from recognized text
    for (TextBlock block in recognizedText.blocks) {
      for (TextLine line in block.lines) {
        // Look for meter reading patterns (adjust regex as needed)
        final RegExp meterPattern = RegExp(r'\d+\.?\d*');
        final matches = meterPattern.allMatches(line.text);
        
        for (RegExpMatch match in matches) {
          final reading = double.tryParse(match.group(0) ?? '');
          if (reading != null && reading > 0) {
            return reading;
          }
        }
      }
    }
  } finally {
    textRecognizer.close();
  }
  
  return null;
}
```

## Error Handling

Common HTTP status codes:
- `200` - Success
- `400` - Bad Request (missing/invalid data)
- `401` - Unauthorized (invalid/missing token)
- `404` - Not Found
- `405` - Method Not Allowed
- `500` - Internal Server Error

## Database Sync

The mobile app connects to the same database as your web system:
- Database: `watersync`
- Host: `localhost` (adjust for your server)
- Tables: `customer_accounts`, `client_list`, `billing_list`, `notices`, `outage_reports`, `pending_meter_readings`

## Security Notes

1. **In Production:**
   - Replace simple API key with JWT tokens
   - Use HTTPS for all communications
   - Implement rate limiting
   - Add input validation and sanitization

2. **Database Security:**
   - Use prepared statements (already implemented)
   - Validate all user inputs
   - Implement proper access controls

## Testing

Use tools like Postman or curl to test the API endpoints:

```bash
# Test customer login
curl -X POST http://localhost/CAPSTONE/api/auth.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer watersync_mobile_2024_new_malitbog" \
  -d '{"email":"customer@example.com","password":"password123","user_type":"customer"}'

# Test admin login
curl -X POST http://localhost/CAPSTONE/api/auth.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer watersync_mobile_2024_new_malitbog" \
  -d '{"username":"admin_username","password":"admin_password","user_type":"admin"}'

# Test get notices
curl -X GET http://localhost/CAPSTONE/api/notices.php \
  -H "Authorization: Bearer watersync_mobile_2024_new_malitbog"

# Test dashboard statistics
curl -X GET http://localhost/CAPSTONE/api/dashboard_stats.php \
  -H "Authorization: Bearer watersync_mobile_2024_new_malitbog"

# Test client list
curl -X GET http://localhost/CAPSTONE/api/client_list.php \
  -H "Authorization: Bearer watersync_mobile_2024_new_malitbog"

# Test client list with search
curl -X GET "http://localhost/CAPSTONE/api/client_list.php?search=john&page=1&limit=10" \
  -H "Authorization: Bearer watersync_mobile_2024_new_malitbog"
``` 