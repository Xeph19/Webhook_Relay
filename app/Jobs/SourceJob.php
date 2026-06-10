<?php
 
namespace App\Jobs;
 
use App\Models\Source;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;
 
class SourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
 
    public int $tries = 3;
 
    public int $timeout = 60;
 
    public bool $deleteWhenMissingModels = true;
 
    /**
     * Create a new job instance.
     */
    public function __construct(public Source $source) {}
 
    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("Processing background tasks for source: {$this->source->name}");
        if ($this->source->is_active) {
            $this->performActivationSetup();
        } else {
            $this->performDeactivationCleanup();
        }
        Log::info("Background tasks for source: {$this->source->name} completed");
    }
 
    public function failed(Throwable $exception)
    {
        Log::error("Source failed for source: {$this->source->id}: ".$exception->getMessage());
    }
 
    protected function performActivationSetup(): void
    {
        Log::info("Activation setup for source: {$this->source->name}");
    }
 
    protected function performDeactivationCleanup(): void
    {
        Log::info("Deactivation cleanup for source: {$this->source->name}");
    }
}
