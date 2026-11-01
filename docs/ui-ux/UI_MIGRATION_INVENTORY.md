# UI/UX Migration Inventory

This inventory is intentionally maintained as the migration progresses. A page is complete only when its shared primitives, responsive states, RTL/LTR behavior, functional checks, and visual reference are recorded.

| Area | Pages/components | Priority | Risk | Status |
|---|---|---:|---:|---|
| Shell | User App, Sidebar, Menu, MobileSidebar | P0 | High | In progress |
| Conversations | Chat Index, ChatTable, ChatHeader, ChatThread, ChatForm, ContactInfo | P0 | High | In progress |
| Customers | Contact pages and contact tables | P1 | Medium | Planned |
| Operations | Dashboard, Reports, Campaigns, Templates | P1 | Medium | Planned |
| Automation | Automation pages and Chatbot Studio | P1 | High | Planned |
| Support | Tickets and support pages | P2 | Medium | Planned |
| Account | Settings, team, notifications, billing | P2 | Medium | Planned |
| Admin | Admin shell and admin pages | P3 | Medium | Planned |
| Auth | Login, register, reset, verification | P3 | Low | Planned |

## Exit checklist per area

- [ ] Uses semantic design tokens and shared primitives.
- [ ] Desktop, tablet, and mobile states are defined.
- [ ] Arabic RTL and English LTR are checked.
- [ ] Existing API, permissions, realtime, and navigation behavior pass regression checks.
- [ ] Loading, empty, error, disabled, and success states are present.
- [ ] Screenshot reference is captured and approved.
