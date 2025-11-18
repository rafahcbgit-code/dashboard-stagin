# Mobile Notifications API

## Setup

### 1. Register Device Token
After user login, register the FCM device token:

**Endpoint:** `POST /api/v1/device-token/register`

**Headers:**
- `Authorization: Bearer {token}`
- `X-Organization-UUID: {org_uuid}`

**Body:**
```json
{
    "device_token": "fcm_token_from_firebase",
    "device_type": "android", // or "ios"
    "device_name": "Device Model Name",
    "app_version": "1.0.0"
}
```

### 2. Heartbeat (Keep Token Active)
Call this endpoint every 1-2 minutes when app is active:

**Endpoint:** `POST /api/v1/device-token/heartbeat`

**Body:**
```json
{
    "device_token": "fcm_token_from_firebase"
}
```

### 3. Unregister on Logout
**Endpoint:** `POST /api/v1/device-token/unregister`

**Body:**
```json
{
    "device_token": "fcm_token_from_firebase"
}
```

## Notification Types

### 1. New Message
**Notification:**
```json
{
    "notification": {
        "title": "John Doe",
        "body": "Hello there"
    },
    "data": {
        "type": "new_message",
        "contact_uuid": "uuid",
        "message_uuid": "uuid",
        "unread_count": "3",
        "click_action": "OPEN_CHAT"
    }
}
```

### 2. Chat Assignment
**Notification:**
```json
{
    "notification": {
        "title": "New Chat Assigned",
        "body": "Jane Smith - Assigned by Admin"
    },
    "data": {
        "type": "chat_assigned",
        "contact_uuid": "uuid",
        "click_action": "OPEN_CHAT"
    }
}
```

## Implementation Notes

- When app is FOREGROUND: Use Pusher for real-time updates
- When app is BACKGROUND/CLOSED: Use FCM notifications
- Always call heartbeat when app is active to prevent duplicate notifications