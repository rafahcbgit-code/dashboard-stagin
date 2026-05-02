# Chatberry Automation System - Architecture Document

> **Document Version:** 2.1
> **Created:** 2026-04-21
> **Status:** Approved
> **Purpose:** Architecture for Chatberry WhatsApp automation enhancement

---

## Executive Summary

This document defines the architecture for enhancing Chatberry's FlowBuilder chatbot with AI integration, built-in flows, and improved automation capabilities for SME customers.

---

## 1. Message Flow Architecture

### 1.1 Flow Priority Order

```
┌─────────────────────────────────────────────────────────────────┐
│                    MESSAGE RECEIVED                              │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
                    ┌─────────────────┐
                    │  New Contact?   │
                    └─────────────────┘
                              │
              ┌───────────────┴───────────────┐
              │                               │
             YES                              NO
              │                               │
              ▼                               │
    ┌─────────────────┐                       │
    │ New Contact Flow │                       │
    │ (Once lifetime) │                       │
    └─────────────────┘                       │
              │                               │
              └───────────────────────────────┤
                              │               │
                              ▼               │
                    ┌─────────────────┐       │
                    │ Existing Contact│◀──────┘
                    └─────────────────┘
                              │
                              ▼
                    ┌─────────────────────────┐
                    │ Sent in last 24 hours?  │
                    └─────────────────────────┘
                              │
              ┌───────────────┴───────────────┐
              │                               │
             YES                              NO
              │                               │
              ▼                               ▼
    ┌─────────────────┐             ┌─────────────────┐
    │ Skip greeting/  │             │ Working Hours?  │
    │ away (rate lim) │             └─────────────────┘
    └─────────────────┘                       │
              │               ┌───────────────┴───────────────┐
              │               │                               │
              │            OUTSIDE                          INSIDE
              │               │                               │
              │               ▼                               ▼
              │     ┌─────────────────┐             ┌─────────────────┐
              │     │ Away Flow      │             │ Greeting Flow   │
              │     │(Once per 24 hrs)│             │(Once per 24 hrs)│
              │     └─────────────────┘             └─────────────────┘
              │               │                               │
              └───────────────┴───────────────────────────────┘
                              │
                              ▼
                    ┌─────────────────┐
                    │  Bot Paused?     │
                    └─────────────────┘
                              │
              ┌───────────────┴───────────────┐
              │                               │
             YES                              NO
              │                               │
              ▼                               ▼
    ┌─────────────────┐             ┌─────────────────┐
    │ Agent Only Mode │             │ In Active Flow? │
    └─────────────────┘             └─────────────────┘
                                              │
                              ┌───────────────┴───────────────┐
                              │                               │
                             YES                              NO
                              │                               │
                              ▼                               ▼
                    ┌─────────────────┐             ┌─────────────────┐
                    │ Continue Flow   │             │Keyword Matching│
                    └─────────────────┘             └─────────────────┘
                                                              │
                                              ┌───────────────┴───────┐
                                              │                       │
                                          MATCH                    NO MATCH
                                              │                       │
                                              ▼                       ▼
                                    ┌─────────────────┐     ┌─────────────────┐
                                    │  Start Flow     │     │ AI Fallback?   │
                                    └─────────────────┘     └─────────────────┘
                                                              │
                                              ┌───────────────┴───────┐
                                              │                       │
                                            YES                      NO
                                              │                       │
                                              ▼                       ▼
                                    ┌─────────────────┐     ┌─────────────────┐
                                    │ AI KB Response  │     │ Fallback Menu  │
                                    │ or Escalate     │     │ [Start][Help]  │
                                    └─────────────────┘     │ [Agent]        │
                                                            └─────────────────┘
```

### 1.2 Rate Limits

| Flow Type | Trigger | Rate Limit |
|-----------|---------|------------|
| **New Contact Flow** | First message from unknown contact | Once per contact (lifetime) |
| **Greeting Flow** | Inside working hours | Once per 24 hours |
| **Away Flow** | Outside working hours | Once per 24 hours |

---

## 2. Built-in Flows

### 2.1 Three Default Flows

| Flow | Trigger | Fixed | Customizable | Rate Limit |
|------|---------|-------|--------------|-------------|
| **New Contact Flow** | First contact | Yes | Content & Nodes | Lifetime |
| **Greeting Flow** | Inside hours | Yes | Content & Nodes | 24 hours |
| **Away Flow** | Outside hours | Yes | Content & Nodes | 24 hours |

### 2.2 Benefits

- **Fixed triggers** = No configuration confusion for SMEs
- **ON/OFF toggle** = Simple control
- **Full flow customization** = Use Buttons, Lists, Questions, etc.
- **Interactive Lists** = More than 3 buttons available

---

## 3. AI Integration Architecture

### 3.1 Four-Layer AI System

```
┌─────────────────────────────────────────────────────────────────┐
│                    AI INTEGRATION LAYERS                        │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│   LAYER 1: TRIGGER GROUP AI MATCHING (Existing)                │
│   ─────────────────────────────────────────────────────────────│
│   - Per trigger group: "Keywords + AI" or "Keywords Only"       │
│   - AI helps match user intent to trigger group                │
│   - Low cost (only when trigger group has AI enabled)          │
│                                                                  │
│   LAYER 2: AI FALLBACK HANDLER (NEW)                            │
│   ─────────────────────────────────────────────────────────────│
│   - When no trigger matches AND organization has AI KB enabled │
│   - AI attempts to answer from knowledge base                   │
│   - If confident (≥75%): answer + "Was this helpful?"          │
│   - If not confident: show fallback menu                        │
│                                                                  │
│   LAYER 3: AI FAQ NODE IN FLOW (NEW)                            │
│   ─────────────────────────────────────────────────────────────│
│   - SME adds "AI FAQ" node in flow                             │
│   - User asks question, AI answers from KB                     │
│   - SME controls where in flow AI engages                      │
│                                                                  │
│   LAYER 4: AI ESCALATION (NEW)                                  │
│   ─────────────────────────────────────────────────────────────│
│   - Before assigning to human agent                            │
│   - Check escalation instructions                              │
│   - If match: escalate immediately                             │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 3.2 AI Escalation Instructions

```
┌─────────────────────────────────────────────────────────────────┐
│          ESCALATION INSTRUCTIONS (Natural Language)              │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│   Escalation Instructions:                                       │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │  Name              Description                           Target │
│   │  ─────────────────────────────────────────────────────────────│
│   │  Price Inquiry     When customer asks about pricing    → Sales │
│   │  Frustration       When customer shows frustration    → Support│
│   │  Refund Request    When customer mentions refund       → Refunds│
│   │                                                          │   │
│   │  [+ Add Instruction]                                     │   │
│   └─────────────────────────────────────────────────────────┘   │
│                                                                  │
│   Target Options:                                                │
│   ○ Route to Queue (Auto/Robinhood)                             │
│   ○ Specific Agent: [John D. ▼]                                 │
│   ○ Bot Queue (no auto-assignment)                              │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 4. Auto Assignment System

### 4.1 Existing Modes (Use Current)

| Mode | Description | Use Case |
|------|-------------|----------|
| **Off** | No auto-assignment | Manual assignment only |
| **Auto** | Distribute among available team members | Even distribution |
| **Robinhood** | Round-robin distribution | Equal workload |

### 4.2 Integration with Escalation

- Escalation instructions use existing Auto Assignment modes
- Target: Queue (uses Auto/Robinhood) OR Specific Agent OR Bot Queue
- Selected Agents configuration applies to Auto/Robinhood modes

---

## 5. Fallback Chain

### 5.1 Fallback Flow

```
┌─────────────────────────────────────────────────────────────────┐
│              FALLBACK FLOW                                       │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│   No trigger match                                               │
│        │                                                         │
│        ▼                                                         │
│   AI Fallback Enabled?                                          │
│        │                                                         │
│        ├── YES → AI attempts KB answer                          │
│        │           │                                              │
│        │           ├── Escalation Instruction matches?          │
│        │           │         │                                    │
│        │           │         └── YES → Escalate to human        │
│        │           │                                              │
│        │           ├── AI confident (≥75%)?                     │
│        │           │         │                                    │
│        │           │         ├── YES → Answer from KB           │
│        │           │         │         │                          │
│        │           │         │         └── "Was this helpful?"  │
│        │           │         │                                    │
│        │           │         └── NO → Fallback Menu             │
│        │           │                                              │
│        │           └── AI Timeout (max attempts) → Fallback Menu│
│        │                                                          │
│        └── NO → Increment counter → Show Fallback Menu          │
│                                                                  │
│   Fallback Menu:                                                 │
│   [Start Over] [Help] [Talk to Agent]                            │
│                                                                  │
│   Counter ≥ Threshold? → Escalate to human                      │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 6. Settings Organization

### 6.1 System Settings Structure

```
System Settings
├── Bot Behavior
│   ├── Fallback Threshold: [3] attempts
│   ├── Flow Timeout: [30] minutes
│   └── Working Hours: [Configure...]
│
├── Built-in Flows
│   ├── New Contact Flow: [ON/OFF] [Edit →]
│   ├── Greeting Flow: [ON/OFF] [Edit →]
│   └── Away Flow: [ON/OFF] [Edit →]
│
├── AI Settings
│   ├── AI Enabled: [ON/OFF]
│   ├── AI-Only Mode: [ON/OFF]
│   ├── Model: [gemma3:4b ▼]
│   ├── Confidence Threshold: [75%]
│   ├── Knowledge Base: [Select KB ▼]
│   └── Escalation Instructions: [Configure...]
│
├── Ticket Assigning
│   ├── Mode: ○ Off ○ Auto ○ Robinhood
│   └── Selected Agents: [Select...]
│
└── Notifications
    ├── In-App: [ON]
    ├── Email: [ON]
    └── Mobile Push: [ON]
```

---

## 7. Key Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| **Architecture** | Enhanced FlowBuilder | Simpler than Rules Engine for SMEs |
| **Trigger System** | Keywords + Optional AI | Cost control, flexibility |
| **New Contact** | Built-in Flow (fixed trigger) | Easy configuration |
| **Greeting/Away** | Built-in Flows (fixed triggers) | Simple UX, rate limited |
| **AI Integration** | Hybrid (4 layers) | Trigger AI + Fallback AI + AI Node + Escalation |
| **AI Escalation** | Natural language instructions | SME-friendly, not keyword toggles |
| **Auto Assignment** | Use existing (Off/Auto/Robinhood) | Leverage current system |
| **Fallback** | AI KB → Menu | Replace Global Reply with AI |
| **Rate Limits** | New Contact: lifetime, Greeting/Away: 24h | Prevent spam |
| **Settings Location** | System Settings | Easy for SMEs, one place |

---

## 8. Flow Nodes Reference

### 8.1 Control Nodes

#### Trigger Node (Start)

```
Purpose: Entry point for flow, linked to trigger group
Configuration:
  - trigger_group_id: int (select which trigger group starts this flow)

Notes:
  - Always first node in flow (required)
  - One per flow
  - Connects keyword/AI matching to flow execution
  - Supports "Keywords Only" or "Keywords + AI" mode per trigger group
```

#### End Node

```
Purpose: Mark flow complete
Configuration:
  - None (just marks completion)

Notes:
  - Can have multiple in flow (different exit paths)
  - When reached, user can start new flow
  - Variables persist after flow ends
```

---

### 8.2 Messaging Nodes

#### Text Node

```
Purpose: Send text message to user
Configuration:
  - body: string (supports {{variables}})

Example:
  "Hello {{contact.name}}, your order {{flow.order_id}} is confirmed."

Variable Support: Yes (contact, flow, system variables)
```

#### Media Node

```
Purpose: Send image/video/audio/document
Configuration:
  - type: enum (image, video, audio, document)
  - url: string (media URL)
  - caption: string (optional)

Use Cases:
  - Product images
  - Tutorial videos
  - Audio messages
  - PDF documents
```

#### Buttons Node

```
Purpose: Send message with quick-reply buttons
Configuration:
  - body: string
  - buttons: array[{id, title}]
  - max_buttons: 3 (WhatsApp limit)

Behavior:
  - User clicks button OR types button title
  - Both trigger same routing (dual matching)

Example:
  Buttons: ["Products", "Support", "Talk to Agent"]
  User types "Products" → Same as clicking "Products" button

Use Cases:
  - Navigation menus
  - Quick actions
  - Decision branches
```

#### List Node

```
Purpose: Send interactive list menu (up to 10 items)
Configuration:
  - header: string
  - body: string
  - footer: string (optional)
  - sections: array[{title, rows: [{id, title, description}]}]

Behavior:
  - User selects from list
  - List closes after selection
  - Single-use per node instance

Use Cases:
  - Product catalogs
  - Service menus
  - FAQ categories
  - When more than 3 options needed
```

#### Question Node

```
Purpose: Collect and validate user input
Configuration:
  - question: string
  - input_type: enum (text, email, phone, number, date, regex)
  - validation_regex: string (optional, for custom validation)
  - error_message: string
  - save_to_variable: string (e.g., {{flow.order_id}})
  - retry_attempts: int (default 3)
  - on_fail_route: node_id (optional, where to go after max retries)

Validation Types:
  - text: min/max length
  - email: RFC email format
  - phone: E.164 format
  - number: numeric, range
  - date: YYYY-MM-DD format
  - regex: custom pattern

Behavior:
  - Ask question
  - Validate response
  - Retry on invalid (up to retry_attempts)
  - Save to variable on success
  - Route to on_fail_route after max retries

Use Cases:
  - Collect order IDs
  - Verify email addresses
  - Get phone numbers
  - Custom validation (regex)
```

#### Template Node

```
Purpose: Send WhatsApp-approved business template message
Configuration:
  - template_name: string (must be approved in WhatsApp Business)
  - template_language: string (e.g., "en")
  - components: array (header, body, buttons)

Use Cases:
  - Marketing broadcasts (outside 24h window)
  - Order confirmations
  - Delivery notifications
  - Appointment reminders

Note: Templates must be pre-approved in WhatsApp Business Manager
```

---

### 8.3 Logic Nodes

#### Condition Node

```
Purpose: Branch flow based on conditions
Configuration:
  - conditions: array[{
      variable: string (e.g., {{flow.order_status}}),
      operator: enum (equals, not_equals, contains, greater_than, less_than),
      value: mixed,
      route_to: node_id
    }]
  - default_route: node_id (when no condition matches)

Supported Variables:
  - Flow variables: {{flow.order_id}}, {{flow.issue_type}}
  - Contact variables: {{contact.name}}, {{contact.email}}
  - System variables: {{system.current_date}}, {{system.agent_name}}

Example:
  IF {{flow.order_status}} equals "shipped" → Route to "Track Order" node
  IF {{flow.order_status}} equals "pending" → Route to "Wait" node
  DEFAULT → Route to "Help" node

Use Cases:
  - Route based on order status
  - Branch by customer type
  - Time-based routing
```

#### Delay Node

```
Purpose: Wait before executing next node
Configuration:
  Mode 1 - Duration:
    - duration_value: int
    - duration_unit: enum (seconds, minutes, hours, days)

  Mode 2 - Until Time:
    - until_datetime: datetime (specific date/time)
    - until_time: time (for recurring, e.g., "09:00 tomorrow")

Constraints:
  - Min duration: 1 second
  - Max duration: 10 minutes (for flow pacing)
  - Longer delays: Use Chatbot Timer (24h max)

Use Cases:
  - Space between messages: "Wait 5 seconds"
  - Give user time to read: "Wait 2 minutes"
  - Morning follow-up: "Wait until 09:00 tomorrow"
  - Birthday greeting: "Wait until {{contact.birthday}}"
```

---

### 8.4 Action Nodes

#### Add to Group Node

```
Purpose: Add contact to contact group
Configuration:
  - group_id: int (select from existing groups)

Use Cases:
  - Tag as "VIP Customer"
  - Add to "Newsletter" group
  - Segment by interest
```

#### Remove from Group Node

```
Purpose: Remove contact from contact group
Configuration:
  - group_id: int

Use Cases:
  - Remove from "Pending" after resolution
  - Unsubscribe from marketing
```

#### Update Contact Node

```
Purpose: Update contact profile fields
Configuration:
  - fields: array[{field, value}]
  - action: enum (set, add_tag, remove_tag)

Examples:
  - Set email: field="email", value="{{flow.user_email}}"
  - Add tag: field="tags", action="add_tag", value="premium_customer"
  - Set custom: field="company_name", value="{{flow.company}}"

Use Cases:
  - Update contact info after collection
  - Tag customers based on flow actions
  - Store preferences
```

#### Webhook Node

```
Purpose: Call external API and use response in flow
Configuration:
  - url: string (supports {{variables}})
  - method: enum (GET, POST, PUT, DELETE)
  - headers: object ({ "Authorization": "Bearer xxx" })
  - body: object (for POST/PUT, supports {{variables}})
  - timeout: int (default 30 seconds)
  - retry_enabled: boolean (default true)
  - max_retries: int (default 144)
  - retry_interval: int (default 10 minutes)
  - on_retry_fail: node_id (where to go after all retries fail)
  - response_mapping: array[{response_field, save_to_variable}]

Example:
  URL: https://api.shop.com/orders/{{flow.order_id}}
  Method: GET
  Response Mapping:
    - $.status → {{flow.order_status}}
    - $.tracking_number → {{flow.tracking}}

  Response: { "status": "shipped", "tracking_number": "TRK123" }
  Result: {{flow.order_status}} = "shipped", {{flow.tracking}} = "TRK123"

Use Cases:
  - Check order status from external system
  - Verify payment status
  - Get customer data from CRM
  - Trigger external actions
```

#### Assign to Agent Node

```
Purpose: Hand off conversation to human agent
Configuration:
  - assignment_mode: enum (auto_assignment, specific_agent, bot_queue)

  IF auto_assignment:
    - use_org_settings: boolean (use organization's Auto/Robinhood config)
    - team_id: int (optional, filter by team)

  IF specific_agent:
    - agent_id: int (select specific agent)

  IF bot_queue:
    - team_id: int (optional, for manual assignment later)

  - pause_bot: boolean (default true)
  - handoff_message: string (supports {{variables}})

Behavior:
  1. Check assignment mode
  2. IF auto_assignment: Use org's Auto/Robinhood mode + Selected Agents
  3. IF specific_agent: Assign directly to agent
  4. IF bot_queue: Add to bot queue (no auto-assignment)
  5. Pause bot
  6. Send notification to agent (in-app, email, push)

Agent Selection (for auto_assignment):
  - Filter by team (if specified)
  - Check availability (online, under capacity)
  - Apply Auto/Robinhood distribution
  - Fallback: team lead or first available

Use Cases:
  - Escalate to support team
  - Route to sales for pricing questions
  - Hand off to specific agent
  - Queue for manual assignment
```

#### AI FAQ Node

```
Purpose: AI-powered Q&A within flow (Layer 3 AI Integration)
Configuration:
  - knowledge_base_id: int (select from organization's KBs)
  - confidence_threshold: int (default 75%)
  - max_attempts: int (default 3)
  - on_success_route: node_id (after successful answer)
  - on_fail_route: node_id (when AI can't answer after max attempts)
  - escalate_on_fail: boolean (auto-escalate if can't answer)
  - escalation_target: enum (auto_assignment, specific_agent, bot_queue)

Behavior:
  1. User asks question
  2. AI searches knowledge base
  3. Check escalation instructions (Layer 4)
  4. IF escalation match → Escalate immediately
  5. IF confident (≥threshold) → Answer + "Was this helpful?"
  6. IF not confident → Retry up to max_attempts
  7. After max attempts → Route to on_fail_node

Variable Support:
  - Uses {{contact.*}} for context
  - Can reference {{flow.*}} for conditional KB selection

Use Cases:
  - Product FAQs within flow
  - Self-service support
  - Answer common questions before escalation
  - KB-powered customer service
```

---

### 8.5 WhatsApp Nodes

#### Meta Flow Node

```
Purpose: Send WhatsApp Flow (structured form within WhatsApp)
Configuration:
  - flow_id: string (WhatsApp Flow ID, pre-created in Meta)
  - variables: object (data to pass to flow)
  - response_mapping: array[{field, save_to_variable}]

What are WhatsApp Flows?
  - Structured forms within WhatsApp chat
  - User fills out fields directly in chat
  - Used for: surveys, order forms, feedback collection

Behavior:
  - Send WhatsApp Flow template
  - User fills form in WhatsApp
  - Receive structured response
  - Map response fields to variables

Use Cases:
  - Order forms
  - Feedback surveys
  - Registration forms
  - Appointment booking
```

#### Send Email Node

```
Purpose: Send email notification
Configuration:
  - to: string (supports {{variables}})
  - subject: string
  - body: string (HTML supported)

Use Cases:
  - Alert internal team about escalation
  - Send confirmation email
  - Notify about high-priority issues
```

---

### 8.6 Variable System

```
Variable Syntax:
├── {{contact.*}} - Contact attributes (persistent)
│   ├── {{contact.name}} - Full name
│   ├── {{contact.phone}} - Phone number
│   ├── {{contact.email}} - Email address
│   └── {{contact.tags}} - Contact tags
│
├── {{flow.*}} - Flow variables (session-only)
│   ├── {{flow.order_id}} - Order ID from Question node
│   ├── {{flow.issue_type}} - Issue type from List node
│   └── Custom variables from Question/Webhook nodes
│
└── {{system.*}} - System variables (read-only)
    ├── {{system.current_date}} - Today's date
    ├── {{system.current_time}} - Current time
    ├── {{system.agent_name}} - Assigned agent name
    └── {{system.organization_name}} - Organization name

Rules:
  - Contact variables persist across sessions
  - Flow variables expire after flow ends
  - System variables are read-only
  - All variables support {{variable}} syntax in text fields
```

---

## 9. Integration Points

### 9.1 Existing Services to Modify

| Service | Changes |
|---------|---------|
| `FlowExecutionService.php` | Add built-in flow triggers, rate limit check |
| `WorkingHoursService.php` | Use for Greeting/Away flow triggers |
| `FallbackService.php` | Integrate AI KB fallback layer |
| `SkillRoutingService.php` | Use for escalation targets |

### 9.2 New Services to Create

| Service | Purpose |
|---------|---------|
| `BuiltinFlowService.php` | Manage New Contact, Greeting, Away flows |
| `RateLimitService.php` | Track 24h rate limits per contact |
| `AiFallbackService.php` | Handle AI KB fallback layer |
| `EscalationInstructionService.php` | Manage natural language escalation rules |

### 9.3 New Database Tables

| Table | Purpose |
|-------|---------|
| `builtin_flows` | Store New Contact, Greeting, Away flow configs |
| `ai_escalation_instructions` | Store escalation instructions |
| `message_rate_limits` | Track rate limit timestamps per contact |

---

## 10. Questions for Tech Team

1. **Rate Limit Storage** - Store in `flow_user_data` or new table?
2. **Built-in Flow IDs** - How to handle flow_id references for built-in flows?
3. **AI KB Integration** - Which KB system to use for fallback?
4. **Migration** - How to migrate existing Greeting/Away messages to built-in flows?

---

*End of Architecture Document*