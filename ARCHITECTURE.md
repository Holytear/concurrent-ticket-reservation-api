# Architecture Document

## System Overview

This document describes the architecture of the Concurrent Ticket Reservation API, a Laravel-based system designed to handle high-concurrency ticket reservations while preventing overselling.

## Core Design Principles

1. **Consistency over Availability**: Prioritize data integrity (no overselling) over high availability
2. **Database-Centric Locking**: Leverage RDBMS ACID properties for consistency
3. **Separation of Concerns**: Clear separation between Controllers, Services, and Models
4. **Test-Driven Design**: Comprehensive test coverage for critical paths

---

## Architecture Layers

```
┌─────────────────────────────────────────────────┐
│            API Layer (Routes)                    │
│  - Authentication (Sanctum)                      │
│  - Rate Limiting                                 │
└─────────────────┬───────────────────────────────┘
                  │
┌─────────────────▼───────────────────────────────┐
│         Controller Layer                         │
│  - EventController                               │
│  - ReservationController                         │
│  - Request Validation                            │
│  - Response Formatting                           │
└─────────────────┬───────────────────────────────┘
                  │
┌─────────────────▼───────────────────────────────┐
│         Service Layer                            │
│  - TicketReservationService                      │
│  - Business Logic                                │
│  - Transaction Management                        │
│  - Concurrency Control                           │
└─────────────────┬───────────────────────────────┘
                  │
┌─────────────────▼───────────────────────────────┐
│         Model Layer (Eloquent ORM)               │
│  - Event Model                                   │
│  - Reservation Model                             │
│  - User Model                                    │
└─────────────────┬───────────────────────────────┘
                  │
┌─────────────────▼───────────────────────────────┐
│         Database Layer (MySQL/PostgreSQL)        │
│  - InnoDB Storage Engine                         │
│  - Row-Level Locking                             │
│  - ACID Transactions                             │
└─────────────────────────────────────────────────┘
```

---

## Concurrency Control Strategy

### Pessimistic Locking with Database Transactions

The system uses **Pessimistic Locking** as the primary concurrency control mechanism:

```
Reserve Ticket Flow:
┌───────────────────────────────────────────────────┐
│ 1. BEGIN TRANSACTION                              │
├───────────────────────────────────────────────────┤
│ 2. SELECT * FROM events WHERE id = ?              │
│    FOR UPDATE;  ← LOCK ACQUIRED                   │
├───────────────────────────────────────────────────┤
│ 3. CHECK: available_tickets > 0?                  │
│    - NO → ROLLBACK, return null                   │
│    - YES → Continue                               │
├───────────────────────────────────────────────────┤
│ 4. UPDATE events                                  │
│    SET available_tickets = available_tickets - 1  │
├───────────────────────────────────────────────────┤
│ 5. INSERT INTO reservations (...)                │
├───────────────────────────────────────────────────┤
│ 6. COMMIT TRANSACTION ← LOCK RELEASED             │
└───────────────────────────────────────────────────┘
```

### Key Implementation Details

**Laravel Methods Used:**

```php
DB::transaction(function () {
    $event = Event::lockForUpdate()->find($id);  // SELECT ... FOR UPDATE
    $event->decrement('available_tickets');       // Atomic UPDATE
    Reservation::create([...]);                   // INSERT
});
```

**SQL Generated:**

```sql
START TRANSACTION;

SELECT * FROM events WHERE id = 1 FOR UPDATE;

UPDATE events 
SET available_tickets = available_tickets - 1 
WHERE id = 1;

INSERT INTO reservations (...) VALUES (...);

COMMIT;
```

### Why This Strategy?

| Aspect | Pessimistic Locking | Optimistic Locking | Atomic Operations |
|--------|---------------------|-------------------|-------------------|
| **Consistency** | ✅ Guaranteed | ✅ Guaranteed | ✅ Guaranteed |
| **Contention Handling** | ✅ Queue requests | ❌ Retry/fail | ⚠️ Limited |
| **Deadlock Risk** | ⚠️ Low to Medium | ❌ None | ❌ None |
| **Code Complexity** | ✅ Simple | ⚠️ Requires retry | ✅ Simple |
| **Throughput** | ⚠️ Medium | ✅ High | ⚠️ Medium |
| **Best For** | ✅ This use case | Low contention | Simple counters |

---

## Data Model

### Entity Relationship Diagram

```
┌─────────────────┐
│     Events      │
├─────────────────┤
│ id (PK)         │
│ name            │
│ description     │
│ total_tickets   │
│ available_tickets│ ← CRITICAL FIELD
│ price           │
│ event_date      │
└────────┬────────┘
         │
         │ 1:N
         │
         ▼
┌─────────────────┐         ┌─────────────────┐
│  Reservations   │    N:1  │     Users       │
├─────────────────┤◄────────┤─────────────────┤
│ id (PK)         │         │ id (PK)         │
│ event_id (FK)   │         │ name            │
│ user_id (FK)    │─────────┤ email           │
│ status          │         │ password        │
│ expires_at      │         └─────────────────┘
│ purchased_at    │
└─────────────────┘
```

### Database Indexes

**Critical Indexes for Performance:**

```sql
-- Events table
CREATE INDEX idx_event_date ON events(event_date);

-- Reservations table
CREATE INDEX idx_event_status ON reservations(event_id, status);
CREATE INDEX idx_user_status ON reservations(user_id, status);
CREATE INDEX idx_expires_at ON reservations(expires_at);  -- CRITICAL for cleanup job
CREATE INDEX idx_event_user_status ON reservations(event_id, user_id, status);
```

### Database Constraints

```sql
-- Prevent negative tickets
ALTER TABLE events ADD CONSTRAINT chk_available_tickets_positive 
CHECK (available_tickets >= 0);

-- Prevent overselling
ALTER TABLE events ADD CONSTRAINT chk_available_tickets_max 
CHECK (available_tickets <= total_tickets);
```

---

## Reservation Lifecycle

```
┌─────────┐
│ REQUEST │
└────┬────┘
     │
     ▼
┌─────────────┐
│  RESERVED   │ ← expires_at = now() + 5 minutes
└──┬────┬─────┘
   │    │
   │    └──────────────┐
   │                   │ (5 minutes pass)
   │                   ▼
   │            ┌──────────────┐
   │            │   EXPIRED    │
   │            └──────────────┘
   │
   ├────────────┐
   │            │
   ▼            ▼
┌──────────┐ ┌──────────┐
│PURCHASED │ │CANCELLED │
└──────────┘ └──────────┘
```

### State Transitions

| From | To | Trigger | Action |
|------|-----|---------|--------|
| - | RESERVED | User reserves | Decrement available_tickets |
| RESERVED | PURCHASED | User purchases | Mark as purchased |
| RESERVED | CANCELLED | User cancels | Increment available_tickets |
| RESERVED | EXPIRED | Scheduled job | Increment available_tickets |

---

## Background Job: Expired Reservation Cleanup

### Scheduled Execution

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('reservations:release-expired')
        ->everyMinute()
        ->withoutOverlapping()
        ->runInBackground();
}
```

### Job Logic

```
Every Minute:
┌───────────────────────────────────────────────────┐
│ 1. Query expired reservations:                    │
│    SELECT * FROM reservations                     │
│    WHERE status = 'reserved'                      │
│    AND expires_at < NOW()                         │
│    FOR UPDATE;                                    │
├───────────────────────────────────────────────────┤
│ 2. For each reservation:                          │
│    - UPDATE reservations SET status = 'expired'   │
│    - UPDATE events                                │
│      SET available_tickets += 1                   │
├───────────────────────────────────────────────────┤
│ 3. COMMIT                                         │
└───────────────────────────────────────────────────┘
```

### Why Every Minute?

- **Balance**: Between responsiveness and database load
- **Responsiveness**: Max 1-minute delay for ticket availability
- **Performance**: Minimal overhead with proper indexing
- **Alternative**: Could be triggered on-demand before reservations

---

## Security Considerations

### Authentication

- **Laravel Sanctum**: Token-based API authentication
- **Token Scope**: Could be extended for fine-grained permissions

### Authorization

```php
// Ensure users can only purchase their own reservations
$reservation = Reservation::where('id', $reservationId)
    ->where('user_id', $userId)
    ->lockForUpdate()
    ->first();
```

### Rate Limiting

```php
// config/sanctum.php
'middleware' => [
    'throttle:api',  // 60 requests per minute
],
```

### SQL Injection Prevention

- **Eloquent ORM**: Parameterized queries by default
- **No Raw SQL**: Unless absolutely necessary

---

## Scalability Analysis

### Vertical Scaling Limits

| Tickets | Concurrent Users | Expected Throughput | Database Load |
|---------|------------------|---------------------|---------------|
| 100 | 500 | 2,000 req/sec | Low |
| 1,000 | 5,000 | 1,500 req/sec | Medium |
| 10,000 | 10,000 | 1,000 req/sec | High |

### Bottlenecks

1. **Database Write Lock**: Single point of serialization
2. **Transaction Duration**: Must be kept minimal
3. **Connection Pool**: Limited concurrent connections

### Horizontal Scaling

**What Scales:**
- ✅ Read operations (event listings)
- ✅ Application servers
- ✅ User authentication

**What Doesn't Scale:**
- ❌ Write operations to same event (single row lock)
- ❌ Transaction processing (serialized)

### Optimization Strategies

1. **Connection Pooling**: 50-100 connections
2. **Read Replicas**: For event listings
3. **Caching**: Event details (with short TTL)
4. **Queue Workers**: For non-critical operations
5. **Database Tuning**: `innodb_lock_wait_timeout`, buffer pool size

---

## Monitoring & Observability

### Key Metrics

```php
// Log in service layer
Log::info('Reservation attempt', [
    'event_id' => $eventId,
    'user_id' => $userId,
    'available_tickets' => $event->available_tickets,
    'duration_ms' => $duration,
]);
```

### Critical Metrics to Track

1. **Lock Wait Time**: Average time waiting for locks
2. **Transaction Duration**: p50, p95, p99 latencies
3. **Deadlock Rate**: Should be < 0.1%
4. **Reservation Success Rate**: Should be > 95%
5. **Expired Reservation Rate**: Indicates user experience

### Alerting Thresholds

- Lock wait time > 500ms
- Deadlock rate > 1%
- Transaction duration p95 > 200ms

---

## Testing Strategy

### Unit Tests

```php
test('prevents overselling under concurrent load', function () {
    // Test with factories and database transactions
});
```

### Integration Tests

```php
test('API reserves ticket correctly', function () {
    // Test full HTTP request/response cycle
});
```

### Load Tests

```bash
# Apache Bench
ab -n 10000 -c 100 -H "Authorization: Bearer TOKEN" \
   http://localhost/api/events/1/reserve
```

### Concurrency Tests

Simulate 100+ concurrent requests to same event with limited tickets.

---

## Deployment Considerations

### Database Configuration

```ini
# MySQL
innodb_buffer_pool_size = 2G
innodb_log_file_size = 512M
innodb_flush_log_at_trx_commit = 1
innodb_lock_wait_timeout = 50
max_connections = 200
```

### Application Configuration

```env
DB_CONNECTION=mysql
DB_POOL_MIN=10
DB_POOL_MAX=50
QUEUE_CONNECTION=redis
CACHE_DRIVER=redis
```

### High Availability

1. **Database Replication**: Master-slave for reads
2. **Load Balancer**: Multiple application servers
3. **Health Checks**: Monitor application and database
4. **Backup Strategy**: Regular database backups

---

## Future Enhancements

### Potential Improvements

1. **Waiting List**: Queue users when sold out
2. **Seat Selection**: Reserve specific seats
3. **Payment Integration**: Stripe, PayPal
4. **Email Notifications**: Reservation confirmations
5. **Analytics Dashboard**: Real-time ticket sales
6. **Event Categories**: Filter and search
7. **Multiple Ticket Types**: VIP, General, etc.

### Scaling Beyond Pessimistic Locking

For extreme scale (100K+ concurrent users):

1. **Event Sourcing**: Append-only event log
2. **CQRS**: Separate read/write models
3. **Redis Queue**: Serialize reservations in queue
4. **Sharding**: Partition events across databases

---

## Conclusion

This architecture provides a robust, production-ready solution for concurrent ticket reservations with strong consistency guarantees. The pessimistic locking strategy ensures zero overselling while maintaining reasonable throughput for most use cases.

The system can handle thousands of concurrent users per event and can be optimized further based on specific requirements and traffic patterns.

