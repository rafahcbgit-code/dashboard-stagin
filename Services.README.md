# WhatsApp Business Platform - Complete Codebase Documentation

## Table of Contents
1. [Project Overview](#project-overview)
2. [Architecture Overview](#architecture-overview)
3. [Core Services Breakdown](#core-services-breakdown)
4. [Data Flow Diagrams](#data-flow-diagrams)
5. [Service Dependencies](#service-dependencies)
6. [Common Patterns](#common-patterns)
7. [Database Relationships](#database-relationships)
8. [API Integration Points](#api-integration-points)
9. [Configuration & Settings](#configuration--settings)
10. [Junior Developer Guide](#junior-developer-guide)

---

## Project Overview

This is a **WhatsApp Business Platform** built with Laravel that enables organizations to manage WhatsApp communications, campaigns, contacts, and customer support through a centralized system.

### Core Features
- **Multi-tenant**: Multiple organizations with isolated data
- **WhatsApp Integration**: Send/receive messages, templates, media
- **Campaign Management**: Bulk messaging with scheduling and retries
- **Contact Management**: CRM-like contact organization with groups
- **Chat Management**: Real-time messaging with ticketing system
- **Auto-replies**: Automated responses with AI integration
- **Template Management**: WhatsApp template creation and approval
- **User Management**: Role-based access control

### Tech Stack
- **Backend**: Laravel PHP Framework
- **Queue System**: Redis/Database queues for async processing
- **Storage**: Local or AWS S3 for media files
- **Real-time**: Pusher for live chat updates
- **External APIs**: WhatsApp Business API (Meta Graph API)

---

## Architecture Overview

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Frontend      │    │   Laravel API   │    │   WhatsApp API  │
│   (Inertia/Vue) │────│   Controllers   │────│   (Meta Graph)  │
└─────────────────┘    └─────────────────┘    └─────────────────┘
                                │
                       ┌────────┴────────┐
                       │                 │
                ┌──────▼──────┐   ┌──────▼──────┐
                │  Services   │   │   Queue     │
                │  Layer      │   │   Jobs      │
                └─────────────┘   └─────────────┘
                       │                 │
                ┌──────▼──────┐   ┌──────▼──────┐
                │  Database   │   │  Storage    │
                │  (MySQL)    │   │ (S3/Local)  │
                └─────────────┘   └─────────────┘
```

### Key Architectural Principles
1. **Service Layer Pattern**: Business logic separated into service classes
2. **Queue-based Processing**: Heavy operations run in background
3. **Multi-tenancy**: Organization-based data isolation
4. **Event-driven**: Real-time updates via events
5. **API Integration**: External WhatsApp API abstraction

---

## Core Services Breakdown

### 1. AuthService
**Purpose**: Handles user authentication and session management

**Key Methods**:
- `authenticateSession()`: Manages login for different user roles

**Important Notes**:
- Supports two user types: 'admin' and 'user'
- Sets current organization in session for users
- Uses different auth guards for admin vs user

### 2. WhatsappService
**Purpose**: Central hub for all WhatsApp API interactions

**Key Responsibilities**:
- Send messages (text, media, templates)
- Manage WhatsApp templates (create, update, delete)
- Handle WhatsApp business profile
- Upload media files to WhatsApp
- Health checks and subscription management

**Core Methods**:
```php
sendMessage()           // Send text/interactive messages
sendTemplateMessage()   // Send approved templates
sendMedia()            // Send images/documents/audio
createTemplate()       // Create new WhatsApp template
syncTemplates()        // Sync templates from WhatsApp
```

**Configuration**: Uses organization metadata for API credentials
- Access token, App ID, Phone Number ID, WABA ID per organization

### 3. CampaignService
**Purpose**: Manages bulk messaging campaigns

**Workflow**:
1. User creates campaign with template and target contacts
2. Campaign scheduled for specific time
3. Background jobs process and send messages
4. Tracks delivery status and handles retries

**Key Features**:
- Template-based messaging
- Contact group targeting
- Media attachment support
- Timezone-aware scheduling

### 4. ChatService
**Purpose**: Real-time chat management and conversation handling

**Core Features**:
- Live chat interface with pagination
- Message sending and receiving
- Template message sending
- Ticket management integration
- Contact assignment and status tracking

**Key Methods**:
```php
getChatList()           // Load chat conversations
sendMessage()           // Send outbound messages
sendTemplateMessage()   // Send template via chat
handleTicketAssignment() // Auto-assign to agents
```

### 5. ContactService
**Purpose**: Contact relationship management

**Features**:
- Contact CRUD operations
- Group management
- Phone number validation
- File upload (avatars)
- Bulk operations
- Webhook integrations

**Data Management**:
- Stores contact metadata as JSON
- Handles address information
- Manages subscription status
- Webhook notifications for contact events

### 6. AutoReplyService
**Purpose**: Automated message responses

**Response Sequence**:
1. **Basic Replies**: Keyword-based auto-responses
2. **Automated Flows**: Complex conversation flows (if module enabled)
3. **AI Replies**: AI-powered responses (if module enabled)

**Matching Logic**:
- Exact match vs contains matching
- Arabic text support (case-sensitive)
- English text (case-insensitive)
- Placeholder replacement in responses

### 7. TemplateService
**Purpose**: WhatsApp template management

**Template Lifecycle**:
1. Create template via WhatsApp API
2. Template pending approval
3. Meta approves/rejects template
4. Sync status back to database

**Features**:
- Template creation with components (header, body, footer, buttons)
- Media upload for template headers
- Template synchronization
- Status tracking (PENDING, APPROVED, REJECTED)

### 8. OrganizationService
**Purpose**: Multi-tenant organization management

**Responsibilities**:
- Organization CRUD
- User-organization relationships (teams)
- Subscription management
- Billing transaction handling

### 9. UserService
**Purpose**: User account management

**Features**:
- User creation with role assignment
- Organization assignment
- Email notifications
- Profile management

### 10. TicketService
**Purpose**: Support ticket management

**Workflow**:
- Create tickets for support requests
- Assign to agents
- Status tracking (open/closed)
- Comment system
- Priority levels

---

## Data Flow Diagrams

### Campaign Message Flow
```
User Creates Campaign
        ↓
CreateCampaignLogsJob (scheduled)
        ↓
Individual CampaignLog records created
        ↓
ProcessCampaignMessagesJob (every minute)
        ↓
ProcessSingleCampaignLogJob (batched)
        ↓
WhatsApp API Call
        ↓
Success/Failure → RetryCampaignLogJob (if failed)
        ↓
Campaign Completion Check
```

### Incoming Message Flow
```
WhatsApp Webhook
        ↓
ContactService (find/create contact)
        ↓
ChatService (create chat record)
        ↓
AutoReplyService (check for auto-reply)
        ↓
Basic Replies → Automated Flows → AI Reply
        ↓
Response sent back to WhatsApp
```

### Template Management Flow
```
User Creates Template
        ↓
WhatsappService.createTemplate()
        ↓
Template sent to Meta for approval
        ↓
WhatsappService.syncTemplates() (periodic)
        ↓
Template status updated in database
```

---

## Service Dependencies

### Service Dependency Map
```
AuthService
├── No dependencies

WhatsappService
├── Organization (config)
├── Template (metadata)
├── Contact (phone numbers)
├── Chat (message storage)
├── ChatMedia (file handling)

CampaignService
├── WhatsappService
├── Template
├── Contact/ContactGroup
├── Campaign/CampaignLog

ChatService
├── WhatsappService
├── Contact
├── Template
├── ChatTicket

ContactService
├── PhoneService
├── WebhookHelper

AutoReplyService
├── WhatsappService
├── Contact
├── Organization
├── FlowBuilder (optional module)
├── IntelliReply (optional module)

TemplateService
├── WhatsappService
├── Organization

OrganizationService
├── User
├── Team
├── Subscription

UserService
├── OrganizationService
├── Role

TicketService
├── Ticket
├── TicketCategory
├── TicketComment
```

---

## Common Patterns

### 1. Service Layer Pattern
Each service handles specific business domain:
```php
class ContactService
{
    private $organizationId;
    
    public function __construct($organizationId)
    {
        $this->organizationId = $organizationId;
    }
    
    public function store($request, $uuid = null)
    {
        // Business logic here
    }
}
```

### 2. Organization Context Pattern
Most services are organization-scoped:
```php
// Service instantiation with organization
$chatService = new ChatService($organizationId);
$contactService = new ContactService($organizationId);
```

### 3. UUID Pattern
External identifiers use UUIDs for security:
```php
// Find by UUID instead of ID
$contact = Contact::where('uuid', $uuid)->first();
```

### 4. Metadata JSON Pattern
Flexible data storage in JSON columns:
```php
$organization->metadata = json_encode([
    'whatsapp' => ['access_token' => '...'],
    'timezone' => 'UTC',
    'campaigns' => ['enable_resend' => true]
]);
```

### 5. Soft Delete Pattern
Logical deletion with audit trail:
```php
$model->update([
    'deleted_by' => auth()->user()->id,
    'deleted_at' => now()
]);
```

### 6. Webhook Integration Pattern
External notifications for key events:
```php
WebhookHelper::triggerWebhookEvent('contact.created', $data, $organizationId);
```

### 7. File Storage Abstraction
Support for local and cloud storage:
```php
if($storage === 'local') {
    $file = Storage::disk('local')->put('public', $fileContent);
    $url = config('app.url') . '/media/' . $file;
} else if($storage === 'aws') {
    $file = Storage::disk('s3')->put($path, $fileContent);
    $url = Storage::disk('s3')->url($file);
}
```

---

## Database Relationships

### Core Entity Relationships
```
Organizations (1) ──── (many) Users
Organizations (1) ──── (many) Contacts
Organizations (1) ──── (many) Templates
Organizations (1) ──── (many) Campaigns
Organizations (1) ──── (many) AutoReplies

Contacts (1) ──── (many) Chats
Contacts (many) ──── (many) ContactGroups
Contacts (1) ──── (1) ChatTicket

Campaigns (1) ──── (many) CampaignLogs
CampaignLogs (1) ──── (many) CampaignLogRetries

Chats (1) ──── (1) ChatMedia
Chats (many) ──── (1) Contact

Users (many) ──── (many) Organizations (via Teams)
Users (many) ──── (1) Role
```

### Key Tables
- **organizations**: Tenant isolation
- **contacts**: Customer data
- **chats**: Message records
- **campaigns**: Bulk messaging
- **templates**: WhatsApp templates
- **teams**: User-organization relationships
- **chat_tickets**: Support system

---

## API Integration Points

### WhatsApp Business API Endpoints Used
```
Messages API:
POST /{version}/{phone-number-id}/messages

Templates API:
GET/POST/DELETE /{version}/{waba-id}/message_templates

Media API:
GET /{version}/{media-id}
POST /{version}/{app-id}/uploads

Business Profile API:
GET/POST /{version}/{phone-number-id}/whatsapp_business_profile

Health Check API:
GET /{version}/{waba-id}?fields=health_status
```

### Internal API Patterns
- RESTful endpoints with UUID-based routing
- JSON request/response format
- Session-based organization context
- Role-based authorization

---

## Configuration & Settings

### Organization-Level Settings
Stored in `organizations.metadata` JSON field:
```json
{
  "timezone": "America/New_York",
  "whatsapp": {
    "access_token": "...",
    "phone_number_id": "...",
    "waba_id": "..."
  },
  "campaigns": {
    "enable_resend": true,
    "resend_intervals": [60, 120, 240],
    "move_failed_contacts_to_group": true,
    "batch_size": 500
  },
  "automation": {
    "response_sequence": ["Basic Replies", "AI Reply"]
  }
}
```

### System Settings
Stored in `settings` table:
- `storage_system`: local/aws
- `timezone`: default timezone
- `trial_period`: trial days for new organizations
- Pusher configuration for real-time features

---

## Junior Developer Guide

### Getting Started Checklist
1. **Understand the Multi-tenancy**: Every action happens within an organization context
2. **Learn the Queue System**: Heavy operations use background jobs
3. **Study the WhatsApp API**: Core business revolves around WhatsApp integration
4. **Grasp the Service Layer**: Business logic lives in service classes, not controllers
5. **Understand JSON Storage**: Many settings stored as JSON in metadata fields

### Common Development Tasks

#### Adding a New Feature
1. Create service class if needed
2. Add database migrations
3. Update models with relationships
4. Create API endpoints
5. Add queue jobs for heavy operations
6. Consider webhook notifications
7. Update organization metadata if configurable

#### Debugging Issues
1. Check queue jobs for failures: `php artisan queue:failed`
2. Review logs in `storage/logs/`
3. Verify organization metadata configuration
4. Check WhatsApp API credentials and limits
5. Validate phone number formats

#### Working with Campaigns
1. Campaign creation → CampaignService
2. Message processing → Campaign Jobs
3. Status tracking → CampaignLog model
4. Retry logic → RetryCampaignLogJob

#### Working with Chats
1. Incoming messages → Webhook handling
2. Outgoing messages → ChatService
3. Auto-replies → AutoReplyService
4. Templates → TemplateService

### Code Style Guidelines
- Use service classes for business logic
- Keep controllers thin
- Use meaningful variable names
- Add logging for external API calls
- Handle exceptions gracefully
- Use transactions for data consistency
- Follow Laravel conventions

### Testing Approach
- Test service methods independently
- Mock external API calls
- Use factories for test data
- Test queue jobs separately
- Verify webhook integrations

### Performance Considerations
- Queue heavy operations
- Use database indexing for frequent queries
- Implement caching for organization metadata
- Batch process large datasets
- Monitor external API rate limits

### Security Notes
- Validate phone numbers properly
- Sanitize user input before WhatsApp API calls
- Use UUIDs for external identifiers
- Implement proper access control
- Secure webhook endpoints
- Handle PII data carefully

This documentation provides a comprehensive understanding of the WhatsApp Business Platform codebase, designed to help both junior and senior developers understand the system architecture, service patterns, and development workflows.