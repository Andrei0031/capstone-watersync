# Batch Upload API - Mobile App Integration Guide

## 📍 Endpoint
```
POST /api/batch_upload_readings.php
```

## 🔑 Authentication
```
Authorization: Bearer watersync_mobile_2024_new_malitbog
Content-Type: application/json
```

## 📤 Request Format

### Required Structure:
```json
{
  "readings": [
    {
      "client_id": 123,
      "image_data": "base64_encoded_image_string"
    }
  ]
}
```

### Optional Fields (per reading):
```json
{
  "readings": [
    {
      "client_id": 123,
      "image_data": "base64_encoded_image_string",
      "meter_reading": 12345.0,        // Optional: manual reading if OCR fails
      "device_info": "Android 12",     // Optional
      "app_version": "1.0.0",          // Optional
      "gps_location": "14.5995,120.9842"  // Optional: "lat,lng"
    }
  ]
}
```

## 📥 Response Format

### Success Response:
```json
{
  "success": true,
  "message": "Batch upload completed: 2 succeeded, 0 failed",
  "timestamp": "2024-01-15 10:30:45",
  "api_version": "2.0",
  "data": {
    "total": 2,
    "success_count": 2,
    "failed_count": 0,
    "cycle_info": {
      "cycle_name": "January 2024",
      "due_date": "2024-01-31"
    },
    "results": [
      {
        "index": 0,
        "success": true,
        "client_id": 123,
        "reading_id": 456,
        "status": "processed",
        "ocr_processed": true,
        "ocr_reading": "12345",
        "client_name": "John Doe",
        "meter_code": "M001"
      }
    ]
  }
}
```

### Error Response:
```json
{
  "success": false,
  "message": "Invalid batch data. Expected \"readings\" array.",
  "timestamp": "2024-01-15 10:30:45",
  "api_version": "2.0"
}
```

## 📱 Flutter/Dart Example

```dart
import 'dart:convert';
import 'package:http/http.dart' as http;

class BatchUploadService {
  static const String baseUrl = 'https://yourdomain.com/api/';
  static const String authToken = 'Bearer watersync_mobile_2024_new_malitbog';
  
  static Map<String, String> get headers => {
    'Content-Type': 'application/json',
    'Authorization': authToken,
  };
  
  /// Upload multiple meter readings in batch
  static Future<Map<String, dynamic>> uploadBatchReadings({
    required List<BatchReading> readings,
  }) async {
    try {
      // Prepare batch data
      final batchData = {
        'readings': readings.map((reading) => {
          'client_id': reading.clientId,
          'image_data': reading.imageBase64,
          if (reading.meterReading != null) 'meter_reading': reading.meterReading,
          if (reading.deviceInfo != null) 'device_info': reading.deviceInfo,
          if (reading.appVersion != null) 'app_version': reading.appVersion,
          if (reading.gpsLocation != null) 'gps_location': reading.gpsLocation,
        }).toList(),
      };
      
      // Make API call
      final response = await http.post(
        Uri.parse('${baseUrl}batch_upload_readings.php'),
        headers: headers,
        body: json.encode(batchData),
      );
      
      // Parse response
      final responseData = json.decode(response.body);
      
      if (response.statusCode == 200 && responseData['success'] == true) {
        return {
          'success': true,
          'data': responseData['data'],
          'message': responseData['message'],
        };
      } else {
        return {
          'success': false,
          'message': responseData['message'] ?? 'Upload failed',
          'error': responseData,
        };
      }
    } catch (e) {
      return {
        'success': false,
        'message': 'Network error: $e',
      };
    }
  }
}

class BatchReading {
  final int clientId;
  final String imageBase64;
  final double? meterReading;
  final String? deviceInfo;
  final String? appVersion;
  final String? gpsLocation;
  
  BatchReading({
    required this.clientId,
    required this.imageBase64,
    this.meterReading,
    this.deviceInfo,
    this.appVersion,
    this.gpsLocation,
  });
}

// Usage Example:
void uploadReadings() async {
  final readings = [
    BatchReading(
      clientId: 123,
      imageBase64: base64Image1,
      meterReading: 12345.0,
      deviceInfo: 'Android 12',
      appVersion: '1.0.0',
    ),
    BatchReading(
      clientId: 124,
      imageBase64: base64Image2,
      meterReading: 23456.0,
    ),
  ];
  
  final result = await BatchUploadService.uploadBatchReadings(
    readings: readings,
  );
  
  if (result['success']) {
    final data = result['data'];
    print('Uploaded: ${data['success_count']}/${data['total']}');
    
    // Check individual results
    for (var readingResult in data['results']) {
      if (readingResult['success']) {
        print('✓ Client ${readingResult['client_id']}: Success');
      } else {
        print('✗ Client ${readingResult['client_id']}: ${readingResult['error']}');
      }
    }
  } else {
    print('Upload failed: ${result['message']}');
  }
}
```

## 🔍 Common Issues & Solutions

### Issue 1: "Invalid batch data. Expected 'readings' array"
**Solution:** Ensure your JSON structure has a `readings` key containing an array:
```dart
// ✅ Correct
{'readings': [...]}

// ❌ Wrong
[...]  // Missing 'readings' wrapper
```

### Issue 2: "No data received"
**Solution:** Check that you're sending the request body correctly:
```dart
// ✅ Correct
body: json.encode(batchData)

// ❌ Wrong
body: batchData  // Must be JSON string
```

### Issue 3: "Invalid or missing JSON data"
**Solution:** Ensure proper JSON encoding:
```dart
import 'dart:convert';

// ✅ Correct
body: json.encode(data)

// ❌ Wrong
body: data.toString()
```

### Issue 4: "No active billing cycle found"
**Solution:** Ensure there's an active billing cycle in the database. Contact administrator.

### Issue 5: "Invalid or inactive client ID"
**Solution:** Verify that:
- Client ID exists in database
- Client status is active (status = 1)
- Client ID is an integer

## 🧪 Testing

### Test with curl:
```bash
curl -X POST https://yourdomain.com/api/batch_upload_readings.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer watersync_mobile_2024_new_malitbog" \
  -d '{
    "readings": [
      {
        "client_id": 1,
        "image_data": "base64_encoded_image_here"
      }
    ]
  }'
```

### Test Script:
Visit: `https://yourdomain.com/api/test_batch_upload.php` to check API configuration.

## 📝 Notes

- Maximum batch size: 50 readings per request
- Each reading is processed independently
- OCR is automatically processed (Roboflow → Tesseract → Manual reading)
- Status: `processed` (success) or `failed` (OCR failed)
- Duplicate readings for same client in same cycle are rejected

## 🔗 Related Endpoints

- Single Upload: `/api/mobile_meter_reading.php`
- Get Clients: `/api/mobile_client_list.php`

