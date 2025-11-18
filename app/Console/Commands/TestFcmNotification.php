<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\FirebaseService;


class TestFcmNotification extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fcm:test {token}';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test FCM notification sending';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $token = $this->argument('token');
        
        $firebaseService = new FirebaseService();
        
        $this->info('Sending test notification...');
        
        $result = $firebaseService->sendToDevice(
            $token,
            'Test from Laravel',
            'This is a test notification',
            ['type' => 'test', 'timestamp' => now()->toIso8601String()]
        );
        
        if ($result['success']) {
            $this->info('✅ Notification sent successfully!');
            $this->info('Message ID: ' . $result['message_id']);
        } else {
            $this->error('❌ Failed to send notification');
            $this->error('Error: ' . $result['error']);
        }
    }
}
