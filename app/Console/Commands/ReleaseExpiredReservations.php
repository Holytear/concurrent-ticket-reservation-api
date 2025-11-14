<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReleaseExpiredReservations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reservations:release-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Release expired ticket reservations back to available pool';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting expired reservation release process...');
        
        $startTime = microtime(true);
        $releasedCount = 0;
        
        try {
            DB::transaction(function () use (&$releasedCount) {
                // Find all expired reservations with pessimistic locking
                $expiredReservations = Reservation::where('status', Reservation::STATUS_RESERVED)
                    ->where('expires_at', '<', Carbon::now())
                    ->lockForUpdate()  // Lock to prevent race conditions
                    ->get();
                
                foreach ($expiredReservations as $reservation) {
                    // Mark reservation as expired
                    $reservation->update(['status' => Reservation::STATUS_EXPIRED]);
                    
                    // Return ticket to available pool
                    Event::where('id', $reservation->event_id)
                        ->increment('available_tickets');
                    
                    $releasedCount++;
                }
            });
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->info("✓ Successfully released {$releasedCount} expired reservations in {$duration}ms");
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("✗ Error releasing reservations: {$e->getMessage()}");
            return 1;
        }
    }
}

