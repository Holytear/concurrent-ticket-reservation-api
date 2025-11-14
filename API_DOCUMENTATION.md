# API Documentation

## Base URL

```
http://localhost:8000/api
```

## Authentication

All protected endpoints require a Bearer token in the Authorization header:

```
Authorization: Bearer {your-token-here}
```

## Endpoints

### 1. Get All Events

Get a paginated list of all events.

**Endpoint:** `GET /events`

**Authentication:** Not required

**Response:** `200 OK`

```json
{
    "current_page": 1,
    "data": [
        {
            "id": 1,
            "name": "Laravel Conference 2024",
            "description": "Annual Laravel conference",
            "total_tickets": 1000,
            "available_tickets": 847,
            "price": "199.99",
            "event_date": "2024-06-15T10:00:00.000000Z",
            "created_at": "2024-01-01T00:00:00.000000Z",
            "updated_at": "2024-01-01T00:00:00.000000Z"
        }
    ],
    "per_page": 20,
    "total": 50
}
```

---

### 2. Get Event Details

Get detailed information about a specific event.

**Endpoint:** `GET /events/{eventId}`

**Authentication:** Not required

**Parameters:**
- `eventId` (integer, required) - The ID of the event

**Response:** `200 OK`

```json
{
    "id": 1,
    "name": "Laravel Conference 2024",
    "description": "Annual Laravel conference with workshops",
    "total_tickets": 1000,
    "available_tickets": 847,
    "price": "199.99",
    "event_date": "2024-06-15T10:00:00+00:00",
    "availability_percentage": 84.7
}
```

**Error Response:** `404 Not Found`

```json
{
    "message": "No query results for model [App\\Models\\Event] 999"
}
```

---

### 3. Reserve a Ticket

Reserve a ticket for an event. Reservation is valid for 5 minutes.

**Endpoint:** `POST /events/{eventId}/reserve`

**Authentication:** Required

**Parameters:**
- `eventId` (integer, required) - The ID of the event

**Response:** `201 Created`

```json
{
    "reservation_id": 12345,
    "event_id": 1,
    "status": "reserved",
    "expires_at": "2024-01-15T10:05:00+00:00",
    "time_remaining_seconds": 300,
    "message": "Ticket reserved successfully. Please complete purchase within 5 minutes."
}
```

**Error Responses:**

**No Tickets Available:** `409 Conflict`

```json
{
    "error": "No tickets available",
    "message": "All tickets for this event are currently reserved or sold out."
}
```

**Event Not Found:** `404 Not Found`

```json
{
    "error": "Event not found",
    "message": "Event with ID 999 not found"
}
```

**Unauthenticated:** `401 Unauthorized`

```json
{
    "message": "Unauthenticated."
}
```

---

### 4. Purchase Reserved Ticket

Complete the purchase of a previously reserved ticket.

**Endpoint:** `POST /reservations/{reservationId}/purchase`

**Authentication:** Required

**Parameters:**
- `reservationId` (integer, required) - The ID of the reservation

**Response:** `200 OK`

```json
{
    "reservation_id": 12345,
    "event_id": 1,
    "event_name": "Laravel Conference 2024",
    "status": "purchased",
    "purchased_at": "2024-01-15T10:03:30+00:00",
    "message": "Ticket purchased successfully!"
}
```

**Error Responses:**

**Reservation Expired:** `410 Gone`

```json
{
    "error": "Reservation has expired",
    "message": "Your reservation has expired. Please try reserving again."
}
```

**Reservation Not Found:** `404 Not Found`

```json
{
    "error": "Reservation not found",
    "message": "Reservation with ID 999 not found"
}
```

**Invalid Reservation:** `400 Bad Request`

```json
{
    "error": "Invalid reservation",
    "message": "Reservation already processed with status: purchased"
}
```

---

### 5. Get User's Reservations

Get all reservations for the authenticated user.

**Endpoint:** `GET /reservations`

**Authentication:** Required

**Response:** `200 OK`

```json
{
    "reservations": [
        {
            "id": 12345,
            "event": {
                "id": 1,
                "name": "Laravel Conference 2024",
                "event_date": "2024-06-15T10:00:00+00:00"
            },
            "status": "reserved",
            "expires_at": "2024-01-15T10:05:00+00:00",
            "purchased_at": null,
            "is_active": true,
            "time_remaining_seconds": 180
        },
        {
            "id": 12344,
            "event": {
                "id": 2,
                "name": "PHP Conference 2024",
                "event_date": "2024-05-20T09:00:00+00:00"
            },
            "status": "purchased",
            "expires_at": "2024-01-14T15:05:00+00:00",
            "purchased_at": "2024-01-14T15:02:00+00:00",
            "is_active": false,
            "time_remaining_seconds": 0
        }
    ]
}
```

---

### 6. Cancel Reservation

Cancel a reservation and return the ticket to the available pool.

**Endpoint:** `DELETE /reservations/{reservationId}`

**Authentication:** Required

**Parameters:**
- `reservationId` (integer, required) - The ID of the reservation

**Response:** `200 OK`

```json
{
    "message": "Reservation cancelled successfully."
}
```

**Error Responses:**

**Reservation Not Found:** `404 Not Found`

```json
{
    "error": "Reservation not found",
    "message": "Reservation with ID 999 not found"
}
```

**Invalid Reservation:** `400 Bad Request`

```json
{
    "error": "Invalid reservation",
    "message": "Can only cancel reserved tickets"
}
```

---

### 7. Get Current User

Get information about the authenticated user.

**Endpoint:** `GET /user`

**Authentication:** Required

**Response:** `200 OK`

```json
{
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "created_at": "2024-01-01T00:00:00.000000Z",
    "updated_at": "2024-01-01T00:00:00.000000Z"
}
```

---

## HTTP Status Codes

| Code | Description |
|------|-------------|
| 200 | OK - Request successful |
| 201 | Created - Resource created successfully |
| 400 | Bad Request - Invalid request data |
| 401 | Unauthorized - Authentication required |
| 404 | Not Found - Resource not found |
| 409 | Conflict - Request conflicts with current state (e.g., no tickets available) |
| 410 | Gone - Resource no longer available (e.g., reservation expired) |
| 500 | Internal Server Error - Server error |

---

## Example Workflows

### Complete Ticket Purchase Flow

```bash
# 1. Get event details
curl -X GET http://localhost:8000/api/events/1

# 2. Reserve a ticket (requires authentication)
curl -X POST http://localhost:8000/api/events/1/reserve \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json"

# Response: { "reservation_id": 12345, ... }

# 3. Purchase the ticket within 5 minutes
curl -X POST http://localhost:8000/api/reservations/12345/purchase \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json"
```

### Cancel Reservation Flow

```bash
# 1. Reserve a ticket
curl -X POST http://localhost:8000/api/events/1/reserve \
  -H "Authorization: Bearer YOUR_TOKEN"

# 2. Cancel the reservation
curl -X DELETE http://localhost:8000/api/reservations/12345 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Check User's Reservations

```bash
curl -X GET http://localhost:8000/api/reservations \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## Rate Limiting

The API implements rate limiting to prevent abuse:

- **Authenticated requests:** 60 requests per minute
- **Unauthenticated requests:** 20 requests per minute

When rate limit is exceeded, you'll receive a `429 Too Many Requests` response.

---

## Error Handling

All error responses follow a consistent format:

```json
{
    "error": "Error type",
    "message": "Detailed error message"
}
```

Some Laravel framework errors may use a different format:

```json
{
    "message": "Error message"
}
```

---

## Pagination

List endpoints return paginated results with the following structure:

```json
{
    "current_page": 1,
    "data": [...],
    "first_page_url": "http://localhost:8000/api/events?page=1",
    "from": 1,
    "last_page": 3,
    "last_page_url": "http://localhost:8000/api/events?page=3",
    "next_page_url": "http://localhost:8000/api/events?page=2",
    "path": "http://localhost:8000/api/events",
    "per_page": 20,
    "prev_page_url": null,
    "to": 20,
    "total": 50
}
```

---

## Postman Collection

A Postman collection is available in the repository for easy API testing:

```
postman/Ticket-Reservation-API.postman_collection.json
```

Import this collection into Postman to get started quickly.

