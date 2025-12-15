# API Specification - Mess Management System

## API Overview

This document defines the RESTful API endpoints for the Mess Management System. The API follows REST conventions and uses JSON for data exchange.

## Base URL

```
https://your-domain.com/api/v1
```

## Authentication

All API endpoints (except public ones) require authentication using Bearer tokens:

```
Authorization: Bearer {token}
```

## Response Format

All responses follow a consistent format:

### Success Response

```json
{
  "success": true,
  "data": {},
  "message": "Operation successful",
  "meta": {
    "timestamp": "2024-01-01T12:00:00Z",
    "pagination": {
      "current_page": 1,
      "total_pages": 10,
      "total_items": 100,
      "per_page": 10
    }
  }
}
```

### Error Response

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "The given data was invalid.",
    "details": {
      "email": ["The email field is required."]
    }
  },
  "meta": {
    "timestamp": "2024-01-01T12:00:00Z"
  }
}
```

## API Endpoints

### 1. Authentication Endpoints

#### Login

```http
POST /auth/login
Content-Type: application/json

{
    "email": "user@example.com",
    "password": "password"
}
```

**Response:**

```json
{
  "success": true,
  "data": {
    "user": {
      "id": 1,
      "name": "John Doe",
      "email": "user@example.com",
      "role": {
        "id": 1,
        "name": "Member",
        "slug": "member"
      },
      "mess": {
        "id": 1,
        "name": "Example Mess"
      }
    },
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "expires_in": 3600
  }
}
```

#### Logout

```http
POST /auth/logout
Authorization: Bearer {token}
```

#### Refresh Token

```http
POST /auth/refresh
Authorization: Bearer {token}
```

#### Forgot Password

```http
POST /auth/forgot-password
Content-Type: application/json

{
    "email": "user@example.com"
}
```

#### Reset Password with OTP

```http
POST /auth/reset-password
Content-Type: application/json

{
    "email": "user@example.com",
    "otp": "123456",
    "password": "newpassword"
}
```

### 2. User Management Endpoints

#### Get Current User

```http
GET /user
Authorization: Bearer {token}
```

#### Update Profile

```http
PUT /user/profile
Authorization: Bearer {token}
Content-Type: application/json

{
    "name": "John Doe",
    "phone": "+8801234567890"
}
```

#### Change Password

```http
PUT /user/password
Authorization: Bearer {token}
Content-Type: application/json

{
    "current_password": "oldpassword",
    "password": "newpassword",
    "password_confirmation": "newpassword"
}
```

### 3. Mess Management Endpoints

#### Get Mess Details

```http
GET /mess
Authorization: Bearer {token}
```

#### Update Mess Settings

```http
PUT /mess
Authorization: Bearer {token}
Content-Type: application/json

{
    "name": "Updated Mess Name",
    "address": "New Address",
    "meal_rate_breakfast": 50.00,
    "meal_rate_lunch": 80.00,
    "meal_rate_dinner": 70.00,
    "payment_cycle": "monthly"
}
```

#### Get Members

```http
GET /mess/members?page=1&per_page=20&status=active
Authorization: Bearer {token}
```

#### Add Member

```http
POST /mess/members
Authorization: Bearer {token}
Content-Type: application/json

{
    "name": "New Member",
    "email": "member@example.com",
    "phone": "+8801234567890",
    "room_number": "A-101",
    "joining_date": "2024-01-01"
}
```

#### Update Member

```http
PUT /mess/members/{id}
Authorization: Bearer {token}
Content-Type: application/json

{
    "room_number": "A-102",
    "status": "active"
}
```

#### Remove Member

```http
DELETE /mess/members/{id}
Authorization: Bearer {token}
```

### 4. Meal Management Endpoints

#### Get Meals

```http
GET /meals?date=2024-01-01&member_id=1
Authorization: Bearer {token}
```

#### Enter Today's Meals

```http
POST /meals/today
Authorization: Bearer {token}
Content-Type: application/json

{
    "breakfast_count": 1,
    "lunch_count": 1,
    "dinner_count": 0,
    "extra_items": "Extra rice"
}
```

#### Update Meals

```http
PUT /meals/{id}
Authorization: Bearer {token}
Content-Type: application/json

{
    "breakfast_count": 2,
    "lunch_count": 1,
    "dinner_count": 1
}
```

#### Lock Meals (Admin)

```http
POST /meals/lock?date=2024-01-01
Authorization: Bearer {token}
```

#### Get Meal Summary

```http
GET /meals/summary?start_date=2024-01-01&end_date=2024-01-31
Authorization: Bearer {token}
```

### 5. Bazar Management Endpoints

#### Get Bazars

```http
GET /bazars?date=2024-01-01&status=all
Authorization: Bearer {token}
```

#### Create Bazar

```http
POST /bazars
Authorization: Bearer {token}
Content-Type: application/json

{
    "date": "2024-01-01",
    "bazar_man_id": 2,
    "items": [
        {
            "item_name": "Rice",
            "quantity": 5,
            "unit": "kg",
            "unit_price": 60.00
        },
        {
            "item_name": "Oil",
            "quantity": 2,
            "unit": "liter",
            "unit_price": 120.00
        }
    ],
    "notes": "Weekly grocery"
}
```

#### Update Bazar

```http
PUT /bazars/{id}
Authorization: Bearer {token}
Content-Type: application/json

{
    "status": "approved",
    "notes": "Updated notes"
}
```

#### Upload Bazar Receipt

```http
POST /bazars/{id}/receipt
Authorization: Bearer {token}
Content-Type: multipart/form-data

receipt: [file]
```

#### Get Bazar Report

```http
GET /bazars/report?month=1&year=2024
Authorization: Bearer {token}
```

### 6. Expense Management Endpoints

#### Get Expenses

```http
GET /expenses?category=electricity&date=2024-01-01
Authorization: Bearer {token}
```

#### Create Expense

```http
POST /expenses
Authorization: Bearer {token}
Content-Type: application/json

{
    "category": "electricity",
    "description": "January electricity bill",
    "amount": 2500.00,
    "date": "2024-01-01"
}
```

#### Update Expense

```http
PUT /expenses/{id}
Authorization: Bearer {token}
Content-Type: application/json

{
    "amount": 2600.00,
    "status": "approved"
}
```

### 7. Billing Endpoints

#### Get Bills

```http
GET /bills?month=1&year=2024&member_id=1
Authorization: Bearer {token}
```

#### Generate Monthly Bills

```http
POST /bills/generate?month=1&year=2024
Authorization: Bearer {token}
```

#### Get Member Bill Details

```http
GET /bills/{id}
Authorization: Bearer {token}
```

#### Download Bill PDF

```http
GET /bills/{id}/pdf
Authorization: Bearer {token}
```

### 8. Payment Endpoints

#### Get Payments

```http
GET /payments?member_id=1&status=all
Authorization: Bearer {token}
```

#### Add Payment

```http
POST /payments
Authorization: Bearer {token}
Content-Type: application/json

{
    "bill_id": 1,
    "member_id": 1,
    "amount": 2500.00,
    "payment_method": "bkash",
    "transaction_id": "TXN123456",
    "notes": "Monthly payment"
}
```

#### Upload Payment Receipt

```http
POST /payments/{id}/receipt
Authorization: Bearer {token}
Content-Type: multipart/form-data

receipt: [file]
```

#### Get Payment History

```http
GET /payments/history?member_id=1&start_date=2024-01-01&end_date=2024-01-31
Authorization: Bearer {token}
```

### 9. Dashboard Endpoints

#### Get Admin Dashboard Data

```http
GET /dashboard/admin
Authorization: Bearer {token}
```

**Response:**

```json
{
  "success": true,
  "data": {
    "total_members": 25,
    "active_members": 23,
    "total_meals_today": 65,
    "total_bazar_this_month": 15000.0,
    "total_due_amount": 25000.0,
    "meal_trends": [
      { "date": "2024-01-01", "breakfast": 20, "lunch": 25, "dinner": 22 }
    ],
    "recent_activities": [
      {
        "user": "John Doe",
        "action": "Added new member",
        "timestamp": "2024-01-01T10:30:00Z"
      }
    ]
  }
}
```

#### Get Member Dashboard Data

```http
GET /dashboard/member
Authorization: Bearer {token}
```

**Response:**

```json
{
  "success": true,
  "data": {
    "today_meals": {
      "breakfast": 1,
      "lunch": 1,
      "dinner": 0
    },
    "monthly_meals": 85,
    "monthly_bill": 8500.0,
    "remaining_due": 2500.0,
    "upcoming_bazar_schedule": [
      {
        "date": "2024-01-15",
        "bazar_man": "Jane Smith"
      }
    ],
    "recent_payments": [
      {
        "amount": 5000.0,
        "date": "2024-01-01",
        "method": "bkash"
      }
    ]
  }
}
```

### 10. Inventory Management Endpoints (Optional)

#### Get Inventory Items

```http
GET /inventory?category=food&low_stock=true
Authorization: Bearer {token}
```

#### Add Inventory Item

```http
POST /inventory
Authorization: Bearer {token}
Content-Type: application/json

{
    "item_name": "Rice",
    "category": "food",
    "current_stock": 50,
    "unit": "kg",
    "min_stock_alert": 10
}
```

#### Update Stock

```http
POST /inventory/{id}/transactions
Authorization: Bearer {token}
Content-Type: application/json

{
    "transaction_type": "in",
    "quantity": 20,
    "unit_price": 60.00,
    "notes": "Weekly purchase"
}
```

### 11. Attendance Endpoints (Optional)

#### Get Attendance

```http
GET /attendance?date=2024-01-01&meal_type=lunch
Authorization: Bearer {token}
```

#### Scan QR Code for Meal

```http
POST /attendance/scan
Authorization: Bearer {token}
Content-Type: application/json

{
    "qr_code": "QR123456",
    "meal_type": "lunch"
}
```

#### Approve Attendance (Staff)

```http
PUT /attendance/{id}/approve
Authorization: Bearer {token}
```

### 12. Announcement Endpoints

#### Get Announcements

```http
GET /announcements?type=general&active=true
Authorization: Bearer {token}
```

#### Create Announcement

```http
POST /announcements
Authorization: Bearer {token}
Content-Type: application/json

{
    "title": "Maintenance Notice",
    "content": "Water supply will be interrupted tomorrow",
    "type": "maintenance",
    "scheduled_at": "2024-01-01T09:00:00Z"
}
```

#### Mark Announcement as Read

```http
POST /announcements/{id}/read
Authorization: Bearer {token}
```

### 13. Notification Endpoints

#### Get Notifications

```http
GET /notifications?unread=true
Authorization: Bearer {token}
```

#### Mark Notification as Read

```http
PUT /notifications/{id}/read
Authorization: Bearer {token}
```

#### Mark All Notifications as Read

```http
PUT /notifications/read-all
Authorization: Bearer {token}
```

### 14. Settings Endpoints

#### Get System Settings

```http
GET /settings?group=meal_timing
Authorization: Bearer {token}
```

#### Update Settings

```http
PUT /settings
Authorization: Bearer {token}
Content-Type: application/json

{
    "meal_cutoff_time": "10:00",
    "currency": "BDT",
    "language": "en",
    "notifications_enabled": true
}
```

### 15. Export Endpoints

#### Export Monthly Report

```http
GET /export/monthly-report?month=1&year=2024&format=pdf
Authorization: Bearer {token}
```

#### Export Bazar List

```http
GET /export/bazar-list?start_date=2024-01-01&end_date=2024-01-31&format=excel
Authorization: Bearer {token}
```

#### Export Meal List

```http
GET /export/meal-list?month=1&year=2024&format=csv
Authorization: Bearer {token}
```

## Error Codes

| Error Code          | HTTP Status | Description                     |
| ------------------- | ----------- | ------------------------------- |
| VALIDATION_ERROR    | 422         | Request validation failed       |
| UNAUTHORIZED        | 401         | Authentication required         |
| FORBIDDEN           | 403         | Insufficient permissions        |
| NOT_FOUND           | 404         | Resource not found              |
| CONFLICT            | 409         | Resource conflict               |
| RATE_LIMIT_EXCEEDED | 429         | Too many requests               |
| SERVER_ERROR        | 500         | Internal server error           |
| SERVICE_UNAVAILABLE | 503         | Service temporarily unavailable |

## Rate Limiting

- **Authentication endpoints**: 5 requests per minute
- **General endpoints**: 60 requests per minute
- **File upload endpoints**: 10 requests per minute

## File Upload Limits

- **Maximum file size**: 10MB
- **Allowed formats**:
  - Images: jpg, jpeg, png, gif
  - Documents: pdf, doc, docx
  - Receipts: jpg, jpeg, png, pdf

## WebSocket Events (Real-time Updates)

### Connection

```
ws://your-domain.com/ws/{token}
```

### Events

#### New Announcement

```json
{
  "event": "announcement.created",
  "data": {
    "id": 1,
    "title": "New Announcement",
    "content": "Content here"
  }
}
```

#### Meal Update

```json
{
    "event": "meal.updated",
    "data": {
        "member_id": 1,
        "date": "2024-01-01",
        "meals": {...}
    }
}
```

#### Payment Received

```json
{
  "event": "payment.received",
  "data": {
    "member_id": 1,
    "amount": 2500.0,
    "method": "bkash"
  }
}
```

This API specification provides a comprehensive foundation for the mess management system with proper authentication, error handling, and real-time capabilities.
