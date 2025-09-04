# WhatsApp Campaign System - Simple Guide

## What This System Does

This Laravel application sends WhatsApp messages to contacts in campaigns. It handles scheduling, sending, retries, and tracking delivery status.

## The Big Picture

```
Campaign Created → Scheduled Time Arrives → Messages Sent → Retries if Failed → Campaign Complete
```

## How It Works (Step by Step)

### Step 1: Campaign Setup
- User creates a campaign with a scheduled time
- Campaign status starts as `scheduled`
- System waits until the scheduled time

### Step 2: Campaign Activation (CreateCampaignLogsJob)
**Runs every minute**

```php
// What it does:
1. Finds campaigns where scheduled_at <= now()
2. Gets all contacts for the campaign
3. Creates a "campaign_log" record for each contact
4. Changes campaign status to "ongoing"
```

**Key Points:**
- Handles different timezones per organization
- Won't create duplicate logs for same contact
- If no contact group specified, uses all organization contacts

### Step 3: Message Processing (ProcessCampaignMessagesJob)
**Runs every minute**

```php
// What it does:
1. Finds all campaign_logs with status "pending" or "failed"
2. Groups them into batches of 1000
3. Creates individual jobs to send each message
4. Checks if campaigns are complete
```

**Key Points:**
- Processes messages in parallel batches for speed
- Only retries failed messages if retry is enabled
- Marks campaigns as "completed" when no more messages to send

### Step 4: Individual Message Sending (ProcessSingleCampaignLogJob)
**Runs when dispatched from Step 3**

```php
// What it does:
1. Locks the campaign_log to prevent duplicates
2. Updates status to "ongoing"
3. Builds WhatsApp template message
4. Sends via WhatsApp API
5. Updates status to "success" or "failed"
6. Schedules retry if failed and retries enabled
```

**Key Points:**
- Uses database locking to prevent same message being sent twice
- Stores WhatsApp response for debugging
- Automatically schedules retries based on organization settings

### Step 5: Retry Handling (RetryCampaignLogJob)
**Runs after delay when message fails**

```php
// What it does:
1. Checks if retry is still needed
2. Attempts to send message again
3. Creates retry log record
4. Updates original campaign log
5. Schedules next retry OR moves contact to failed group
```

**Key Points:**
- Has built-in delays between retries
- Stops after max retry attempts reached
- Can automatically move persistently failed contacts to a "failed" group

## Database Tables (Simplified)

```
campaigns
├── id, status (scheduled/ongoing/completed)
├── scheduled_at
└── contact_group_id (optional)

campaign_logs  
├── campaign_id
├── contact_id  
├── status (pending/ongoing/success/failed)
└── metadata (WhatsApp response)

campaign_log_retries
├── campaign_log_id
├── status (success/failed)
└── metadata (retry response)

contacts
├── id, uuid
└── organization_id
```

## Configuration (Organization Settings)

Each organization can configure:

```json
{
  "timezone": "America/New_York",
  "campaigns": {
    "enable_resend": true,
    "resend_intervals": [60, 120, 240],  // retry after 1hr, 2hr, 4hr
    "move_failed_contacts_to_group": true,
    "failed_campaign_group": "uuid-of-failed-group"
  }
}
```

## Status Flow

### Campaign Status:
`scheduled` → `ongoing` → `completed`

### Campaign Log Status:
`pending` → `ongoing` → `success` OR `failed`
- If failed and retries enabled: stays `failed` until max retries, then final status

## Common Scenarios

### Normal Flow:
1. Campaign scheduled for 2 PM
2. At 2 PM, CreateCampaignLogsJob creates logs for all contacts
3. ProcessCampaignMessagesJob picks up pending logs
4. ProcessSingleCampaignLogJob sends each message
5. All succeed, campaign marked complete

### With Retries:
1. Some messages fail in step 4
2. RetryCampaignLogJob scheduled for later (based on retry intervals)
3. Retry attempts made automatically
4. After max retries, failed contacts moved to failed group
5. Campaign marked complete

### Error Handling:
1. If WhatsApp API is down, messages marked as failed
2. Retry system will attempt again later
3. If job crashes, Laravel will retry the job itself (3 times)
4. All errors logged for debugging

## Monitoring Points

**Check these if campaigns aren't working:**

1. **Queue Workers Running**: `php artisan queue:work`
2. **Cron Job Active**: Laravel scheduler must be running
3. **Campaign Status**: Should progress from scheduled → ongoing → completed
4. **Failed Jobs**: Check `php artisan queue:failed`
5. **WhatsApp Credentials**: Verify in organization metadata

## Quick Debugging

```bash
# Check if scheduler is running
php artisan schedule:list

# Check queue status  
php artisan queue:monitor

# View specific campaign
Campaign::with('logs')->find($campaign_id)

# Check organization settings
Organization::find($id)->metadata
```

## Production Checklist

- Cron job configured: `* * * * * php artisan schedule:run`
- Queue workers running on both queues
- Redis/Database queue driver configured
- WhatsApp API credentials configured per organization
- Monitor queue depth during large campaigns
- Set up alerts for failed job accumulation

This system is designed to be resilient and handle large volumes of messages while providing retry mechanisms and proper error tracking.