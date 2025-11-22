<?php

use App\Http\Middleware\AuthenticateBearerToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

/*Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});*/
Route::get('/translations/{locale}', function ($locale) {
    if (Str::startsWith($locale, 'php_')) {
        return response()->json(['error' => 'Invalid locale'], 400);
    }

    $path = base_path("lang/{$locale}.json");
    
    if (!File::exists($path)) {
        return response()->json(['error' => 'Translation file not found'], 404);
    }

    return response()->json(json_decode(File::get($path), true));
});

Route::middleware([AuthenticateBearerToken::class])->group(function () {
    Route::post('/send', [App\Http\Controllers\ApiController::class, 'sendMessage']);
    Route::post('/send/template', [App\Http\Controllers\ApiController::class, 'sendTemplateMessage']);
    Route::post('/send/media', [App\Http\Controllers\ApiController::class, 'sendMediaMessage']);
    Route::post('/campaigns', [App\Http\Controllers\ApiController::class, 'storeCampaign']);
    
    Route::get('/contacts', [App\Http\Controllers\ApiController::class, 'listContacts']);
    Route::post('/contacts', [App\Http\Controllers\ApiController::class, 'storeContact']);
    Route::put('/contacts/{uuid}', [App\Http\Controllers\ApiController::class, 'storeContact']);
    Route::delete('/contacts/{uuid}', [App\Http\Controllers\ApiController::class, 'destroyContact']);

    Route::get('/contact-groups', [App\Http\Controllers\ApiController::class, 'listContactGroups']);
    Route::post('/contact-groups', [App\Http\Controllers\ApiController::class, 'storeContactGroup']);
    Route::put('/contact-groups/{uuid}', [App\Http\Controllers\ApiController::class, 'storeContactGroup']);
    Route::delete('/contact-groups/{uuid}', [App\Http\Controllers\ApiController::class, 'destroyContactGroup']);

    Route::get('/canned-replies', [App\Http\Controllers\ApiController::class, 'listCannedReplies']);
    Route::post('/canned-replies', [App\Http\Controllers\ApiController::class, 'storeCannedReply']);
    Route::put('/canned-replies/{uuid}', [App\Http\Controllers\ApiController::class, 'storeCannedReply']);
    Route::delete('/canned-replies/{uuid}', [App\Http\Controllers\ApiController::class, 'destroyCannedReply']);

    Route::get('/templates', [App\Http\Controllers\ApiController::class, 'listTemplates']);
    Route::get('/verify', [App\Http\Controllers\ApiController::class, 'verifyApiKey']);
});


// ============================================
// Mobile API Routes (v1)
// ============================================
Route::prefix('v1')->group(function () {
    
    // Public routes (No authentication)
    Route::post('/auth/login', [App\Http\Controllers\Api\AuthController::class, 'login']);
    
    // Protected routes (Sanctum authentication for mobile)
    Route::middleware('sanctum.auth')->group(function () {
        Route::get('/auth/me', [App\Http\Controllers\Api\AuthController::class, 'me']);
        Route::post('/auth/logout', [App\Http\Controllers\Api\AuthController::class, 'logout']);
        
        // Get organizations list
        Route::get('/auth/organizations', [App\Http\Controllers\Api\AuthController::class, 'organizations']);


        // Organization-specific endpoints (requires X-Organization-UUID header)
        Route::middleware('check.organization_selected')->group(function () {
            
            // Labels
            Route::get('/labels', [App\Http\Controllers\Api\LabelController::class, 'index']);
            

            // Chats
            Route::get('/chats', [App\Http\Controllers\Api\ChatController::class, 'index']);
            Route::get('/chats/unread-count', [App\Http\Controllers\Api\ChatController::class, 'unreadCount']);
            Route::get('/chats/{contact_uuid}/messages', [App\Http\Controllers\Api\ChatController::class, 'getMessages']);
            Route::post('/chats/{contact_uuid}/mark-as-read', [App\Http\Controllers\Api\ChatController::class, 'markAsRead']);

            // Messages (Send) 
            Route::post('/chats/{contact_uuid}/messages', [App\Http\Controllers\Api\MessageController::class, 'sendMessage']);
            Route::post('/chats/{contact_uuid}/messages/template', [App\Http\Controllers\Api\MessageController::class, 'sendTemplate']);
            Route::post('/chats/{contact_uuid}/messages/pre-created-reply', [App\Http\Controllers\Api\PreCreatedReplyController::class, 'send']);

            // Pre-created Replies
            Route::get('/pre-created-replies', [App\Http\Controllers\Api\PreCreatedReplyController::class, 'index']);
            
            // Contact Management 
            Route::get('/contacts/{contact_uuid}/labels', [App\Http\Controllers\Api\ContactController::class, 'getLabels']);
            Route::put('/contacts/{contact_uuid}/labels', [App\Http\Controllers\Api\ContactController::class, 'updateLabels']);
            Route::delete('/contacts/{contact_uuid}/labels', [App\Http\Controllers\Api\ContactController::class, 'removeLabels']);
            Route::put('/contacts/{contact_uuid}/bot', [App\Http\Controllers\Api\ContactController::class, 'toggleBot']);
        
            // Ticket Management
            Route::get('/agents', [App\Http\Controllers\Api\TicketController::class, 'getAgents']);
            Route::get('/chats/{contact_uuid}/ticket', [App\Http\Controllers\Api\TicketController::class, 'getTicket']);
            Route::put('/chats/{contact_uuid}/status', [App\Http\Controllers\Api\TicketController::class, 'updateStatus']);
            Route::put('/chats/{contact_uuid}/assign', [App\Http\Controllers\Api\TicketController::class, 'assignAgent']);

            // Templates
            Route::get('/templates', [App\Http\Controllers\Api\TemplateController::class, 'index']);
            Route::get('/templates/{uuid}', [App\Http\Controllers\Api\TemplateController::class, 'show']);

            // Device Token Endpoints
            Route::prefix('device-token')->group(function () {
                Route::post('/register', [App\Http\Controllers\Api\DeviceTokenController::class, 'register']);
                Route::post('/unregister', [App\Http\Controllers\Api\DeviceTokenController::class, 'unregister']);
                Route::post('/heartbeat', [App\Http\Controllers\Api\DeviceTokenController::class, 'heartbeat']);
                Route::get('/list', [App\Http\Controllers\Api\DeviceTokenController::class, 'list']);
                Route::post('/test', [App\Http\Controllers\Api\DeviceTokenController::class, 'test']);
            });
           
            Route::prefix('config')->group(function () {
                Route::get('/pusher', [App\Http\Controllers\Api\ConfigController::class, 'pusher']);
            });

            // Chat Notes Endpoints (nested under contacts)
            Route::prefix('contacts/{contact_uuid}/notes')->group(function () {
                Route::get('/', [App\Http\Controllers\Api\ChatNoteController::class, 'index']);           // List notes
                Route::post('/', [App\Http\Controllers\Api\ChatNoteController::class, 'store']);          // Create note
                Route::get('/{note_uuid}', [App\Http\Controllers\Api\ChatNoteController::class, 'show']); // Get single note
                Route::put('/{note_uuid}', [App\Http\Controllers\Api\ChatNoteController::class, 'update']); // Update note
                Route::delete('/{note_uuid}', [App\Http\Controllers\Api\ChatNoteController::class, 'destroy']); // Delete note
            });
        });
    });
});