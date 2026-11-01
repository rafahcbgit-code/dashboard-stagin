# Chatberry Design System

## Direction

Chatberry uses a calm, operations-focused workspace: navigation stays compact, work stays in context, and customer information is visible beside the conversation. The system is RTL-first while remaining LTR-safe.

## Tokens

The source of truth is `resources/css/app.css` and the Tailwind theme. New page styles must use the semantic tokens (`primary`, `primary-dark`, `canvas`, `ink`, `muted`, `line`) or Tailwind semantic classes. Do not introduce one-off brand colors in page templates.

## Layout

- Desktop application shell: navigation rail + content workspace.
- Operational pages: page header, filters/actions, content surface.
- Chat workspace: conversation list + thread + customer context.
- Tablet: customer context collapses into a drawer.
- Mobile: one primary surface at a time; list, thread, and context are navigable without losing state.

## Components

Shared primitives live under `resources/js/Components/UI`. They must expose keyboard focus, loading, disabled, error, RTL/LTR, and translated content states where applicable. Feature components remain responsible for API calls and business behavior; UI primitives must not contain domain logic.

## Status and accessibility

Status must use text and/or an icon in addition to color. Focus rings are required for interactive controls. Sensitive customer fields remain governed by the existing server-side permissions and must not be copied to client logs or screenshots.

## Reference decisions

- Reference-inspired: rounded surfaces, light canvas, compact navigation, green/teal action color, visible customer context, soft borders and shadows.
- Chatberry-specific: existing routes, translations, permissions, realtime transport, API contracts, and current business workflows.
- Dark mode is deferred until the light system is stable.
