---
name: CommunityPass
description: Material 3 teaching interface with native HTML controls
colors:
  primary: "#245d78"
  on-primary: "#ffffff"
  primary-container: "#cce6f5"
  on-primary-container: "#092f43"
  secondary-container: "#e0e8ed"
  on-secondary-container: "#253e4b"
  surface: "#f8f9fc"
  on-surface: "#191c20"
  surface-container-low: "#f0f3f7"
  surface-container-high: "#e5eaf0"
  on-surface-variant: "#444b53"
  outline: "#737b84"
  outline-variant: "#c3cbd3"
  error: "#ba1a1a"
  on-error: "#ffffff"
  error-container: "#ffdad6"
  on-error-container: "#410002"
  vip-container: "#f4e4c2"
  on-vip-container: "#503d15"
  warning-container: "#ffedc1"
  on-warning-container: "#553d00"
  success-container: "#d7efdf"
  on-success-container: "#173c29"
typography:
  headline:
    fontFamily: 'system-ui, -apple-system, "Segoe UI", Arial, sans-serif'
    fontSize: "1.875rem"
    fontWeight: 650
    lineHeight: 1.3
  title:
    fontFamily: 'system-ui, -apple-system, "Segoe UI", Arial, sans-serif'
    fontSize: "1.25rem"
    fontWeight: 650
    lineHeight: 1.3
  body:
    fontFamily: 'system-ui, -apple-system, "Segoe UI", Arial, sans-serif'
    fontSize: "1rem"
    lineHeight: 1.6
  label:
    fontFamily: 'system-ui, -apple-system, "Segoe UI", Arial, sans-serif'
    fontSize: ".875rem"
rounded:
  field: "4px"
  seat: "6px"
  badge: "8px"
  card: "12px"
  form: "16px"
  action: "24px"
spacing:
  xs: "4px"
  sm: "8px"
  compact: "12px"
  md: "16px"
  lg: "24px"
  xl: "32px"
  section: "40px"
  wide: "48px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.on-primary}"
    rounded: "{rounded.action}"
    padding: "12px 24px"
  button-secondary:
    backgroundColor: "{colors.secondary-container}"
    textColor: "{colors.on-secondary-container}"
    rounded: "{rounded.action}"
    padding: "12px 24px"
  input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.on-surface}"
    rounded: "{rounded.field}"
    padding: "12px 16px"
  event-card:
    backgroundColor: "{colors.surface-container-low}"
    rounded: "{rounded.card}"
  status:
    backgroundColor: "{colors.primary-container}"
    textColor: "{colors.on-primary-container}"
    rounded: "{rounded.badge}"
    padding: "4px 12px"
---

# Design System: CommunityPass

## Overview

**Creative North Star: "The Clear Teaching Desk"**

The implemented direction is a calm Material Design 3 teaching interface: neutral light surfaces, deep blue actions, restrained amber VIP accents and familiar native controls. Readable Vietnamese content and visible task states take priority over decoration. This name describes the supplied direction; it does not introduce a new identity.

The same visual system serves independent student and teacher labs. Preserve their separate teaching roles and the boundaries in PRODUCT.md. Lab01 remains an introduction and Lab02 remains a terminal exercise.

**Key Characteristics:**
- Role-based colors and tonal surfaces.
- System typography and native HTML semantics.
- Generous action targets and compact, readable event cards.

## Colors

Primary deep blue identifies actions, links, selection and focus. Its pale container supports selected tickets and introductory guidance. Secondary cool gray-blue supports quiet navigation and notices. VIP amber belongs to VIP pricing; warning amber communicates unavailable or caution states.

Neutral surfaces distinguish page, card and disabled layers. Use on-surface for main content, on-surface-variant for metadata and outline-variant for dividers. Error roles pair red text or pale error containers with explicit messages. Success roles are available in the source palette; do not invent success outcomes to use them.

**The Role Pair Rule.** Pair each colored container with its corresponding on-container color; event artwork never changes the application palette.

## Typography

One system font stack covers headings, body and controls, including Vietnamese. Headline and title use modest scale changes and semibold weight. Body text stays comfortably spaced; supporting labels reduce size rather than rely on low contrast. Detail prose is constrained to approximately 75 characters per line. Long titles and values wrap.

## Layout

The shared shell has a centered maximum width (1248px), desktop side padding (24px), and mobile side padding (16px). Event lists use three columns, reduce to two at 1000px, and become one at 600px. Detail and preview screens pair flexible content with a summary column (320px); they stack at 1000px. The desktop preview summary is sticky above 1000px.

Forms use a centered content width (600px). Repeated gaps follow the spacing scale; section breaks use section or wide spacing. Navigation and action rows wrap. The fixed seat geometry scrolls inside its own frame, with a list alternative; it must not widen the page.

## Elevation & Depth

Flat tonal layering, thin borders and spacing establish depth. The implementation has no box shadows, glass or gradients. Hover changes surface color; reduced-motion preference suppresses the optional background and border transitions (120ms ease-out).

## Shapes

Event cards, image corners and summary panels use the card radius. Forms use a slightly softer form radius. Pill-shaped actions and navigation use the action radius. Fields remain lightly rounded; badges and seat controls retain their smaller shapes. Event covers use a consistent widescreen aspect ratio (16:9).

## Components

- **Buttons:** filled primary, quiet secondary, transparent cancel and text-link variants. Actions have a minimum target height (48px); preserve visible keyboard focus (3px outline, 4px offset). Disabled controls retain readable labels and unavailable cues.
- **Fields:** native input, select and checkbox, with persistent labels and help text. Text fields and selects have a minimum height (52px), an outline border and closer focus offset (1px). Invalid fields use the error role and accompanying error text.
- **Navigation:** wrapping native links, pill hover surfaces, and a toned selected state with heavier text. A skip link becomes visible on keyboard focus.
- **Cards and status:** event covers, type, title, date, venue, status and actions stay distinct. Status badges communicate their meaning in text as well as color. Cards have bordered edges and content padding (24px, reduced to 20px on mobile).
- **Detail content:** a readable article, summary, local image gallery and native details/summary FAQ. Summary controls keep the common action target.
- **Ticket and seat preview:** pressed-state ticket buttons, amber VIP price labels, labeled seat buttons and a checkbox list alternative. Selected seats use the primary pair; unavailable seats are disabled and visibly marked. Summary copy explicitly identifies a sample selection. Selection is page-memory-only; these components do not promise reservation, ticket creation or payment.

## Do's and Don'ts

### Do:
- Do reuse role tokens, native controls and visible text labels.
- Do keep action targets at least 48px high and event cards at the established radius.
- Do keep fictional artwork local and retain its sample disclosure.
- Do preserve teaching TODOs and existing form, session and validation contracts.

### Don'ts:
- Don't imply that preview selection reserves a seat or completes a transaction.
- Don't encode selection, errors or unavailable states through color alone.
- Don't replace the system font, add frameworks or decorative effects for visual consistency.

Source of truth: the shared public/assets/css/tokens.css and app.css distributed into each applicable independent lab. This document captures implemented styling; it is not evidence of backend availability or business acceptance.
