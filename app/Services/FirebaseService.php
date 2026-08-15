<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Illuminate\Support\Facades\Log;

class FirebaseService
{
    protected $messaging;

    public function __construct()
    {
        try {
            $credentialsPath = config('firebase.credentials');
            $credentialsJson = config('firebase.credentials_json');

            // Prefer a service-account file when it exists and is readable.
            if (is_string($credentialsPath)
                && $credentialsPath !== ''
                && is_file($credentialsPath)
                && is_readable($credentialsPath)) {
                $factory = (new Factory)->withServiceAccount($credentialsPath);
            }
            // Fall back to a JSON service-account value when configured.
            elseif (is_string($credentialsJson) && trim($credentialsJson) !== '') {
                $credentials = json_decode($credentialsJson, true);

                if (! is_array($credentials)) {
                    throw new \InvalidArgumentException('FIREBASE_CREDENTIALS_JSON must contain valid JSON.');
                }

                $factory = (new Factory)->withServiceAccount($credentials);
            } else {
                Log::warning('Firebase credentials not configured');
                $this->messaging = null;
                return;
            }

            $this->messaging = $factory->createMessaging();
        } catch (\Exception $e) {
            Log::error('Firebase initialization failed: ' . $e->getMessage());
            $this->messaging = null;
        }
    }

    /**
     * Send notification to a single device
     */
    public function sendToDevice($deviceToken, $title, $body, $data = [])
    {
        if (!$this->messaging) {
            Log::warning('Firebase messaging not initialized');
            return false;
        }

        try {
            $notification = Notification::create($title, $body);

            $message = CloudMessage::withTarget('token', $deviceToken)
                ->withNotification($notification)
                ->withData($data);

            // Add platform-specific configs
            $message = $message
                ->withAndroidConfig($this->getAndroidConfig())
                ->withApnsConfig($this->getApnsConfig());

            $result = $this->messaging->send($message);

            return [
                'success' => true,
                'message_id' => $result,
            ];
        } catch (\Exception $e) {
            Log::error('FCM send failed: ' . $e->getMessage(), [
                'device_token' => $deviceToken,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send notification to multiple devices
     */
    public function sendToMultipleDevices(array $deviceTokens, $title, $body, $data = [])
    {
        if (!$this->messaging || empty($deviceTokens)) {
            return [
                'success' => false,
                'error' => 'No device tokens or messaging not initialized'
            ];
        }

        try {
            $notification = Notification::create($title, $body);

            $message = CloudMessage::new()
                ->withNotification($notification)
                ->withData($data)
                ->withAndroidConfig($this->getAndroidConfig())
                ->withApnsConfig($this->getApnsConfig());

            $result = $this->messaging->sendMulticast($message, $deviceTokens);

            return [
                'success' => true,
                'success_count' => $result->successes()->count(),
                'failure_count' => $result->failures()->count(),
                'results' => $result,
            ];
        } catch (\Exception $e) {
            Log::error('FCM multicast send failed: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send data-only message (silent notification)
     */
    public function sendDataMessage($deviceToken, $data = [])
    {
        if (!$this->messaging) {
            return false;
        }

        try {
            $message = CloudMessage::withTarget('token', $deviceToken)
                ->withData($data);

            $result = $this->messaging->send($message);

            return [
                'success' => true,
                'message_id' => $result,
            ];
        } catch (\Exception $e) {
            Log::error('FCM data message send failed: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Android-specific configuration
     */
    private function getAndroidConfig()
    {
        return AndroidConfig::fromArray([
            'priority' => 'high',
            'notification' => [
                'sound' => 'default',
                'channel_id' => 'chat_messages',
            ],
        ]);
    }

    /**
     * iOS-specific configuration
     */
    private function getApnsConfig()
    {
        return ApnsConfig::fromArray([
            'headers' => [
                'apns-priority' => '10',
            ],
            'payload' => [
                'aps' => [
                    'sound' => 'default',
                    'badge' => 1,
                ],
            ],
        ]);
    }

    /**
     * Validate device token format
     */
    public function validateToken($token)
    {
        // Basic validation: FCM tokens are usually 152-163 characters
        return !empty($token) && strlen($token) > 100 && strlen($token) < 200;
    }
}
