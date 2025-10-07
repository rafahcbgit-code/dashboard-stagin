Our cache key structure:

$cacheKey = sprintf(
    'chat_list:%d:%s:%s:%s:%s:%s',
    $this->organizationId,    // Which company?
    $ticketState,             // all/open/closed?
    $searchKey,               // Searching for something?
    $labelKey,                // Filtered by labels?
    $sortDirection,           // asc or desc?
    $role                     // admin or agent?
);

chat_list:1:all:none:none:desc:admin
    ↑    ↑  ↑   ↑    ↑    ↑    ↑
    |    |  |   |    |    |    └─ User role
    |    |  |   |    |    └────── Sort direction
    |    |  |   |    └─────────── No label filters
    |    |  |   └──────────────── No search term
    |    |  └──────────────────── Show all tickets
    |    └─────────────────────── Organization ID
    └──────────────────────────── Cache prefix

chat_list:1:open:abc123:def456:desc:agent
    Different search and label = Different cache key!

-------------------------------------------------------------


Before Optimization:

User receives WhatsApp message
    ↓
Pusher broadcasts event to frontend
    ↓
Frontend makes AJAX request to /chats
    ↓
Backend queries database (200-500ms)
    ↓
Returns full contact list
    ↓
Frontend updates UI

Problem: This happens for EVERY message!
If 10 messages arrive in 30 seconds = 10 expensive database queries

After Optimization:

User receives WhatsApp message
    ↓
Pusher broadcasts event to frontend
    ↓
Frontend makes AJAX request to /chats
    ↓
Backend checks: "Did we just serve this data?" (Throttle check)
    ↓
YES → Return {status: 'wait'} (instant, no DB query)
NO  → Check cache
    ↓
Cache exists? → Return cached data (5-10ms, no DB query)
Cache expired? → Query database + store in cache + return data

Result: 10 messages in 30 seconds = 2 database queries (at 0s and 15s)

------------------------------

# Clear all cache
php artisan cache:clear


Cache file lifecycle:

First request → File created with 300 second TTL
Subsequent requests → File read and returned
After 300 seconds → File marked as expired
Next request → File deleted, new DB query, new cache file


--------------------------------

┌─────────────────────────────────────────────────────────────┐
│                     Request Flow                             │
└─────────────────────────────────────────────────────────────┘

User receives message via Pusher
         ↓
Frontend debounces for 1 second
         ↓
Leader tab makes AJAX request to /chats
         ↓
┌────────────────────────────────────────────┐
│  Backend Throttle Check (15 seconds)       │
├────────────────────────────────────────────┤
│  Last request < 15 sec ago?                │
│     YES → Return {status: 'wait'}          │
│     NO  → Continue to cache check          │
└────────────────────────────────────────────┘
         ↓
┌────────────────────────────────────────────┐
│  Cache Check (5 minutes)                   │
├────────────────────────────────────────────┤
│  Cache exists?                             │
│     YES → Return cached data (5-10ms)      │
│     NO  → Query database (200-500ms)       │
│           → Store in cache                 │
│           → Return data                    │
└────────────────────────────────────────────┘
         ↓
Frontend updates UI (or ignores if 'wait')