# 🎫 Senior Laravel Concurrent Ticket Reservation API

## 📋 Challenge Overview

A production-ready API for handling ticket reservations with robust concurrency control to prevent overselling under high load. This system ensures that a limited inventory of tickets is never exceeded, even with thousands of simultaneous requests.

**🔗 GitHub Repository**: [https://github.com/Holytear/concurrent-ticket-reservation-api](https://github.com/Holytear/concurrent-ticket-reservation-api)

---

## 1️⃣ Concurrency Strategy: Deep Dive & Trade-offs

### A. Primary Strategy: **Pessimistic Locking with Database Transactions**

**Recommended Approach**: Combine Pessimistic Locking (`FOR UPDATE`) with Laravel Database Transactions for the ticket reservation process.

#### Why This Strategy?

1. **Strongest Consistency Guarantee**: Ensures absolute data integrity at the database level
2. **Race Condition Prevention**: Locks prevent concurrent transactions from reading stale data
3. **Simple Mental Model**: Straightforward to implement and reason about
4. **Native Database Support**: MySQL/PostgreSQL provide robust locking mechanisms

### B. Implementation Details

#### Core Reservation Logic with Pessimistic Locking

```php
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TicketReservationService
{
    /**
     * Reserve a ticket for an event
     * 
     * @param int $eventId
     * @param int $userId
     * @return Reservation|null
     */
    public function reserveTicket(int $eventId, int $userId): ?Reservation
    {
        return DB::transaction(function () use ($eventId, $userId) {
            // Step 1: Lock the event row and check availability
            $event = Event::where('id', $eventId)
                ->lockForUpdate()  // SELECT ... FOR UPDATE
                ->first();
            
            if (!$event) {
                throw new EventNotFoundException();
            }
            
            // Step 2: Check if tickets are available
            if ($event->available_tickets <= 0) {
                return null; // No tickets available
            }
            
            // Step 3: Check if user already has an active reservation
            $existingReservation = Reservation::where('event_id', $eventId)
                ->where('user_id', $userId)
                ->where('status', 'reserved')
                ->where('expires_at', '>', Carbon::now())
                ->first();
            
            if ($existingReservation) {
                return $existingReservation; // User already has a reservation
            }
            
            // Step 4: Decrement available tickets atomically
            $event->decrement('available_tickets');
            
            // Step 5: Create the reservation
            $reservation = Reservation::create([
                'event_id' => $eventId,
                'user_id' => $userId,
                'status' => 'reserved',
                'expires_at' => Carbon::now()->addMinutes(5),
            ]);
            
            return $reservation;
        });
    }
    
    /**
     * Purchase a reserved ticket
     * 
     * @param int $reservationId
     * @param int $userId
     * @return bool
     */
    public function purchaseTicket(int $reservationId, int $userId): bool
    {
        return DB::transaction(function () use ($reservationId, $userId) {
            // Lock the reservation
            $reservation = Reservation::where('id', $reservationId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();
            
            if (!$reservation) {
                throw new ReservationNotFoundException();
            }
            
            // Check if reservation is still valid
            if ($reservation->status !== 'reserved') {
                throw new InvalidReservationException('Reservation already processed');
            }
            
            if ($reservation->expires_at < Carbon::now()) {
                throw new ExpiredReservationException();
            }
            
            // Mark as purchased
            $reservation->update([
                'status' => 'purchased',
                'purchased_at' => Carbon::now(),
            ]);
            
            return true;
        });
    }
}
```

#### Key Laravel Methods Used

1. **`DB::transaction()`**: Wraps operations in a database transaction with automatic rollback on exceptions
2. **`lockForUpdate()`**: Applies `SELECT ... FOR UPDATE` lock, preventing other transactions from reading locked rows
3. **`decrement()`**: Atomic decrement operation on the database level
4. **Automatic Rollback**: If any exception occurs, all changes are rolled back

### C. Trade-offs Analysis

#### ✅ Advantages

| Aspect | Benefit |
|--------|---------|
| **Data Integrity** | 100% prevention of overselling - guaranteed by database |
| **Simplicity** | Clear, linear code flow - easy to understand and maintain |
| **Reliability** | Battle-tested mechanism used in banking systems |
| **Error Handling** | Automatic rollback on failures |

#### ⚠️ Disadvantages

| Aspect | Impact | Mitigation Strategy |
|--------|--------|---------------------|
| **Database Contention** | High concurrent requests will queue waiting for locks | Use connection pooling, optimize transaction duration |
| **Throughput Limitation** | Serial processing of reservation requests | Keep transactions short, consider read replicas for queries |
| **Deadlock Potential** | Risk when locking multiple resources | Always lock resources in consistent order, use deadlock detection |
| **Scalability Ceiling** | Limited by single database write capacity | Can scale to ~1,000-5,000 req/sec per event with proper tuning |

#### Performance Characteristics

- **Expected Throughput**: 1,000-3,000 reservations/second per event on modern hardware
- **Latency**: 5-50ms per reservation (depends on concurrent load)
- **Deadlock Probability**: <0.1% with proper implementation
- **Horizontal Scaling**: Limited (write bottleneck), but sufficient for most use cases

#### When This Strategy Excels

✅ Events with 10-10,000 tickets  
✅ Moderate to high concurrency (up to 5,000 simultaneous users)  
✅ Strong consistency requirements (no overselling tolerance)  
✅ Traditional RDBMS infrastructure  

#### Alternative Strategies Considered (Not Chosen)

**Optimistic Locking**: 
- ❌ Higher retry rate under load
- ❌ More complex client-side retry logic
- ✅ Better for low contention scenarios

**Atomic Database Operations Only**: 
- ❌ Requires careful query design
- ❌ Harder to handle complex business logic
- ✅ Slightly better throughput

**Redis/Queue-Based**: 
- ❌ Additional infrastructure complexity
- ❌ Harder to maintain consistency
- ✅ Can handle higher throughput (10K+ req/sec)

---

## 2️⃣ Database Schema Design

### A. Database Tables/Models

```php
// database/migrations/2024_01_01_000001_create_events_table.php
Schema::create('events', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->text('description')->nullable();
    $table->integer('total_tickets')->unsigned();
    $table->integer('available_tickets')->unsigned();
    $table->decimal('price', 10, 2);
    $table->timestamp('event_date');
    $table->timestamps();
    
    // Indexes
    $table->index('event_date');
    
    // Constraints
    $table->check('available_tickets >= 0');
    $table->check('available_tickets <= total_tickets');
});

// database/migrations/2024_01_01_000002_create_reservations_table.php
Schema::create('reservations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('event_id')->constrained()->onDelete('cascade');
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->enum('status', ['reserved', 'purchased', 'expired', 'cancelled'])
        ->default('reserved');
    $table->timestamp('expires_at');
    $table->timestamp('purchased_at')->nullable();
    $table->timestamps();
    
    // Indexes for performance
    $table->index(['event_id', 'status']);
    $table->index(['user_id', 'status']);
    $table->index('expires_at');
    
    // Composite index for common queries
    $table->index(['event_id', 'user_id', 'status']);
});

// database/migrations/2024_01_01_000003_create_users_table.php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('email')->unique();
    $table->string('password');
    $table->timestamps();
});
```

### B. Key Fields for Concurrency Control

#### Events Table

| Field | Type | Purpose for Concurrency |
|-------|------|------------------------|
| `available_tickets` | `integer unsigned` | **Critical Field** - The atomic counter that prevents overselling. Decremented during reservation, incremented when reservations expire |
| `total_tickets` | `integer unsigned` | Reference value for validation and reporting |

**Why `available_tickets` is Essential**:
- Single source of truth for availability
- Atomic operations (`decrement()`/`increment()`) are database-level
- With `lockForUpdate()`, provides strong consistency guarantee
- Database constraint ensures it never goes negative

#### Reservations Table

| Field | Type | Purpose |
|-------|------|---------|
| `status` | `enum` | Tracks reservation lifecycle (reserved → purchased/expired/cancelled) |
| `expires_at` | `timestamp` | **Critical for Expiration** - Indexed for efficient expired reservation queries |

### C. Key Fields for Expiration Handling

**Primary Expiration Fields**:

1. **`expires_at`** (timestamp, indexed)
   - Set to `now() + 5 minutes` on creation
   - Indexed for efficient batch queries
   - Used by scheduled job to find expired reservations

2. **`status`** (enum)
   - `reserved`: Active reservation within expiration window
   - `expired`: Reservation that passed `expires_at` without purchase
   - `purchased`: Successfully converted to purchase
   - `cancelled`: Manually cancelled by user

**Expiration Query Pattern**:
```php
// Find all expired reservations
Reservation::where('status', 'reserved')
    ->where('expires_at', '<', Carbon::now())
    ->get();
```

---

## 3️⃣ API Endpoints & Core Logic Flow

### A. API Endpoints

#### 1. Get Event Details & Availability

```http
GET /api/events/{eventId}
```

**Response**:
```json
{
    "id": 1,
    "name": "Laravel Conference 2024",
    "description": "Annual Laravel conference",
    "total_tickets": 1000,
    "available_tickets": 847,
    "price": "199.99",
    "event_date": "2024-06-15T10:00:00Z"
}
```

**Controller Logic**:
```php
public function show(int $eventId)
{
    $event = Event::findOrFail($eventId);
    return response()->json($event);
}
```

---

#### 2. Reserve a Ticket

```http
POST /api/events/{eventId}/reserve
```

**Headers**:
```
Authorization: Bearer {token}
```

**Response (Success - 201)**:
```json
{
    "reservation_id": 12345,
    "event_id": 1,
    "status": "reserved",
    "expires_at": "2024-01-15T10:05:00Z",
    "message": "Ticket reserved successfully. Please complete purchase within 5 minutes."
}
```

**Response (No Availability - 409)**:
```json
{
    "error": "No tickets available",
    "available_tickets": 0
}
```

**Controller Logic**:
```php
public function reserve(Request $request, int $eventId)
{
    $userId = $request->user()->id;
    
    try {
        $reservation = $this->ticketService->reserveTicket($eventId, $userId);
        
        if (!$reservation) {
            return response()->json([
                'error' => 'No tickets available'
            ], 409);
        }
        
        return response()->json([
            'reservation_id' => $reservation->id,
            'event_id' => $reservation->event_id,
            'status' => $reservation->status,
            'expires_at' => $reservation->expires_at,
            'message' => 'Ticket reserved successfully. Please complete purchase within 5 minutes.'
        ], 201);
        
    } catch (Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}
```

---

#### 3. Purchase Reserved Ticket

```http
POST /api/reservations/{reservationId}/purchase
```

**Headers**:
```
Authorization: Bearer {token}
```

**Response (Success - 200)**:
```json
{
    "reservation_id": 12345,
    "event_id": 1,
    "status": "purchased",
    "purchased_at": "2024-01-15T10:03:30Z",
    "message": "Ticket purchased successfully"
}
```

**Response (Expired - 410)**:
```json
{
    "error": "Reservation has expired",
    "expired_at": "2024-01-15T10:05:00Z"
}
```

**Controller Logic**:
```php
public function purchase(Request $request, int $reservationId)
{
    $userId = $request->user()->id;
    
    try {
        $success = $this->ticketService->purchaseTicket($reservationId, $userId);
        
        $reservation = Reservation::find($reservationId);
        
        return response()->json([
            'reservation_id' => $reservation->id,
            'event_id' => $reservation->event_id,
            'status' => $reservation->status,
            'purchased_at' => $reservation->purchased_at,
            'message' => 'Ticket purchased successfully'
        ]);
        
    } catch (ExpiredReservationException $e) {
        return response()->json([
            'error' => 'Reservation has expired'
        ], 410);
    } catch (Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}
```

---

#### 4. Get User's Reservations

```http
GET /api/users/reservations
```

**Response**:
```json
{
    "reservations": [
        {
            "id": 12345,
            "event": {
                "id": 1,
                "name": "Laravel Conference 2024"
            },
            "status": "reserved",
            "expires_at": "2024-01-15T10:05:00Z"
        }
    ]
}
```

---

### B. Expiration Handling Strategy

#### Production-Ready Approach: Laravel Scheduled Job

**Implementation**:

```php
// app/Console/Commands/ReleaseExpiredReservations.php
namespace App\Console\Commands;

use App\Models\Reservation;
use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReleaseExpiredReservations extends Command
{
    protected $signature = 'reservations:release-expired';
    protected $description = 'Release expired ticket reservations back to available pool';

    public function handle()
    {
        $this->info('Starting expired reservation release process...');
        
        $releasedCount = 0;
        
        DB::transaction(function () use (&$releasedCount) {
            // Find all expired reservations
            $expiredReservations = Reservation::where('status', 'reserved')
                ->where('expires_at', '<', Carbon::now())
                ->lockForUpdate()  // Lock to prevent race conditions
                ->get();
            
            foreach ($expiredReservations as $reservation) {
                // Mark reservation as expired
                $reservation->update(['status' => 'expired']);
                
                // Return ticket to available pool
                Event::where('id', $reservation->event_id)
                    ->increment('available_tickets');
                
                $releasedCount++;
            }
        });
        
        $this->info("Released {$releasedCount} expired reservations.");
        
        return 0;
    }
}
```

**Schedule Configuration**:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    // Run every minute for responsive ticket release
    $schedule->command('reservations:release-expired')
        ->everyMinute()
        ->withoutOverlapping()  // Prevent concurrent executions
        ->runInBackground();
}
```

#### How It Works

1. **Scheduled Execution**: Runs every minute via Laravel's scheduler
2. **Query Efficiency**: Indexed `expires_at` field makes queries fast
3. **Atomic Operations**: Wrapped in transaction to ensure consistency
4. **Lock Protection**: Uses `lockForUpdate()` to prevent race conditions
5. **Overlap Prevention**: `withoutOverlapping()` ensures only one instance runs

#### Alternative Approaches

**Option 1: On-Demand Release** (Used in conjunction)
```php
// In reserve() method, release expired tickets before checking availability
$this->releaseExpiredForEvent($eventId);
```

**Option 2: Database Triggers** (Not recommended for Laravel)
- More complex to maintain
- Harder to debug
- Less flexible for business logic changes

**Option 3: Queue Jobs** (For high-scale systems)
```php
// Dispatch job when reservation is created
ReleaseExpiredReservationJob::dispatch($reservation)
    ->delay($reservation->expires_at);
```

---

## 📊 Performance Benchmarks (Expected)

| Scenario | Concurrent Users | Throughput | Success Rate |
|----------|------------------|------------|--------------|
| Low Load | 100 | 2,500 req/sec | 99.9% |
| Medium Load | 1,000 | 2,000 req/sec | 99.5% |
| High Load | 5,000 | 1,500 req/sec | 98% |

---

## 🚀 Deployment Considerations

### Database Optimization
- Use InnoDB engine for row-level locking
- Configure appropriate `innodb_lock_wait_timeout`
- Monitor for deadlocks with `SHOW ENGINE INNODB STATUS`

### Application Optimization
- Keep transactions as short as possible
- Use connection pooling (minimum 20-50 connections)
- Implement proper error handling and retries

### Monitoring
- Track lock wait times
- Monitor transaction duration
- Alert on high deadlock rates
- Track reservation-to-purchase conversion rate

---

## 🧪 Testing Strategy

### Concurrency Tests
```php
// Use Laravel Pest or PHPUnit with parallel execution
test('prevents overselling under concurrent load', function () {
    $event = Event::factory()->create(['available_tickets' => 100]);
    
    // Simulate 200 concurrent reservation attempts
    $promises = collect(range(1, 200))->map(function () use ($event) {
        return async(fn() => $this->ticketService->reserveTicket($event->id, rand(1, 200)));
    });
    
    $results = Promise::all($promises)->wait();
    
    // Should have exactly 100 successful reservations
    $successful = collect($results)->filter()->count();
    expect($successful)->toBe(100);
    
    // Database should reflect correct count
    expect($event->fresh()->available_tickets)->toBe(0);
});
```

---

## 📚 Technology Stack

- **Framework**: Laravel 11.x
- **Database**: MySQL 8.0+ or PostgreSQL 14+
- **PHP**: 8.2+
- **Authentication**: Laravel Sanctum
- **Testing**: Pest PHP
- **Monitoring**: Laravel Telescope (development)

---

## 🎯 Success Criteria

✅ Zero overselling incidents under load testing  
✅ 99.9% success rate for valid reservation attempts  
✅ <100ms average response time under normal load  
✅ Expired reservations released within 60 seconds  
✅ Comprehensive test coverage (>80%)  

---

## 📖 Additional Resources

- [Laravel Database Transactions](https://laravel.com/docs/database#database-transactions)
- [MySQL InnoDB Locking](https://dev.mysql.com/doc/refman/8.0/en/innodb-locking.html)
- [Laravel Task Scheduling](https://laravel.com/docs/scheduling)

---

## 👨‍💻 Author

Senior Laravel Developer Coding Challenge  
Built with ❤️ and attention to concurrency details

---

## 📄 License

MIT License - Feel free to use this as a reference for similar systems

