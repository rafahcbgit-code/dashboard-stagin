# WhatsApp Business Platform - Complete Service Reference Guide

## Table of Contents
1. [Service Architecture Overview](#service-architecture-overview)
2. [AuthService - Authentication Management](#authservice---authentication-management)
3. [WhatsappService - WhatsApp API Integration](#whatsappservice---whatsapp-api-integration)
4. [CampaignService - Bulk Messaging](#campaignservice---bulk-messaging)
5. [ChatService - Real-time Messaging](#chatservice---real-time-messaging)
6. [ContactService - Customer Management](#contactservice---customer-management)
7. [AutoReplyService - Automated Responses](#autoreplyservice---automated-responses)
8. [TemplateService - Template Management](#templateservice---template-management)
9. [OrganizationService - Multi-tenant Management](#organizationservice---multi-tenant-management)
10. [UserService - User Account Management](#userservice---user-account-management)
11. [TicketService - Support System](#ticketservice---support-system)
12. [PhoneService - Phone Number Handling](#phoneservice---phone-number-handling)
13. [MediaService - File Management](#mediaservice---file-management)
14. [RoleService - Access Control](#roleservice---access-control)
15. [UnsubscribeChecker - Subscription Management](#unsubscribechecker---subscription-management)
16. [ChatNoteService - Note Management](#chatnoteservice---note-management)
17. [Integration Patterns and Best Practices](#integration-patterns-and-best-practices)
18. [Troubleshooting Guide](#troubleshooting-guide)

---

## Service Architecture Overview

### Multi-layered Architecture
```
Controllers (HTTP Layer)
    ↓
Services (Business Logic Layer)
    ↓
Models (Data Access Layer)
    ↓
Database/External APIs
```

### Key Principles
- **Single Responsibility**: Each service handles one domain
- **Organization Context**: Most operations are organization-scoped
- **Event-Driven**: Real-time updates via Laravel events
- **Queue-Based**: Heavy operations run asynchronously
- **Webhook Integration**: External system notifications

---

## AuthService - Authentication Management

### Purpose
Handles user authentication and session management for different user roles in the multi-tenant system.

### Class Overview
```php
class AuthService
{
    private $user;
    
    public function __construct($user) {
        $this->user = $user;
    }
}
```

### Core Methods

#### `authenticateSession($request)`
**Purpose**: Manages login process for different user types

**Parameters**:
- `$request`: HTTP request object containing login credentials

**Logic Flow**:
1. Check user role (admin vs user)
2. Use appropriate auth guard
3. Set session context for users

**Implementation**:
```php
public function authenticateSession($request)
{
    if($this->user->role != 'user'){
        Auth::guard('admin')->attempt(['email' => $request->email, 'password' => $request->password]);
    } else {
        Auth::guard('user')->attempt(['email' => $request->email, 'password' => $request->password]);
        $team = Team::where('user_id', $this->user->id)->first();
        session()->put('current_organization', $team->organization_id);
    }
}
```

### Key Features
- **Dual Auth Guards**: Separate authentication for admin and user roles
- **Session Management**: Sets current organization context for users
- **Team Integration**: Links users to their organizations

### Usage Examples

#### Basic Authentication
```php
$authService = new AuthService($user);
$authService->authenticateSession($request);

// Check session
$organizationId = session()->get('current_organization');
```

### When to Use
- User login process
- Role-based authentication
- Session context setup

### Integration Points
- **Controllers**: Login controllers
- **Middleware**: Session validation
- **Guards**: Laravel auth guards

---

## WhatsappService - WhatsApp API Integration

### Purpose
Central service for all WhatsApp Business API interactions including messaging, templates, and media management.

### Class Overview
```php
class WhatsappService
{
    private $accessToken;
    private $apiVersion;
    private $appId;
    private $phoneNumberId;
    private $organizationId;
    private $wabaId;

    public function __construct($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $organizationId) {
        // Initialize API credentials
    }
}
```

### Core Methods

#### `sendMessage($contactUuId, $messageContent, $userId = NULL, $type = "text", $buttons = [], $header = [], $footer = null, $buttonLabel = null)`
**Purpose**: Send various types of messages via WhatsApp

**Parameters**:
- `$contactUuId`: UUID of the contact
- `$messageContent`: Message text content
- `$userId`: User ID sending the message
- `$type`: Message type (text, interactive buttons, etc.)
- `$buttons`: Interactive buttons array
- `$header`: Message header content
- `$footer`: Message footer text
- `$buttonLabel`: Label for list button

**Message Types Supported**:
1. **Text Messages**: Simple text with URL preview
2. **Interactive Buttons**: Quick reply buttons
3. **Call-to-Action URL**: URL buttons
4. **Interactive Lists**: Selection lists

**Implementation Example**:
```php
public function sendMessage($contactUuId, $messageContent, $userId = NULL, $type="text", $buttons = [])
{
    $contact = Contact::where('uuid', $contactUuId)->first();
    $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";
    
    $requestData['messaging_product'] = 'whatsapp';
    $requestData['recipient_type'] = 'individual';
    $requestData['to'] = $contact->phone;
    
    if($type == "text") {
        $requestData['type'] = 'text';
        $requestData['text']['preview_url'] = true;
        $requestData['text']['body'] = clean($messageContent);
    }
    // ... handle other types
    
    $responseObject = $this->sendHttpRequest('POST', $url, $requestData, $headers);
    
    // Store message in database and trigger events
    if($responseObject->success === true) {
        $chat = Chat::create([
            'organization_id' => $contact->organization_id,
            'wam_id' => $responseObject->data->messages[0]->id,
            'contact_id' => $contact->id,
            'type' => 'outbound',
            'user_id' => $userId,
            'metadata' => json_encode($response),
            'status' => 'delivered',
        ]);
        
        // Trigger real-time event
        event(new NewChatEvent($chatArray, $contact->organization_id));
    }
    
    return $responseObject;
}
```

#### `sendTemplateMessage($contactUuId, $templateContent, $userId = NULL, $campaignId = NULL, $mediaId = NULL)`
**Purpose**: Send approved WhatsApp template messages

**Parameters**:
- `$contactUuId`: Contact UUID
- `$templateContent`: Template structure with parameters
- `$userId`: Sending user ID
- `$campaignId`: Associated campaign ID (for bulk sends)
- `$mediaId`: Media file ID if template includes media

**Template Structure**:
```php
$templateContent = [
    'name' => 'template_name',
    'language' => ['code' => 'en'],
    'components' => [
        [
            'type' => 'body',
            'parameters' => [
                ['type' => 'text', 'text' => 'John Doe']
            ]
        ]
    ]
];
```

#### `sendMedia($contactUuId, $mediaType, $mediaFileName, $mediaFilePath, $mediaUrl, $location, $caption = NULL)`
**Purpose**: Send media files (images, documents, audio, video)

**Parameters**:
- `$contactUuId`: Contact UUID
- `$mediaType`: Type (image, document, audio, video)
- `$mediaFileName`: Original filename
- `$mediaFilePath`: Storage path
- `$mediaUrl`: Public URL to media
- `$location`: Storage location (local/amazon)
- `$caption`: Optional caption text

**Media Types**:
- **image**: JPEG, PNG images
- **document**: PDFs, Word docs, etc.
- **audio**: MP3, AAC, OGG audio files
- **video**: MP4, AVI video files

#### Template Management Methods

#### `createTemplate(Request $request)`
**Purpose**: Create new WhatsApp template for approval

**Template Components**:
1. **Header**: Text, Image, Video, Document, or Location
2. **Body**: Main message text with placeholders
3. **Footer**: Optional footer text
4. **Buttons**: Quick reply, URL, or phone number buttons

**Implementation Flow**:
```php
public function createTemplate(Request $request)
{
    $requestData = [
        "name" => $request->name,
        "language" => $request->language,
        "category" => $request->category,
    ];
    
    // Build header component
    if($request->header['format'] === 'TEXT') {
        $requestData['components'][] = [
            'type' => "HEADER",
            'format' => $request->header['format'],
            'text' => $request->header['text']
        ];
    }
    
    // Build body component
    $requestData['components'][] = [
        'type' => "BODY",
        'text' => $request->body['text']
    ];
    
    // Send to WhatsApp API
    $response = $client->post($url, [
        'headers' => ['Authorization' => 'Bearer ' . $this->accessToken],
        'json' => $requestData,
    ]);
    
    // Save to database
    Template::create([
        'organization_id' => session()->get('current_organization'),
        'meta_id' => $response->id,
        'name' => $request->name,
        'metadata' => json_encode($requestData),
        'status' => 'PENDING'
    ]);
}
```

#### `syncTemplates()`
**Purpose**: Synchronize templates from WhatsApp to local database

**Process**:
1. Fetch all templates from WhatsApp API
2. Update existing template records
3. Create new template records
4. Handle pagination for large template lists

#### Business Profile Management

#### `getBusinessProfile()`
**Purpose**: Retrieve WhatsApp business profile information

#### `updateBusinessProfile(Request $request)`
**Purpose**: Update business profile details

**Updatable Fields**:
- Business description
- Email address
- Address
- Industry vertical
- Profile picture

### File Upload System

#### `initiateResumableUploadSession($file)`
**Purpose**: Upload media files to WhatsApp for use in templates

**Process**:
1. Create upload session
2. Upload file content
3. Receive media handle for template use

### Health and Subscription Management

#### `checkHealth()`
**Purpose**: Check WhatsApp Business Account health status

#### `subscribeToWaba()` / `unSubscribeToWaba()`
**Purpose**: Manage webhook subscriptions

#### `getPhoneNumberStatus()`
**Purpose**: Check phone number verification and quality status

### Private Helper Methods

#### `setHeaders()`
**Purpose**: Set standard API headers

#### `sendHttpRequest($method, $url, $data = [], $headers = [])`
**Purpose**: Generic HTTP client wrapper with error handling

#### `buildTemplateChatMessage($templateContent, $contact)`
**Purpose**: Convert template API response to chat message format

### Usage Examples

#### Sending a Simple Text Message
```php
$whatsappService = new WhatsappService($token, $version, $appId, $phoneId, $wabaId, $orgId);

$response = $whatsappService->sendMessage(
    $contactUuid,
    "Hello! How can I help you today?",
    auth()->user()->id
);

if($response->success) {
    // Message sent successfully
    $messageId = $response->data->messages[0]->id;
}
```

#### Sending Interactive Buttons
```php
$buttons = [
    ['id' => 'option_1', 'title' => 'Get Support'],
    ['id' => 'option_2', 'title' => 'View Products']
];

$response = $whatsappService->sendMessage(
    $contactUuid,
    "How can I help you today?",
    auth()->user()->id,
    "interactive buttons",
    $buttons
);
```

#### Creating a Template
```php
$request = new Request([
    'name' => 'welcome_message',
    'category' => 'UTILITY',
    'language' => 'en',
    'body' => [
        'text' => 'Welcome {{1}}! Your order #{{2}} is confirmed.'
    ]
]);

$response = $whatsappService->createTemplate($request);
```

### Error Handling
The service includes comprehensive error handling:
- Connection timeouts
- API rate limiting
- Invalid credentials
- Malformed requests

### Integration Points
- **CampaignService**: Bulk template sending
- **ChatService**: Real-time messaging
- **TemplateService**: Template management
- **AutoReplyService**: Automated responses

---

## CampaignService - Bulk Messaging

### Purpose
Manages bulk WhatsApp messaging campaigns with scheduling, targeting, and media support.

### Class Overview
```php
class CampaignService
{
    use TemplateTrait;
    
    // Core campaign management methods
}
```

### Core Methods

#### `store(object $request)`
**Purpose**: Create and schedule a new campaign

**Parameters**:
- `$request`: Campaign creation request with all configuration

**Campaign Configuration**:
```php
$campaignData = [
    'name' => 'Holiday Sale Campaign',
    'template' => 'template-uuid',
    'contacts' => 'contact-group-uuid', // or 'all'
    'time' => '2024-12-25 10:00:00',
    'skip_schedule' => false,
    'header' => [...],
    'body' => [...],
    'footer' => [...],
    'buttons' => [...]
];
```

**Implementation Flow**:
```php
public function store(object $request)
{
    $organizationId = session()->get('current_organization');
    $timezone = $this->getOrganizationTimezone($organizationId);
    
    DB::transaction(function () use ($request, $organizationId, $timezone) {
        // Handle media upload if present
        $mediaId = null;
        if(in_array($request->header['format'], ['IMAGE', 'DOCUMENT', 'VIDEO'])) {
            $mediaId = $this->processMediaUpload($request->header['parameters']);
        }
        
        // Build campaign metadata
        $metadata = [
            'header' => $request->header,
            'body' => $request->body,
            'footer' => $request->footer,
            'buttons' => $request->buttons,
            'media' => $mediaId
        ];
        
        // Convert scheduled time to UTC
        $scheduledAt = $request->skip_schedule 
            ? Carbon::now('UTC') 
            : Carbon::parse($request->time, $timezone)->setTimezone('UTC');
        
        // Create campaign record
        Campaign::create([
            'organization_id' => $organizationId,
            'name' => $request->name,
            'template_id' => Template::where('uuid', $request->template)->value('id'),
            'contact_group_id' => $request->contacts === 'all' ? 0 : ContactGroup::where('uuid', $request->contacts)->value('id'),
            'metadata' => json_encode($metadata),
            'status' => 'scheduled',
            'scheduled_at' => $scheduledAt,
            'created_by' => auth()->user()->id
        ]);
    });
}
```

**Key Features**:
- **Template Integration**: Uses approved WhatsApp templates
- **Media Support**: Handles image, document, and video attachments
- **Contact Targeting**: All contacts or specific groups
- **Timezone Handling**: Converts local time to UTC for storage
- **Metadata Storage**: Flexible JSON storage for campaign configuration

#### `sendCampaign()`
**Purpose**: Trigger campaign processing (legacy method)

**Note**: This method dispatches the older `SendCampaignJob`. The new system uses separate jobs:
- `CreateCampaignLogsJob` - Creates individual message records
- `ProcessCampaignMessagesJob` - Processes messages in batches

#### `destroy($uuid)`
**Purpose**: Soft delete a campaign

**Implementation**:
```php
public function destroy($uuid)
{
    Campaign::where('uuid', $uuid)->update([
        'deleted_by' => auth()->user()->id,
        'deleted_at' => now()
    ]);
}
```

### Media Processing Methods

#### `getMediaInfo($path)` (Private)
**Purpose**: Extract media file information

#### `getContentTypeFromUrl($url)` (Private)
**Purpose**: Determine MIME type of media files

#### `getMediaSizeInBytesFromUrl($url)` (Private)
**Purpose**: Calculate file size for storage

### Campaign Lifecycle

#### 1. Creation Phase
```php
User creates campaign → CampaignService.store() → Database record with 'scheduled' status
```

#### 2. Processing Phase
```php
Scheduled time arrives → CreateCampaignLogsJob → Individual CampaignLog records created
```

#### 3. Execution Phase
```php
ProcessCampaignMessagesJob → ProcessSingleCampaignLogJob (batched) → WhatsApp API calls
```

#### 4. Completion Phase
```php
All messages processed → Campaign status updated to 'completed'
```

### Campaign Metadata Structure
```json
{
  "header": {
    "format": "IMAGE",
    "parameters": [{
      "type": "image",
      "selection": "upload",
      "value": "https://example.com/image.jpg"
    }]
  },
  "body": {
    "text": "Hello {{1}}, your order {{2}} is ready!",
    "parameters": [{
      "selection": "contact_field",
      "value": "first_name"
    }]
  },
  "footer": {
    "text": "Reply STOP to unsubscribe"
  },
  "buttons": [{
    "type": "QUICK_REPLY",
    "text": "Confirm Order"
  }],
  "media": 123
}
```

### Usage Examples

#### Creating a Text-only Campaign
```php
$campaignService = new CampaignService();

$request = (object) [
    'name' => 'Weekly Newsletter',
    'template' => 'newsletter-template-uuid',
    'contacts' => 'subscribers-group-uuid',
    'time' => '2024-12-20 09:00:00',
    'skip_schedule' => false,
    'header' => ['format' => 'TEXT', 'text' => 'Weekly Update'],
    'body' => [
        'text' => 'Hi {{1}}, here is this week\'s update...',
        'parameters' => [['selection' => 'contact_field', 'value' => 'first_name']]
    ],
    'footer' => ['text' => 'Unsubscribe: reply STOP'],
    'buttons' => []
];

$campaignService->store($request);
```

#### Creating a Media Campaign
```php
$request = (object) [
    'name' => 'Product Launch',
    'template' => 'product-template-uuid',
    'contacts' => 'all',
    'header' => [
        'format' => 'IMAGE',
        'parameters' => [[
            'type' => 'image',
            'selection' => 'upload',
            'value' => $uploadedFile // UploadedFile object
        ]]
    ],
    'body' => [
        'text' => 'Check out our new product!',
        'parameters' => []
    ]
];

$campaignService->store($request);
```

### Integration Points
- **Queue Jobs**: Campaign processing jobs
- **Template System**: WhatsApp template integration
- **Contact Management**: Group targeting
- **Media Storage**: File upload and storage
- **Timezone Handling**: Organization timezone support

### Best Practices
1. **Template Approval**: Ensure templates are approved before campaign creation
2. **Contact Validation**: Verify contact groups exist and have valid contacts
3. **Media Optimization**: Optimize media files for WhatsApp size limits
4. **Scheduling**: Consider recipient timezones when scheduling
5. **Testing**: Test campaigns with small groups first

---

## ChatService - Real-time Messaging

### Purpose
Manages real-time chat functionality, conversation handling, and integration with the ticketing system.

### Class Overview
```php
class ChatService
{
    use TemplateTrait;
    
    private $whatsappService;
    private $organizationId;

    public function __construct($organizationId) {
        $this->organizationId = $organizationId;
        $this->initializeWhatsappService();
    }
}
```

### Core Methods

#### `getChatList($request, $uuid = null, $searchTerm = null)`
**Purpose**: Retrieve chat conversations with pagination and filtering

**Parameters**:
- `$request`: HTTP request with filters and pagination
- `$uuid`: Specific contact UUID for detailed view
- `$searchTerm`: Search query for filtering contacts

**Features**:
- **Pagination**: 10 contacts per page
- **Search**: Filter by contact name, phone, or email
- **Status Filtering**: Open, closed, all tickets
- **Label Filtering**: Filter by contact labels
- **Role-based Access**: Agents see assigned chats vs all chats

**Implementation**:
```php
public function getChatList($request, $uuid = null, $searchTerm = null)
{
    $role = auth()->user()->teams[0]->role;
    $ticketingActive = $this->isTicketingEnabled();
    $allowAgentsToViewAllChats = $this->getTicketingSettings()['allow_agents_to_view_all_chats'];
    
    // Get label filters
    $labelFilters = $request->query('labels') ? explode(',', $request->query('labels')) : [];
    
    // Retrieve contacts with chats
    $contacts = (new Contact)->contactsWithChats(
        $this->organizationId,
        $searchTerm,
        $ticketingActive,
        $request->status ?? 'all',
        $request->session()->get('chat_sort_direction', 'desc'),
        $role,
        $allowAgentsToViewAllChats,
        $labelFilters
    );
    
    if ($uuid !== null) {
        // Load specific contact details
        $contact = Contact::with(['lastChat', 'notes', 'contactGroups', 'labels'])
                          ->where('uuid', $uuid)
                          ->first();
        
        $initialMessages = $this->getChatMessages($contact->id);
        
        // Mark messages as read
        Chat::where('contact_id', $contact->id)
            ->where('type', 'inbound')
            ->whereNull('deleted_at')
            ->where('is_read', 0)
            ->update(['is_read' => 1]);
    }
    
    return $this->buildChatResponse($contacts, $contact ?? null, $initialMessages ?? []);
}
```

#### `getChatMessages($contactId, $page = 1, $perPage = 10)`
**Purpose**: Load paginated chat messages for a contact

**Parameters**:
- `$contactId`: Contact database ID
- `$page`: Page number for pagination
- `$perPage`: Messages per page

**Return Structure**:
```php
return [
    'messages' => array_reverse($chats), // Oldest first
    'hasMoreMessages' => $chatLogs->hasMorePages(),
    'nextPage' => $chatLogs->currentPage() + 1
];
```

**Message Types Included**:
- Chat messages (inbound/outbound)
- Notes
- Ticket activities
- System events

#### `sendMessage(object $request)`
**Purpose**: Send outbound messages to contacts

**Parameters**:
- `$request->type`: 'text' or media type
- `$request->uuid`: Contact UUID
- `$request->message`: Message content (for text)
- `$request->file`: Uploaded file (for media)

**Text Message Flow**:
```php
if($request->type === 'text') {
    return $this->whatsappService->sendMessage(
        $request->uuid, 
        $request->message, 
        auth()->user()->id
    );
}
```

**Media Message Flow**:
```php
else {
    $storage = Setting::where('key', 'storage_system')->first()->value;
    $fileName = $request->file('file')->getClientOriginalName();
    
    if($storage === 'local') {
        $file = Storage::disk('local')->put('public', $fileContent);
        $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($file, '/');
        $location = 'local';
    } else if($storage === 'aws') {
        $uploadedFile = $file->store('uploads/media/sent/' . $this->organizationId, 's3');
        $mediaUrl = Storage::disk('s3')->url($uploadedFile);
        $location = 'amazon';
    }

    $this->whatsappService->sendMedia(
        $request->uuid, 
        $request->type, 
        $fileName, 
        $file, 
        $mediaUrl, 
        $location
    );
}
```

#### `sendTemplateMessage(object $request, $uuid)`
**Purpose**: Send WhatsApp template messages through chat interface

**Parameters**:
- `$request`: Template configuration
- `$uuid`: Contact UUID

**Template Processing**:
1. Process media uploads in template header
2. Build template metadata structure
3. Use TemplateTrait to build final template
4. Send via WhatsappService

**Media Processing**:
```php
if(in_array($request->header['format'], ['IMAGE', 'DOCUMENT', 'VIDEO'])) {
    foreach ($request->header['parameters'] as $parameter) {
        if ($parameter['selection'] === 'upload') {
            // Upload file to storage
            $mediaUrl = $this->uploadFile($parameter['value']);
            
            // Create ChatMedia record
            $chatMedia = ChatMedia::create([
                'name' => $fileName,
                'path' => $mediaUrl,
                'type' => $contentType,
                'size' => $mediaSize
            ]);
            
            $mediaId = $chatMedia->id;
        }
    }
}
```

#### `handleTicketAssignment($contactId)`
**Purpose**: Manage automatic ticket assignment for conversations

**Ticket Logic**:
1. Check if ticketing is enabled
2. Create ticket if none exists
3. Auto-assign to agent with least tickets (if enabled)
4. Reopen closed tickets on new messages
5. Log ticket activities

**Implementation**:
```php
public function handleTicketAssignment($contactId)
{
    $settings = $this->getTicketingSettings();
    
    if(!$settings['active']) return;
    
    DB::transaction(function () use ($contactId, $settings) {
        $ticket = ChatTicket::where('contact_id', $contactId)->first();
        
        if(!$ticket) {
            // Create new ticket
            $ticket = new ChatTicket([
                'contact_id' => $contactId,
                'status' => 'open',
                'updated_at' => now()
            ]);
            
            // Auto-assign if enabled
            if($settings['auto_assignment']) {
                $agent = Team::where('organization_id', $this->organizationId)
                    ->withCount('tickets')
                    ->orderBy('tickets_count')
                    ->first();
                
                $ticket->assigned_to = $agent->user_id;
            }
            
            $ticket->save();
            
            // Log ticket creation
            ChatTicketLog::create([
                'contact_id' => $contactId,
                'description' => 'Conversation was opened'
            ]);
        }
        else if($ticket->status === 'closed') {
            // Reopen closed ticket
            $ticket->status = 'open';
            
            if($settings['reassign_reopened_chats'] && $settings['auto_assignment']) {
                $agent = Team::where('organization_id', $this->organizationId)
                    ->withCount('tickets')
                    ->orderBy('tickets_count')
                    ->first();
                
                $ticket->assigned_to = $agent->user_id;
            }
            
            $ticket->save();
            
            ChatTicketLog::create([
                'contact_id' => $contactId,
                'description' => 'Conversation was moved from closed to open'
            ]);
        }
    });
}
```

### Message Management Methods

#### `clearMessage($uuid)`
**Purpose**: Soft delete individual messages

#### `clearContactChat($uuid)`
**Purpose**: Soft delete entire conversation with a contact

**Process**:
1. Find contact and last chat
2. Soft delete all chat messages
3. Soft delete related chat logs
4. Trigger real-time event for UI updates

### Helper Methods

#### `getLocationSettings()`
**Purpose**: Retrieve organization location settings for contact forms

#### `initializeWhatsappService()`
**Purpose**: Initialize WhatsApp service with organization credentials

**Configuration Loading**:
```php
private function initializeWhatsappService()
{
    $config = Organization::where('id', $this->organizationId)->first()->metadata;
    $config = $config ? json_decode($config, true) : [];

    $this->whatsappService = new WhatsappService(
        $config['whatsapp']['access_token'] ?? null,
        config('graph.api_version'),
        $config['whatsapp']['app_id'] ?? null,
        $config['whatsapp']['phone_number_id'] ?? null,
        $config['whatsapp']['waba_id'] ?? null,
        $this->organizationId
    );
}
```

### Usage Examples

#### Loading Chat List
```php
$chatService = new ChatService($organizationId);

// Get all chats
$chatList = $chatService->getChatList($request);

// Get specific contact chat
$specificChat = $chatService->getChatList($request, $contactUuid);
```

#### Sending Messages
```php
// Send text message
$request = (object) [
    'type' => 'text',
    'uuid' => $contactUuid,
    'message' => 'Hello! How can I help you?'
];
$response = $chatService->sendMessage($request);

// Send image
$request = (object) [
    'type' => 'image',
    'uuid' => $contactUuid,
    'file' => $uploadedFile
];
$response = $chatService->sendMessage($request);
```

#### Template Messaging
```php
$request = (object) [
    'template' => $templateUuid,
    'header' => ['format' => 'TEXT', 'text' => 'Order Update'],
    'body' => [
        'parameters' => [
            ['selection' => 'static', 'value' => 'John Doe']
        ]
    ]
];

$response = $chatService->sendTemplateMessage($request, $contactUuid);
```

### Real-time Features
- **Live Updates**: Uses Pusher for real-time message updates
- **Read Receipts**: Marks messages as read when viewed
- **Typing Indicators**: Shows when agents are typing (if implemented)
- **Presence Status**: Shows agent online/offline status

### Integration Points
- **WhatsappService**: Message sending
- **TemplateService**: Template processing
- **ContactService**: Contact management
- **TicketService**: Support ticket integration
- **AutoReplyService**: Automated responses
- **SubscriptionService**: Message limit checking

### Performance Considerations
- **Pagination**: Large conversation histories are paginated
- **Lazy Loading**: Messages loaded on demand
- **Caching**: Organization settings cached
- **Database Indexes**: Optimized queries for chat loading

---

## ContactService - Customer Management

### Purpose
Comprehensive contact relationship management system for storing and managing customer information with multi-organization support.

### Class Overview
```php
class ContactService
{
    private $organizationId;

    public function __construct($organizationId) {
        $this->organizationId = $organizationId;
    }
}
```

### Core Methods

#### `store(object $request, $uuid = null)`
**Purpose**: Create or update contact records with comprehensive data validation

**Parameters**:
- `$request`: Contact data including personal info, address, metadata
- `$uuid`: Contact UUID for updates (null for new contacts)

**Fields Managed**:
- **Basic Info**: first_name, last_name, email, phone
- **Status**: is_subscribed (marketing consent)
- **Address**: street, city, state, zip, country (stored as JSON)
- **Avatar**: Profile image upload with storage options
- **Metadata**: Custom fields stored as JSON
- **Groups**: Contact group associations

**Implementation**:
```php
public function store(object $request, $uuid = null)
{
    $contact = $uuid === null ? new Contact() : Contact::where('uuid', $uuid)->firstOrFail();
    
    // Basic contact information
    $contact->first_name = $request->first_name;
    $contact->last_name = $request->last_name;
    $contact->email = $request->email;
    $contact->is_subscribed = $request->is_subscribed;
    
    // Phone number formatting
    $contact->phone = PhoneService::getE164Format($request->phone);
    
    // Avatar upload handling
    if($request->hasFile('file')) {
        $storage = Setting::where('key', 'storage_system')->first()->value;
        
        if($storage === 'local') {
            $file = Storage::disk('local')->put('public', $fileContent);
            $contact->avatar = '/media/' . ltrim($file, '/');
        } else if($storage === 'aws') {
            $uploadedFile = $request->file('file')->store('uploads/media/contacts/' . $this->organizationId, 's3');
            $contact->avatar = Storage::disk('s3')->url($uploadedFile);
        }
    }
    
    // Address information (JSON storage)
    $address = json_encode([
        'street' => $request->street,
        'city' => $request->city,
        'state' => $request->state,
        'zip' => $request->zip,
        'country' => $request->country,
    ]);
    $contact->address = $address;
    
    // Custom metadata fields
    $contact->metadata = json_encode($request->metadata);
    
    // Set organization context for new contacts
    if($uuid === null) {
        $contact->organization_id = $this->organizationId;
        $contact->created_by = auth()->user() ? auth()->user()->id : 0;
        $contact->created_at = now();
    }
    
    $contact->updated_at = now();
    $contact->save();
    
    // Sync contact groups
    if($request->group) {
        $groupUuids = array_map('trim', $request->group);
        $groupIds = ContactGroup::whereIn('uuid', $groupUuids)->pluck('id')->toArray();
        $contact->contactGroups()->sync($groupIds);
    }
    
    // Trigger webhook
    WebhookHelper::triggerWebhookEvent(
        $uuid === null ? 'contact.created' : 'contact.updated',
        $contact->makeHidden(['id', 'organization_id', 'created_by']),
        $this->organizationId
    );
    
    return $contact;
}
```

#### `updatePartial($uuid, array $data)`
**Purpose**: Update specific contact fields without requiring all data

**Use Cases**:
- Subscription status changes
- Quick field updates
- Programmatic updates from other services

**Implementation**:
```php
public function updatePartial($uuid, array $data)
{
    $contact = Contact::where('id', $uuid)->firstOrFail();

    foreach ($data as $field => $value) {
        if ($field === 'phone') {
            $contact->phone = PhoneService::getE164Format($value);
        } else {
            $contact->{$field} = $value;
        }
    }

    $contact->updated_at = now();
    $contact->save();

    return $contact;
}
```

**Example Usage**:
```php
// Unsubscribe contact
$contactService->updatePartial($contactId, ['is_subscribed' => 0]);

// Update phone number
$contactService->updatePartial($contactId, ['phone' => '+1234567890']);
```

#### `favorite(object $request, $uuid)`
**Purpose**: Toggle contact favorite status for quick access

**Parameters**:
- `$request->favorite`: Boolean favorite status
- `$uuid`: Contact UUID

#### `delete($uuids)`
**Purpose**: Bulk delete contacts with automatic cleanup

**Features**:
- Single contact deletion
- Bulk deletion by UUID array
- Complete organization cleanup (empty array)
- Automatic unread message marking

**Implementation Logic**:
```php
public function delete($uuids)
{
    $deletedContacts = [];

    if (empty($uuids)) {
        // Delete all contacts in organization
        $contacts = Contact::where('organization_id', $this->organizationId)->get();
        Contact::where('organization_id', $this->organizationId)->delete();
        
        // Mark all unread chats as read
        Chat::where('organization_id', $this->organizationId)
            ->where('type', 'inbound')
            ->whereNull('deleted_at')
            ->where('is_read', 0)
            ->update(['is_read' => 1]);
            
        foreach ($contacts as $contact) {
            $deletedContacts[] = [
                'uuid' => $contact->uuid,
                'deleted_at' => now()->toISOString()
            ];
        }
    } else {
        // Delete specific contacts
        foreach($uuids as $uuid) {
            $contact = Contact::where('uuid', $uuid)->firstOrFail();
            
            // Mark unread chats as read for this contact
            Chat::where('contact_id', $contact->id)
                ->where('type', 'inbound')
                ->whereNull('deleted_at')
                ->where('is_read', 0)
                ->update(['is_read' => 1]);
                
            $deletedContacts[] = [
                'uuid' => $contact->uuid,
                'deleted_at' => now()->toISOString()
            ];
        }
        
        Contact::whereIn('uuid', $uuids)
               ->where('organization_id', $this->organizationId)
               ->delete();
    }

    // Trigger webhook notification
    WebhookHelper::triggerWebhookEvent('contact.deleted', [
        'list' => $deletedContacts
    ], $this->organizationId);
}
```

### Contact Data Structure

#### Core Fields
```php
$contact = [
    'uuid' => 'auto-generated',
    'organization_id' => 123,
    'first_name' => 'John',
    'last_name' => 'Doe',
    'email' => 'john@example.com',
    'phone' => '+1234567890', // E.164 format
    'is_subscribed' => true,
    'avatar' => '/path/to/avatar.jpg',
    'is_favorite' => false,
    'created_by' => 456,
    'created_at' => '2024-01-01 00:00:00',
    'updated_at' => '2024-01-01 00:00:00'
];
```

#### Address JSON Structure
```json
{
    "street": "123 Main Street",
    "city": "New York",
    "state": "NY",
    "zip": "10001",
    "country": "USA"
}
```

#### Metadata JSON Structure
```json
{
    "custom_field_1": "value1",
    "customer_type": "premium",
    "source": "website",
    "tags": ["vip", "high-value"]
}
```

### Usage Examples

#### Creating a New Contact
```php
$contactService = new ContactService($organizationId);

$request = (object) [
    'first_name' => 'Jane',
    'last_name' => 'Smith',
    'email' => 'jane@example.com',
    'phone' => '+1-555-123-4567',
    'is_subscribed' => true,
    'street' => '456 Oak Avenue',
    'city' => 'San Francisco',
    'state' => 'CA',
    'zip' => '94102',
    'country' => 'USA',
    'metadata' => [
        'source' => 'referral',
        'customer_since' => '2024-01-01'
    ],
    'group' => ['group-uuid-1', 'group-uuid-2']
];

$contact = $contactService->store($request);
```

#### Updating Existing Contact
```php
$contact = $contactService->store($request, $contactUuid);
```

#### Bulk Contact Deletion
```php
// Delete specific contacts
$contactService->delete(['uuid1', 'uuid2', 'uuid3']);

// Delete all organization contacts
$contactService->delete([]);
```

### Integration Points
- **PhoneService**: Phone number validation and formatting
- **WebhookHelper**: External system notifications
- **Storage Systems**: File upload handling (local/S3)
- **ContactGroups**: Many-to-many relationship management
- **Chat System**: Automatic cleanup of unread messages

### Webhook Events
The service triggers webhooks for:
- `contact.created` - New contact registration
- `contact.updated` - Contact information changes
- `contact.deleted` - Contact removal (single or bulk)

### Best Practices
1. **Phone Validation**: Always use PhoneService for phone number handling
2. **Metadata Usage**: Store custom fields in metadata JSON for flexibility
3. **Group Management**: Use contact groups for campaign targeting
4. **File Storage**: Configure storage system (local/S3) via settings
5. **Webhook Integration**: Monitor webhook events for external system sync

---

## AutoReplyService - Automated Responses

### Purpose
Handles automated responses to incoming messages through configurable response sequences including basic replies, automated flows, and AI-powered responses.

### Class Overview
```php
class AutoReplyService
{
    // Manages automated response logic and integrations
}
```

### Response Sequence Architecture

The service implements a configurable response sequence that processes in order:
1. **Basic Replies**: Keyword-based auto-responses
2. **Automated Flows**: Complex conversation flows (if FlowBuilder module enabled)
3. **AI Reply Assistant**: AI-powered responses (if IntelliReply module enabled)

### Core Methods

#### `checkAutoReply(Chat $chat, $isNewContact)`
**Purpose**: Main entry point for processing auto-replies on incoming messages

**Parameters**:
- `$chat`: Chat message object with metadata
- `$isNewContact`: Boolean indicating if this is a new contact

**Flow**:
1. Get organization configuration
2. Check for active flows (overrides sequence)
3. Execute response sequence in order
4. Return first successful response

#### `replySequence($organizationId, $chat, $isNewContact)`
**Purpose**: Executes the configured response sequence

**Configuration Override Logic**:
```php
private function replySequence($organizationId, $chat, $isNewContact)
{
    $organizationConfig = Organization::where('id', $organizationId)->first();
    $metadataArray = json_decode($organizationConfig->metadata, true) ?? [];
    
    // Check for active flows (FlowBuilder module)
    $activeFlow = false;
    if (class_exists(\Modules\FlowBuilder\Services\FlowExecutionService::class)) {
        $query = new \Modules\FlowBuilder\Services\FlowExecutionService($organizationId);
        $activeFlow = $query->hasActiveFlow($chat);
    }

    // Override sequence if active flow exists
    if ($activeFlow) {
        $response_sequence = ['Automated Flows'];
    } else {
        $response_sequence = $metadataArray['automation']['response_sequence'] ?? 
                           ['Basic Replies', 'Automated Flows', 'AI Reply Assistant'];
    }

    // Execute sequence functions
    $sequenceFunctions = [
        'Basic Replies' => fn() => $this->handleBasicReplies($chat),
        'Automated Flows' => fn() => $this->handleAutomatedFlows($organizationId, $chat, $isNewContact),
        'AI Reply Assistant' => fn() => $this->handleAIReplyAssistant($chat),
    ];

    foreach ($response_sequence as $sequenceItem) {
        if (isset($sequenceFunctions[$sequenceItem])) {
            $response = $sequenceFunctions[$sequenceItem]();
            if ($response) break; // Exit on first successful response
        }
    }

    return $response;
}
```

### Basic Replies System

#### `handleBasicReplies($chat)`
**Purpose**: Process keyword-based auto-replies

**Message Type Support**:
- **Text messages**: Direct text content
- **Button responses**: Button payload text
- **Interactive replies**: Button or list selection titles

**Text Extraction Logic**:
```php
private function handleBasicReplies($chat)
{
    $text = '';
    $metadata = json_decode($chat->metadata, true);

    switch($metadata['type']) {
        case 'text':
            $text = $metadata['text']['body'];
            break;
        case 'button':
            $text = $metadata['button']['payload'];
            break;
        case 'interactive':
            if($metadata['interactive']['type'] == 'button_reply') {
                $text = $metadata['interactive']['button_reply']['title'];
            } else if($metadata['interactive']['type'] == 'list_reply') {
                $text = $metadata['interactive']['list_reply']['title'];
            }
            break;
    }
    
    $receivedMessage = " " . $text; // Add leading space for matching
    
    // Process auto-replies
    $autoReplies = AutoReply::where('organization_id', $chat->organization_id)
                            ->whereNull('deleted_at')
                            ->get();

    foreach ($autoReplies as $autoReply) {
        $triggerValues = $this->getTriggerValues($autoReply->trigger);
        
        foreach ($triggerValues as $trigger) {
            if ($this->checkMatch($receivedMessage, $trigger, $autoReply->match_criteria)) {
                $this->sendReply($chat, $autoReply);
                return true;
            }
        }
    }

    // Check for global reply if no auto-reply matched
    return $this->sendGlobalReply($chat);
}
```

#### Matching System

#### `checkMatch($receivedMessage, $trigger, $criteria)`
**Purpose**: Advanced text matching with multilingual support

**Matching Types**:
1. **Exact Match**: Precise text matching
2. **Contains**: Word-boundary matching for English, substring for Arabic

**Arabic Text Handling**:
```php
private function checkMatch($receivedMessage, $trigger, $criteria)
{
    $normalizedTrigger = trim($trigger);

    if ($criteria === 'exact match') {
        $hasArabic = preg_match('/[\p{Arabic}]/u', $receivedMessage . $normalizedTrigger);
        
        if ($hasArabic) {
            // Arabic: case-sensitive exact matching
            return $receivedMessage === " " . $normalizedTrigger;
        } else {
            // English: case-insensitive matching
            return strtolower($receivedMessage) === " " . strtolower($normalizedTrigger);
        }
    } 
    else if ($criteria === 'contains') {
        $triggerWords = explode(' ', $normalizedTrigger);
        $hasArabic = preg_match('/[\p{Arabic}]/u', $receivedMessage . $normalizedTrigger);
        
        if ($hasArabic) {
            // Arabic: simple substring matching
            foreach ($triggerWords as $word) {
                if (strpos($receivedMessage, $word) !== false) {
                    return true;
                }
            }
        } else {
            // English: word boundary regex matching
            $pattern = '/\b(' . implode('|', array_map('preg_quote', $triggerWords)) . ')\b/i';
            return preg_match($pattern, strtolower($receivedMessage)) === 1;
        }
    }
    
    return false;
}
```

#### `getTriggerValues($trigger)`
**Purpose**: Parse comma-separated trigger phrases

```php
private function getTriggerValues($trigger)
{
    return is_string($trigger) && strpos($trigger, ',') !== false
        ? explode(',', $trigger)
        : (array) $trigger;
}
```

### Response Sending System

#### `sendReply(Chat $chat, AutoReply $autoreply)`
**Purpose**: Send configured auto-reply response

**Response Types**:
1. **Text**: Plain text with placeholder replacement
2. **Media**: Images or audio files

**Implementation**:
```php
protected function sendReply(Chat $chat, AutoReply $autoreply)
{
    $contact = Contact::where('id', $chat->contact_id)->first();
    $metadata = json_decode($autoreply->metadata);
    $replyType = $metadata->type;

    if($replyType === 'text') {
        $message = $this->replacePlaceholders(
            $chat->organization_id, 
            $contact->uuid, 
            $metadata->data->text
        );
        $this->initializeWhatsappService($chat->organization_id)
             ->sendMessage($contact->uuid, $message);
    } 
    else if($replyType === 'audio' || $replyType === 'image') {
        $location = strpos($metadata->data->file->location, 'public\\') === 0 ? 'local' : 'amazon';
        $this->initializeWhatsappService($chat->organization_id)
             ->sendMedia(
                 $contact->uuid, 
                 $replyType, 
                 $metadata->data->file->name, 
                 $metadata->data->file->location, 
                 $metadata->data->file->url, 
                 $location
             );
    }
}
```

### Placeholder Replacement System

#### `replacePlaceholders($organizationId, $contactUuid, $message)`
**Purpose**: Dynamic content replacement in messages

**Available Placeholders**:
```php
$data = [
    'first_name' => $contact->first_name,
    'last_name' => $contact->last_name,
    'full_name' => $contact->full_name,
    'email' => $contact->email,
    'phone' => $contact->phone,
    'organization_name' => $organization->name,
    'full_address' => $formattedAddress,
    'street' => $address['street'],
    'city' => $address['city'],
    'state' => $address['state'],
    'zip_code' => $address['zip'],
    'country' => $address['country'],
    // Plus custom metadata fields with snake_case transformation
];
```

**Placeholder Formats**:
- `{first_name}` - Regular replacement
- `{url:email}` - URL-encoded replacement

**Processing Logic**:
```php
// First handle URL-encoded placeholders
$message = preg_replace_callback('/\{url:(\w+)\}/', function ($matches) use ($mergedData) {
    $key = $matches[1];
    return isset($mergedData[$key]) ? rawurlencode($mergedData[$key]) : $matches[0];
}, $message);

// Then handle regular placeholders
return preg_replace_callback('/\{(\w+)\}/', function ($matches) use ($mergedData) {
    $key = $matches[1];
    return isset($mergedData[$key]) ? $mergedData[$key] : $matches[0];
}, $message);
```

### Global Reply System

#### `sendGlobalReply($chat)`
**Purpose**: Send organization-wide default response when no specific reply matches

**Configuration**:
```json
{
  "global_reply": {
    "active": true,
    "message": "Thanks for your message! We'll get back to you soon.",
    "buttons": [
      {
        "title": "Talk to Agent",
        "payload": "agent_request"
      },
      {
        "title": "FAQ",
        "payload": "show_faq"
      }
    ]
  }
}
```

**Implementation**:
```php
private function sendGlobalReply($chat)
{
    $organization = Organization::where('id', $chat->organization_id)->first();
    $metadata = json_decode($organization->metadata, true);
    
    if (!isset($metadata['global_reply']) || !$metadata['global_reply']['active']) {
        return false;
    }
    
    $globalReply = $metadata['global_reply'];
    $contact = Contact::where('id', $chat->contact_id)->first();
    
    if (!empty($globalReply['buttons'])) {
        // Send interactive message with buttons
        $buttons = array_map(function($button) {
            return ['id' => $button['payload'], 'title' => $button['title']];
        }, $globalReply['buttons']);
        
        $this->initializeWhatsappService($chat->organization_id)->sendMessage(
            $contact->uuid, 
            $globalReply['message'], 
            null, 
            'interactive buttons', 
            $buttons
        );
    } else {
        // Send simple text message
        $this->initializeWhatsappService($chat->organization_id)->sendMessage(
            $contact->uuid, 
            $globalReply['message']
        );
    }
    
    return true;
}
```

### External Module Integration

#### AI Reply Assistant Integration
```php
private function handleAIReplyAssistant($chat)
{
    if (file_exists(base_path('modules/IntelliReply/Services/AIResponseService.php'))) {
        $query = new \Modules\IntelliReply\Services\AIResponseService();
        return $query->handleAIResponse($chat, $receivedMessage);
    }
    return false;
}
```

#### Automated Flows Integration
```php
private function handleAutomatedFlows($organizationId, $chat, $isNewContact)
{
    if (file_exists(base_path('modules/FlowBuilder/Services/FlowExecutionService.php'))) {
        $query = new \Modules\FlowBuilder\Services\FlowExecutionService($organizationId);
        return $query->executeFlow($chat, $isNewContact, $receivedMessage);
    }
    return false;
}
```

### CRUD Operations

#### `getRows(object $request)`
**Purpose**: Retrieve auto-reply configurations with search

#### `store(object $request, $uuid = null)`
**Purpose**: Create or update auto-reply configurations

**Auto-reply Structure**:
```php
$autoReply = [
    'name' => 'Welcome Message',
    'trigger' => 'hello,hi,hey', // Comma-separated
    'match_criteria' => 'contains', // or 'exact match'
    'metadata' => [
        'type' => 'text',
        'data' => [
            'text' => 'Hello {first_name}! How can I help you today?'
        ]
    ]
];
```

#### `destroy($uuid)`
**Purpose**: Soft delete auto-reply configuration

### Usage Examples

#### Creating Text Auto-Reply
```php
$autoReplyService = new AutoReplyService();

$request = (object) [
    'name' => 'Greeting Response',
    'trigger' => 'hello,hi,hey,good morning',
    'match_criteria' => 'contains',
    'response_type' => 'text',
    'response' => 'Hello {first_name}! Welcome to {organization_name}. How can we help you today?'
];

$autoReplyService->store($request);
```

#### Creating Media Auto-Reply
```php
$request = (object) [
    'name' => 'Product Catalog',
    'trigger' => 'catalog,products,brochure',
    'match_criteria' => 'contains',
    'response_type' => 'image',
    'response' => $uploadedFile // UploadedFile object
];

$autoReplyService->store($request);
```

### Integration Points
- **Chat Processing**: Processes all incoming messages
- **WhatsappService**: Sends response messages
- **Contact Management**: Accesses contact information for placeholders
- **Organization Settings**: Retrieves configuration and global replies
- **FlowBuilder Module**: Advanced conversation flows
- **IntelliReply Module**: AI-powered responses
- **WebhookHelper**: External notifications

### Best Practices
1. **Response Sequence**: Configure sequence based on complexity needs
2. **Keyword Strategy**: Use specific keywords to avoid conflicts
3. **Multilingual Support**: Consider Arabic vs English text handling
4. **Placeholder Usage**: Leverage contact data for personalization
5. **Global Fallback**: Always configure a global reply for unmatched messages
6. **Testing**: Test triggers with various message formats

---

## TemplateService - Template Management

### Purpose
Manages WhatsApp Business template lifecycle including creation, approval tracking, and synchronization with Meta's template system.

### Class Overview
```php
class TemplateService
{
    private $whatsappService;
    private $organizationId;

    public function __construct($organizationId) {
        $this->organizationId = $organizationId;
        $this->initializeWhatsappService();
    }
}
```

### Template Lifecycle Management

#### Template States
1. **PENDING**: Submitted to Meta, awaiting review
2. **APPROVED**: Approved by Meta, ready for use
3. **REJECTED**: Rejected by Meta, needs modification
4. **PAUSED**: Temporarily disabled
5. **DISABLED**: Disabled due to policy violations

### Core Methods

#### `getTemplates(Request $request, $uuid = null, $searchTerm = null)`
**Purpose**: Unified template retrieval method handling multiple use cases

**Use Cases**:
1. **List View** (`$uuid = null`): Returns paginated template list
2. **Sync Operation** (`$uuid = 'sync'`): Triggers template synchronization
3. **Detail View** (`$uuid = template-uuid`): Returns specific template details

**API Response Format**:
```php
// For dropdown/API usage
if ($request->expectsJson() && $uuid === null) {
    return response()->json([[
        'value' => $template->id,
        'label' => $template->name,
    ]]);
}

// For web interface
return Inertia::render('User/Templates/Index', [
    'title' => __('templates'),
    'allowCreate' => true,
    'rows' => TemplateResource::collection($templates)
]);
```

#### `createTemplate(Request $request)`
**Purpose**: Handle template creation for both GET (form) and POST (submission)

**Validation Rules**:
```php
$validator = Validator::make($request->all(), [
    'name' => 'required',
    'category' => 'required',
    'language' => 'required',
]);
```

**Template Categories**:
- **UTILITY**: General business communications
- **MARKETING**: Promotional messages (limited frequency)
- **AUTHENTICATION**: OTP and verification codes

**GET Request Response**:
```php
if ($request->isMethod('get')) {
    return Inertia::render('User/Templates/Add', [
        'languages' => config('languages'),
        'settings' => Organization::find($this->organizationId)
    ]);
}
```

**POST Request Processing**:
```php
return $this->whatsappService->createTemplate($request);
```

#### `updateTemplate(Request $request, $uuid)`
**Purpose**: Update existing template with validation

**Important Notes**:
- Approved templates have limited editability
- Updates reset template status to PENDING
- Must preserve approved template category

#### `deleteTemplate($uuid)`
**Purpose**: Delete template from both Meta and local database

**Process**:
1. Call WhatsApp API to delete template
2. Soft delete local template record
3. Return success/failure response

**Demo Account Protection**:
```php
protected function abortIfDemo($type)
{
    if (app()->environment('demo') && $this->organizationId == 1) {
        return response()->json([
            'success' => false,
            'message' => 'Demo account restriction'
        ]);
    }
    return null;
}
```

### Template Structure Components

#### Header Component Types
1. **TEXT**: Dynamic text with placeholders
2. **IMAGE**: Static or dynamic images
3. **VIDEO**: Static or dynamic videos
4. **DOCUMENT**: Static or dynamic documents

#### Body Component
- **Required**: Main message content
- **Placeholders**: `{{1}}`, `{{2}}`, etc.
- **Character Limits**: Varies by template type

#### Footer Component
- **Optional**: Additional context
- **Static Text**: No placeholders allowed

#### Button Components
1. **QUICK_REPLY**: Response buttons
2. **URL**: Website redirect buttons
3. **PHONE_NUMBER**: Call action buttons
4. **COPY_CODE**: Copy text buttons

### WhatsApp API Integration

The service delegates actual API calls to WhatsappService:

#### Template Creation Flow
```php
public function createTemplate(Request $request)
{
    // Validation
    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->messages()->get('*')
        ]);
    }

    // Delegate to WhatsappService
    return $this->whatsappService->createTemplate($request);
}
```

#### Template Synchronization
```php
// Triggered by: /templates/sync
if ($uuid === 'sync') {
    return $this->whatsappService->syncTemplates();
}
```

### Template Data Structure

#### Database Template Record
```php
$template = [
    'uuid' => 'auto-generated',
    'organization_id' => 123,
    'meta_id' => 'meta-template-id',
    'name' => 'order_confirmation',
    'category' => 'UTILITY',
    'language' => 'en',
    'status' => 'APPROVED',
    'metadata' => json_encode($templateStructure),
    'created_by' => 456,
    'created_at' => '2024-01-01 00:00:00',
    'updated_at' => '2024-01-01 00:00:00'
];
```

#### Template Metadata Structure
```json
{
  "components": [
    {
      "type": "HEADER",
      "format": "TEXT",
      "text": "Order Update for {{1}}"
    },
    {
      "type": "BODY",
      "text": "Hi {{1}}, your order {{2}} has been {{3}}. Expected delivery: {{4}}."
    },
    {
      "type": "FOOTER",
      "text": "Reply STOP to unsubscribe"
    },
    {
      "type": "BUTTONS",
      "buttons": [
        {
          "type": "QUICK_REPLY",
          "text": "Track Order"
        },
        {
          "type": "URL",
          "text": "View Details",
          "url": "https://example.com/order/{{2}}"
        }
      ]
    }
  ]
}
```

### Usage Examples

#### Creating a Simple Text Template
```php
$templateService = new TemplateService($organizationId);

$request = new Request([
    'name' => 'welcome_message',
    'category' => 'UTILITY',
    'language' => 'en',
    'body' => [
        'text' => 'Welcome {{1}} to our service! Your account {{2}} is now active.',
        'example' => ['John Doe', 'premium']
    ],
    'footer' => [
        'text' => 'Thank you for choosing us!'
    ]
]);

$response = $templateService->createTemplate($request);
```

#### Creating Template with Interactive Buttons
```php
$request = new Request([
    'name' => 'order_status',
    'category' => 'UTILITY',
    'language' => 'en',
    'header' => [
        'format' => 'TEXT',
        'text' => 'Order #{{1}} Status Update'
    ],
    'body' => [
        'text' => 'Your order has been {{2}}. Estimated delivery: {{3}}.',
        'example' => ['ORD123', 'shipped', '2024-12-25']
    ],
    'buttons' => [
        [
            'type' => 'QUICK_REPLY',
            'text' => 'Track Package'
        ],
        [
            'type' => 'URL',
            'text' => 'View Order',
            'url' => 'https://shop.example.com/orders/{{1}}'
        ],
        [
            'type' => 'PHONE_NUMBER',
            'text' => 'Call Support',
            'country' => '+1',
            'phone_number' => '5551234567'
        ]
    ]
]);

$response = $templateService->createTemplate($request);
```

### Integration Points
- **WhatsappService**: All API interactions
- **CampaignService**: Bulk template messaging
- **ChatService**: Individual template messages
- **Organization Settings**: Multi-tenant context

### Best Practices
1. **Template Naming**: Use descriptive, lowercase names with underscores
2. **Placeholder Strategy**: Plan parameter usage carefully
3. **Approval Process**: Allow time for Meta's review process
4. **Testing**: Test templates thoroughly before production use
5. **Maintenance**: Regularly sync templates to check status changes

---

## OrganizationService - Multi-tenant Management

### Purpose
Manages multi-tenant organization structure with user relationships, subscription handling, and billing transaction management.

### Class Overview
```php
class OrganizationService
{
    // Handles organization CRUD and related operations
}
```

### Core Methods

#### `get(object $request, $userId = null)`
**Purpose**: Retrieve organizations with optional user filtering

**Parameters**:
- `$request`: HTTP request with search parameters
- `$userId`: Optional user ID filter for user-specific organizations

**Implementation**:
```php
public function get(object $request, $userId = null)
{
    $organizations = (new Organization)->listAll($request->query('search'), $userId);
    return OrganizationsResource::collection($organizations);
}
```

#### `getByUuid($request, $uuid = null)`
**Purpose**: Retrieve detailed organization information with related data

**Returns**:
```php
$result = [
    'organization' => Organization::with('subscription.plan')->where('uuid', $uuid)->first(),
    'billing' => BillingResource::collection($billingTransactions),
    'users' => UserResource::collection($users),
    'plans' => SubscriptionPlan::all()
];
```

**Related Data Loading**:
- Organization with subscription and plan details
- Billing transaction history
- Associated users
- Available subscription plans

#### `store(Object $request)`
**Purpose**: Create new organization with user assignment and subscription setup

**Organization Creation Flow**:
```php
public function store(Object $request)
{
    return DB::transaction(function () use ($request) {
        // 1. Handle user creation or assignment
        if($request->input('create_user') == 1) {
            $user = User::create([
                'first_name' => $request->input('first_name'),
                'last_name' => $request->input('last_name'),
                'email' => $request->input('email'),
                'role' => 'user',
                'phone' => $request->input('phone') ? phone($request->input('phone'))->formatE164() : null,
                'address' => json_encode([...]),
                'password' => $request->input('password'),
            ]);
        } else {
            $user = User::where('email', $request->input('email'))->first();
        }

        // 2. Generate unique identifier
        $timestamp = now()->format('YmdHis');
        $randomString = Str::random(4);
        $userId = $user->id;

        // 3. Create organization
        $organization = Organization::create([
            'name' => $request->input('name'),
            'identifier' => $timestamp . $userId . $randomString,
            'address' => json_encode([...]),
            'created_by' => auth()->user()->id,
        ]);

        // 4. Create team relationship
        Team::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
            'created_by' => auth()->user()->id,
        ]);

        // 5. Setup subscription
        $plan = SubscriptionPlan::where('uuid', $request->plan)->first();
        $has_trial = $this->hasTrialPeriod();

        Subscription::create([
            'organization_id' => $organization->id,
            'status' => $has_trial ? 'trial' : 'active',
            'plan_id' => $plan ? $plan->id : null,
            'start_date' => now(),
            'valid_until' => $has_trial ? $this->getTrialEndDate() : now(),
        ]);

        return $organization;
    });
}
```

**Key Features**:
- **Dual User Handling**: Create new user or assign existing user
- **Unique Identifiers**: Timestamp + UserID + Random string
- **Team Management**: Automatic owner role assignment
- **Trial Support**: Configurable trial periods for new organizations
- **Address Storage**: JSON-formatted address storage

#### `update($request, $uuid)`
**Purpose**: Update organization and subscription details

**Update Process**:
1. Update organization basic information
2. Handle subscription plan changes
3. Create subscription if none exists

```php
public function update($request, $uuid)
{
    $organization = Organization::where('uuid', $uuid)->firstOrFail();

    $organization->update([
        'name' => $request->input('name'),
        'address' => json_encode([
            'street' => $request->street,
            'city' => $request->city,
            'state' => $request->state,
            'zip' => $request->zip,
            'country' => $request->country,
        ]),
    ]);

    // Handle subscription updates
    $subscription = Subscription::where('organization_id', $organization->id)->first();
    $plan = SubscriptionPlan::where('uuid', $request->plan)->first();

    if($subscription) {
        $subscription->update(['plan_id' => $plan->id]);
    } else {
        // Create new subscription
        Subscription::create([...]);
    }

    return $organization;
}
```

#### `destroy($uuid)`
**Purpose**: Delete organization with cleanup of related data

**Deletion Process**:
```php
public function destroy($uuid)
{
    $organization = Organization::where('uuid', $uuid)->first();

    if ($organization) {
        // Delete all teams associated with the organization
        Team::where('organization_id', $organization->id)->delete();
        
        // Delete the organization
        $organization->delete();
        return true;
    }

    return false;
}
```

### Billing Management

#### `storeTransaction($request, $uuid)`
**Purpose**: Create billing transactions (credits, debits, payments)

**Transaction Types**:
1. **Credit**: Add funds to organization account
2. **Debit**: Charge organization account
3. **Payment**: Payment received from organization

**Implementation**:
```php
public function storeTransaction($request, $uuid)
{
    return DB::transaction(function () use ($request, $uuid) {
        $organization = Organization::where('uuid', $uuid)->firstOrFail();

        // Determine model class based on transaction type
        $modelClass = match ($request->type) {
            'credit' => BillingCredit::class,
            'debit' => BillingDebit::class,
            'payment' => BillingPayment::class,
        };

        // Create specific transaction record
        $entry = $modelClass::create([
            'organization_id' => $organization->id,
            'amount' => $request->amount,
            'description' => $request->description ?? null,
            'processor' => $request->method ?? null, // For payments
        ]);

        // Create general transaction log
        $transaction = BillingTransaction::create([
            'organization_id' => $organization->id,
            'entity_type' => $request->type,
            'entity_id' => $entry->id,
            'description' => $this->getTransactionDescription($request),
            'amount' => $request->amount,
            'created_by' => auth()->user()->id
        ]);

        return $transaction;
    });
}
```

### Organization Structure

#### Core Organization Fields
```php
$organization = [
    'uuid' => 'auto-generated',
    'name' => 'Acme Corp',
    'identifier' => '202412011234567ABCD', // Unique identifier
    'address' => json_encode([
        'street' => '123 Business Ave',
        'city' => 'New York',
        'state' => 'NY',
        'zip' => '10001',
        'country' => 'USA'
    ]),
    'metadata' => json_encode([
        'whatsapp' => [...],
        'timezone' => 'America/New_York',
        'campaigns' => [...],
        // ... other configuration
    ]),
    'created_by' => 1,
    'created_at' => '2024-01-01 00:00:00'
];
```

#### Team Relationships
```php
$team = [
    'organization_id' => 123,
    'user_id' => 456,
    'role' => 'owner', // owner, admin, agent, user
    'status' => 'active', // active, inactive
    'created_by' => 1
];
```

#### Subscription Structure
```php
$subscription = [
    'organization_id' => 123,
    'plan_id' => 1,
    'status' => 'active', // trial, active, inactive, expired
    'start_date' => '2024-01-01',
    'valid_until' => '2024-02-01',
    'created_at' => '2024-01-01 00:00:00'
];
```

### Usage Examples

#### Creating Organization with New User
```php
$orgService = new OrganizationService();

$request = (object) [
    'name' => 'New Business Inc',
    'create_user' => 1,
    'first_name' => 'John',
    'last_name' => 'Doe',
    'email' => 'john@newbusiness.com',
    'password' => 'secure_password',
    'phone' => '+1-555-123-4567',
    'street' => '123 Main St',
    'city' => 'Anytown',
    'state' => 'CA',
    'zip' => '90210',
    'country' => 'USA',
    'plan' => 'basic-plan-uuid'
];

$organization = $orgService->store($request);
```

#### Creating Organization with Existing User
```php
$request = (object) [
    'name' => 'Another Business LLC',
    'create_user' => 0,
    'email' => 'existing@user.com', // Must exist in system
    'street' => '456 Business Ave',
    'city' => 'Business City',
    'state' => 'NY',
    'zip' => '10001',
    'country' => 'USA',
    'plan' => 'premium-plan-uuid'
];

$organization = $orgService->store($request);
```

#### Adding Billing Transaction
```php
$request = (object) [
    'type' => 'credit',
    'amount' => 100.00,
    'description' => 'Account credit for promotional campaign'
];

$transaction = $orgService->storeTransaction($request, $organizationUuid);
```

### Integration Points
- **UserService**: User creation and management
- **SubscriptionService**: Subscription and billing logic
- **Team Management**: User-organization relationships
- **Multi-tenancy**: Organization context for all operations

### Best Practices
1. **Unique Identifiers**: Generate collision-resistant identifiers
2. **Address Storage**: Use structured JSON for international addresses
3. **Trial Management**: Configure trials via system settings
4. **Team Roles**: Define clear role hierarchies
5. **Billing Accuracy**: Use database transactions for financial operations
6. **Cleanup**: Proper cascade deletion for organization removal

---

## UserService - User Account Management

### Purpose
Comprehensive user account management with role-based access control and organization relationships.

### Class Overview
```php
class UserService
{
    private $organization;
    private $role;

    public function __construct($role) {
        $this->organizationService = new OrganizationService();
        $this->role = $role; // 'user' or admin-level role
    }
}
```

### Core Methods

#### `get(object $request)`
**Purpose**: Retrieve users filtered by role with search functionality

**Role-based Filtering**:
- **'user'**: Returns only standard users
- **Other roles**: Returns admin/staff users

```php
public function get(object $request)
{
    $users = (new User)->listAll($this->role, $request->query('search'));
    return UserResource::collection($users);
}
```

#### `getByUuid($request, $id = NULL)`
**Purpose**: Retrieve specific user with role-appropriate related data

**Return Structure**:
```php
// For user role
$result = [
    'user' => User::with('role')->find($id),
    'organizations' => $this->organizationService->get($request, $id)
];

// For admin roles
$result = [
    'user' => User::with('role')->find($id),
    'roles' => Role::all()
];
```

#### `store(Object $request)`
**Purpose**: Create new user with optional organization setup

**User Creation Flow**:
```php
public function store(Object $request)
{
    $role = $this->role === 'user' ? 'user' : $this->getRoleNameFromUuid($request->input('role'));

    return DB::transaction(function () use ($request, $role) {
        // 1. Handle avatar upload
        $avatarPath = null;
        if ($request->hasFile('avatar')) {
            $avatarPath = $request->file('avatar')->store('public');
        }

        // 2. Create user record
        $newUser = User::create([
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),
            'email' => $request->input('email'),
            'avatar' => $avatarPath,
            'role' => $role,
            'phone' => $request->input('phone') ? phone($request->input('phone'))->formatE164() : null,
            'address' => json_encode([
                'street' => $request->input('street'),
                'city' => $request->input('city'),
                'state' => $request->input('state'),
                'zip' => $request->input('zip'),
                'country' => $request->input('country'),
            ]),
            'password' => $request->input('password'),
        ]);

        // 3. Create organization for user role (if specified)
        if($this->role === 'user' && $request->has('organization_name')) {
            $this->createUserOrganization($newUser, $request);
        }

        // 4. Send registration email
        Email::send('Registration', $newUser);

        return $newUser;
    });
}
```

### Organization Creation for Users

#### `createUserOrganization($newUser, $request)`
**Purpose**: Automatically create organization when registering new users

**Process**:
```php
private function createUserOrganization($newUser, $request)
{
    $timestamp = now()->format('YmdHis');
    $randomString = Str::random(4);
    $userId = $newUser->id;
    $creatorId = auth()->check() ? auth()->user()->id : $newUser->id;

    // Create Organization
    $newOrganization = Organization::create([
        'identifier' => $timestamp . $userId . $randomString,
        'name' => $request->input('organization_name'),
        'created_by' => $creatorId
    ]);

    // Create Team relationship
    Team::create([
        'organization_id' => $newOrganization->id,
        'user_id' => $userId,
        'role' => 'owner',
        'status' => 'active',
        'created_by' => $creatorId
    ]);

    // Setup trial subscription
    $has_trial = $this->hasTrialPeriod();
    
    Subscription::create([
        'organization_id' => $newOrganization->id,
        'status' => $has_trial ? 'trial' : 'active',
        'plan_id' => null,
        'start_date' => now(),
        'valid_until' => $has_trial ? $this->getTrialEndDate() : now(),
    ]);
}
```

#### `update($request, $id)`
**Purpose**: Update existing user account

**Update Logic**:
```php
public function update($request, $id)
{
    $user = User::where('id', $id)->firstOrFail();
    
    // Preserve existing role for protected accounts
    $role = ($user->role === 'user' || $user->role === 'admin') 
        ? $user->role 
        : $this->getRoleNameFromUuid($request->input('role'));

    // Handle avatar upload
    $avatarPath = null;
    if ($request->hasFile('avatar')) {
        $avatarPath = $request->file('avatar')->store('avatars', 'public');
    }

    $user->update([
        'first_name' => $request->input('first_name'),
        'last_name' => $request->input('last_name'),
        'email' => $request->input('email'),
        'avatar' => $avatarPath,
        'role' => $role,
        'phone' => $request->input('phone') ? phone($request->input('phone'))->formatE164() : null,
        'address' => json_encode([
            'street' => $request->street,
            'city' => $request->city,
            'state' => $request->state,
            'zip' => $request->zip,
            'country' => $request->country,
        ]),
    ]);

    return $user;
}
```

### User Deletion with Organization Management

#### `destroy($id)`
**Purpose**: Delete user with automatic organization ownership transfer

**Deletion Process**:
```php
public function destroy($id)
{
    $user = User::where('id', $id)->first();

    if ($user) {
        $organizationId = $user->teams()->value('organization_id');
        $isOwner = $user->teams()->where('role', 'owner')->exists();
        
        // Soft delete the user
        $user->delete();

        if ($user->trashed() && $organizationId) {
            // Transfer ownership if user was owner
            if ($isOwner) {
                $nextUser = User::whereHas('teams', function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId);
                })->where('id', '!=', $user->id)->first();

                if ($nextUser) {
                    $team = $nextUser->teams()->where('organization_id', $organizationId)->first();
                    $team->role = 'owner';
                    $team->save();
                }
            }

            // Delete organization if no users remaining
            $userCount = User::whereHas('teams', function ($query) use ($organizationId) {
                $query->where('organization_id', $organizationId);
            })->where('id', '!=', $user->id)->count();

            if ($userCount === 0) {
                Organization::find($organizationId)->delete();
            }
        }
    }
}
```

### Role Management

#### `getRoleNameFromUuid($uuid)`
**Purpose**: Convert role UUID to role name for assignment

```php
private function getRoleNameFromUuid($uuid)
{
    $role = Role::where('uuid', $uuid)->value('name');
    return $role ?: 'user';
}
```

### User Data Structure

#### Core User Fields
```php
$user = [
    'id' => 123,
    'first_name' => 'John',
    'last_name' => 'Doe',
    'email' => 'john@example.com',
    'avatar' => '/storage/avatars/avatar.jpg',
    'role' => 'user', // or custom role name
    'phone' => '+1234567890', // E.164 format
    'address' => json_encode([
        'street' => '123 Main St',
        'city' => 'Anytown',
        'state' => 'CA',
        'zip' => '90210',
        'country' => 'USA'
    ]),
    'email_verified_at' => '2024-01-01 00:00:00',
    'created_at' => '2024-01-01 00:00:00',
    'updated_at' => '2024-01-01 00:00:00'
];
```

### Usage Examples

#### Creating Standard User
```php
$userService = new UserService('user');

$request = (object) [
    'first_name' => 'Jane',
    'last_name' => 'Smith',
    'email' => 'jane@example.com',
    'password' => 'secure_password',
    'phone' => '+1-555-987-6543',
    'street' => '456 Oak Ave',
    'city' => 'Sample City',
    'state' => 'TX',
    'zip' => '75001',
    'country' => 'USA'
];

$user = $userService->store($request);
```

#### Creating User with Organization
```php
$request = (object) [
    'first_name' => 'Bob',
    'last_name' => 'Johnson',
    'email' => 'bob@business.com',
    'password' => 'secure_password',
    'organization_name' => 'Johnson Enterprises',
    // ... other fields
];

$user = $userService->store($request);
```

#### Creating Admin User
```php
$userService = new UserService('admin');

$request = (object) [
    'first_name' => 'Admin',
    'last_name' => 'User',
    'email' => 'admin@system.com',
    'role' => 'admin-role-uuid',
    'password' => 'admin_password',
    // ... other fields
];

$user = $userService->store($request);
```

### Integration Points
- **OrganizationService**: Organization creation and management
- **RoleService**: Role-based access control
- **Email System**: Registration notifications
- **Team Management**: User-organization relationships
- **File Storage**: Avatar upload handling

### Best Practices
1. **Role Security**: Preserve critical roles during updates
2. **Phone Formatting**: Always use E.164 format for phone numbers
3. **Organization Ownership**: Ensure smooth ownership transfers
4. **File Handling**: Secure avatar upload and storage
5. **Email Verification**: Send appropriate notifications
6. **Data Cleanup**: Proper cascade deletion for user removal

---

## TicketService - Support System

### Purpose
Manages customer support ticket lifecycle including creation, assignment, status tracking, and comment management.

### Class Overview
```php
class TicketService
{
    // Handles support ticket operations
}
```

### Core Methods

#### `store(object $request)`
**Purpose**: Create new support ticket with automatic reference generation

**Reference Generation**:
- Format: `SUP-XXXXXX-YYMMDD`
- Example: `SUP-000001-241201`

**Implementation**:
```php
public function store(object $request)
{
    Ticket::create([
        'reference' => 'SUP-' . sprintf('%06d', Ticket::count() + 1) . '-' . now()->format('ymd'),
        'user_id' => auth()->user()->role === 'user' ? auth()->user()->id : $request->user,
        'category_id' => $request->category,
        'subject' => $request->subject,
        'message' => $request->message,
        'assigned_to' => auth()->user()->role === 'user' ? null : auth()->user()->id,
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now()
    ]);
}
```

**Assignment Logic**:
- **User-created tickets**: Not assigned initially
- **Admin-created tickets**: Assigned to creating admin

#### `assignTicket(object $request, $ticketUuid)`
**Purpose**: Assign ticket to specific agent

```php
public function assignTicket(object $request, $ticketUuid)
{
    $ticket = Ticket::where('uuid', $ticketUuid)->first();
    $ticket->update([
        'assigned_to' => $request->user,
        'updated_at' => now()
    ]);
}
```

#### `changeStatus(object $request, $ticketUuid)`
**Purpose**: Update ticket status

**Available Statuses**:
- **open**: Active ticket requiring attention
- **in_progress**: Agent working on ticket
- **pending**: Waiting for customer response
- **resolved**: Issue resolved, awaiting confirmation
- **closed**: Ticket completed

```php
public function changeStatus(object $request, $ticketUuid)
{
    $ticket = Ticket::where('uuid', $ticketUuid)->first();
    $ticket->update([
        'status' => $request->status,
        'updated_at' => now()
    ]);
}
```

#### `changePriority(object $request, $ticketUuid)`
**Purpose**: Update ticket priority level

**Priority Levels**:
- **low**: Non-urgent issues
- **medium**: Standard priority
- **high**: Important issues
- **urgent**: Critical issues requiring immediate attention

```php
public function changePriority(object $request, $ticketUuid)
{
    $ticket = Ticket::where('uuid', $ticketUuid)->first();
    $ticket->update([
        'priority' => $request->priority,
        'updated_at' => now()
    ]);
}
```

### Communication Management

#### `comment(object $request, $ticketUuid)`
**Purpose**: Add comment to ticket conversation

```php
public function comment(object $request, $ticketUuid)
{
    $ticket = Ticket::where('uuid', $ticketUuid)->first();
    TicketComment::create([
        'ticket_id' => $ticket->id,
        'user_id' => auth()->user()->id,
        'message' => $request->message,
        'created_at' => now(),
        'updated_at' => now()
    ]);
}
```

#### `markAsRead($ticketUuid)`
**Purpose**: Mark all ticket comments as read

```php
public function markAsRead($ticketUuid)
{
    $ticket = Ticket::where('uuid', $ticketUuid)->first();
    TicketComment::where('ticket_id', $ticket->id)->update([
        'seen' => 1
    ]);
}
```

### Ticket Data Structure

#### Core Ticket Fields
```php
$ticket = [
    'uuid' => 'auto-generated',
    'reference' => 'SUP-000123-241201',
    'user_id' => 456, // Ticket creator
    'category_id' => 1, // Support category
    'subject' => 'Unable to send messages',
    'message' => 'Detailed description of the issue...',
    'assigned_to' => 789, // Agent user ID
    'status' => 'open', // open, in_progress, pending, resolved, closed
    'priority' => 'medium', // low, medium, high, urgent
    'created_at' => '2024-01-01 00:00:00',
    'updated_at' => '2024-01-01 00:00:00'
];
```

#### Ticket Comment Structure
```php
$comment = [
    'id' => 123,
    'ticket_id' => 456,
    'user_id' => 789,
    'message' => 'Comment content...',
    'seen' => 0, // 0 = unread, 1 = read
    'created_at' => '2024-01-01 00:00:00',
    'updated_at' => '2024-01-01 00:00:00'
];
```

#### Ticket Category Structure
```php
$category = [
    'id' => 1,
    'name' => 'Technical Support',
    'description' => 'Technical issues and bugs',
    'color' => '#FF5722', // Display color
    'created_at' => '2024-01-01 00:00:00'
];
```

### Ticket Workflow

#### Standard Ticket Lifecycle
```
Created (open) → Assigned → In Progress → Resolved → Closed
                    ↓
              Can be reassigned
                    ↓
              Priority can be changed
                    ↓
              Comments can be added
```

#### Status Transitions
- **open** → **in_progress**: Agent starts working
- **in_progress** → **pending**: Waiting for customer response
- **pending** → **in_progress**: Customer responded
- **in_progress** → **resolved**: Issue fixed
- **resolved** → **closed**: Customer confirms resolution
- **Any status** → **open**: Reopened for further work

### Usage Examples

#### Creating Support Ticket
```php
$ticketService = new TicketService();

$request = (object) [
    'user' => 123, // For admin-created tickets
    'category' => 1,
    'subject' => 'WhatsApp Integration Issue',
    'message' => 'Unable to connect WhatsApp Business Account. Getting error: "Invalid access token".'
];

$ticketService->store($request);
```

#### Managing Ticket Lifecycle
```php
// Assign to agent
$ticketService->assignTicket((object)['user' => 456], $ticketUuid);

// Change priority
$ticketService->changePriority((object)['priority' => 'high'], $ticketUuid);

// Update status
$ticketService->changeStatus((object)['status' => 'in_progress'], $ticketUuid);

// Add comment
$ticketService->comment((object)['message' => 'Checking API credentials...'], $ticketUuid);

// Mark as read
$ticketService->markAsRead($ticketUuid);
```

### Integration Points
- **User Management**: Ticket assignment to agents
- **Categories**: Organized support topics
- **Chat System**: Integration with ChatTicket for conversation-based support

---

## PhoneService - Phone Number Handling

### Purpose
Provides comprehensive phone number validation, formatting, and type detection with special handling for Brazilian numbers.

### Class Overview
```php
class PhoneService
{
    // Static methods for phone number operations
}
```

### Core Methods

#### `validateAndFormat($phoneNumber)`
**Purpose**: Primary method for phone number validation and formatting

**Return Structure**:
```php
return [
    'is_valid' => true,
    'formatted' => '+5511999887766',
    'error' => null,
    'type' => 'mobile'
];
```

**Implementation Logic**:
```php
public static function validateAndFormat($phoneNumber)
{
    // Check if Brazilian number (special handling)
    if (BrazilPhoneHelper::isBrazilianNumber($phoneNumber)) {
        $validation = BrazilPhoneHelper::validateBrazilPhone($phoneNumber);
        
        if ($validation['is_valid']) {
            return [
                'is_valid' => true,
                'formatted' => $validation['formatted'],
                'error' => null,
                'type' => BrazilPhoneHelper::getPhoneType($phoneNumber)
            ];
        } else {
            return [
                'is_valid' => false,
                'formatted' => null,
                'error' => $validation['error'],
                'type' => 'invalid'
            ];
        }
    }

    // Use libphonenumber for international numbers
    try {
        $phone = new PhoneNumber($phoneNumber);
        
        if ($phone->isValid()) {
            return [
                'is_valid' => true,
                'formatted' => $phone->formatE164(),
                'error' => null,
                'type' => 'international'
            ];
        }
    } catch (\Exception $e) {
        return [
            'is_valid' => false,
            'formatted' => null,
            'error' => 'Invalid phone number format',
            'type' => 'invalid'
        ];
    }
}
```

#### `getE164Format($phoneNumber)`
**Purpose**: Get standardized E.164 format for storage

**Usage**: Primary method for database storage
```php
public static function getE164Format($phoneNumber)
{
    $validation = self::validateAndFormat($phoneNumber);
    return $validation['is_valid'] ? $validation['formatted'] : null;
}
```

#### `formatForDisplay($phoneNumber)`
**Purpose**: Format phone number for user-friendly display

**Implementation**:
```php
public static function formatForDisplay($phoneNumber)
{
    if (BrazilPhoneHelper::isBrazilianNumber($phoneNumber)) {
        return BrazilPhoneHelper::formatBrazilPhone($phoneNumber);
    }

    try {
        $phone = new PhoneNumber($phoneNumber);
        return $phone->formatInternational(); // +1 555-123-4567 format
    } catch (\Exception $e) {
        return $phoneNumber; // Return original if formatting fails
    }
}
```

### Helper Methods

#### `isValid($phoneNumber)`
**Purpose**: Simple validation check

```php
public static function isValid($phoneNumber)
{
    $validation = self::validateAndFormat($phoneNumber);
    return $validation['is_valid'];
}
```

#### `getType($phoneNumber)`
**Purpose**: Determine phone number type

**Types**:
- **mobile**: Mobile/cellular numbers
- **landline**: Fixed-line numbers
- **voip**: VoIP numbers
- **international**: General international numbers
- **invalid**: Invalid numbers

#### `normalize($phoneNumber)`
**Purpose**: Ensure phone number starts with + prefix

```php
public static function normalize($phoneNumber)
{
    if (!str_starts_with($phoneNumber, '+')) {
        $phoneNumber = '+' . $phoneNumber;
    }
    return $phoneNumber;
}
```

### Brazilian Number Handling

The service includes special handling for Brazilian numbers via `BrazilPhoneHelper`:

#### Brazilian Number Features
- **Mobile Numbers**: 11 digits (including area code)
- **Landline Numbers**: 10 digits (including area code)
- **Special Validation**: Brazil-specific number validation rules
- **Proper Formatting**: Brazilian standard formatting

### Usage Examples

#### Basic Validation
```php
$phoneNumber = '+1-555-123-4567';
$result = PhoneService::validateAndFormat($phoneNumber);

if ($result['is_valid']) {
    echo "Valid: " . $result['formatted']; // +15551234567
    echo "Type: " . $result['type']; // international
} else {
    echo "Error: " . $result['error'];
}
```

#### Storage Format
```php
$userInput = '(555) 123-4567';
$storageFormat = PhoneService::getE164Format($userInput);
// Store $storageFormat in database: +15551234567
```

#### Display Format
```php
$storedNumber = '+15551234567';
$displayFormat = PhoneService::formatForDisplay($storedNumber);
// Display to user: +1 555-123-4567
```

#### Integration in ContactService
```php
// In ContactService::store()
$contact->phone = PhoneService::getE164Format($request->phone);

// In ContactService::updatePartial()
if ($field === 'phone') {
    $contact->phone = PhoneService::getE164Format($value);
}
```

### Integration Points
- **ContactService**: Contact phone number handling
- **UserService**: User phone number formatting
- **BrazilPhoneHelper**: Brazil-specific validation
- **LibPhoneNumber**: International number validation

### Best Practices
1. **Always Validate**: Use validateAndFormat() for comprehensive checks
2. **Store E.164**: Always store phone numbers in E.164 format
3. **Display Formatted**: Use formatForDisplay() for user interfaces
4. **Handle Errors**: Check validation results before processing
5. **Regional Support**: Leverage Brazilian helper for Brazil-specific needs

---

## MediaService - File Management

### Purpose
Handles file uploads with configurable storage options (local or AWS S3).

### Class Overview
```php
class MediaService
{
    // Static methods for media operations
}
```

### Core Methods

#### `upload($image)`
**Purpose**: Upload media files to configured storage system

**Configuration Check**:
```php
public static function upload($image)
{
    if(config('settings.use_s3_as_storage', false)) {
        $path = $image->storePublicly('uploads/media/send/'.$contact->company_id, 's3');
        $imageUrl = Storage::disk('s3')->url($path);
    } else {
        $path = $image->store(null, 'public');
        $imageUrl = Storage::disk('public')->url($path);
    }

    $name = basename($path);
    return ['name' => $name, 'path' => $imageUrl];
}
```

**Return Format**:
```php
return [
    'name' => 'uploaded_file.jpg',
    'path' => 'https://s3.amazonaws.com/bucket/path/to/file.jpg'
];
```

### Storage Configuration

#### S3 Storage
- **Path Structure**: `uploads/media/send/{company_id}/`
- **Public Access**: Files stored with public visibility
- **URL Generation**: Full S3 URLs returned

#### Local Storage
- **Disk**: Laravel's public disk
- **Path Structure**: Dynamic path generation
- **URL Generation**: Local filesystem URLs

### Usage Examples

#### Basic File Upload
```php
$uploadedFile = $request->file('image');
$result = MediaService::upload($uploadedFile);

echo $result['name']; // filename.jpg
echo $result['path']; // Full URL to file
```

### Integration Points
- **Storage Configuration**: Laravel filesystem configuration
- **S3 Integration**: AWS S3 bucket configuration
- **File Management**: Various services use this for uploads

### Note
This service appears to be a simplified version. The main file handling logic is implemented directly in other services (ContactService, ChatService, etc.) with more sophisticated storage options.

---

## RoleService - Access Control

### Purpose
Manages role-based access control system with dynamic permission assignment.

### Class Overview
```php
class RoleService
{
    // Role and permission management
}
```

### Core Methods

#### `get(object $request)`
**Purpose**: Retrieve roles with search functionality

```php
public function get(object $request)
{
    $roles = (new Role)->listAll($request->query('search'));
    return RoleResource::collection($roles);
}
```

#### `getByUuid($uuid)`
**Purpose**: Retrieve role details with permissions and available modules

**Return Structure**:
```php
return [
    'role' => Role::where('uuid', $uuid)->first(),
    'modules' => Module::all(), // Available system modules
    'permissions' => RolePermission::where('role_id', $role->id)->get()
];
```

#### `store(Object $request)`
**Purpose**: Create new role with permission assignments

**Permission Structure**:
```php
$permissions = [
    'users' => [
        'create' => true,
        'read' => true,
        'update' => false,
        'delete' => false
    ],
    'campaigns' => [
        'create' => true,
        'read' => true,
        'update' => true,
        'delete' => true
    ]
];
```

**Implementation**:
```php
public function store(Object $request)
{
    return DB::transaction(function () use ($request) {
        $newRole = Role::create([
            'name' => $request->input('name'),
        ]);

        $permissions = $request->input('permissions', []);

        foreach ($permissions as $module => $actions) {
            foreach ($actions as $action => $value) {
                if ($value) {
                    RolePermission::create([
                        'role_id' => $newRole->id,
                        'module' => $module,
                        'action' => $action,
                    ]);
                }
            }
        }

        return $newRole;
    });
}
```

#### `update($request, $uuid)`
**Purpose**: Update role and permissions

**Process**:
1. Update role name
2. Delete existing permissions
3. Recreate permissions from request

#### `destroy($request, $uuid)`
**Purpose**: Delete role with user reassignment

**Safety Process**:
```php
public function destroy($request, $uuid)
{
    $role = Role::where('uuid', $uuid)->firstOrFail();
    $usersToUpdate = User::where('role', $role->name)->get();
    $newRole = Role::where('uuid', $request->input('new_role'))->firstOrFail();

    // Update affected users to new role
    foreach ($usersToUpdate as $user) {
        $user->update(['role' => $newRole->name]);
    }

    // Delete the role and permissions
    $role->delete();

    return response()->json(['message' => 'Role deleted successfully']);
}
```

### Permission System

#### Module-Action Structure
```php
$permissionStructure = [
    'module_name' => [
        'create' => boolean,
        'read' => boolean,
        'update' => boolean,
        'delete' => boolean,
        // Custom actions as needed
    ]
];
```

#### Available Modules (Examples)
- **users**: User management
- **contacts**: Contact management
- **campaigns**: Campaign operations
- **templates**: Template management
- **chats**: Chat/messaging
- **reports**: Analytics and reports
- **settings**: System configuration

### Data Structure

#### Role Structure
```php
$role = [
    'uuid' => 'auto-generated',
    'name' => 'Campaign Manager',
    'created_at' => '2024-01-01 00:00:00',
    'updated_at' => '2024-01-01 00:00:00'
];
```

#### Role Permission Structure
```php
$permission = [
    'id' => 123,
    'role_id' => 456,
    'module' => 'campaigns',
    'action' => 'create'
];
```

### Usage Examples

#### Creating Role with Permissions
```php
$roleService = new RoleService();

$request = (object) [
    'name' => 'Marketing Manager',
    'permissions' => [
        'campaigns' => [
            'create' => true,
            'read' => true,
            'update' => true,
            'delete' => false
        ],
        'contacts' => [
            'create' => true,
            'read' => true,
            'update' => true,
            'delete' => false
        ],
        'templates' => [
            'create' => false,
            'read' => true,
            'update' => false,
            'delete' => false
        ]
    ]
];

$role = $roleService->store($request);
```

### Integration Points
- **UserService**: Role assignment to users
- **Authorization Middleware**: Permission checking
- **UI Components**: Role-based feature visibility

---

## UnsubscribeChecker - Subscription Management

### Purpose
Monitors incoming messages for unsubscribe triggers and automatically updates contact subscription status.

### Class Overview
```php
class UnsubscribeChecker
{
    // Handles automatic unsubscription logic
}
```

### Core Methods

#### `check(?Organization $organization = null, ?string $messageText = null, ?int $contactId = null): bool`
**Purpose**: Check message for unsubscribe triggers and process accordingly

**Parameters**:
- `$organization`: Organization object with metadata
- `$messageText`: Incoming message content
- `$contactId`: Contact ID to unsubscribe

**Implementation Logic**:
```php
public function check(?Organization $organization = null, ?string $messageText = null, ?int $contactId = null): bool
{
    // Early returns for invalid input
    if (!$organization || empty($messageText)) {
        return false;
    }

    $contactService = new ContactService($organization->id);

    // Get unsubscribe triggers from organization metadata
    $metadataArray = $organization->metadata ? json_decode($organization->metadata, true) : [];
    $triggers = $metadataArray['campaign_settings']['unsubscribe_trigger'] ?? null;

    if (!$triggers) {
        return false;
    }

    // Process trigger matching
    $triggerWords = array_map('strtolower', preg_split('/\s*,\s*/', $triggers, -1, PREG_SPLIT_NO_EMPTY));
    $messageCleaned = strtolower(trim($messageText));

    // Check for exact match
    if (in_array($messageCleaned, $triggerWords, true)) {
        if ($contactId) {
            $contact = Contact::find($contactId);
            if ($contact) {
                $contactService->updatePartial($contactId, ['is_subscribed' => 0]);
            }
        }
        return true;
    }

    return false;
}
```

### Trigger Configuration

#### Organization Metadata Structure
```json
{
  "campaign_settings": {
    "unsubscribe_trigger": "stop,unsubscribe,remove,optout"
  }
}
```

#### Trigger Processing
1. **Split by Comma**: Handles comma-separated trigger words
2. **Case Insensitive**: Converts to lowercase for matching
3. **Exact Match**: Requires exact word match (not substring)
4. **Trim Whitespace**: Removes leading/trailing spaces

### Usage Examples

#### Basic Usage
```php
$checker = new UnsubscribeChecker();
$organization = Organization::find(123);
$messageText = "STOP";
$contactId = 456;

$wasUnsubscribed = $checker->check($organization, $messageText, $contactId);

if ($wasUnsubscribed) {
    echo "Contact has been unsubscribed";
}
```

#### Integration in Message Processing
```php
// In webhook processing
$organization = Organization::find($organizationId);
$messageText = $incomingMessage['text'];
$contactId = $contact->id;

// Check for unsubscribe before processing auto-replies
if (UnsubscribeChecker::check($organization, $messageText, $contactId)) {
    // Contact unsubscribed, don't send marketing messages
    return;
}

// Continue with normal message processing
```

### Integration Points
- **ContactService**: Updates subscription status
- **Webhook Processing**: Checks incoming messages
- **Campaign System**: Respects subscription status
- **Organization Settings**: Configurable trigger words

### Best Practices
1. **Clear Triggers**: Use common unsubscribe words
2. **Multiple Options**: Provide various unsubscribe terms
3. **Case Insensitive**: Handle different capitalizations
4. **Immediate Processing**: Update subscription status instantly
5. **Respect Status**: Honor unsubscribe in all messaging

---

## ChatNoteService - Note Management

### Purpose
Manages notes attached to customer contacts for agent reference and customer history tracking.

### Class Overview
```php
class ChatNoteService
{
    // Handles contact note operations
}
```

### Core Methods

#### `get(object $request)`
**Purpose**: Retrieve notes with search functionality

```php
public function get(object $request)
{
    $rows = (new ChatNote)->listAll($request->query('search'));
    return ChatNoteResource::collection($rows);
}
```

#### `getByUuid($uuid = null)`
**Purpose**: Retrieve specific note by UUID

```php
public function getByUuid($uuid = null)
{
    return ChatNote::where('id', $uuid)->first();
}
```

#### `store(object $request, $uuid = NULL)`
**Purpose**: Create or update contact notes with activity logging

**Implementation**:
```php
public function store(object $request, $uuid = NULL)
{
    $contact = Contact::where('uuid', $request->contact)->first();

    $note = $uuid === null ? new ChatNote() : ChatNote::where('uuid', $uuid)->firstOrFail();
    $note->contact_id = $contact->id;
    $note->content = $request->notes;
    $note->created_by = auth()->user()->id;
    $note->save();

    // Create activity log entry
    ChatLog::insert([
        'contact_id' => $contact->id,
        'entity_type' => 'notes',
        'entity_id' => $note->id,
        'created_at' => now()
    ]);

    return $note;
}
```

#### `delete($uuid)`
**Purpose**: Soft delete note with audit trail

```php
public function delete($uuid)
{
    $note = ChatNote::where('uuid', $uuid)->firstOrFail();
    $note->deleted_at = date('Y-m-d H:i:s');
    $note->deleted_by = auth()->user()->id;
    $note->save();
}
```

### Note Data Structure

#### ChatNote Structure
```php
$note = [
    'uuid' => 'auto-generated',
    'contact_id' => 123,
    'content' => 'Customer prefers email communication. Very responsive to promotional offers.',
    'created_by' => 456,
    'created_at' => '2024-01-01 00:00:00',
    'updated_at' => '2024-01-01 00:00:00',
    'deleted_at' => null,
    'deleted_by' => null
];
```

### Activity Logging

Notes are automatically logged in the chat timeline via ChatLog:

```php
$chatLog = [
    'contact_id' => 123,
    'entity_type' => 'notes',
    'entity_id' => 456, // Note ID
    'created_at' => '2024-01-01 00:00:00'
];
```

### Usage Examples

#### Creating Contact Note
```php
$noteService = new ChatNoteService();

$request = (object) [
    'contact' => $contactUuid,
    'notes' => 'Customer interested in premium plan. Follow up next week about upgrade options.'
];

$note = $noteService->store($request);
```

#### Updating Existing Note
```php
$request = (object) [
    'contact' => $contactUuid,
    'notes' => 'Updated: Customer purchased premium plan. Very satisfied with service.'
];

$updatedNote = $noteService->store($request, $noteUuid);
```

### Integration Points
- **Contact Management**: Notes attached to specific contacts
- **Chat Timeline**: Notes appear in conversation history
- **User Tracking**: Audit trail of note creators
- **Search**: Notes searchable for quick reference

---

## Integration Patterns and Best Practices

### Service Integration Patterns

#### 1. Organization Context Pattern
Most services require organization context for multi-tenancy:

```php
// Service instantiation with organization context
$chatService = new ChatService($organizationId);
$contactService = new ContactService($organizationId);
$templateService = new TemplateService($organizationId);

// Access organization context from session
$organizationId = session()->get('current_organization');
```

#### 2. WhatsApp Service Integration
Many services integrate with WhatsappService for API calls:

```php
// Initialize WhatsApp service within other services
private function initializeWhatsappService()
{
    $config = Organization::where('id', $this->organizationId)->first()->metadata;
    $config = $config ? json_decode($config, true) : [];

    $this->whatsappService = new WhatsappService(
        $config['whatsapp']['access_token'],
        config('graph.api_version'),
        $config['whatsapp']['app_id'],
        $config['whatsapp']['phone_number_id'],
        $config['whatsapp']['waba_id'],
        $this->organizationId
    );
}
```

#### 3. Webhook Integration Pattern
Services trigger webhooks for external system integration:

```php
// Trigger webhook after significant events
WebhookHelper::triggerWebhookEvent(
    'contact.created', 
    $cleanedData, 
    $organizationId
);
```

#### 4. Queue Integration Pattern
Heavy operations are queued for background processing:

```php
// Dispatch jobs for async processing
CreateCampaignLogsJob::dispatch()->onQueue('campaign-logs');
ProcessCampaignMessagesJob::dispatch()->onQueue('campaign-messages');
```

### Data Consistency Patterns

#### 1. Database Transactions
Critical operations use database transactions:

```php
DB::transaction(function () use ($data) {
    // Multiple related database operations
    $record1 = Model1::create($data1);
    $record2 = Model2::create($data2);
    $record3 = Model3::create($data3);
});
```

#### 2. Soft Delete Pattern
Most models use soft deletion for audit trails:

```php
$model->update([
    'deleted_by' => auth()->user()->id,
    'deleted_at' => now()
]);
```

#### 3. UUID External Identifiers
Public-facing APIs use UUIDs instead of database IDs:

```php
$record = Model::where('uuid', $uuid)->firstOrFail();
```

### Error Handling Patterns

#### 1. Service-level Error Handling
```php
try {
    $result = $this->performOperation($data);
    return $this->successResponse($result);
} catch (\Exception $e) {
    Log::error('Operation failed', [
        'error' => $e->getMessage(),
        'data' => $data,
        'trace' => $e->getTraceAsString()
    ]);
    throw $e;
}
```

#### 2. API Response Standardization
```php
// Success response
return response()->json([
    'success' => true,
    'data' => $data,
    'message' => 'Operation completed successfully'
]);

// Error response
return response()->json([
    'success' => false,
    'message' => 'Operation failed',
    'errors' => $validationErrors
]);
```

### Performance Optimization Patterns

#### 1. Eager Loading
```php
$contacts = Contact::with(['contactGroups', 'lastChat', 'labels'])
    ->where('organization_id', $organizationId)
    ->get();
```

#### 2. Chunked Processing
```php
Contact::where('organization_id', $organizationId)
    ->chunk(1000, function ($contacts) {
        foreach ($contacts as $contact) {
            $this->processContact($contact);
        }
    });
```

#### 3. Caching Organization Settings
```php
$config = cache()->remember("organization.{$organizationId}.metadata", 3600, function() {
    return Organization::find($organizationId)->metadata;
});
```

### Security Patterns

#### 1. Input Sanitization
```php
$requestData['text']['body'] = clean($messageContent);
```

#### 2. Phone Number Validation
```php
$contact->phone = PhoneService::getE164Format($request->phone);
```

#### 3. File Upload Security
```php
if($request->hasFile('file')) {
    $validator = Validator::make($request->all(), [
        'file' => 'required|file|max:10240|mimes:jpg,png,pdf'
    ]);
}
```

---

## Troubleshooting Guide

### Common Issues and Solutions

#### 1. WhatsApp API Issues

**Problem**: Messages not sending
```php
// Check organization WhatsApp configuration
$org = Organization::find($organizationId);
$config = json_decode($org->metadata, true);
$whatsappConfig = $config['whatsapp'] ?? [];

// Verify required fields
$required = ['access_token', 'phone_number_id', 'waba_id', 'app_id'];
foreach ($required as $field) {
    if (empty($whatsappConfig[$field])) {
        throw new Exception("Missing WhatsApp config: {$field}");
    }
}
```

**Problem**: Template not found
```php
// Sync templates from WhatsApp
$whatsappService->syncTemplates();

// Check template status
$template = Template::where('name', $templateName)
    ->where('organization_id', $orgId)
    ->where('status', 'APPROVED')
    ->first();
```

#### 2. Campaign Issues

**Problem**: Campaigns not processing
```php
// Check queue workers
php artisan queue:work --queue=campaign-messages,campaign-logs

// Check failed jobs
php artisan queue:failed

// Check campaign logs
$logs = CampaignLog::where('campaign_id', $campaignId)
    ->where('status', 'failed')
    ->with('campaign', 'contact')
    ->get();
```

**Problem**: Messages stuck in pending
```php
// Check for locked logs
CampaignLog::where('status', 'ongoing')
    ->where('updated_at', '<', now()->subMinutes(10))
    ->update(['status' => 'pending']);
```

#### 3. Contact Issues

**Problem**: Phone number validation errors
```php
$validation = PhoneService::validateAndFormat($phoneNumber);
if (!$validation['is_valid']) {
    Log::error('Invalid phone number', [
        'number' => $phoneNumber,
        'error' => $validation['error']
    ]);
}
```

#### 4. Storage Issues

**Problem**: File upload failures
```php
// Check storage configuration
$storage = Setting::where('key', 'storage_system')->first()->value;

if ($storage === 'aws') {
    // Check S3 configuration
    $s3Config = config('filesystems.disks.s3');
    // Verify key, secret, region, bucket
} else {
    // Check local storage permissions
    $path = storage_path('app/public');
    if (!is_writable($path)) {
        throw new Exception('Storage path not writable: ' . $path);
    }
}
```

### Debug Commands

#### Queue Management
```bash
# Monitor queue status
php artisan queue:monitor

# Process specific queue
php artisan queue:work --queue=campaign-messages --timeout=300

# Clear failed jobs
php artisan queue:flush

# Restart queue workers
php artisan queue:restart
```

#### Database Queries
```php
// Enable query logging
DB::enableQueryLog();

// Your service operations here

// View executed queries
$queries = DB::getQueryLog();
dd($queries);
```

#### Cache Management
```bash
# Clear application cache
php artisan cache:clear

# Clear config cache
php artisan config:clear

# Clear route cache
php artisan route:clear
```

### Monitoring and Logging

#### Key Log Channels
- **webhook**: Incoming webhook processing
- **campaign**: Campaign processing logs  
- **whatsapp**: WhatsApp API interactions
- **laravel**: General application logs

#### Important Metrics to Monitor
1. **Queue Depth**: Jobs waiting to be processed
2. **Failed Job Rate**: Percentage of failed vs successful jobs
3. **WhatsApp API Limits**: Rate limiting and quota usage
4. **Database Performance**: Slow query monitoring
5. **Storage Usage**: File upload storage consumption

This comprehensive documentation provides detailed coverage of all services in the WhatsApp Business Platform, including their purposes, methods, integrations, and practical usage examples. The guide serves both junior and senior developers with clear explanations, code examples, and troubleshooting information.