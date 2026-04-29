# Chatberry Automation Flow Reference

How the automation system processes incoming WhatsApp messages based on current settings.

---

## 1. Message Processing Order

Every incoming message goes through `AutoReplyService::replySequence()` in this exact order:

```
1. Interactive button click?  → handle it (fallback menu or AI feedback) and STOP
2. AI-Only Mode ON?          → try AI answer from KB → fallback menu → STOP
3. Active flow exists?       → resume the flow → STOP if handled
4. Trigger match?            → start the trigger's linked flow → STOP
5. AI Fallback enabled?      → try KB answer → STOP if answered
6. Fallback Menu enabled?    → send 3-button menu → STOP
7. Nothing matched            → silence
```

Each step only runs if the previous step didn't handle the message.

---

## 2. Setting Combinations & Outcomes

| Settings | What Happens When a User Messages |
|----------|-----------------------------------|
| AI-Only Mode ON, no triggers, KB has data | AI answers from KB → "Was this helpful?" buttons. If AI can't answer → fallback menu |
| AI-Only Mode ON, no triggers, no KB | Falls straight to fallback menu |
| AI-Only Mode ON, triggers active | **AI-Only Mode skips triggers entirely.** Only AI KB answers + fallback menu |
| AI-Only Mode OFF, active flow running | Flow resumes. If flow doesn't handle the message → falls through to triggers → AI → fallback menu |
| AI-Only Mode OFF, no active flow, trigger matches | Trigger's linked flow starts |
| AI-Only Mode OFF, no flow, no trigger, AI enabled | AI attempts KB answer. If escalation rule matches → routes to agent |
| AI-Only Mode OFF, nothing matches | Fallback menu (if enabled) |
| AI disabled entirely (`ai_enabled = false`) | Skips AI fallback. Only triggers and fallback menu work |
| Fallback menu OFF | No recovery menu shown. Repeated unhandled messages can still trigger auto-escalation (counter keeps counting) |

---

## 3. Trigger Types

| Type | When It Fires | Notes |
|------|--------------|-------|
| **new_contact** | Contact's first message ever | Skipped if not a new contact. Rate-limited (lifetime or 24h). |
| **greeting** | First message during working hours | Skipped outside working hours. Rate-limited. |
| **away** | First message outside working hours | Skipped during working hours. Rate-limited. |
| **fallback** | Always matches (catch-all) | Placed last in priority order. If no flow is linked, falls through to AI/menu. |
| **new_message** | Every message, keyword matching | Uses exact, contains, or AI intent classification. Rate-limited. |

**Trigger priority:** Evaluated in database order (`sort_order`). First match wins.

**Rate limit types:**
- `none` — fires every time
- `lifetime` — fires once per contact, ever
- `24h` — fires once per contact per 24-hour window

**Keyword match modes** (new_message triggers only):
- `exact` — message equals the keyword
- `contains` — message contains the keyword
- `ai` — AI classifies intent against the trigger's keywords (requires AI enabled org-wide)

---

## 4. AI Fallback Pipeline

When AI fallback is enabled (`bot_settings.ai_fallback_enabled = true`) and AI is on (`ai_settings.ai_enabled = true`):

```
1. Is it a question?     → Heuristic check (too short, email, phone, URL, gibberish → skip)
2. Escalation rule match? → Route to agent (see §6)
3. Has KB data?          → No KB → skip, fall through
4. AI call               → Returns {"answer": "...", "confidence": 0.0-1.0}
5. Confidence check       → Below threshold (default 75%) → skip, fall through
6. Answer sent           → WhatsApp message + "Was this helpful?" buttons
```

**"Was this helpful?" buttons:**
- **Yes** → "Great! Let me know if you need anything else."
- **No** → Shows fallback menu (§5)

---

## 5. Fallback Menu

When nothing else handles the message and `bot_settings.fallback_menu_enabled = true`:

**Default buttons:**
| Button | Action |
|--------|--------|
| Start Over | Starts the configured flow, or re-triggers the new_contact trigger |
| Help | Starts the configured help flow |
| Talk to Agent | Routes to agent via auto-assignment (only shown if `fallback_menu_route_to_agent = true`) |

**Escalation counter:** Each fallback increments a counter. When counter ≥ `bot_settings.fallback_threshold` (default 3), the next unhandled message auto-assigns to an agent instead of showing the menu. Counter resets when:
- Agent is assigned
- 24+ hours pass since last fallback

---

## 6. Escalation Instructions

Escalation rules are configured in **Automation → Escalation**. Each rule has a name, description, priority, and target agent.

When AI fallback runs, it checks escalation rules **before** KB lookup:

1. AI is called with the rule list and the user's message
2. If the AI classifies the message as matching a rule → returns the rule
3. The rule's target determines routing:
   - `specific_agent` → assigned to that agent
   - `auto_assignment` → round-robin or least-loaded
4. If no escalation rule matches → proceeds to KB answer

**AI-only mode also uses escalation.** In AI-only mode, the AI fallback pipeline runs first, so escalation rules are checked before KB answers.

---

## 7. Agent Routing

When a conversation is routed to an agent (`AgentRoutingService::assignToAgent`):

| Setting | Routing Method |
|---------|---------------|
| `tickets.assigned_to_specific_agents = true` | Round-robin among listed agents |
| `tickets.auto_assignment = true` | Least-loaded agent (fewest open tickets) |
| Neither | Ticket created, unassigned (manual assignment) |

On assignment:
- Ticket created or reopened
- Fallback counter reset to 0
- Push notification sent to agent
- Assignment email queued (if SMTP configured)

---

## 8. FlowBuilder Node Types

| Node Type | Behavior |
|-----------|----------|
| **start** | Entry point, skipped automatically |
| **message** | Sends text, media, buttons, list, or CTA. Interactive types pause for user input. Text/media auto-advance. |
| **question** | Sends question, validates answer (`input_type`, `max_retries`, `validation_pattern`). Pauses flow until answered. |
| **action** | Executes a side-effect action. See §9 for action types. |

---

## 9. Action Node Types

| Action | What It Does |
|--------|-------------|
| `add_to_group` | Adds contact to a group |
| `remove_from_group` | Removes contact from a group |
| `update_contact` | Updates contact fields (name, email, custom fields, labels) |
| `send_email` | Sends an email via org SMTP config |
| `delay` | Pauses flow for N minutes/hours (queued job resumes it) |
| `webhook` | POSTs data to an external URL |
| `ai_response` | AI answers from KB with confidence check (see §10) |
| `conditional` | Branches flow based on contact data, message content, or computed conditions |
| `assign_to_agent` | Routes conversation to an agent (same routing as escalation) |

---

## 10. AI Response Action Node

Used inside flows to generate AI-powered replies:

```
1. Fetch KB context for the organization
2. Build system prompt (KB context + optional custom prompt from config)
3. Call AI → expects {"answer": "...", "confidence": 0.0-1.0}
4. If answer is empty or "NONE" → send low_confidence_message (if set) → flow pauses (returns false)
5. Send answer via WhatsApp
6. If proceed_condition = "confidence_threshold" and confidence < threshold → send low_confidence_message → flow pauses
7. If proceed_condition = "always" or confidence ≥ threshold → flow continues to next node
```

**Config fields:**
- `prompt` — extra instructions appended to the system prompt (optional)
- `proceed_condition` — `always` or `confidence_threshold`
- `confidence_threshold` — minimum confidence to continue (default 0.75)
- `low_confidence_message` — message sent when confidence is too low (optional)

---

## 11. Edge Cases

| Scenario | Behavior |
|----------|----------|
| User clicks a fallback menu button | Handled as interactive reply. Start Over / Help / Talk to Agent — does not re-enter the main reply sequence |
| User clicks "No, I need more help" on AI feedback | Shows the fallback menu |
| Flow times out (default 30 min) | `FlowUserData` expires. Next message starts fresh through the full sequence |
| Contact has an active flow but sends unrelated message | Flow tries to resume. If flow can't handle it (no matching question/input), falls through to triggers → AI → fallback menu |
| AI returns unparseable response | Treated as no answer. Falls through to next step |
| AI returns `{"answer": "NONE"}` | Same as no KB match. Falls through |
| Message is an email/phone/URL/gibberish | AI heuristic skips it. No KB lookup attempted |
| Escalation counter reaches threshold | Next unhandled message auto-assigns to agent instead of showing fallback menu |
| Agent assigned → counter resets | `RateLimitService::resetFallbackCounter` called on every agent assignment |
| No KB entries exist | AI fallback returns null immediately. Falls through to fallback menu |
| AI-only mode + no KB data | Falls straight to fallback menu |
| Trigger has no linked flow | Trigger matches but doesn't start anything. Falls through to next step |
| Trigger rate limit exceeded | Trigger is skipped as if it doesn't exist |
| `greeting` trigger outside working hours | Skipped (only fires during working hours) |
| `away` trigger during working hours | Skipped (only fires outside working hours) |