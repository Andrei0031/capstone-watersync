# Mobile App Integration Guide - WaterSync Billing System

## Overview

This guide explains how to integrate mobile meter reading applications with the WaterSync billing system. The system supports automatic assignment of meter readings to the current billing cycle when mobile apps are connected to the same network.

## Key Features

- **Automatic Billing Cycle Assignment**: Mobile readings are automatically assigned to the active billing cycle
- **Local Network Integration**: Mobile apps can connect when on the same network (no API key required for local connections)
- **OCR Integration**: Support for OCR meter reading values from images
- **Real-time Progress Tracking**: Monitor meter reading collection progress
- **Duplicate Prevention**: Prevents multiple submissions for the same client in the same billing cycle

## API Endpoints

### 1. Submit Meter Reading
**Endpoint**: `POST /api/mobile_meter_reading.php`

**Purpose**: Submit a meter reading with image and OCR value

**Request Body**:
```json
{
    "client_id": 123,
    "meter_reading": 1234.56,
    "image_data": "base64_encoded_image_data",
    "device_info": "Android 12, Samsung Galaxy S21",
    "app_version": "1.0.0",
    "gps_location": "14.5995° N, 120.9842° E"
}
```

**Response**:
```json
{
    "success": true,
    "message": "Meter reading submitted successfully",
    "timestamp": "2024-01-15 10:30:00",
    "api_version": "2.0",
    "data": {
        "reading_id": 456,
        "cycle_info": {
            "cycle_name": "January 2024 Billing",
            "due_date": "2024-02-15"
        },
        "client_info": {
            "name": "John Doe",
            "meter_number": "WM-001234"
        },
        "submission_details": {
            "reading_value": 1234.56,
            "upload_id": "mobile_abc123_1705310200",
            "timestamp": "2024-01-15 10:30:00"
        }
    }
}
```

### 2. Get Client List and Billing Cycle Info
**Endpoint**: `GET /api/mobile_client_list.php`

**Purpose**: Get list of clients and current billing cycle information

**Response**:
```json
{
    "success": true,
    "message": "Client list and billing cycle information retrieved successfully",
    "timestamp": "2024-01-15 10:30:00",
    "api_version": "2.0",
    "data": {
        "billing_cycle": {
            "id": 1,
            "name": "January 2024 Billing",
            "start_date": "2024-01-01",
            "end_date": "2024-01-31",
            "due_date": "2024-02-15",
            "description": "Monthly billing cycle for January 2024",
            "days_remaining": 16
        },
        "progress": {
            "total_clients": 150,
            "readings_submitted": 89,
            "readings_pending": 61,
            "progress_percentage": 59.3
        },
        "clients": [
            {
                "id": 123,
                "meter_number": "WM-001234",
                "meter_code": "METER123",
                "customer_info": {
                    "name": "John Doe",
                    "email": "john@example.com",
                    "address": "123 Main St, City",
                    "contact": "+63 912 345 6789"
                },
                "reading_info": {
                    "status": "pending",
                    "last_reading": 1200.45,
                    "submitted_reading": null,
                    "submission_date": null
                }
            }
        ]
    }
}
```

## Network Configuration

### Local Network Access
When the mobile app is connected to the same local network as the server, API key authentication is bypassed for convenience.

**Supported Local Network Ranges**:
- `127.0.0.1` (Localhost)
- `192.168.*` (Private Class C)
- `10.*` (Private Class A)
- `172.16.*` - `172.31.*` (Private Class B)

### External Access
For connections from outside the local network, an API key is required. Configure this in `/api/config.php`.

## Billing Cycle Management

### Admin Interface
Admins can manage billing cycles through the web interface at `/billing_cycles.php`.

**Billing Cycle Statuses**:
- **Planned**: Created but not yet active
- **Active**: Currently collecting readings (only one can be active)
- **Completed**: Finished cycle
- **Cancelled**: Cancelled cycle

### Automatic Assignment
When a mobile app submits a meter reading:
1. System checks for the current active billing cycle
2. Reading is automatically assigned to that cycle
3. Duplicate submissions for the same client in the same cycle are prevented

## Implementation Steps

### 1. Setup Database Tables
Run the setup script:
```bash
php setup_billing_cycles.php
```

### 2. Create First Billing Cycle
1. Access admin interface: `/billing_cycles.php`
2. Click "Create New Cycle"
3. Fill in cycle details
4. Activate the cycle

### 3. Mobile App Integration
Implement the following in your mobile app:

```javascript
// Get client list and current cycle
async function getCurrentCycleAndClients() {
    const response = await fetch('/api/mobile_client_list.php');
    return await response.json();
}

// Submit meter reading
async function submitMeterReading(clientId, meterReading, imageData) {
    const payload = {
        client_id: clientId,
        meter_reading: meterReading,
        image_data: imageData,
        device_info: getDeviceInfo(),
        app_version: "1.0.0",
        gps_location: await getCurrentLocation()
    };
    
    const response = await fetch('/api/mobile_meter_reading.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
    });
    
    return await response.json();
}
```

## Error Handling

### Common Error Responses

**No Active Billing Cycle**:
```json
{
    "success": false,
    "message": "No active billing cycle found. Please contact administrator.",
    "timestamp": "2024-01-15 10:30:00",
    "api_version": "2.0"
}
```

**Duplicate Submission**:
```json
{
    "success": false,
    "message": "Reading already submitted for this billing cycle",
    "data": {
        "cycle_name": "January 2024 Billing",
        "existing_reading_id": 456
    }
}
```

**Invalid Client**:
```json
{
    "success": false,
    "message": "Invalid or inactive client ID"
}
```

## Security Considerations

1. **Local Network Validation**: Ensure proper validation of local network ranges
2. **Image Upload Limits**: Implement appropriate file size and type restrictions
3. **Rate Limiting**: Consider implementing rate limiting for API endpoints
4. **Data Validation**: Always validate meter reading values and client IDs
5. **Error Logging**: Monitor API usage and errors through logs

## Testing

### Test Local Network Connection
```bash
# Test from local network (should work without API key)
curl -X GET http://192.168.1.100/api/mobile_client_list.php

# Test meter reading submission
curl -X POST http://192.168.1.100/api/mobile_meter_reading.php \
  -H "Content-Type: application/json" \
  -d '{
    "client_id": 1,
    "meter_reading": 1234.56,
    "image_data": "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=="
  }'
```

## Monitoring and Analytics

### Admin Dashboard Features
- View current billing cycle status
- Monitor meter reading collection progress
- Track mobile app submissions
- View reading statistics by cycle

### Progress Tracking
- Total clients vs readings submitted
- Percentage completion
- Daily submission trends
- Cycle completion timeline

## Troubleshooting

### Common Issues

1. **"No active billing cycle found"**
   - Solution: Create and activate a billing cycle in the admin interface

2. **"Invalid client ID"**
   - Solution: Ensure client exists and is active in the system

3. **"Failed to save meter image"**
   - Solution: Check directory permissions for `/uploads/meter_readings/`

4. **API connection issues**
   - Solution: Verify network connectivity and API endpoint URLs

### Support
For technical support or integration assistance, refer to the admin interface or contact the system administrator. 