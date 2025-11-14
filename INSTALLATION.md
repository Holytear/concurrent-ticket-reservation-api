# Installation Guide

## Prerequisites

- PHP 8.2 or higher
- Composer
- MySQL 8.0+ or PostgreSQL 14+
- Git

## Installation Steps

### 1. Clone the Repository

```bash
git clone <repository-url>
cd concurrent-ticket-reservation-api
```

### 2. Install Dependencies

```bash
composer install
```

### 3. Environment Configuration

```bash
cp .env.example .env
```

Edit `.env` file and configure your database:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ticket_reservation
DB_USERNAME=root
DB_PASSWORD=your_password
```

### 4. Generate Application Key

```bash
php artisan key:generate
```

### 5. Run Migrations

```bash
php artisan migrate
```

### 6. (Optional) Seed Database

Create a seeder or manually insert test data:

```bash
php artisan db:seed
```

### 7. Configure Task Scheduler

The system uses Laravel's task scheduler to release expired reservations.

**For Production (Linux/Mac):**

Add to crontab:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

**For Development:**

Run scheduler manually:

```bash
php artisan schedule:work
```

Or run the command directly:

```bash
php artisan reservations:release-expired
```

### 8. Start the Development Server

```bash
php artisan serve
```

The API will be available at `http://localhost:8000`

## Testing

### Run All Tests

```bash
php artisan test
```

### Run Specific Test Suite

```bash
php artisan test --filter TicketReservationTest
```

### Run with Coverage

```bash
php artisan test --coverage
```

## API Authentication

This API uses Laravel Sanctum for authentication.

### Create a User

```bash
php artisan tinker
```

```php
$user = \App\Models\User::factory()->create([
    'email' => 'test@example.com',
    'password' => bcrypt('password')
]);

// Generate a token
$token = $user->createToken('api-token')->plainTextToken;
echo $token;
```

### Use the Token

Include in request headers:

```
Authorization: Bearer {your-token-here}
```

## Database Optimization

### For MySQL

Ensure InnoDB is configured properly in `my.cnf`:

```ini
[mysqld]
innodb_lock_wait_timeout = 50
innodb_buffer_pool_size = 256M
innodb_log_file_size = 64M
```

### Indexes

All necessary indexes are created by migrations. Verify with:

```sql
SHOW INDEXES FROM events;
SHOW INDEXES FROM reservations;
```

## Performance Testing

### Load Testing with Apache Bench

```bash
# Test event endpoint
ab -n 1000 -c 100 http://localhost:8000/api/events/1

# Test reservation endpoint (with auth token)
ab -n 1000 -c 100 -H "Authorization: Bearer YOUR_TOKEN" -p reserve.json -T application/json http://localhost:8000/api/events/1/reserve
```

### Concurrent Reservation Test

Create a simple PHP script to simulate concurrent reservations:

```php
<?php
// concurrent_test.php

$eventId = 1;
$token = 'YOUR_AUTH_TOKEN';
$baseUrl = 'http://localhost:8000/api';

$processes = [];
for ($i = 0; $i < 50; $i++) {
    $cmd = sprintf(
        'curl -X POST %s/events/%d/reserve -H "Authorization: Bearer %s" -H "Content-Type: application/json"',
        $baseUrl,
        $eventId,
        $token
    );
    
    $processes[] = popen($cmd . ' &', 'r');
}

foreach ($processes as $process) {
    pclose($process);
}
```

## Troubleshooting

### Deadlock Errors

If you encounter deadlock errors:

1. Check `innodb_lock_wait_timeout` setting
2. Review transaction duration - keep them short
3. Check database logs: `SHOW ENGINE INNODB STATUS`

### Slow Performance

1. Verify indexes are created: `SHOW INDEXES FROM reservations`
2. Check database connection pool size
3. Monitor long-running queries
4. Consider read replicas for read-heavy workloads

### Scheduler Not Running

Verify cron is configured:

```bash
# Check cron logs
grep CRON /var/log/syslog

# Test manually
php artisan schedule:run
```

## Production Deployment

### Optimization Commands

```bash
# Cache configuration
php artisan config:cache

# Cache routes
php artisan route:cache

# Cache views
php artisan view:cache

# Optimize autoloader
composer install --optimize-autoloader --no-dev
```

### Queue Workers (Optional Enhancement)

For higher concurrency, consider using queue workers:

```bash
php artisan queue:work --tries=3
```

### Monitoring

Install Laravel Telescope for development:

```bash
composer require laravel/telescope --dev
php artisan telescope:install
php artisan migrate
```

Access at: `http://localhost:8000/telescope`

## Support

For issues or questions, please open an issue on the GitHub repository.

