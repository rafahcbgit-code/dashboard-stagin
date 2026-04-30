# Chatberry Automation — Scenario-Based Test Guide

> Comprehensive scenario tests covering the full message flow architecture
> from the PM doc: triggers, AI fallback, escalation, fallback menu, bot settings, and flows.

---

## 0. Prerequisites

Before testing, ensure:
1. **FlowBuilder module** is enabled for the organization
2. **AI Assistant module** is enabled (for AI-related tests)
3. **WhatsApp number** is connected and receiving messages
4. **Ollama** is running (for AI match tests) — or expect graceful skip
5. **At least 1 flow** exists and is published (active status)
6. **At least 1 agent** is assigned to the organization via Teams

---

## 1. Trigger System — System Triggers

### 1.1 New Contact Trigger (Lifetime Rate Limit)

**Setup:**
- Create or ensure a "Welcome" system trigger exists (type: `new_contact`, rate_limit: `lifetime`)
- Link it to an active flow (e.g., a flow that sends "Welcome to our service!")
- Disable other triggers temporarily

**Test:**
1. Send a message from a **brand new phone number** (never contacted before)
2. **Expected:** The linked welcome flow starts immediately
3. Send another message from the **same number**
4. **Expected:** Welcome flow does NOT re-trigger (lifetime rate limit)
5. Send another message
6. **Expected:** Falls through to AI fallback, basic replies, or fallback menu (not the welcome flow again)

**Edge Case — No Linked Flow:**
1. Edit the Welcome trigger, unlink the flow (set to "No flow")
2. Send a message from a new contact
3. **Expected:** Welcome trigger matches but does nothing (no flow to start). Falls through to next handler.

### 1.2 Greeting Trigger (24h Rate Limit, Inside Working Hours)

**Setup:**
- Ensure working hours are configured and currently active (e.g., 9am–5pm, and it's currently within those hours)
- Create or ensure a "Greeting" system trigger exists (type: `greeting`, rate_limit: `24h`)
- Link it to an active flow
- Use a contact that has NOT messaged in 24+ hours

**Test:**
1. Send a message from an **existing contact** who hasn't messaged in 24+ hours
2. **Expected:** Greeting flow starts
3. Send another message within 24 hours from the same contact
4. **Expected:** Greeting does NOT re-trigger (24h rate limit blocks it)
5. Wait 24+ hours, send again
6. **Expected:** Greeting re-triggers

**Edge Case — Outside Working Hours:**
1. Set working hours to a time window that has already passed today (e.g., 8am–9am and it's now 3pm)
2. Send a message from a contact who hasn't messaged in 24+ hours
3. **Expected:** Greeting does NOT trigger. Away trigger should fire instead.

### 1.3 Away Trigger (24h Rate Limit, Outside Working Hours)

**Setup:**
- Set working hours to a window that is NOT currently active
- Ensure "Away" system trigger exists (type: `away`, rate_limit: `24h`)
- Link it to an active flow

**Test:**
1. Send a message from a contact outside working hours who hasn't messaged in 24+ hours
2. **Expected:** Away flow starts
3. Send another message within 24 hours
4. **Expected:** Away does NOT re-trigger

**Edge Case — Inside Working Hours:**
1. Change working hours to be currently active
2. Send a message
3. **Expected:** Away does NOT trigger. Greeting trigger may fire instead.

### 1.4 Fallback Trigger (Always Matches, Lowest Priority)

**Setup:**
- Ensure "Fallback" system trigger exists (type: `fallback`, rate_limit: `none`)
- Link it to an active flow
- Disable all other triggers

**Test:**
1. Send any message
2. **Expected:** Fallback trigger always matches, linked flow starts
3. Send another message
4. **Expected:** Fallback trigger matches again (no rate limit)

**Edge Case — Fallback Without Linked Flow:**
1. Unlink the flow from the Fallback trigger
2. Send a message
3. **Expected:** Fallback trigger matches but no flow starts. Falls through to fallback menu or basic replies.

---

## 2. Trigger System — Custom Keyword Triggers

### 2.1 Exact Match Trigger

**Setup:**
- Create a trigger: Name="Refund Handler", Type="New Message", Keywords=["refund"], Match Mode="Exact"
- Link to an active flow
- Set priority ABOVE other triggers

**Test:**
1. Send message: "refund"
2. **Expected:** Trigger matches exactly, linked flow starts
3. Send message: "I want a refund" (contains keyword but not exact match)
4. **Expected:** Does NOT trigger (exact mode requires full message match)
5. Send message: "Refund" (capital R)
6. **Expected:** Triggers (exact match is case-insensitive)

### 2.2 AI Match Trigger

**Setup:**
- Enable AI in AI Settings
- Create a trigger: Name="Pricing Inquiry", Type="New Message", Keywords=["pricing", "cost", "how much"], Match Mode="AI"
- Set AI confidence threshold to 70%
- Link to an active flow

**Test:**
1. Send message: "How much does this cost?"
2. **Expected:** AI classifies this as matching "pricing" or "cost" intent, trigger matches, flow starts
3. Send message: "What are your prices?"
4. **Expected:** AI matches this to "pricing" intent
4. Send message: "hello"
5. **Expected:** AI does NOT match this to any keyword, trigger doesn't fire

**Edge Case — Ollama Down:**
1. Stop the Ollama service
2. Send a message that would match "pricing" semantically
3. **Expected:** AI match fails gracefully (no 500 error). Trigger is skipped. Falls through to next handler (AI fallback, basic replies, or fallback menu)

**Edge Case — AI Disabled in Settings:**
1. Turn OFF AI Features in AI Settings
2. Send a message that would semantically match a keyword
3. **Expected:** AI match triggers are skipped entirely. Only exact/contains triggers evaluate.

### 2.3 Priority Order Testing

**Setup:**
- Create triggers in this order (drag to reorder):
  1. Priority 1: "Refund Handler" — keyword "refund", exact match, linked to Flow A
  2. Priority 2: "Help" — keyword "help", AI match, linked to Flow B
  3. Priority 3: "Fallback" — type fallback, linked to Flow C

**Test:**
1. Send "refund"
2. **Expected:** Flow A starts (Priority 1 matches first)
3. Send "I need assistance"
4. **Expected:** Flow B starts (AI matches "assistance" to "help")
5. Send "random message"
6. **Expected:** Flow C starts (fallback catches everything)

### 2.4 Drag Reorder Priority

**Test:**
1. Go to `/automation/triggers`
2. Drag "Help" trigger above "Refund Handler"
3. **Expected:** Priority numbers update, toast shows "Priority updated"
4. Refresh the page
5. **Expected:** New order persists
6. Send "refund"
7. **Expected:** If "Help" now has higher priority and "refund" could match via AI, "Help" trigger fires first. Otherwise "Refund Handler" still matches.

### 2.5 Toggle ON/OFF

**Test:**
1. Toggle OFF a trigger
2. **Expected:** Toggle shows gray/off state. Toast confirms.
3. Send a message matching that trigger's keywords
4. **Expected:** Trigger does NOT fire (disabled triggers are skipped)
5. Toggle it back ON
6. Send the same message
7. **Expected:** Trigger fires again

### 2.6 Create Trigger — Validation

**Test:**
1. Click "Create Trigger"
2. Leave name empty, click "Add Trigger"
3. **Expected:** Validation error — name is required
4. Fill name, select type "New Message", leave keywords empty, click "Add Trigger"
5. **Expected:** Validation error — at least one keyword required for new_message type
6. Add keyword, select match mode "AI", move confidence slider to 90%
7. **Expected:** Slider shows "90%"
8. Click "Add Trigger"
9. **Expected:** Trigger created, toast shows success

### 2.7 Edit Trigger

**Test:**
1. Click edit icon on a trigger
2. **Expected:** Modal opens with all fields pre-filled (name, type, keywords, match mode, confidence, flow link)
3. Change the name, add a new keyword, click "Save Changes"
4. **Expected:** Changes reflected in list, toast shows success

### 2.8 Delete Trigger — System vs Custom

**Test:**
1. Try to delete a system trigger (Welcome, Greeting, Away, Fallback)
2. **Expected:** No delete icon available (system triggers cannot be deleted, only disabled)
3. Click delete on a custom trigger
4. **Expected:** Confirmation dialog appears
5. Confirm deletion
6. **Expected:** Trigger removed from list, toast shows success

---

## 3. Trigger → Flow Execution Integration

### 3.1 Trigger Starts Flow Correctly

**Setup:**
- Create a simple flow with: Start → Text Node ("Hello from the flow!") → End
- Publish the flow (set status to active)
- Create a trigger: keyword "test", exact match, linked to this flow

**Test:**
1. Send "test" via WhatsApp
2. **Expected:** Flow starts, user receives "Hello from the flow!"
3. Flow state (FlowUserData) is created for the contact
4. Send another message (not a keyword)
5. **Expected:** Flow continues from where it left off (if there are more nodes) or flow ends and new trigger evaluation begins

### 3.2 Active Flow Takes Priority Over New Triggers

**Setup:**
- Create a flow: Start → Text Node ("Step 1") → Text Node ("Step 2") → End
- Create trigger: keyword "start", linked to this flow

**Test:**
1. Send "start" — flow begins, user receives "Step 1"
2. Send "refund" (which would match a different trigger)
3. **Expected:** Current flow continues (Step 2 sent). New trigger does NOT start a different flow.

### 3.3 Flow Timeout Expires Active Flow

**Setup:**
- Set Flow Timeout to 2 minutes in Bot Settings
- Create a flow: Start → Text Node ("Hello") → Text Node ("Goodbye") → End
- Create trigger for the flow

**Test:**
1. Trigger the flow, receive "Hello"
2. Wait 2+ minutes
3. Send any message
4. **Expected:** Flow is expired. New message goes through trigger evaluation fresh (not continuing the old flow)

### 3.4 No Linked Flow on Trigger

**Setup:**
- Create a trigger with a keyword but NO linked flow

**Test:**
1. Send a message matching the keyword
2. **Expected:** Trigger matches in evaluation but since there's no linked flow, it falls through to AI fallback, basic replies, or fallback menu

---

## 4. AI Fallback (Layer 2)

### 4.1 AI Fallback — KB Answer with "Was this helpful?"

**Setup:**
- Enable AI Features in AI Settings
- Enable AI Fallback in Bot Settings
- Add KB entries:
  - Q: "What are your hours?" A: "We're open Monday to Friday, 9am to 5pm."
  - Q: "How do I return an item?" A: "You can return items within 30 days by visiting our returns page."

**Test:**
1. Send a message that does NOT match any trigger keyword (e.g., "When are you open?")
2. **Expected:** AI answers from KB: "We're open Monday to Friday, 9am to 5pm."
3. **Expected:** Follow-up message: "Was this helpful?" with two buttons:
   - "Yes, this helped"
   - "No, I need more help"

### 4.2 "Yes, this helped" Button

**Test (continuing from 4.1):**
1. Click "Yes, this helped"
2. **Expected:** Bot sends acknowledgment message (e.g., "Great! Let me know if you need anything else.")
3. **Expected:** No further bot messages sent

### 4.3 "No, I need more help" Button → Fallback Menu

**Test (continuing from 4.1):**
1. Click "No, I need more help"
2. **Expected:** Fallback menu appears with 3 buttons (or 2 if "Talk to Agent" is disabled):
   - Start Over
   - Help
   - Talk to Agent (if enabled)

### 4.4 AI Fallback — Not Confident

**Setup:**
- Enable AI Fallback, set confidence threshold to 80%
- Add KB entries

**Test:**
1. Send a very ambiguous message: "asdfghjkl"
2. **Expected:** AI cannot find a confident match in KB
3. **Expected:** Falls through to fallback menu (if enabled) or basic replies

### 4.5 AI Fallback — Escalation Match Takes Priority

**Setup:**
- Enable AI Fallback in Bot Settings
- Create escalation rule: Name="Price Inquiry", Description="When customer asks about pricing, costs, or subscription plans", Target=Auto Queue
- Add KB entries (so AI has something to answer)

**Test:**
1. Send message: "How much does this cost?"
2. **Expected:** Escalation instruction matches (AI classifies as pricing question)
3. **Expected:** A chat ticket is created/assigned to an agent
4. **Expected:** No KB answer is sent (escalation takes priority over KB answer)

### 4.6 AI Fallback — No KB Entries

**Setup:**
- Enable AI Fallback
- Delete all KB entries

**Test:**
1. Send an unmatched message
2. **Expected:** AI returns NONE (can't answer without KB context)
3. **Expected:** Falls through to fallback menu or basic replies

### 4.7 AI Fallback — Ollama Down

**Setup:**
- Enable AI Fallback
- Stop Ollama service

**Test:**
1. Send an unmatched message
2. **Expected:** No crash, no 500 error. AI fallback is skipped gracefully
3. **Expected:** Falls through to fallback menu or basic replies

---

## 5. AI Escalation Instructions (Layer 4)

### 5.1 Create Escalation Rule — Auto Queue

**Test:**
1. Go to `/automation/escalation`
2. Click "Add Rule"
3. Enter name: "Frustrated Customer"
4. Enter description: "When customer shows frustration, anger, or dissatisfaction"
5. Select target: "Auto Queue"
6. Click "Add Rule"
7. **Expected:** Rule created, badge shows "Auto Queue" in blue

### 5.2 Create Escalation Rule — Specific Agent

**Test:**
1. Click "Add Rule"
2. Enter name: "VIP Support"
3. Enter description: "When customer mentions VIP, premium, or priority support"
4. Select target: "Specific Agent"
5. Select an agent from dropdown
6. Click "Add Rule"
7. **Expected:** Rule created, badge shows "Agent" in green with agent name

### 5.3 Validation — Agent Required

**Test:**
1. Click "Add Rule"
2. Select "Specific Agent" as target
3. Leave agent dropdown on default "— Choose an agent —"
4. Click "Add Rule"
5. **Expected:** Validation error: "Please select an agent when routing to a specific agent."

### 5.4 Escalation Rule Drag Reorder

**Test:**
1. Create 2+ escalation rules
2. Drag one above another
3. **Expected:** Order changes visually
4. Refresh page
5. **Expected:** New order persists (priority order matters — first match wins)

### 5.5 Escalation Rule — Toggle, Edit, Delete

**Test:**
1. Toggle a rule OFF — **Expected:** Rule becomes disabled (not evaluated)
2. Toggle it back ON — **Expected:** Rule active again
3. Edit a rule — **Expected:** Modal pre-fills all fields
4. Delete a rule — **Expected:** Confirmation dialog, then removed

### 5.6 Escalation in Live Message Flow

**Setup:**
- Create escalation rule: "Refund Request" → "When customer asks for a refund or wants their money back" → Auto Queue
- Enable AI Fallback in Bot Settings

**Test:**
1. Send: "I want my money back, this is terrible!"
2. **Expected:** Escalation rule matches (AI classifies as refund intent)
3. **Expected:** Chat ticket is created and assigned to an agent (using org's auto-assignment mode)
4. **Expected:** Agent receives notification (if push notifications configured)

---

## 6. Fallback Menu

### 6.1 Fallback Menu — Full 3-Button Menu

**Setup:**
- Enable Fallback Menu in Bot Settings
- Set menu message: "I didn't quite understand. What would you like to do?"
- Enable "Talk to Agent" routing
- Set Start Over flow and Help flow (optional)

**Test:**
1. Send an unmatched message (no trigger, no basic reply match)
2. **Expected:** 3-button interactive message appears:
   ```
   I didn't quite understand. What would you like to do?

   [Start Over]  [Help]  [Talk to Agent]
   ```

### 6.2 Fallback Menu — "Start Over" Button

**Test (continuing from 6.1):**
1. Click "Start Over"
2. **Expected:** If Start Over flow is configured → that flow starts
3. **Expected:** If no Start Over flow → Welcome/new-contact trigger is attempted

### 6.3 Fallback Menu — "Help" Button

**Test (continuing from 6.1):**
1. Click "Help"
2. **Expected:** If Help flow is configured → that flow starts
3. **Expected:** If no Help flow → nothing happens (no error)

### 6.4 Fallback Menu — "Talk to Agent" Button

**Test (continuing from 6.1):**
1. Click "Talk to Agent"
2. **Expected:** Chat ticket is created/assigned to an agent
3. **Expected:** Agent receives push notification

### 6.5 Fallback Menu — 2-Button (No Agent)

**Setup:**
- Enable Fallback Menu, but turn OFF "Talk to Agent" routing

**Test:**
1. Send an unmatched message
2. **Expected:** 2-button menu only:
   ```
   [Start Over]  [Help]
   ```

### 6.6 Fallback Menu — Disabled

**Setup:**
- Disable Fallback Menu in Bot Settings

**Test:**
1. Send an unmatched message
2. **Expected:** No menu appears. Falls through to global reply (if configured) or no response.

### 6.7 Counter-Based Auto-Escalation

**Setup:**
- Set Fallback Threshold to 3 in Bot Settings
- Enable Fallback Menu
- Enable "Talk to Agent" routing

**Test:**
1. Send an unmatched message → fallback menu appears (counter = 1)
2. Click "Start Over" or send another unmatched message → fallback menu appears (counter = 2)
3. Send another unmatched message
4. **Expected:** Counter reaches threshold. Bot sends "You're being connected to a team member..." instead of the menu
5. **Expected:** A chat ticket is auto-assigned to an agent

---

## 7. Bot Settings

### 7.1 Fallback Threshold

**Test:**
1. Go to `/automation/bot-settings`
2. Change Fallback Threshold to 5
3. Click "Save Settings"
4. **Expected:** Toast: "Bot settings updated successfully"
5. Refresh page
6. **Expected:** Value persists as 5

### 7.2 Flow Timeout

**Test:**
1. Change Flow Timeout to 30 (minutes)
2. Click "30 min" quick preset button
3. **Expected:** Value changes to 30
4. Click "1 hr" preset
5. **Expected:** Value changes to 60
6. Save and refresh — value persists

### 7.3 Working Hours Display

**Test:**
1. **Expected:** Card shows read-only schedule from General Settings
2. If working hours are enabled: shows per-day schedule with times + timezone
3. If working hours are disabled: shows "Working hours are disabled" message
4. Click "Configure in General Settings" link
5. **Expected:** Navigates to `/settings/general`

### 7.4 AI Fallback Settings

**Test:**
1. Toggle AI Fallback ON
2. **Expected:** Expanded section with confidence slider
3. If no KB entries: amber warning "No Knowledge Base entries found"
4. If KB entries exist: no warning
5. Move confidence slider to 80%
6. **Expected:** Label shows "80%"
7. Toggle AI Fallback OFF
8. **Expected:** Section collapses, shows "AI will not attempt to answer..." text

---

## 8. AI Settings

### 8.1 AI Features Toggle

**Test:**
1. Go to `/automation/ai-settings`
2. Toggle AI ON
3. **Expected:** Child cards (AI-Only Mode, Confidence) become active/interactive
4. Toggle AI OFF
5. **Expected:** Child cards are dimmed/disabled

### 8.2 AI-Only Mode

**Test:**
1. With AI enabled, toggle AI-Only Mode ON
2. **Expected:** Toggle activates (green)
3. With AI disabled, try to toggle AI-Only Mode
4. **Expected:** AI-Only Mode is inaccessible (grayed out)

### 8.3 AI-Only Mode — Message Flow

**Test:**
1. Enable AI-Only Mode in AI Settings
2. Send any message
3. **Expected:** Bot sends NO auto-reply at all (no trigger, no AI answer, no fallback menu)
4. Disable AI-Only Mode
5. Send a message
6. **Expected:** Normal bot behavior resumes

### 8.4 Confidence Threshold

**Test:**
1. Move slider to 85%
2. **Expected:** Label shows "85%"
3. Save and refresh — value persists

### 8.5 Knowledge Base Summary

**Test:**
1. **Expected:** Shows count of KB entries with colored badge (green if > 0, gray if 0)
2. Click "Manage Knowledge Base" link
3. **Expected:** Navigates to `/automation/knowledge-base`

---

## 9. Full Message Flow — End-to-End Scenarios

### 9.1 Complete Happy Path — New Contact

**Setup:**
- Welcome trigger: type=new_contact, linked to "Welcome Flow"
- Greeting trigger: type=greeting, linked to "Greeting Flow"
- Custom trigger: "pricing" (exact match), linked to "Pricing Flow"
- AI Fallback: enabled
- Fallback Menu: enabled with all 3 buttons

**Test:**
1. First message from new number → **Expected:** Welcome Flow starts
2. Second message (no keyword) → **Expected:** If within 24h of first, greeting doesn't re-trigger. Falls to AI fallback or basic replies.
3. Message "pricing" → **Expected:** Pricing Flow starts (exact match trigger)
4. While in Pricing Flow, send "refund" → **Expected:** Current flow continues (active flow priority)
5. After flow ends, send "hello" → **Expected:** AI fallback or fallback menu

### 9.2 Escalation Chain — From Unmatched to Agent

**Setup:**
- Fallback Threshold: 3
- Fallback Menu: enabled with "Talk to Agent"
- Escalation rule: "Frustrated" → Auto Queue

**Test:**
1. Send "where is my order" (unmatched) → AI fallback attempts KB answer
2. If AI not confident → Fallback menu appears (counter = 1)
3. Send "I need help now" → Fallback menu again (counter = 2)
4. Send "this is terrible" → Counter at 3, auto-escalation: "You're being connected to a team member..."
5. **Expected:** Agent gets assigned ticket

### 9.3 Working Hours — Greeting vs Away

**Setup:**
- Current time is 2pm. Working hours set to 9am–5pm.
- Greeting trigger linked to "Daytime Greeting" flow
- Away trigger linked to "Nighttime Away" flow

**Test:**
1. Existing contact sends message at 2pm (inside hours)
2. **Expected:** Greeting flow triggers (if 24h rate limit allows)
3. Same contact sends message at 10pm (outside hours)
4. **Expected:** Away flow triggers (if 24h rate limit allows)

### 9.4 Flow Priority Chain

**Setup — Triggers in priority order:**
1. "Refund" (exact match) → Refund Flow
2. "Help" (AI match) → Help Flow
3. "Greeting" (system, 24h rate limit) → Greeting Flow
4. "Fallback" (system, no rate limit) → Fallback Flow

**Test:**
1. Send "refund" → **Expected:** Refund Flow starts (priority 1 match)
2. Send "I need assistance" → **Expected:** Help Flow starts (AI matches "assistance" to "help")
3. Send "hello" (no keyword match, existing contact) → **Expected:** Greeting triggers (if 24h rate limit allows), otherwise fallback

---

## 10. Edge Cases & Error Handling

### 10.1 Ollama Service Down

**Test:**
1. Stop Ollama service
2. Send a message that would trigger AI matching
3. **Expected:** No crash, no 500 error. AI modes are skipped gracefully
4. Send a message that would trigger AI fallback
5. **Expected:** Falls through to fallback menu

### 10.2 No Triggers Configured

**Test:**
1. Disable all triggers
2. Send any message
3. **Expected:** Falls through to AI fallback → basic replies → fallback menu → global reply

### 10.3 Trigger Points to Inactive/Deleted Flow

**Test:**
1. Create a trigger linked to a flow
2. Unpublish (deactivate) the flow
3. Send a message matching the trigger
4. **Expected:** Trigger matches, but startFlow returns false because flow is inactive. Falls through to next handler.

### 10.4 Media-Only Message (No Text)

**Test:**
1. Send an image with no caption
2. **Expected:** No keyword trigger fires (empty text). Falls through to AI fallback or fallback menu.

### 10.5 Rapid Messages (Race Condition)

**Test:**
1. Send 5 messages rapidly in succession
2. **Expected:** First message triggers the flow. Subsequent messages continue the flow or are queued.
3. **Expected:** No duplicate flow states created for the same contact.

### 10.6 Arabic Keywords — Exact Match

**Test:**
1. Create trigger with exact keyword "مرحبا"
2. Send "مرحبا"
3. **Expected:** Triggers (case-insensitive for Arabic)
4. Send "مرحباً" (different character)
5. **Expected:** Does NOT trigger (exact match, different character)

### 10.7 AI Settings Toggle Affects All AI Features

**Test:**
1. Disable AI Features in AI Settings
2. Send a message matching an AI trigger
3. **Expected:** AI trigger is skipped (treated as if it doesn't match)
4. Send an unmatched message (would normally trigger AI fallback)
5. **Expected:** AI fallback is skipped
6. Re-enable AI
7. **Expected:** Both AI triggers and AI fallback work again

---

## 11. Toast Messages Verification

After every create/edit/delete/toggle/reorder action, verify:

| Action | Expected Toast |
|--------|---------------|
| Create trigger | "Trigger created successfully" |
| Edit trigger | "Trigger updated successfully" |
| Delete trigger | "Trigger deleted successfully" |
| Reorder triggers | "Priority updated" |
| Toggle trigger | "Trigger status updated" |
| Create KB entry | "Knowledge base entry created successfully" |
| Edit KB entry | "Knowledge base entry updated successfully" |
| Delete KB entry | "Knowledge base entry deleted successfully" |
| Save Bot Settings | "Bot settings updated successfully" |
| Save AI Settings | "AI settings updated successfully" |
| Create escalation rule | "Escalation instruction created successfully" |
| Edit escalation rule | "Escalation instruction updated successfully" |
| Delete escalation rule | "Escalation instruction deleted successfully" |
| Toggle escalation rule | "Escalation instruction status updated" |

**Critical:** If any toast appears as a white/empty bar, that's a bug — the flash message format is wrong.

---

## 12. FlowBuilder Cleanup Verification

### 12.1 Start Node No Longer Has Trigger Dropdown

**Test:**
1. Open the FlowBuilder editor for any flow
2. Click on the Start node
3. **Expected:** Start node shows "Start" label and "Triggers are configured in the Triggers page" text
4. **Expected:** No trigger type dropdown or keywords textarea

### 12.2 Flow Execution Works Without Trigger Column

**Test:**
1. Create a flow with: Start → Text Node ("Test message") → End
2. Publish the flow
3. Create a trigger linked to this flow
4. Trigger the flow via WhatsApp
5. **Expected:** Flow executes correctly, message is sent
6. **Expected:** No errors in logs about missing `trigger` or `keywords` columns

### 12.3 Cycle Detection Works

**Test:**
1. Create a flow with a loop (e.g., two text nodes connected in a cycle with no condition break)
2. Publish and trigger the flow
3. **Expected:** Flow processes a few nodes then stops gracefully (no infinite loop)
4. **Expected:** Log shows "cycle detected" warning

### 12.4 Old Flows Still Work After Migration

**Test:**
1. Find an existing flow that was created before the cleanup
2. Trigger it via a keyword trigger
3. **Expected:** Flow executes normally (metadata is intact, only trigger/keywords columns were removed from the flows table)

---

## 13. Agent Routing

### 13.1 Escalation → Specific Agent

**Test:**
1. Create escalation rule: target "Specific Agent", pick an agent
2. Send a message matching the escalation rule
3. **Expected:** Chat ticket is created/assigned to that specific agent
4. **Expected:** Agent receives push notification

### 13.2 Escalation → Auto Queue (Round-Robin)

**Test:**
1. Configure org ticket settings to "Auto Robinhood" mode
2. Select 2-3 agents for the round-robin pool
3. Create escalation rule: target "Auto Queue"
4. Trigger escalation from 2 different contacts
5. **Expected:** Tickets are distributed round-robin among selected agents

### 13.3 Talk to Agent → Routing

**Test:**
1. Click "Talk to Agent" on fallback menu
2. **Expected:** Ticket assigned using org's auto-assignment mode
3. **Expected:** Agent receives notification

### 13.4 Already Assigned — No Reassignment

**Test:**
1. Have a conversation with an open, assigned ticket
2. Trigger escalation on the same contact
3. **Expected:** AgentRoutingService does NOT reassign — existing assignment stays