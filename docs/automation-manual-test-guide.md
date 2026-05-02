# Chatberry Automation — Manual Test Guide

> Test all automation features before proceeding to Section C (Flow Builder Nodes).

---

## 1. Automation Sidebar Navigation

### 1.1 Page Load
1. Navigate to `/automation/basic`
2. **Expected**: Page loads without any server error (no `ArgumentCountError` or 500)
3. **Expected**: Left sidebar shows these nav items (when FlowBuilder module is enabled):
   - Basic Replies
   - Triggers
   - Knowledge Base
   - Bot Settings
   - Escalation
   - Automations
4. **Expected**: If AI Assistant module is enabled, also shows:
   - AI Settings
   - AI Reply Assistant
5. **Expected**: Clicking each nav item navigates to the correct page
6. **Expected**: The active page's nav item has a highlighted/slate background

### 1.2 Escalation Nav Specifically
1. Confirm the Escalation link appears in the sidebar (gated by `fbmodule`, not `aimodule`)
2. **Expected**: Clicking it navigates to `/automation/escalation`
3. **Expected**: No server error (the old `User::whereHas('organizations')` bug is fixed — agents are now fetched via Team model)

---

## 2. Triggers Page (`/automation/triggers`)

### 2.1 Initial Load
1. Navigate to `/automation/triggers`
2. **Expected**: Page loads. If first visit, system triggers (Welcome, Greeting, Away, Fallback) are auto-created
3. **Expected**: Four system triggers appear in the list with a lock icon
4. **Expected**: System triggers cannot be deleted (no trash icon or confirmation)

### 2.2 Create a Keyword Trigger
1. Click **"Create Trigger"** button
2. **Expected**: Modal opens
3. Enter name: "Refund Handler"
4. Select type: **New Message**
5. **Expected**: Keyword input section appears (only shown for `new_message` type)
6. Type "refund" in the keyword input and press Enter
7. **Expected**: A chip/badge "refund" appears below the input
8. Type "money back" and press Enter
9. **Expected**: Second chip "money back" appears
10. Click the X on "money back" chip
11. **Expected**: Chip is removed, only "refund" remains
12. Select match mode: **Exact Match**
13. **Expected**: The toggle shows emerald/green color, AI confidence slider is hidden
14. Switch match mode to **AI Match**
15. **Expected**: Toggle shows violet/purple color, AI confidence slider appears below
16. Move the confidence slider to 80%
17. **Expected**: Slider label shows "80%"
18. Select a flow from the "Linked Flow" dropdown (or leave empty)
19. Click **"Add Trigger"**
20. **Expected**: Modal closes, toast shows "Trigger created successfully", new trigger appears in the list with keyword badge colored by match mode

### 2.3 Create a Non-Keyword Trigger
1. Click **"Create Trigger"**
2. Select type: **New Contact**
3. **Expected**: Keyword input section is hidden (new_contact doesn't need keywords)
4. Enter a name, click **"Add Trigger"**
5. **Expected**: Trigger created successfully

### 2.4 Drag Reorder
1. Drag a trigger by the grip handle (≡) and drop it above another
2. **Expected**: Trigger order changes visually
3. Refresh the page
4. **Expected**: New order persists after page reload

### 2.5 Toggle ON/OFF
1. Click the toggle switch on any trigger
2. **Expected**: Toggle state changes (green = on, gray = off)
3. **Expected**: Toast shows "Trigger status updated"
4. Disabled triggers appear slightly faded/opacity reduced

### 2.6 Edit Trigger
1. Click the pencil/edit icon on a trigger
2. **Expected**: Modal opens with all fields pre-filled
3. Change the name, click **"Save Changes"**
4. **Expected**: Name updates in the list, toast shows success

### 2.7 Delete Trigger
1. Click the trash icon on a **non-system** trigger
2. **Expected**: Browser confirmation dialog appears
3. Click OK
4. **Expected**: Trigger is removed from the list, toast shows success
5. Try to delete a **system** trigger (Welcome, Greeting, etc.)
6. **Expected**: No delete icon available for system triggers

---

## 3. Knowledge Base Page (`/automation/knowledge-base`)

### 3.1 Empty State
1. Navigate to `/automation/knowledge-base`
2. **Expected**: If no entries exist, shows empty state with "No Q&A entries yet" message

### 3.2 Add Q&A Entry
1. Click **"Add Entry"** button
2. **Expected**: Modal opens with Question and Answer textareas
3. Enter question: "What are your business hours?"
4. Enter answer: "We're open Monday to Friday, 9am to 5pm."
5. Click **"Add Entry"**
6. **Expected**: Modal closes, new entry appears in list with "Q" and "A" badges, toast shows success

### 3.3 Toggle Entry
1. Click the toggle on an entry
2. **Expected**: Entry toggles on/off, disabled entries appear faded

### 3.4 Edit Entry
1. Click the edit icon on an entry
2. **Expected**: Modal opens with both fields pre-filled
3. Modify the answer text, click **"Save Changes"**
4. **Expected**: Changes reflected in list, toast shows success

### 3.5 Delete Entry
1. Click the delete icon on an entry
2. **Expected**: Confirmation dialog appears
3. Click OK
4. **Expected**: Entry removed from list, toast shows success

---

## 4. Bot Settings Page (`/automation/bot-settings`)

### 4.1 Page Load
1. Navigate to `/automation/bot-settings`
2. **Expected**: Page loads with 5 cards: Fallback Threshold, Flow Timeout, Working Hours, AI Fallback, Fallback Menu

### 4.2 Fallback Threshold
1. Change the number to 5
2. Click **"Save Settings"**
3. **Expected**: Toast shows "Bot settings updated successfully"
4. Refresh page
5. **Expected**: Value persists as 5

### 4.3 Flow Timeout
1. Change the number manually to 60
2. Click the "1 hr" quick preset button
3. **Expected**: Number changes to 60
4. Click the "30 min" preset
5. **Expected**: Number changes to 30
6. Save and refresh — value persists

### 4.4 Working Hours
1. **Expected**: Card shows read-only schedule from General Settings
2. If working hours enabled: shows per-day schedule with times, plus timezone
3. If working hours disabled: shows "Working hours are disabled" message
4. Click **"Configure in General Settings"** link
5. **Expected**: Navigates to `/settings/general`

### 4.5 AI Fallback
1. Toggle AI Fallback **ON**
2. **Expected**: Expanded section appears with KB warning (if no entries) and confidence slider
3. If no KB entries: **Expected**: Amber warning "No Knowledge Base entries found"
4. If KB entries exist: warning is not shown
5. Move confidence slider to 80%
6. **Expected**: Label shows "80%"
7. Toggle AI Fallback **OFF**
8. **Expected**: Expanded section collapses, shows "AI will not attempt to answer..." text
9. Save and refresh — all values persist

### 4.6 Fallback Menu
1. Toggle Fallback Menu **ON**
2. **Expected**: Expanded section appears with:
   - Menu Message textarea
   - WhatsApp Preview with 3 buttons
   - Start Over flow dropdown
   - Help flow dropdown
   - Talk to Agent routing toggle
3. Change the menu message text
4. **Expected**: WhatsApp preview updates in real-time to show the new message
5. Select a flow from the Start Over dropdown
6. Select a flow from the Help dropdown
7. Toggle "Talk to Agent" routing ON/OFF
8. When "Talk to Agent" is OFF: **Expected**: only 2 buttons in preview (Start Over, Help)
9. When "Talk to Agent" is ON: **Expected**: 3 buttons in preview
10. Toggle Fallback Menu **OFF**
11. **Expected**: Shows "When no match is found, the old global reply behavior will be used instead."
12. Save and refresh — all values persist

---

## 5. AI Settings Page (`/automation/ai-settings`)

### 5.1 Page Load
1. Navigate to `/automation/ai-settings`
2. **Expected**: Page loads with AI Features toggle, AI-Only Mode, confidence slider, KB summary, model info

### 5.2 AI Features Toggle
1. Toggle AI **ON**
2. **Expected**: Child cards become active/interactive
3. Toggle AI **OFF**
4. **Expected**: Child cards are visually dimmed/disabled

### 5.3 AI-Only Mode
1. With AI enabled, toggle AI-Only Mode **ON**
2. **Expected**: Toggle activates
3. With AI disabled, AI-Only Mode should be inaccessible
4. **Expected**: When AI is OFF, AI-Only Mode cannot be turned on

### 5.4 Confidence Threshold
1. Move slider to 85%
2. **Expected**: Label shows "85%"
3. Save and refresh — value persists

### 5.5 Knowledge Base Summary
1. **Expected**: Shows count of KB entries with link to KB page
2. Click the link
3. **Expected**: Navigates to `/automation/knowledge-base`

### 5.6 AI Model Card
1. **Expected**: Shows the configured model name (read-only, e.g., "gemma3:4b")
2. **Expected**: No edit controls — this is admin-level config

---

## 6. Escalation Page (`/automation/escalation`)

### 6.1 Page Load
1. Navigate to `/automation/escalation`
2. **Expected**: Page loads without errors (agents loaded via Team model, not User::whereHas)

### 6.2 Add Escalation Rule
1. Click **"Add Rule"** button
2. **Expected**: Modal opens
3. Enter name: "Price Inquiry"
4. Enter description: "When customer asks about pricing, shows frustration about cost, or requests a quote"
5. Select target type: **Auto Queue**
6. **Expected**: Only 2 target options exist (Auto Queue + Specific Agent, no Bot Queue)
7. Switch target type to **Specific Agent**
8. **Expected**: Agent dropdown appears below
9. Select an agent from the dropdown
10. Click **"Add Rule"**
11. **Expected**: Modal closes, new rule in list with badge "Agent" (emerald color)
12. Create another rule with Auto Queue target
13. **Expected**: Badge shows "Auto Queue" (blue color)

### 6.3 Validation: Agent Required
1. Click **"Add Rule"**
2. Select **Specific Agent** as target type
3. Leave agent dropdown as "— Choose an agent —"
4. Click **"Add Rule"**
5. **Expected**: Validation error "Please select an agent when routing to a specific agent."

### 6.4 Drag Reorder
1. Drag a rule above another
2. **Expected**: Order changes visually
3. Refresh — order persists

### 6.5 Toggle, Edit, Delete
1. Toggle a rule ON/OFF — state changes, toast confirms
2. Edit a rule — modal pre-fills all fields
3. Delete a rule — confirmation dialog, then removed

---

## 7. WhatsApp Live Message Flow

> These tests require sending actual WhatsApp messages to a connected number.

### 7.1 New Contact Flow
1. Send a message from a **new phone number** (never contacted before)
2. **Expected**: If the "Welcome" system trigger is enabled and has a linked flow, the welcome flow starts
3. Send another message from the same number
4. **Expected**: Welcome flow does NOT re-trigger (lifetime rate limit)

### 7.2 Greeting Flow
1. During working hours, send a message from a contact who hasn't messaged in 24h+
2. **Expected**: If Greeting trigger is enabled with a linked flow, greeting flow starts
3. Send another message within 24h
4. **Expected**: Greeting does NOT re-trigger (24h rate limit)

### 7.3 Away Flow
1. Outside working hours, send a message from a contact who hasn't messaged in 24h+
2. **Expected**: If Away trigger is enabled with a linked flow, away flow starts
3. Send another message within 24h
4. **Expected**: Away does NOT re-trigger (24h rate limit)

### 7.4 Exact Keyword Match
1. Create a trigger with match mode **Exact**, keyword "refund"
2. Send message: "refund"
3. **Expected**: The linked flow starts
4. Send message: "I want a refund"
5. **Expected**: Does NOT trigger exact match (exact = full message match only)
6. Send message: "Refund" (capital R)
7. **Expected**: Triggers (case-insensitive for Latin text)

### 7.5 AI Keyword Match
1. Create a trigger with match mode **AI**, keyword "refund"
2. Ensure AI is enabled in AI Settings
3. Send message: "I want my money back"
4. **Expected**: AI classifies this as matching "refund" intent, linked flow starts (requires Ollama running)
5. If Ollama is down: **Expected**: No crash, trigger is skipped, falls through to next handler

### 7.6 AI Fallback — KB Answer
1. Enable AI Fallback in Bot Settings
2. Add a KB entry: Q="What are your hours?" A="We're open Mon-Fri 9am-5pm"
3. Send a message that doesn't match any trigger: "What time do you open?"
4. **Expected**: AI answers from KB: "We're open Mon-Fri 9am-5pm"
5. **Expected**: Follow-up message "Was this helpful?" with 2 buttons: "Yes, this helped" and "No, I need more help"

### 7.7 AI Fallback — "Was this helpful?" — YES
1. After receiving the KB answer + feedback buttons, click **"Yes, this helped"**
2. **Expected**: Bot sends "Great! Let me know if you need anything else."
3. **Expected**: No further bot messages

### 7.8 AI Fallback — "Was this helpful?" — NO
1. After receiving the KB answer + feedback buttons, click **"No, I need more help"**
2. **Expected**: Fallback menu appears with 3 buttons: Start Over, Help, Talk to Agent

### 7.9 AI Fallback — Escalation Match
1. Create an escalation rule: "Price Inquiry" → "When customer asks about pricing" → Auto Queue
2. Enable AI in AI Settings
3. Send message: "How much does this cost?" (no matching trigger keyword)
4. **Expected**: Escalation instruction matches (AI classifies it as pricing question)
5. **Expected**: A chat ticket is created/assigned to an agent (check the tickets table)
6. **Expected**: No KB answer is sent (escalation takes priority)

### 7.10 AI Fallback — Not Confident
1. Send a very ambiguous message: "asdfghjkl"
2. **Expected**: AI cannot answer from KB, falls through to Fallback Menu

### 7.11 Fallback Menu
1. Disable AI Fallback (or send a message AI can't answer)
2. Enable Fallback Menu in Bot Settings
3. Send an unmatched message
4. **Expected**: 3-button menu appears: Start Over, Help, Talk to Agent
5. **Expected**: Custom menu message appears above the buttons (as configured in Bot Settings)

### 7.12 Fallback Menu — Start Over
1. Click **"Start Over"** button
2. **Expected**: If a Start Over flow is configured, that flow starts
3. If no flow configured: **Expected**: Welcome/new-contact trigger is attempted

### 7.13 Fallback Menu — Help
1. Click **"Help"** button
2. **Expected**: If a Help flow is configured, that flow starts
3. If no flow configured: **Expected**: Nothing happens (no error)

### 7.14 Fallback Menu — Talk to Agent
1. Click **"Talk to Agent"** button
2. **Expected**: A chat ticket is created/assigned to an agent (check in the dashboard)
3. **Expected**: The agent receives a push notification (if FCM configured)

### 7.15 Counter-Based Auto-Escalation
1. Set Fallback Threshold to 2 in Bot Settings
2. Send 2 unmatched messages (triggering the fallback menu each time)
3. On the 2nd or 3rd unmatched message: **Expected**: Bot sends "You're being connected to a team member..." instead of the 3-button menu
4. **Expected**: A chat ticket is auto-assigned to an agent

### 7.16 AI-Only Mode
1. Enable AI-Only Mode in AI Settings
2. Send any message
3. **Expected**: Bot sends NO auto-reply at all (no trigger, no AI answer, no fallback menu)
4. Disable AI-Only Mode
5. Send a message
6. **Expected**: Normal bot behavior resumes

### 7.17 Active Flow Priority
1. Start a flow by triggering a keyword
2. While in the active flow, send another keyword that would trigger a different flow
3. **Expected**: The current flow continues (active flow takes priority)

### 7.18 Flow Timeout
1. Set Flow Timeout to 5 minutes in Bot Settings
2. Start a flow
3. Wait 5+ minutes
4. Send a message
5. **Expected**: Old flow is expired, new message goes through trigger evaluation fresh

---

## 8. Agent Routing (Ticket Assignment)

### 8.1 Escalation → Specific Agent
1. Create escalation rule with target **Specific Agent** and pick an agent
2. Send a message that matches the escalation rule
3. **Expected**: In the dashboard, the conversation ticket is assigned to that specific agent
4. **Expected**: Agent gets a push notification

### 8.2 Escalation → Auto Queue (Round-Robin)
1. Configure ticket settings to **Auto Robinhood** mode (Settings > Ticket)
2. Select 2-3 agents for the round-robin pool
3. Create escalation rule with target **Auto Queue**
4. Trigger escalation from 2 different contacts
5. **Expected**: Tickets are distributed round-robin among the selected agents

### 8.3 Escalation → Auto Queue (Least-Loaded)
1. Configure ticket settings to **Auto** mode (Settings > Ticket)
2. Create escalation rule with target **Auto Queue**
3. Trigger escalation
4. **Expected**: Ticket assigned to the agent with fewest open tickets

### 8.4 Talk to Agent → Routing
1. Click "Talk to Agent" on the fallback menu
2. **Expected**: Ticket assigned using the org's configured auto-assignment mode
3. **Expected**: Agent receives notification

### 8.5 Auto-Escalation → Routing
1. Set Fallback Threshold to 2
2. Send 2+ unmatched messages to trigger counter-based escalation
3. **Expected**: Ticket auto-assigned using org's auto-assignment mode

### 8.6 Already Assigned — No Reassignment
1. Have a conversation with an open, assigned ticket
2. Trigger escalation on the same contact
3. **Expected**: AgentRoutingService does NOT reassign — existing assignment stays

---

## 9. Edge Cases

### 9.1 Ollama Down
1. Stop Ollama service
2. Send a message that would trigger AI matching
3. **Expected**: No crash, no 500 error. AI modes are skipped gracefully, falls through to fallback menu or basic replies

### 9.2 No KB Entries + AI Fallback ON
1. Delete all KB entries
2. Enable AI Fallback
3. Send an unmatched message
4. **Expected**: AI returns NONE (can't answer without KB), falls through to fallback menu

### 9.3 No Escalation Rules + AI Fallback ON
1. Delete all escalation rules
2. Enable AI Fallback
3. Send an unmatched message
4. **Expected**: Escalation check is skipped, goes straight to KB answer attempt

### 9.4 Trigger With No Linked Flow
1. Create a trigger with a keyword but no linked flow
2. Send a message matching that keyword
3. **Expected**: Trigger matches but no flow starts, falls through to next handler (AI fallback, etc.)

### 9.5 Media-Only Message (No Text)
1. Send an image/sticker with no text caption
2. **Expected**: No keyword trigger fires (empty text), falls through to AI/fallback

### 9.6 Fallback Menu Disabled + AI Disabled
1. Disable Fallback Menu in Bot Settings
2. Disable AI Fallback
3. Send an unmatched message
4. **Expected**: Old global reply behavior is used (if configured) or no reply

### 9.7 Arabic Keyword — Exact Match
1. Create trigger with exact keyword "مرحبا"
2. Send "مرحبا"
3. **Expected**: Triggers (case-sensitive for Arabic)
4. Send "مرحباً" (with different character)
5. **Expected**: Does NOT trigger (exact match)

---

## 10. Toast Messages Check

> After every action, verify toast notification behavior.

1. Create trigger → **Expected**: Green toast with "Trigger created successfully"
2. Edit trigger → **Expected**: Green toast with "Trigger updated successfully"
3. Delete trigger → **Expected**: Green toast with "Trigger deleted successfully"
4. Reorder triggers → **Expected**: Green toast with "Priority updated"
5. Toggle trigger → **Expected**: Green toast with "Trigger status updated"
6. Create KB entry → **Expected**: Green toast with "Knowledge base entry created successfully"
7. Save Bot Settings → **Expected**: Green toast with "Bot settings updated successfully"
8. Save AI Settings → **Expected**: Green toast with "AI settings updated successfully"
9. Create escalation rule → **Expected**: Green toast with "Escalation instruction created successfully"

**Critical**: If any toast appears as a white/empty bar, that's a bug — the flash message format is wrong.